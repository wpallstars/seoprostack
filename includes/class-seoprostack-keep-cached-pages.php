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

    /**
     * Most main pages per site: the home page, the header's and footer's
     * menus and links, the posts page and the home page's other links.
     */
    const MAIN_MAX = 50;

    /** The same, per site, when every site of a network is filled. */
    const NETWORK_MAIN_MAX = 10;
    const NETWORK_MORE_MAX = 25;

    /**
     * Most addresses at once, and most pages of one site where no crawler
     * covers the rest of it.
     */
    const MAX = 1000;

    /**
     * The pages to open on this site, or on every site of the network.
     *
     * @param bool $network Every site of the network.
     * @return string[]
     */
    public static function to_open($network) {
        if ($network && is_multisite()) {
            return self::network(0, 0, 0, 0)['urls'];
        }
        return self::of_site(self::MAIN_MAX, SEOProStack_Keep_Cached_Requests::covered() ? self::MAIN_MAX : self::MAX, true);
    }

    /**
     * Plan network sites until the batch budget or queue capacity is reached.
     *
     * @param bool  $network Every site of the network.
     * @param int   $site    Network site ID to plan next.
     * @param float $start   Batch start time.
     * @param int   $budget  Batch time budget.
     * @param int   $waiting Addresses already waiting.
     * @return array{urls:string[],blogs:array<string,int>,site:int,done:bool}
     */
    public static function plan_network($network, $site, $start, $budget, $waiting) {
        if ($network && is_multisite()) {
            return self::network($site, $start, $budget, $waiting);
        }
        return array('urls' => self::to_open(false), 'blogs' => array(), 'site' => 0, 'done' => true);
    }

    /**
     * This site's pages to open, in the order visitors reach them: the home
     * page, the header's menus and links, the footer's, the posts page and
     * the home page's other links; then the rest of the site (pages, posts,
     * then other public types, newest first).
     *
     * @param int  $main      Most main pages.
     * @param int  $total     Most pages in all.
     * @param bool $read_home Read the home page for its links (not on a network fill).
     * @return string[]
     */
    private static function of_site($main, $total, $read_home) {
        $home       = home_url('/');
        $menus      = self::menu_urls();
        $links      = $read_home ? self::page_links($home) : array('header' => array(), 'footer' => array(), 'other' => array());
        $posts_page = (int) get_option('page_for_posts');
        $posts      = $posts_page && 'page' === get_option('show_on_front') ? array((string) get_permalink($posts_page)) : array();
        $urls       = self::clean(array_merge(array($home), $menus['header'], $links['header'], $menus['footer'], $links['footer'], $posts, $links['other']), $main);
        if (count($urls) >= $total) {
            return $urls;
        }
        return self::clean(array_merge($urls, self::rest_urls($total)), $total);
    }

    /**
     * The rest of the site: published pages, then posts, then other public
     * post types, newest first. One query per type keeps to core's
     * type_status_date index.
     *
     * @param int $most Most addresses (main pages among them are left out by clean()).
     * @return string[]
     */
    private static function rest_urls($most) {
        $urls  = array();
        $types = array_values(array_filter(array_diff(get_post_types(array('public' => true)), array('attachment')), 'is_post_type_viewable'));
        $types = array_values(array_unique(array_merge(array_intersect(array('page', 'post'), $types), $types)));
        foreach ($types as $type) {
            $left = $most - count($urls);
            if ($left <= 0) {
                break;
            }
            foreach (get_posts(array(
                'post_type'              => $type,
                'post_status'            => 'publish',
                'has_password'           => false,
                'posts_per_page'         => $left,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )) as $post) {
                $urls[] = (string) get_permalink($post);
            }
        }
        return $urls;
    }

    /**
     * Addresses in the menus assigned to the theme's menu locations, the
     * header's first: a location whose name has "footer" in it is the
     * footer's, any other the header's (Kadence: primary, secondary and
     * mobile, then footer). Locations go in the order the theme registers
     * them; a menu in two locations counts once, in the first.
     *
     * @return array{header:string[],footer:string[]}
     */
    private static function menu_urls() {
        $urls      = array('header' => array(), 'footer' => array());
        $locations = array_map('intval', (array) get_nav_menu_locations());
        $order     = array_unique(array_merge(array_keys((array) get_registered_nav_menus()), array_keys($locations)));
        $seen      = array();
        foreach ($order as $location) {
            $menu = isset($locations[$location]) ? $locations[$location] : 0;
            if ($menu <= 0 || isset($seen[$menu])) {
                continue;
            }
            $seen[$menu] = true;
            $part        = false !== stripos((string) $location, 'footer') ? 'footer' : 'header';
            foreach ((array) wp_get_nav_menu_items($menu, array('update_post_term_cache' => false)) as $item) {
                if (is_object($item) && !empty($item->url)) {
                    $urls[$part][] = (string) $item->url;
                }
            }
        }
        return $urls;
    }

    /**
     * The links in a page's HTML, in document order: those in the site's
     * header (Kadence's #masthead or .site-header, a block theme's header
     * template part), those in its footer (#colophon, .site-footer, the
     * footer template part) and the others. Addresses are made absolute;
     * clean() keeps only this site's pages.
     *
     * @param string $url Page address.
     * @return array{header:string[],footer:string[],other:string[]}
     */
    private static function page_links($url) {
        $links = array('header' => array(), 'footer' => array(), 'other' => array());
        $html  = SEOProStack_Keep_Cached_Requests::page_html($url);
        if ('' === $html || !class_exists('WP_HTML_Tag_Processor')) {
            return $links;
        }
        $tags   = new WP_HTML_Tag_Processor($html);
        $part   = 'other';
        $region = '';
        $depth  = 0;
        while ($tags->next_tag(array('tag_closers' => 'visit'))) {
            $tag    = (string) $tags->get_tag();
            $closer = $tags->is_tag_closer();
            if ('' !== $region && $tag === $region) {
                // A header inside the site's header (rare) closes before it.
                $depth += $closer ? -1 : 1;
                if ($depth <= 0) {
                    $part   = 'other';
                    $region = '';
                }
                continue;
            }
            if ($closer) {
                continue;
            }
            if ('' === $region && ('HEADER' === $tag || 'FOOTER' === $tag) && self::site_part($tags)) {
                $part   = 'HEADER' === $tag ? 'header' : 'footer';
                $region = $tag;
                $depth  = 1;
            } elseif ('A' === $tag) {
                $href = $tags->get_attribute('href');
                if (is_string($href) && '' !== trim($href)) {
                    $links[$part][] = WP_Http::make_absolute_url(trim($href), $url);
                }
            }
        }
        return $links;
    }

    /**
     * Whether a header or footer element is the site's own, not a post's.
     *
     * @param WP_HTML_Tag_Processor $tags At a header or footer opener.
     * @return bool
     */
    private static function site_part(WP_HTML_Tag_Processor $tags) {
        if (in_array($tags->get_attribute('id'), array('masthead', 'colophon'), true)) {
            return true;
        }
        $class = $tags->get_attribute('class');
        $names = is_string($class) ? preg_split('/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY) : array();
        return (bool) array_intersect(array('site-header', 'site-footer', 'wp-block-template-part'), (array) $names);
    }

    /**
     * Pages of every site of the network where Keep pages cached is on and
     * a page cache is kept, each site's worked out on that site.
     *
     * @param int   $site    Network site ID to plan next.
     * @param float $start   Batch start time.
     * @param int   $budget  Batch time budget.
     * @param int   $waiting Addresses already waiting.
     * @return array{urls:string[],blogs:array<string,int>,site:int,done:bool}
     */
    private static function network($site, $start, $budget, $waiting) {
        $urls  = array();
        $blogs = array();
        while (self::NETWORK_MORE_MAX <= self::MAX - $waiting - count($urls) && (!$start || microtime(true) - $start < $budget)) {
            $id = self::next_site($site);
            if (!$id) {
                self::rewrite_init();
                return array('urls' => $urls, 'blogs' => $blogs, 'site' => 0, 'done' => true);
            }
            switch_to_blog($id);
            self::rewrite_init();
            if (self::fills_here()) {
                foreach (self::of_site(self::NETWORK_MAIN_MAX, self::NETWORK_MORE_MAX, false) as $url) {
                    $urls[]       = $url;
                    $blogs[$url] = $id;
                }
            }
            restore_current_blog();
            $site = self::next_site($id + 1);
            if (!$site) {
                self::rewrite_init();
                return array('urls' => $urls, 'blogs' => $blogs, 'site' => 0, 'done' => true);
            }
        }
        self::rewrite_init();
        return array('urls' => $urls, 'blogs' => $blogs, 'site' => $site, 'done' => false);
    }

    /**
     * The next active site in this network after an ID.
     *
     * @param int $after Site ID to start at or after.
     * @return int
     */
    private static function next_site($after) {
        global $wpdb;
        $cursor = static function ($clauses) use ($after, $wpdb) {
            $clauses['where'] .= $wpdb->prepare(' AND blog_id >= %d', $after);
            return $clauses;
        };
        add_filter('sites_clauses', $cursor);
        $sites = get_sites(array('fields' => 'ids', 'number' => 1, 'orderby' => 'id', 'order' => 'ASC', 'archived' => 0, 'deleted' => 0, 'spam' => 0));
        remove_filter('sites_clauses', $cursor);
        return $sites ? (int) $sites[0] : 0;
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
        // Files are skipped; .html and .htm stay, as permalinks can end in them.
        return !preg_match('#/(wp-admin|wp-login\.php|feed)(/|$)#', $path) && !preg_match('/\.(?!html?$)[a-z0-9]{2,5}$/i', $path);
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
