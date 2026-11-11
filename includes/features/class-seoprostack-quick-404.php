<?php
/**
 * Short not-found answer for missing files.
 *
 * A request for a file that is not there (/llms.txt, a removed picture, a
 * bot's .php or .env probe) goes through WordPress, which answers with the
 * theme's whole not-found page: header, menus, footer and blocks. On two
 * large directory sites that took 6 to 9 s uncached, three quarters of it
 * the theme's page (2.8 s and 91 of 118 queries), and PageSpeed Insights'
 * Agentic Browsing check gives /llms.txt 2 s before it fails with "Timed
 * out fetching resource"; a quick 404 passes (GH#1525).
 *
 * When WordPress has decided a request is not found (404, or 410 from 410
 * Gone for removed pages) and the address's last part has a file extension,
 * the answer is a short plain-text "Not Found" instead of the theme's page,
 * with the status WordPress set. It runs last at template_include, after
 * every redirect and every plugin that serves a file of its own (Rank
 * Math's llms.txt and sitemaps, core's robots.txt, favicon.ico and
 * sitemaps), which never reach a 404.
 *
 * Left alone: addresses without an extension or ending in /, and .htm and
 * .html pages people may have bookmarked, which keep the theme's page; any
 * status other than 404 or 410. Rank Math's 404 Monitor records not-found
 * addresses from the theme's get_header (wp_head on block themes), which the
 * short answer does not run, so its capture runs here.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.199
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Quick_404 extends SEOProStack_Feature {

    const KEY = 'quick_404';

    /** Extensions of pages people may have bookmarked: these keep the theme's page. */
    const PAGE_EXTENSIONS = array('htm', 'html', 'shtml', 'xhtml');

    /** Hooks a not-found logger may record from, as the theme's page runs them. */
    const LOG_HOOKS = array('get_header', 'wp_head');

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
                'tab'         => 'server',
                'label'       => __('Short not-found answer for missing files', 'seoprostack'),
                'description' => __('A missing file, such as llms.txt, a removed picture or a bot’s probe, gets the theme’s whole not-found page, which costs as much as building a page. This answers those with a short “Not Found” instead, with the same status. Other addresses, and .html pages, keep the theme’s not-found page.', 'seoprostack'),
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
        // Last: after every plugin had its chance to choose the template.
        add_filter('template_include', array(__CLASS__, 'template'), PHP_INT_MAX);
    }

    /**
     * Answer a missing file's request in short, instead of loading the
     * theme's not-found page.
     *
     * @param string $template Template file.
     * @return string Unchanged, unless the request ends here.
     */
    public static function template($template) {
        $status = http_response_code();
        if (!is_404() || !in_array($status, array(404, 410), true) || headers_sent()) {
            return $template;
        }
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only its path is compared.
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        /**
         * Whether a missing file gets the short answer instead of the theme's
         * not-found page.
         *
         * @param bool   $short Whether to answer in short; true for a path with a file extension.
         * @param string $path  Requested path.
         */
        if (!apply_filters('seoprostack_quick_404', self::is_file_path($path), $path)) {
            return $template;
        }
        self::record();
        header('Content-Type: text/plain; charset=' . get_option('blog_charset', 'UTF-8'), true);
        header('X-Robots-Tag: noindex', true);
        echo esc_html(get_status_header_desc($status)) . "\n";
        exit;
    }

    /**
     * Whether a path names a file: its last part has an extension other than
     * a page's (.htm, .html), and it does not end in /.
     *
     * @param string $path URL path.
     * @return bool
     */
    public static function is_file_path($path) {
        $path = (string) $path;
        if ('' === $path || '/' === substr($path, -1)) {
            return false;
        }
        $parts = explode('/', $path);
        $name  = rawurldecode((string) end($parts));
        if (!preg_match('/\.([a-z0-9]{1,8})$/i', $name, $match)) {
            return false;
        }
        return !in_array(strtolower($match[1]), self::PAGE_EXTENSIONS, true);
    }

    /**
     * Let Rank Math's 404 Monitor record the address, as it would from the
     * theme's page.
     */
    private static function record() {
        global $wp_filter;
        foreach (self::LOG_HOOKS as $hook) {
            $callbacks = isset($wp_filter[$hook]) && $wp_filter[$hook] instanceof WP_Hook ? $wp_filter[$hook]->callbacks : array();
            foreach ($callbacks as $at_priority) {
                foreach ($at_priority as $callback) {
                    if (self::is_rank_math_capture($callback['function'])) {
                        call_user_func($callback['function']);
                    }
                }
            }
        }
    }

    /**
     * Whether a callback is Rank Math's 404 Monitor capture.
     *
     * @param mixed $callback Callback.
     * @return bool
     */
    private static function is_rank_math_capture($callback) {
        return is_array($callback) && isset($callback[0], $callback[1]) && is_object($callback[0])
            && 'capture_404' === $callback[1] && 0 === strpos(get_class($callback[0]), 'RankMath\\');
    }
}
