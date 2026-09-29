<?php
/**
 * WP Allstars auto upload images.
 *
 * When a post is saved, external <img> sources are downloaded into the Media
 * Library (attached to the post) and the content is rewritten to use the
 * local copy. Works for the block editor (REST), the classic editor and
 * programmatic wp_insert_post() calls made by users who can upload files.
 *
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Auto_Upload {

    /** Attachment meta holding the original remote URL (for de-duplication). */
    const SOURCE_META = '_wp_allstars_source_url';

    /**
     * Remote URL => local URL map for the current request.
     *
     * @var array<string,string>
     */
    private $uploaded = array();

    /**
     * Register hooks.
     */
    public function __construct() {
        add_filter('wp_insert_post_data', array($this, 'filter_post_data'), 10, 2);
    }

    /**
     * Rewrite external images in post content before it is stored.
     *
     * @param array $data    Slashed, sanitized post data.
     * @param array $postarr Slashed raw post data (includes ID).
     * @return array
     */
    public function filter_post_data($data, $postarr) {
        if (!$this->should_process($data)) {
            return $data;
        }

        $post_id = isset($postarr['ID']) ? (int) $postarr['ID'] : 0;
        $content = wp_unslash($data['post_content']);
        $updated = $this->process_content($content, $post_id, wp_unslash($data));

        if ($updated !== $content) {
            $data['post_content'] = wp_slash($updated);
        }

        return $data;
    }

    /**
     * Whether this save should be processed.
     *
     * @param array $data Post data.
     * @return bool
     */
    private function should_process($data) {
        if (!WP_Allstars_Settings::get('auto_upload_images')) {
            return false;
        }
        if (empty($data['post_content']) || false === stripos($data['post_content'], '<img')) {
            return false;
        }
        if (in_array($data['post_type'], array('revision', 'attachment', 'nav_menu_item', 'customize_changeset'), true)) {
            return false;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }
        if (!current_user_can('upload_files')) {
            return false;
        }

        /**
         * Filter whether auto upload runs for a post.
         *
         * @param bool  $process Whether to process.
         * @param array $data    Post data.
         */
        return (bool) apply_filters('wp_allstars_auto_upload_process_post', true, $data);
    }

    /**
     * Replace external image sources in HTML.
     *
     * @param string $content HTML (unslashed).
     * @param int    $post_id Post ID, 0 for new posts.
     * @param array  $post    Unslashed post data for pattern tokens.
     * @return string
     */
    public function process_content($content, $post_id, array $post) {
        if (!class_exists('WP_HTML_Tag_Processor')) {
            return $content;
        }

        /**
         * Filter the maximum number of images imported per save.
         * Remaining images are imported on the next save.
         *
         * @param int $limit Default 10.
         */
        $limit     = (int) apply_filters('wp_allstars_auto_upload_limit', 10);
        $processed = 0;
        $replaced  = array();
        $tags      = new WP_HTML_Tag_Processor($content);

        while ($tags->next_tag('img')) {
            $src = $tags->get_attribute('src');
            if (!is_string($src) || !$this->is_importable($src)) {
                continue;
            }

            if (!isset($this->uploaded[$src])) {
                if ($processed >= $limit) {
                    continue;
                }
                $processed++;
                $local = $this->import($src, $post_id, $post);
                if (is_wp_error($local)) {
                    $this->log($src, $local->get_error_message());
                    continue;
                }
                $this->uploaded[$src] = $local;
            }

            $local = $this->uploaded[$src];
            $tags->set_attribute('src', $local);
            // Remote srcset/sizes would keep loading the external files.
            $tags->remove_attribute('srcset');
            $tags->remove_attribute('sizes');

            $alt = $tags->get_attribute('alt');
            if (!is_string($alt) || '' === trim($alt)) {
                $new_alt = $this->alt_text($src, $post_id, $post);
                if ('' !== $new_alt) {
                    $tags->set_attribute('alt', $new_alt);
                }
            }

            $replaced[$src] = $local;
        }

        $content = $tags->get_updated_html();

        // Links to the original file (e.g. "link to media file") follow the image.
        foreach ($replaced as $remote => $local) {
            $content = str_replace(
                array($remote, esc_attr($remote)),
                array($local, esc_attr($local)),
                $content
            );
        }

        return $content;
    }

    /**
     * Whether a URL is an external http(s) image that should be imported.
     *
     * @param string $url Image URL.
     * @return bool
     */
    private function is_importable($url) {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url) || !wp_http_validate_url($url)) {
            return false;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ('' === $host) {
            return false;
        }

        $local_hosts = array_filter(array(
            wp_parse_url(home_url(), PHP_URL_HOST),
            wp_parse_url(site_url(), PHP_URL_HOST),
            wp_parse_url(content_url(), PHP_URL_HOST),
        ));
        $excluded = array_merge(
            array_map('strtolower', $local_hosts),
            WP_Allstars_Settings::parse_domains(WP_Allstars_Settings::get('auto_upload_exclude_domains'))
        );

        foreach ($excluded as $domain) {
            $domain = preg_replace('/^www\./', '', $domain);
            $bare   = preg_replace('/^www\./', '', $host);
            if ($bare === $domain || substr($bare, -strlen('.' . $domain)) === '.' . $domain) {
                return false;
            }
        }

        return true;
    }

    /**
     * Download, validate, resize and sideload one image.
     *
     * @param string $url     Remote URL.
     * @param int    $post_id Parent post ID.
     * @param array  $post    Post data for tokens.
     * @return string|WP_Error Local URL.
     */
    private function import($url, $post_id, array $post) {
        $existing = $this->find_existing($url);
        if ($existing) {
            return $existing;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // download_url() uses wp_safe_remote_get(), which rejects internal hosts.
        $tmp = download_url($url, 15);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $mime    = wp_get_image_mime($tmp);
        $allowed = get_allowed_mime_types();
        if (!$mime || !in_array($mime, $allowed, true)) {
            wp_delete_file($tmp);
            return new WP_Error('wp_allstars_not_image', __('The file is not an allowed image type.', 'wp-allstars'));
        }

        $extension = wp_get_default_extension_for_mime_type($mime);
        $tmp       = $this->maybe_resize($tmp, $mime, $extension);

        $file = array(
            'name'     => $this->file_name($url, $post_id, $post, $extension),
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file, $post_id);
        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp);
            return $attachment_id;
        }

        update_post_meta($attachment_id, self::SOURCE_META, esc_url_raw($url));

        $alt = $this->alt_text($url, $post_id, $post);
        if ('' !== $alt) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        }

        /**
         * Fires after an external image is imported.
         *
         * @param int    $attachment_id New attachment ID.
         * @param string $url           Original URL.
         * @param int    $post_id       Parent post ID.
         */
        do_action('wp_allstars_image_imported', $attachment_id, $url, $post_id);

        return wp_get_attachment_url($attachment_id);
    }

    /**
     * Reuse an attachment previously imported from the same URL.
     *
     * @param string $url Remote URL.
     * @return string|false Local URL.
     */
    private function find_existing($url) {
        $ids = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value'     => esc_url_raw($url), // phpcs:ignore WordPress.DB.SlowDBQuery
        ));

        return $ids ? wp_get_attachment_url($ids[0]) : false;
    }

    /**
     * Scale the downloaded file to the configured maximum size.
     *
     * @param string $tmp       Temporary file path.
     * @param string $mime      Image MIME type.
     * @param string $extension File extension.
     * @return string Path to use for the sideload.
     */
    private function maybe_resize($tmp, $mime, $extension) {
        $max_w = (int) WP_Allstars_Settings::get('auto_upload_max_width');
        $max_h = (int) WP_Allstars_Settings::get('auto_upload_max_height');
        if ($max_w <= 0 && $max_h <= 0) {
            return $tmp;
        }

        $editor = wp_get_image_editor($tmp);
        if (is_wp_error($editor)) {
            return $tmp;
        }

        $size = $editor->get_size();
        if (($max_w <= 0 || $size['width'] <= $max_w) && ($max_h <= 0 || $size['height'] <= $max_h)) {
            return $tmp;
        }

        $resized = $editor->resize($max_w > 0 ? $max_w : null, $max_h > 0 ? $max_h : null, false);
        if (is_wp_error($resized)) {
            return $tmp;
        }

        $saved = $editor->save($tmp . '-scaled.' . $extension, $mime);
        if (is_wp_error($saved) || empty($saved['path'])) {
            return $tmp;
        }

        wp_delete_file($tmp);
        return $saved['path'];
    }

    /**
     * Build the uploaded file name from the configured pattern.
     *
     * @param string $url       Remote URL.
     * @param int    $post_id   Post ID.
     * @param array  $post      Post data.
     * @param string $extension File extension.
     * @return string
     */
    private function file_name($url, $post_id, array $post, $extension) {
        $pattern = (string) WP_Allstars_Settings::get('auto_upload_filename_pattern');
        $name    = $this->replace_tokens('' !== $pattern ? $pattern : '%filename%', $url, $post_id, $post);
        $name    = sanitize_file_name($name);

        if ('' === $name || '-' === $name) {
            $name = 'image-' . time();
        }

        return $name . '.' . $extension;
    }

    /**
     * Alt text from the configured pattern.
     *
     * @param string $url     Remote URL.
     * @param int    $post_id Post ID.
     * @param array  $post    Post data.
     * @return string
     */
    private function alt_text($url, $post_id, array $post) {
        $pattern = (string) WP_Allstars_Settings::get('auto_upload_alt_pattern');
        if ('' === trim($pattern)) {
            return '';
        }
        return trim(sanitize_text_field($this->replace_tokens($pattern, $url, $post_id, $post)));
    }

    /**
     * Replace pattern tokens.
     *
     * @param string $pattern Pattern.
     * @param string $url     Remote URL.
     * @param int    $post_id Post ID.
     * @param array  $post    Post data.
     * @return string
     */
    private function replace_tokens($pattern, $url, $post_id, array $post) {
        $path     = (string) wp_parse_url($url, PHP_URL_PATH);
        $filename = pathinfo(rawurldecode(wp_basename($path)), PATHINFO_FILENAME);
        $now      = time(); // wp_date() applies the site timezone.

        $tokens = array(
            '%filename%'   => $filename,
            '%post_id%'    => $post_id ? (string) $post_id : '',
            '%postname%'   => isset($post['post_name']) ? (string) $post['post_name'] : '',
            '%post_title%' => isset($post['post_title']) ? wp_strip_all_tags((string) $post['post_title']) : '',
            '%timestamp%'  => (string) time(),
            '%date%'       => wp_date('Y-m-d', $now),
            '%year%'       => wp_date('Y', $now),
            '%month%'      => wp_date('m', $now),
            '%day%'        => wp_date('d', $now),
        );

        return strtr($pattern, $tokens);
    }

    /**
     * Log a failed import when debugging.
     *
     * @param string $url   Remote URL.
     * @param string $error Message.
     */
    private function log($url, $error) {
        /**
         * Fires when an image cannot be imported.
         *
         * @param string $url   Remote URL.
         * @param string $error Error message.
         */
        do_action('wp_allstars_image_upload_error', $url, $error);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf('[WP Allstars] Auto upload failed for %s: %s', esc_url_raw($url), $error));
        }
    }
}
