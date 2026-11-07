<?php
/**
 * The missing copies of Kadence Blocks row and slide pictures (GH#1406),
 * for Image loading (SEOProStack_Hero_Picture).
 *
 * The sizing scripts (SEOProStack_Kadence_Hero, SEOProStack_Kadence_Early,
 * SEOProStack_Kadence_Slider) take the smallest copy at least as wide as
 * the pixels a picture paints, else the picture itself. Many uploads lack
 * WordPress's 1536 and 2048 copies (made before WordPress 5.3, or while a
 * plugin removed them), so a row that needs 1,500 pixels got the full
 * 2,000 to 2,560 pixel upload, on phones too. When a picture is read for a
 * page, the uncropped core sizes WordPress would make for it and it lacks
 * are made in the background, a few pictures a run, with WordPress's own
 * code (wp_update_image_subsizes(), only those sizes), and the page it was
 * seen on is opened again. More copies never make a browser fetch a larger
 * file (it takes the smallest that is enough), so never less sharp. Sizes
 * a site removed (remove_image_size()) are not registered, so not made.
 * Next-gen images makes the new files' AVIF and WebP copies, as for any
 * upload.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.183
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Picture_Sizes {

    /** Cron hook of the batch. */
    const HOOK = 'seoprostack_picture_sizes';

    /** Option: attachment ID => ID of the post it was seen on (0 when none). */
    const OPTION = 'seoprostack_picture_sizes';

    /** Attachment meta: when its sizes were last made or tried. */
    const TRIED = '_seoprostack_sizes_tried';

    /** Sizes made: WordPress's uncropped ones, which the scripts can use. */
    const SIZES = array('medium', 'medium_large', 'large', '1536x1536', '2048x2048');

    /** Pictures waiting, at most. */
    const MAX = 50;

    /** Pictures a batch makes sizes for, at most. */
    const BATCH = 3;

    /** Seconds a batch starts new pictures for. */
    const BUDGET = 20;

    /** Seconds between batches. */
    const DELAY = 60;

    /** Seconds before a picture is tried again. */
    const RETRY = WEEK_IN_SECONDS;

    /** @var array<int,bool> Pictures already looked at on this request. */
    private static $seen = array();

    /** @var int Attachment whose sizes are being made. */
    private static $making = 0;

    /**
     * Register the batch.
     */
    public static function boot() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
    }

    /**
     * A picture was read for a page: queue it when it lacks sizes.
     *
     * @param int   $id   Attachment ID.
     * @param array $meta Its metadata.
     */
    public static function want($id, array $meta) {
        $id = (int) $id;
        if ($id < 1 || isset(self::$seen[$id]) || is_admin()) {
            return;
        }
        self::$seen[$id] = true;
        if (!self::missing($meta) || !wp_attachment_is_image($id) || (int) get_post_meta($id, self::TRIED, true) > time() - self::RETRY) {
            return;
        }
        $queue = self::queue();
        if (!isset($queue[$id]) && count($queue) < self::MAX) {
            $queue[$id] = is_singular() ? (int) get_queried_object_id() : 0;
            update_option(self::OPTION, $queue, false);
        }
        if ($queue && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + self::DELAY, self::HOOK);
        }
    }

    /**
     * The sizes of SIZES a picture lacks that WordPress would make for it:
     * registered, and smaller than the picture (as image_resize_dimensions()
     * decides, which wp_get_missing_image_subsizes() also asks).
     *
     * @param array $meta Attachment metadata.
     * @return array<string,array> Size name => its registered width, height and crop.
     */
    public static function missing(array $meta) {
        $width  = (int) ($meta['width'] ?? 0);
        $height = (int) ($meta['height'] ?? 0);
        if ($width < 1 || $height < 1) {
            return array();
        }
        $have    = (array) ($meta['sizes'] ?? array());
        $missing = array();
        foreach (array_intersect_key(wp_get_registered_image_subsizes(), array_flip(self::SIZES)) as $name => $size) {
            if (empty($have[$name]) && image_resize_dimensions($width, $height, (int) $size['width'], (int) $size['height'], !empty($size['crop']))) {
                $missing[$name] = $size;
            }
        }
        return $missing;
    }

    /**
     * One batch: make the sizes of up to BATCH pictures, then purge and
     * open again the pages they were seen on. Each picture leaves the queue
     * and is marked tried before its sizes are made, so one that stops the
     * run (memory, time) is not tried again for RETRY.
     */
    public static function run() {
        $queue = self::queue();
        $start = time();
        $done  = 0;
        $posts = array();
        if ($queue) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            add_filter('wp_get_missing_image_subsizes', array(__CLASS__, 'only_these'), 99, 3);
            add_filter('wp_get_original_image_path', array(__CLASS__, 'source'), 99, 2);
        }
        foreach ($queue as $id => $post_id) {
            if ($done >= self::BATCH || time() - $start >= self::BUDGET) {
                break;
            }
            ++$done;
            unset($queue[$id]);
            self::save($queue);
            update_post_meta($id, self::TRIED, time());
            if (self::make($id) && $post_id > 0) {
                $posts[] = $post_id;
            }
        }
        remove_filter('wp_get_missing_image_subsizes', array(__CLASS__, 'only_these'), 99);
        remove_filter('wp_get_original_image_path', array(__CLASS__, 'source'), 99);
        if ($posts) {
            self::refresh(array_unique($posts));
        }
        if ($queue && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + self::DELAY, self::HOOK);
        }
    }

    /**
     * Make a picture's missing sizes.
     *
     * @param int $id Attachment ID.
     * @return bool Whether it has more sizes now.
     */
    private static function make($id) {
        $meta = wp_get_attachment_metadata($id);
        // Not a watermarked picture: its files are marked in place, so new
        // sizes would carry a mark Watermark pictures could not remove.
        if (!wp_attachment_is_image($id) || !is_array($meta) || !self::missing($meta) || get_post_meta($id, '_seoprostack_watermark', true)) {
            return false;
        }
        $before       = count((array) ($meta['sizes'] ?? array()));
        self::$making = (int) $id;
        $result       = wp_update_image_subsizes($id);
        self::$making = 0;
        return is_array($result) && count((array) ($result['sizes'] ?? array())) > $before;
    }

    /**
     * While a batch makes a picture's sizes: only those of SIZES.
     *
     * @param array $missing       Sizes WordPress would make.
     * @param array $image_meta    Attachment metadata.
     * @param int   $attachment_id Attachment ID.
     * @return array
     */
    public static function only_these($missing, $image_meta, $attachment_id) {
        unset($image_meta);
        if ((int) $attachment_id !== self::$making || !is_array($missing)) {
            return $missing;
        }
        return array_intersect_key($missing, array_flip(self::SIZES));
    }

    /**
     * While a batch makes a picture's sizes: when the original upload is
     * gone (Resize uploads, or an upload kept only at the large image
     * threshold), make them from the picture's own file instead, which is
     * larger than each of them. Media in cloud storage brings the original
     * back first (an earlier filter).
     *
     * @param string|false $path          Original's path.
     * @param int          $attachment_id Attachment ID.
     * @return string|false
     */
    public static function source($path, $attachment_id) {
        if ((int) $attachment_id !== self::$making || ($path && is_file($path))) {
            return $path;
        }
        $file = get_attached_file($attachment_id);
        return $file && is_file($file) ? $file : $path;
    }

    /**
     * Purge the pages the pictures were seen on, only those, and open them
     * again (Keep pages cached), so their scripts list the new copies.
     *
     * @param int[] $posts Post IDs.
     */
    private static function refresh(array $posts) {
        SEOProStack_Page_Cache::purge_posts($posts, true);
        if (!class_exists('SEOProStack_Keep_Pages_Cached') || !SEOProStack_Keep_Pages_Cached::enabled() || '' === SEOProStack_Keep_Pages_Cached::page_cache()) {
            return;
        }
        // The home page's own address for the front page (get_page_link()).
        $urls = SEOProStack_Keep_Cached_Pages::clean(array_filter(array_map('get_permalink', $posts)), count($posts));
        if ($urls) {
            SEOProStack_Keep_Cached_Queue::add($urls, self::DELAY);
        }
    }

    /**
     * Pictures waiting.
     *
     * @return array<int,int>
     */
    private static function queue() {
        $queue = get_option(self::OPTION, array());
        $list  = array();
        foreach (is_array($queue) ? $queue : array() as $id => $post_id) {
            if ((int) $id > 0) {
                $list[(int) $id] = (int) $post_id;
            }
        }
        return $list;
    }

    /**
     * Store what waits; the option goes when nothing does.
     *
     * @param array<int,int> $queue Pictures waiting.
     */
    private static function save(array $queue) {
        if ($queue) {
            update_option(self::OPTION, $queue, false);
        } else {
            delete_option(self::OPTION);
        }
    }
}
