<?php
class TWB_Stream_Handler {
    
    private $wasabi;
    private $db;
    
    public function __construct() {
        $this->wasabi = new TWB_Wasabi_Handler();
        $this->db = new TWB_Database();
    }
    
    public function handle_stream($request) {
        $token = $request['token'];
        $file = $this->db->get_file_by_token($token);
        
        if (!$file) {
            return $this->send_error("File not found", 404);
        }
        
        // Check expiry
        if ($file->expires_at && strtotime($file->expires_at) < time()) {
            return $this->send_error("Link expired", 410);
        }
        
        // Update stream count
        $this->db->update_file_stats($file->id, 'stream');
        
        // Get file from Wasabi and stream
        $file_url = $this->wasabi->get_file_url($file->wasabi_path, 3600);
        
        // Handle range requests for seeking
        $this->stream_file($file_url, $file);
        
        return null;
    }
    
    public function handle_download($request) {
        $token = $request['token'];
        $file = $this->db->get_file_by_token($token);
        
        if (!$file) {
            return $this->send_error("File not found", 404);
        }
        
        // Update download count
        $this->db->update_file_stats($file->id, 'download');
        
        // Log download
        $this->db->save_download([
            'file_id' => $file->id,
            'user_id' => $this->get_user_id(),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'download_type' => 'direct'
        ]);
        
        // Force download
        $file_url = $this->wasabi->get_file_url($file->wasabi_path, 3600);
        wp_redirect($file_url);
        exit;
    }
    
    public function get_file_info($request) {
        $token = $request['token'];
        $file = $this->db->get_file_by_token($token);
        
        if (!$file) {
            return new WP_REST_Response(['error' => 'File not found'], 404);
        }
        
        return new WP_REST_Response([
            'filename' => $file->file_name,
            'size' => $file->file_size,
            'type' => $file->mime_type,
            'downloads' => $file->download_count,
            'streams' => $file->stream_count,
            'expires' => $file->expires_at
        ], 200);
    }
    
    private function stream_file($file_url, $file) {
        $ch = curl_init($file_url);
        
        // Handle range requests
        $range = $this->get_range_header();
        if ($range) {
            curl_setopt($ch, CURLOPT_RANGE, $range);
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // Set proper headers
        header('Content-Type: ' . $file->mime_type);
        header('Content-Length: ' . $file->file_size);
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-cache');
        
        if ($range) {
            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes ' . $range . '/' . $file->file_size);
        }
        
        // Stream the file
        curl_exec($ch);
        curl_close($ch);
    }
    
    private function get_range_header() {
        if (isset($_SERVER['HTTP_RANGE'])) {
            preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches);
            $start = $matches[1];
            $end = isset($matches[2]) ? $matches[2] : '';
            return $start . '-' . $end;
        }
        return null;
    }
    
    private function send_error($message, $code) {
        status_header($code);
        wp_die($message, '', ['response' => $code]);
    }
    
    private function get_user_id() {
        if (is_user_logged_in()) {
            return get_current_user_id();
        }
        return null;
    }
}
