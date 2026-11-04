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
 *
 * On a network, a purge of every site (Network Admin, updates, as LiteSpeed
 * Cache decides it; any WP-Optimize purge) fills each site's main pages,
 * from the site where it happened.
 *
 * Batches run for about 20 seconds, a minute apart. Where the page cache
 * keeps a separate copy for phones, pages are opened as a phone too, and
 * requests go straight to this server when its Server IP is set. On a
 * LiteSpeed page cache they go as LiteSpeed Cache's crawler, which
 * LiteSpeed's bot protection lets through, after any purge LiteSpeed Cache
 * still holds has reached the cache; when the server refuses pages anyway,
 * batches wait longer, then leave the rest to the crawler.
 *
 * The pages: SEOProStack_Keep_Cached_Pages. The queue and batches:
 * SEOProStack_Keep_Cached_Queue. The page cache and requests:
 * SEOProStack_Keep_Cached_Requests. WP-CLI: SEOProStack_Keep_Cached_CLI.
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
                'description' => __('After the page cache is emptied (updates, Purge All, menu or Customizer changes) or a post is published, open the site’s pages in the background, one at a time, so visitors get cached pages again within minutes instead of waiting for each page to be built: the home page, the header menu’s pages, the footer menu’s, the home page’s other links, then the rest of the site. Where LiteSpeed Cache’s crawler or WP-Optimize’s preload is on, it is started for the rest of the site after them.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action(SEOProStack_Keep_Cached_Queue::HOOK, array(__CLASS__, 'run'));
        if (defined('WP_CLI') && WP_CLI) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-keep-cached-cli.php';
            SEOProStack_Keep_Cached_CLI::register();
        }
        if (!self::enabled()) {
            return;
        }
        add_action('litespeed_purged_all', array(__CLASS__, 'litespeed_purged'));
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
    }

    /**
     * LiteSpeed Cache emptied the page cache (Purge All).
     */
    public static function litespeed_purged() {
        SEOProStack_Keep_Cached_Queue::fill_later(self::network_purge());
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
     * One batch: work out the pages if the cache was emptied, then open as
     * many as fit in the batch's time. On a LiteSpeed page cache, a purge
     * LiteSpeed Cache still holds (WP-CLI, or WP-Cron run from the command
     * line) is delivered first, so the pages opened are new ones; while it
     * cannot be, or when the server refuses a page (bot protection, limits),
     * the batch stops and tries again later.
     */
    public static function run() {
        if (!self::enabled()) {
            delete_option(SEOProStack_Keep_Cached_Queue::OPTION);
            return;
        }
        $start = microtime(true);
        if ('litespeed' === SEOProStack_Keep_Cached_Requests::page_cache() && !SEOProStack_Litespeed::deliver_purge()) {
            SEOProStack_Keep_Cached_Queue::done(array(), (int) floor($start), true);
            return;
        }
        $queue = SEOProStack_Keep_Cached_Queue::get();
        if ($queue['full'] && count($queue['urls']) < SEOProStack_Keep_Cached_Pages::MAX) {
            $queue = SEOProStack_Keep_Cached_Queue::plan($queue, $start, self::BUDGET);
        }
        $mobile  = SEOProStack_Keep_Cached_Requests::mobile_copy();
        $opened  = array();
        $refused = '';
        foreach ($queue['urls'] as $url) {
            if ($opened && !self::more_time($start, self::BUDGET)) {
                break;
            }
            $answers = SEOProStack_Keep_Cached_Requests::open($url, $mobile, isset($queue['blogs'][$url]) ? $queue['blogs'][$url] : 0);
            if (SEOProStack_Keep_Cached_Requests::refused($answers)) {
                $refused = $url;
                break;
            }
            $opened[] = $url;
        }
        SEOProStack_Keep_Cached_Queue::done($opened, (int) floor($start), '' !== $refused, $refused);
    }
}
