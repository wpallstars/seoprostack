<?php
/**
 * Auto Upload Images functionality for WP Allstars Plugin.
 *
 * Automatically downloads external images found in post content upon saving
 * and uploads them to the WordPress media library, replacing the external URLs.
 *
 * @package WPALLSTARS
 * @subpackage Core
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class WPALLSTARS_Auto_Upload
 *
 * Manages the automatic uploading of external images embedded in post content.
 */
class WPALLSTARS_Auto_Upload {

    /**
     * Initialize the hooks for the Auto Upload feature.
     */
    public function __construct() {
        // Hook into content saving process. Priority 10 is standard.
        add_filter('content_save_pre', array($this, 'process_content_for_images'), 10, 1);

        // Hook for logging errors, allowing other parts of the plugin or themes to hook in too.
        add_action('wpallstars_image_upload_error', array($this, 'log_upload_error'), 10, 2);
    }

    /**
     * Process post content before saving to find and upload external images.
     *
     * Checks if the feature is enabled via settings before processing.
     *
     * @param string $content The raw post content being saved.
     * @return string Modified content with external image URLs replaced by local ones,
     *                or the original content if the feature is disabled or no images are found.
     */
    public function process_content_for_images($content) {
        // Retrieve workflow options, providing a default for 'auto_upload_images'
        $workflow_options = get_option('wpallstars_workflow_options', array('auto_upload_images' => 0)); // Default to disabled (0)

        // Check if the 'auto_upload_images' setting is enabled (expecting '1' if enabled)
        if (empty($workflow_options['auto_upload_images']) || $workflow_options['auto_upload_images'] != '1') {
            return $content; // Feature disabled, return original content.
        }

        // Regular expression to find <img> tags and capture their src attribute.
        // This pattern is case-insensitive (i) and handles single or double quotes.
        $pattern = '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i';

        // Use preg_replace_callback to process each found image URL individually.
        $modified_content = preg_replace_callback($pattern, array($this, 'replace_image_url_callback'), $content);

        // Return the modified content, or the original if preg_replace_callback encountered an error.
        return (null === $modified_content) ? $content : $modified_content;
    }

    /**
     * Callback function for preg_replace_callback. Processes a single image match.
     *
     * @param array $matches An array of matches from preg_replace_callback.
     *                       $matches[0] is the full <img> tag.
     *                       $matches[1] is the URL from the src attribute.
     * @return string The original <img> tag if the URL is local or upload fails,
     *                or the modified <img> tag with the new local URL.
     */
    private function replace_image_url_callback($matches) {
        // Ensure the URL capture group exists.
        if (empty($matches[1])) {
            return $matches[0]; // Return original tag if no URL found.
        }

        $external_url = trim($matches[1]);

        // Validate the URL format (basic check).
        if (filter_var($external_url, FILTER_VALIDATE_URL) === false) {
             // Optionally log invalid URL format if needed
             // $this->log_upload_error($external_url, 'Invalid URL format detected');
            return $matches[0]; // Invalid URL, skip.
        }

        // Skip if the URL is already pointing to the local site.
        if ($this->is_local_image_url($external_url)) {
            return $matches[0]; // Already local, no need to process.
        }

        try {
            // Attempt to upload the image.
            $local_url = $this->sideload_image($external_url);

            // If upload is successful, replace the URL in the img tag.
            if ($local_url) {
                // Use str_replace for simple replacement within the matched tag.
                // This is generally safe as we're replacing the exact matched URL string.
                return str_replace($external_url, esc_url($local_url), $matches[0]);
            }
        } catch (Exception $e) {
            // Trigger a WordPress action to log the error.
            // Pass the original external URL and the exception message.
            do_action('wpallstars_image_upload_error', esc_url($external_url), $e->getMessage());
        }

        // Return the original <img> tag if the upload failed or an exception occurred.
        return $matches[0];
    }

    /**
     * Check if a given image URL belongs to the local WordPress site.
     *
     * Compares the host of the image URL with the host of the site URL.
     *
     * @param string $url The image URL to check.
     * @return bool True if the URL is local, false otherwise.
     */
    private function is_local_image_url($url) {
        // Get the host part of the site URL (e.g., '[www.example.com](www.example.com)').
        $site_host = parse_url(get_site_url(), PHP_URL_HOST);

        // Get the host part of the image URL.
        $image_host = parse_url($url, PHP_URL_HOST);

        // Return true if the hosts match, false otherwise.
        // Use strtolower for case-insensitive comparison.
        return !empty($site_host) && !empty($image_host) && strtolower($site_host) === strtolower($image_host);
    }

    /**
     * Download an external image and upload it to the WordPress media library.
     *
     * Uses WordPress core functions download_url() and media_handle_sideload().
     *
     * @param string $external_url The URL of the external image to download.
     * @return string|false The local URL of the uploaded image on success, false on failure.
     * @throws Exception If downloading or uploading fails, or if the file type is invalid.
     */
    private function sideload_image($external_url) {
        // Ensure required WordPress core files for media handling are loaded.
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php'; // For potential image processing

        $file_array = array();
        // Sanitize filename derived from the URL path.
        $file_array['name'] = sanitize_file_name(basename(parse_url($external_url, PHP_URL_PATH)));

        // Download the file to a temporary location.
        // Set timeout to prevent long waits for unresponsive URLs.
        $timeout_seconds = 15;
        $temp_file_path = download_url($external_url, $timeout_seconds);

        // Check for download errors.
        if (is_wp_error($temp_file_path)) {
             // Clean up temporary file if it exists, even on error
            if (file_exists($temp_file_path)) {
                 @unlink($temp_file_path);
            }
            throw new Exception(sprintf('Failed to download image: %s', $temp_file_path->get_error_message()));
        }

        // Check file type for security. Associate with the temporary file.
        $file_array['tmp_name'] = $temp_file_path;
        $wp_filetype = wp_check_filetype_and_ext($file_array['tmp_name'], $file_array['name']);

        // Check if the file type is allowed by WordPress.
        if (empty($wp_filetype['ext']) || empty($wp_filetype['type']) || !in_array(strtolower($wp_filetype['ext']), get_allowed_mime_types()) && !in_array($wp_filetype['type'], get_allowed_mime_types())) {
            unlink($file_array['tmp_name']); // Clean up the temporary file.
            throw new Exception(sprintf('Invalid or disallowed file type detected: %s', $wp_filetype['type'] ?: 'Unknown'));
        }


        // Upload the file from the temporary location to the media library.
        // The post ID '0' indicates the attachment is not associated with any specific post.
        $attachment_id = media_handle_sideload($file_array, 0);

        // Check for errors during the sideloading process.
        if (is_wp_error($attachment_id)) {
            unlink($file_array['tmp_name']); // Clean up the temporary file.
            throw new Exception(sprintf('Failed to upload image: %s', $attachment_id->get_error_message()));
        }

        // Get the URL of the newly uploaded attachment.
        $local_url = wp_get_attachment_url($attachment_id);

        // Check if a valid URL was returned.
        if (!$local_url) {
             // Attachment likely created, but URL failed. Try to clean up attachment? Difficult.
             // Best practice is often to leave the attachment and log the URL retrieval error.
             unlink($file_array['tmp_name']); // Still clean up temp file
            throw new Exception(sprintf('Failed to get attachment URL after upload (Attachment ID: %d)', $attachment_id));
        }

        // Return the local URL on success.
        return $local_url;
    }

    /**
     * Log image upload errors using WordPress's error logging mechanism.
     *
     * Only logs if WP_DEBUG is enabled.
     *
     * @param string $failed_url The external URL that failed to upload.
     * @param string $error_message The specific error message.
     */
    public function log_upload_error($failed_url, $error_message) {
        // Check if WordPress debugging is enabled.
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log(sprintf(
                '[WPALLSTARS Auto Upload] Error: Failed to process image "%s". Reason: %s',
                esc_url_raw($failed_url), // Use raw URL for logging
                sanitize_text_field($error_message)
            ));
        }
    }
}