<?php
/**
 * Lighter Tutor LMS pages: whether a page is one of Tutor LMS's, or has
 * Tutor content (SEOProStack_Tutor_Light). Its courses, lessons, quizzes
 * and other post types, their archives and categories, its dashboard,
 * profile and course subpages, the pages named in its settings
 * (registration, cart, checkout and the like), posts whose content, or
 * a synced pattern in it, has Tutor shortcodes or blocks (synced patterns
 * are followed two levels deep, ten per post; beyond that the page keeps
 * Tutor's files), and every page while Tutor's Tutor Course widget is in a
 * widget area.
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

final class SEOProStack_Tutor_Light_Page {

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
     * Whether this page is one of Tutor's, or has Tutor content.
     *
     * @return bool
     */
    public static function is_tutor_page() {
        if (null !== self::$tutor_page) {
            return self::$tutor_page;
        }
        // Tutor's Tutor Course widget, in a widget area, shows course cards on any page.
        $page = self::query_var() || self::post_type_screen() || self::utils_screen() || self::singular()
            || is_active_widget(false, false, 'tutor_course_widget', true);

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
     * Whether a query variable of Tutor's dashboard, profiles or course
     * subpages is set.
     *
     * @return bool
     */
    private static function query_var() {
        foreach (self::QUERY_VARS as $var) {
            if ('' !== (string) get_query_var($var)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether this is a page of Tutor's post types, their archives or
     * categories.
     *
     * @return bool
     */
    private static function post_type_screen() {
        $types = self::post_types();
        if (!$types) {
            return false;
        }
        if (is_singular($types) || is_post_type_archive($types)) {
            return true;
        }
        $taxonomies = get_object_taxonomies($types);
        return $taxonomies && is_tax($taxonomies);
    }

    /**
     * Whether tutor_utils() says this is one of Tutor's own screens.
     *
     * @return bool
     */
    private static function utils_screen() {
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
     * Whether this is a page named in Tutor's settings, or a post with
     * Tutor content.
     *
     * @return bool
     */
    private static function singular() {
        if (!is_singular()) {
            return false;
        }
        $post = get_queried_object();
        return $post instanceof WP_Post && (in_array($post->ID, self::option_pages(), true) || self::tutor_content($post->post_content));
    }

    /**
     * Tutor's registered post types.
     *
     * @return string[]
     */
    private static function post_types() {
        $types    = array();
        $settings = self::config();
        foreach (self::TYPES as $name) {
            $type = isset($settings[$name]) ? $settings[$name] : null;
            if (is_string($type) && '' !== $type && post_type_exists($type)) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * Tutor's configuration: TUTOR\Config since Tutor 3.7, whose all()
     * avoids __get()'s log entry for a name an older version lacks; before,
     * an object with these as properties.
     *
     * @return array
     */
    private static function config() {
        if (!function_exists('tutor')) {
            return array();
        }
        try {
            $config = tutor();
            return is_object($config) && method_exists($config, 'all') ? (array) $config->all() : (array) $config;
        } catch (Throwable $e) {
            return array();
        }
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
        if (!preg_match_all('/<!--\s*wp:block\s+\{[^}]*"ref":(\d+)/', $content, $refs)) {
            return false;
        }
        $refs = array_unique($refs[1]);
        if ($depth >= 2 || count($refs) > 10) {
            return true; // More patterns than are followed: keep Tutor's files, as one may hold Tutor content.
        }
        foreach ($refs as $ref) {
            $pattern = get_post((int) $ref);
            if ($pattern instanceof WP_Post && 'wp_block' === $pattern->post_type && self::tutor_content($pattern->post_content, $depth + 1)) {
                return true;
            }
        }
        return false;
    }
}
