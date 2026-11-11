<?php
/**
 * Keep pages cached: fill the page cache again after it is emptied.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * Every plugin, theme or core update empties the page cache (LiteSpeed
 * Cache's Purge All On Upgrade, WP-Optimize's purge on updates, SEO Pro
 * Stack's own purge after its updates), and so do Purge All, menu saves and
 * Customizer, widget or site title saves (SEOProStack_Page_Cache::
 * design_saved(), GH#1546). Until someone opens each page again, visitors wait for
 * WordPress to build it, which on a busy development day is most of the day.
 *
 * After the cache is emptied, this opens the site's main pages in WP-Cron,
 * one at a time, as a visitor who is not logged in, so the cache holds them
 * again within minutes, in the order visitors reach them:
 * - the home page, the header's menus and links, the footer's, the home
 *   page's other links and the posts page;
 * - the rest of the site too (pages, posts, other public types, newest
 *   first, up to 1,000 pages), where no crawler covers it;
 * - then LiteSpeed Cache's crawler, where it is on, as its own Crawl now
 *   does: after a finished crawl it otherwise waits for its next full
 *   crawl, up to a day away (SEOProStack_Litespeed_Crawler);
 * - or WP-Optimize's own preload, as its Purge button schedules it, where
 *   its preload after purge or scheduled preload is on.
 * After a post is published or updated, its page and the home page are
 * opened again a minute later; after it is unpublished, the home page
 * (LiteSpeed Cache; WP-Optimize does that itself with preload after purge on).
 * With LiteSpeed Cache's purge rule Pages (on by default) or All pages,
 * that save emptied every page of the site, so it is filled as after Purge
 * All (#1399).
 *
 * On a network, a purge of every site (Network Admin, updates, as LiteSpeed
 * Cache decides it; any WP-Optimize purge) fills each site's main pages,
 * from the site where it happened: every site's first pages, then each
 * site's others (SEOProStack_Keep_Cached_Pages::network()).
 *
 * A LiteSpeed page cache can also be emptied from outside the site, which
 * runs none of its code: another site on the same hosting account sending
 * Empty Entire Cache (wp litespeed-purge all on a network), the host's panel
 * or the server. So every five minutes, on a single site or a network's main
 * site, the home page under an address only this check opens
 * (/?seoprostack-held=1, so a visitor reopening the home page cannot hide an
 * emptied cache) is opened once as LiteSpeed Cache's crawler (an answer
 * without PHP while LiteSpeed holds it); when the page held at one check, or
 * at the end of a fill, is missing at the next, the whole cache is filled as
 * after Purge All (every site of a network). A page never cached, or
 * redirected, never sets that off (GitHub issues #1229, #1233).
 *
 * Batches run for about 20 seconds, a minute apart. Where the page cache
 * keeps a separate copy for phones, pages are opened as a phone too, and
 * requests go straight to this server when its Server IP is set. On a
 * LiteSpeed page cache they go as LiteSpeed Cache's crawler, which
 * LiteSpeed's bot protection lets through, after any purge LiteSpeed Cache
 * still holds has reached the cache; when the server refuses pages anyway,
 * batches wait longer, then leave the rest to the crawler.
 *
 * Sites on one hosting account share it: at most two batches open pages at
 * once across all its installs (a lock per slot in the temporary folder),
 * a page that took over 8 seconds to build ends its batch, and a site hidden
 * from search engines fills only its main pages (GitHub issue #1471).
 *
 * The pages: SEOProStack_Keep_Cached_Pages. The queue and batches:
 * SEOProStack_Keep_Cached_Queue. The page cache and requests:
 * SEOProStack_Keep_Cached_Requests. WP-CLI: SEOProStack_Keep_Cached_CLI.
 *
 * @package SEOProStack
 * @since 1.3.145
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-keep-cached-requests.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-keep-cached-pages.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-keep-cached-queue.php';

class SEOProStack_Keep_Pages_Cached extends SEOProStack_Feature {

    const KEY = 'keep_pages_cached';

    /** Seconds after a post is saved. */
    const POST_DELAY = 60;

    /** Seconds a batch may run. */
    const BUDGET = 20;

    /**
     * Seconds a batch run from the command line may run: WP-Cron from the
     * host's cron job (often every five minutes, where 20 seconds a run
     * left a network's fill pending for hours) or WP-CLI. No visitor waits
     * on it and no web server time limit applies; it stays well under a
     * five-minute cron interval (#1236).
     *
     * On a single site or a network's main site only (the network's fill
     * and check run there). A network's cron is run one site after another,
     * and each site purged on its own fills on its own: with 90 seconds a
     * site, the others waited 20 to 30 minutes for a turn, their scheduled
     * tasks too, where 20 seconds opens every site's first pages in one
     * round (#1240).
     */
    const CLI_BUDGET = 90;

    /**
     * WP-Cron event, and its interval's name, of the check for a LiteSpeed
     * page cache emptied from outside the site.
     */
    const CHECK = 'seoprostack_keep_pages_cached_check';

    /** Seconds between checks. */
    const CHECK_INTERVAL = 300;

    /** Whether the last check found its page held, and since when. Not autoloaded. */
    const HELD = 'seoprostack_keep_pages_cached_held';

    /**
     * Query argument of the check's page: the home page under an address of
     * its own, which LiteSpeed caches apart from the home page and visitors
     * never open, so only an emptied cache takes it away (#1233).
     */
    const CHECK_ARG = 'seoprostack-held';

    /**
     * Seconds a page may take to build before the batch ends after it
     * (filter seoprostack_keep_cached_slow), so the next waits a minute: a
     * slow build is the first sign of a busy hosting account, well before
     * the server refuses pages (#1471).
     */
    const SLOW = 8;

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
                'description' => __('After the page cache is emptied (updates, Purge All, menu or Customizer changes) or a post is published, open the site’s pages in the background, one at a time, so visitors get cached pages again within minutes instead of waiting for each page to be built: the home page, the header menu’s pages, the footer menu’s, the home page’s other links, then the rest of the site. Where LiteSpeed Cache’s crawler or WP-Optimize’s preload is on, it is started for the rest of the site after them. With LiteSpeed Cache, the home page is also checked every five minutes, so a cache emptied from outside the site (another site on the same hosting account, the host’s panel) is filled again too.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action(SEOProStack_Keep_Cached_Queue::HOOK, array(__CLASS__, 'run'));
        add_action(self::CHECK, array(__CLASS__, 'check'));
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'), 10, 1);
        $cli = defined('WP_CLI') && WP_CLI;
        if ($cli) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-keep-cached-cli.php';
            SEOProStack_Keep_Cached_CLI::register();
        }
        if ((is_admin() && !wp_doing_ajax()) || wp_doing_cron() || $cli) {
            // Also when switched off, to take the check away. In cron runs
            // and WP-CLI too: a site whose host runs its cron, updated by a
            // deploy script, may not open its admin for weeks.
            add_action('wp_loaded', array(__CLASS__, 'sync_check'));
        }
        if (!self::enabled()) {
            return;
        }
        // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- every five minutes (CHECK_INTERVAL): one request LiteSpeed answers without PHP while it holds the page.
        add_filter('cron_schedules', array(__CLASS__, 'check_schedule'));
        add_action('litespeed_purged_all', array(__CLASS__, 'litespeed_purged'));
        add_action('litespeed_purged_post', array(__CLASS__, 'litespeed_post_purged'), 10, 1);
        add_action('wpo_cache_flush', array(__CLASS__, 'wp_optimize_purged'));
        add_action('transition_post_status', array(__CLASS__, 'post_changed'), 10, 3);
    }

    /**
     * The page cache on the current site (for the speed plan).
     *
     * @return string litespeed, wp-optimize or ''.
     */
    public static function page_cache() {
        return SEOProStack_Keep_Cached_Requests::page_cache();
    }

    /**
     * Plugin deactivated: nothing left waiting.
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        unset($network_wide);
        SEOProStack_Keep_Cached_Queue::clear();
        wp_clear_scheduled_hook(self::CHECK);
        delete_option(self::HELD);
    }

    /**
     * Whether this site checks for a cache emptied from outside it: with
     * this on, a LiteSpeed page cache, and on a network the main site only
     * (Empty Entire Cache empties every site, and a check per site would
     * send a request per site).
     *
     * @return bool
     */
    private static function checks() {
        return self::enabled() && is_main_site() && 'litespeed' === SEOProStack_Keep_Cached_Requests::page_cache();
    }

    /**
     * Keep the check's event in step with the switch and the page cache.
     */
    public static function sync_check() {
        $next = wp_next_scheduled(self::CHECK);
        if (self::checks()) {
            if (!$next) {
                // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- every five minutes (CHECK_INTERVAL), as in boot().
                add_filter('cron_schedules', array(__CLASS__, 'check_schedule'));
                wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CHECK, self::CHECK);
            }
            return;
        }
        if ($next) {
            wp_clear_scheduled_hook(self::CHECK);
            delete_option(self::HELD);
        }
    }

    /**
     * A setting changed: update the check's event.
     *
     * @param string $key Setting key.
     */
    public static function setting_saved($key) {
        if (self::KEY === $key) {
            self::sync_check();
        }
    }

    /**
     * The check's interval (cron_schedules). WordPress needs it known when
     * the event is scheduled and when a run schedules the next.
     *
     * @param array $schedules Intervals by name.
     * @return array
     */
    public static function check_schedule($schedules) {
        $schedules = is_array($schedules) ? $schedules : array();
        // Not translated: this filter can run before translations load.
        $schedules[self::CHECK] = array(
            'interval' => self::CHECK_INTERVAL,
            'display'  => 'SEO Pro Stack: every five minutes, for a page cache emptied from outside the site',
        );
        return $schedules;
    }

    /**
     * Every five minutes: open the check's page once (look()), as LiteSpeed
     * Cache's crawler, which LiteSpeed answers 201 without running PHP
     * while it holds the page. When the page held at the last check, or at
     * the end of the last fill (hold()), is missing now, the cache was
     * emptied, from wherever (another site's Empty Entire Cache, the host's
     * panel, or the page's own TTL), so fill it all as after Purge All:
     * every site of a network. The request that found the page missing has
     * cached it again.
     *
     * Nothing is opened while a fill is waiting or under way: hold() opens
     * the page when it ends. Only a change from held to missing starts a
     * fill, so a page LiteSpeed never caches (excluded, a site with no
     * front end) does not; redirects, refusals and errors change nothing.
     */
    public static function check() {
        if (!self::checks()) {
            self::sync_check();
            return;
        }
        $queue = SEOProStack_Keep_Cached_Queue::get();
        if ($queue['urls'] || $queue['full']) {
            return;
        }
        $held = self::look();
        $last = get_option(self::HELD, array());
        $was  = is_array($last) && isset($last['held']) ? (bool) $last['held'] : null;
        if (null === $held || $held === $was) {
            return;
        }
        // Stored only when it changes: no write every five minutes.
        update_option(self::HELD, array('held' => $held, 'since' => time()), false);
        if (true === $was && !$held) {
            SEOProStack_Keep_Cached_Queue::fill_later(is_multisite());
        }
    }

    /**
     * A fill ended with every page opened: the cache holds the site again,
     * so count the check's page held from now, when LiteSpeed answers it
     * from its cache (opened a second time if this request built it).
     * Otherwise a cache emptied between a fill and the first check after
     * an update, with nothing stored yet to compare, was never filled
     * again (#1233). A page LiteSpeed never caches is never counted held,
     * so it still never starts fills.
     */
    private static function hold() {
        if (!self::checks()) {
            return;
        }
        $held = self::look();
        if (false === $held) {
            $held = self::look();
        }
        $last = get_option(self::HELD, array());
        if (true !== $held || (is_array($last) && !empty($last['held']))) {
            return;
        }
        update_option(self::HELD, array('held' => true, 'since' => time()), false);
    }

    /**
     * Open the check's page once: the home page under its own address
     * (CHECK_ARG), which visitors never open, so a visit to the home page
     * after the cache was emptied cannot hide it (#1233). Where that address
     * does not answer as a page (redirected, refused), the home page itself.
     *
     * @return bool|null As held().
     */
    private static function look() {
        $answers = SEOProStack_Keep_Cached_Requests::open(add_query_arg(self::CHECK_ARG, '1', home_url('/')), false);
        $held    = self::held($answers[0]);
        if (null !== $held) {
            return $held;
        }
        $answers = SEOProStack_Keep_Cached_Requests::open(home_url('/'), false);
        return self::held($answers[0]);
    }

    /**
     * Whether LiteSpeed held the page, from open()'s answer: true for a
     * hit (201 to its crawler), false for a page it built (200), null for
     * anything else (redirect, refused, error).
     *
     * @param array $answer One of open()'s answers.
     * @return bool|null
     */
    private static function held(array $answer) {
        if (!in_array($answer['code'], array(200, 201), true)) {
            return null;
        }
        return false !== stripos((string) $answer['cache'], 'hit');
    }

    /**
     * LiteSpeed Cache emptied the page cache (Purge All).
     */
    public static function litespeed_purged() {
        SEOProStack_Keep_Cached_Queue::fill_later(self::network_purge());
    }

    /**
     * LiteSpeed Cache purged a post's pages (a post published, updated,
     * unpublished, trashed or deleted, or its comment count changed). With
     * its Purge Rules For Publish/Update set to Pages (on by default), that
     * empties every WordPress page of this site, the front page among them;
     * with All pages, the whole site. Neither runs Purge All, so fill this
     * site as after it: its main pages, then LiteSpeed Cache's crawler, or
     * the rest where the crawler is off. post_changed() still reopens the
     * post itself first. Saves one after another move the fill back, for up
     * to 10 minutes after the first (#1399).
     *
     * Only for a post LiteSpeed Cache applies those rules to: one of a
     * built-in or viewable type (\LiteSpeed\Purge::_get_purge_tags_by_post()).
     *
     * @param int $post_id Post.
     */
    public static function litespeed_post_purged($post_id) {
        if ((defined('WP_IMPORTING') && WP_IMPORTING) || 'litespeed' !== SEOProStack_Keep_Cached_Requests::page_cache()) {
            return;
        }
        if (!SEOProStack_Litespeed::conf('purge-post_p', false) && !SEOProStack_Litespeed::conf('purge-post_all', false)) {
            return;
        }
        $type = get_post_type_object((string) get_post_type((int) $post_id));
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own filter, read as it reads it.
        if ($type && !$type->_builtin && !is_post_type_viewable($type) && apply_filters('litespeed_purge_post_skip_non_viewable', true, (int) $post_id)) {
            return;
        }
        SEOProStack_Keep_Cached_Queue::fill_later(false);
    }

    /**
     * WP-Optimize emptied its page cache: fill the main pages, then start
     * its own preload where it has one on, as its Purge button does, for
     * the rest of the site. Its cache folder holds every site of a network.
     */
    public static function wp_optimize_purged() {
        SEOProStack_Keep_Cached_Queue::fill_later(is_multisite(), SEOProStack_Keep_Cached_Requests::wp_optimize_preloads());
    }

    /**
     * A post was published, updated or unpublished: open the home page, and
     * its page while published, again once the cache has dropped them. WP-Optimize with its
     * preload after purge on does that itself.
     *
     * @param string  $new  New status.
     * @param string  $old  Old status.
     * @param WP_Post $post Post.
     */
    public static function post_changed($new, $old, $post) {
        if (!self::visible_change($new, $old, $post)) {
            return;
        }
        $cache = SEOProStack_Keep_Cached_Requests::page_cache();
        if ('litespeed' === $cache || ('wp-optimize' === $cache && !SEOProStack_Keep_Cached_Requests::wp_optimize_preloads())) {
            SEOProStack_Keep_Cached_Queue::add(SEOProStack_Keep_Cached_Pages::clean(self::post_urls($new, $post), 3), self::POST_DELAY);
        }
    }

    /**
     * Whether visitors see the change: a viewable post published or
     * unpublished, not while importing, nor a revision or autosave.
     *
     * @param string $new  New status.
     * @param string $old  Old status.
     * @param mixed  $post Post.
     * @return bool
     */
    private static function visible_change($new, $old, $post) {
        if (('publish' !== $new && 'publish' !== $old) || !($post instanceof WP_Post) || (defined('WP_IMPORTING') && WP_IMPORTING)) {
            return false;
        }
        return !wp_is_post_revision($post) && !wp_is_post_autosave($post) && is_post_type_viewable($post->post_type);
    }

    /**
     * The pages a post's change empties: the home page, the post's own
     * page while it is published, and the posts page for posts.
     *
     * @param string  $new  New status.
     * @param WP_Post $post Post.
     * @return string[]
     */
    private static function post_urls($new, WP_Post $post) {
        $urls = array(home_url('/'));
        if ('publish' === $new) {
            $urls[] = (string) get_permalink($post);
        }
        $posts_page = (int) get_option('page_for_posts');
        if ('post' === $post->post_type && $posts_page) {
            $urls[] = (string) get_permalink($posts_page);
        }
        return $urls;
    }

    /**
     * Whether LiteSpeed Cache's Purge All emptied every site of the
     * network, as it decides it (\LiteSpeed\Purge::_is_subsite_purge()).
     * Also read by Clear Cloudflare's copies of pages.
     *
     * @return bool
     */
    public static function network_purge() {
        if (!is_multisite()) {
            return false;
        }
        if (is_network_admin() || (defined('LSWCP_EMPTYCACHE') && LSWCP_EMPTYCACHE)) {
            return true;
        }
        return wp_doing_ajax() && (false !== check_ajax_referer('updates', false, false) || false !== check_ajax_referer('litespeed-purgeall-network', false, false));
    }

    /**
     * One batch: work out the pages if the cache was emptied, then open as
     * many as fit in the batch's time. On a LiteSpeed page cache, a purge
     * LiteSpeed Cache still holds (WP-CLI, or WP-Cron run from the command
     * line) is delivered first, so the pages opened are new ones; while it
     * cannot be, or when the server refuses a page (bot protection, limits),
     * the batch stops and tries again later. Run from the command line (the
     * host's cron job, WP-CLI) on a single site or a network's main site, a
     * batch has CLI_BUDGET seconds, else BUDGET.
     *
     * A batch opens pages only with one of the hosting account's slots
     * (SEOProStack_Keep_Cached_Queue::SLOTS); with none free it opens
     * nothing and tries again a minute later. A page that took longer than
     * SLOW to build ends the batch after it (#1471).
     */
    public static function run() {
        if (!self::enabled()) {
            delete_option(SEOProStack_Keep_Cached_Queue::OPTION);
            return;
        }
        // A purge during a cron run moves the fill back, but core still calls the copy of the event it read at the start: leave the moved one to run at its new time.
        $moved = class_exists('SEOProStack_Cron_List', false) ? SEOProStack_Cron_List::next_stored(SEOProStack_Keep_Cached_Queue::HOOK) : null;
        if (null !== $moved && $moved > time()) {
            return;
        }
        if (!SEOProStack_Keep_Cached_Queue::take_slot()) {
            SEOProStack_Keep_Cached_Queue::no_slot();
            return;
        }
        try {
            self::batch();
        } finally {
            SEOProStack_Keep_Cached_Queue::release_slot();
        }
    }

    /**
     * Seconds a page may take to build before the batch ends after it.
     *
     * @return float
     */
    private static function slow() {
        return max(1.0, (float) apply_filters('seoprostack_keep_cached_slow', self::SLOW));
    }

    /**
     * The batch itself, holding a slot (run()).
     */
    private static function batch() {
        $start  = microtime(true);
        $budget = 'cli' === PHP_SAPI && is_main_site() ? self::CLI_BUDGET : self::BUDGET;
        if ('litespeed' === SEOProStack_Keep_Cached_Requests::page_cache() && !SEOProStack_Litespeed::deliver_purge()) {
            SEOProStack_Keep_Cached_Queue::done(array(), (int) floor($start), true);
            return;
        }
        $queue = SEOProStack_Keep_Cached_Queue::get();
        if ($queue['full'] && count($queue['urls']) < SEOProStack_Keep_Cached_Pages::MAX) {
            $queue = SEOProStack_Keep_Cached_Queue::plan($queue, $start, $budget);
        }
        $mobile  = SEOProStack_Keep_Cached_Requests::mobile_copy();
        $slow    = self::slow();
        $opened  = array();
        $refused = '';
        foreach ($queue['urls'] as $url) {
            if ($opened && !self::more_time($start, $budget)) {
                break;
            }
            $began   = microtime(true);
            $answers = SEOProStack_Keep_Cached_Requests::open($url, $mobile, isset($queue['blogs'][$url]) ? $queue['blogs'][$url] : 0);
            if (SEOProStack_Keep_Cached_Requests::refused($answers)) {
                $refused = $url;
                break;
            }
            $opened[] = $url;
            // Per request: a phone copy doubles the requests, not the build time.
            if ((microtime(true) - $began) / max(1, count($answers)) > $slow) {
                break;
            }
        }
        SEOProStack_Keep_Cached_Queue::done($opened, (int) floor($start), '' !== $refused, $refused);
        $left = SEOProStack_Keep_Cached_Queue::get();
        if ($opened && '' === $refused && !$left['urls'] && !$left['full']) {
            self::hold();
        }
    }
}
