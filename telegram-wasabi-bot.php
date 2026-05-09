<?php
/**
 * Plugin Name: Telegram Wasabi Bot Pro
 * Plugin URI: https://yourdomain.com/telegram-wasabi-bot
 * Description: Advanced Telegram bot with Wasabi cloud storage - Support files up to 4GB, streaming links for MX Player & VLC
 * Version: 2.0.0
 * Author: Mraprguild 
 * Author URI: https://yourdomain.com
 * License: GPL v3
 * Text Domain: twb-pro
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TWB_VERSION', '2.0.0');
define('TWB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TWB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TWB_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('TWB_CACHE_DIR', WP_CONTENT_DIR . '/cache/twb/');
define('TWB_MAX_FILE_SIZE', 4294967296); // 4GB
define('TWB_CHUNK_SIZE', 5242880); // 5MB chunks for large files

// Include required files
require_once TWB_PLUGIN_DIR . 'includes/class-database.php';
require_once TWB_PLUGIN_DIR . 'includes/class-wasabi-handler.php';
require_once TWB_PLUGIN_DIR . 'includes/class-telegram-handler.php';
require_once TWB_PLUGIN_DIR . 'includes/class-stream-handler.php';

// Main Plugin Class
class TelegramWasabiBotPro {
    
    private static $instance = null;
    private $db;
    private $wasabi;
    private $telegram;
    private $stream;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
        $this->init_classes();
    }
    
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('rest_api_init', [$this, 'register_endpoints']);
        add_action('wp_ajax_twb_upload', [$this, 'handle_ajax_upload']);
        add_action('wp_ajax_nopriv_twb_upload', [$this, 'handle_ajax_upload']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Shortcodes
        add_shortcode('twb_uploader', [$this, 'render_uploader']);
        add_shortcode('twb_files_list', [$this, 'render_files_list']);
        
        // Cron jobs
        add_action('twb_cleanup_temp_files', [$this, 'cleanup_temp_files']);
        add_action('twb_update_stats', [$this, 'update_statistics']);
    }
    
    private function init_classes() {
        $this->db = new TWB_Database();
        $this->wasabi = new TWB_Wasabi_Handler();
        $this->telegram = new TWB_Telegram_Handler();
        $this->stream = new TWB_Stream_Handler();
    }
    
    public function activate() {
        // Create database tables
        $this->db->create_tables();
        
        // Create cache directory
        if (!file_exists(TWB_CACHE_DIR)) {
            wp_mkdir_p(TWB_CACHE_DIR);
        }
        
        // Set up cron jobs
        if (!wp_next_scheduled('twb_cleanup_temp_files')) {
            wp_schedule_event(time(), 'hourly', 'twb_cleanup_temp_files');
        }
        
        if (!wp_next_scheduled('twb_update_stats')) {
            wp_schedule_event(time(), 'daily', 'twb_update_stats');
        }
        
        // Set default options
        add_option('twb_version', TWB_VERSION);
        add_option('twb_total_uploads', 0);
        add_option('twb_total_size', 0);
        
        // Create .htaccess for security
        $this->create_htaccess();
    }
    
    public function deactivate() {
        // Clear cron jobs
        wp_clear_scheduled_hook('twb_cleanup_temp_files');
        wp_clear_scheduled_hook('twb_update_stats');
        
        // Clear cache
        $this->cleanup_temp_files(true);
    }
    
    private function create_htaccess() {
        $htaccess_content = "
# TWB Security
<FilesMatch \"\.(php|php\.\d+|phtml)$\">
    Order Deny,Allow
    Deny from all
</FilesMatch>

Options -Indexes
RewriteEngine On
RewriteRule ^.*$ - [F,L]
        ";
        file_put_contents(TWB_CACHE_DIR . '.htaccess', trim($htaccess_content));
    }
    
    public function init() {
        // Load text domain
        load_plugin_textdomain('twb-pro', false, dirname(TWB_PLUGIN_BASENAME) . '/languages');
        
        // Check and set webhook
        if (get_option('twb_auto_set_webhook')) {
            $this->telegram->set_webhook();
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('Telegram Wasabi Bot', 'twb-pro'),
            __('TG Wasabi Bot', 'twb-pro'),
            'manage_options',
            'twb-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-cloud-upload',
            30
        );
        
        add_submenu_page(
            'twb-dashboard',
            __('Settings', 'twb-pro'),
            __('Settings', 'twb-pro'),
            'manage_options',
            'twb-settings',
            [$this, 'render_settings']
        );
        
        add_submenu_page(
            'twb-dashboard',
            __('Files Manager', 'twb-pro'),
            __('Files Manager', 'twb-pro'),
            'manage_options',
            'twb-files',
            [$this, 'render_files_manager']
        );
        
        add_submenu_page(
            'twb-dashboard',
            __('Statistics', 'twb-pro'),
            __('Statistics', 'twb-pro'),
            'manage_options',
            'twb-stats',
            [$this, 'render_statistics']
        );
    }
    
    public function render_dashboard() {
        include TWB_PLUGIN_DIR . 'assets/templates/admin-dashboard.php';
    }
    
    public function render_settings() {
        if (isset($_POST['submit'])) {
            check_admin_referer('twb_settings');
            $this->save_settings();
        }
        include TWB_PLUGIN_DIR . 'assets/templates/admin-settings.php';
    }
    
    private function save_settings() {
        $settings = [
            'api_id' => sanitize_text_field($_POST['api_id']),
            'api_hash' => sanitize_text_field($_POST['api_hash']),
            'bot_token' => sanitize_text_field($_POST['bot_token']),
            'wasabi_access_key' => sanitize_text_field($_POST['wasabi_access_key']),
            'wasabi_secret_key' => sanitize_text_field($_POST['wasabi_secret_key']),
            'wasabi_bucket' => sanitize_text_field($_POST['wasabi_bucket']),
            'wasabi_region' => sanitize_text_field($_POST['wasabi_region']),
            'max_file_size' => intval($_POST['max_file_size']),
            'allowed_extensions' => sanitize_textarea_field($_POST['allowed_extensions']),
            'stream_expiry_hours' => intval($_POST['stream_expiry_hours']),
            'enable_watermark' => isset($_POST['enable_watermark']) ? 1 : 0,
            'auto_delete_days' => intval($_POST['auto_delete_days']),
            'rate_limit_per_user' => intval($_POST['rate_limit_per_user'])
        ];
        
        foreach ($settings as $key => $value) {
            update_option('twb_' . $key, $value);
        }
        
        // Update webhook if token changed
        if (get_option('twb_auto_set_webhook')) {
            $this->telegram->set_webhook();
        }
        
        add_settings_error('twb_messages', 'twb_message', 
            __('Settings Saved Successfully!', 'twb-pro'), 'updated');
    }
    
    public function render_files_manager() {
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        switch($action) {
            case 'delete':
                $this->delete_file();
                break;
            case 'edit':
                $this->edit_file();
                break;
            default:
                $this->list_files();
        }
    }
    
    public function render_statistics() {
        $stats = [
            'total_uploads' => get_option('twb_total_uploads', 0),
            'total_size' => get_option('twb_total_size', 0),
            'total_users' => $this->db->count_unique_users(),
            'monthly_uploads' => $this->db->get_monthly_uploads(),
            'storage_usage' => $this->wasabi->get_storage_usage()
        ];
        include TWB_PLUGIN_DIR . 'assets/templates/admin-stats.php';
    }
    
    public function register_endpoints() {
        // Webhook endpoint
        register_rest_route('twb/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this->telegram, 'handle_webhook'],
            'permission_callback' => '__return_true'
        ]);
        
        // Stream endpoint
        register_rest_route('twb/v1', '/stream/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this->stream, 'handle_stream'],
            'permission_callback' => '__return_true'
        ]);
        
        // Download endpoint
        register_rest_route('twb/v1', '/download/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this->stream, 'handle_download'],
            'permission_callback' => '__return_true'
        ]);
        
        // File info endpoint
        register_rest_route('twb/v1', '/info/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [$this->stream, 'get_file_info'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    public function handle_ajax_upload() {
        check_ajax_referer('twb_upload', 'nonce');
        
        if (!isset($_FILES['file'])) {
            wp_send_json_error('No file uploaded');
        }
        
        $result = $this->telegram->upload_direct($_FILES['file']);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    public function enqueue_assets() {
        if (has_shortcode(get_post()->post_content, 'twb_uploader')) {
            wp_enqueue_style('twb-style', TWB_PLUGIN_URL . 'assets/css/style.css', [], TWB_VERSION);
            wp_enqueue_script('twb-upload', TWB_PLUGIN_URL . 'assets/js/upload.js', ['jquery'], TWB_VERSION, true);
            wp_localize_script('twb-upload', 'twb_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('twb_upload'),
                'max_size' => get_option('twb_max_file_size', TWB_MAX_FILE_SIZE)
            ]);
        }
    }
    
    public function render_uploader($atts) {
        $atts = shortcode_atts([
            'max_size' => get_option('twb_max_file_size', TWB_MAX_FILE_SIZE),
            'allowed_types' => '*',
            'multiple' => false
        ], $atts);
        
        ob_start();
        include TWB_PLUGIN_DIR . 'assets/templates/upload-form.php';
        return ob_get_clean();
    }
    
    public function render_files_list() {
        $files = $this->db->get_public_files();
        ob_start();
        include TWB_PLUGIN_DIR . 'assets/templates/files-list.php';
        return ob_get_clean();
    }
    
    public function cleanup_temp_files($force = false) {
        $files = glob(TWB_CACHE_DIR . '*');
        $now = time();
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $file_age = $now - filemtime($file);
                if ($force || $file_age > 86400) { // 24 hours
                    unlink($file);
                }
            }
        }
    }
    
    public function update_statistics() {
        $total_size = $this->db->get_total_storage_usage();
        $total_files = $this->db->get_total_files_count();
        
        update_option('twb_total_size', $total_size);
        update_option('twb_total_uploads', $total_files);
    }
}

// Initialize plugin
function TWB() {
    return TelegramWasabiBotPro::get_instance();
}

TWB();
