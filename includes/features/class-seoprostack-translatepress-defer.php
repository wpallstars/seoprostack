<?php
/**
 * Load TranslatePress's scripts without holding up the page.
 *
 * TranslatePress (checked with 3.3.8) enqueues its language switcher's
 * script (trp-language-switcher-js-v2, assets/js/trp-frontend-language-switcher.js,
 * 16 KB) on every page, and with WooCommerce active a compatibility script
 * (trp-frontend-compatibility, 0.8 KB), both in the head without a loading
 * strategy, so the browser stops reading the page to fetch and run them
 * before its first paint: PageSpeed Insights listed them as render-blocking
 * on every TranslatePress site checked, 120 to 130 ms on desktop and an
 * estimated 430 ms on a phone (GitHub issue #1486).
 *
 * Both only start on DOMContentLoaded, and deferred scripts run, in order,
 * before it fires, so giving them WordPress's defer strategy changes when
 * they are fetched, not what they do. WordPress keeps a script blocking when
 * a blocking script depends on it or it has an inline script after it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.195
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Translatepress_Defer extends SEOProStack_Feature {

    const KEY = 'translatepress_defer';

    /** TranslatePress's scripts that wait for DOMContentLoaded. */
    const SCRIPTS = array('trp-language-switcher-js-v2', 'trp-frontend-compatibility');

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
                'label'       => __('Load TranslatePress’s scripts without holding up the page', 'seoprostack'),
                'description' => __('TranslatePress puts its language switcher’s script, and with WooCommerce a second script, in the head of every page, where the page waits for them before it shows. Both only start once the page has been read, so this loads them with defer: fetched alongside the page and run just before that point. The language switcher works as before. Does nothing without TranslatePress.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('seoprostack_settings_changed', array(__CLASS__, 'settings_changed'), 10, 2);
        if (!self::enabled() || is_admin() || !defined('TRP_PLUGIN_VERSION')) {
            return;
        }
        add_action('wp_enqueue_scripts', array(__CLASS__, 'defer'), PHP_INT_MAX);
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
     * Give TranslatePress's scripts the defer strategy, unless they already
     * have one or this page is left alone.
     */
    public static function defer() {
        if (is_feed() || is_embed() || (function_exists('amp_is_request') && amp_is_request())) {
            return;
        }
        // TranslatePress's translation editor and its preview: logged-in only, left as they are.
        if (isset($_GET['trp-edit-translation'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only read to leave the page alone.
            return;
        }
        /**
         * Whether TranslatePress's head scripts load with defer on this page.
         *
         * @param bool $defer True to defer them.
         */
        if (!apply_filters('seoprostack_translatepress_defer', true)) {
            return;
        }
        $scripts = wp_scripts();
        foreach (self::SCRIPTS as $handle) {
            if (!isset($scripts->registered[$handle]) || !$scripts->registered[$handle]->src
                || in_array($handle, $scripts->done, true) || $scripts->get_data($handle, 'strategy')) {
                continue;
            }
            $scripts->add_data($handle, 'strategy', 'defer');
        }
    }
}
