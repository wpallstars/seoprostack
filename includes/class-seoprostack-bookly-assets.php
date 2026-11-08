<?php
/**
 * Load plugins only where needed: Bookly's booking form files at the end of
 * the page, not in the head (GitHub issue #1414).
 *
 * Bookly adds its booking form's stylesheets and scripts to the head of
 * pages whose posts have a [bookly-form] shortcode (or of every page, with
 * its "Enqueue" asset method): about twenty files that hold up the first
 * paint. The form's markup is an empty container that bookly.min.js fills
 * once document.readyState is "complete" (its start-up script, in
 * frontend/modules/booking/templates/short_code.php), so nothing needs
 * those files before the first paint. Here its scripts go to the footer and
 * its styles are enqueued again in the footer, where WordPress prints them
 * (print_late_styles()) before the footer scripts, so the form's CSS is in
 * place before its script fills it.
 *
 * Only where every Bookly shortcode in the page's posts is a booking form
 * and the content has no Bookly blocks: Bookly's other shortcodes, such as
 * appointment lists and the customer cabinet, print their markup on the
 * server and need their CSS for the first paint.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.185
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Bookly_Assets {

    /** Bookly's booking form shortcode. */
    const FORM = 'bookly-form';

    /** @var string[] Style handles taken out of the head, to print in the footer. */
    private static $styles = array();

    /**
     * Register hooks, on the site's pages.
     */
    public static function boot() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        // After Bookly's own wp_enqueue_scripts callbacks (priority 10).
        add_action('wp_enqueue_scripts', array(__CLASS__, 'move'), PHP_INT_MAX);
    }

    /**
     * Move Bookly's scripts and styles to the end of the page.
     */
    public static function move() {
        if (!class_exists('Bookly\Lib\Plugin', false) || !self::forms_only()) {
            return;
        }
        $scripts = wp_scripts();
        foreach ($scripts->registered as $handle => $script) {
            if (self::bookly($handle, $script->src)) {
                $scripts->add_data($handle, 'group', 1);
            }
        }
        $styles = wp_styles();
        foreach ($styles->queue as $handle) {
            $style = isset($styles->registered[$handle]) ? $styles->registered[$handle] : null;
            if ($style && self::bookly($handle, $style->src)) {
                self::$styles[] = $handle;
                wp_dequeue_style($handle);
            }
        }
        if (self::$styles) {
            // Before wp_print_footer_scripts (priority 20) prints late styles.
            add_action('wp_footer', array(__CLASS__, 'styles'), 1);
        }
    }

    /**
     * Enqueue the styles again, for print_late_styles().
     */
    public static function styles() {
        foreach (self::$styles as $handle) {
            wp_enqueue_style($handle);
        }
    }

    /**
     * Whether a script or style is Bookly's or one of its add-ons'.
     *
     * @param string      $handle Handle.
     * @param string|bool $src    Its address, false for an alias or data only.
     * @return bool
     */
    private static function bookly($handle, $src) {
        return 0 === strpos((string) $handle, 'bookly-')
            || (is_string($src) && false !== strpos($src, '/plugins/bookly'));
    }

    /**
     * Whether the page's posts have Bookly booking forms and no other Bookly
     * shortcode or block.
     *
     * @return bool
     */
    private static function forms_only() {
        global $wp_query;
        if (!$wp_query instanceof WP_Query || !is_array($wp_query->posts)) {
            return false;
        }
        $forms = false;
        foreach ($wp_query->posts as $post) {
            $content = $post instanceof WP_Post ? (string) $post->post_content : '';
            if ('' === $content || false === stripos($content, 'bookly')) {
                continue;
            }
            if (false !== stripos($content, '<!-- wp:bookly/')) {
                return false;
            }
            if (!preg_match_all('/\[(bookly-[a-z0-9_-]+)/i', $content, $codes)) {
                continue;
            }
            foreach ($codes[1] as $code) {
                if (self::FORM !== strtolower($code)) {
                    return false;
                }
            }
            $forms = true;
        }
        return $forms;
    }
}
