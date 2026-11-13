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
     * menus and links, the home page's other links and the posts page.
     * Sites with a large header menu and a home page of links have 80 to
     * 100; at 50, a home page's own links were left to LiteSpeed Cache's
     * crawler, which goes in sitemap order and took most of an hour to
     * reach them (GitHub issue #1363).
     */
    const MAIN_MAX = 150;

    /**
     * Where a crawler or preload covers the rest: the newest pages and posts
     * are added until there are this many in all. Main pages are all kept,
     * even when there are more of them.
     */
    const COVERED_MAX = 50;

    /**
     * A network fill's first round, per site: the home page and the first
     * header links, so every site gets them in one round (#1240).
     */
    const NETWORK_MAIN_MAX = 10;

    /**
     * Its second round, per site: the rest of the main pages, read from the
     * site's home page as on a single site, and the newest pages and posts
     * up to this many in all.
     */
    const NETWORK_MORE_MAX = 25;

    /**
     * Most addresses at once, and most pages of one site where no crawler
     * covers the rest of it.
     */
    const MAX = 1000;

    /** TranslatePress's main file and settings option (translations()). */
    const TRP_FILE   = 'translatepress-multilingual/index.php';
    const TRP_OPTION = 'trp_settings';

    /**
     * The pages to open on this site, or on every site of the network.
     *
     * @param bool $network Every site of the network.
     * @return string[]
     */
    public static function to_open($network) {
        if ($network && is_multisite()) {
            $first = self::network(0, 0, 0, 0, 1)['urls'];
            return array_merge($first, self::network(0, 0, 0, count($first), 2)['urls']);
        }
        return self::of_site(self::MAIN_MAX, self::site_max(), true);
    }

    /**
     * Most pages in all for this site's fill; its main pages are always
     * kept (up to MAIN_MAX). Where a crawler or preload covers the rest,
     * COVERED_MAX, else MAX. A site that asks search engines not to index
     * it (Settings, Reading; a staging or review copy) gets only its main
     * pages: no crawler needs the rest, and people reviewing it start
     * there, while up to 1,000 pages each on many such sites kept one
     * hosting account at its CPU limit (#1471).
     *
     * @return int
     */
    private static function site_max() {
        $max = SEOProStack_Keep_Cached_Requests::covered() ? self::COVERED_MAX : self::MAX;
        if ('0' === (string) get_option('blog_public')) {
            $max = 0;
        }
        /**
         * Most pages one site's fill opens in all; its main pages (the home
         * page, header, footer and home page links, up to 150) are always
         * opened. 0 opens only those.
         *
         * @param int $max 1000; 50 where LiteSpeed Cache's crawler or
         *                 WP-Optimize's preload covers the rest; 0 where
         *                 search engines are asked not to index the site.
         */
        $max = (int) apply_filters('seoprostack_keep_cached_max', $max);
        return max(0, min(self::MAX, $max));
    }

    /**
     * Plan network sites until the batch budget or queue capacity is reached.
     *
     * @param bool  $network Every site of the network.
     * @param int   $site    Network site ID to plan next.
     * @param float $start   Batch start time.
     * @param int   $budget  Batch time budget.
     * @param int   $waiting Addresses already waiting.
     * @param int   $round   Network fill round: 1 (first pages) or 2 (the rest).
     * @return array{urls:string[],blogs:array<string,int>,site:int,round:int,done:bool}
     */
    public static function plan_network($network, $site, $start, $budget, $waiting, $round = 1) {
        if ($network && is_multisite()) {
            return self::network($site, $start, $budget, $waiting, $round);
        }
        return array('urls' => self::to_open(false), 'blogs' => array(), 'site' => 0, 'round' => 1, 'done' => true);
    }

    /**
     * This site's pages to open, in the order visitors reach them: the home
     * page, the header's menus and links, the footer's, the home page's
     * other links and the posts page; then the same pages in TranslatePress's
     * other languages (translations()); then the rest of the site (pages,
     * posts, then other public types, newest first).
     *
     * @param int  $main  Most main pages, in the site's own language.
     * @param int  $total Most pages in all, in the site's own language.
     * @param bool $full  Read the home page for its links and add the
     *                    translations (not in a network fill's first round).
     * @return string[]
     */
    private static function of_site($main, $total, $full) {
        $home       = home_url('/');
        $menus      = self::menu_urls();
        $links      = $full ? self::page_links($home) : array('header' => array(), 'footer' => array(), 'other' => array());
        $posts_page = (int) get_option('page_for_posts');
        $posts      = $posts_page && 'page' === get_option('show_on_front') ? array((string) get_permalink($posts_page)) : array();
        $urls       = self::clean(array_merge(array($home), $menus['header'], $links['header'], $menus['footer'], $links['footer'], $links['other'], $posts), $main);
        $site       = count($urls) >= $total ? $urls : self::clean(array_merge($urls, self::rest_urls($total)), $total);
        if (!$full) {
            return $site;
        }
        // Every language's main pages before the rest of the site, which stays in the site's own language.
        return self::clean(array_merge($urls, self::translations($urls), $site), self::MAX);
    }

    /**
     * These pages, then the same pages in TranslatePress's other languages
     * (a post's own pages after it is saved: LiteSpeed Cache purges them by
     * the same tags in every language).
     *
     * @param string[] $urls Addresses, already cleaned.
     * @return string[]
     */
    public static function with_translations(array $urls) {
        return self::clean(array_merge($urls, self::translations($urls)), self::MAX);
    }

    /**
     * The same pages in TranslatePress's other published languages, which
     * it serves under each language's slug (/en/, /fr/) and page caches
     * keep apart: each page in every language before the next page, so
     * every language's home page and header pages come first. Nothing
     * without TranslatePress active on the current site.
     *
     * Addresses come from TranslatePress's own URL converter (it follows
     * SEO Pack's translated slugs) where it runs for the current site;
     * a network site planned from another site, where TranslatePress holds
     * that site's settings, gets them from its own settings instead, as the
     * converter makes them for a page without translated slugs.
     *
     * @param string[] $urls Addresses in the site's own language, already cleaned.
     * @return string[]
     */
    private static function translations(array $urls) {
        $languages = self::languages();
        if (!$languages['publish'] || !$urls) {
            return array();
        }
        $converter = self::trp_converter();
        $out       = array();
        foreach ($urls as $url) {
            foreach ($languages['publish'] as $code => $slug) {
                $translated = $converter ? self::trp_url($converter, $code, $url) : '';
                $out[]      = '' !== $translated ? $translated : self::language_url($url, $slug, $languages['slugs']);
            }
        }
        return $out;
    }

    /**
     * TranslatePress's languages on the current site, read from its
     * settings as it stores them.
     *
     * @return array{publish:array<string,string>,slugs:string[]} Published
     *         languages other than the default (the default too when its
     *         addresses have a slug) => slug; every language's slug.
     */
    private static function languages() {
        $none = array('publish' => array(), 'slugs' => array());
        if (!SEOProStack_Plugin_Loader::is_active(self::TRP_FILE)) {
            return $none;
        }
        $settings = get_option(self::TRP_OPTION);
        if (!is_array($settings) || empty($settings['publish-languages']) || empty($settings['url-slugs']) || !is_array($settings['url-slugs'])) {
            return $none;
        }
        $slugs   = array_filter(array_map(static function ($slug) {
            return trim((string) $slug, '/');
        }, $settings['url-slugs']), static function ($slug) {
            return '' !== $slug;
        });
        $default = isset($settings['default-language']) ? (string) $settings['default-language'] : '';
        // With "Use a subdirectory for the default language", the default's pages have a slug too.
        $own     = isset($settings['add-subdirectory-to-default-language']) && 'yes' === $settings['add-subdirectory-to-default-language'];
        $publish = array();
        foreach ((array) $settings['publish-languages'] as $code) {
            $code = (string) $code;
            if (isset($slugs[$code]) && ($own || $code !== $default)) {
                $publish[$code] = $slugs[$code];
            }
        }
        return array('publish' => $publish, 'slugs' => array_values($slugs));
    }

    /**
     * TranslatePress's URL converter, where it runs for the current site:
     * not while switched to another site of the network, as TranslatePress
     * keeps the settings of the site the request began on.
     *
     * @return callable|null Its get_url_for_language().
     */
    private static function trp_converter() {
        if (!class_exists('TRP_Translate_Press', false) || !is_callable(array('TRP_Translate_Press', 'get_trp_instance'))) {
            return null;
        }
        if (is_multisite() && ms_is_switched() && (int) reset($GLOBALS['_wp_switched_stack']) !== get_current_blog_id()) {
            return null;
        }
        $trp       = TRP_Translate_Press::get_trp_instance();
        $converter = is_object($trp) && method_exists($trp, 'get_component') ? $trp->get_component('url_converter') : null;
        $callback  = array($converter, 'get_url_for_language');
        return is_object($converter) && is_callable($callback) ? $callback : null;
    }

    /**
     * A page's address in a language, through TranslatePress's converter.
     *
     * @param callable $converter From trp_converter().
     * @param string   $code      Language code (fr_FR).
     * @param string   $url       Address.
     * @return string '' when it gives none.
     */
    private static function trp_url($converter, $code, $url) {
        // '': without the "already processed" marker it adds for its own page output.
        $translated = call_user_func($converter, $code, $url, '');
        return is_string($translated) ? explode('#', $translated)[0] : '';
    }

    /**
     * A page's address in a language, made as TranslatePress's converter
     * makes it for a page without translated slugs: the language's slug
     * after the home address, in place of another language's slug, with a
     * trailing slash.
     *
     * @param string   $url   Address.
     * @param string   $slug  The language's slug.
     * @param string[] $slugs Every language's slug.
     * @return string '' for an address outside the home address.
     */
    private static function language_url($url, $slug, array $slugs) {
        $home  = wp_parse_url(home_url('/'));
        $parts = wp_parse_url($url);
        if (!is_array($home) || !is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $base = trailingslashit(isset($home['path']) ? (string) $home['path'] : '/');
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        if (0 !== strpos(trailingslashit($path), $base)) {
            return '';
        }
        $rest  = ltrim((string) substr($path, strlen($base)), '/');
        $first = explode('/', $rest)[0];
        if ('' !== $first && in_array($first, $slugs, true)) {
            $rest = ltrim((string) substr($rest, strlen($first)), '/');
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'] . $port . trailingslashit($base . $slug . ('' !== $rest ? '/' . $rest : ''));
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
            $ids = get_posts(array(
                'post_type'              => $type,
                'post_status'            => 'publish',
                'has_password'           => false,
                'posts_per_page'         => $left,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ));
            foreach (array_chunk(array_map('intval', $ids), 200) as $chunk) {
                $urls = array_merge($urls, self::permalinks($chunk));
            }
        }
        return $urls;
    }

    /**
     * Permalinks of published posts, from the columns permalinks use: up to
     * 1,000 posts' content is not loaded into memory or the object cache.
     *
     * @param int[] $ids Post IDs, in order.
     * @return string[]
     */
    private static function permalinks(array $ids) {
        global $wpdb;
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- primary-key lookup of the columns permalinks read, once per fill; $in holds one placeholder per ID.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_author, post_date, post_date_gmt, post_status, post_name, post_parent, post_type, menu_order FROM {$wpdb->posts} WHERE ID IN ($in)", $ids), OBJECT_K);
        $urls = array();
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $row              = $rows[$id];
                $row->ID          = (int) $row->ID;
                $row->post_parent = (int) $row->post_parent;
                $row->menu_order  = (int) $row->menu_order;
                // As loaded: get_post() then uses this object as it is.
                $row->filter      = 'raw';
                $urls[]           = (string) get_permalink(new WP_Post($row));
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
     * a page cache is kept, each site's worked out on that site, in two
     * rounds: first every site's home page and first header links, then
     * each site's other main pages (its home page read for its links, as on
     * a single site) and newest pages and posts. In one round, the sites at
     * the end of a large network waited for every other site's whole set
     * (#1240); with only the first round, a network site's footer, most of
     * a large header and its home page's links waited for the crawler
     * (#1363). The second round also adds each site's main pages in
     * TranslatePress's other languages; a site whose list no longer fits
     * the queue waits for a later batch rather than being cut (#1593).
     *
     * @param int   $site    Network site ID to plan next.
     * @param float $start   Batch start time.
     * @param int   $budget  Batch time budget.
     * @param int   $waiting Addresses already waiting.
     * @param int   $round   1 or 2.
     * @return array{urls:string[],blogs:array<string,int>,site:int,round:int,done:bool}
     */
    private static function network($site, $start, $budget, $waiting, $round) {
        $round = 2 === (int) $round ? 2 : 1;
        // A site's whole list must fit, as the queue keeps only MAX addresses.
        $most  = 1 === $round ? self::NETWORK_MAIN_MAX : max(self::MAIN_MAX, self::NETWORK_MORE_MAX);
        $urls  = array();
        $blogs = array();
        while ($most <= self::MAX - $waiting - count($urls) && (!$start || microtime(true) - $start < $budget)) {
            $id = self::next_site($site);
            if (!$id) {
                return self::network_end($urls, $blogs, $round);
            }
            $more = self::network_site($id, $round);
            if ($urls && count($urls) + count($more) > self::MAX - $waiting) {
                // Translations can make a site's list longer than $most: plan it again in a later batch, whole.
                $site = $id;
                break;
            }
            foreach ($more as $url) {
                $urls[]       = $url;
                $blogs[$url] = $id;
            }
            $site = self::next_site($id + 1);
            if (!$site) {
                return self::network_end($urls, $blogs, $round);
            }
        }
        self::rewrite_init();
        return array('urls' => $urls, 'blogs' => $blogs, 'site' => $site, 'round' => $round, 'done' => false);
    }

    /**
     * One network site's pages for a round, worked out on that site: none
     * when Keep pages cached is off there or no page cache is kept. The
     * first round is in the site's own language only.
     *
     * @param int $id    Site ID.
     * @param int $round 1 (home page and first header links) or 2 (the rest).
     * @return string[]
     */
    private static function network_site($id, $round) {
        $urls = array();
        switch_to_blog($id);
        self::rewrite_init();
        if (self::fills_here()) {
            $first = self::of_site(self::NETWORK_MAIN_MAX, self::NETWORK_MAIN_MAX, false);
            // A site hidden from search engines: its main pages only, as site_max().
            $more  = '0' === (string) get_option('blog_public') ? 0 : self::NETWORK_MORE_MAX;
            $urls  = 1 === $round ? $first : array_values(array_diff(self::of_site(self::MAIN_MAX, $more, true), $first));
        }
        restore_current_blog();
        return $urls;
    }

    /**
     * A network round reached the last site: the second round starts at the
     * first site, or the fill is planned.
     *
     * @param string[]           $urls  Addresses planned.
     * @param array<string, int> $blogs Address => site ID.
     * @param int                $round The round that ended.
     * @return array{urls:string[],blogs:array<string,int>,site:int,round:int,done:bool}
     */
    private static function network_end(array $urls, array $blogs, $round) {
        self::rewrite_init();
        $done = 2 === $round;
        return array('urls' => $urls, 'blogs' => $blogs, 'site' => 0, 'round' => $done ? 1 : 2, 'done' => $done);
    }

    /**
     * The next active site in this network at or after an ID.
     *
     * The network's active site IDs come from one get_sites() query, which
     * WordPress caches by its arguments and refreshes when a site changes.
     * Before, a sites_clauses filter narrowed a query for one site, but the
     * filter runs only when the cache misses, so every call after the first
     * got the first call's site and a network fill planned only that site
     * (GitHub issue #1238).
     *
     * @param int $after Site ID to start at or after.
     * @return int 0 when there is none.
     */
    private static function next_site($after) {
        $ids = get_sites(array('fields' => 'ids', 'number' => 0, 'network_id' => get_current_network_id(), 'orderby' => 'id', 'order' => 'ASC', 'archived' => 0, 'deleted' => 0, 'spam' => 0));
        foreach ($ids as $id) {
            if ((int) $id >= $after) {
                return (int) $id;
            }
        }
        return 0;
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
