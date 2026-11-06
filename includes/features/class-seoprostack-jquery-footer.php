<?php
/**
 * Load jQuery at the end of the page when nothing in the head needs it.
 *
 * WordPress registers jquery-core and jquery-migrate in the head, and a
 * script's dependencies print no later than the script, so a footer script
 * that needs jQuery (the Kadence theme's shop spinner with WooCommerce,
 * PropertyHive's search) brings jQuery into the head as a synchronous,
 * render-blocking script. Here jQuery's own place is the footer: WordPress's
 * dependency order still prints it in the head when a head script needs it,
 * and otherwise just before the first footer script that does (GH#1307).
 *
 * Inline code and scripts that do not declare jQuery can use it too, so
 * the page is checked before it is sent. When anything between the head
 * scripts and jQuery's new place might use it (inline code naming jQuery,
 * Google Tag Manager, a script on this site outside WordPress, the Kadence
 * theme, SEO Pro Stack and the plugins it recommends, or a synchronous
 * script from another site), jQuery's tags go back to where WordPress would
 * have printed them.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Jquery_Footer extends SEOProStack_Feature {

    const KEY = 'jquery_footer';

    /** Printed where WordPress prints the head scripts, and removed again. */
    const MARKER = '<!--seoprostack-jquery-->';

    /** jQuery's handles. */
    const HANDLES = array('jquery', 'jquery-core', 'jquery-migrate');

    /** jQuery's tags, with their inline scripts. */
    const TAGS = '#<script\b[^>]*\sid\s*=\s*(["\'])(?:jquery-core|jquery-migrate|jquery)-js(?:-extra|-before|-after|-translations)?\1[^>]*>.*?</script>\s*#is';

    /**
     * Plugins, besides those Used CSS handles (SEOProStack_Used_Css_Links::PLUGINS),
     * whose scripts may come before jQuery: recommended plugins with
     * front-end scripts but no stylesheets, and SEO Pro Stack's sister plugins.
     */
    const PLUGINS = array('burst-statistics', 'seoprocookieconsent', 'seoproanalytics', 'seoproanalytics-connect', 'seoprodirectory');

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
                'label'       => __('Load jQuery at the end of the page', 'seoprostack'),
                'description' => __('WordPress puts jQuery in the head, where the page waits for it, whenever a script needs it, even one at the end of the page such as the Kadence theme’s shop spinner. This prints jQuery just before the first script that needs it instead, unless something in the head does. If anything earlier in the page might use jQuery (inline code, or a script outside WordPress, the Kadence theme and the plugins SEO Pro Stack recommends), jQuery stays where it was. Not for logged-in users or the cart, checkout and account pages.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('seoprostack_settings_changed', array(__CLASS__, 'settings_changed'), 10, 2);
        if (!self::enabled() || is_admin()) {
            return;
        }
        add_action('template_redirect', array(__CLASS__, 'start'), PHP_INT_MAX);
    }

    /**
     * Settings changed: when this feature's did, cached pages were made the
     * other way, so empty the page cache.
     *
     * @param array $old Settings before.
     * @param array $new Settings after.
     */
    public static function settings_changed($old, $new) {
        if (!empty($old[self::KEY]) !== !empty($new[self::KEY])) {
            SEOProStack_Page_Cache::purge_all();
        }
    }

    /**
     * On a visitor's page view: mark the head scripts' place and check the
     * page before it is sent.
     */
    public static function start() {
        if (!self::page_view()) {
            return;
        }
        // After wp_print_styles (8, added by WordPress first), before wp_print_head_scripts (9).
        add_action('wp_head', array(__CLASS__, 'head'), 8);
        ob_start(array(__CLASS__, 'rewrite'));
    }

    /**
     * Whether this is a page a visitor who is not logged in sees as is,
     * other than the cart, checkout and account pages.
     *
     * @return bool
     */
    private static function page_view() {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        if ('GET' !== $method || is_user_logged_in() || is_preview() || is_customize_preview()
            || is_feed() || is_embed() || is_robots() || is_trackback()) {
            return false;
        }
        if (wp_doing_ajax() || wp_is_json_request() || (defined('REST_REQUEST') && REST_REQUEST)
            || (function_exists('amp_is_request') && amp_is_request())) {
            return false;
        }
        if ((function_exists('is_cart') && is_cart())
            || (function_exists('is_checkout') && is_checkout())
            || (function_exists('is_account_page') && is_account_page())) {
            return false;
        }
        /**
         * Whether jQuery may load at the end of this page.
         *
         * @param bool $footer True to let it.
         */
        return (bool) apply_filters('seoprostack_jquery_footer', true);
    }

    /**
     * Before the head scripts: when WordPress's own jQuery is already needed
     * (so it would print here), give it the footer and mark this place.
     */
    public static function head() {
        $scripts = wp_scripts();
        $core    = isset($scripts->registered['jquery-core']) ? $scripts->registered['jquery-core'] : null;
        if (!$core || !is_string($core->src) || false === strpos($core->src, '/wp-includes/js/jquery/')
            || in_array('jquery-core', $scripts->done, true)) {
            return;
        }
        if (!self::needs($scripts, $scripts->queue)) {
            return; // Not needed yet: WordPress prints it where it is first needed.
        }
        foreach (self::HANDLES as $handle) {
            $scripts->add_data($handle, 'group', 1);
        }
        echo self::MARKER . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed HTML comment.
    }

    /**
     * Whether any of these scripts needs jquery-core.
     *
     * @param WP_Scripts $scripts Scripts.
     * @param array      $handles Handles.
     * @param array      $seen    Handles already looked at.
     * @return bool
     */
    private static function needs(WP_Scripts $scripts, array $handles, array &$seen = array()) {
        foreach ($handles as $handle) {
            $handle = explode('?', (string) $handle)[0];
            if (isset($seen[$handle])) {
                continue;
            }
            $seen[$handle] = true;
            if ('jquery-core' === $handle) {
                return true;
            }
            if (isset($scripts->registered[$handle]) && self::needs($scripts, (array) $scripts->registered[$handle]->deps, $seen)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Leave jQuery at the end of the page, or put it back where WordPress
     * would have printed it, and remove the marker.
     *
     * @param string $html Page.
     * @return string
     */
    public static function rewrite($html) {
        $at = strpos($html, self::MARKER);
        if (false === $at) {
            return $html;
        }
        $end   = $at + strlen(self::MARKER);
        $plain = substr_replace($html, '', $at, strlen(self::MARKER));
        if (!preg_match_all(self::TAGS, $html, $found, PREG_OFFSET_CAPTURE, $end)) {
            return $plain; // jQuery was not printed after all, or the check hit a PCRE limit.
        }
        $first    = (int) $found[0][0][1];
        $head_end = stripos($html, '</head>', $end);
        if (false === $head_end || $first < $head_end) {
            return $plain; // A head script needs it, so WordPress kept it there.
        }
        $why = self::blocker(substr($html, $end, $first - $end));
        if ('' === $why) {
            return $plain;
        }
        // Back to the marker, in order, removing the later ones first so offsets hold.
        $tags = '';
        for ($i = count($found[0]) - 1; $i >= 0; $i--) {
            $tag  = (string) $found[0][$i][0];
            $tags = $tag . $tags;
            $html = substr_replace($html, '', (int) $found[0][$i][1], strlen($tag));
        }
        return substr_replace($html, '<!-- SEO Pro Stack: jQuery in the head, as ' . $why . ' -->' . "\n" . $tags, $at, strlen(self::MARKER));
    }

    /**
     * Why jQuery must stay in the head: what in this part of the page, before
     * jQuery's new place, might use it.
     *
     * @param string $html Part of the page.
     * @return string Empty when nothing might.
     */
    private static function blocker($html) {
        if (false === preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $found, PREG_SET_ORDER)) {
            return 'the page could not be checked';
        }
        foreach ($found as $m) {
            $attrs = SEOProStack_Used_Css_Links::attributes('<script' . $m[1] . '>');
            $type  = isset($attrs['type']) ? strtolower(trim($attrs['type'])) : '';
            // Consent managers hold scripts as text/plain and add them later, like async ones.
            $held = 'text/plain' === $type;
            $js   = '' === $type || 'module' === $type || (bool) preg_match('#^(?:text|application)/(?:x-)?(?:java|ecma)script$#', $type);
            if (!$js && !$held) {
                continue; // JSON, templates, speculation rules, and scripts Delay scripts holds until interaction.
            }
            if (!isset($attrs['src']) || '' === trim($attrs['src'])) {
                if (false !== strpos($m[2], 'jQuery')) {
                    return 'an inline script uses it';
                }
                if (false !== stripos($m[2], 'googletagmanager.com/gtm.js')) {
                    return 'Google Tag Manager may use it';
                }
                continue;
            }
            $why = self::blocker_file(trim($attrs['src']), $attrs, $type, $held);
            if ('' !== $why) {
                return $why;
            }
        }
        return '';
    }

    /**
     * Why a script file before jQuery's new place might need it in the head.
     *
     * @param string $src   Address.
     * @param array  $attrs Attributes.
     * @param string $type  Type, lower case.
     * @param bool   $held  Held by a consent manager.
     * @return string Empty when it does not.
     */
    private static function blocker_file($src, array $attrs, $type, $held) {
        if (false !== stripos($src, 'googletagmanager.com/gtm.js')) {
            return 'Google Tag Manager may use it';
        }
        if (!$held && (isset($attrs['defer']) || 'module' === $type)) {
            return ''; // Runs after the page is read, so after jQuery.
        }
        if (SEOProStack_Used_Css_Links::on_site($src)) {
            $file = SEOProStack_Used_Css_Links::script_file($src);
            return '' !== $file && self::supported($file) ? '' : 'a script outside WordPress, the Kadence theme and the recommended plugins comes before it';
        }
        return isset($attrs['async']) || $held ? '' : 'a script from another site comes before it';
    }

    /**
     * Whether a script file is WordPress's, the Kadence theme's, SEO Pro
     * Stack's or a recommended plugin's.
     *
     * @param string $file File (normalised).
     * @return bool
     */
    private static function supported($file) {
        $plugins = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
        $dirs    = array(trailingslashit(wp_normalize_path(ABSPATH . WPINC)), trailingslashit(wp_normalize_path(SEOPROSTACK_DIR)));
        foreach (array_merge(SEOProStack_Used_Css_Links::PLUGINS, self::PLUGINS) as $slug) {
            $dirs[] = $plugins . $slug . '/';
        }
        if (SEOProStack_Used_Css::kadence_theme()) {
            $dirs[] = trailingslashit(wp_normalize_path(get_template_directory()));
            $dirs[] = trailingslashit(wp_normalize_path(get_stylesheet_directory()));
        }
        foreach ($dirs as $dir) {
            if (0 === strpos($file, $dir)) {
                return true;
            }
        }
        return false;
    }
}
