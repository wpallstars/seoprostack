<?php
/**
 * Clear Cloudflare's copies of pages: when LiteSpeed Cache or WP-Optimize
 * empties pages, empty Cloudflare's copies of the same pages.
 *
 * A Cloudflare Cache Rule that caches HTML keeps the site's pages at the
 * edge, so visitors no longer reach the server for each click (on a shared
 * LiteSpeed host, its bot protection counts every request that does). But
 * when a page cache on the server is emptied (an edit, an update, Purge
 * All), Cloudflare goes on serving its copy until its edge time ends.
 * LiteSpeed Cache's own Clear Cloudflare cache covers Purge All only, and
 * empties the whole zone (purge_everything): every site on the domain,
 * other subdomains and networks too. WP-Optimize clears nothing there.
 *
 * This asks Cloudflare to empty only this site's pages:
 * - a whole site (LiteSpeed Cache's Purge All, or its All pages purge rule
 *   after a post is saved; WP-Optimize's purge): its home address's host
 *   name, or for a site in a folder of its host (a subdirectory network)
 *   every address under that folder. Never the zone.
 * - a post published, updated or unpublished: the pages LiteSpeed Cache's
 *   Purge Rules For Publish/Update empty for it (post_pages()), by address,
 *   and their further pages (/page/2/ on) by prefix; its old address when
 *   it moved or was unpublished. More than MAX_URLS empty the whole site.
 * On a network, a purge of every site empties each site's host or folder.
 *
 * Purges are collected during the request and sent once, from WP-Cron on a
 * single site or a network's main site, at least DELAY seconds later, after
 * the page cache's own purge has reached the server; a LiteSpeed purge
 * still held by WP-CLI is delivered first. Otherwise a visitor in between
 * could put the old page back at the edge. Cloudflare's Free plan takes 5
 * purge requests a minute per zone (shared by every site on the zone), so a
 * run sends at most PER_RUN, combines up to MAX_ITEMS hosts or addresses in
 * one, and waits after a 429. The last LOG_SIZE answers are kept.
 *
 * Credentials, for each site: SEOPROSTACK_CLOUDFLARE_TOKEN and
 * SEOPROSTACK_CLOUDFLARE_ZONE in wp-config.php (one token for a network),
 * else this feature's own settings, else LiteSpeed Cache's Cloudflare API
 * key and zone when it is connected. A token limited to Zone → Cache Purge
 * is enough. The token is never shown, logged or sent anywhere but
 * api.cloudflare.com.
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

class SEOProStack_Cloudflare_Purge extends SEOProStack_Feature {

    const KEY = 'cloudflare_purge';

    /** WP-Cron event that sends the purges. */
    const HOOK = 'seoprostack_cloudflare_purge';

    /** Purges waiting to be sent (network option on a network). Not autoloaded. */
    const QUEUE = 'seoprostack_cloudflare_purge_queue';

    /** The last answers from Cloudflare (network option on a network). Not autoloaded. */
    const LOG = 'seoprostack_cloudflare_purge_log';

    /** Seconds after a purge before Cloudflare is asked. */
    const DELAY = 15;

    /** Seconds between runs while purges wait. */
    const GAP = 60;

    /** Requests a run sends: Cloudflare's Free plan takes 5 a minute per zone. */
    const PER_RUN = 4;

    /** Hosts, folders or addresses in one request. */
    const MAX_ITEMS = 30;

    /**
     * Addresses and folders waiting for one site above which the whole site
     * is emptied instead: three requests of MAX_ITEMS, within one run.
     */
    const MAX_URLS = 90;

    /**
     * LiteSpeed Cache's Purge Rules For Publish/Update (purge-post_<rule>)
     * that empty pages other than the post's own, and whether each is on
     * where LiteSpeed Cache is not the page cache (as its defaults, without
     * Pages, which can be many). Not its Recent Posts widget rule (pwrp):
     * the pages showing that widget cannot be listed, so their Cloudflare
     * copies wait for the edge time, or a save with the All pages rule.
     */
    const RULES = array(
        'f'  => true,  // Front page.
        'h'  => true,  // Home page (the posts page).
        'p'  => false, // Every published Page.
        'a'  => true,  // Author archive.
        't'  => true,  // Term archives.
        'pt' => true,  // Post type archive.
        'y'  => false, // Yearly archive.
        'm'  => false, // Monthly archive.
        'd'  => false, // Daily archive.
    );

    /** Answers kept. */
    const LOG_SIZE = 20;

    /** Runs a refused or failed request is tried in before it is dropped. */
    const TRIES = 6;

    /** Cloudflare's API. */
    const API = 'https://api.cloudflare.com/client/v4/zones/';

    /** Setting: seconds Cloudflare keeps a page, while purges reach it. */
    const EDGE_KEY = 'cloudflare_edge_time';

    /** The edge time's default: a day (#1545). */
    const EDGE_DEFAULT = 86400;

    /**
     * Most seconds for an address with a query string (directory filters,
     * campaign links): a purge by address empties only the address itself.
     */
    const EDGE_QUERY = 300;

    /** Seconds Cloudflare may serve its last copy while this server answers with an error. */
    const STALE_IF_ERROR = 86400;

    /** The edge time header: read by Cloudflare only; browsers and LiteSpeed Cache ignore it. */
    const EDGE_HEADER = 'Cloudflare-CDN-Cache-Control';

    /** @var bool Whether this request sent the edge time. */
    private static $edge_sent = false;

    /**
     * Purges collected during this request, by site: true for the whole
     * site, or addresses.
     *
     * @var array<int,true|string[]>
     */
    private static $pending = array();

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'speed',
                'label'       => __('Clear Cloudflare’s copies of pages', 'seoprostack'),
                'description' => __('Where a Cloudflare Cache Rule keeps copies of this site’s pages, empty Cloudflare’s copies whenever LiteSpeed Cache or WP-Optimize empties its own: after an update or Purge All, this site’s pages; after a post is published, updated or unpublished, its page and the pages that list it, as LiteSpeed Cache’s purge rules empty them. Only this site’s address is emptied, never every site on the domain. Needs a Cloudflare API token limited to Zone → Cache Purge, or LiteSpeed Cache’s Cloudflare API.', 'seoprostack'),
                'panel'       => true,
            ),
            'cloudflare_purge_token' => array(
                'type'        => 'text',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Cloudflare API token', 'seoprostack'),
                'description' => __('A token limited to Zone → Cache Purge for this domain (Cloudflare: My Profile → API Tokens). Leave empty to use LiteSpeed Cache’s Cloudflare API key, or set SEOPROSTACK_CLOUDFLARE_TOKEN in wp-config.php instead, which also serves every site of a network.', 'seoprostack'),
                // An account's key: the network never shows it to every site's administrators.
                'network'     => false,
                // Never put back in the page (SEOProStack_Settings_Manager::render_text()).
                'secret'      => true,
            ),
            'cloudflare_purge_zone' => array(
                'type'        => 'text',
                'default'     => '',
                'parent'      => self::KEY,
                'label'       => __('Zone ID', 'seoprostack'),
                'description' => __('From the domain’s Overview page in Cloudflare. Leave empty to use LiteSpeed Cache’s, or set SEOPROSTACK_CLOUDFLARE_ZONE in wp-config.php.', 'seoprostack'),
                'network'     => false,
            ),
            self::EDGE_KEY => array(
                'type'        => 'select',
                'default'     => (string) self::EDGE_DEFAULT,
                'parent'      => self::KEY,
                'label'       => __('Cloudflare edge time', 'seoprostack'),
                'description' => __('How long Cloudflare keeps a page for visitors who are not logged in, sent with each page while a token and zone are set and Cloudflare has not refused them, as edits and updates then empty Cloudflare’s copies. Addresses with a query string, such as filtered lists, get 5 minutes at most, as a purge cannot reach every one. Pages that are not cached (logged in, search, 404, previews, carts) get none. Pages LiteSpeed Cache holds already get it when they are next cached again.', 'seoprostack'),
                'options'     => array(__CLASS__, 'edge_options'),
            ),
        );
    }

    /**
     * Choices of Cloudflare edge time, in seconds (PHP keeps the keys as
     * integers; saved values are their strings).
     *
     * @return array<int,string>
     */
    public static function edge_options() {
        return array(
            '7200'    => __('2 hours', 'seoprostack'),
            '86400'   => __('1 day', 'seoprostack'),
            '604800'  => __('1 week', 'seoprostack'),
            '2592000' => __('30 days', 'seoprostack'),
            '0'       => __('Send none (Cloudflare’s own setting)', 'seoprostack'),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('seoprostack cloudflare-purge', array(__CLASS__, 'cli'), array(
                'shortdesc' => 'Clear Cloudflare’s copies of this site’s pages: status, site, url <address>..., run or log.',
            ));
        }
        if (is_admin()) {
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
        }
        if (!self::enabled()) {
            return;
        }
        add_action('litespeed_purged_all', array(__CLASS__, 'litespeed_purged_all'));
        add_action('litespeed_purged_all_lscache', array(__CLASS__, 'litespeed_purged_all'));
        add_action('litespeed_purged_post', array(__CLASS__, 'litespeed_purged_post'), 10, 1);
        add_action('wpo_cache_flush', array(__CLASS__, 'wp_optimize_purged'));
        add_action('transition_post_status', array(__CLASS__, 'post_changed'), 10, 3);
        add_action('post_updated', array(__CLASS__, 'post_moved'), 10, 3);
        add_action('wp_after_insert_post', array(__CLASS__, 'post_saved'), 10, 4);
        add_action('seoprostack_page_cache_purge_post', array(__CLASS__, 'post_purged'), 10, 1);
        // After redirects and other plugins' template_redirect work.
        add_action('template_redirect', array(__CLASS__, 'send_edge_time'), PHP_INT_MAX - 100);
        // LiteSpeed Cache decides a page is not cacheable as it ends.
        add_filter('litespeed_buffer_after', array(__CLASS__, 'check_edge_time'));
    }

    /**
     * Tell Cloudflare how long to keep this page (EDGE_HEADER), while its
     * purges reach Cloudflare: anonymous GET and HEAD front-end pages that
     * a page cache would keep. EDGE_QUERY at most with a query string.
     */
    public static function send_edge_time() {
        if (headers_sent() || !self::ready() || !self::edge_cacheable()) {
            return;
        }
        $seconds = (int) SEOProStack_Settings::get(self::EDGE_KEY);
        if ($seconds <= 0 || self::refused()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only whether there is a query string.
        if (array() !== $_GET) {
            $seconds = min($seconds, self::EDGE_QUERY);
        }
        /**
         * Seconds Cloudflare keeps this page (Cloudflare-CDN-Cache-Control
         * max-age), while Clear Cloudflare's copies of pages reaches
         * Cloudflare; 0 sends nothing. Runs on template_redirect.
         *
         * @since 1.3.203
         * @param int $seconds Cloudflare edge time setting (EDGE_QUERY at most with a query string).
         */
        $seconds = (int) apply_filters('seoprostack_cloudflare_edge_time', $seconds);
        if ($seconds <= 0) {
            return;
        }
        header(sprintf('%s: max-age=%d, stale-if-error=%d', self::EDGE_HEADER, $seconds, self::STALE_IF_ERROR));
        self::$edge_sent = true;
        // LiteSpeed Cache's own buffer calls check_edge_time() through
        // litespeed_buffer_after; without it, this one does, as the page ends.
        if (!did_action('litespeed_initing')) {
            ob_start(array(__CLASS__, 'check_edge_time'));
        }
    }

    /**
     * Take the edge time off a page that turned out not to be cacheable
     * while it was made: LiteSpeed Cache marked it not cacheable, it set
     * DONOTCACHEPAGE, or sent Cache-Control no-store, no-cache or private.
     * Cloudflare reads EDGE_HEADER before Cache-Control, so it would
     * otherwise keep the page. An output buffer callback: headers can
     * still change, as nothing has been sent.
     *
     * @param mixed $buffer Page.
     * @return mixed
     */
    public static function check_edge_time($buffer) {
        if (!self::$edge_sent || headers_sent()) {
            return $buffer;
        }
        $cacheable = !did_action('litespeed_initing') || !class_exists('\LiteSpeed\Control') || \LiteSpeed\Control::is_cacheable();
        if (!$cacheable || !self::edge_cacheable()) {
            header_remove(self::EDGE_HEADER);
            self::$edge_sent = false;
        }
        return $buffer;
    }

    /**
     * Whether this response may be kept at Cloudflare: an anonymous GET or
     * HEAD front-end page, not a preview, 404, search, feed, password
     * protected post or DONOTCACHEPAGE, with no Cache-Control that forbids
     * shared caches.
     *
     * @return bool
     */
    private static function edge_cacheable() {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        if ('GET' !== $method && 'HEAD' !== $method) {
            return false;
        }
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || is_user_logged_in()) {
            return false;
        }
        if ((defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) || is_preview() || is_404() || is_search() || is_feed() || is_trackback() || is_robots()) {
            return false;
        }
        if (is_singular() && post_password_required()) {
            return false;
        }
        foreach (headers_list() as $header) {
            if (0 === stripos($header, 'Cache-Control:') && preg_match('/\b(no-store|no-cache|private)\b/i', $header)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Plugin deactivated: nothing left waiting.
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        unset($network_wide);
        wp_clear_scheduled_hook(self::HOOK);
        self::delete(self::QUEUE);
    }

    /**
     * LiteSpeed Cache emptied the page cache: this site, or every site of
     * the network when its Purge All did (as Keep pages cached reads it).
     * Not when LiteSpeed Cache's own Clear Cloudflare cache is on: it has
     * already emptied the whole zone.
     */
    public static function litespeed_purged_all() {
        if (SEOProStack_Litespeed::conf('cdn-cloudflare', false) && SEOProStack_Litespeed::conf('cdn-cloudflare_clear', false)) {
            return;
        }
        if (SEOProStack_Keep_Pages_Cached::network_purge()) {
            self::network();
            return;
        }
        self::site();
    }

    /**
     * LiteSpeed Cache purged a post's pages. With its Purge Rules For
     * Publish/Update set to All pages, that emptied every page of the site,
     * so empty the site at Cloudflare too; otherwise post_changed() empties
     * the pages its rules emptied. Its Pages rule empties pages of the Page
     * type only (the PGS tag, set where is_page()), not every page (#1547).
     *
     * @param int $post_id Post.
     */
    public static function litespeed_purged_post($post_id) {
        unset($post_id);
        if (defined('WP_IMPORTING') && WP_IMPORTING) {
            return;
        }
        if (SEOProStack_Litespeed::conf('purge-post_all', false)) {
            self::site();
        }
    }

    /**
     * WP-Optimize emptied its page cache. Its cache folder holds every site
     * of a network, so on a network every site.
     */
    public static function wp_optimize_purged() {
        if (is_multisite()) {
            self::network();
            return;
        }
        self::site();
    }

    /**
     * A post was published, updated or unpublished: its page (the address
     * it had, once unpublished) and the pages that list it (post_pages()).
     *
     * @param string  $new  New status.
     * @param string  $old  Old status.
     * @param WP_Post $post Post.
     */
    public static function post_changed($new, $old, $post) {
        if (('publish' !== $new && 'publish' !== $old) || !($post instanceof WP_Post) || (defined('WP_IMPORTING') && WP_IMPORTING)) {
            return;
        }
        if (wp_is_post_revision($post) || wp_is_post_autosave($post) || !is_post_type_viewable($post->post_type)) {
            return;
        }
        self::queue_post_pages($post, 'publish' === $new ? (string) get_permalink($post) : self::published_link($post));
    }

    /**
     * A published post was saved, with its terms: post_pages() again. The
     * block editor's REST save sets the terms after transition_post_status
     * (WP_REST_Posts_Controller::handle_terms()), so post_changed() saw the
     * terms it had (still emptied, as they listed it) and this adds the new
     * ones, and the address a %category% permalink now gives.
     *
     * @param int          $post_id     Post.
     * @param WP_Post      $post        Post.
     * @param bool         $update      Whether it existed.
     * @param WP_Post|null $post_before Post before.
     */
    public static function post_saved($post_id, $post, $update, $post_before) {
        unset($post_id, $update, $post_before);
        if (!($post instanceof WP_Post) || 'publish' !== $post->post_status || (defined('WP_IMPORTING') && WP_IMPORTING)) {
            return;
        }
        if (wp_is_post_revision($post) || wp_is_post_autosave($post) || !is_post_type_viewable($post->post_type)) {
            return;
        }
        self::queue_post_pages($post, (string) get_permalink($post));
    }

    /**
     * Queue a post's address and the pages that list it (post_pages()),
     * or the whole site when they are too many.
     *
     * @param WP_Post $post Post.
     * @param string  $link Its address.
     */
    private static function queue_post_pages(WP_Post $post, $link) {
        $pages = self::post_pages($post, $link);
        if (null === $pages) {
            self::site();
            return;
        }
        self::urls($pages['files']);
        self::prefixes($pages['prefixes']);
    }

    /**
     * A published post's address changed (its slug, parent or date): empty
     * the old one too, which Cloudflare would go on serving.
     *
     * @param int     $post_id Post.
     * @param WP_Post $after   Post now.
     * @param WP_Post $before  Post before.
     */
    public static function post_moved($post_id, $after, $before) {
        unset($post_id);
        if (!($after instanceof WP_Post) || !($before instanceof WP_Post) || 'publish' !== $before->post_status || 'publish' !== $after->post_status || (defined('WP_IMPORTING') && WP_IMPORTING)) {
            return;
        }
        if (!is_post_type_viewable($after->post_type)) {
            return;
        }
        $was = (string) get_permalink($before);
        if ('' !== $was && (string) get_permalink($after) !== $was) {
            self::urls(array($was));
        }
    }

    /**
     * The address an unpublished post had while it was published (a
     * trashed post's slug has __trashed added).
     *
     * @param WP_Post $post Post.
     * @return string
     */
    private static function published_link(WP_Post $post) {
        $was              = clone $post;
        $was->post_status = 'publish';
        $slug             = (string) get_post_meta($post->ID, '_wp_desired_post_slug', true);
        if ('' !== $slug) {
            $was->post_name = $slug;
        }
        return (string) get_permalink($was);
    }

    /**
     * What a changed post empties at Cloudflare: its address, and the pages
     * LiteSpeed Cache's Purge Rules For Publish/Update empty for it (RULES
     * where LiteSpeed Cache is not the page cache): the front and home
     * page, every published Page, its term, post type, author and date
     * archives, by address, with each list's further pages (/page/2/ on)
     * by prefix, plus seoprostack_cloudflare_purge_post_urls. Never the
     * archives' folders: a listing's own address can be inside one.
     *
     * @param WP_Post $post Post.
     * @param string  $link Its address.
     * @return array{files:string[],prefixes:string[]}|null Null when more than MAX_URLS.
     */
    private static function post_pages(WP_Post $post, $link) {
        $rules = self::RULES;
        if ('litespeed' === SEOProStack_Keep_Cached_Requests::page_cache()) {
            foreach (array_keys($rules) as $rule) {
                $rules[$rule] = (bool) SEOProStack_Litespeed::conf('purge-post_' . $rule, false);
            }
        }
        $lists = array();
        if ($rules['f'] || $rules['h']) {
            $lists[] = home_url('/');
        }
        $posts_page = (int) get_option('page_for_posts');
        if ($rules['h'] && $posts_page && 'post' === $post->post_type) {
            $lists[] = (string) get_permalink($posts_page);
        }
        if ($rules['t']) {
            foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
                $terms = is_taxonomy_viewable($taxonomy) ? get_the_terms($post, $taxonomy) : array();
                foreach (is_array($terms) ? $terms : array() as $term) {
                    $lists[] = get_term_link($term);
                }
            }
        }
        if ($rules['pt']) {
            $lists[] = get_post_type_archive_link($post->post_type);
        }
        if ($rules['a'] && (int) $post->post_author > 0) {
            $lists[] = get_author_posts_url((int) $post->post_author);
        }
        $year  = (int) get_post_time('Y', false, $post);
        $month = (int) get_post_time('n', false, $post);
        if ($rules['y'] && $year) {
            $lists[] = get_year_link($year);
        }
        if ($rules['m'] && $year) {
            $lists[] = get_month_link($year, $month);
        }
        if ($rules['d'] && $year) {
            $lists[] = get_day_link($year, $month, (int) get_post_time('j', false, $post));
        }
        // The post type archive of posts is home_url() without its slash.
        $lists = array_map(function ($url) {
            return is_string($url) && '' === (string) wp_parse_url($url, PHP_URL_PATH) ? trailingslashit($url) : $url;
        }, $lists);
        $lists = array_values(array_unique(array_filter($lists, 'is_string')));

        $files = array_merge(array($link), $lists);
        if ($rules['p']) {
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams -- IDs only, at most MAX_URLS + 1, once per saved post.
            foreach (get_posts(array('post_type' => 'page', 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => self::MAX_URLS + 1, 'no_found_rows' => true, 'orderby' => 'ID')) as $page) {
                $files[] = (string) get_permalink((int) $page);
            }
        }
        /**
         * Addresses (one page each) to empty at Cloudflare after a post is
         * published, updated or unpublished, such as pages of an add-on
         * that list it.
         *
         * @since 1.3.203
         * @param string[] $files Addresses.
         * @param WP_Post  $post  Post.
         */
        $files = apply_filters('seoprostack_cloudflare_purge_post_urls', $files, $post);
        $files = array_values(array_unique(array_filter(array_map('strval', is_array($files) ? $files : array()))));

        // Further pages of each list: /page/2/ on, with pretty permalinks.
        $prefixes = array();
        if ('' !== (string) get_option('permalink_structure')) {
            global $wp_rewrite;
            $base = $wp_rewrite instanceof WP_Rewrite && '' !== $wp_rewrite->pagination_base ? $wp_rewrite->pagination_base : 'page';
            foreach ($lists as $list) {
                $prefixes[] = trailingslashit($list) . $base . '/';
            }
        }
        if (count($files) + count($prefixes) > self::MAX_URLS) {
            return null;
        }
        return array('files' => $files, 'prefixes' => $prefixes);
    }

    /**
     * SEO Pro Stack found a post's cached page out of date (its pictures'
     * addresses changed).
     *
     * @param int $post_id Post.
     */
    public static function post_purged($post_id) {
        if ('publish' === get_post_status((int) $post_id)) {
            self::urls(array((string) get_permalink((int) $post_id)));
        }
    }

    /**
     * Empty a whole site at Cloudflare.
     *
     * @param int $blog Site; 0 for the current one.
     */
    public static function site($blog = 0) {
        self::$pending[$blog ? (int) $blog : get_current_blog_id()] = true;
        self::send_later();
    }

    /**
     * Empty every site of the network at Cloudflare (this site on a single
     * site).
     */
    public static function network() {
        if (!is_multisite()) {
            self::site();
            return;
        }
        // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams -- site IDs only, at most 1,000, on a purge of every site.
        foreach (get_sites(array('fields' => 'ids', 'number' => 1000, 'archived' => 0, 'deleted' => 0, 'spam' => 0)) as $blog) {
            self::$pending[(int) $blog] = true;
        }
        self::send_later();
    }

    /**
     * Empty these addresses of a site at Cloudflare.
     *
     * @param string[] $urls Addresses.
     * @param int      $blog Site; 0 for the current one.
     */
    public static function urls(array $urls, $blog = 0) {
        $blog = $blog ? (int) $blog : get_current_blog_id();
        if (isset(self::$pending[$blog]) && true === self::$pending[$blog]) {
            return;
        }
        $have = isset(self::$pending[$blog]) && is_array(self::$pending[$blog]) ? self::$pending[$blog] : array();
        foreach ($urls as $url) {
            $url = esc_url_raw((string) $url, array('http', 'https'));
            if ('' !== $url) {
                $have[] = $url;
            }
        }
        self::$pending[$blog] = array_values(array_unique($have));
        self::send_later();
    }

    /**
     * Empty every address under these folders of a site at Cloudflare.
     * Kept in the same list as addresses, without the scheme, as
     * Cloudflare takes them (host/path/); never a site's home folder,
     * which empties the whole site (site()).
     *
     * @param string[] $urls Folder addresses.
     * @param int      $blog Site; 0 for the current one.
     */
    public static function prefixes(array $urls, $blog = 0) {
        $blog = $blog ? (int) $blog : get_current_blog_id();
        if (isset(self::$pending[$blog]) && true === self::$pending[$blog]) {
            return;
        }
        $home = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $have = isset(self::$pending[$blog]) && is_array(self::$pending[$blog]) ? self::$pending[$blog] : array();
        foreach ($urls as $url) {
            $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
            $path = trim((string) wp_parse_url((string) $url, PHP_URL_PATH), '/');
            if ('' !== $host && '' !== $path && $path !== $home) {
                $have[] = $host . '/' . $path . '/';
            }
        }
        self::$pending[$blog] = array_values(array_unique($have));
        self::send_later();
    }

    /**
     * Store this request's purges once, at its end.
     */
    private static function send_later() {
        if (!has_action('shutdown', array(__CLASS__, 'store'))) {
            add_action('shutdown', array(__CLASS__, 'store'), 5);
        }
    }

    /**
     * At the end of the request: add its purges to the stored queue (one
     * read and one write) and make sure a run is scheduled.
     */
    public static function store() {
        if (!self::$pending) {
            return;
        }
        $queue          = self::merge(self::queue(), self::$pending);
        self::$pending  = array();
        self::save($queue);
        self::schedule(self::DELAY);
    }

    /**
     * Merge purges into a queue: a whole site replaces its addresses, and a
     * site with more than MAX_URLS addresses is emptied whole.
     *
     * @param array<int,true|string[]> $queue Queue.
     * @param array<int,true|string[]> $add   Purges to add.
     * @return array<int,true|string[]>
     */
    private static function merge(array $queue, array $add) {
        foreach ($add as $blog => $what) {
            $blog = (int) $blog;
            if (true === $what || (isset($queue[$blog]) && true === $queue[$blog])) {
                $queue[$blog] = true;
                continue;
            }
            $have         = isset($queue[$blog]) && is_array($queue[$blog]) ? $queue[$blog] : array();
            $urls         = array_values(array_unique(array_merge($have, is_array($what) ? $what : array())));
            $queue[$blog] = count($urls) > self::MAX_URLS ? true : $urls;
        }
        return $queue;
    }

    /**
     * Schedule a run on the network's main site (one queue for the network)
     * unless one is due sooner.
     *
     * @param int $delay Seconds from now.
     */
    private static function schedule($delay) {
        $switch = is_multisite() && !is_main_site();
        if ($switch) {
            switch_to_blog(get_main_site_id());
        }
        $next = wp_next_scheduled(self::HOOK);
        if (!$next || $next > time() + $delay + self::GAP) {
            if ($next) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
        if ($switch) {
            restore_current_blog();
        }
    }

    /**
     * The stored queue.
     *
     * @return array<int,true|string[]>
     */
    private static function queue() {
        $queue = self::read(self::QUEUE);
        $sites = isset($queue['sites']) && is_array($queue['sites']) ? $queue['sites'] : array();
        $clean = array();
        foreach ($sites as $blog => $what) {
            if ((int) $blog > 0 && (true === $what || is_array($what))) {
                $clean[(int) $blog] = true === $what ? true : array_values(array_filter(array_map('strval', $what)));
            }
        }
        return $clean;
    }

    /**
     * Store the queue, or remove it when empty.
     *
     * @param array<int,true|string[]> $queue Queue.
     * @param int                      $tries Runs the first request has failed in.
     */
    private static function save(array $queue, $tries = -1) {
        if (!$queue) {
            self::delete(self::QUEUE);
            return;
        }
        if ($tries < 0) {
            $stored = self::read(self::QUEUE);
            $tries  = isset($stored['tries']) ? (int) $stored['tries'] : 0;
        }
        self::write(self::QUEUE, array('sites' => $queue, 'tries' => (int) $tries));
    }

    /**
     * Send what waits, at most PER_RUN requests: after a LiteSpeed purge
     * WP-CLI still holds has reached the server.
     */
    public static function run() {
        $queue = self::queue();
        if (!$queue) {
            return;
        }
        if ('litespeed' === SEOProStack_Keep_Cached_Requests::page_cache() && !SEOProStack_Litespeed::deliver_purge()) {
            self::schedule(self::GAP);
            return;
        }
        $stored = self::read(self::QUEUE);
        $tries  = isset($stored['tries']) ? (int) $stored['tries'] : 0;
        // Taken out now; what is not sent goes back, merged with purges added meanwhile.
        self::delete(self::QUEUE);
        $requests = self::requests($queue);
        $left     = array();
        $sent     = 0;
        $wait     = false;
        foreach ($requests as $request) {
            if ($wait || $sent >= self::PER_RUN) {
                $left[] = $request;
                continue;
            }
            ++$sent;
            $answer = self::send($request);
            if ('retry' === $answer) {
                $wait   = true;
                $left[] = $request;
            }
        }
        $back = array();
        foreach ($left as $request) {
            foreach ($request['blogs'] as $blog) {
                $back[$blog] = $queue[$blog];
            }
        }
        if ($wait && ++$tries >= self::TRIES) {
            self::log(array_keys($back), '', 0, false, __('Gave up after repeated failures; these pages refresh when Cloudflare’s copies expire.', 'seoprostack'));
            $back  = array();
            $tries = 0;
        } elseif (!$wait) {
            $tries = 0;
        }
        $back = self::merge(self::queue(), $back);
        self::save($back, $tries);
        if ($back) {
            self::schedule($wait ? self::GAP * min(8, 1 << max(0, $tries - 1)) : self::GAP);
        }
    }

    /**
     * Requests for a queue: each site's hosts, folders or addresses,
     * combined per zone and credentials, up to MAX_ITEMS each.
     *
     * @param array<int,true|string[]> $queue Queue.
     * @return array[] Each: zone, auth (headers), what (hosts, prefixes or files), items, blogs.
     */
    private static function requests(array $queue) {
        $groups = array();
        foreach ($queue as $blog => $what) {
            $switch = is_multisite() && get_current_blog_id() !== $blog;
            if ($switch) {
                if (!get_site($blog)) {
                    continue;
                }
                switch_to_blog($blog);
            }
            $credentials = self::enabled() ? self::credentials() : null;
            $targets     = $credentials ? self::targets($what) : array();
            if ($switch) {
                restore_current_blog();
            }
            if (!$credentials) {
                continue;
            }
            foreach ($targets as $kind => $items) {
                $group = $credentials['id'] . '|' . $kind;
                if (!isset($groups[$group])) {
                    $groups[$group] = array('credentials' => $credentials, 'what' => $kind, 'items' => array());
                }
                foreach ($items as $item) {
                    $groups[$group]['items'][$item][] = $blog;
                }
            }
        }
        $requests = array();
        foreach ($groups as $group) {
            foreach (array_chunk($group['items'], self::MAX_ITEMS, true) as $chunk) {
                $blogs = array();
                foreach ($chunk as $owners) {
                    $blogs = array_merge($blogs, $owners);
                }
                $requests[] = array(
                    'zone'  => $group['credentials']['zone'],
                    'auth'  => $group['credentials']['headers'],
                    'what'  => $group['what'],
                    'items' => array_keys($chunk),
                    'blogs' => array_values(array_unique($blogs)),
                );
            }
        }
        return $requests;
    }

    /**
     * What to ask Cloudflare to empty for the current site: for the whole
     * site its home host, or the folder it lives in on a host it shares
     * (a subdirectory network); otherwise its addresses (with a scheme) and
     * folders (prefixes(), without).
     *
     * @param true|string[] $what Whole site, or addresses and folders.
     * @return array<string,string[]> hosts, prefixes or files => items.
     */
    private static function targets($what) {
        if (true !== $what) {
            $targets = array();
            foreach (array_unique((array) $what) as $item) {
                $targets[false !== strpos((string) $item, '://') ? 'files' : 'prefixes'][] = (string) $item;
            }
            return $targets;
        }
        $home = home_url('/');
        $host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
        if ('' === $host) {
            return array();
        }
        $path = trim((string) wp_parse_url($home, PHP_URL_PATH), '/');
        if ('' === $path) {
            return array('hosts' => array($host));
        }
        return array('prefixes' => array($host . '/' . $path . '/'));
    }

    /**
     * Credentials for the current site: the wp-config.php constants, this
     * feature's settings, else LiteSpeed Cache's connected Cloudflare API.
     *
     * @return array{zone:string,headers:array<string,string>,id:string,source:string}|null
     */
    public static function credentials() {
        $token  = defined('SEOPROSTACK_CLOUDFLARE_TOKEN') && is_string(SEOPROSTACK_CLOUDFLARE_TOKEN) ? trim(SEOPROSTACK_CLOUDFLARE_TOKEN) : '';
        $source = '' !== $token ? 'constant' : '';
        if ('' === $token) {
            $token  = trim((string) SEOProStack_Settings::get('cloudflare_purge_token'));
            $source = '' !== $token ? 'setting' : '';
        }
        $zone = defined('SEOPROSTACK_CLOUDFLARE_ZONE') && is_string(SEOPROSTACK_CLOUDFLARE_ZONE) ? trim(SEOPROSTACK_CLOUDFLARE_ZONE) : '';
        if ('' === $zone) {
            $zone = trim((string) SEOProStack_Settings::get('cloudflare_purge_zone'));
        }
        $email     = '';
        $litespeed = (bool) SEOProStack_Litespeed::conf('cdn-cloudflare', false);
        if ('' === $token && $litespeed) {
            $token  = trim((string) SEOProStack_Litespeed::conf('cdn-cloudflare_key', ''));
            $email  = trim((string) SEOProStack_Litespeed::conf('cdn-cloudflare_email', ''));
            $source = '' !== $token ? 'litespeed' : '';
        }
        if ('' === $zone && $litespeed) {
            $zone = trim((string) SEOProStack_Litespeed::conf('cdn-cloudflare_zone', ''));
        }
        if ('' === $token || !preg_match('/^[0-9a-f]{32}$/i', $zone)) {
            return null;
        }
        // As LiteSpeed Cache routes its key: a Global API Key (cfk_, or 37 to 45 hex digits) goes with the account's email.
        $global  = 0 === strpos($token, 'cfk_') || preg_match('/^[0-9a-f]{37,45}$/', $token);
        $headers = $global
            ? array('X-Auth-Email' => $email, 'X-Auth-Key' => $token)
            : array('Authorization' => 'Bearer ' . $token);
        return array(
            'zone'    => strtolower($zone),
            'headers' => $headers,
            // Groups requests by credentials without keeping the token in the key.
            'id'      => md5($zone . "\0" . $token), // NOSONAR: a grouping key, not security.
            'source'  => $source,
        );
    }

    /**
     * Send one request.
     *
     * @param array $request From requests().
     * @return string done, retry (refused for now or failed) or dropped (Cloudflare said no).
     */
    private static function send(array $request) {
        $response = wp_remote_post(self::API . rawurlencode($request['zone']) . '/purge_cache', array(
            'timeout' => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- in WP-Cron only, at most PER_RUN a run.
            'headers' => array_merge($request['auth'], array('Content-Type' => 'application/json')),
            'body'    => (string) wp_json_encode(array($request['what'] => $request['items'])),
        ));
        if (is_wp_error($response)) {
            self::log($request['blogs'], $request['what'], 0, false, $response->get_error_message(), $request['items']);
            return 'retry';
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $ok   = 200 === $code && is_array($body) && !empty($body['success']);
        $message = '';
        if (!$ok && is_array($body) && !empty($body['errors']) && is_array($body['errors'])) {
            $parts = array();
            foreach ($body['errors'] as $error) {
                if (is_array($error)) {
                    $parts[] = trim((isset($error['code']) ? $error['code'] . ': ' : '') . (isset($error['message']) ? (string) $error['message'] : ''));
                }
            }
            $message = implode('; ', $parts);
        }
        if (429 === $code) {
            $message = __('Cloudflare is busy with this zone’s purges (5 a minute on the Free plan, shared by every site on it); trying again shortly.', 'seoprostack');
        }
        self::log($request['blogs'], $request['what'], $code, $ok, $message, $request['items']);
        if ($ok) {
            return 'done';
        }
        return 429 === $code || $code >= 500 ? 'retry' : 'dropped';
    }

    /**
     * Keep one answer, newest first.
     *
     * @param int[]    $blogs   Sites.
     * @param string   $what    hosts, prefixes or files.
     * @param int      $code    HTTP status, 0 for none.
     * @param bool     $ok      Whether Cloudflare emptied them.
     * @param string   $message Cloudflare's or the request's error.
     * @param string[] $items   What was asked for.
     */
    private static function log(array $blogs, $what, $code, $ok, $message, array $items = array()) {
        $log = self::read(self::LOG);
        $log = isset($log['entries']) && is_array($log['entries']) ? $log['entries'] : array();
        array_unshift($log, array(
            'time'    => time(),
            'blogs'   => array_values(array_map('intval', $blogs)),
            'what'    => (string) $what,
            'items'   => array_slice(array_map('strval', $items), 0, 5),
            'count'   => count($items),
            'code'    => (int) $code,
            'ok'      => (bool) $ok,
            'message' => substr(wp_strip_all_tags((string) $message), 0, 300),
        ));
        self::write(self::LOG, array('entries' => array_slice($log, 0, self::LOG_SIZE)));
    }

    /**
     * The kept answers, newest first.
     *
     * @return array[]
     */
    public static function entries() {
        $log = self::read(self::LOG);
        return isset($log['entries']) && is_array($log['entries']) ? $log['entries'] : array();
    }

    /**
     * Whether this site's purges reach Cloudflare: on, with credentials.
     *
     * @return bool
     */
    public static function ready() {
        return self::enabled() && null !== self::credentials();
    }

    /**
     * Whether Cloudflare's last answer refused the token or zone (4xx,
     * not 429): purges would not reach it, so a long edge time would keep
     * old pages. Rate limits and outages are tried again, so they do not
     * count.
     *
     * @return bool
     */
    public static function refused() {
        $entries = self::entries();
        if (!$entries) {
            return false;
        }
        $code = isset($entries[0]['code']) ? (int) $entries[0]['code'] : 0;
        return empty($entries[0]['ok']) && $code >= 400 && $code < 500 && 429 !== $code;
    }

    /**
     * Read one of the feature's stored values (the network's, on a network).
     *
     * @param string $name Option name.
     * @return array
     */
    private static function read($name) {
        $value = is_multisite() ? get_site_option($name, array()) : get_option($name, array());
        return is_array($value) ? $value : array();
    }

    /**
     * Store one of the feature's values, not autoloaded.
     *
     * @param string $name  Option name.
     * @param array  $value Value.
     */
    private static function write($name, array $value) {
        if (is_multisite()) {
            update_site_option($name, $value);
            return;
        }
        update_option($name, $value, false);
    }

    /**
     * Remove one of the feature's stored values.
     *
     * @param string $name Option name.
     */
    private static function delete($name) {
        if (is_multisite()) {
            delete_site_option($name);
            return;
        }
        delete_option($name);
    }

    /**
     * Under the setting: where the credentials come from, or that there are
     * none, and the last answers for this site.
     *
     * @param string $key   Setting key.
     * @param array  $field Setting definition.
     */
    public static function panel($key, $field) {
        unset($field);
        if (self::KEY !== $key || !self::switched_on()) {
            return;
        }
        $credentials = self::credentials();
        if (!$credentials) {
            printf('<div class="sps-panel-note sps-panel-note--warning"><p>%s</p></div>', esc_html__('No Cloudflare token and zone ID yet, so nothing is emptied at Cloudflare. Enter them below, set them in wp-config.php, or connect LiteSpeed Cache’s Cloudflare API.', 'seoprostack'));
        } else {
            $sources = array(
                'constant'  => __('Using the Cloudflare token from wp-config.php.', 'seoprostack'),
                'setting'   => __('Using the Cloudflare token entered below.', 'seoprostack'),
                'litespeed' => __('Using LiteSpeed Cache’s Cloudflare API key and zone.', 'seoprostack'),
            );
            printf('<div class="sps-panel-note"><p>%s</p></div>', esc_html($sources[$credentials['source']]));
        }
        $blog  = get_current_blog_id();
        $shown = 0;
        foreach (self::entries() as $entry) {
            if (!in_array($blog, (array) $entry['blogs'], true)) {
                continue;
            }
            $label = $entry['ok']
                /* translators: 1: time ago, 2: number of hosts, folders or addresses */
                ? sprintf(__('%1$s ago: Cloudflare emptied %2$s.', 'seoprostack'), human_time_diff((int) $entry['time']), self::describe($entry))
                /* translators: 1: time ago, 2: what was asked for, 3: error */
                : sprintf(__('%1$s ago: emptying %2$s failed: %3$s', 'seoprostack'), human_time_diff((int) $entry['time']), self::describe($entry), '' !== $entry['message'] ? $entry['message'] : (string) $entry['code']);
            printf('<div class="sps-panel-note%s"><p>%s</p></div>', $entry['ok'] ? '' : ' sps-panel-note--warning', esc_html($label));
            if (++$shown >= 3) {
                break;
            }
        }
    }

    /**
     * What a log entry asked for, in words.
     *
     * @param array $entry Log entry.
     * @return string
     */
    private static function describe(array $entry) {
        $count = isset($entry['count']) ? (int) $entry['count'] : 0;
        if ('hosts' === $entry['what'] || 'prefixes' === $entry['what']) {
            if (1 === $count && !empty($entry['items'][0])) {
                /* translators: %s: host name or folder */
                return sprintf(__('every page of %s', 'seoprostack'), $entry['items'][0]);
            }
            return 'hosts' === $entry['what']
                /* translators: %s: number of sites */
                ? sprintf(_n('every page of %s site', 'every page of %s sites', $count, 'seoprostack'), number_format_i18n($count))
                /* translators: %s: number of folders */
                : sprintf(_n('every page in %s folder', 'every page in %s folders', $count, 'seoprostack'), number_format_i18n($count));
        }
        /* translators: %s: number of addresses */
        return sprintf(_n('%s page', '%s pages', $count, 'seoprostack'), number_format_i18n($count));
    }

    /**
     * `wp seoprostack cloudflare-purge <status|site|url|run|log> [<address>...]`
     *
     * - status: where this site's credentials come from (never the token), and what waits.
     * - site: empty this site's pages at Cloudflare (sent by the next run).
     * - url <address>...: empty these addresses (sent by the next run).
     * - run: send what waits now.
     * - log: the last answers.
     *
     * @param array $args  Positional arguments.
     * @param array $assoc Flags.
     */
    public static function cli($args, $assoc) {
        unset($assoc);
        $command = isset($args[0]) ? (string) $args[0] : 'status';
        if ('status' === $command) {
            $credentials = self::credentials();
            WP_CLI::log('Switched on: ' . (self::enabled() ? 'yes' : 'no'));
            WP_CLI::log('Credentials: ' . ($credentials ? $credentials['source'] . ', zone ' . $credentials['zone'] : 'none'));
            WP_CLI::log('Whole site empties: ' . wp_json_encode(self::targets(true)));
            WP_CLI::log('Waiting: ' . wp_json_encode(self::queue()));
            return;
        }
        if ('log' === $command) {
            foreach (self::entries() as $entry) {
                WP_CLI::log(sprintf('%s %s %s %s [%s] %s', gmdate('Y-m-d H:i:s', (int) $entry['time']), $entry['ok'] ? 'ok' : 'FAILED', $entry['code'], $entry['what'], implode(' ', (array) $entry['items']), $entry['message']));
            }
            return;
        }
        if (!self::enabled() && 'run' !== $command) {
            WP_CLI::error('Clear Cloudflare’s copies of pages is off on this site.');
        }
        if ('site' === $command) {
            self::site();
        } elseif ('url' === $command) {
            self::urls(array_slice($args, 1));
        } elseif ('run' !== $command) {
            WP_CLI::error('Use status, site, url <address>..., run or log.');
        }
        self::store();
        if ('run' === $command) {
            self::run();
            $entries = self::entries();
            WP_CLI::log($entries ? sprintf('Last answer: %s %s', $entries[0]['ok'] ? 'ok' : 'FAILED', $entries[0]['message']) : 'Nothing sent.');
        }
        WP_CLI::success('Waiting: ' . wp_json_encode(self::queue()));
    }
}
