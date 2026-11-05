<?php
/**
 * Lighter Tutor LMS pages.
 *
 * Tutor LMS (checked with 4.2.0 and Tutor LMS Pro 4.2.0) loads its styles
 * and scripts on every page of the site (TUTOR\Assets on
 * wp_enqueue_scripts), and through tutor-script's dependencies React,
 * ReactDOM and moment; the Kadence theme adds its own Tutor stylesheet
 * (kadence-tutorlms) whenever Tutor is active. On a home page with no Tutor
 * content that was seven stylesheets holding up the first paint and about
 * fifteen scripts (GitHub issue #1258). This keeps them to Tutor's pages:
 * its courses, lessons, quizzes and other post types, their archives and
 * categories, its dashboard, profile, registration, cart and checkout
 * pages, and posts whose content has Tutor shortcodes or blocks.
 *
 * Only dequeued: a script or style that another one needs still loads. If
 * a Tutor shortcode or block renders on another page anyway (in a Kadence
 * Element or a widget), what was taken out is enqueued again, and WordPress
 * prints it in the footer. Left alone for people who are logged in, who
 * get Tutor's toolbar items, push notification prompt and flash messages;
 * pages for visitors are the ones that are cached and measured.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.161
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Tutor_Light extends SEOProStack_Feature {

    const KEY = 'tutor_light';

    /** Plugin folders whose styles and scripts are Tutor's. */
    const FOLDERS = array('tutor', 'tutor-pro');

    /** The Kadence theme's stylesheet for Tutor LMS. */
    const KADENCE = 'kadence-tutorlms';

    /** Properties of tutor() that name Tutor's post types. */
    const TYPES = array(
        'course_post_type', 'bundle_post_type', 'lesson_post_type', 'topics_post_type', 'announcement_post_type',
        'assignment_post_type', 'enrollment_post_type', 'quiz_post_type', 'zoom_post_type', 'meet_post_type',
    );

    /** Query variables Tutor registers for its dashboard, profiles and course pages. */
    const QUERY_VARS = array('course_subpage', 'lesson_video', 'tutor_dashboard_page', 'tutor_dashboard_sub_page', 'tutor_profile_username');

    /** tutor_utils() checks for Tutor's own screens. */
    const CHECKS = array('is_dashboard_page', 'is_tutor_frontend_dashboard', 'is_learning_area', 'is_course_list_page', 'is_course_details_page');

    /** A Tutor shortcode or block in content. */
    const CONTENT = '/\[tutor_[a-z0-9_]*[\s\]\/]|<!--\s*wp:tutor[a-z0-9-]*\//i';

    /**
     * Request-level cache of is_tutor_page().
     *
     * @var bool|null
     */
    private static $tutor_page = null;

    /**
     * Whether a Tutor shortcode or block rendered on this request.
     *
     * @var bool
     */
    private static $used = false;

    /**
     * Handles taken out on this request.
     *
     * @var array{styles: non-empty-string[], scripts: non-empty-string[]}
     */
    private static $removed = array('styles' => array(), 'scripts' => array());

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Lighter Tutor LMS pages', 'seoprostack'),
                'description' => __('Tutor LMS loads its styles and scripts, and with them React, on every page. Keep them to Tutor’s own pages (courses, lessons, the dashboard, profiles, registration, cart and checkout) and to pages with Tutor blocks or shortcodes. People who are logged in get every page as before. Does nothing without Tutor LMS.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin()) {
            return;
        }
        // After Tutor (10) and the Kadence theme's Tutor stylesheet (60).
        add_action('wp_enqueue_scripts', array(__CLASS__, 'dequeue'), PHP_INT_MAX);
        add_filter('pre_do_shortcode_tag', array(__CLASS__, 'shortcode'), 10, 2);
        add_filter('pre_render_block', array(__CLASS__, 'block'), 10, 2);
    }

    /**
     * Take Tutor's styles and scripts out of pages that do not use Tutor.
     */
    public static function dequeue() {
        // Logged-in people keep everything: Tutor's toolbar items, its push
        // notification prompt on the front page and its flash messages.
        if (self::$used || !function_exists('tutor') || is_user_logged_in() || self::is_tutor_page()) {
            return;
        }
        $folders = self::folders();
        foreach (array('styles' => wp_styles(), 'scripts' => wp_scripts()) as $kind => $assets) {
            foreach ($assets->queue as $handle) {
                if ('' !== $handle && (('styles' === $kind && self::KADENCE === $handle) || self::in_folders($assets, $handle, $folders))) {
                    self::$removed[$kind][] = $handle;
                }
            }
        }
        foreach (self::$removed['styles'] as $handle) {
            wp_dequeue_style($handle);
        }
        foreach (self::$removed['scripts'] as $handle) {
            wp_dequeue_script($handle);
        }
    }

    /**
     * A Tutor shortcode is about to render: it needs Tutor's files.
     *
     * @param false|string $output Short-circuit output.
     * @param string       $tag    Shortcode name.
     * @return false|string
     */
    public static function shortcode($output, $tag) {
        if (0 === strpos((string) $tag, 'tutor')) {
            self::used();
        }
        return $output;
    }

    /**
     * A Tutor block is about to render: it needs Tutor's files.
     *
     * @param string|null $output Short-circuit output.
     * @param array       $block  Parsed block.
     * @return string|null
     */
    public static function block($output, $block) {
        if (isset($block['blockName']) && is_string($block['blockName']) && 0 === strpos($block['blockName'], 'tutor')) {
            self::used();
        }
        return $output;
    }

    /**
     * Put back what was taken out; WordPress prints it in the footer.
     */
    private static function used() {
        self::$used = true;
        foreach (self::$removed['styles'] as $handle) {
            wp_enqueue_style($handle);
        }
        foreach (self::$removed['scripts'] as $handle) {
            wp_enqueue_script($handle);
        }
        self::$removed = array('styles' => array(), 'scripts' => array());
    }

    /**
     * Whether this page is one of Tutor's, or has Tutor content.
     *
     * @return bool
     */
    public static function is_tutor_page() {
        if (null !== self::$tutor_page) {
            return self::$tutor_page;
        }
        $page = self::tutor_screen();
        if (!$page && is_singular()) {
            $post = get_queried_object();
            $page = $post instanceof WP_Post && (in_array($post->ID, self::option_pages(), true) || self::tutor_content($post->post_content));
        }

        /**
         * Whether a page keeps Tutor LMS's styles and scripts with Lighter
         * Tutor LMS pages on, for content the checks do not see.
         *
         * @param bool $page Whether it is one of Tutor's pages or has Tutor blocks or shortcodes.
         */
        self::$tutor_page = (bool) apply_filters('seoprostack_tutor_light_page', $page);
        return self::$tutor_page;
    }

    /**
     * Whether this is a page of Tutor's post types, their archives or
     * categories, or one of Tutor's own screens.
     *
     * @return bool
     */
    private static function tutor_screen() {
        foreach (self::QUERY_VARS as $var) {
            if ('' !== (string) get_query_var($var)) {
                return true;
            }
        }
        $types = self::post_types();
        if ($types && (is_singular($types) || is_post_type_archive($types))) {
            return true;
        }
        $taxonomies = $types ? get_object_taxonomies($types) : array();
        if ($taxonomies && is_tax($taxonomies)) {
            return true;
        }
        if (!function_exists('tutor_utils')) {
            return false;
        }
        try {
            $utils = tutor_utils();
            foreach (self::CHECKS as $check) {
                if (method_exists($utils, $check) && $utils->$check()) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            return true; // A Tutor version this does not know: keep its files.
        }
        return false;
    }

    /**
     * Tutor's registered post types.
     *
     * @return string[]
     */
    private static function post_types() {
        $types = array();
        if (!function_exists('tutor')) {
            return $types;
        }
        try {
            // TUTOR\Config since Tutor 3.7, whose all() avoids __get()'s
            // log entry for a name an older version lacks; before, an object
            // with these as properties.
            $config   = tutor();
            $settings = is_object($config) && method_exists($config, 'all') ? (array) $config->all() : (array) $config;
            foreach (self::TYPES as $name) {
                $type = isset($settings[$name]) ? $settings[$name] : null;
                if (is_string($type) && '' !== $type && post_type_exists($type)) {
                    $types[] = $type;
                }
            }
        } catch (Throwable $e) {
            return $types;
        }
        return $types;
    }

    /**
     * Pages named in Tutor's settings: dashboard, registration, cart,
     * checkout and the like (`*_page` and `*_page_id`, not `*_per_page`).
     *
     * @return int[]
     */
    private static function option_pages() {
        $ids = array();
        foreach ((array) get_option('tutor_option', array()) as $name => $value) {
            if (is_string($name) && preg_match('/_page(_id)?$/', $name) && false === strpos($name, 'per_page')
                && is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }
        return $ids;
    }

    /**
     * Whether content, or a synced pattern in it, has a Tutor shortcode or
     * block.
     *
     * @param string $content Post content.
     * @param int    $depth   Synced patterns followed so far.
     * @return bool
     */
    private static function tutor_content($content, $depth = 0) {
        $content = (string) $content;
        if (preg_match(self::CONTENT, $content)) {
            return true;
        }
        if ($depth >= 2 || !preg_match_all('/<!--\s*wp:block\s+\{[^}]*"ref":(\d+)/', $content, $refs)) {
            return false;
        }
        foreach (array_slice(array_unique($refs[1]), 0, 10) as $ref) {
            $pattern = get_post((int) $ref);
            if ($pattern instanceof WP_Post && 'wp_block' === $pattern->post_type && self::tutor_content($pattern->post_content, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * URL paths of Tutor's plugin folders, with a trailing slash.
     *
     * @return string[]
     */
    private static function folders() {
        $paths = array();
        foreach (self::FOLDERS as $folder) {
            $path = wp_parse_url(plugins_url($folder . '/'), PHP_URL_PATH);
            if (is_string($path) && '' !== $path) {
                $paths[] = trailingslashit($path);
            }
        }
        return $paths;
    }

    /**
     * Whether a queued style or script is a file in Tutor's folders.
     *
     * @param WP_Dependencies $assets  Styles or scripts.
     * @param string          $handle  Handle.
     * @param string[]        $folders URL paths.
     * @return bool
     */
    private static function in_folders($assets, $handle, array $folders) {
        $asset = isset($assets->registered[$handle]) ? $assets->registered[$handle] : null;
        if (!$asset instanceof _WP_Dependency || !is_string($asset->src) || '' === $asset->src) {
            return false;
        }
        $path = wp_parse_url($asset->src, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        foreach ($folders as $folder) {
            if (0 === strpos($path, $folder)) {
                return true;
            }
        }
        return false;
    }
}
