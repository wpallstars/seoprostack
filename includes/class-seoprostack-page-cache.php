<?php
/**
 * Purge the page cache once after SEO Pro Stack updates.
 *
 * Cached pages keep the HTML, CSS and JS links of the version that made
 * them, so after an update visitors could get pages without its changes.
 * On each site's first load of a new version, the page caches SEO Pro Stack
 * recommends are purged once, through their own code: LiteSpeed Cache
 * (its litespeed_purge_all hook) and WP-Optimize (its page cache's purge,
 * when the page cache is on). This works however the update was installed:
 * the Plugins screen, automatic updates, WP-CLI or a zip. LiteSpeed Cache
 * also purges on updates made from WordPress itself; this covers the rest,
 * and a purge its own WP-CLI command could not deliver.
 *
 * "Load plugins only where needed" can skip a cache plugin on a request; a
 * cache that is active but not loaded is purged on the next request that
 * loads it. Other page caches can purge on the seoprostack_page_cache_purge
 * action.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.77
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Page_Cache {

    /**
     * The version the page caches were purged for, and the cache plugins
     * still to purge (active, not loaded yet). Autoloaded: read on every
     * request.
     */
    const OPTION = 'seoprostack_page_cache_purged';

    /** Page cache plugins: plugin file => method that purges it. */
    const CACHES = array(
        'litespeed-cache/litespeed-cache.php' => 'purge_litespeed',
        'wp-optimize/wp-optimize.php'         => 'purge_wp_optimize',
    );

    /**
     * Register hooks.
     */
    public static function init() {
        // Every plugin has loaded and set up its hooks.
        add_action('wp_loaded', array(__CLASS__, 'after_update'));
    }

    /**
     * Purge the page caches once for this version.
     */
    public static function after_update() {
        $state = get_option(self::OPTION, array());
        $state = is_array($state) ? $state : array();
        $same  = isset($state['version']) && SEOPROSTACK_VERSION === $state['version'];
        if ($same && empty($state['waiting'])) {
            return;
        }

        $waiting = $same ? (array) $state['waiting'] : array_keys(self::CACHES);
        $left    = array();
        foreach ($waiting as $file) {
            if (!isset(self::CACHES[$file]) || !SEOProStack_Plugin_Loader::is_active($file)) {
                continue;
            }
            $method = self::CACHES[$file];
            if (!self::$method()) {
                $left[] = $file;
            }
        }
        if (!$same) {
            /**
             * Fires once after SEO Pro Stack updates, for page caches
             * SEO Pro Stack does not purge itself.
             *
             * @param string $version The new version.
             */
            do_action('seoprostack_page_cache_purge', SEOPROSTACK_VERSION);
        }
        update_option(self::OPTION, array('version' => SEOPROSTACK_VERSION, 'waiting' => $left), true);
    }

    /**
     * LiteSpeed Cache: purge this site's pages, through its own hook.
     *
     * @return bool False when LiteSpeed Cache is not loaded on this request.
     */
    private static function purge_litespeed() {
        if (!has_action('litespeed_purge_all')) {
            return false;
        }
        do_action('litespeed_purge_all', 'SEO Pro Stack ' . SEOPROSTACK_VERSION); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's hook.
        return true;
    }

    /**
     * WP-Optimize: purge its page cache when it is on, as WP-Optimize does
     * when a plugin is activated.
     *
     * @return bool False when WP-Optimize is not loaded on this request.
     */
    private static function purge_wp_optimize() {
        if (!function_exists('WP_Optimize') || !class_exists('WPO_Page_Cache')) {
            return false;
        }
        $cache = WP_Optimize()->get_page_cache();
        if (is_object($cache) && method_exists($cache, 'is_enabled') && method_exists($cache, 'purge') && $cache->is_enabled()) {
            $cache->purge();
        }
        return true;
    }
}
