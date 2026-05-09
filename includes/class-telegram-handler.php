<?php
class TWB_Telegram_Handler {
    
    private $api_id;
    private $api_hash;
    private $bot_token;
    private $api_url;
    private $wasabi;
    private $db;
    
    public function __construct() {
        $this->api_id = get_option('twb_api_id');
        $this->api_hash = get_option('twb_api_hash');
        $this->bot_token = get_option('twb_bot_token');
        $this->api_url = "https://api.telegram.org/bot{$this->bot_token}";
        $this->wasabi = new TWB_Wasabi_Handler();
        $this->db = new TWB_Database();
    }
    
    public function set_webhook() {
        $webhook_url = home_url('/wp-json/twb/v1/webhook');
        $response = $this->call_telegram_api('setWebhook', [
            'url' => $webhook_url,
            'max_connections' => 100,
            'allowed_updates' => ['message', 'callback_query']
        ]);
        
        if ($response && $response['ok']) {
            update_option('twb_webhook_set', true);
            return true;
        }
        
        update_option('twb_webhook_set', false);
        return false;
    }
    
    public function handle_webhook($request) {
        $update = $request->get_json_params();
        
        if (isset($update['message'])) {
            $this->process_message($update['message']);
        }
        
        if (isset($update['callback_query'])) {
            $this->process_callback($update['callback_query']);
        }
        
        return new WP_REST_Response(['status' => 'ok'], 200);
    }
    
    private function process_message($message) {
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        
        // Rate limiting
        if ($this->check_rate_limit($user_id)) {
            $this->send_message($chat_id, "⏳ Rate limit exceeded. Please wait before uploading more files.");
            return;
        }
        
        // Save user
        $this->db->save_user([
            'user_id' => $user_id,
            'username' => $message['from']['username'] ?? '',
            'first_name' => $message['from']['first_name'] ?? '',
            'last_name' => $message['from']['last_name'] ?? '',
            'last_active' => current_time('mysql')
        ]);
        
        // Handle different message types
        if (isset($message['document'])) {
            $this->handle_document($message);
        } elseif (isset($message['video'])) {
            $this->handle_video($message);
        } elseif (isset($message['audio'])) {
            $this->handle_audio($message);
        } elseif (isset($message['text'])) {
            $this->handle_command($message);
        } elseif (isset($message['photo'])) {
            $this->handle_photo($message);
        }
    }
    
    private function handle_document($message) {
        $chat_id = $message['chat']['id'];
        $document = $message['document'];
        $file_id = $document['file_id'];
        $file_name = $document['file_name'];
        $file_size = $document['file_size'];
        
        // Check file size (max 4GB)
        $max_size = get_option('twb_max_file_size', TWB_MAX_FILE_SIZE);
        if ($file_size > $max_size) {
            $this->send_message($chat_id, "❌ File size exceeds " . $this->format_size($max_size) . " limit");
            return;
        }
        
        // Check allowed extensions
        $allowed = explode("\n", get_option('twb_allowed_extensions', '*'));
        if ($allowed[0] !== '*') {
            $ext = pathinfo($file_name, PATHINFO_EXTENSION);
            if (!in_array($ext, $allowed)) {
                $this->send_message($chat_id, "❌ File type not allowed. Allowed types: " . implode(', ', $allowed));
                return;
            }
        }
        
        // Send processing message
        $this->send_message($chat_id, "📤 *Processing your file...*\n\nFile: `{$file_name}`\nSize: " . $this->format_size($file_size), 'Markdown');
        
        // Download file from Telegram
        $temp_file = $this->download_file($file_id);
        
        if (!$temp_file) {
            $this->send_message($chat_id, "❌ Failed to download file from Telegram");
            return;
        }
        
        // Upload to Wasabi with progress
        $remote_name = date('Y/m/d/') . $user_id . '_' . time() . '_' . $file_name;
        $upload_success = $this->wasabi->upload_file($temp_file, $remote_name, function($resource, $download_size, $downloaded, $upload_size, $uploaded) use ($chat_id) {
            if ($uploaded > 0) {
                $percent = round(($uploaded / $upload_size) * 100);
                if ($percent % 10 == 0) {
                    $this->send_message($chat_id, "📊 Upload progress: {$percent}%");
                }
            }
        });
        
        unlink($temp_file);
        
        if ($upload_success) {
            // Save to database
            $file_data = [
                'file_id' => $file_id,
                'file_name' => $file_name,
                'file_size' => $file_size,
                'file_type' => $document['mime_type'] ?? 'application/octet-stream',
                'mime_type' => $document['mime_type'] ?? 'application/octet-stream',
                'wasabi_path' => $remote_name,
                'user_id' => $message['from']['id'],
                'chat_id' => $chat_id
            ];
            
            $file_id_db = $this->db->save_file($file_data);
            $file_record = $this->db->get_file_by_token($file_data['stream_token']);
            
            // Generate streaming links
            $stream_url = home_url("/wp-json/twb/v1/stream/{$file_record->stream_token}");
            $download_url = home_url("/wp-json/twb/v1/download/{$file_record->stream_token}");
            
            // Send success message with links
            $success_msg = $this->generate_success_message($file_name, $file_size, $stream_url, $download_url);
            $this->send_message($chat_id, $success_msg, 'HTML');
            
            // Update user stats
            $this->update_user_stats($message['from']['id'], $file_size);
        } else {
            $this->send_message($chat_id, "❌ Failed to upload file to Wasabi storage. Please try again later.");
        }
    }
    
    private function handle_video($message) {
        $chat_id = $message['chat']['id'];
        $video = $message['video'];
        
        // Convert video to document for uniform handling
        $message['document'] = [
            'file_id' => $video['file_id'],
            'file_name' => $video['file_name'] ?? 'video_' . time() . '.mp4',
            'file_size' => $video['file_size'],
            'mime_type' => $video['mime_type'] ?? 'video/mp4'
        ];
        
        $this->handle_document($message);
    }
    
    private function handle_audio($message) {
        $chat_id = $message['chat']['id'];
        $audio = $message['audio'];
        
        $message['document'] = [
            'file_id' => $audio['file_id'],
            'file_name' => $audio['file_name'] ?? $audio['title'] . '.mp3',
            'file_size' => $audio['file_size'],
            'mime_type' => $audio['mime_type'] ?? 'audio/mpeg'
        ];
        
        $this->handle_document($message);
    }
    
    private function handle_photo($message) {
        $chat_id = $message['chat']['id'];
        $photo = end($message['photo']); // Get the largest photo
        
        $message['document'] = [
            'file_id' => $photo['file_id'],
            'file_name' => 'photo_' . time() . '.jpg',
            'file_size' => $photo['file_size'],
            'mime_type' => 'image/jpeg'
        ];
        
        $this->handle_document($message);
    }
    
    private function handle_command($message) {
        $chat_id = $message['chat']['id'];
        $text = trim($message['text']);
        
        switch($text) {
            case '/start':
                $this->send_start_message($chat_id);
                break;
            case '/help':
                $this->send_help_message($chat_id);
                break;
            case '/stats':
                $this->send_stats_message($chat_id, $message['from']['id']);
                break;
            case '/files':
                $this->send_files_list($chat_id, $message['from']['id']);
                break;
            default:
                if (strpos($text, '/') === 0) {
                    $this->send_message($chat_id, "❌ Unknown command. Use /help to see available commands.");
                }
        }
    }
    
    private function process_callback($callback) {
        $chat_id = $callback['message']['chat']['id'];
        $data = json_decode($callback['data'], true);
        
        switch($data['action']) {
            case 'delete_file':
                $this->delete_file_callback($chat_id, $data['file_id']);
                break;
            case 'refresh_link':
                $this->refresh_link_callback($chat_id, $data['file_id']);
                break;
        }
        
        // Answer callback
        $this->call_telegram_api('answerCallbackQuery', [
            'callback_query_id' => $callback['id']
        ]);
    }
    
    private function download_file($file_id) {
        $file_info = $this->call_telegram_api('getFile', ['file_id' => $file_id]);
        
        if (!$file_info || !$file_info['ok']) {
            return false;
        }
        
        $file_path = $file_info['result']['file_path'];
        $download_url = "https://api.telegram.org/file/bot{$this->bot_token}/{$file_path}";
        
        $temp_file = TWB_CACHE_DIR . uniqid() . '_' . basename($file_path);
        $fp = fopen($temp_file, 'w+');
        
        $ch = curl_init($download_url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        
        if ($http_code !== 200) {
            unlink($temp_file);
            return false;
        }
        
        return $temp_file;
    }
    
    public function upload_direct($file) {
        // Handle direct uploads from website
        $temp_file = $file['tmp_name'];
        $file_name = sanitize_file_name($file['name']);
        $file_size = $file['size'];
        
        $remote_name = date('Y/m/d/') . 'web_' . time() . '_' . $file_name;
        $upload_success = $this->wasabi->upload_file($temp_file, $remote_name);
        
        if ($upload_success) {
            $file_data = [
                'file_id' => uniqid(),
                'file_name' => $file_name,
                'file_size' => $file_size,
                'mime_type' => $file['type'],
                'wasabi_path' => $remote_name,
                'user_id' => get_current_user_id()
            ];
            
            $this->db->save_file($file_data);
            
            return [
                'success' => true,
                'stream_url' => home_url("/wp-json/twb/v1/stream/{$file_data['stream_token']}")
            ];
        }
        
        return ['success' => false, 'error' => 'Upload failed'];
    }
    
    private function send_message($chat_id, $text, $parse_mode = 'HTML', $reply_markup = null) {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => $parse_mode,
            'disable_web_page_preview' => true
        ];
        
        if ($reply_markup) {
            $params['reply_markup'] = json_encode($reply_markup);
        }
        
        return $this->call_telegram_api('sendMessage', $params);
    }
    
    private function send_start_message($chat_id) {
        $message = "🎉 *Welcome to Telegram Wasabi Bot!*\n\n";
        $message .= "I'm a powerful bot that stores your files on Wasabi cloud storage and provides high-speed streaming links.\n\n";
        $message .= "✨ *Features:*\n";
        $message .= "✓ Upload files up to 4GB\n";
        $message .= "✓ Generate streaming links for MX Player & VLC\n";
        $message .= "✓ High-speed downloads from Wasabi CDN\n";
        $message .= "✓ Support for all file types\n";
        $message .= "✓ Secure and private\n\n";
        $message .= "📱 *How to use:*\n";
        $message .= "Simply send me any file (document, video, audio, etc.)\n\n";
        $message .= "🔗 *Streaming:*\n";
        $message .= "Copy the generated link and paste in:\n";
        $message .= "• MX Player (Network Stream)\n";
        $message .= "• VLC Media Player (Open Network Stream)\n\n";
        $message .= "Type /help for more commands";
        
        $this->send_message($chat_id, $message, 'Markdown');
    }
    
    private function send_help_message($chat_id) {
        $message = "📖 *Help Guide*\n\n";
        $message .= "*Available Commands:*\n";
        $message .= "/start - Welcome message\n";
        $message .= "/help - Show this help\n";
        $message .= "/stats - Your statistics\n";
        $message .= "/files - List your uploaded files\n\n";
        $message .= "*Upload Methods:*\n";
        $message .= "1. Send any file directly to this bot\n";
        $message .= "2. Use forward from other chats\n";
        $message .= "3. Website upload form\n\n";
        $message .= "*Streaming Instructions:*\n";
        $message .= "**MX Player:**\n";
        $message .= "• Open MX Player\n";
        $message .= "• Tap Menu → Network Stream\n";
        $message .= "• Paste the streaming link\n";
        $message .= "• Tap OK\n\n";
        $message .= "**VLC Player:**\n";
        $message .= "• Open VLC\n";
        $message .= "• Tap Network → Open Network Stream\n";
        $message .= "• Paste the streaming link\n";
        $message .= "• Tap Play\n\n";
        $message .= "*Limitations:*\n";
        $message .= "• Maximum file size: " . $this->format_size(get_option('twb_max_file_size', TWB_MAX_FILE_SIZE)) . "\n";
        $message .= "• Download links expire after " . get_option('twb_stream_expiry_hours', 24) . " hours\n";
        $message .= "• Rate limit: " . get_option('twb_rate_limit_per_user', 10) . " files per hour\n\n";
        $message .= "For support, contact @YourSupport";
        
        $this->send_message($chat_id, $message, 'Markdown');
    }
    
    private function send_stats_message($chat_id, $user_id) {
        $stats = $this->db->get_user_stats($user_id);
        
        if (!$stats) {
            $message = "📊 *Your Statistics*\n\n";
            $message .= "Total uploads: 0\n";
            $message .= "Total downloads: 0\n";
            $message .= "Storage used: 0 GB";
        } else {
            $message = "📊 *Your Statistics*\n\n";
            $message .= "Total uploads: *{$stats->total_uploads}*\n";
            $message .= "Total downloads: *{$stats->total_downloads}*\n";
            $message .= "Storage used: *" . $this->format_size($stats->total_size) . "*\n";
            $message .= "Last active: *{$stats->last_active}*";
        }
        
        $this->send_message($chat_id, $message, 'Markdown');
    }
    
    private function send_files_list($chat_id, $user_id) {
        $files = $this->db->get_public_files();
        $user_files = array_filter($files, function($file) use ($user_id) {
            return $file->user_id == $user_id;
        });
        
        if (empty($user_files)) {
            $this->send_message($chat_id, "📂 You haven't uploaded any files yet.");
            return;
        }
        
        $message = "📂 *Your Files*\n\n";
        foreach (array_slice($user_files, 0, 10) as $file) {
            $message .= "📄 `{$file->file_name}`\n";
            $message .= "   Size: " . $this->format_size($file->file_size) . "\n";
            $message .= "   Downloads: {$file->download_count}\n";
            $message .= "   🎬 <a href='" . home_url("/wp-json/twb/v1/stream/{$file->stream_token}") . "'>Stream</a>\n\n";
        }
        
        if (count($user_files) > 10) {
            $message .= "_And " . (count($user_files) - 10) . " more files..._";
        }
        
        $this->send_message($chat_id, $message, 'HTML');
    }
    
    private function generate_success_message($file_name, $file_size, $stream_url, $download_url) {
        $expiry_hours = get_option('twb_stream_expiry_hours', 24);
        
        $message = "✅ *File Uploaded Successfully!*\n\n";
        $message .= "📁 *Filename:* `{$file_name}`\n";
        $message .= "📊 *Size:* " . $this->format_size($file_size) . "\n";
        $message .= "⏰ *Link expires:* {$expiry_hours} hours\n\n";
        $message .= "🎬 *Streaming Link:*\n";
        $message .= "<code>{$stream_url}</code>\n\n";
        $message .= "💾 *Download Link:*\n";
        $message .= "<code>{$download_url}</code>\n\n";
        $message .= "📱 *How to stream:*\n";
        $message .= "1. Copy the streaming link\n";
        $message .= "2. Open MX Player or VLC Player\n";
        $message .= "3. Select 'Network Stream'\n";
        $message .= "4. Paste and enjoy!\n\n";
        $message .= "⚠️ *Note:* Links are valid for {$expiry_hours} hours only.";
        
        // Add inline keyboard buttons
        $reply_markup = [
            'inline_keyboard' => [
                [
                    ['text' => '🎬 Stream Now', 'url' => $stream_url],
                    ['text' => '💾 Download', 'url' => $download_url]
                ],
                [
                    ['text' => '📋 Copy Links', 'callback_data' => json_encode(['action' => 'copy_links'])],
                    ['text' => '❌ Delete File', 'callback_data' => json_encode(['action' => 'delete_file'])]
                ]
            ]
        ];
        
        $this->send_message($chat_id, $message, 'HTML', $reply_markup);
        
        return $message;
    }
    
    private function call_telegram_api($method, $params = []) {
        $url = "{$this->api_url}/{$method}";
        
        $response = wp_remote_post($url, [
            'body' => $params,
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log("Telegram API Error: " . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function check_rate_limit($user_id) {
        $limit = get_option('twb_rate_limit_per_user', 10);
        $window = HOUR_IN_SECONDS;
        
        $transient_key = "twb_rate_limit_{$user_id}";
        $uploads = get_transient($transient_key);
        
        if ($uploads === false) {
            set_transient($transient_key, 1, $window);
            return false;
        }
        
        if ($uploads >= $limit) {
            return true;
        }
        
        set_transient($transient_key, $uploads + 1, $window);
        return false;
    }
    
    private function update_user_stats($user_id, $file_size) {
        $stats = $this->db->get_user_stats($user_id);
        
        if ($stats) {
            $this->db->save_user([
                'user_id' => $user_id,
                'total_uploads' => $stats->total_uploads + 1,
                'total_size' => $stats->total_size + $file_size,
                'last_active' => current_time('mysql')
            ]);
        }
    }
    
    private function format_size($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
