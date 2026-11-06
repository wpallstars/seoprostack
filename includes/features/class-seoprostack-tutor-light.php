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
 * fifteen scripts (GitHub issue #1258). This keeps them to Tutor's pages
 * (SEOProStack_Tutor_Light_Page). Elsewhere it also leaves out the
 * WordPress jQuery UI scripts Tutor queues, the Kadence theme's Tutor rules
 * in its inline CSS and Tutor's body class, so such a page is the same as
 * without Tutor, and Load plugins only where needed can skip Tutor there
 * (GitHub issue #1264).
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

require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-tutor-light-page.php';

class SEOProStack_Tutor_Light extends SEOProStack_Feature {

    const KEY = 'tutor_light';

    /** Plugin folders whose styles and scripts are Tutor's. */
    const FOLDERS = array('tutor', 'tutor-pro');

    /** The Kadence theme's stylesheet for Tutor LMS. */
    const KADENCE = 'kadence-tutorlms';

    /** The Kadence theme's Tutor LMS part, which adds Tutor's rules to its inline CSS. */
    const KADENCE_PART = 'Kadence\\TutorLMS\\Component';

    /**
     * WordPress scripts Tutor LMS 4.2.0 queues on every page
     * (TUTOR\Assets::frontend_scripts(), with its legacy scripts), which
     * bring jQuery into the head and jQuery UI.
     */
    const CORE_SCRIPTS = array('jquery-ui-sortable', 'jquery-touch-punch');

    /** Body class Tutor adds to every page. */
    const BODY_CLASS = 'tutor-lms';

    /**
     * CORE_SCRIPTS already queued before Tutor's (wp_enqueue_scripts 10).
     *
     * @var string[]
     */
    private static $core_before = array();

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
        // Before the Kadence theme builds its inline CSS (10).
        add_action('wp_enqueue_scripts', array(__CLASS__, 'kadence_css'), 1);
        // Around Tutor's own (10).
        add_action('wp_enqueue_scripts', array(__CLASS__, 'core_before'), 9);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'core_after'), 11);
        // After Tutor (10) and the Kadence theme's Tutor stylesheet (60).
        add_action('wp_enqueue_scripts', array(__CLASS__, 'dequeue'), PHP_INT_MAX);
        add_filter('body_class', array(__CLASS__, 'body_class'), 20);
        add_filter('pre_do_shortcode_tag', array(__CLASS__, 'shortcode'), 10, 2);
        add_filter('pre_render_block', array(__CLASS__, 'block'), 10, 2);
    }

    /**
     * Whether this page leaves Tutor's files out: not a Tutor page, and
     * nobody logged in. Logged-in people keep everything: Tutor's toolbar
     * items, its push notification prompt on the front page and its flash
     * messages.
     *
     * @return bool
     */
    private static function light() {
        return !self::$used && function_exists('tutor') && !is_user_logged_in() && !SEOProStack_Tutor_Light_Page::is_tutor_page();
    }

    /**
     * Leave the Kadence theme's Tutor rules (course and course archive
     * headers) out of its inline CSS on pages that do not use Tutor. They
     * are only there while Tutor is loaded, so without this a page renders
     * differently with and without Tutor, and Load plugins only where needed
     * kept Tutor on it (GitHub issue #1264).
     */
    public static function kadence_css() {
        global $wp_filter;
        if (!isset($wp_filter['kadence_dynamic_css']) || !self::light()) {
            return;
        }
        foreach ($wp_filter['kadence_dynamic_css']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                if (is_array($function) && is_object($function[0]) && is_a($function[0], self::KADENCE_PART)) {
                    remove_filter('kadence_dynamic_css', $function, $priority);
                }
            }
        }
    }

    /** Note which of CORE_SCRIPTS were queued before Tutor's turn. */
    public static function core_before() {
        self::$core_before = array_values(array_intersect(wp_scripts()->queue, self::CORE_SCRIPTS));
    }

    /**
     * Take out the CORE_SCRIPTS Tutor queued on a page that does not use
     * Tutor. Right after Tutor's turn, so a plugin that queues one later
     * still gets it.
     */
    public static function core_after() {
        if (!self::light() || !is_callable(array('TUTOR\\Assets', 'should_load_legacy_scripts'))
            || !call_user_func(array('TUTOR\\Assets', 'should_load_legacy_scripts'))) {
            return;
        }
        $queue = wp_scripts()->queue;
        foreach (self::CORE_SCRIPTS as $handle) {
            if (in_array($handle, $queue, true) && !in_array($handle, self::$core_before, true)) {
                wp_dequeue_script($handle);
                self::$removed['scripts'][] = $handle;
            }
        }
    }

    /**
     * Leave Tutor's body class off pages that do not use Tutor: nothing
     * there styles or reads it once Tutor's files are out.
     *
     * @param string[] $classes Body classes.
     * @return string[]
     */
    public static function body_class($classes) {
        if (!is_array($classes) || !self::light()) {
            return $classes;
        }
        return array_values(array_diff($classes, array(self::BODY_CLASS)));
    }

    /**
     * Take Tutor's styles and scripts out of pages that do not use Tutor.
     */
    public static function dequeue() {
        if (!self::light()) {
            return;
        }
        $folders                  = self::folders();
        self::$removed['styles']  = self::queued(wp_styles(), $folders, self::KADENCE);
        $scripts                  = self::queued(wp_scripts(), $folders, '');
        self::$removed['scripts'] = array_values(array_unique(array_merge(self::$removed['scripts'], $scripts)));
        foreach (self::$removed['styles'] as $handle) {
            wp_dequeue_style($handle);
        }
        foreach ($scripts as $handle) {
            wp_dequeue_script($handle);
        }
    }

    /**
     * Queued handles that are Tutor's: files in its folders, or the one
     * extra handle named.
     *
     * @param WP_Dependencies $assets  Styles or scripts.
     * @param string[]        $folders URL paths.
     * @param string          $extra   A further handle, or empty.
     * @return non-empty-string[]
     */
    private static function queued($assets, array $folders, $extra) {
        $handles = array();
        foreach ($assets->queue as $handle) {
            if ('' !== $handle && (('' !== $extra && $extra === $handle) || self::in_folders($assets, $handle, $folders))) {
                $handles[] = $handle;
            }
        }
        return $handles;
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
