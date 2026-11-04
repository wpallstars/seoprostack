<?php
/**
 * Keep pages cached: which pages to open (see SEOProStack_Keep_Pages_Cached).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.145
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Keep_Cached_Pages {

    /** Most main pages (home, posts page, menus) per site. */
    const MAIN_MAX = 20;

    /** Most pages per site with the newest pages and posts. */
    const MORE_MAX = 50;

    /** The same, per site, when every site of a network is filled. */
    const NETWORK_MAIN_MAX = 10;
    const NETWORK_MORE_MAX = 25;

    /** Most sites filled on a network. */
    const NETWORK_SITES = 200;

    /** Most addresses at once. */
    const MAX = 1000;

    /**
     * The pages to open on this site, or on every site of the network.
     *
     * @param bool $network Every site of the network.
     * @return string[]
     */
    public static function to_open($network) {
        if ($network && is_multisite()) {
            return self::network();
        }
        return self::of_site(self::MAIN_MAX, SEOProStack_Keep_Cached_Requests::covered() ? self::MAIN_MAX : self::MORE_MAX);
    }

    /**
     * This site's pages to open: the home page, the posts page and the
     * pages in the theme's menus, then the newest pages and posts.
     *
     * @param int $main  Most main pages.
     * @param int $total Most pages in all.
     * @return string[]
     */
    private static function of_site($main, $total) {
        $urls       = array(home_url('/'));
        $posts_page = (int) get_option('page_for_posts');
        if ($posts_page && 'page' === get_option('show_on_front')) {
            $urls[] = (string) get_permalink($posts_page);
        }
        $urls = self::clean(array_merge($urls, self::menu_urls()), $main);
        if (count($urls) >= $total) {
            return $urls;
        }
        // One query per type keeps to core's type_status_date index.
        $each = (int) ceil(($total - count($urls)) / 2);
        foreach (array('page', 'post') as $type) {
            $urls = array_merge($urls, array_map('strval', array_map('get_permalink', get_posts(array(
                'post_type'              => $type,
                'post_status'            => 'publish',
                'has_password'           => false,
                'posts_per_page'         => $each,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )))));
        }
        return self::clean($urls, $total);
    }

    /**
     * Addresses in the menus assigned to the theme's menu locations.
     *
     * @return string[]
     */
    private static function menu_urls() {
        $urls  = array();
        $menus = array_unique(array_filter(array_map('intval', (array) get_nav_menu_locations())));
        foreach ($menus as $menu) {
            foreach ((array) wp_get_nav_menu_items($menu, array('update_post_term_cache' => false)) as $item) {
                if (is_object($item) && !empty($item->url)) {
                    $urls[] = (string) $item->url;
                }
            }
        }
        return $urls;
    }

    /**
     * Pages of every site of the network where Keep pages cached is on and
     * a page cache is kept, each site's worked out on that site.
     *
     * @return string[]
     */
    private static function network() {
        $urls = array();
        foreach (get_sites(array('fields' => 'ids', 'number' => self::NETWORK_SITES, 'archived' => 0, 'deleted' => 0, 'spam' => 0)) as $id) {
            if (count($urls) >= self::MAX) {
                break;
            }
            switch_to_blog((int) $id);
            self::rewrite_init();
            if (self::fills_here()) {
                $urls = array_merge($urls, self::of_site(self::NETWORK_MAIN_MAX, self::NETWORK_MORE_MAX));
            }
            restore_current_blog();
        }
        self::rewrite_init();
        return $urls;
    }

    /**
     * Whether the current site of a network runs SEO Pro Stack with Keep
     * pages cached on, and keeps a page cache.
     *
     * @return bool
     */
    private static function fills_here() {
        return SEOProStack_Plugin_Loader::is_active(plugin_basename(SEOPROSTACK_FILE))
            && SEOProStack_Keep_Pages_Cached::switched_on()
            && '' !== SEOProStack_Keep_Cached_Requests::page_cache();
    }

    /**
     * Rebuild permalinks for the current site: switch_to_blog() keeps the
     * first site's permalink structure, which other sites may not share.
     */
    private static function rewrite_init() {
        global $wp_rewrite;
        if ($wp_rewrite instanceof WP_Rewrite) {
            $wp_rewrite->init();
        }
    }

    /**
     * Only this site's own pages that a page cache keeps: no query strings,
     * files, feeds, admin or login, nor WooCommerce's basket, checkout and
     * account pages. In order, without repeats.
     *
     * @param string[] $urls Addresses.
     * @param int      $max  Most kept.
     * @return string[]
     */
    public static function clean(array $urls, $max) {
        $host  = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $skip  = self::uncached_pages();
        $clean = array();
        foreach ($urls as $url) {
            $url = explode('#', (string) $url)[0];
            if (self::cacheable($url, $host, $skip)) {
                $clean[$url] = true;
            }
            if (count($clean) >= $max) {
                break;
            }
        }
        return array_keys($clean);
    }

    /**
     * Whether an address is a page of this site that a page cache keeps.
     *
     * @param string             $url  Address, without a fragment.
     * @param string             $host This site's host, in lower case.
     * @param array<string,true> $skip From uncached_pages().
     * @return bool
     */
    private static function cacheable($url, $host, array $skip) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !self::same_site($parts, $host) || isset($skip[untrailingslashit($url)])) {
            return false;
        }
        $path = isset($parts['path']) ? $parts['path'] : '/';
        return !preg_match('#/(wp-admin|wp-login\.php|feed)(/|$)#', $path) && !preg_match('/\.[a-z0-9]{2,5}$/i', $path);
    }

    /**
     * Whether a parsed address is on this site, over HTTP(S), without a query.
     *
     * @param array  $parts From wp_parse_url().
     * @param string $host  This site's host, in lower case.
     * @return bool
     */
    private static function same_site(array $parts, $host) {
        $parts += array('host' => '', 'scheme' => '', 'query' => '');
        return '' !== $host && strtolower((string) $parts['host']) === $host && '' === (string) $parts['query'] && in_array($parts['scheme'], array('http', 'https'), true);
    }

    /**
     * WooCommerce's basket, checkout and account pages, which page caches
     * leave out.
     *
     * @return array<string,true> Address without the trailing slash => true.
     */
    private static function uncached_pages() {
        $skip = array();
        if (!function_exists('wc_get_page_id')) {
            return $skip;
        }
        foreach (array('cart', 'checkout', 'myaccount') as $page) {
            $id = (int) wc_get_page_id($page);
            if ($id > 0) {
                $skip[untrailingslashit((string) get_permalink($id))] = true;
            }
        }
        return $skip;
    }
}
