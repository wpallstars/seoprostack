<?php
/**
 * WebP and AVIF images.
 *
 * A smaller WebP and/or AVIF copy of every JPEG and PNG picture (and an AVIF
 * copy of WebP pictures) in the Media Library, and of every size of it, is
 * saved next to the file as `photo.jpg.webp` / `photo.jpg.avif`. Browsers
 * that accept those formats get the copy instead, at the same address:
 * - on Apache and LiteSpeed through rules in the uploads folder's .htaccess
 *   (on multisite one block for the network, since every site's uploads are
 *   below the main site's);
 * - on Nginx through a few configuration lines shown on the settings card.
 * Pages, the editors and the Media Library keep the original addresses, so
 * nothing else changes and switching off needs no clean-up.
 *
 * Copies at their own addresses (GitHub issue #779) instead wraps pictures
 * in posts, pages, block widgets and attachment images (featured images,
 * logos) in a <picture> tag naming the copies, and the rules only give the
 * copies their types. Every address then always sends one file, so CDNs
 * that ignore "Vary: Accept" can cache pictures without the private header.
 *
 * Pictures are converted in the background with WP-Cron: new uploads, edited
 * and replaced pictures, and every existing picture, newest first. A copy is
 * only kept when it is smaller than its original. Animated pictures are left
 * alone. Copies CompressX made (in wp-content/compressx-nextgen) are moved
 * next to the pictures instead of being made again, so they keep working
 * after CompressX is deleted.
 *
 * Replaces "CompressX" and imports its settings.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Nextgen_Images extends SEOProStack_Feature {

    const KEY = 'nextgen_images';

    /** Post meta: what was made for an attachment (sizes, for the details). */
    const META = '_seoprostack_nextgen';

    /** Post meta: formats an attachment was converted for. Missing or different = waiting. */
    const DONE = '_seoprostack_nextgen_done';

    /** WP-Cron hook for background batches. */
    const HOOK = 'seoprostack_nextgen_batch';

    /** Transient: a background batch is running. */
    const LOCK = 'seoprostack_nextgen_lock';

    /** Network (site) option: sites using the rules, and the rules' status. */
    const RULES = 'seoprostack_nextgen_rules';

    /** Option: what this site last put in the rules, so syncing is free when nothing changed. */
    const SYNCED = 'seoprostack_nextgen_synced';

    /** Transient: whether a test request got a copy, for the rules then in place. */
    const CHECK = 'seoprostack_nextgen_check';

    /** .htaccess marker. */
    const MARKER = 'SEO Pro Stack WebP and AVIF';

    const BULK = 'seoprostack_nextgen';

    /** Seconds a batch may run. */
    const BUDGET = 20;

    /** Copy formats: extension => MIME type. */
    const FORMATS = array(
        'avif' => 'image/avif',
        'webp' => 'image/webp',
    );

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
                'label'       => __('WebP and AVIF images', 'seoprostack'),
                'description' => __('Save a smaller WebP and AVIF copy of every picture in the Media Library and send it to browsers that support it, at the same address. Originals are not changed. Pictures are converted in the background.', 'seoprostack'),
                'replaces'    => array('compressx' => 'CompressX'),
            ),
            'nextgen_webp' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Make WebP copies', 'seoprostack'),
                'description' => __('Every current browser can show WebP.', 'seoprostack'),
            ),
            'nextgen_webp_quality' => array(
                'type'        => 'int',
                'default'     => 90,
                'min'         => 10,
                'max'         => 100,
                'parent'      => self::KEY,
                'label'       => __('WebP quality', 'seoprostack'),
                'description' => __('90 keeps pictures sharp; lower makes smaller files. After a change, pictures are converted again in the background.', 'seoprostack'),
            ),
            'nextgen_avif' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Make AVIF copies', 'seoprostack'),
                'description' => self::avif_description(),
            ),
            'nextgen_avif_quality' => array(
                'type'        => 'int',
                'default'     => 70,
                'min'         => 10,
                'max'         => 100,
                'parent'      => self::KEY,
                'label'       => __('AVIF quality', 'seoprostack'),
                'description' => __('AVIF at 70 looks about the same as WebP at 90. After a change, pictures are converted again in the background.', 'seoprostack'),
            ),
            'nextgen_smart' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Quality by picture size', 'seoprostack'),
                'description' => __('Higher quality for small sizes such as thumbnails, where flaws show, and lower for large ones, which are usually shown smaller than they are: +15 below 200 pixels, −10 from 800, −20 from 2,000 (the longest side).', 'seoprostack'),
            ),
            'nextgen_png' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Convert PNG pictures', 'seoprostack'),
                'description' => __('Screenshots and graphics usually become much smaller too. Transparency is kept.', 'seoprostack'),
            ),
            'nextgen_private' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Keep pictures out of shared caches', 'seoprostack'),
                'description' => __('Stops CDNs that ignore “Vary: Accept”, such as Cloudflare’s free plan, from sending AVIF or WebP to browsers that cannot show them. Browsers still cache pictures. Turn off if your CDN handles “Vary: Accept”. Apache and LiteSpeed only. Not used with Copies at their own addresses.', 'seoprostack'),
            ),
            'nextgen_picture' => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Copies at their own addresses', 'seoprostack'),
                'description' => __('Pages name each picture’s AVIF and WebP copy in a <picture> tag and the browser picks one, so every address always sends the same file and CDNs, Cloudflare’s free plan included, can cache every picture. Pictures in posts, pages, block widgets, featured images and logos; pictures set in CSS, such as backgrounds, stay in their original format. Check pages afterwards: theme styles written for a picture’s direct parent may need changing.', 'seoprostack'),
            ),
        );
    }

    /**
     * AVIF description; on the settings screen it says whether this server
     * can make AVIF.
     *
     * @return string
     */
    private static function avif_description() {
        $text = __('Usually smaller than WebP, but slower to make. Needs WordPress 6.5 or later and server support.', 'seoprostack');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen check only.
        if (is_admin() && isset($_GET['page']) && 'seoprostack' === $_GET['page']) {
            $text .= ' ' . (self::can_make('avif')
                ? __('This server can make them.', 'seoprostack')
                : __('This server cannot make them, so none are made.', 'seoprostack'));
        }
        return $text;
    }

    /**
     * Import CompressX's settings, and switch on while it is active. On
     * multisite CompressX keeps its settings on the main site.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (isset(self::active_plugins()['compressx'])) {
            $options = self::import_setting($options, self::KEY, true);
        }

        foreach (array('webp', 'avif') as $format) {
            $value = self::compressx_option('compressx_output_format_' . $format);
            if (null !== $value && '' !== $value && 'not init' !== $value) {
                $options = self::import_setting($options, 'nextgen_' . $format, (bool) $value);
            }
        }

        $quality = self::compressx_option('compressx_quality');
        if (is_array($quality) && !empty($quality['quality'])) {
            // CompressX's levels: WebP and AVIF quality.
            $levels = array(
                'lossless'    => array(99, 80),
                'lossy_minus' => array(90, 75),
                'lossy'       => array(80, 60),
                'lossy_plus'  => array(70, 50),
                'lossy_super' => array(60, 40),
            );
            $webp = 0;
            $avif = 0;
            if ('custom' === $quality['quality']) {
                $webp = isset($quality['quality_webp']) ? (int) $quality['quality_webp'] : 0;
                $avif = isset($quality['quality_avif']) ? (int) $quality['quality_avif'] : 0;
            } elseif (isset($levels[$quality['quality']])) {
                list($webp, $avif) = $levels[$quality['quality']];
            }
            if ($webp > 0) {
                $options = self::import_setting($options, 'nextgen_webp_quality', $webp);
            }
            if ($avif > 0) {
                $options = self::import_setting($options, 'nextgen_avif_quality', $avif);
            }
        }

        $general = self::compressx_option('compressx_general_settings');
        if (is_array($general)) {
            // CompressX excludes PNG per format; PNG is off only when both are.
            if (isset($general['exclude_png']) || isset($general['exclude_png_webp'])) {
                $options = self::import_setting($options, 'nextgen_png', empty($general['exclude_png']) || empty($general['exclude_png_webp']));
            }
            $options = self::import_setting($options, 'nextgen_private', empty($general['disable_cache_control']));
        }
        return $options;
    }

    /**
     * A CompressX setting.
     *
     * @param string $name Option name.
     * @return mixed Null when not set.
     */
    public static function compressx_option($name) {
        if (is_multisite() && !is_main_site()) {
            return get_blog_option(get_main_site_id(), $name, null);
        }
        return get_option($name, null);
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'), 10, 2);
        add_action('seoprostack_setting_panel', array(__CLASS__, 'panel_status'), 10, 2);
        // Also when switched off or waiting: copies left from earlier must
        // never outlive or outdate their pictures. Both only check files.
        add_filter('wp_delete_file', array(__CLASS__, 'delete_copies'));
        add_filter('wp_update_attachment_metadata', array(__CLASS__, 'metadata_updated'), 20, 2);
        if (is_admin()) {
            // Also when switched off, to take this site out of the rules
            // however the switch was turned off.
            add_action('admin_init', array(__CLASS__, 'maybe_sync_rules'));
            if (is_multisite()) {
                add_action('seoprostack_network_setting_panel', array(__CLASS__, 'network_panel'));
            }
        }

        if (!self::enabled()) {
            return;
        }

        add_action(self::HOOK, array(__CLASS__, 'run_batch'));

        if (!is_admin() && SEOProStack_Settings::get('nextgen_picture')) {
            // After core's wp_filter_content_tags (12), so lazy loading and sizes are set.
            add_filter('the_content', array(__CLASS__, 'pictures'), 30);
            add_filter('widget_block_content', array(__CLASS__, 'pictures'), 30);
            // Featured images, logos and other attachment images in templates.
            add_filter('wp_get_attachment_image', array(__CLASS__, 'pictures'), 30);
        }

        if (is_admin()) {
            add_filter('attachment_fields_to_edit', array(__CLASS__, 'attachment_field'), 20, 2);
            add_filter('bulk_actions-upload', array(__CLASS__, 'bulk_action'));
            add_filter('handle_bulk_actions-upload', array(__CLASS__, 'handle_bulk'), 10, 3);
            add_action('admin_notices', array(__CLASS__, 'notice'));
            add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
        }

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('seoprostack convert-images', array(__CLASS__, 'cli'));
        }
    }

    /* --------------------------------------------------------------------- */
    /* Formats                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Whether this server's image editor can save a format.
     *
     * @param string $format webp or avif.
     * @return bool
     */
    public static function can_make($format) {
        static $can = array();
        if (!isset($can[$format])) {
            $can[$format] = isset(self::FORMATS[$format]) && wp_image_editor_supports(array('mime_type' => self::FORMATS[$format]));
        }
        return $can[$format];
    }

    /**
     * Formats to make: chosen and possible.
     *
     * @return array<string,string> extension => MIME type
     */
    public static function formats() {
        $formats = array();
        foreach (self::FORMATS as $format => $mime) {
            if (SEOProStack_Settings::get('nextgen_' . $format) && self::can_make($format)) {
                $formats[$format] = $mime;
            }
        }
        return $formats;
    }

    /**
     * Source MIME types that get copies with the current settings.
     *
     * @return string[]
     */
    public static function source_types() {
        $formats = self::formats();
        if (!$formats) {
            return array();
        }
        $types = array('image/jpeg');
        if (SEOProStack_Settings::get('nextgen_png')) {
            $types[] = 'image/png';
        }
        if (isset($formats['avif'])) {
            $types[] = 'image/webp';
        }
        return $types;
    }

    /**
     * What an attachment is converted for: each format with its quality
     * settings. It changes when formats or quality change, so pictures are
     * looked at again; convert_attachment() makes again only the copies whose
     * quality changed.
     *
     * @return string
     */
    private static function signature() {
        $parts = array();
        foreach (array_keys(self::formats()) as $format) {
            $parts[] = $format . ':' . self::quality_key($format);
        }
        return implode(',', $parts);
    }

    /**
     * A format's quality settings, as kept with the copies: the quality, and
     * "s" when it is adjusted to picture size.
     *
     * @param string $format webp or avif.
     * @return string
     */
    private static function quality_key($format) {
        return (int) SEOProStack_Settings::get('nextgen_' . $format . '_quality') . (SEOProStack_Settings::get('nextgen_smart') ? 's' : '');
    }

    /**
     * Quality for one copy. With Quality by picture size on, small pictures
     * get more (flaws show in thumbnails) and large ones less (they are
     * usually shown smaller than they are), as CompressX's smart mode does.
     *
     * @param string $format webp or avif.
     * @param int    $width  Picture width.
     * @param int    $height Picture height.
     * @return int
     */
    public static function quality($format, $width, $height) {
        $quality = (int) SEOProStack_Settings::get('nextgen_' . $format . '_quality');
        $longest = max((int) $width, (int) $height);
        if (!SEOProStack_Settings::get('nextgen_smart') || $longest <= 0) {
            return $quality;
        }
        if ($longest < 200) {
            $quality += 15;
        } elseif ($longest >= 2000) {
            $quality -= 20;
        } elseif ($longest >= 800) {
            $quality -= 10;
        }
        return max(10, min(100, $quality));
    }

    /* --------------------------------------------------------------------- */
    /* Converting                                                             */
    /* --------------------------------------------------------------------- */

    /**
     * Make the copies of an attachment's file and every size of it.
     *
     * @param int  $attachment_id Attachment ID.
     * @param bool $force         Make them again even when up to date.
     * @return array|WP_Error Record: files => [name => [format => bytes]]
     *                        (0 = not smaller, -1 = failed), sources => [name => bytes].
     */
    public static function convert_attachment($attachment_id, $force = false) {
        $attachment_id = (int) $attachment_id;
        $signature     = self::signature();
        $file          = get_attached_file($attachment_id);
        $types         = self::source_types();
        if (!$file || !is_file($file) || !in_array(get_post_mime_type($attachment_id), $types, true)) {
            update_post_meta($attachment_id, self::DONE, $signature);
            return new WP_Error('nextgen_not_image', __('Not a picture that gets WebP or AVIF copies.', 'seoprostack'));
        }

        $previous = get_post_meta($attachment_id, self::META, true);
        $previous = is_array($previous) ? $previous : array();
        $dir      = dirname($file);
        $record   = array('files' => array(), 'sources' => array(), 'quality' => array());
        // Formats whose copies were made with other quality settings: made
        // again. Copies from before quality was kept (0.10.1 and earlier)
        // were made at WordPress's default quality, whatever the setting
        // said, so they are made again too.
        $redo = array();
        foreach (array_keys(self::formats()) as $format) {
            $record['quality'][$format] = self::quality_key($format);
            $redo[$format]              = !empty($previous['files']) && (isset($previous['quality'])
                ? isset($previous['quality'][$format]) && $previous['quality'][$format] !== $record['quality'][$format]
                : true);
        }
        foreach (self::file_names($file, wp_get_attachment_metadata($attachment_id)) as $name) {
            $path = $dir . '/' . $name;
            $type = wp_check_filetype($name);
            if (!is_file($path) || !in_array($type['type'], $types, true) || self::is_animated($path, $type['type'])) {
                continue;
            }
            $bytes = (int) filesize($path);
            $record['sources'][$name] = $bytes;
            // Unchanged file whose copy was not smaller last time: not again.
            $same = !$force && isset($previous['sources'][$name]) && $previous['sources'][$name] === $bytes;
            foreach (self::formats() as $format => $mime) {
                if ($mime === $type['type']) {
                    continue;
                }
                if ($same && !$redo[$format] && isset($previous['files'][$name][$format]) && 0 === $previous['files'][$name][$format] && !is_file($path . '.' . $format)) {
                    $record['files'][$name][$format] = 0;
                    continue;
                }
                $record['files'][$name][$format] = self::convert_file($path, $format, $force || $redo[$format]);
            }
        }
        update_post_meta($attachment_id, self::META, $record);
        update_post_meta($attachment_id, self::DONE, $signature);
        do_action('seoprostack_media_files_changed', $attachment_id);
        return $record;
    }

    /**
     * File names of an attachment: the file, its sizes and the original
     * WordPress kept when it scaled or rotated it.
     *
     * @param string     $file Attached file.
     * @param array|bool $meta Attachment metadata.
     * @return string[]
     */
    private static function file_names($file, $meta) {
        $names = array(wp_basename($file));
        if (is_array($meta)) {
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if (!empty($size['file'])) {
                        $names[] = wp_basename($size['file']);
                    }
                }
            }
            if (!empty($meta['original_image'])) {
                $names[] = wp_basename($meta['original_image']);
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Make one copy.
     *
     * @param string $path   Original file.
     * @param string $format webp or avif.
     * @param bool   $force  Make it again even when up to date.
     * @return int Bytes of the copy; 0 when not smaller; -1 when it failed.
     */
    private static function convert_file($path, $format, $force) {
        $target = $path . '.' . $format;
        clearstatcache();
        $source_bytes = (int) filesize($path);
        $source_time  = (int) filemtime($path);
        if (is_file($target)) {
            if (self::is_attachment_file($target)) {
                // A Media Library file of its own, not a copy.
                return -1;
            }
            if (!$force && filemtime($target) >= $source_time) {
                return (int) filesize($target);
            }
        } elseif (!$force) {
            $adopted = self::adopt_compressx_copy($path, $target, $source_bytes, $source_time);
            if ($adopted) {
                return $adopted;
            }
        }

        $mime   = self::FORMATS[$format];
        $editor = wp_get_image_editor($path, array('output_mime_type' => $mime));
        if (is_wp_error($editor)) {
            return -1;
        }
        // Copies have no orientation tag, so turn the picture upright.
        $editor->maybe_exif_rotate();
        if ($editor instanceof WP_Image_Editor_GD && 'image/png' === wp_check_filetype($path)['type']) {
            // GD cannot write palette (8-bit) PNGs as WebP or AVIF; a
            // full-size crop copies the picture to full colour, keeping
            // transparency.
            $size = $editor->get_size();
            $crop = $editor->crop(0, 0, $size['width'], $size['height']);
            if (is_wp_error($crop)) {
                return -1;
            }
        }
        $size    = $editor->get_size();
        $quality = self::quality($format, isset($size['width']) ? $size['width'] : 0, isset($size['height']) ? $size['height'] : 0);
        // save() resets the quality to WordPress's default for the new
        // format (86 for WebP, 82 for AVIF) through this filter when it
        // converts, so set_quality() alone has no effect.
        $set_quality = function ($value, $type) use ($quality, $mime) {
            return $type === $mime ? $quality : $value;
        };
        add_filter('wp_editor_set_quality', $set_quality, PHP_INT_MAX, 2);
        $editor->set_quality($quality);

        // Unique, so a bulk action and a background batch on the same
        // picture do not write over each other's file.
        $temp  = $path . '.sps-tmp' . wp_generate_password(6, false) . '.' . $format;
        $saved = $editor->save($temp, $mime);
        remove_filter('wp_editor_set_quality', $set_quality, PHP_INT_MAX);
        if (is_wp_error($saved) || empty($saved['path']) || !is_file($saved['path'])) {
            return -1;
        }
        clearstatcache();
        // Another plugin may change the output format.
        if ($saved['mime-type'] !== $mime || $saved['path'] !== $temp) {
            wp_delete_file($saved['path']);
            return -1;
        }
        $bytes = (int) filesize($temp);
        if ($bytes <= 0 || $bytes >= $source_bytes) {
            wp_delete_file($temp);
            if (is_file($target)) {
                wp_delete_file($target);
            }
            return 0;
        }
        if (!@rename($temp, $target)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- same directory.
            wp_delete_file($temp);
            return -1;
        }
        return $bytes;
    }

    /**
     * Move CompressX's copy of a file next to it, when it is up to date and
     * smaller. CompressX keeps copies in wp-content/compressx-nextgen, in the
     * same folders as below wp-content.
     *
     * @param string $path         Original file.
     * @param string $target       Where the copy goes.
     * @param int    $source_bytes Size of the original.
     * @param int    $source_time  Modification time of the original.
     * @return int Bytes of the copy; 0 when none was moved.
     */
    private static function adopt_compressx_copy($path, $target, $source_bytes, $source_time) {
        static $root = null;
        if (null === $root) {
            $content = untrailingslashit(wp_normalize_path(WP_CONTENT_DIR));
            $root    = is_dir($content . '/compressx-nextgen') ? $content : '';
        }
        $path = wp_normalize_path($path);
        if ('' === $root || 0 !== strpos($path, $root . '/')) {
            return 0;
        }
        $copy = $root . '/compressx-nextgen' . substr(wp_normalize_path($target), strlen($root));
        if (!is_file($copy)) {
            return 0;
        }
        $bytes = (int) filesize($copy);
        if ($bytes <= 0 || $bytes >= $source_bytes || filemtime($copy) < $source_time) {
            return 0;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- moving a file within wp-content.
        return @rename($copy, $target) ? $bytes : 0;
    }

    /**
     * Whether a picture is animated (animated WebP or PNG); a copy would
     * only keep the first frame.
     *
     * @param string $path File path.
     * @param string $mime MIME type.
     * @return bool
     */
    private static function is_animated($path, $mime) {
        if ('image/webp' !== $mime && 'image/png' !== $mime) {
            return false;
        }
        $head = (string) file_get_contents($path, false, null, 0, 'image/png' === $mime ? 4096 : 32); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        if ('image/webp' === $mime) {
            // Extended WebP with the animation flag.
            return strlen($head) > 20 && 'VP8X' === substr($head, 12, 4) && (ord($head[20]) & 0x02);
        }
        // APNG: an animation control chunk before the image data.
        $control = strpos($head, 'acTL');
        $data    = strpos($head, 'IDAT');
        return false !== $control && (false === $data || $control < $data);
    }

    /**
     * Whether a path is a Media Library item's main file.
     *
     * @param string $path File path.
     * @return bool
     */
    private static function is_attachment_file($path) {
        global $wpdb;
        $relative = _wp_relative_upload_path($path);
        if ($relative === $path) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact lookup, only when such a file exists.
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $relative));
    }

    /**
     * When an original is deleted, delete its copies too.
     *
     * @param string $file File being deleted.
     * @return string
     */
    public static function delete_copies($file) {
        if (is_string($file) && preg_match('/\.(jpe?g|png|webp)$/i', $file)) {
            foreach (array_keys(self::FORMATS) as $format) {
                $copy = $file . '.' . $format;
                if (is_file($copy) && !self::is_attachment_file($copy)) {
                    wp_delete_file($copy);
                }
            }
        }
        return $file;
    }

    /**
     * A picture was uploaded, edited, replaced or its sizes remade: delete
     * copies now older than their files (so the old picture is never sent)
     * and convert it again in the background.
     *
     * @param array $data          Metadata.
     * @param int   $attachment_id Attachment ID.
     * @return array
     */
    public static function metadata_updated($data, $attachment_id) {
        if (!is_array($data) || !in_array(get_post_mime_type($attachment_id), array('image/jpeg', 'image/png', 'image/webp'), true)) {
            return $data;
        }
        $file = get_attached_file($attachment_id);
        if (!$file) {
            return $data;
        }
        clearstatcache();
        foreach (self::file_names($file, $data) as $name) {
            $path = dirname($file) . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            foreach (array_keys(self::FORMATS) as $format) {
                $copy = $path . '.' . $format;
                if (is_file($copy) && filemtime($copy) < filemtime($path) && !self::is_attachment_file($copy)) {
                    wp_delete_file($copy);
                }
            }
        }
        if ('' !== (string) get_post_meta($attachment_id, self::DONE, true)) {
            delete_post_meta($attachment_id, self::DONE);
        }
        if (self::enabled()) {
            self::schedule(5);
        }
        return $data;
    }

    /* --------------------------------------------------------------------- */
    /* Background batches                                                     */
    /* --------------------------------------------------------------------- */

    /**
     * Schedule a batch unless one is due.
     *
     * @param int $delay Seconds from now.
     */
    public static function schedule($delay = 0) {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + (int) $delay, self::HOOK);
        }
    }

    /**
     * Values for the three "p.post_mime_type IN (%s, %s, %s)" placeholders:
     * source_types() has one to three types, so the first is repeated. The
     * queries stay fixed strings, with every value passed to prepare().
     *
     * @param string[] $types MIME types (one to three).
     * @return string[]
     */
    private static function type_args(array $types) {
        return array_slice(array_pad(array_values($types), 3, (string) reset($types)), 0, 3);
    }

    /**
     * Attachments waiting for copies, newest first.
     *
     * @param int $limit Most to return.
     * @return int[]
     */
    public static function waiting($limit) {
        global $wpdb;
        $types = self::source_types();
        if (!$types) {
            return array();
        }
        $types = self::type_args($types);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- queue lookup.
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = 'attachment' AND p.post_mime_type IN (%s, %s, %s) AND m.meta_id IS NULL ORDER BY p.ID DESC LIMIT %d",
            self::DONE,
            self::signature(),
            $types[0],
            $types[1],
            $types[2],
            (int) $limit
        )));
    }

    /**
     * Counts for the settings card.
     *
     * @return array{done:int,total:int}
     */
    private static function counts() {
        global $wpdb;
        $types = self::source_types();
        if (!$types) {
            return array('done' => 0, 'total' => 0);
        }
        $types = self::type_args($types);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- settings screen only.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.post_mime_type IN (%s, %s, %s)",
            $types[0],
            $types[1],
            $types[2]
        ));
        $done  = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = 'attachment' AND p.post_mime_type IN (%s, %s, %s)",
            self::DONE,
            self::signature(),
            $types[0],
            $types[1],
            $types[2]
        ));
        // phpcs:enable
        return array('done' => min($done, $total), 'total' => $total);
    }

    /**
     * Convert waiting pictures for up to BUDGET seconds, then schedule the
     * next batch if any are left.
     */
    public static function run_batch() {
        if (!self::enabled()) {
            return;
        }
        // Next batch first, so a picture that stops PHP (a broken file, too
        // little memory) does not stop the queue.
        self::schedule(60);
        // One batch at a time; one that stops PHP frees it after a while.
        if (get_transient(self::LOCK)) {
            return;
        }
        set_transient(self::LOCK, 1, self::BUDGET + 60);
        $start = microtime(true);
        do {
            $ids = self::waiting(10);
            foreach ($ids as $id) {
                if (!self::more_time($start, self::BUDGET)) {
                    break 2;
                }
                // Marked as tried first: if PHP stops on it, it is not tried
                // again at the head of every batch.
                $record = get_post_meta($id, self::META, true);
                $record = is_array($record) ? $record : array();
                $record['incomplete'] = true;
                update_post_meta($id, self::META, $record);
                update_post_meta($id, self::DONE, self::signature());
                self::convert_attachment($id);
            }
        } while ($ids);
        delete_transient(self::LOCK);
        if (!self::waiting(1)) {
            wp_clear_scheduled_hook(self::HOOK);
            self::remove_compressx_block();
        }
    }

    /**
     * Settings changed: update the rules and convert what the new settings
     * need. Pictures whose copies are up to date are passed over quickly.
     *
     * @param string $key   Setting key.
     * @param mixed  $value New value.
     */
    public static function setting_saved($key, $value) {
        if (self::KEY !== $key && 0 !== strpos($key, 'nextgen_')) {
            return;
        }
        self::sync_rules();
        if (self::enabled()) {
            self::schedule();
        } else {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Serving                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Whether this site names its copies in <picture> tags instead of
     * sending them at the originals' addresses.
     *
     * @return bool
     */
    private static function picture_mode() {
        return (bool) SEOProStack_Settings::get('nextgen_picture');
    }

    /**
     * Wrap each picture of the uploads folder that has an AVIF or WebP copy
     * in a <picture> tag naming the copies. Pictures already in a <picture>
     * tag, and in scripts, text areas and noscript, are left alone, so
     * running twice changes nothing. Front-end pages only.
     *
     * @param mixed $html HTML.
     * @return mixed
     */
    public static function pictures($html) {
        if (!is_string($html) || false === stripos($html, '<img') || !self::picture_request()) {
            return $html;
        }
        $parts = preg_split('#(<(picture|script|textarea|noscript)\b.*?</\2\s*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $html;
        }
        $out = '';
        // Parts: text, then each skipped block followed by its tag name.
        for ($i = 0, $n = count($parts); $i < $n; $i += 3) {
            $out .= (string) preg_replace_callback('/<img\b[^>]*>/i', function ($m) {
                return self::picture($m[0]);
            }, $parts[$i]);
            if (isset($parts[$i + 1])) {
                $out .= $parts[$i + 1];
            }
        }
        return $out;
    }

    /**
     * Whether this request gets <picture> tags: front-end pages, not feeds,
     * REST responses (the editors) or the admin.
     *
     * @return bool
     */
    private static function picture_request() {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }
        return !(isset($GLOBALS['wp_query']) && is_feed());
    }

    /**
     * One <img> tag in a <picture> tag with a <source> for each format that
     * has a copy of one of its addresses. In a source, an address without a
     * copy keeps the original, so every width stays on offer.
     *
     * @param string $img <img> tag.
     * @return string
     */
    private static function picture($img) {
        $src = self::attribute($img, 'src');
        if ('' === $src) {
            return $img;
        }
        $srcset     = self::attribute($img, 'srcset');
        $candidates = array();
        foreach ('' !== $srcset ? explode(',', $srcset) : array($src) as $candidate) {
            // An address, then an optional width or density descriptor.
            if (preg_match('/^\s*(\S+)(?:\s+(\S.*?))?\s*$/s', $candidate, $m)) {
                $candidates[] = array($m[1], isset($m[2]) ? ' ' . $m[2] : '');
            }
        }
        $sizes   = self::attribute($img, 'sizes');
        $sources = '';
        foreach (self::FORMATS as $format => $mime) {
            if (SEOProStack_Settings::get('nextgen_' . $format)) {
                $sources .= self::source($candidates, $format, $mime, $sizes);
            }
        }
        if ('' === $sources) {
            return $img;
        }
        // display: contents lays the picture out as the <img> alone.
        return '<picture class="sps-picture" style="display:contents">' . $sources . $img . '</picture>';
    }

    /**
     * A <source> for one format: each address with its copy where there is
     * one, else the original. '' when no address has a copy.
     *
     * @param array[] $candidates Address and descriptor pairs.
     * @param string  $format     avif or webp.
     * @param string  $mime       Its MIME type.
     * @param string  $sizes      The <img> tag's sizes, or ''.
     * @return string
     */
    private static function source(array $candidates, $format, $mime, $sizes) {
        $list   = array();
        $copies = 0;
        foreach ($candidates as $candidate) {
            list($url, $descriptor) = $candidate;
            $path = self::upload_path($url);
            if ('' !== $path && is_file($path . '.' . $format)) {
                $url .= '.' . $format;
                ++$copies;
            }
            $list[] = $url . $descriptor;
        }
        if (!$copies) {
            return '';
        }
        $sizes = '' !== $sizes ? ' sizes="' . esc_attr($sizes) . '"' : '';
        return '<source type="' . esc_attr($mime) . '" srcset="' . esc_attr(implode(', ', $list)) . '"' . $sizes . '>';
    }

    /**
     * An attribute's value in a tag, decoded; '' when missing.
     *
     * @param string $tag  HTML tag.
     * @param string $name Attribute name.
     * @return string
     */
    private static function attribute($tag, $name) {
        if (!preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m)) {
            return '';
        }
        // Double-quoted, single-quoted or bare: the last group that matched.
        $value = (string) end($m);
        return trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * The file behind an address in this site's uploads folder that can
     * have copies; '' for other addresses (other sites, cloud storage,
     * queries, other file types).
     *
     * @param string $url Address.
     * @return string
     */
    private static function upload_path($url) {
        static $base = null;
        if (null === $base) {
            $uploads = wp_get_upload_dir();
            $base    = empty($uploads['error']) && !empty($uploads['baseurl']) ? array(
                'url'  => (string) preg_replace('#^https?:#i', '', untrailingslashit($uploads['baseurl'])),
                'path' => (string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH),
                'dir'  => untrailingslashit($uploads['basedir']),
            ) : array();
        }
        if (!$base || !preg_match('/\.(?:jpe?g|png|webp)$/i', $url) || false !== strpbrk($url, '?#\\')) {
            return '';
        }
        $url = (string) preg_replace('#^https?:#i', '', $url);
        if (0 === strpos($url, $base['url'] . '/')) {
            $relative = substr($url, strlen($base['url']));
        } elseif ('' !== $base['path'] && '/' === $url[0] && '/' !== substr($url, 1, 1) && 0 === strpos($url, untrailingslashit($base['path']) . '/')) {
            $relative = substr($url, strlen(untrailingslashit($base['path'])));
        } else {
            return '';
        }
        $relative = rawurldecode($relative);
        if (false !== strpos($relative, '..')) {
            return '';
        }
        return $base['dir'] . $relative;
    }

    /**
     * Uploads folder of the main site (every site's uploads are below it).
     *
     * @return array{dir:string,url:string}|null
     */
    public static function main_uploads() {
        $switched = false;
        if (is_multisite() && !is_main_site()) {
            switch_to_blog(get_main_site_id());
            $switched = true;
        }
        $uploads = wp_upload_dir(null, false);
        if ($switched) {
            restore_current_blog();
        }
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return null;
        }
        return array(
            'dir' => untrailingslashit(wp_normalize_path($uploads['basedir'])),
            'url' => untrailingslashit((string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH)),
        );
    }

    /**
     * Whether this site's uploads are below the folder the rules cover.
     *
     * @return bool
     */
    private static function covered() {
        $main    = self::main_uploads();
        $uploads = wp_upload_dir(null, false);
        if (!$main || empty($uploads['basedir'])) {
            return false;
        }
        $dir = untrailingslashit(wp_normalize_path($uploads['basedir']));
        return $dir === $main['dir'] || 0 === strpos($dir, $main['dir'] . '/');
    }

    /**
     * This site's part of the rules.
     *
     * @return array{webp:bool,avif:bool,private:bool,picture:bool}
     */
    private static function site_rules() {
        $picture = self::picture_mode();
        return array(
            'webp'    => (bool) SEOProStack_Settings::get('nextgen_webp'),
            'avif'    => (bool) SEOProStack_Settings::get('nextgen_avif'),
            'private' => !$picture && (bool) SEOProStack_Settings::get('nextgen_private'),
            'picture' => $picture,
        );
    }

    /**
     * Rules for the uploads folder's .htaccess (Apache and LiteSpeed).
     *
     * @param array $flags webp, avif, private, picture (every site names its copies).
     * @return string[] Empty when no format is served.
     */
    public static function htaccess_rules(array $flags) {
        if (empty($flags['webp']) && empty($flags['avif'])) {
            return array();
        }
        if (!empty($flags['picture'])) {
            // Each address sends one file, so caches need no Vary and may keep it.
            return array(
                '# Pages name the AVIF and WebP copies in <picture> tags; each address sends one format.',
                '<IfModule mod_mime.c>',
                'AddType image/avif .avif',
                'AddType image/webp .webp',
                '</IfModule>',
            );
        }
        $lines = array(
            '# Browsers that accept AVIF or WebP get the smaller copy at the same address.',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
        );
        if (!empty($flags['avif'])) {
            $lines[] = 'RewriteCond %{HTTP_ACCEPT} image/avif';
            $lines[] = 'RewriteCond %{REQUEST_FILENAME}.avif -f';
            $lines[] = 'RewriteRule ^(.+)\.(jpe?g|png|webp)$ $1.$2.avif [NC,T=image/avif,L]';
        }
        if (!empty($flags['webp'])) {
            $lines[] = 'RewriteCond %{HTTP_ACCEPT} image/webp';
            $lines[] = 'RewriteCond %{REQUEST_FILENAME}.webp -f';
            $lines[] = 'RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [NC,T=image/webp,L]';
        }
        $lines[] = '</IfModule>';
        $lines[] = '<IfModule mod_mime.c>';
        $lines[] = 'AddType image/avif .avif';
        $lines[] = 'AddType image/webp .webp';
        $lines[] = '</IfModule>';
        // Caches must keep one version per Accept header.
        $lines[] = '<IfModule mod_headers.c>';
        $lines[] = '<FilesMatch "(?i)\.(jpe?g|png|webp)(\.(webp|avif))?$">';
        $lines[] = 'Header merge Vary Accept';
        if (!empty($flags['private'])) {
            $lines[] = 'Header always set Cache-Control "private"';
        }
        $lines[] = '</FilesMatch>';
        $lines[] = '</IfModule>';
        return $lines;
    }

    /**
     * Nginx configuration lines.
     *
     * @return string
     */
    public static function nginx_rules() {
        $uploads = self::main_uploads();
        $path    = $uploads ? $uploads['url'] : '/wp-content/uploads';
        if (self::picture_mode()) {
            // Copies take this block instead of other image blocks, so lines such as expires go here too.
            return '# In the server { } block, before other image locations (copy their expires lines here):' . "\n"
                . 'location ~* ^' . preg_quote($path, '#') . '/.+\.(jpe?g|png|webp)\.(avif|webp)$ { types { image/avif avif; image/webp webp; } }';
        }
        return implode("\n", array(
            '# In the http { } block:',
            'map $http_accept $sps_avif { default ".none"; "~*image/avif" ".avif"; }',
            'map $http_accept $sps_webp { default ".none"; "~*image/webp" ".webp"; }',
            '',
            '# In the server { } block, before other image locations:',
            'location ~* ^' . preg_quote($path, '#') . '/.+\.(jpe?g|png|webp)$ {',
            '    # Pictures use this block instead of other image blocks, so copy',
            '    # their lines here too (such as expires).',
            '    add_header Vary Accept;',
            '    types { image/avif avif; image/webp webp; image/jpeg jpg jpeg; image/png png; }',
            '    try_files $uri$sps_avif $uri$sps_webp $uri =404;',
            '}',
        ));
    }

    /**
     * Whether the server is Nginx (which does not read .htaccess files).
     *
     * @return bool
     */
    private static function is_nginx() {
        global $is_nginx;
        return !empty($is_nginx);
    }

    /**
     * Whether the server is LiteSpeed (Enterprise, OpenLiteSpeed or Web
     * ADC), which keeps .htaccess rules in memory.
     *
     * @return bool
     */
    private static function is_litespeed() {
        return SEOProStack_Litespeed::is_server();
    }

    /**
     * Ask this site's own server for a converted picture, as a browser that
     * accepts AVIF and WebP, and see whether it sends the copy. LiteSpeed can
     * keep old rules in memory, and some servers ignore .htaccess files, so
     * written rules are not always working rules. Settings screen only; the
     * answer is kept for a week (a few minutes when it got the original) and
     * until the rules change.
     *
     * @param array $sites Sites using the rules (site ID => flags).
     * @return string copy, original or unknown (nothing to test, or no answer).
     */
    private static function check_rules(array $sites) {
        $rules  = md5((string) wp_json_encode(self::rules_for($sites))); // NOSONAR: a fingerprint to notice changes, not security.
        $cached = get_transient(self::CHECK);
        if (is_array($cached) && isset($cached['rules'], $cached['result']) && $cached['rules'] === $rules) {
            return $cached['result'];
        }
        $sample = self::sample_copy();
        if (!$sample) {
            return 'unknown';
        }
        $picture = self::all_pictures($sites);
        if ($picture) {
            // The copy at its own address, which must come with its type.
            $sample['url']  .= '.' . $sample['format'];
            $sample['type']  = self::FORMATS[$sample['format']];
        }
        $response = wp_remote_head(add_query_arg('sps-check', time(), $sample['url']), array(
            // One static picture from this site's own server; the settings screen waits on it.
            'timeout'     => 3,
            'redirection' => 2,
            'headers'     => array('Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8'),
            // As core's own loopback requests (WP_Site_Health).
            'sslverify'   => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
        ));
        $result = self::check_result($response, $sample['type'], $picture);
        set_transient(self::CHECK, array('rules' => $rules, 'result' => $result), 'copy' === $result ? WEEK_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
        return $result;
    }

    /**
     * What a rules check's answer was.
     *
     * @param array|WP_Error $response Response.
     * @param string         $type     MIME type asked for: the original's, or the copy's at its own address.
     * @param bool           $picture  Whether the copy was asked for at its own address.
     * @return string copy, original or unknown.
     */
    private static function check_result($response, $type, $picture) {
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return 'unknown';
        }
        $got = wp_remote_retrieve_header($response, 'content-type');
        $got = strtolower(is_array($got) ? (string) end($got) : $got);
        if ($picture) {
            return false !== strpos($got, $type) ? 'copy' : 'original';
        }
        // A WebP original only has an AVIF copy.
        return (false !== strpos($got, 'image/avif') || false !== strpos($got, 'image/webp')) && false === strpos($got, $type) ? 'copy' : 'original';
    }

    /**
     * Address of a recent picture whose main file has a copy.
     *
     * @return array{url:string,type:string,format:string}|null
     */
    private static function sample_copy() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 20 rows, settings screen only, at most every few minutes.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id DESC LIMIT 20", self::META));
        foreach (array_map('intval', $ids) as $id) {
            $record = get_post_meta($id, self::META, true);
            $file   = get_attached_file($id);
            $url    = class_exists('SEOProStack_Media_Storage') ? SEOProStack_Media_Storage::local_url($id) : wp_get_attachment_url($id);
            if (!$file || !$url || !is_array($record)) {
                continue;
            }
            $name = wp_basename($file);
            if (empty($record['files'][$name])) {
                continue;
            }
            foreach ($record['files'][$name] as $format => $bytes) {
                if ($bytes > 0 && isset(self::FORMATS[$format]) && is_file($file . '.' . $format)) {
                    return array('url' => $url, 'type' => (string) get_post_mime_type($id), 'format' => $format);
                }
            }
        }
        return null;
    }

    /**
     * Sync the rules when this site's part changed since the last sync.
     * Reads one autoloaded option otherwise, plus the network's list on
     * multisite.
     */
    public static function maybe_sync_rules() {
        if (!self::enabled()) {
            // Switched off, or waiting for CompressX: leave the rules only
            // if this site is still marked as using them.
            if (false !== get_option(self::SYNCED)) {
                self::sync_rules();
            }
            return;
        }
        $synced = get_option(self::SYNCED) === md5((string) wp_json_encode(self::site_rules())); // NOSONAR: a fingerprint to notice changed settings, not security.
        if ($synced && is_multisite()) {
            // The network's list is cleared when the plugin is deactivated
            // network-wide; this site must join it again.
            $state  = get_site_option(self::RULES);
            $synced = is_array($state) && isset($state['sites'][get_current_blog_id()]);
        }
        if (!$synced) {
            self::sync_rules();
        }
    }

    /**
     * Keep the .htaccess rules in step with the sites using them. The block
     * is shared by every site on a network: it serves the formats any site
     * uses, and is removed when no site uses the feature.
     */
    public static function sync_rules() {
        $state = (array) get_site_option(self::RULES, array());
        $old   = isset($state['sites']) && is_array($state['sites']) ? $state['sites'] : array();
        $sites = $old;
        $blog  = get_current_blog_id();
        $new   = !isset($sites[$blog]);

        if (self::enabled()) {
            $sites[$blog] = self::site_rules();
        } else {
            unset($sites[$blog]);
        }
        if (!$sites && !$old) {
            delete_option(self::SYNCED);
            return;
        }

        $status = self::write_rules(self::rules_for($sites));
        if ($sites) {
            update_site_option(self::RULES, array('sites' => $sites, 'status' => $status));
        } else {
            delete_site_option(self::RULES);
        }

        if (isset($sites[$blog])) {
            // Retry on the next admin page while the file cannot be written.
            if ('unwritable' === $status) {
                delete_option(self::SYNCED);
            } else {
                update_option(self::SYNCED, md5((string) wp_json_encode($sites[$blog])), true); // NOSONAR: a fingerprint to notice changed settings, not security.
            }
            if ($new) {
                self::schedule();
            }
        } else {
            delete_option(self::SYNCED);
        }
    }

    /**
     * Rules for every site using the feature.
     *
     * @param array $sites Site ID => flags.
     * @return string[]
     */
    private static function rules_for(array $sites) {
        $flags = array('webp' => false, 'avif' => false, 'private' => false);
        foreach ($sites as $site) {
            foreach ($flags as $flag => $on) {
                $flags[$flag] = $on || !empty($site[$flag]);
            }
        }
        $flags['picture'] = self::all_pictures($sites);
        return self::htaccess_rules($flags);
    }

    /**
     * Whether every site using the shared rules names its copies in
     * <picture> tags. The Accept rules stay while any site sends copies at
     * the originals' addresses.
     *
     * @param array $sites Site ID => flags.
     * @return bool
     */
    private static function all_pictures(array $sites) {
        foreach ($sites as $site) {
            if (empty($site['picture'])) {
                return false;
            }
        }
        return (bool) $sites;
    }

    /**
     * .htaccess file in the main uploads folder.
     *
     * @return string
     */
    private static function htaccess_path() {
        $uploads = self::main_uploads();
        return $uploads ? $uploads['dir'] . '/.htaccess' : '';
    }

    /**
     * Write or remove the .htaccess block.
     *
     * @param string[] $lines Rules; empty removes the block.
     * @return string written, removed, unwritable or nginx
     */
    public static function write_rules(array $lines) {
        $file = self::htaccess_path();
        if (!$file) {
            return 'unwritable';
        }
        if (!$lines) {
            return self::remove_block($file) ? 'removed' : 'unwritable';
        }
        if (self::is_nginx()) {
            // Nginx ignores .htaccess; leave the uploads folder alone.
            self::remove_block($file);
            return 'nginx';
        }
        if (!(is_file($file) ? wp_is_writable($file) : wp_is_writable(dirname($file)))) {
            return 'unwritable';
        }
        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        if (!insert_with_markers($file, self::MARKER, $lines)) {
            return 'unwritable';
        }
        self::remove_compressx_block();
        return 'written';
    }

    /**
     * Remove the block, markers included.
     *
     * @param string $file   .htaccess path.
     * @param string $marker Block marker.
     * @return bool Whether the file has no block now.
     */
    public static function remove_block($file, $marker = self::MARKER) {
        if (is_link($file)) {
            return false;
        }
        if (!is_file($file)) {
            return true;
        }
        if (!wp_is_writable($file)) {
            return false;
        }
        // Coordinate the read and write with core's insert_with_markers().
        $handle = fopen($file, 'r+'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- locked local rules update, as core does.
        if (false === $handle) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $contents = stream_get_contents($handle);
            if (false === $contents) {
                return false;
            }
            if (false === strpos($contents, '# BEGIN ' . $marker)) {
                return true;
            }
            $quoted  = preg_quote($marker, '/');
            $cleaned = preg_replace('/^# BEGIN ' . $quoted . '\r?\n.*?^# END ' . $quoted . '\r?$(\n|$)/ms', '', $contents);
            if (null === $cleaned || false !== strpos($cleaned, '# BEGIN ' . $marker) || false !== strpos($cleaned, '# END ' . $marker)) {
                return false;
            }
            $cleaned = ltrim($cleaned, "\r\n");
            // Keep the file even when empty: unlinking a locked file would
            // let another writer lock a different inode at the same path.
            if (!rewind($handle) || !ftruncate($handle, 0)) {
                return false;
            }
            return strlen($cleaned) === fwrite($handle, $cleaned) && fflush($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- locked local rules update.
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- close our local rules handle.
        }
    }

    /**
     * Whether every site has finished taking over the shared CompressX folder.
     * Read activation lists afresh: active_plugins() caches the current site.
     *
     * @return bool
     */
    private static function compressx_cleanup_ready() {
        $offset = 0;
        do {
            $ids = is_multisite() ? get_sites(array('fields' => 'ids', 'number' => 50, 'offset' => $offset)) : array(get_current_blog_id());
            foreach ($ids as $id) {
                if (is_multisite()) {
                    switch_to_blog((int) $id);
                }
                try {
                    $plugins = array_merge(self::stored_active_plugins(), array_keys((array) get_site_option('active_sitewide_plugins', array())));
                    foreach ($plugins as $plugin) {
                        if ('compressx' === dirname($plugin)) {
                            return false;
                        }
                    }
                    if (!self::switched_on() || !self::formats() || get_transient(self::LOCK) || self::waiting(1) || self::conversion_failed()) {
                        return false;
                    }
                } finally {
                    if (is_multisite()) {
                        restore_current_blog();
                    }
                }
            }
            $offset += 50;
        } while (is_multisite() && count($ids) === 50);
        return true;
    }

    /**
     * A tried attachment can be marked done even when an individual copy failed.
     * Page the indexed metadata lookup; never delete potentially usable legacy
     * copies merely because those failed attempts left the queue empty.
     *
     * @return bool
     */
    private static function conversion_failed() {
        global $wpdb;
        $after = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed, paged cleanup preflight only.
            $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id LIMIT 50", self::DONE, $after));
            foreach ($ids as $id) {
                $record = get_post_meta((int) $id, self::META, true);
                if (!is_array($record) || !empty($record['incomplete'])) {
                    return true;
                }
                foreach (isset($record['files']) && is_array($record['files']) ? $record['files'] : array() as $formats) {
                    if (is_array($formats) && in_array(-1, $formats, true)) {
                        return true;
                    }
                }
                $after = (int) $id;
            }
        } while (count($ids) === 50);
        return false;
    }

    /**
     * Retire CompressX's rules only once conversion has finished everywhere.
     *
     * @return bool
     */
    private static function remove_compressx_block() {
        $file = self::htaccess_path();
        if (!$file || is_link($file) || (is_file($file) && !is_readable($file))) {
            return false;
        }
        if (!is_file($file)) {
            return true;
        }
        $contents = (string) file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local rules preflight.
        if (false === strpos($contents, '# BEGIN CompressX')) {
            return true;
        }
        if (!self::compressx_cleanup_ready()) {
            return false;
        }
        delete_transient(self::CHECK);
        return self::remove_block($file, 'CompressX');
    }

    /**
     * Inspect leftover files without following symbolic links. No visitor work.
     *
     * @return array{files:int,bytes:int}|WP_Error
     */
    private static function compressx_leftovers() {
        $path = WP_CONTENT_DIR . '/compressx-nextgen';
        $size = array('files' => 0, 'bytes' => 0);
        if (!file_exists($path) && !is_link($path)) {
            return $size;
        }
        if (is_link($path) || !is_dir($path)) {
            return new WP_Error('nextgen_compressx_path', __('The CompressX folder is not a regular folder. No files were removed.', 'seoprostack'));
        }
        try {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isLink() || !$file->isFile()) {
                    return new WP_Error('nextgen_compressx_link', __('The CompressX folder contains a link or a special file. No files were removed.', 'seoprostack'));
                }
                $size['files']++;
                $size['bytes'] += $file->getSize();
            }
        } catch (UnexpectedValueException $error) {
            return new WP_Error('nextgen_compressx_read', __('The CompressX folder could not be read. No files were removed.', 'seoprostack'));
        }
        return $size;
    }

    /**
     * Explicit CLI cleanup: keep adopted copies beside the original pictures.
     *
     * @return true|WP_Error
     */
    private static function cleanup_compressx() {
        if (!self::compressx_cleanup_ready()) {
            return new WP_Error('nextgen_compressx_busy', __('Deactivate CompressX and finish conversion on every site first. No files were removed.', 'seoprostack'));
        }
        $size = self::compressx_leftovers();
        if (is_wp_error($size)) {
            return $size;
        }
        $file = self::htaccess_path();
        if (!$file || is_link($file)) {
            return new WP_Error('nextgen_compressx_rules_path', __('The uploads rules file is not a regular file. No files were removed.', 'seoprostack'));
        }
        // Install our rules before removing the old ones, including on sites
        // whose conversion completed before this cleanup was available.
        self::sync_rules();
        $state = (array) get_site_option(self::RULES, array());
        if (!isset($state['status']) || !in_array($state['status'], array('written', 'nginx'), true)) {
            return new WP_Error('nextgen_compressx_serving', __('Replacement image rules could not be written. No files were removed.', 'seoprostack'));
        }
        if (!self::remove_compressx_block()) {
            return new WP_Error('nextgen_compressx_rules', __('The CompressX rules could not be removed. No files were removed.', 'seoprostack'));
        }
        $path = WP_CONTENT_DIR . '/compressx-nextgen';
        if (!is_dir($path)) {
            return true;
        }
        // WP_Filesystem_Direct needs no credentials. The preflight above
        // refuses symlinks, so recursive deletion cannot follow them outside.
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $filesystem = new WP_Filesystem_Direct(null);
        if (!$filesystem->delete($path, true)) {
            return new WP_Error('nextgen_compressx_delete', __('Some CompressX files could not be removed. Check folder permissions and run cleanup again.', 'seoprostack'));
        }
        return true;
    }

    /**
     * Plugin deactivated: remove the rules (for this site, or for every
     * site when deactivated network-wide) and stop background batches.
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        wp_clear_scheduled_hook(self::HOOK);
        delete_option(self::SYNCED);
        delete_transient(self::CHECK);
        $state = (array) get_site_option(self::RULES, array());
        $sites = isset($state['sites']) && is_array($state['sites']) ? $state['sites'] : array();
        unset($sites[get_current_blog_id()]);
        if ($network_wide || !is_multisite() || !$sites) {
            self::write_rules(array());
            delete_site_option(self::RULES);
            return;
        }
        update_site_option(self::RULES, array('sites' => $sites, 'status' => self::write_rules(self::rules_for($sites))));
    }

    /* --------------------------------------------------------------------- */
    /* Admin                                                                  */
    /* --------------------------------------------------------------------- */

    /**
     * On the network screen (multisite): one set of rules serves every site.
     *
     * @param string $key Setting key.
     */
    public static function network_panel($key) {
        if (self::KEY !== $key) {
            return;
        }
        echo '<div class="sps-panel-note"><p>' . esc_html__('Every site’s pictures are below the main site’s uploads folder, so one set of rules there serves the whole network, for the formats any site uses. Each site converts its own pictures.', 'seoprostack') . '</p></div>';
    }

    /**
     * Progress and how copies are sent, in the settings panel.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel_status($key, $field = array()) {
        if (self::KEY !== $key || !self::enabled()) {
            return;
        }
        echo '<div class="sps-panel-note">';
        if (!self::formats()) {
            echo '<p>' . esc_html__('No copies are made: turn on a format this server can make.', 'seoprostack') . '</p></div>';
            return;
        }

        $counts = self::counts();
        $text   = sprintf(
            /* translators: 1: pictures with copies, 2: all pictures */
            _n('%1$s of %2$s picture converted.', '%1$s of %2$s pictures converted.', $counts['total'], 'seoprostack'),
            number_format_i18n($counts['done']),
            number_format_i18n($counts['total'])
        );
        if ($counts['done'] < $counts['total']) {
            $text .= ' ' . __('The rest are converted in the background.', 'seoprostack');
            self::schedule();
        }
        echo '<p>' . esc_html($text) . '</p>';

        $leftovers = self::compressx_leftovers();
        if (is_wp_error($leftovers)) {
            echo '<p>' . esc_html($leftovers->get_error_message()) . '</p>';
        } elseif ($leftovers['files']) {
            /* translators: 1: number of leftover files, 2: their total size. */
            echo '<p>' . esc_html(sprintf(__('CompressX leftovers: %1$s files (%2$s). After conversion finishes on every site and CompressX is deactivated, remove them with WP-CLI: wp seoprostack convert-images --cleanup-compressx. Adopted copies are kept.', 'seoprostack'), number_format_i18n($leftovers['files']), size_format($leftovers['bytes'], 1))) . '</p>';
        }

        $state  = (array) get_site_option(self::RULES, array());
        $status = isset($state['status']) ? $state['status'] : '';
        $sites  = isset($state['sites']) && is_array($state['sites']) ? $state['sites'] : array();
        if (self::picture_mode()) {
            self::picture_panel($status, $sites);
            echo '</div>';
            return;
        }
        if (!self::covered()) {
            echo '<p>' . esc_html__('This site’s uploads folder is outside the main uploads folder, so browsers are sent the originals.', 'seoprostack') . '</p>';
        } elseif (self::is_nginx() || 'nginx' === $status) {
            echo '<p>' . esc_html__('Nginx does not read .htaccess files. So browsers get the copies, add these lines to the site’s Nginx configuration and reload Nginx:', 'seoprostack') . '</p>';
            echo '<pre class="sps-code">' . esc_html(self::nginx_rules()) . '</pre>';
        } elseif ('written' === $status) {
            $check = self::check_rules(isset($state['sites']) && is_array($state['sites']) ? $state['sites'] : array());
            if ('original' === $check) {
                echo '<p>' . esc_html__('The rules are in the uploads folder’s .htaccess file, but a test request for a converted picture got the original, so browsers are sent the originals.', 'seoprostack') . ' ' . esc_html(self::retry_advice('mod_rewrite and mod_headers')) . '</p>';
            } elseif ('copy' === $check) {
                echo '<p>' . esc_html__('Browsers get the copies through rules in the uploads folder’s .htaccess file. A test request got a copy.', 'seoprostack') . '</p>';
            } else {
                echo '<p>' . esc_html__('Browsers get the copies through rules in the uploads folder’s .htaccess file.', 'seoprostack') . '</p>';
            }
        } else {
            $sites = isset($state['sites']) && is_array($state['sites']) ? $state['sites'] : array(get_current_blog_id() => self::site_rules());
            echo '<p>' . esc_html__('The uploads folder’s .htaccess file could not be changed. So browsers get the copies, add these lines to it:', 'seoprostack') . '</p>';
            echo '<pre class="sps-code">' . esc_html('# BEGIN ' . self::MARKER . "\n" . implode("\n", self::rules_for($sites)) . "\n# END " . self::MARKER) . '</pre>';
        }
        echo '</div>';
    }

    /**
     * How copies are sent, in the settings panel, with Copies at their own
     * addresses on.
     *
     * @param string $status Rules status (written, nginx, unwritable, removed).
     * @param array  $sites  Sites using the rules (site ID => flags).
     */
    private static function picture_panel($status, array $sites) {
        echo '<p>' . esc_html__('Pages name each picture’s copies in a <picture> tag, so every address always sends the same file and CDNs can cache pictures.', 'seoprostack') . '</p>';
        if (self::is_nginx() || 'nginx' === $status) {
            echo '<p>' . esc_html__('If Nginx sends AVIF copies with the wrong type, add these lines to the site’s Nginx configuration and reload Nginx. Lines added earlier that choose the copy by the Accept header are no longer needed.', 'seoprostack') . '</p>';
            echo '<pre class="sps-code">' . esc_html(self::nginx_rules()) . '</pre>';
        } elseif ($sites && !self::all_pictures($sites)) {
            echo '<p>' . esc_html__('Other sites on the network still get copies at the originals’ addresses, so the shared rules in the uploads folder still choose by the Accept header.', 'seoprostack') . '</p>';
        } elseif ('written' === $status && 'original' === self::check_rules($sites)) {
            echo '<p>' . esc_html__('A test request for a copy did not come back as AVIF or WebP, so browsers may not show it.', 'seoprostack') . ' ' . esc_html(self::retry_advice('mod_mime')) . '</p>';
        }
    }

    /**
     * What to do when a rules check fails.
     *
     * @param string $modules Apache modules the rules need.
     * @return string
     */
    private static function retry_advice($modules) {
        if (self::is_litespeed()) {
            return __('LiteSpeed keeps .htaccess rules in memory: restart LiteSpeed in your hosting panel, or ask your host to. Checked again in a few minutes.', 'seoprostack');
        }
        /* translators: %s: Apache module names, such as mod_rewrite and mod_headers. */
        return sprintf(__('Check that the server reads .htaccess files in the uploads folder and has %s, or ask your host. Checked again in a few minutes.', 'seoprostack'), $modules);
    }

    /**
     * Sizes of the copies in the attachment details.
     *
     * @param array   $fields Fields.
     * @param WP_Post $post   Attachment.
     * @return array
     */
    public static function attachment_field($fields, $post) {
        if (!in_array(get_post_mime_type($post), self::source_types(), true)) {
            return $fields;
        }
        $file   = get_attached_file($post->ID);
        $name   = $file ? wp_basename($file) : '';
        $record = get_post_meta($post->ID, self::META, true);
        if (self::signature() !== get_post_meta($post->ID, self::DONE, true)) {
            $text = __('Waiting to be converted.', 'seoprostack');
        } elseif (!is_array($record) || empty($record['files'][$name])) {
            $text = __('None made.', 'seoprostack');
        } else {
            $parts = array();
            foreach ($record['files'][$name] as $format => $bytes) {
                $label = strtoupper($format);
                if ($bytes > 0) {
                    $parts[] = sprintf('%1$s %2$s', $label, size_format($bytes, 1));
                } elseif (0 === $bytes) {
                    /* translators: %s: format */
                    $parts[] = sprintf(__('%s not smaller', 'seoprostack'), $label);
                } else {
                    /* translators: %s: format */
                    $parts[] = sprintf(__('%s failed', 'seoprostack'), $label);
                }
            }
            $original = isset($record['sources'][$name]) ? (int) $record['sources'][$name] : 0;
            /* translators: 1: list of copies, 2: original file size */
            $text = sprintf(__('%1$s (original %2$s)', 'seoprostack'), implode(', ', $parts), size_format($original, 1));
        }
        $fields['seoprostack_nextgen'] = array(
            'label' => __('WebP and AVIF', 'seoprostack'),
            'input' => 'html',
            'html'  => '<span class="sps-nextgen">' . esc_html($text) . '</span>',
        );
        return $fields;
    }

    /**
     * Bulk action on Media → Library (list view).
     *
     * @param array $actions Bulk actions.
     * @return array
     */
    public static function bulk_action($actions) {
        if (self::formats()) {
            $actions[self::BULK] = __('Make WebP and AVIF copies', 'seoprostack');
        }
        return $actions;
    }

    /**
     * Make copies of the chosen pictures now.
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
        $ids    = array_values(array_filter(array_map('absint', (array) $ids), function ($id) {
            return current_user_can('edit_post', $id);
        }));
        $start  = microtime(true);
        $counts = array('sps_nextgen_done' => 0, 'sps_nextgen_left' => 0);
        foreach ($ids as $i => $id) {
            if (!self::more_time($start, self::BUDGET)) {
                $counts['sps_nextgen_left'] = count($ids) - $i;
                break;
            }
            if (!is_wp_error(self::convert_attachment($id))) {
                $counts['sps_nextgen_done']++;
            }
        }
        if ($counts['sps_nextgen_left']) {
            // Those left are still waiting, so background batches do them.
            self::schedule();
        }
        return add_query_arg($counts, remove_query_arg(array('sps_nextgen_done', 'sps_nextgen_left'), $redirect));
    }

    /**
     * Result notice on Media → Library.
     */
    public static function notice() {
        if (!isset($_GET['sps_nextgen_done'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
            return;
        }
        $done = absint(wp_unslash($_GET['sps_nextgen_done'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $left = isset($_GET['sps_nextgen_left']) ? absint(wp_unslash($_GET['sps_nextgen_left'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        /* translators: %d: number of pictures */
        $text = sprintf(_n('%d picture converted.', '%d pictures converted.', $done, 'seoprostack'), $done);
        if ($left) {
            /* translators: %d: number of pictures */
            $text .= ' ' . sprintf(_n('%d more will be converted in the background.', '%d more will be converted in the background.', $left, 'seoprostack'), $left);
        }
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($text));
    }

    /**
     * Drop the notice arguments from the address after showing them.
     *
     * @param string[] $args Query arguments.
     * @return string[]
     */
    public static function removable_query_args($args) {
        return array_merge($args, array('sps_nextgen_done', 'sps_nextgen_left'));
    }

    /**
     * Make WebP and AVIF copies of every picture now.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Make every copy again. Not needed after changing the quality: copies
     * made with other quality settings are made again anyway.
     *
     * [--dry-run]
     * : Say how many pictures are waiting.
     *
     * [--cleanup-compressx]
     * : Delete CompressX leftovers after conversion finishes on every site.
     * Combine with --dry-run to show their count and size without changes.
     *
     * ## EXAMPLES
     *
     *     wp seoprostack convert-images
     *     wp seoprostack convert-images --force
     *
     * @param array $args  Positional arguments.
     * @param array $assoc Options.
     */
    public static function cli($args, $assoc) {
        if (isset($assoc['cleanup-compressx'])) {
            if (isset($assoc['force'])) {
                WP_CLI::error('Run conversion with --force separately before cleanup.');
            }
            if (isset($assoc['dry-run'])) {
                $size = self::compressx_leftovers();
                if (is_wp_error($size)) {
                    WP_CLI::error($size->get_error_message());
                    return;
                }
                WP_CLI::success(sprintf('CompressX leftovers: %d files (%s). Nothing changed.', $size['files'], size_format($size['bytes'], 1)));
                return;
            }
            $result = self::cleanup_compressx();
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::success('CompressX leftovers removed. Adopted WebP and AVIF copies were kept.');
            return;
        }
        if (!self::formats()) {
            WP_CLI::error('No format to make: turn on WebP or AVIF copies, or check server support.');
        }
        $force = !empty($assoc['force']);
        if (!empty($assoc['dry-run'])) {
            $counts = self::counts();
            WP_CLI::success(sprintf('%d of %d pictures are waiting.', $force ? $counts['total'] : $counts['total'] - $counts['done'], $counts['total']));
            return;
        }
        if ($force) {
            delete_metadata('post', 0, self::DONE, '', true);
        }
        $counts = array('converted' => 0, 'failed' => 0);
        while ($ids = self::waiting(50)) {
            foreach ($ids as $id) {
                $record = self::convert_attachment($id, $force);
                if (is_wp_error($record)) {
                    continue;
                }
                $counts['converted']++;
                foreach ($record['files'] as $name => $formats) {
                    if (in_array(-1, $formats, true)) {
                        $counts['failed']++;
                        WP_CLI::warning(sprintf('%d: a copy of %s could not be made.', $id, $name));
                    }
                }
            }
            wp_cache_flush_runtime();
        }
        WP_CLI::success(sprintf('%d pictures converted; %d copies could not be made.', $counts['converted'], $counts['failed']));
    }
}
