<?php
/**
 * Watermark pictures.
 *
 * Lays the site icon, the site logo or a chosen picture over pictures, faintly
 * (30% by default) in a corner, sized as a share of each picture:
 * - new uploads are marked after WordPress has made every size (and after
 *   Resize large uploads), including WordPress 7.1 uploads that the browser
 *   processes, which are marked when the browser has sent its sizes;
 * - the file, the original WordPress keeps for big pictures and every size
 *   large enough are marked; small sizes such as thumbnails are left alone;
 * - an unmarked copy of the original is kept in a folder with a random name
 *   in uploads, so the watermark can be removed, or added again with new
 *   settings, from Media → Library or with `wp seoprostack watermark-images`.
 *
 * Replaces "Easy Watermark" and imports its image watermark.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Watermark_Images extends SEOProStack_Feature {

    const KEY = 'watermark_images';

    /** Post meta: files marked (name => bytes), kept original and its name. */
    const META = '_seoprostack_watermark';

    /** Option: name of the folder in uploads that holds the originals. */
    const DIR_OPTION = 'seoprostack_watermark_dir';

    const ACTION = 'seoprostack_watermark_image';

    const BULK_ADD = 'seoprostack_watermark_add';

    const BULK_REMOVE = 'seoprostack_watermark_remove';

    /** Seconds a bulk action may run before leaving the rest for later. */
    const BUDGET = 20;

    /** Picture types that can be marked. */
    const TYPES = array('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif');

    /** Query arguments of the result notice. */
    const COUNTS = array('sps_wm_added', 'sps_wm_removed', 'sps_wm_skipped', 'sps_wm_unmarked', 'sps_wm_failed', 'sps_wm_left');

    /**
     * Set while this class regenerates sizes, so they are not marked.
     *
     * @var bool
     */
    private static $lock = false;

    /**
     * Whether this request's upload is processed by the browser (WordPress
     * 7.1): it is marked when the browser has sent its sizes.
     *
     * @var bool
     */
    private static $client_processed = false;

    /**
     * Whether this request records the sizes the browser made.
     *
     * @var bool
     */
    private static $finalizing = false;

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
                'label'       => __('Watermark pictures', 'seoprostack'),
                'description' => __('Add your site icon, logo or another picture faintly to a corner of pictures as they are uploaded. An unmarked copy is kept, so a watermark can be removed. Existing pictures are marked from Media → Library.', 'seoprostack'),
                'replaces'    => array('easy-watermark' => 'Easy Watermark'),
            ),
            'watermark_images_mark' => array(
                'type'        => 'select',
                'default'     => 'icon',
                'parent'      => self::KEY,
                'label'       => __('Watermark', 'seoprostack'),
                'description' => __('A picture with a transparent background works best.', 'seoprostack'),
                'options'     => array(
                    'icon'  => __('Site icon', 'seoprostack'),
                    'logo'  => __('Site logo', 'seoprostack'),
                    'image' => __('A picture you choose', 'seoprostack'),
                ),
            ),
            'watermark_images_image' => array(
                'type'        => 'media',
                'default'     => 0,
                'parent'      => self::KEY,
                'label'       => __('Picture', 'seoprostack'),
                'description' => __('Used when Watermark is “A picture you choose”.', 'seoprostack'),
            ),
            'watermark_images_position' => array(
                'type'    => 'select',
                'default' => 'bottom-right',
                'parent'  => self::KEY,
                'label'   => __('Position', 'seoprostack'),
                'options' => array(
                    'top-left'     => __('Top left', 'seoprostack'),
                    'top'          => __('Top middle', 'seoprostack'),
                    'top-right'    => __('Top right', 'seoprostack'),
                    'left'         => __('Middle left', 'seoprostack'),
                    'center'       => __('Middle', 'seoprostack'),
                    'right'        => __('Middle right', 'seoprostack'),
                    'bottom-left'  => __('Bottom left', 'seoprostack'),
                    'bottom'       => __('Bottom middle', 'seoprostack'),
                    'bottom-right' => __('Bottom right', 'seoprostack'),
                ),
            ),
            'watermark_images_size' => array(
                'type'        => 'int',
                'default'     => 20,
                'min'         => 2,
                'max'         => 100,
                'unit'        => '%',
                'parent'      => self::KEY,
                'label'       => __('Size', 'seoprostack'),
                'description' => __('Largest width and height of the watermark, as a share of the picture’s width and height.', 'seoprostack'),
            ),
            'watermark_images_margin' => array(
                'type'        => 'int',
                'default'     => 3,
                'min'         => 0,
                'max'         => 25,
                'unit'        => '%',
                'parent'      => self::KEY,
                'label'       => __('Distance from the edge', 'seoprostack'),
                'description' => __('As a share of the picture’s shorter side.', 'seoprostack'),
            ),
            'watermark_images_opacity' => array(
                'type'        => 'int',
                'default'     => 30,
                'min'         => 5,
                'max'         => 100,
                'unit'        => '%',
                'parent'      => self::KEY,
                'label'       => __('Opacity', 'seoprostack'),
                'description' => __('100 is solid.', 'seoprostack'),
            ),
            'watermark_images_min' => array(
                'type'        => 'int',
                'default'     => 400,
                'min'         => 0,
                'max'         => 10000,
                'unit'        => 'px',
                'parent'      => self::KEY,
                'label'       => __('Leave pictures smaller than', 'seoprostack'),
                'description' => __('Measured on the shorter side, for each size WordPress makes, so thumbnails stay clean. Files with “nowatermark” in the name are always left alone.', 'seoprostack'),
            ),
            'watermark_images_new' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Mark new uploads', 'seoprostack'),
                'description' => __('Turn off to mark only the pictures you choose in Media → Library.', 'seoprostack'),
            ),
            'watermark_images_keep' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Keep unmarked originals', 'seoprostack'),
                'description' => __('Needed to remove a watermark, or add it again with new settings. Kept in a hidden folder in uploads. Pictures marked while this is off cannot be unmarked.', 'seoprostack'),
            ),
        );
    }

    /**
     * Import Easy Watermark's first image watermark (text watermarks are not
     * supported), and switch on while Easy Watermark is active with one that
     * is added to uploads.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off import; the post type is not registered while Easy Watermark is inactive.
        $rows = $wpdb->get_col("SELECT post_content FROM {$wpdb->posts} WHERE post_type = 'watermark' AND post_status = 'publish' ORDER BY ID");
        $chosen = null;
        foreach ((array) $rows as $content) {
            $params = json_decode((string) $content, true);
            if (!is_array($params) || !isset($params['type']) || 'image' !== $params['type'] || empty($params['attachment_id'])) {
                continue;
            }
            if (null === $chosen || (!empty($params['auto_add']) && empty($chosen['auto_add']))) {
                $chosen = $params;
            }
        }
        if (null === $chosen) {
            return $options;
        }

        if (isset(self::active_plugins()['easy-watermark']) && !empty($chosen['auto_add'])) {
            $options = self::import_setting($options, self::KEY, true);
        }
        $options = self::import_setting($options, 'watermark_images_mark', 'image');
        $options = self::import_setting($options, 'watermark_images_image', (int) $chosen['attachment_id']);
        $options = self::import_setting($options, 'watermark_images_new', !empty($chosen['auto_add']));
        if (isset($chosen['opacity']) && is_numeric($chosen['opacity'])) {
            $options = self::import_setting($options, 'watermark_images_opacity', (int) $chosen['opacity']);
        }
        if (!empty($chosen['alignment']) && is_string($chosen['alignment'])) {
            $options = self::import_setting($options, 'watermark_images_position', $chosen['alignment']);
        }
        // Scaled to the picture: its scale is a share of the picture's width
        // or height, as here. Unscaled watermarks keep the default size.
        if (isset($chosen['scaling_mode'], $chosen['scale']) && 'none' !== $chosen['scaling_mode'] && is_numeric($chosen['scale'])) {
            $options = self::import_setting($options, 'watermark_images_size', (int) $chosen['scale']);
        }
        $settings = get_option('easy-watermark-settings', null);
        if (is_array($settings) && isset($settings['backup']['backup'])) {
            $options = self::import_setting($options, 'watermark_images_keep', (bool) $settings['backup']['backup']);
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        // Also when switched off: kept originals must not outlive their
        // pictures, and watermarks can still be removed.
        add_action('delete_attachment', array(__CLASS__, 'forget'));
        if (is_admin()) {
            add_action('load-upload.php', array(__CLASS__, 'library_hooks'));
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle_one'));
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel_status'), 10, 2);
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('seoprostack watermark-images', array(__CLASS__, 'cli'));
        }

        if (!self::enabled()) {
            return;
        }
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'note_client_processing'), 10, 3);
        // After Resize large uploads (90), which may delete the original.
        add_filter('wp_generate_attachment_metadata', array(__CLASS__, 'after_generate'), 95, 3);
    }

    /**
     * Row and bulk actions on Media → Library.
     */
    public static function library_hooks() {
        add_filter('media_row_actions', array(__CLASS__, 'row_action'), 10, 2);
        add_filter('bulk_actions-upload', array(__CLASS__, 'bulk_action'));
        add_filter('handle_bulk_actions-upload', array(__CLASS__, 'handle_bulk'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
    }

    /* --------------------------------------------------------------------- */
    /* The watermark                                                          */
    /* --------------------------------------------------------------------- */

    /**
     * The watermark picture: its attachment ID, size and files.
     *
     * @return array|WP_Error id, width, height, files => [[path, width, height]].
     */
    public static function mark() {
        $which = (string) SEOProStack_Settings::get('watermark_images_mark');
        if ('logo' === $which) {
            $id = (int) get_theme_mod('custom_logo');
            if (!$id) {
                $id = (int) get_option('site_logo');
            }
            $none = __('This site has no logo. Add one in the theme settings, or choose another watermark.', 'seoprostack');
        } elseif ('image' === $which) {
            $id   = (int) SEOProStack_Settings::get('watermark_images_image');
            $none = __('Choose a picture for the watermark.', 'seoprostack');
        } else {
            $id   = (int) get_option('site_icon');
            $none = __('This site has no site icon. Add one, or choose another watermark.', 'seoprostack');
        }
        if ($id <= 0) {
            return new WP_Error('watermark_none', $none);
        }

        $file = get_attached_file($id);
        if (!$file || !is_file($file)) {
            return new WP_Error('watermark_missing', __('The watermark picture’s file is missing.', 'seoprostack'));
        }
        $mime = get_post_mime_type($id);
        if ('image/svg+xml' === $mime) {
            return new WP_Error('watermark_svg', __('The watermark is an SVG file, which cannot be added to pictures. Use a PNG.', 'seoprostack'));
        }
        if (!in_array($mime, self::TYPES, true)) {
            return new WP_Error('watermark_type', __('The watermark must be a PNG, JPEG, GIF, WebP or AVIF picture.', 'seoprostack'));
        }

        $meta = wp_get_attachment_metadata($id);
        $meta = is_array($meta) ? $meta : array();
        if (empty($meta['width']) || empty($meta['height'])) {
            $size = @getimagesize($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is handled.
            if (!$size) {
                return new WP_Error('watermark_mark', __('The watermark picture could not be read.', 'seoprostack'));
            }
            $meta['width']  = (int) $size[0];
            $meta['height'] = (int) $size[1];
        }
        $files = array(array('path' => $file, 'width' => (int) $meta['width'], 'height' => (int) $meta['height']));
        foreach (isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : array() as $size) {
            if (empty($size['file']) || empty($size['width']) || empty($size['height'])) {
                continue;
            }
            $path = dirname($file) . '/' . wp_basename($size['file']);
            if (is_file($path)) {
                $files[] = array('path' => $path, 'width' => (int) $size['width'], 'height' => (int) $size['height']);
            }
        }
        return array('id' => $id, 'width' => (int) $meta['width'], 'height' => (int) $meta['height'], 'files' => $files);
    }

    /**
     * The smallest file of the watermark at least as large as needed, so
     * it is scaled down, not up.
     *
     * @param array $mark   mark().
     * @param int   $width  Width needed.
     * @param int   $height Height needed.
     * @return string
     */
    private static function mark_file(array $mark, $width, $height) {
        $best = null;
        foreach ($mark['files'] as $file) {
            if ($file['width'] >= $width && $file['height'] >= $height && (null === $best || $file['width'] < $best['width'])) {
                $best = $file;
            }
        }
        return null === $best ? $mark['files'][0]['path'] : $best['path'];
    }

    /**
     * Where the watermark goes on a picture.
     *
     * @param array $mark   mark().
     * @param int   $width  Picture width.
     * @param int   $height Picture height.
     * @return array|false x, y, width, height; false when it would be too small.
     */
    public static function placement(array $mark, $width, $height) {
        $share  = max(1, (int) SEOProStack_Settings::get('watermark_images_size')) / 100;
        $scale  = min($width * $share / max(1, $mark['width']), $height * $share / max(1, $mark['height']));
        $mark_w = (int) round($mark['width'] * $scale);
        $mark_h = (int) round($mark['height'] * $scale);
        if ($mark_w < 8 || $mark_h < 8) {
            return false;
        }
        $margin   = (int) round(min($width, $height) * max(0, (int) SEOProStack_Settings::get('watermark_images_margin')) / 100);
        $position = (string) SEOProStack_Settings::get('watermark_images_position');

        $x = (int) round(($width - $mark_w) / 2);
        if (false !== strpos($position, 'left')) {
            $x = $margin;
        } elseif (false !== strpos($position, 'right')) {
            $x = $width - $mark_w - $margin;
        }
        $y = (int) round(($height - $mark_h) / 2);
        if (false !== strpos($position, 'top')) {
            $y = $margin;
        } elseif (false !== strpos($position, 'bottom')) {
            $y = $height - $mark_h - $margin;
        }
        return array(
            'x'      => max(0, $x),
            'y'      => max(0, $y),
            'width'  => $mark_w,
            'height' => $mark_h,
        );
    }

    /**
     * An editor that can mark a file, following WordPress's choice of image
     * library (Imagick, then GD, unless changed with wp_image_editors).
     *
     * @param string $path File path.
     * @param string $mime MIME type.
     * @return SEOProStack_Watermark_GD|SEOProStack_Watermark_Imagick|WP_Error
     */
    private static function editor($path, $mime) {
        $classes = self::editor_classes();
        foreach ($classes as $class) {
            $args = array('path' => $path, 'mime_type' => $mime);
            if (!call_user_func(array($class, 'test'), $args) || !call_user_func(array($class, 'supports_mime_type'), $mime)) {
                continue;
            }
            $editor = new $class($path);
            $loaded = $editor->load();
            return is_wp_error($loaded) ? $loaded : $editor;
        }
        return new WP_Error('watermark_editor', __('This server’s image library cannot change this type of picture.', 'seoprostack'));
    }

    /**
     * Watermark editor classes in WordPress's order of preference.
     *
     * @return string[]
     */
    private static function editor_classes() {
        require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
        require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
        require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
        require_once SEOPROSTACK_DIR . 'includes/image-editors/class-seoprostack-watermark-gd.php';
        require_once SEOPROSTACK_DIR . 'includes/image-editors/class-seoprostack-watermark-imagick.php';

        $ours    = array(
            'WP_Image_Editor_Imagick' => 'SEOProStack_Watermark_Imagick',
            'WP_Image_Editor_GD'      => 'SEOProStack_Watermark_GD',
        );
        $classes = array();
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, read to follow WordPress's choice.
        foreach ((array) apply_filters('wp_image_editors', array('WP_Image_Editor_Imagick', 'WP_Image_Editor_GD')) as $class) {
            if (is_string($class) && isset($ours[$class])) {
                $classes[] = $ours[$class];
            }
        }
        return $classes;
    }

    /**
     * Whether this server can mark pictures at all.
     *
     * @return bool
     */
    public static function can_mark() {
        foreach (self::editor_classes() as $class) {
            if (call_user_func(array($class, 'test'), array())) {
                return true;
            }
        }
        return false;
    }

    /* --------------------------------------------------------------------- */
    /* Marking                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * WordPress 7.1 lets the browser process uploads: the original arrives
     * with generate_sub_sizes=false, then the browser's sizes, then a
     * finalize request. Mark at finalize, when every size exists.
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
     * After WordPress has made every size of a picture: mark new uploads.
     * Sizes made again later from a marked picture carry the watermark
     * already, so they are only noted.
     *
     * @param array  $metadata      Metadata.
     * @param int    $attachment_id Attachment ID.
     * @param string $context       create or update.
     * @return array
     */
    public static function after_generate($metadata, $attachment_id, $context = 'create') {
        if (self::$lock || self::$client_processed || !is_array($metadata)) {
            return $metadata;
        }
        $record = self::record($attachment_id);
        if ($record && !self::record_current($attachment_id, $record)) {
            // A new file (replaced): the kept original is of the old one.
            self::forget($attachment_id);
            $record = array();
        }
        if ($record && !self::$finalizing) {
            $file = get_attached_file($attachment_id);
            foreach (self::file_names($file, $metadata) as $name) {
                if (!isset($record['files'][$name]) && is_file(dirname($file) . '/' . $name)) {
                    $record['files'][$name] = (int) filesize(dirname($file) . '/' . $name);
                }
            }
            update_post_meta($attachment_id, self::META, $record);
            return $metadata;
        }
        if (!$record && (!SEOProStack_Settings::get('watermark_images_new') || ('create' !== $context && !self::$finalizing))) {
            return $metadata;
        }
        $result = self::mark_attachment($attachment_id, $metadata);
        return is_array($result) ? $result : $metadata;
    }

    /**
     * Why an attachment cannot be marked, if it cannot.
     *
     * @param int  $attachment_id Attachment ID.
     * @param bool $read_file     Also read the file to find animations
     *                            (skipped for Media Library rows).
     * @return WP_Error|null
     */
    public static function unmarkable($attachment_id, $read_file = true) {
        $attachment_id = (int) $attachment_id;
        $file          = get_attached_file($attachment_id);
        $mime          = get_post_mime_type($attachment_id);
        if (!$file || !is_file($file) || !in_array($mime, self::TYPES, true)) {
            return new WP_Error('watermark_not_image', __('Not a picture that can be marked.', 'seoprostack'));
        }
        if (false !== stripos(wp_basename($file), 'nowatermark')) {
            return new WP_Error('watermark_excluded', __('The file name says “nowatermark”.', 'seoprostack'));
        }
        // Site icons, logos, headers and backgrounds cropped in the Customizer.
        if ('' !== (string) get_post_meta($attachment_id, '_wp_attachment_context', true)) {
            return new WP_Error('watermark_site_image', __('Site icons, logos and headers are not marked.', 'seoprostack'));
        }
        if (in_array($attachment_id, array((int) get_option('site_icon'), (int) get_theme_mod('custom_logo'), (int) get_option('site_logo'), (int) SEOProStack_Settings::get('watermark_images_image')), true)) {
            return new WP_Error('watermark_is_mark', __('This picture is the watermark or site icon.', 'seoprostack'));
        }
        if (get_post_meta($attachment_id, '_ew_applied_watermarks', true)) {
            return new WP_Error('watermark_easy_watermark', __('Easy Watermark has already marked this picture.', 'seoprostack'));
        }
        if ($read_file && self::is_animated($file, $mime)) {
            return new WP_Error('watermark_animated', __('Animated pictures are not marked.', 'seoprostack'));
        }
        /**
         * Whether a picture may be marked.
         *
         * @param bool $mark          Return false to leave it alone.
         * @param int  $attachment_id Attachment ID.
         */
        if (!apply_filters('seoprostack_watermark_attachment', true, $attachment_id)) {
            return new WP_Error('watermark_filtered', __('Left alone by a filter.', 'seoprostack'));
        }
        return null;
    }

    /**
     * Mark the files of an attachment that are not marked yet: the file,
     * the original WordPress kept, then every size large enough. The first
     * time, keep an unmarked copy of the original (if chosen).
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $metadata      Metadata (sizes to mark).
     * @return array|string|WP_Error Metadata with new file sizes when marked;
     *                               "small" when nothing was large enough.
     */
    public static function mark_attachment($attachment_id, array $metadata) {
        $attachment_id = (int) $attachment_id;
        $why_not       = self::unmarkable($attachment_id);
        if ($why_not) {
            return $why_not;
        }
        $mark = self::mark();
        if (is_wp_error($mark)) {
            return $mark;
        }
        wp_raise_memory_limit('image');

        $file   = get_attached_file($attachment_id);
        $dir    = dirname($file);
        $record = self::record($attachment_id);
        $first  = !$record;
        if ($first) {
            $source = !empty($metadata['original_image']) && is_file($dir . '/' . wp_basename($metadata['original_image']))
                ? wp_basename($metadata['original_image'])
                : wp_basename($file);
            $record = array('files' => array(), 'source' => $source, 'backup' => '');
            if (SEOProStack_Settings::get('watermark_images_keep')) {
                $backup = self::keep_original($dir . '/' . $source);
                if (is_wp_error($backup)) {
                    return $backup;
                }
                $record['backup'] = $backup;
            }
        }

        $errors = array();
        $marked = 0;
        foreach (self::file_names($file, $metadata) as $name) {
            if (isset($record['files'][$name]) || !is_file($dir . '/' . $name)) {
                continue;
            }
            $bytes = self::mark_file_at($dir . '/' . $name, $mark);
            $main  = wp_basename($file) === $name;
            if (is_wp_error($bytes)) {
                $errors[] = $bytes;
            } elseif ($bytes > 0) {
                $record['files'][$name] = $bytes;
                $marked++;
                continue;
            }
            if ($main) {
                // The main file comes first and is the largest: when it is
                // not marked, nothing else is changed.
                break;
            }
        }

        if (!isset($record['files'][wp_basename($file)])) {
            // The main file is not marked (too small or failed): nothing is.
            if ($first) {
                self::delete_backup($record);
            }
            return $errors ? $errors[0] : 'small';
        }
        update_post_meta($attachment_id, self::META, $record);

        // Sizes of the changed files, for the Media Library and WordPress.
        if ($marked) {
            clearstatcache();
            if (isset($record['files'][wp_basename($file)])) {
                $metadata['filesize'] = $record['files'][wp_basename($file)];
            }
            foreach (isset($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : array() as $size => $data) {
                if (!empty($data['file']) && isset($record['files'][wp_basename($data['file'])])) {
                    $metadata['sizes'][$size]['filesize'] = $record['files'][wp_basename($data['file'])];
                }
            }
        }
        return $metadata;
    }

    /**
     * Mark one file in place.
     *
     * @param string $path File path.
     * @param array  $mark mark().
     * @return int|WP_Error Bytes of the marked file; 0 when too small.
     */
    private static function mark_file_at($path, array $mark) {
        $type = wp_check_filetype($path);
        $mime = $type['type'];
        if (!in_array($mime, self::TYPES, true) || self::is_animated($path, $mime)) {
            return 0;
        }
        $size = @getimagesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is handled.
        $min  = max(0, (int) SEOProStack_Settings::get('watermark_images_min'));
        if (!$size || min((int) $size[0], (int) $size[1]) < $min) {
            return 0;
        }

        $editor = self::editor($path, $mime);
        if (is_wp_error($editor)) {
            return $editor;
        }
        // The saved file has no orientation tag, so turn the picture upright.
        $editor->maybe_exif_rotate();
        $dims  = $editor->get_size();
        $place = self::placement($mark, (int) $dims['width'], (int) $dims['height']);
        if (!$place) {
            return 0;
        }
        $stamped = $editor->stamp(self::mark_file($mark, $place['width'], $place['height']), $place['width'], $place['height'], $place['x'], $place['y'], (int) SEOProStack_Settings::get('watermark_images_opacity') / 100);
        if (is_wp_error($stamped)) {
            return $stamped;
        }

        $temp  = $path . '.sps-tmp.' . pathinfo($path, PATHINFO_EXTENSION);
        $saved = $editor->save($temp, $mime);
        if (is_wp_error($saved)) {
            return $saved;
        }
        clearstatcache();
        // Another plugin may change the output format or name.
        if (empty($saved['path']) || $saved['path'] !== $temp || $saved['mime-type'] !== $mime || !is_file($temp)) {
            if (!empty($saved['path']) && is_file($saved['path'])) {
                wp_delete_file($saved['path']);
            }
            return new WP_Error('watermark_save', __('The marked picture could not be saved.', 'seoprostack'));
        }
        if (!@rename($temp, $path)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- same directory.
            wp_delete_file($temp);
            return new WP_Error('watermark_save', __('The marked picture could not be saved.', 'seoprostack'));
        }
        self::delete_copies($path);
        clearstatcache();
        return (int) filesize($path);
    }

    /**
     * Mark an existing picture. A marked picture with a kept original is
     * marked again from the original, with the current settings.
     *
     * @param int $attachment_id Attachment ID.
     * @return string|WP_Error added, small or marked (marked, nothing kept).
     */
    public static function add($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $record        = self::record($attachment_id);
        if ($record) {
            if (empty($record['backup'])) {
                return 'marked';
            }
            $removed = self::remove($attachment_id);
            if (is_wp_error($removed)) {
                return $removed;
            }
        }
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!is_array($meta)) {
            return new WP_Error('watermark_not_image', __('Not a picture that can be marked.', 'seoprostack'));
        }
        $result = self::mark_attachment($attachment_id, $meta);
        if (is_array($result)) {
            wp_update_attachment_metadata($attachment_id, $result);
            delete_transient('dirsize_cache');
            return 'added';
        }
        return $result;
    }

    /**
     * Remove the watermark: put the kept original back and make every size
     * again from it, as when it was uploaded.
     *
     * @param int $attachment_id Attachment ID.
     * @return string|WP_Error removed or unmarked.
     */
    public static function remove($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $record        = self::record($attachment_id);
        if (!$record) {
            return 'unmarked';
        }
        $backup = self::backup_path($record);
        if (!$backup || !is_file($backup)) {
            return new WP_Error('watermark_no_original', __('No unmarked original was kept for this picture.', 'seoprostack'));
        }
        if (!self::record_current($attachment_id, $record)) {
            return new WP_Error('watermark_changed', __('This picture was edited or replaced after it was marked, so the kept original is out of date.', 'seoprostack'));
        }

        $file   = get_attached_file($attachment_id);
        $meta   = wp_get_attachment_metadata($attachment_id);
        $dir    = dirname($file);
        $source = $dir . '/' . $record['source'];
        if (!@copy($backup, $source)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is handled.
            return new WP_Error('watermark_restore', __('The original could not be put back.', 'seoprostack'));
        }
        self::delete_copies($source);

        // The original is back; the marked file and sizes are made again.
        foreach (self::file_names($file, is_array($meta) ? $meta : array()) as $name) {
            if ($name !== $record['source'] && is_file($dir . '/' . $name)) {
                wp_delete_file($dir . '/' . $name);
            }
        }
        update_attached_file($attachment_id, $source);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        self::$lock = true;
        $new_meta   = wp_generate_attachment_metadata($attachment_id, $source);
        self::$lock = false;
        if (is_array($new_meta)) {
            wp_update_attachment_metadata($attachment_id, $new_meta);
        }
        delete_post_meta($attachment_id, self::META);
        self::delete_backup($record);
        delete_transient('dirsize_cache');
        return 'removed';
    }

    /**
     * Forget an attachment's watermark: delete its kept original and record.
     * Runs when the attachment is deleted or gets a new file.
     *
     * @param int $attachment_id Attachment ID.
     */
    public static function forget($attachment_id) {
        $record = self::record($attachment_id);
        if ($record) {
            self::delete_backup($record);
            delete_post_meta((int) $attachment_id, self::META);
        }
    }

    /**
     * An attachment's watermark record.
     *
     * @param int $attachment_id Attachment ID.
     * @return array Empty when not marked.
     */
    public static function record($attachment_id) {
        $record = get_post_meta((int) $attachment_id, self::META, true);
        if (!is_array($record) || empty($record['files']) || !is_array($record['files']) || empty($record['source'])) {
            return array();
        }
        $record['backup'] = isset($record['backup']) ? (string) $record['backup'] : '';
        return $record;
    }

    /**
     * Whether the attachment's file is still the one that was marked.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $record        record().
     * @return bool
     */
    private static function record_current($attachment_id, array $record) {
        $file = get_attached_file($attachment_id);
        $name = $file ? wp_basename($file) : '';
        clearstatcache();
        return '' !== $name && isset($record['files'][$name]) && is_file($file) && (int) filesize($file) === (int) $record['files'][$name];
    }

    /**
     * File names of an attachment: the file, the original WordPress kept
     * and its sizes, largest first.
     *
     * @param string $file Attached file.
     * @param array  $meta Metadata.
     * @return string[]
     */
    private static function file_names($file, array $meta) {
        $names = array(wp_basename($file));
        if (!empty($meta['original_image'])) {
            $names[] = wp_basename($meta['original_image']);
        }
        $sizes = isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : array();
        uasort($sizes, function ($a, $b) {
            return (isset($b['width']) ? (int) $b['width'] : 0) - (isset($a['width']) ? (int) $a['width'] : 0);
        });
        foreach ($sizes as $size) {
            if (!empty($size['file'])) {
                $names[] = wp_basename($size['file']);
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Delete WebP and AVIF copies of a changed file, so the old picture is
     * never sent; WebP and AVIF images makes them again.
     *
     * @param string $path File path.
     */
    private static function delete_copies($path) {
        if (class_exists('SEOProStack_Nextgen_Images')) {
            SEOProStack_Nextgen_Images::delete_copies($path);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Kept originals                                                         */
    /* --------------------------------------------------------------------- */

    /**
     * The folder for unmarked originals, in uploads, with a random name so
     * its files cannot be guessed (servers such as Nginx ignore .htaccess).
     *
     * @return array|WP_Error basedir, name.
     */
    private static function originals_dir() {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return new WP_Error('watermark_uploads', __('The uploads folder is not available.', 'seoprostack'));
        }
        $name = (string) get_option(self::DIR_OPTION, '');
        if (!preg_match('/^seoprostack-originals-[a-z0-9]{16}$/', $name)) {
            $name = 'seoprostack-originals-' . strtolower(wp_generate_password(16, false));
            update_option(self::DIR_OPTION, $name, false);
        }
        $dir = trailingslashit($uploads['basedir']) . $name;
        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                return new WP_Error('watermark_originals', __('The folder for unmarked originals could not be made.', 'seoprostack'));
            }
            // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- small guard files.
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            // phpcs:enable
        }
        return array('basedir' => trailingslashit($uploads['basedir']), 'name' => $name);
    }

    /**
     * Copy a file to the originals folder, in the same year/month folders.
     *
     * @param string $path File path.
     * @return string|WP_Error Path of the copy, relative to uploads.
     */
    private static function keep_original($path) {
        $dir = self::originals_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }
        $relative = _wp_relative_upload_path($path);
        $subdir   = dirname($relative);
        $subdir   = '.' === $subdir || $relative === $path ? '' : '/' . $subdir;
        $target   = $dir['basedir'] . $dir['name'] . $subdir;
        if (!wp_mkdir_p($target)) {
            return new WP_Error('watermark_originals', __('The folder for unmarked originals could not be made.', 'seoprostack'));
        }
        // Not wp_unique_filename(): it also renames for WordPress's image
        // size and "-scaled" names, which do not apply here.
        $name = wp_basename($path);
        for ($i = 1; file_exists($target . '/' . $name); $i++) {
            $name = pathinfo($path, PATHINFO_FILENAME) . '-' . $i . '.' . pathinfo($path, PATHINFO_EXTENSION);
        }
        if (!@copy($path, $target . '/' . $name)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is handled.
            return new WP_Error('watermark_originals', __('The unmarked original could not be kept, so the picture was not marked.', 'seoprostack'));
        }
        return ltrim($dir['name'] . $subdir . '/' . $name, '/');
    }

    /**
     * Full path of a record's kept original.
     *
     * @param array $record record().
     * @return string Empty when none.
     */
    private static function backup_path(array $record) {
        if ('' === $record['backup'] || false !== strpos($record['backup'], '..')) {
            return '';
        }
        $uploads = wp_get_upload_dir();
        return empty($uploads['basedir']) ? '' : trailingslashit($uploads['basedir']) . $record['backup'];
    }

    /**
     * Delete a record's kept original.
     *
     * @param array $record record() or a new record.
     */
    private static function delete_backup(array $record) {
        $record['backup'] = isset($record['backup']) ? (string) $record['backup'] : '';
        $path             = self::backup_path($record);
        if ($path && 0 === strpos($record['backup'], 'seoprostack-originals-') && is_file($path)) {
            wp_delete_file($path);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Media Library                                                          */
    /* --------------------------------------------------------------------- */

    /**
     * Add or Remove watermark link.
     *
     * @param array   $actions Row actions.
     * @param WP_Post $post    Attachment.
     * @return array
     */
    public static function row_action($actions, $post) {
        if (!current_user_can('edit_post', $post->ID) || !wp_attachment_is_image($post)) {
            return $actions;
        }
        $record = self::record($post->ID);
        if ($record) {
            if ('' === $record['backup']) {
                return $actions;
            }
            $op    = 'remove';
            $label = __('Remove watermark', 'seoprostack');
            /* translators: %s: attachment title */
            $aria = __('Remove the watermark from “%s”', 'seoprostack');
        } elseif (self::enabled() && !self::unmarkable($post->ID, false)) {
            $op    = 'add';
            $label = __('Add watermark', 'seoprostack');
            /* translators: %s: attachment title */
            $aria = __('Add a watermark to “%s”', 'seoprostack');
        } else {
            return $actions;
        }
        $url = wp_nonce_url(add_query_arg(array('action' => self::ACTION, 'op' => $op, 'attachment' => $post->ID), admin_url('admin-post.php')), self::ACTION . '_' . $post->ID);
        $actions['seoprostack_watermark'] = sprintf('<a href="%1$s" aria-label="%2$s">%3$s</a>', esc_url($url), esc_attr(sprintf($aria, get_the_title($post))), esc_html($label));
        return $actions;
    }

    /**
     * admin-post.php?action=seoprostack_watermark_image
     */
    public static function handle_one() {
        $id = isset($_GET['attachment']) ? absint(wp_unslash($_GET['attachment'])) : 0;
        check_admin_referer(self::ACTION . '_' . $id);
        if (!$id || !current_user_can('edit_post', $id)) {
            wp_die(esc_html__('Sorry, you are not allowed to edit this item.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
        $op     = isset($_GET['op']) && 'remove' === $_GET['op'] ? 'remove' : 'add';
        $counts = self::run(array($id), $op);
        $back   = wp_get_referer();
        wp_safe_redirect(add_query_arg($counts, $back ? $back : admin_url('upload.php?mode=list')));
        exit;
    }

    /**
     * Bulk actions on Media → Library (list view).
     *
     * @param array $actions Bulk actions.
     * @return array
     */
    public static function bulk_action($actions) {
        if (self::enabled()) {
            $actions[self::BULK_ADD] = __('Add watermark', 'seoprostack');
        }
        $actions[self::BULK_REMOVE] = __('Remove watermark', 'seoprostack');
        return $actions;
    }

    /**
     * Mark or unmark the chosen pictures.
     *
     * @param string $redirect Where to go afterwards.
     * @param string $action   Bulk action.
     * @param int[]  $ids      Attachment IDs.
     * @return string
     */
    public static function handle_bulk($redirect, $action, $ids) {
        if (self::BULK_ADD !== $action && self::BULK_REMOVE !== $action) {
            return $redirect;
        }
        $ids = array_filter(array_map('absint', (array) $ids), function ($id) {
            return current_user_can('edit_post', $id);
        });
        $counts = self::run($ids, self::BULK_REMOVE === $action ? 'remove' : 'add');
        return add_query_arg($counts, remove_query_arg(self::COUNTS, $redirect));
    }

    /**
     * Mark or unmark attachments within the time budget. The first error
     * message is kept briefly for the notice.
     *
     * @param int[]  $ids Attachment IDs.
     * @param string $op  add or remove.
     * @return array<string,int> Counts for the notice.
     */
    private static function run(array $ids, $op) {
        $start  = microtime(true);
        $counts = array_fill_keys(self::COUNTS, 0);
        $error  = '';
        foreach (array_values($ids) as $i => $id) {
            if (microtime(true) - $start > self::BUDGET) {
                $counts['sps_wm_left'] = count($ids) - $i;
                break;
            }
            if ('add' === $op && !self::enabled()) {
                $counts['sps_wm_skipped']++;
                continue;
            }
            $status = 'remove' === $op ? self::remove($id) : self::add($id);
            if (is_wp_error($status)) {
                // Pictures that are not for marking are skipped, not failed.
                if ('add' === $op && self::unmarkable($id)) {
                    $counts['sps_wm_skipped']++;
                    continue;
                }
                $counts['sps_wm_failed']++;
                $error = $error ? $error : $status->get_error_message();
            } elseif ('added' === $status) {
                $counts['sps_wm_added']++;
            } elseif ('removed' === $status) {
                $counts['sps_wm_removed']++;
            } elseif ('unmarked' === $status) {
                $counts['sps_wm_unmarked']++;
            } else {
                $counts['sps_wm_skipped']++;
            }
        }
        if ($error) {
            set_transient('seoprostack_watermark_error_' . get_current_user_id(), $error, 5 * MINUTE_IN_SECONDS);
        }
        return $counts;
    }

    /**
     * Result notice on Media → Library.
     */
    public static function notice() {
        if (!isset($_GET['sps_wm_added'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
            return;
        }
        $get = function ($key) {
            return isset($_GET[$key]) ? absint(wp_unslash($_GET[$key])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        };
        $parts    = array();
        $removing = $get('sps_wm_removed') || $get('sps_wm_unmarked');
        if ($get('sps_wm_added') || !$removing) {
            /* translators: %d: number of pictures */
            $parts[] = sprintf(_n('%d picture marked.', '%d pictures marked.', $get('sps_wm_added'), 'seoprostack'), $get('sps_wm_added'));
        }
        if ($removing) {
            /* translators: %d: number of pictures */
            $parts[] = sprintf(_n('Watermark removed from %d picture.', 'Watermark removed from %d pictures.', $get('sps_wm_removed'), 'seoprostack'), $get('sps_wm_removed'));
        }
        if ($get('sps_wm_unmarked')) {
            /* translators: %d: number of pictures */
            $parts[] = sprintf(_n('%d had no watermark.', '%d had no watermark.', $get('sps_wm_unmarked'), 'seoprostack'), $get('sps_wm_unmarked'));
        }
        if ($get('sps_wm_skipped')) {
            /* translators: %d: number of pictures */
            $parts[] = sprintf(_n('%d was left alone: too small, or not a picture that is marked.', '%d were left alone: too small, or not pictures that are marked.', $get('sps_wm_skipped'), 'seoprostack'), $get('sps_wm_skipped'));
        }
        if ($get('sps_wm_failed')) {
            /* translators: %d: number of pictures */
            $parts[] = sprintf(_n('%d could not be changed.', '%d could not be changed.', $get('sps_wm_failed'), 'seoprostack'), $get('sps_wm_failed'));
            $key   = 'seoprostack_watermark_error_' . get_current_user_id();
            $error = get_transient($key);
            if ($error) {
                $parts[] = (string) $error;
                delete_transient($key);
            }
        }
        if ($get('sps_wm_left')) {
            /* translators: %d: number of pictures */
            $parts[] = sprintf(_n('%d was left for lack of time: select it and try again.', '%d were left for lack of time: select them and try again.', $get('sps_wm_left'), 'seoprostack'), $get('sps_wm_left'));
        }
        $type = $get('sps_wm_failed') || $get('sps_wm_left') ? 'warning' : 'success';
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($type), esc_html(implode(' ', $parts)));
    }

    /**
     * Drop the notice arguments from the address after showing them.
     *
     * @param string[] $args Query arguments.
     * @return string[]
     */
    public static function removable_query_args($args) {
        return array_merge($args, self::COUNTS);
    }

    /**
     * Settings panel: the watermark in use, or why none can be added.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel_status($key, $field) {
        if (self::KEY !== $key) {
            return;
        }
        if (!self::can_mark()) {
            printf('<div class="sps-panel-note sps-panel-note--warning"><p>%s</p></div>', esc_html__('This server has no image library that can add watermarks (GD or Imagick).', 'seoprostack'));
            return;
        }
        $mark = self::mark();
        if (is_wp_error($mark)) {
            printf('<div class="sps-panel-note sps-panel-note--warning"><p>%s</p></div>', esc_html($mark->get_error_message()));
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one count on the settings screen.
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META));
        printf(
            '<div class="sps-panel-note sps-watermark-note">%1$s<p>%2$s %3$s</p></div>',
            wp_get_attachment_image($mark['id'], 'thumbnail', false, array('class' => 'sps-watermark-note__mark', 'alt' => '')),
            esc_html(sprintf(
                /* translators: %d: number of pictures */
                _n('%d picture has a watermark.', '%d pictures have a watermark.', $count, 'seoprostack'),
                $count
            )),
            wp_kses(
                sprintf(
                    /* translators: %s: Media Library address */
                    __('To mark pictures already uploaded, or change or remove watermarks, select them in <a href="%s">Media → Library</a> (list view) and choose a bulk action.', 'seoprostack'),
                    esc_url(admin_url('upload.php?mode=list'))
                ),
                array('a' => array('href' => array()))
            )
        );
    }

    /**
     * Add, change or remove watermarks on existing pictures.
     *
     * ## OPTIONS
     *
     * [--remove]
     * : Remove watermarks, putting the kept originals back.
     *
     * [--dry-run]
     * : List the pictures that would change.
     *
     * ## EXAMPLES
     *
     *     wp seoprostack watermark-images --dry-run
     *     wp seoprostack watermark-images
     *     wp seoprostack watermark-images --remove
     *
     * Pictures already marked are marked again from their kept originals,
     * with the current settings.
     *
     * @param array $args  Positional arguments.
     * @param array $assoc Options.
     */
    public static function cli($args, $assoc) {
        $remove = !empty($assoc['remove']);
        $dry    = !empty($assoc['dry-run']);
        if (!$remove && !self::enabled()) {
            WP_CLI::error('Watermark pictures is off, or waiting for Easy Watermark to be deactivated.');
        }
        if (!$remove) {
            $mark = self::mark();
            if (is_wp_error($mark)) {
                WP_CLI::error($mark->get_error_message());
            }
        }
        $counts = array('added' => 0, 'removed' => 0, 'skipped' => 0, 'failed' => 0);
        $last   = 0;
        global $wpdb;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- paged by ID, so pictures that change are not skipped or repeated.
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID LIMIT 100",
                $wpdb->esc_like('image/') . '%',
                $last
            ));
            foreach ($ids as $id) {
                $last = (int) $id;
                if ($remove ? !self::record($last) : (bool) self::unmarkable($last)) {
                    continue;
                }
                if ($dry) {
                    $meta = wp_get_attachment_metadata($last);
                    if (!$remove && is_array($meta) && isset($meta['width'], $meta['height']) && min((int) $meta['width'], (int) $meta['height']) < (int) SEOProStack_Settings::get('watermark_images_min')) {
                        continue;
                    }
                    WP_CLI::log(sprintf('%d %s', $last, get_attached_file($last)));
                    $counts[$remove ? 'removed' : 'added']++;
                    continue;
                }
                $status = $remove ? self::remove($last) : self::add($last);
                if (is_wp_error($status)) {
                    $counts['failed']++;
                    WP_CLI::warning(sprintf('%d: %s', $last, $status->get_error_message()));
                } elseif (isset($counts[$status])) {
                    $counts[$status]++;
                } else {
                    $counts['skipped']++;
                }
            }
            wp_cache_flush_runtime();
        } while ($ids);

        if ($dry) {
            WP_CLI::success(sprintf($remove ? '%d pictures would be unmarked.' : '%d pictures would be marked.', $remove ? $counts['removed'] : $counts['added']));
            return;
        }
        WP_CLI::success(sprintf('%d marked, %d unmarked, %d left alone, %d failed.', $counts['added'], $counts['removed'], $counts['skipped'], $counts['failed']));
    }

    /* --------------------------------------------------------------------- */
    /* File checks                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * Whether a picture is animated (GIF, WebP or PNG); marking would keep
     * only the first frame.
     *
     * @param string $path File path.
     * @param string $mime MIME type.
     * @return bool
     */
    private static function is_animated($path, $mime) {
        if ('image/gif' === $mime) {
            $data = (string) file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
            return preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $data) > 1;
        }
        if ('image/webp' !== $mime && 'image/png' !== $mime) {
            return false;
        }
        $head = (string) file_get_contents($path, false, null, 0, 'image/png' === $mime ? 4096 : 32); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        if ('image/webp' === $mime) {
            return strlen($head) > 20 && 'VP8X' === substr($head, 12, 4) && (ord($head[20]) & 0x02);
        }
        $control = strpos($head, 'acTL');
        $data    = strpos($head, 'IDAT');
        return false !== $control && (false === $data || $control < $data);
    }
}
