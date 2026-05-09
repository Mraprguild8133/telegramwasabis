<?php
class TWB_Wasabi_Handler {
    
    private $access_key;
    private $secret_key;
    private $bucket;
    private $region;
    private $endpoint;
    
    public function __construct() {
        $this->access_key = get_option('twb_wasabi_access_key');
        $this->secret_key = get_option('twb_wasabi_secret_key');
        $this->bucket = get_option('twb_wasabi_bucket');
        $this->region = get_option('twb_wasabi_region', 'us-east-1');
        $this->endpoint = "https://s3.{$this->region}.wasabisys.com";
    }
    
    public function upload_file($local_path, $remote_name, $progress_callback = null) {
        $url = "{$this->endpoint}/{$this->bucket}/" . rawurlencode($remote_name);
        
        $file_size = filesize($local_path);
        $file_handle = fopen($local_path, 'r');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $file_handle);
        curl_setopt($ch, CURLOPT_INFILESIZE, $file_size);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->get_headers('PUT', $remote_name, $file_size));
        
        // Progress callback
        if ($progress_callback) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $progress_callback);
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($file_handle);
        
        return $http_code === 200;
    }
    
    public function download_file($remote_name, $local_path) {
        $url = $this->get_signed_url($remote_name);
        
        $ch = curl_init($url);
        $file_handle = fopen($local_path, 'w');
        
        curl_setopt($ch, CURLOPT_FILE, $file_handle);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($file_handle);
        
        return $http_code === 200;
    }
    
    public function delete_file($remote_name) {
        $url = "{$this->endpoint}/{$this->bucket}/" . rawurlencode($remote_name);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->get_headers('DELETE', $remote_name));
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 204;
    }
    
    public function file_exists($remote_name) {
        $url = "{$this->endpoint}/{$this->bucket}/" . rawurlencode($remote_name);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->get_headers('HEAD', $remote_name));
        
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 200;
    }
    
    public function get_file_url($remote_name, $expires = 3600) {
        return $this->get_signed_url($remote_name, $expires);
    }
    
    public function get_storage_usage() {
        // Use Wasabi API to get bucket size
        // This is simplified - implement full bucket stats via AWS SDK
        return [
            'total' => $this->estimate_bucket_size(),
            'files' => $this->count_bucket_files()
        ];
    }
    
    public function upload_chunk($file_path, $remote_name, $chunk_index, $total_chunks) {
        $chunk_size = TWB_CHUNK_SIZE;
        $file_handle = fopen($file_path, 'r');
        fseek($file_handle, $chunk_index * $chunk_size);
        $chunk_data = fread($file_handle, $chunk_size);
        fclose($file_handle);
        
        $upload_id = $this->initiate_multipart_upload($remote_name);
        $etag = $this->upload_part($upload_id, $chunk_index + 1, $chunk_data);
        
        if ($chunk_index + 1 == $total_chunks) {
            return $this->complete_multipart_upload($upload_id, $remote_name);
        }
        
        return ['upload_id' => $upload_id, 'etag' => $etag];
    }
    
    private function initiate_multipart_upload($remote_name) {
        $url = "{$this->endpoint}/{$this->bucket}/" . rawurlencode($remote_name) . "?uploads";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->get_headers('POST', $remote_name));
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        // Parse UploadId from response
        preg_match('/<UploadId>(.*?)<\/UploadId>/', $response, $matches);
        return $matches[1] ?? null;
    }
    
    private function upload_part($upload_id, $part_number, $data) {
        // Implement part upload
        return md5($data);
    }
    
    private function complete_multipart_upload($upload_id, $remote_name) {
        // Implement complete multipart upload
        return true;
    }
    
    private function get_headers($method, $key, $content_length = null) {
        $date = gmdate('D, d M Y H:i:s T');
        $headers = [
            "Date: {$date}",
            "Host: s3.{$this->region}.wasabisys.com"
        ];
        
        if ($content_length) {
            $headers[] = "Content-Length: {$content_length}";
        }
        
        $string_to_sign = "{$method}\n\n\n{$date}\n/{$this->bucket}/{$key}";
        $signature = base64_encode(hash_hmac('sha1', $string_to_sign, $this->secret_key, true));
        $headers[] = "Authorization: AWS {$this->access_key}:{$signature}";
        
        return $headers;
    }
    
    private function get_signed_url($key, $expires = 3600) {
        $expiration = time() + $expires;
        $string_to_sign = "GET\n\n\n{$expiration}\n/{$this->bucket}/{$key}";
        $signature = rawurlencode(base64_encode(hash_hmac('sha1', $string_to_sign, $this->secret_key, true)));
        
        return "{$this->endpoint}/{$this->bucket}/" . rawurlencode($key) . 
               "?AWSAccessKeyId={$this->access_key}&Expires={$expiration}&Signature={$signature}";
    }
    
    private function estimate_bucket_size() {
        // Implement bucket size estimation
        return 0;
    }
    
    private function count_bucket_files() {
        // Implement file counting
        return 0;
    }
}
