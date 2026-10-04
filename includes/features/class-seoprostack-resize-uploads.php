<?php
/**
 * Resize large uploads.
 *
 * Pictures wider or taller than a set size are scaled down when uploaded, and
 * the smaller file replaces the original. WordPress's own "big image" limit
 * (2560 by default) keeps the huge original next to a scaled copy; this uses
 * the same limit instead, so there is no huge original to keep:
 * - uploads through the Media Library, the editors, REST and sideloads are
 *   resized in wp_handle_upload, before WordPress makes the smaller sizes;
 * - big_image_size_threshold is set to the limit, so anything not resized
 *   here (such as WordPress 7.1 uploads that the browser processes) is
 *   scaled to the same size by WordPress or the browser;
 * - BMP pictures, and optionally PNG photos, are saved as JPEG;
 * - camera details, captions and credits are kept even when the resized file
 *   loses them, and pictures are turned upright first.
 *
 * Existing pictures are resized from Media → Library (Resize row action and
 * bulk action) or with `wp seoprostack resize-images`.
 *
 * Replaces "Imsanity" and imports its settings.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Resize_Uploads extends SEOProStack_Feature {

    const KEY = 'resize_uploads';

    const ACTION = 'seoprostack_resize_image';

    const BULK = 'seoprostack_resize';

    /** Seconds a bulk resize may run before leaving the rest for later. */
    const BUDGET = 20;

    /** Imsanity's default limit, which is not imported. */
    const IMSANITY_DEFAULT_MAX = 1920;

    /**
     * Whether this request's uploads are processed by the browser (WordPress
     * 7.1 client-side media processing) or are sub-sizes it sends.
     *
     * @var bool
     */
    private static $client_processed = false;

    /**
     * Whether this request records the sizes the browser made (the last step
     * of WordPress 7.1 client-side media processing).
     *
     * @var bool
     */
    private static $finalizing = false;

    /**
     * Image metadata read before resizing, by new file path.
     *
     * @var array<string,array>
     */
    private static $kept_meta = array();

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
                'label'       => __('Resize large uploads', 'seoprostack'),
                'description' => __('Scale down pictures larger than a set size as they are uploaded, instead of keeping the huge original next to a smaller copy. Existing pictures can be resized from Media → Library.', 'seoprostack'),
                'replaces'    => array('imsanity' => 'Imsanity'),
            ),
            'resize_uploads_max' => array(
                'type'        => 'int',
                'default'     => 2560,
                'min'         => 320,
                'max'         => 20000,
                'unit'        => 'px',
                'parent'      => self::KEY,
                'label'       => __('Largest width or height', 'seoprostack'),
                'description' => __('WordPress uses 2560. Pictures with “noresize” in the file name are left alone.', 'seoprostack'),
            ),
            'resize_uploads_quality' => array(
                'type'        => 'int',
                'default'     => 82,
                'min'         => 10,
                'max'         => 100,
                'parent'      => self::KEY,
                'label'       => __('JPEG and WebP quality', 'seoprostack'),
                'description' => __('82 is the WordPress default. A resized picture is only kept when its file is smaller.', 'seoprostack'),
            ),
            'resize_uploads_bmp' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Save BMP pictures as JPEG', 'seoprostack'),
                'description' => __('BMP files are large and many browsers show them badly.', 'seoprostack'),
            ),
            'resize_uploads_png' => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Save PNG photos as JPEG', 'seoprostack'),
                'description' => __('Only when the JPEG is smaller. PNGs with transparency are left alone. Screenshots and graphics are usually best kept as PNG.', 'seoprostack'),
            ),
            'resize_uploads_originals' => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Delete kept originals', 'seoprostack'),
                'description' => __('WordPress keeps the original of pictures it scales, rotates or converts. Delete it after uploading or resizing to save space. It cannot be restored afterwards.', 'seoprostack'),
            ),
        );
    }

    /**
     * Import Imsanity's settings, and switch on while it is active. Its three
     * size pairs (posts, Media Library, other) become one limit: the largest.
     * Imsanity's own default (1920) is not imported, so WordPress's 2560 is
     * used unless a different limit was chosen.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (isset(self::active_plugins()['imsanity'])) {
            $options = self::import_setting($options, self::KEY, true);
        }

        $max = 0;
        foreach (array('imsanity_max_width', 'imsanity_max_height', 'imsanity_max_width_library', 'imsanity_max_height_library', 'imsanity_max_width_other', 'imsanity_max_height_other') as $name) {
            $max = max($max, (int) get_option($name, 0));
        }
        if ($max > 0 && self::IMSANITY_DEFAULT_MAX !== $max) {
            $options = self::import_setting($options, 'resize_uploads_max', $max);
        }

        $quality = get_option('imsanity_quality', null);
        if (is_numeric($quality) && (int) $quality > 0) {
            $options = self::import_setting($options, 'resize_uploads_quality', (int) $quality);
        }
        foreach (array('imsanity_bmp_to_jpg' => 'resize_uploads_bmp', 'imsanity_png_to_jpg' => 'resize_uploads_png', 'imsanity_delete_originals' => 'resize_uploads_originals') as $theirs => $ours) {
            $value = get_option($theirs, null);
            if (null !== $value && false !== $value) {
                $options = self::import_setting($options, $ours, (bool) $value);
            }
        }

        // CompressX also resizes uploads (on unless switched off, 2560 by
        // default); WebP and AVIF images replaces the rest of it.
        if (isset(self::active_plugins()['compressx'])) {
            $general = SEOProStack_Nextgen_Images::compressx_option('compressx_general_settings');
            $resize  = is_array($general) && isset($general['resize']) && is_array($general['resize']) ? $general['resize'] : array();
            if (!isset($resize['enable']) || $resize['enable']) {
                $options = self::import_setting($options, self::KEY, true);
                $width   = isset($resize['width']) ? (int) $resize['width'] : 2560;
                $height  = isset($resize['height']) ? (int) $resize['height'] : 2560;
                if (max($width, $height) > 0) {
                    $options = self::import_setting($options, 'resize_uploads_max', max($width, $height));
                }
            }
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'note_client_processing'), 10, 3);
        add_filter('wp_handle_upload', array(__CLASS__, 'handle_upload'), 10, 2);
        add_filter('big_image_size_threshold', array(__CLASS__, 'threshold'), 20, 3);
        add_filter('wp_read_image_metadata', array(__CLASS__, 'keep_image_meta'), 10, 2);
        add_filter('wp_generate_attachment_metadata', array(__CLASS__, 'after_generate'), 90, 3);

        if (is_admin()) {
            add_filter('media_row_actions', array(__CLASS__, 'row_action'), 10, 2);
            add_filter('bulk_actions-upload', array(__CLASS__, 'bulk_action'));
            add_filter('handle_bulk_actions-upload', array(__CLASS__, 'handle_bulk'), 10, 3);
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle_one'));
            add_action('admin_notices', array(__CLASS__, 'notice'));
            add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
        }

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('seoprostack resize-images', array(__CLASS__, 'cli'));
        }
    }

    /**
     * Largest width or height.
     *
     * @return int
     */
    public static function max() {
        return max(0, (int) SEOProStack_Settings::get('resize_uploads_max'));
    }

    /* --------------------------------------------------------------------- */
    /* Uploads                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * WordPress 7.1 lets the browser process uploads: it sends the original
     * with generate_sub_sizes=false, then its own scaled copy and sizes. Those
     * are left alone; the browser scales to the limit (see threshold()).
     *
     * @param mixed           $response Response so far.
     * @param array           $handler  Route handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed
     */
    public static function note_client_processing($response, $handler, $request) {
        if ($request instanceof WP_REST_Request && 0 === strpos($request->get_route(), '/wp/v2/media')) {
            $route = $request->get_route();
            if (preg_match('#^/wp/v2/media/\d+/sideload#', $route)
                || ('/wp/v2/media' === $route && 'POST' === $request->get_method() && false === $request->get_param('generate_sub_sizes'))
            ) {
                self::$client_processed = true;
            }
            if (preg_match('#^/wp/v2/media/\d+/finalize#', $route)) {
                self::$finalizing = true;
            }
        }
        return $response;
    }

    /**
     * Resize or convert an uploaded picture before WordPress makes its sizes.
     *
     * @param array  $upload  file, url, type (or error).
     * @param string $context upload or sideload.
     * @return array
     */
    public static function handle_upload($upload, $context = 'upload') {
        if (self::$client_processed || !is_array($upload) || !empty($upload['error']) || empty($upload['file']) || empty($upload['type'])) {
            return $upload;
        }
        $file = $upload['file'];
        if (!is_file($file) || false !== stripos(wp_basename($file), 'noresize')) {
            return $upload;
        }

        $type = $upload['type'];
        $jpeg = false;
        if (in_array($type, array('image/bmp', 'image/x-ms-bmp'), true) && SEOProStack_Settings::get('resize_uploads_bmp')) {
            $jpeg = 'always';
        } elseif ('image/png' === $type && SEOProStack_Settings::get('resize_uploads_png') && !self::has_transparency($file)) {
            $jpeg = 'smaller';
        }

        if ($jpeg) {
            $result = self::process($file, 'image/jpeg', 'smaller' === $jpeg);
        } elseif (self::resizable($file, $type)) {
            $result = self::process($file, $type, true);
        } else {
            return $upload;
        }
        if (is_wp_error($result) || !$result) {
            return $upload;
        }

        if ($result['path'] !== $file) {
            $upload['url']  = trailingslashit(dirname($upload['url'])) . wp_basename($result['path']);
            $upload['file'] = $result['path'];
            $upload['type'] = $result['mime'];
        }
        return $upload;
    }

    /**
     * Whether a file is a picture this can resize: not animated, and a type
     * the image editor can write.
     *
     * @param string $file File path.
     * @param string $mime MIME type.
     * @return bool
     */
    private static function resizable($file, $mime) {
        if (!in_array($mime, array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'), true)) {
            return false;
        }
        if ('image/gif' === $mime && self::is_animated_gif($file)) {
            return false;
        }
        return wp_image_editor_supports(array('mime_type' => $mime));
    }

    /**
     * Scale a file down to the limit and/or save it in another format.
     * The original is replaced when the result is kept.
     *
     * @param string $file         File path.
     * @param string $mime         MIME type to save as.
     * @param bool   $only_smaller Keep the result only when its file is smaller.
     * @return array|false|WP_Error path, mime, width, height; false when unchanged.
     */
    private static function process($file, $mime, $only_smaller) {
        $max    = self::max();
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return $editor;
        }
        $size    = $editor->get_size();
        $too_big = $max > 0 && ($size['width'] > $max || $size['height'] > $max);
        $current = wp_check_filetype($file);
        $convert = $current['type'] !== $mime;
        if (!$too_big && !$convert) {
            return false;
        }

        $kept = self::read_image_meta($file);
        // The saved file has no orientation tag, so turn the picture upright.
        $editor->maybe_exif_rotate();
        if ($too_big) {
            $resized = $editor->resize($max, $max, false);
            if (is_wp_error($resized)) {
                return $resized;
            }
        }
        $editor->set_quality((int) SEOProStack_Settings::get('resize_uploads_quality'));

        $dir    = dirname($file);
        $name   = pathinfo($file, PATHINFO_FILENAME);
        $ext    = 'image/jpeg' === $mime ? 'jpg' : pathinfo($file, PATHINFO_EXTENSION);
        $target = $dir . '/' . wp_unique_filename($dir, $name . '-sps-resizing.' . $ext);
        $saved  = $editor->save($target, $mime);
        if (is_wp_error($saved)) {
            return $saved;
        }
        clearstatcache();
        if (empty($saved['path']) || !is_file($saved['path'])) {
            return false;
        }
        // Another plugin may change the output format; keep the original then.
        if ($saved['mime-type'] !== $mime || ($only_smaller && filesize($saved['path']) >= filesize($file))) {
            wp_delete_file($saved['path']);
            return false;
        }

        // Move the new file into place before removing the old one, so a
        // failure never loses the picture.
        $path = $convert ? $dir . '/' . wp_unique_filename($dir, $name . '.' . $ext) : $file;
        if (!@rename($saved['path'], $path)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- same directory.
            wp_delete_file($saved['path']);
            return new WP_Error('resize_rename', __('The resized picture could not be saved.', 'seoprostack'));
        }
        if ($convert) {
            wp_delete_file($file);
        }
        clearstatcache();
        if ($kept) {
            // The picture is upright now.
            unset($kept['orientation']);
            self::$kept_meta[$path] = $kept;
        }
        return array(
            'path'   => $path,
            'mime'   => $mime,
            'width'  => (int) $saved['width'],
            'height' => (int) $saved['height'],
        );
    }

    /**
     * Camera details, caption, credit and copyright, before resizing.
     *
     * @param string $file File path.
     * @return array
     */
    private static function read_image_meta($file) {
        if (!function_exists('wp_read_image_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $meta = wp_read_image_metadata($file);
        return is_array($meta) ? $meta : array();
    }

    /**
     * Give a resized picture back the details its file lost.
     *
     * @param array  $meta Metadata read from the file.
     * @param string $file File path.
     * @return array
     */
    public static function keep_image_meta($meta, $file) {
        if (!isset(self::$kept_meta[$file])) {
            return $meta;
        }
        $meta = is_array($meta) ? $meta : array();
        foreach (self::$kept_meta[$file] as $key => $value) {
            if (empty($meta[$key]) && !empty($value)) {
                $meta[$key] = $value;
            }
        }
        return $meta;
    }

    /**
     * Use the limit as WordPress's own "big image" limit (also sent to the
     * browser when it processes uploads). "noresize" files keep full size.
     *
     * @param int|false $threshold Limit.
     * @param array     $imagesize Width and height.
     * @param string    $file      File path.
     * @return int|false
     */
    public static function threshold($threshold, $imagesize = array(), $file = '') {
        if (false === $threshold) {
            return $threshold;
        }
        if ($file && false !== stripos(wp_basename($file), 'noresize')) {
            return false;
        }
        $max = self::max();
        return $max > 0 ? $max : $threshold;
    }

    /**
     * After WordPress (or the browser) has made every size of a new upload,
     * delete the original it kept, if chosen.
     *
     * @param array  $metadata      Metadata.
     * @param int    $attachment_id Attachment ID.
     * @param string $context       create or update.
     * @return array
     */
    public static function after_generate($metadata, $attachment_id, $context = 'create') {
        if (('create' === $context || self::$finalizing) && is_array($metadata)) {
            $metadata = self::delete_original($attachment_id, $metadata);
        }
        return $metadata;
    }

    /**
     * Delete the original WordPress kept for a scaled, rotated or converted
     * picture, if chosen.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $metadata      Metadata.
     * @return array Metadata without original_image when deleted.
     */
    private static function delete_original($attachment_id, array $metadata) {
        if (empty($metadata['original_image']) || !SEOProStack_Settings::get('resize_uploads_originals')) {
            return $metadata;
        }
        $file = get_attached_file($attachment_id, true);
        if (!$file) {
            return $metadata;
        }
        $original = trailingslashit(dirname($file)) . wp_basename($metadata['original_image']);
        if ($original !== $file && is_file($original)) {
            wp_delete_file($original);
        }
        if (!is_file($original)) {
            unset($metadata['original_image']);
        }
        return $metadata;
    }

    /* --------------------------------------------------------------------- */
    /* Existing pictures                                                      */
    /* --------------------------------------------------------------------- */

    /**
     * Whether an attachment is larger than the limit.
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public static function too_big($attachment_id) {
        $max  = self::max();
        $meta = wp_get_attachment_metadata($attachment_id);
        return $max > 0 && is_array($meta) && 'image/svg+xml' !== get_post_mime_type($attachment_id)
            && ((!empty($meta['width']) && (int) $meta['width'] > $max) || (!empty($meta['height']) && (int) $meta['height'] > $max));
    }

    /**
     * Resize an existing picture to the limit. The file keeps its name and
     * address; its smaller sizes are unchanged.
     *
     * @param int $attachment_id Attachment ID.
     * @return string|WP_Error resized, fits or larger (the resized file was not smaller).
     */
    public static function resize_attachment($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $file          = get_attached_file($attachment_id);
        $mime          = (string) get_post_mime_type($attachment_id);
        $meta          = wp_get_attachment_metadata($attachment_id);
        if (!$file || !is_file($file) || !is_array($meta) || !wp_attachment_is_image($attachment_id) || 'image/svg+xml' === $mime) {
            return new WP_Error('resize_not_image', __('Not a picture that can be resized.', 'seoprostack'));
        }
        if (false !== stripos(wp_basename($file), 'noresize')) {
            return 'fits';
        }
        if (!self::resizable($file, $mime)) {
            return new WP_Error('resize_type', __('This type of picture cannot be resized on this server.', 'seoprostack'));
        }

        $status = 'fits';
        if (self::too_big($attachment_id)) {
            $result = self::process($file, $mime, true);
            if (is_wp_error($result)) {
                return $result;
            }
            if ($result) {
                $meta['width']    = $result['width'];
                $meta['height']   = $result['height'];
                $meta['filesize'] = (int) filesize($file);
                if (!empty($meta['image_meta']['orientation'])) {
                    $meta['image_meta']['orientation'] = 1;
                }
                unset(self::$kept_meta[$file]);
                $status = 'resized';
            } else {
                $status = 'larger';
            }
        }
        $before = $meta;
        $meta   = self::delete_original($attachment_id, $meta);
        if ('resized' === $status || $meta !== $before) {
            wp_update_attachment_metadata($attachment_id, $meta);
            delete_transient('dirsize_cache');
        }
        return $status;
    }

    /**
     * Resize link for pictures larger than the limit.
     *
     * @param array   $actions Row actions.
     * @param WP_Post $post    Attachment.
     * @return array
     */
    public static function row_action($actions, $post) {
        if (current_user_can('edit_post', $post->ID) && self::too_big($post->ID)) {
            $url = wp_nonce_url(add_query_arg(array('action' => self::ACTION, 'attachment' => $post->ID), admin_url('admin-post.php')), self::ACTION . '_' . $post->ID);
            $actions['seoprostack_resize'] = sprintf(
                '<a href="%1$s" aria-label="%2$s">%3$s</a>',
                esc_url($url),
                /* translators: 1: attachment title, 2: pixels */
                esc_attr(sprintf(__('Resize “%1$s” to %2$d pixels', 'seoprostack'), get_the_title($post), self::max())),
                /* translators: %d: pixels */
                esc_html(sprintf(__('Resize to %d px', 'seoprostack'), self::max()))
            );
        }
        return $actions;
    }

    /**
     * admin-post.php?action=seoprostack_resize_image
     */
    public static function handle_one() {
        $id = isset($_GET['attachment']) ? absint(wp_unslash($_GET['attachment'])) : 0;
        check_admin_referer(self::ACTION . '_' . $id);
        if (!$id || !current_user_can('edit_post', $id)) {
            wp_die(esc_html__('Sorry, you are not allowed to edit this item.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
        $counts = self::run(array($id));
        $back   = wp_get_referer();
        wp_safe_redirect(add_query_arg($counts, $back ? $back : admin_url('upload.php?mode=list')));
        exit;
    }

    /**
     * Bulk action on Media → Library (list view).
     *
     * @param array $actions Bulk actions.
     * @return array
     */
    public static function bulk_action($actions) {
        /* translators: %d: pixels */
        $actions[self::BULK] = sprintf(__('Resize to %d px', 'seoprostack'), self::max());
        return $actions;
    }

    /**
     * Resize the chosen pictures.
     *
     * @param string $redirect Where to go afterwards.
     * @param string $action   Bulk action.
     * @param int[]  $ids      Attachment IDs.
     * @return string
     */
    public static function handle_bulk($redirect, $action, $ids) {
        if (self::BULK !== $action) {
            return $redirect;
        }
        $ids = array_filter(array_map('absint', (array) $ids), function ($id) {
            return current_user_can('edit_post', $id);
        });
        return add_query_arg(self::run($ids), remove_query_arg(array('sps_resized', 'sps_resize_skipped', 'sps_resize_left', 'sps_resize_failed'), $redirect));
    }

    /**
     * Resize attachments within the time budget.
     *
     * @param int[] $ids Attachment IDs.
     * @return array<string,int> Counts for the notice.
     */
    private static function run(array $ids) {
        $start  = microtime(true);
        $counts = array('sps_resized' => 0, 'sps_resize_skipped' => 0, 'sps_resize_failed' => 0, 'sps_resize_left' => 0);
        foreach (array_values($ids) as $i => $id) {
            if (microtime(true) - $start > self::BUDGET) {
                $counts['sps_resize_left'] = count($ids) - $i;
                break;
            }
            $status = self::resize_attachment($id);
            if (is_wp_error($status)) {
                $counts['sps_resize_failed']++;
            } elseif ('resized' === $status) {
                $counts['sps_resized']++;
            } else {
                $counts['sps_resize_skipped']++;
            }
        }
        return $counts;
    }

    /**
     * Result notice on Media → Library.
     */
    public static function notice() {
        if (!isset($_GET['sps_resized'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
            return;
        }
        $get = function ($key) {
            return isset($_GET[$key]) ? absint(wp_unslash($_GET[$key])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        };
        /* translators: %d: number of pictures */
        $parts = array(sprintf(_n('%d picture resized.', '%d pictures resized.', $get('sps_resized'), 'seoprostack'), $get('sps_resized')));
        if ($get('sps_resize_skipped')) {
            /* translators: %d: number of pictures */
            $parts[] = sprintf(_n('%d was already small enough, or would not have been smaller.', '%d were already small enough, or would not have been smaller.', $get('sps_resize_skipped'), 'seoprostack'), $get('sps_resize_skipped'));
        }
        if ($get('sps_resize_failed')) {
            /* translators: %d: number of files */
            $parts[] = sprintf(_n('%d could not be resized.', '%d could not be resized.', $get('sps_resize_failed'), 'seoprostack'), $get('sps_resize_failed'));
        }
        if ($get('sps_resize_left')) {
            /* translators: %d: number of pictures */
            $parts[] = sprintf(_n('%d was left for lack of time: select it and resize again.', '%d were left for lack of time: select them and resize again.', $get('sps_resize_left'), 'seoprostack'), $get('sps_resize_left'));
        }
        $type = $get('sps_resize_failed') || $get('sps_resize_left') ? 'warning' : 'success';
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($type), esc_html(implode(' ', $parts)));
    }

    /**
     * Drop the notice arguments from the address after showing them.
     *
     * @param string[] $args Query arguments.
     * @return string[]
     */
    public static function removable_query_args($args) {
        return array_merge($args, array('sps_resized', 'sps_resize_skipped', 'sps_resize_failed', 'sps_resize_left'));
    }

    /**
     * Resize existing pictures larger than the limit.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List the pictures that would be resized.
     *
     * ## EXAMPLES
     *
     *     wp seoprostack resize-images --dry-run
     *     wp seoprostack resize-images
     *
     * @param array $args  Positional arguments.
     * @param array $assoc Options.
     */
    public static function cli($args, $assoc) {
        $dry    = !empty($assoc['dry-run']);
        $counts = array('resized' => 0, 'fits' => 0, 'larger' => 0, 'failed' => 0);
        $page   = 1;
        do {
            $ids = get_posts(array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'fields'         => 'ids',
                'posts_per_page' => 100,
                'paged'          => $page++,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ));
            foreach ($ids as $id) {
                if (!self::too_big($id)) {
                    continue;
                }
                if ($dry) {
                    WP_CLI::log(sprintf('%d %s', $id, get_attached_file($id)));
                    $counts['resized']++;
                    continue;
                }
                $status = self::resize_attachment($id);
                if (is_wp_error($status)) {
                    $counts['failed']++;
                    WP_CLI::warning(sprintf('%d: %s', $id, $status->get_error_message()));
                } else {
                    $counts[$status]++;
                }
            }
            wp_cache_flush_runtime();
        } while ($ids);

        if ($dry) {
            WP_CLI::success(sprintf('%d pictures are larger than %d px.', $counts['resized'], self::max()));
            return;
        }
        WP_CLI::success(sprintf('%d resized, %d not smaller when resized, %d failed.', $counts['resized'], $counts['larger'], $counts['failed']));
    }

    /* --------------------------------------------------------------------- */
    /* File checks                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * Whether a PNG may have transparent pixels: an alpha channel or a
     * transparency chunk.
     *
     * @param string $file File path.
     * @return bool
     */
    private static function has_transparency($file) {
        $head = (string) file_get_contents($file, false, null, 0, 64 * 1024); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        if (strlen($head) < 26 || "\x89PNG" !== substr($head, 0, 4)) {
            return true;
        }
        $colour = ord($head[25]);
        return 4 === $colour || 6 === $colour || false !== strpos($head, 'tRNS');
    }

    /**
     * Whether a GIF has more than one frame.
     *
     * @param string $file File path.
     * @return bool
     */
    private static function is_animated_gif($file) {
        $data = (string) file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        return preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $data) > 1;
    }
}
