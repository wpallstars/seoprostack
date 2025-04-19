<?php
/**
 * Auto Upload Images functionality
 *
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Auto_Upload {
    /**
     * Initialize the class
     */
    public function __construct() {
        add_filter('content_save_pre', array($this, 'process_content'));
        add_action('wp_allstars_image_upload_error', array($this, 'log_error'), 10, 2);
    }

    /**
     * Process content for external images
     *
     * @param string $content The post content
     * @return string Modified content with local image URLs
     */
    public function process_content($content) {
        // Check if auto upload is enabled
        $options = get_option('wp_allstars_workflow_options', array('auto_upload_images' => false));
        if (!$options['auto_upload_images']) {
            return $content;
        }

        // Regular expression to find image URLs
        $pattern = '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i';
        
        return preg_replace_callback($pattern, array($this, 'process_image_url'), $content);
    }

    /**
     * Process individual image URL
     *
     * @param array $matches Regex matches
     * @return string Updated img tag
     */
    private function process_image_url($matches) {
        if (empty($matches[1])) {
            return $matches[0];
        }

        $url = $matches[1];

        // Skip if already a local URL
        if ($this->is_local_url($url)) {
            return $matches[0];
        }

        try {
            $local_url = $this->upload_image($url);
            if ($local_url) {
                return str_replace($url, $local_url, $matches[0]);
            }
        } catch (Exception $e) {
            // Trigger error action for logging
            do_action('wp_allstars_image_upload_error', esc_url($url), $e->getMessage());
        }

        return $matches[0];
    }

    /**
     * Check if URL is local
     *
     * @param string $url URL to check
     * @return boolean
     */
    private function is_local_url($url) {
        $site_url = parse_url(get_site_url(), PHP_URL_HOST);
        $image_host = parse_url($url, PHP_URL_HOST);
        return $site_url === $image_host;
    }

    /**
     * Upload external image to media library
     *
     * @param string $url External image URL
     * @return string|false Local URL on success, false on failure
     * @throws Exception If download or upload fails
     */
    private function upload_image($url) {
        $file_array = array(
            'name' => sanitize_file_name(basename($url))
        );

        // Download file to temp location
        $file_array['tmp_name'] = download_url($url);

        if (is_wp_error($file_array['tmp_name'])) {
            throw new Exception('Failed to download image: ' . $file_array['tmp_name']->get_error_message());
        }

        // Check file type for security
        $wp_filetype = wp_check_filetype_and_ext($file_array['tmp_name'], $file_array['name']);
        if (!$wp_filetype['type']) {
            unlink($file_array['tmp_name']);
            throw new Exception('Invalid file type');
        }

        // Upload the file to media library
        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            unlink($file_array['tmp_name']);
            throw new Exception('Failed to upload image: ' . $attachment_id->get_error_message());
        }

        return wp_get_attachment_url($attachment_id);
    }

    /**
     * Log errors to WordPress debug log
     *
     * @param string $url URL that failed
     * @param string $error Error message
     */
    public function log_error($url, $error) {
        if (WP_DEBUG) {
            error_log(sprintf(
                '[WP ALLSTARS] Auto Upload Images Error - URL: %s, Error: %s',
                esc_url_raw($url),
                sanitize_text_field($error)
            ));
        }
    }
}