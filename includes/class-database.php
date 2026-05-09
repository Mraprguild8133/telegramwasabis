<?php
class TWB_Database {
    
    private $table_files;
    private $table_users;
    private $table_downloads;
    
    public function __construct() {
        global $wpdb;
        $this->table_files = $wpdb->prefix . 'twb_files';
        $this->table_users = $wpdb->prefix . 'twb_users';
        $this->table_downloads = $wpdb->prefix . 'twb_downloads';
    }
    
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Files table
        $sql1 = "CREATE TABLE IF NOT EXISTS {$this->table_files} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            file_id varchar(255) NOT NULL,
            file_name varchar(255) NOT NULL,
            file_size bigint(20) NOT NULL,
            file_type varchar(100),
            mime_type varchar(100),
            wasabi_path varchar(500),
            stream_token varchar(100) UNIQUE,
            user_id bigint(20),
            chat_id bigint(20),
            download_count int(11) DEFAULT 0,
            stream_count int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime,
            status varchar(20) DEFAULT 'active',
            PRIMARY KEY (id),
            KEY file_id (file_id),
            KEY stream_token (stream_token),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Users table
        $sql2 = "CREATE TABLE IF NOT EXISTS {$this->table_users} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            username varchar(255),
            first_name varchar(255),
            last_name varchar(255),
            total_uploads int(11) DEFAULT 0,
            total_downloads int(11) DEFAULT 0,
            total_size bigint(20) DEFAULT 0,
            last_active datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) $charset_collate;";
        
        // Downloads table
        $sql3 = "CREATE TABLE IF NOT EXISTS {$this->table_downloads} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            file_id bigint(20) NOT NULL,
            user_id bigint(20),
            ip_address varchar(45),
            user_agent text,
            download_type varchar(20),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY file_id (file_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }
    
    public function save_file($data) {
        global $wpdb;
        $data['stream_token'] = $this->generate_token();
        $wpdb->insert($this->table_files, $data);
        return $wpdb->insert_id;
    }
    
    public function get_file_by_token($token) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_files} WHERE stream_token = %s AND status = 'active'",
            $token
        ));
    }
    
    public function update_file_stats($file_id, $type) {
        global $wpdb;
        $column = $type === 'download' ? 'download_count' : 'stream_count';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_files} SET {$column} = {$column} + 1 WHERE id = %d",
            $file_id
        ));
    }
    
    public function save_user($user_data) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_users} WHERE user_id = %d",
            $user_data['user_id']
        ));
        
        if ($exists) {
            $wpdb->update($this->table_users, $user_data, ['user_id' => $user_data['user_id']]);
        } else {
            $wpdb->insert($this->table_users, $user_data);
        }
    }
    
    public function save_download($data) {
        global $wpdb;
        $wpdb->insert($this->table_downloads, $data);
    }
    
    public function get_user_stats($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_users} WHERE user_id = %d",
            $user_id
        ));
    }
    
    public function get_public_files($limit = 50) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_files} WHERE status = 'active' ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }
    
    public function get_total_storage_usage() {
        global $wpdb;
        return $wpdb->get_var("SELECT SUM(file_size) FROM {$this->table_files} WHERE status = 'active'");
    }
    
    public function get_total_files_count() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_files} WHERE status = 'active'");
    }
    
    public function count_unique_users() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$this->table_files} WHERE user_id IS NOT NULL");
    }
    
    public function get_monthly_uploads() {
        global $wpdb;
        return $wpdb->get_results("
            SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count 
            FROM {$this->table_files} 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month DESC
        ");
    }
    
    private function generate_token($length = 32) {
        return bin2hex(random_bytes($length));
    }
}
