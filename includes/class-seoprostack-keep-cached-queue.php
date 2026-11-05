<?php
/**
 * Keep pages cached: the pages waiting to be opened, and the WP-Cron batch
 * that opens them (see SEOProStack_Keep_Pages_Cached).
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

final class SEOProStack_Keep_Cached_Queue {

    /** WP-Cron hook for each batch. */
    const HOOK = 'seoprostack_keep_pages_cached';

    /** Pages waiting to be opened, and what to work out first. Not autoloaded. */
    const OPTION = 'seoprostack_keep_pages_cached_queue';

    /** When the last fill finished and how many pages it opened. Not autoloaded. */
    const LAST = 'seoprostack_keep_pages_cached_last';

    /** Seconds after a purge: LiteSpeed applies it when the purging request ends. */
    const DELAY = 90;

    /** Most seconds purges one after another move the fill back. */
    const WAIT = 600;

    /** Seconds between batches. */
    const GAP = 60;

    /** WP-Optimize's WP-Cron hook that preloads its page cache. */
    const WPO_PRELOAD = 'wpo_page_cache_run_preload';

    /**
     * Batches in a row the server may refuse (or a purge may still wait)
     * before the rest is left to LiteSpeed Cache's crawler. Each waits a
     * GAP longer than the one before.
     */
    const STOPS = 5;

    /**
     * Pages waiting, with defaults.
     *
     * @return array urls, blogs (address => site ID), added (address => when a
     *               saved post queued it), full (work out the pages first), network
     *               (for every site), site (the next network site), crawl (start
     *               LiteSpeed Cache's crawler after), preload (start WP-Optimize's
     *               preload after), since (first purge not yet worked out), purges
     *               (count), opened (count), stops (batches stopped in a row).
     */
    public static function get() {
        self::forget();
        $queue = get_option(self::OPTION, array());
        $queue = (is_array($queue) ? $queue : array()) + array('urls' => array(), 'blogs' => array(), 'added' => array(), 'site' => 0, 'since' => 0, 'purges' => 0, 'opened' => 0, 'stops' => 0);
        return array(
            'urls'    => array_values(array_filter((array) $queue['urls'], 'is_string')),
            'blogs'   => array_map('intval', (array) $queue['blogs']),
            'added'   => array_map('intval', (array) $queue['added']),
            'full'    => !empty($queue['full']),
            'network' => !empty($queue['network']),
            'site'    => (int) $queue['site'],
            'crawl'   => !empty($queue['crawl']),
            'preload' => !empty($queue['preload']),
            'since'   => (int) $queue['since'],
            'purges'  => (int) $queue['purges'],
            'opened'  => (int) $queue['opened'],
            'stops'   => (int) $queue['stops'],
        );
    }

    /**
     * Forget this request's copy of the queue, so the next read gets the
     * stored one, and update_option() compares with it. A purge or a saved
     * post in another request can change the queue while a batch runs (up
     * to a minute and a half from the command line); with the copy the
     * batch read itself, done() deleted the new fill or wrote the old queue
     * back over it, and the site stayed unfilled (#1240).
     */
    private static function forget() {
        wp_cache_delete(self::OPTION, 'options');
        $missing = wp_cache_get('notoptions', 'options');
        if (is_array($missing) && isset($missing[self::OPTION])) {
            unset($missing[self::OPTION]);
            wp_cache_set('notoptions', $missing, 'options');
        }
    }

    /**
     * Fill the whole cache again a little later.
     *
     * @param bool $network Every site of the network.
     * @param bool $preload Start WP-Optimize's preload when the fill ends.
     */
    public static function fill_later($network, $preload = false) {
        $queue            = self::get();
        $queue['full']    = true;
        $queue['network'] = $queue['network'] || $network;
        $queue['preload'] = $queue['preload'] || $preload;
        if ($network) {
            $queue['site'] = 0;
        }
        $queue['since']   = $queue['since'] ? $queue['since'] : time();
        ++$queue['purges'];
        // Purges one after another (a run of updates) move the fill back
        // until they stop, for up to WAIT seconds after the first.
        self::save($queue, self::DELAY, time() - $queue['since'] < self::WAIT);
    }

    /**
     * Open these pages again a little later.
     *
     * @param string[] $urls  Addresses (already cleaned).
     * @param int      $delay Seconds before the batch.
     */
    public static function add(array $urls, $delay) {
        $queue         = self::get();
        $queue['urls'] = self::merge($queue['urls'], $urls);
        foreach ($urls as $url) {
            $queue['added'][$url] = time();
        }
        self::save($queue, $delay, false);
    }

    /**
     * Store what waits and make sure a batch is scheduled.
     *
     * @param array $queue From get().
     * @param int   $delay Seconds before the batch.
     * @param bool  $later Move a batch due sooner to $delay, so it runs after this purge reached the cache.
     */
    private static function save(array $queue, $delay, $later) {
        $queue['urls']  = array_slice($queue['urls'], 0, SEOProStack_Keep_Cached_Pages::MAX);
        $queue['blogs'] = array_intersect_key($queue['blogs'], array_flip($queue['urls']));
        $queue['added'] = array_intersect_key($queue['added'], array_flip($queue['urls']));
        update_option(self::OPTION, $queue, false);
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
     * Work out the pages to open, and keep them with any already waiting.
     * A purge since then leaves the queue marked to work them out again.
     *
     * @param array $queue From get(), marked full.
     * @return array The queue as stored now.
     */
    public static function plan(array $queue, $start, $budget) {
        $planned = SEOProStack_Keep_Cached_Pages::plan_network($queue['network'], $queue['site'], $start, $budget, count($queue['urls']));
        $latest          = self::get();
        $latest['urls']  = array_slice(self::merge($latest['urls'], $planned['urls']), 0, SEOProStack_Keep_Cached_Pages::MAX);
        $latest['blogs'] = array_intersect_key($latest['blogs'] + $planned['blogs'], array_flip($latest['urls']));
        $latest['crawl'] = $latest['crawl'] || SEOProStack_Keep_Cached_Requests::litespeed_crawler();
        if ($latest['purges'] === $queue['purges']) {
            $latest = array_merge($latest, array('full' => !$planned['done'], 'network' => !$planned['done'], 'site' => $planned['site'], 'since' => $planned['done'] ? 0 : $latest['since']));
        }
        update_option(self::OPTION, $latest, false);
        return $latest;
    }

    /**
     * After a batch: take the opened pages off the queue as it is now (a
     * purge or a saved post may have changed it meanwhile), except pages a
     * post saved since the batch began queued again, as the batch may have
     * opened them before the save; then schedule the next batch, or start
     * LiteSpeed Cache's crawler or WP-Optimize's preload for the rest of
     * the site when all are done, so they come after these pages.
     *
     * A batch the server refused, or that waited for a purge to reach the
     * cache, is stopped: the next waits a GAP longer for each such batch in
     * a row that opened nothing, and after STOPS of them the rest is left
     * to LiteSpeed Cache's crawler. A refused page goes to the end, so one
     * page the server always refuses cannot hold up the others.
     *
     * @param string[] $opened  Pages opened.
     * @param int      $began   When the batch began.
     * @param bool     $stopped Stopped early: refused, or a purge still waiting.
     * @param string   $refused The page the server refused, if any.
     */
    public static function done(array $opened, $began, $stopped = false, $refused = '') {
        $latest = self::get();
        $again  = array_keys(array_filter($latest['added'], static function ($time) use ($began) {
            return $time >= $began;
        }));
        $latest['urls']    = array_values(array_diff($latest['urls'], array_diff($opened, $again)));
        if ('' !== $refused && in_array($refused, $latest['urls'], true)) {
            $latest['urls']   = array_values(array_diff($latest['urls'], array($refused)));
            $latest['urls'][] = $refused;
        }
        $latest['blogs']   = array_intersect_key($latest['blogs'], array_flip($latest['urls']));
        $latest['opened'] += count($opened);
        $stops             = 0;
        if ($stopped) {
            $stops = $opened ? 1 : $latest['stops'] + 1;
        }
        $latest['stops'] = $stops;
        $gave_up         = $stops > self::STOPS;
        if (($latest['urls'] || $latest['full']) && !$gave_up) {
            self::save($latest, self::GAP * ($stops ? $stops : 1), false);
            return;
        }
        delete_option(self::OPTION);
        update_option(self::LAST, array('time' => time(), 'opened' => $latest['opened'], 'stopped' => $gave_up), false);
        $crawl = $latest['crawl'] || ($gave_up && SEOProStack_Keep_Cached_Requests::litespeed_crawler());
        if ($crawl && class_exists('SEOProStack_Litespeed_Crawler')) {
            SEOProStack_Litespeed_Crawler::start();
        }
        if ($latest['preload'] && SEOProStack_Keep_Cached_Requests::wp_optimize_preloads() && !wp_next_scheduled(self::WPO_PRELOAD)) {
            // As WP-Optimize's Purge button schedules it.
            wp_schedule_single_event(time() + 10, self::WPO_PRELOAD);
        }
    }

    /**
     * Nothing left waiting.
     */
    public static function clear() {
        wp_clear_scheduled_hook(self::HOOK);
        delete_option(self::OPTION);
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
}
