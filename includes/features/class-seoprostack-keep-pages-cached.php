<?php
/**
 * Keep pages cached: fill the page cache again after it is emptied.
 *
 * Every plugin, theme or core update empties the page cache (LiteSpeed
 * Cache's Purge All On Upgrade, WP-Optimize's purge on updates, SEO Pro
 * Stack's own purge after its updates), and so do Purge All and menu or
 * Customizer saves. Until someone opens each page again, visitors wait for
 * WordPress to build it, which on a busy development day is most of the day.
 *
 * After the cache is emptied, this opens the site's main pages in WP-Cron,
 * one at a time, as a visitor who is not logged in, so the cache holds them
 * again within minutes:
 * - the home page, the posts page and the pages in the theme's menus;
 * - the newest pages and posts too, where no crawler covers the rest;
 * - then LiteSpeed Cache's crawler, where it is on, as its own Crawl now
 *   does: after a finished crawl it otherwise waits for its next full
 *   crawl, up to a day away (SEOProStack_Litespeed_Crawler);
 * - and WP-Optimize's own preload, as its Purge button schedules it, where
 *   its preload after purge or scheduled preload is on.
 * After a post is published, updated or unpublished, its page and the home
 * page are opened again a minute later (LiteSpeed Cache; WP-Optimize does
 * that itself with preload after purge on).
 *
 * On a network, a purge of every site (Network Admin, updates, as LiteSpeed
 * Cache decides it; any WP-Optimize purge) fills each site's main pages,
 * from the site where it happened.
 *
 * Batches run for about 20 seconds, a minute apart. Where the page cache
 * keeps a separate copy for phones, pages are opened as a phone too, and
 * requests go straight to this server when its Server IP is set.
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

class SEOProStack_Keep_Pages_Cached extends SEOProStack_Feature {

    const KEY = 'keep_pages_cached';

    /** WP-Cron hook for each batch. */
    const HOOK = 'seoprostack_keep_pages_cached';

    /** Pages waiting to be opened, and what to work out first. Not autoloaded. */
    const QUEUE = 'seoprostack_keep_pages_cached_queue';

    /** When the last fill finished and how many pages it opened. Not autoloaded. */
    const LAST = 'seoprostack_keep_pages_cached_last';

    /** Seconds after a purge: LiteSpeed applies it when the purging request ends. */
    const DELAY = 90;

    /** Most seconds purges one after another move the fill back. */
    const WAIT = 600;

    /** Seconds after a post is saved. */
    const POST_DELAY = 60;

    /** Seconds between batches. */
    const GAP = 60;

    /** Seconds a batch may run. */
    const BUDGET = 20;

    /** Seconds each page may take. */
    const TIMEOUT = 15;

    /** Most main pages (home, posts page, menus) per site. */
    const MAIN_MAX = 20;

    /** Most pages per site with the newest pages and posts. */
    const MORE_MAX = 50;

    /** The same, per site, when every site of a network is filled. */
    const NETWORK_MAIN_MAX = 10;
    const NETWORK_MORE_MAX = 25;

    /** Most sites filled on a network. */
    const NETWORK_SITES = 200;

    /** Most addresses waiting at once. */
    const QUEUE_MAX = 1000;

    /** As a desktop browser, and as a phone (the cache's mobile copy). */
    const AGENT  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 SEOProStack-KeepCached';
    const MOBILE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 SEOProStack-KeepCached';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request (#1192): it only opens the site's own pages, in WP-Cron, one at a time.
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Keep pages cached', 'seoprostack'),
                'description' => __('After the page cache is emptied (updates, Purge All, menu or Customizer changes) or a post is published, open the home page, the menu pages and the newest posts in the background, one at a time, so visitors get cached pages again within minutes instead of waiting for each page to be built. Where LiteSpeed Cache’s crawler or WP-Optimize’s preload is on, it is started for the rest of the site.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('seoprostack keep-cached', array(__CLASS__, 'cli'), array(
                'shortdesc' => 'Fill the page cache now: open the pages Keep pages cached would, and show how each answered.',
                'synopsis'  => array(
                    array(
                        'type'        => 'flag',
                        'name'        => 'network',
                        'optional'    => true,
                        'description' => 'Every site of the network.',
                    ),
                    array(
                        'type'        => 'flag',
                        'name'        => 'dry-run',
                        'optional'    => true,
                        'description' => 'List the pages without opening them.',
                    ),
                ),
            ));
        }
        if (!self::enabled()) {
            return;
        }
        add_action('litespeed_purged_all', array(__CLASS__, 'litespeed_purged'));
        add_action('wpo_cache_flush', array(__CLASS__, 'wp_optimize_purged'));
        add_action('transition_post_status', array(__CLASS__, 'post_changed'), 10, 3);
    }

    /**
     * Plugin deactivated: nothing left waiting.
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        unset($network_wide);
        wp_clear_scheduled_hook(self::HOOK);
        delete_option(self::QUEUE);
    }

    /**
     * LiteSpeed Cache emptied the page cache (Purge All).
     */
    public static function litespeed_purged() {
        self::fill_later(self::network_purge());
    }

    /**
     * WP-Optimize emptied its page cache: start its own preload where it
     * has one on, as its Purge button does, and fill the main pages. Its
     * cache folder holds every site of a network.
     */
    public static function wp_optimize_purged() {
        if (self::wp_optimize_preloads() && !wp_next_scheduled('wpo_page_cache_run_preload')) {
            wp_schedule_single_event(time() + 30, 'wpo_page_cache_run_preload');
        }
        self::fill_later(is_multisite());
    }

    /**
     * A post was published, updated or unpublished: open its page and the
     * home page again once the cache has dropped them. WP-Optimize with its
     * preload after purge on does that itself.
     *
     * @param string  $new  New status.
     * @param string  $old  Old status.
     * @param WP_Post $post Post.
     */
    public static function post_changed($new, $old, $post) {
        if (('publish' !== $new && 'publish' !== $old) || !($post instanceof WP_Post) || (defined('WP_IMPORTING') && WP_IMPORTING)
            || wp_is_post_revision($post) || wp_is_post_autosave($post) || !is_post_type_viewable($post->post_type)) {
            return;
        }
        $cache = self::page_cache();
        if ('' === $cache || ('wp-optimize' === $cache && self::wp_optimize_preloads())) {
            return;
        }
        $urls = array(home_url('/'));
        if ('publish' === $new) {
            $urls[] = (string) get_permalink($post);
        }
        $posts_page = (int) get_option('page_for_posts');
        if ('post' === $post->post_type && $posts_page) {
            $urls[] = (string) get_permalink($posts_page);
        }
        $queue         = self::queue();
        $queue['urls'] = self::merge($queue['urls'], self::clean($urls, 3));
        self::save($queue, self::POST_DELAY, false);
    }

    /**
     * Fill the whole cache again a little later.
     *
     * @param bool $network Every site of the network.
     */
    private static function fill_later($network) {
        $queue            = self::queue();
        $queue['full']    = true;
        $queue['network'] = $queue['network'] || $network;
        $queue['since']   = $queue['since'] ? $queue['since'] : time();
        ++$queue['purges'];
        // Purges one after another (a run of updates) move the fill back
        // until they stop, for up to WAIT seconds after the first.
        self::save($queue, self::DELAY, time() - $queue['since'] < self::WAIT);
    }

    /**
     * Whether LiteSpeed Cache's Purge All emptied every site of the
     * network, as it decides it (\LiteSpeed\Purge::_is_subsite_purge()).
     *
     * @return bool
     */
    private static function network_purge() {
        if (!is_multisite()) {
            return false;
        }
        if (is_network_admin() || (defined('LSWCP_EMPTYCACHE') && LSWCP_EMPTYCACHE)) {
            return true;
        }
        return wp_doing_ajax() && (false !== check_ajax_referer('updates', false, false) || false !== check_ajax_referer('litespeed-purgeall-network', false, false));
    }

    /**
     * Pages waiting, with defaults.
     *
     * @return array urls, full (work out the pages first), network (for every site),
     *               crawl (start LiteSpeed Cache's crawler after), since (first purge
     *               not yet worked out), purges (count), opened (count).
     */
    private static function queue() {
        $queue = get_option(self::QUEUE, array());
        $queue = is_array($queue) ? $queue : array();
        return array(
            'urls'    => isset($queue['urls']) && is_array($queue['urls']) ? array_values(array_filter($queue['urls'], 'is_string')) : array(),
            'full'    => !empty($queue['full']),
            'network' => !empty($queue['network']),
            'crawl'   => !empty($queue['crawl']),
            'since'   => isset($queue['since']) ? (int) $queue['since'] : 0,
            'purges'  => isset($queue['purges']) ? (int) $queue['purges'] : 0,
            'opened'  => isset($queue['opened']) ? (int) $queue['opened'] : 0,
        );
    }

    /**
     * Store what waits and make sure a batch is scheduled.
     *
     * @param array $queue From queue().
     * @param int   $delay Seconds before the batch.
     * @param bool  $later Move a batch due sooner to $delay, so it runs after this purge reached the cache.
     */
    private static function save(array $queue, $delay, $later) {
        $queue['urls'] = array_slice($queue['urls'], 0, self::QUEUE_MAX);
        update_option(self::QUEUE, $queue, false);
        $next = wp_next_scheduled(self::HOOK);
        if ($next && $later && $next < time() + $delay) {
            wp_clear_scheduled_hook(self::HOOK);
            $next = false;
        }
        if (!$next) {
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
    }

    /**
     * One batch: work out the pages if the cache was emptied, then open as
     * many as fit in the batch's time.
     */
    public static function run() {
        if (!self::enabled()) {
            delete_option(self::QUEUE);
            return;
        }
        $start = microtime(true);
        $queue = self::queue();
        if ($queue['full']) {
            $queue = self::plan($queue);
        }
        $mobile = self::mobile_copy();
        $opened = array();
        foreach ($queue['urls'] as $url) {
            if ($opened && !self::more_time($start, self::BUDGET)) {
                break;
            }
            self::open($url, $mobile);
            $opened[] = $url;
        }
        self::done($opened);
    }

    /**
     * Work out the pages to open, and keep them with any already waiting.
     * A purge since then leaves the queue marked to work them out again.
     *
     * @param array $queue From queue(), marked full.
     * @return array The queue as stored now.
     */
    private static function plan(array $queue) {
        $network = $queue['network'] && is_multisite();
        $urls    = $network ? self::network_pages() : self::pages(self::MAIN_MAX, self::covered() ? self::MAIN_MAX : self::MORE_MAX);
        $latest  = self::queue();
        $latest['urls']  = self::merge($latest['urls'], $urls);
        $latest['crawl'] = $latest['crawl'] || self::litespeed_crawler();
        if ($latest['purges'] === $queue['purges']) {
            $latest['full']    = false;
            $latest['network'] = false;
            $latest['since']   = 0;
        }
        $latest['urls'] = array_slice($latest['urls'], 0, self::QUEUE_MAX);
        update_option(self::QUEUE, $latest, false);
        return $latest;
    }

    /**
     * After a batch: take the opened pages off the queue as it is now (a
     * purge or a saved post may have changed it meanwhile), then schedule
     * the next batch, or start LiteSpeed Cache's crawler when all are done.
     *
     * @param string[] $opened Pages opened.
     */
    private static function done(array $opened) {
        $latest            = self::queue();
        $latest['urls']    = array_values(array_diff($latest['urls'], $opened));
        $latest['opened'] += count($opened);
        if ($latest['urls'] || $latest['full']) {
            self::save($latest, self::GAP, false);
            return;
        }
        delete_option(self::QUEUE);
        update_option(self::LAST, array('time' => time(), 'opened' => $latest['opened']), false);
        if ($latest['crawl'] && class_exists('SEOProStack_Litespeed_Crawler')) {
            SEOProStack_Litespeed_Crawler::start();
        }
    }

    /**
     * Open one page as a visitor who is not logged in: also as a phone
     * where the page cache keeps a mobile copy, and straight to this
     * server when LiteSpeed Cache's Server IP is set.
     *
     * @param string $url    Page address.
     * @param bool   $mobile Also as a phone.
     * @return array[] Each user agent's code and cache header (for WP-CLI).
     */
    private static function open($url, $mobile) {
        $agents  = $mobile ? array(self::AGENT, self::MOBILE) : array(self::AGENT);
        $resolve = self::resolver($url);
        $answers = array();
        if ($resolve) {
            add_action('http_api_curl', $resolve);
        }
        foreach ($agents as $agent) {
            $response  = wp_remote_get($url, array(
                'timeout'     => self::TIMEOUT, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- in WP-Cron, never on a visitor's page: an uncached page can take several seconds to build.
                'redirection' => 0,
                'user-agent'  => $agent,
                'headers'     => array('Accept' => 'text/html,application/xhtml+xml'),
                // Core's filter for requests to the site itself, as its loopback test uses.
                'sslverify'   => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
            ));
            $answers[] = is_wp_error($response)
                ? array('code' => $response->get_error_message(), 'cache' => '')
                : array(
                    'code'  => (int) wp_remote_retrieve_response_code($response),
                    'cache' => implode(' ', (array) wp_remote_retrieve_header($response, 'x-litespeed-cache')),
                );
        }
        if ($resolve) {
            remove_action('http_api_curl', $resolve);
        }
        return $answers;
    }

    /**
     * Send requests for this page straight to LiteSpeed Cache's Server IP,
     * as its crawler does, so a CDN in front neither answers nor times out.
     *
     * @param string $url Page address.
     * @return callable|null http_api_curl callback, or null to use DNS.
     */
    private static function resolver($url) {
        if ('litespeed' !== self::page_cache()) {
            return null;
        }
        $address = trim((string) SEOProStack_Litespeed::conf('server_ip', ''));
        $host    = (string) wp_parse_url($url, PHP_URL_HOST);
        if ('' === $host || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !defined('CURLOPT_RESOLVE')) {
            return null;
        }
        $port = 'https' === wp_parse_url($url, PHP_URL_SCHEME) ? 443 : 80;
        return function ($handle) use ($host, $port, $address) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the HTTP API's own handle, at its http_api_curl hook.
            curl_setopt($handle, CURLOPT_RESOLVE, array($host . ':' . $port . ':' . $address));
        };
    }

    /**
     * This site's pages to open: the home page, the posts page and the
     * pages in the theme's menus, then the newest pages and posts.
     *
     * @param int $main  Most main pages.
     * @param int $total Most pages in all.
     * @return string[]
     */
    private static function pages($main, $total) {
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
            $ids = get_posts(array(
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
            ));
            foreach ($ids as $id) {
                $urls[] = (string) get_permalink($id);
            }
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
    private static function network_pages() {
        $urls = array();
        foreach (get_sites(array('fields' => 'ids', 'number' => self::NETWORK_SITES, 'archived' => 0, 'deleted' => 0, 'spam' => 0)) as $id) {
            if (count($urls) >= self::QUEUE_MAX) {
                break;
            }
            switch_to_blog((int) $id);
            self::rewrite_init();
            if (self::switched_on() && self::runs_here() && '' !== self::page_cache()) {
                $urls = array_merge($urls, self::pages(self::NETWORK_MAIN_MAX, self::NETWORK_MORE_MAX));
            }
            restore_current_blog();
        }
        self::rewrite_init();
        return $urls;
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
     * Whether SEO Pro Stack is active on the current site of a network.
     *
     * @return bool
     */
    private static function runs_here() {
        return SEOProStack_Plugin_Loader::is_active(plugin_basename(SEOPROSTACK_FILE));
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
    private static function clean(array $urls, $max) {
        $host  = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $skip  = self::uncached_pages();
        $clean = array();
        foreach ($urls as $url) {
            $url   = explode('#', (string) $url)[0];
            $parts = wp_parse_url($url);
            if (!is_array($parts) || empty($parts['host']) || strtolower($parts['host']) !== $host || !empty($parts['query'])
                || !in_array(isset($parts['scheme']) ? $parts['scheme'] : '', array('http', 'https'), true)) {
                continue;
            }
            $path = isset($parts['path']) ? $parts['path'] : '/';
            if (preg_match('#/(wp-admin|wp-login\.php|feed)(/|$)#', $path) || preg_match('/\.[a-z0-9]{2,5}$/i', $path) || isset($skip[untrailingslashit($url)])) {
                continue;
            }
            $clean[$url] = true;
            if (count($clean) >= $max) {
                break;
            }
        }
        return array_keys($clean);
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

    /**
     * Two lists in order, without repeats.
     *
     * @param string[] $first  Addresses.
     * @param string[] $second Addresses.
     * @return string[]
     */
    private static function merge(array $first, array $second) {
        return array_values(array_unique(array_merge($first, $second)));
    }

    /**
     * The page cache on the current site: LiteSpeed Cache's on a LiteSpeed
     * server, or WP-Optimize's. Counted as active even when "Load plugins
     * only where needed" skipped it on this request.
     *
     * @return string litespeed, wp-optimize or ''.
     */
    public static function page_cache() {
        if (SEOProStack_Plugin_Loader::is_active('litespeed-cache/litespeed-cache.php') && SEOProStack_Litespeed::is_server() && SEOProStack_Litespeed::conf('cache', '1')) {
            return 'litespeed';
        }
        if (class_exists('WPO_Cache_Config') && self::wp_optimize_config('enable_page_caching')) {
            return 'wp-optimize';
        }
        return '';
    }

    /**
     * Whether a crawler opens the rest of this site after a purge.
     *
     * @return bool
     */
    private static function covered() {
        return self::litespeed_crawler() || ('wp-optimize' === self::page_cache() && self::wp_optimize_preloads());
    }

    /**
     * Whether LiteSpeed Cache's crawler is on for this site.
     *
     * @return bool
     */
    private static function litespeed_crawler() {
        return 'litespeed' === self::page_cache() && (bool) SEOProStack_Litespeed::conf('crawler', false);
    }

    /**
     * Whether the page cache keeps a separate copy for phones (LiteSpeed
     * Cache's mobile cache, WP-Optimize's mobile caching).
     *
     * @return bool
     */
    private static function mobile_copy() {
        $cache = self::page_cache();
        return ('litespeed' === $cache && (bool) SEOProStack_Litespeed::conf('cache-mobile', false))
            || ('wp-optimize' === $cache && self::wp_optimize_config('enable_mobile_caching'));
    }

    /**
     * Whether WP-Optimize preloads its page cache after a purge: its
     * preload after purge or scheduled preload, with its page cache on.
     *
     * @return bool
     */
    private static function wp_optimize_preloads() {
        return self::wp_optimize_config('enable_page_caching')
            && (self::wp_optimize_config('auto_preload_purged_contents') || self::wp_optimize_config('enable_schedule_preload'));
    }

    /**
     * One of WP-Optimize's page cache settings, through its own code.
     *
     * @param string $key Setting.
     * @return bool
     */
    private static function wp_optimize_config($key) {
        if (!class_exists('WPO_Cache_Config') || !is_callable(array('WPO_Cache_Config', 'instance'))) {
            return false;
        }
        $config = WPO_Cache_Config::instance()->get();
        return is_array($config) && !empty($config[$key]);
    }

    /**
     * `wp seoprostack keep-cached [--network] [--dry-run]`: open the pages
     * now, one at a time, and show each answer.
     *
     * @param array $args  Positional arguments (none).
     * @param array $assoc Flags.
     */
    public static function cli($args, $assoc) {
        unset($args);
        $network = !empty($assoc['network']) && is_multisite();
        $urls    = $network ? self::network_pages() : self::pages(self::MAIN_MAX, self::covered() ? self::MAIN_MAX : self::MORE_MAX);
        if (!empty($assoc['dry-run'])) {
            foreach ($urls as $url) {
                WP_CLI::log($url);
            }
            WP_CLI::success(sprintf('%d pages.', count($urls)));
            return;
        }
        $mobile = self::mobile_copy();
        foreach ($urls as $url) {
            $answers = array();
            foreach (self::open($url, $mobile) as $answer) {
                $answers[] = trim($answer['code'] . ' ' . $answer['cache']);
            }
            WP_CLI::log($url . ' ' . implode(' | ', $answers));
        }
        if (!$network && self::litespeed_crawler() && class_exists('SEOProStack_Litespeed_Crawler')) {
            SEOProStack_Litespeed_Crawler::start();
            WP_CLI::log('LiteSpeed Cache crawler started.');
        }
        WP_CLI::success(sprintf('%d pages opened.', count($urls)));
    }
}
