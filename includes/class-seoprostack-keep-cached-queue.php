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

    /**
     * Pages waiting, with defaults.
     *
     * @return array urls, full (work out the pages first), network (for every site),
     *               crawl (start LiteSpeed Cache's crawler after), since (first purge
     *               not yet worked out), purges (count), opened (count).
     */
    public static function get() {
        $queue = get_option(self::OPTION, array());
        $queue = (is_array($queue) ? $queue : array()) + array('urls' => array(), 'since' => 0, 'purges' => 0, 'opened' => 0);
        return array(
            'urls'    => array_values(array_filter((array) $queue['urls'], 'is_string')),
            'full'    => !empty($queue['full']),
            'network' => !empty($queue['network']),
            'crawl'   => !empty($queue['crawl']),
            'since'   => (int) $queue['since'],
            'purges'  => (int) $queue['purges'],
            'opened'  => (int) $queue['opened'],
        );
    }

    /**
     * Fill the whole cache again a little later.
     *
     * @param bool $network Every site of the network.
     */
    public static function fill_later($network) {
        $queue            = self::get();
        $queue['full']    = true;
        $queue['network'] = $queue['network'] || $network;
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
        $queue['urls'] = array_slice($queue['urls'], 0, SEOProStack_Keep_Cached_Pages::MAX);
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
    public static function plan(array $queue) {
        $urls            = SEOProStack_Keep_Cached_Pages::to_open($queue['network']);
        $latest          = self::get();
        $latest['urls']  = array_slice(self::merge($latest['urls'], $urls), 0, SEOProStack_Keep_Cached_Pages::MAX);
        $latest['crawl'] = $latest['crawl'] || SEOProStack_Keep_Cached_Requests::litespeed_crawler();
        if ($latest['purges'] === $queue['purges']) {
            $latest = array_merge($latest, array('full' => false, 'network' => false, 'since' => 0));
        }
        update_option(self::OPTION, $latest, false);
        return $latest;
    }

    /**
     * After a batch: take the opened pages off the queue as it is now (a
     * purge or a saved post may have changed it meanwhile), then schedule
     * the next batch, or start LiteSpeed Cache's crawler when all are done.
     *
     * @param string[] $opened Pages opened.
     */
    public static function done(array $opened) {
        $latest            = self::get();
        $latest['urls']    = array_values(array_diff($latest['urls'], $opened));
        $latest['opened'] += count($opened);
        if ($latest['urls'] || $latest['full']) {
            self::save($latest, self::GAP, false);
            return;
        }
        delete_option(self::OPTION);
        update_option(self::LAST, array('time' => time(), 'opened' => $latest['opened']), false);
        if ($latest['crawl'] && class_exists('SEOProStack_Litespeed_Crawler')) {
            SEOProStack_Litespeed_Crawler::start();
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
