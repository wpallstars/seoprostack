<?php
/**
 * SEO Pro Stack auto upload images.
 *
 * When a post is saved, external <img> sources are downloaded into the Media
 * Library (attached to the post) and the content is rewritten to use the
 * local copy. Works for the block editor (REST), the classic editor and
 * programmatic wp_insert_post() calls made by users who can upload files.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Auto_Upload extends SEOProStack_Feature {

    const KEY = 'auto_upload_images';

    /** Attachment meta holding the original remote URL (for de-duplication). */
    const SOURCE_META = '_seoprostack_source_url';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'media',
                'label'       => __('Copy linked images to Media Library', 'seoprostack'),
                'description' => __('When a post is saved, copy images from any external image links into the Media Library, resize, and change the link to serve the local copy.', 'seoprostack'),
            ),
            'auto_upload_max_width' => array(
                'type'        => 'int',
                'default'     => 2560,
                'min'         => 0,
                'max'         => 10000,
                'unit'        => 'px',
                'parent'      => self::KEY,
                'label'       => __('Maximum width', 'seoprostack'),
                'description' => __('Larger images are scaled down before upload. 0 keeps the original size.', 'seoprostack'),
            ),
            'auto_upload_max_height' => array(
                'type'        => 'int',
                'default'     => 2560,
                'min'         => 0,
                'max'         => 10000,
                'unit'        => 'px',
                'parent'      => self::KEY,
                'label'       => __('Maximum height', 'seoprostack'),
                'description' => __('Larger images are scaled down before upload. 0 keeps the original size.', 'seoprostack'),
            ),
            'auto_upload_exclude_domains' => array(
                'type'        => 'domains',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Excluded domains', 'seoprostack'),
                'description' => __('One domain per line. Images from these domains (and their subdomains) stay external.', 'seoprostack'),
                'placeholder' => "cdn.example.com\nimages.example.org",
            ),
            'auto_upload_filename_pattern' => array(
                'type'        => 'text',
                'default'     => '%filename%',
                'parent'      => self::KEY,
                'label'       => __('File name pattern', 'seoprostack'),
                'description' => __('Name given to uploaded files.', 'seoprostack'),
                'tokens'      => array('%filename%', '%post_id%', '%postname%', '%post_title%', '%timestamp%', '%date%', '%year%', '%month%', '%day%'),
            ),
            'auto_upload_alt_pattern' => array(
                'type'        => 'text',
                'default'     => '%post_title%',
                'parent'      => self::KEY,
                'label'       => __('Alt text pattern', 'seoprostack'),
                'description' => __('Used when an image has no alt text. Leave empty to keep images without alt text unchanged.', 'seoprostack'),
                'tokens'      => array('%filename%', '%post_id%', '%postname%', '%post_title%'),
            ),
        );
    }

    /**
     * Register hooks when enabled.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        $instance = new self();
        add_filter('wp_insert_post_data', array($instance, 'filter_post_data'), 10, 2);
        add_action('wp_insert_post', array($instance, 'attach_orphans'), 10, 2);
    }

    /**
     * Remote URL => local URL map for the current request.
     *
     * @var array<string,string>
     */
    private $uploaded = array();

    /**
     * Attachments imported for a post that had no ID yet (new post).
     *
     * @var int[]
     */
    private $orphans = array();

    /**
     * Attach images imported before a new post had an ID.
     *
     * @param int     $post_id Saved post ID.
     * @param WP_Post $post    Saved post.
     */
    public function attach_orphans($post_id, $post) {
        if (!$this->orphans || 'attachment' === $post->post_type || wp_is_post_revision($post_id)) {
            return;
        }
        $orphans       = $this->orphans;
        $this->orphans = array();
        foreach ($orphans as $attachment_id) {
            wp_update_post(array('ID' => $attachment_id, 'post_parent' => $post_id));
        }
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
        if (!self::enabled()) {
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
        return (bool) apply_filters('seoprostack_auto_upload_process_post', true, $data);
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
        $limit     = (int) apply_filters('seoprostack_auto_upload_limit', 10);
        $processed = 0;
        $replaced  = array();
        $tags      = new WP_HTML_Tag_Processor($content);

        while ($tags->next_tag(array('tag_name' => 'img'))) {
            $src = $tags->get_attribute('src');
            if (!is_string($src)) {
                continue;
            }
            $src = trim($src);
            // Protocol-relative sources ("//cdn.example.com/a.jpg") are fetched over https.
            $fetch_url = 0 === strpos($src, '//') ? 'https:' . $src : $src;
            if (!$this->is_importable($fetch_url)) {
                continue;
            }

            if (!isset($this->uploaded[$src])) {
                if ($processed >= $limit) {
                    continue;
                }
                $processed++;
                $local = $this->import($fetch_url, $post_id, $post);
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
                $new_alt = $this->alt_text($fetch_url, $post_id, $post);
                if ('' !== $new_alt) {
                    $tags->set_attribute('alt', $new_alt);
                }
            }

            $replaced[$src] = $local;
        }

        $content = $tags->get_updated_html();

        // Links to the original file (e.g. "link to media file") follow the image.
        foreach ($replaced as $remote => $local) {
            $remote = (string) $remote;
            $forms  = array($remote);
            if (0 === strpos($remote, '//')) {
                // Scheme-qualified forms first, so "//host/x" never matches inside "https://host/x".
                $forms = array('https:' . $remote, 'http:' . $remote, $remote);
            }
            foreach ($forms as $form) {
                $content = str_replace(
                    array($form, esc_attr($form)),
                    array($local, esc_attr($local)),
                    $content
                );
            }
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
            SEOProStack_Settings::parse_domains(implode("\n", $local_hosts)),
            SEOProStack_Settings::parse_domains(SEOProStack_Settings::get('auto_upload_exclude_domains'))
        );

        return !SEOProStack_Settings::host_matches($host, $excluded);
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
            return new WP_Error('seoprostack_not_image', __('The file is not an allowed image type.', 'seoprostack'));
        }

        $extension = (string) wp_get_default_extension_for_mime_type($mime);
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
        if (!$post_id) {
            $this->orphans[] = (int) $attachment_id;
        }

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
        do_action('seoprostack_image_imported', $attachment_id, $url, $post_id);

        $local = wp_get_attachment_url($attachment_id);
        return $local ? $local : new WP_Error('seoprostack_no_url', __('The imported image has no address.', 'seoprostack'));
    }

    /**
     * Reuse an attachment previously imported from the same URL.
     *
     * @param string $url Remote URL.
     * @return string|false Local URL.
     */
    private function find_existing($url) {
        // The legacy key was written by releases named "WP Allstars".
        foreach (array(self::SOURCE_META, '_wp_allstars_source_url') as $meta_key) {
            $ids = get_posts(array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
                'meta_value'     => esc_url_raw($url), // phpcs:ignore WordPress.DB.SlowDBQuery
            ));
            if ($ids) {
                return wp_get_attachment_url($ids[0]);
            }
        }
        return false;
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
        $max_w = (int) SEOProStack_Settings::get('auto_upload_max_width');
        $max_h = (int) SEOProStack_Settings::get('auto_upload_max_height');
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
        $pattern = (string) SEOProStack_Settings::get('auto_upload_filename_pattern');
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
        $pattern = (string) SEOProStack_Settings::get('auto_upload_alt_pattern');
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
        do_action('seoprostack_image_upload_error', $url, $error);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf('[SEO Pro Stack] Auto upload failed for %s: %s', esc_url_raw($url), $error));
        }
    }
}
