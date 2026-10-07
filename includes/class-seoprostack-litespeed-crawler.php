<?php
/**
 * LiteSpeed Cache's crawler, for Hosting needs.
 *
 * LiteSpeed Cache's crawler visits every page of the sitemap so pages are
 * cached again after a purge (every plugin, theme and core update purges
 * them), and visitors get cached pages. When it stops working, nothing on
 * LiteSpeed Cache's own screens says why. This says how the crawl is going
 * and finds what stops it, each with a one-click fix through LiteSpeed
 * Cache's own code, only when someone clicks:
 *
 * - A Purge All (every update does one) after the last full crawl: the
 *   crawler waits for its next full crawl, up to the crawl interval away.
 *   Fix: Crawl now.
 * - A turn that stopped without finishing: LiteSpeed Cache keeps its lane
 *   file and waits an hour before the next turn. Fix: release the lane and
 *   start again.
 * - Pages that failed when the crawler last tried them (a timeout or a
 *   server error, such as Cloudflare's 522): LiteSpeed Cache blocklists
 *   them for that crawler for good, even when it rebuilds its list. Pages
 *   blocklisted because they cannot be cached (its orange "N") are left
 *   alone. Fix: take the failed ones off the blocklist.
 * - Server IP empty while the site's address points at another server
 *   (a CDN such as Cloudflare, or a proxy): the crawler then goes through
 *   it. Fix: set Server IP to this server's address, after a test request
 *   to it reaches LiteSpeed.
 * - A crawl in progress whose last turn started over an hour ago: WP-Cron
 *   is not starting it.
 * Plus Crawl now (LiteSpeed Cache's own manual run) and a link to its
 * Crawler screen. Nothing is stored.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Litespeed_Crawler {

    /** admin-post action; each fix's nonce adds its name. */
    const ACTION = 'seoprostack_crawler';

    /** Query argument naming what a fix did, for the notice after it. */
    const DONE = 'seoprostack_crawler_done';

    /** Query argument with the number of pages a fix changed. */
    const COUNT = 'seoprostack_crawler_count';

    /** LiteSpeed Cache's Crawler screen. */
    const PAGE = 'litespeed-crawler';

    /** A turn's lane file this long past the turn's length means the turn stopped. */
    const STOPPED_AFTER = 300;

    /** A crawl in progress with no turn started for this long is not being started. */
    const IDLE_AFTER = HOUR_IN_SECONDS;

    /** Most blocklisted pages read. */
    const BLOCKLIST_MAX = 500;

    /**
     * The crawler's state, found once per request.
     *
     * @var array|null
     */
    private static $state = null;

    /**
     * Register hooks (Hosting needs on).
     */
    public static function init() {
        // Site Health also runs its tests from cron, outside wp-admin.
        add_filter('site_status_tests', array(__CLASS__, 'tests'));
        if (!is_admin()) {
            return;
        }
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'act'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
    }

    /**
     * Whether LiteSpeed Cache's crawler code is loaded, as this reads it.
     *
     * @return bool
     */
    private static function loaded() {
        return class_exists('LiteSpeed\Crawler') && class_exists('LiteSpeed\Crawler_Map')
            && is_callable(array('LiteSpeed\Crawler', 'get_summary')) && is_callable(array('LiteSpeed\Crawler', 'cls'))
            && method_exists('LiteSpeed\Crawler', 'get_crawler_duration') && method_exists('LiteSpeed\Crawler', 'json_local_path')
            && method_exists('LiteSpeed\Crawler', 'Release_lane') && method_exists('LiteSpeed\Crawler_Map', 'list_blacklist');
    }

    /**
     * Whether to show the crawler: LiteSpeed Cache on a LiteSpeed server,
     * in a site's admin (the crawler works per site), or Site Health.
     *
     * @return bool
     */
    private static function applies() {
        return SEOProStack_Litespeed::is_server() && self::loaded() && !is_network_admin();
    }

    /**
     * Who may use the fixes: as for LiteSpeed Cache's own crawler screen.
     *
     * @return bool
     */
    private static function can() {
        return current_user_can('manage_options');
    }

    /**
     * How the crawl is going and what stops it.
     *
     * @return array on, summary, crawlers, interval, duration, stopped (seconds since the
     *               lane was last touched, when the turn stopped, else 0), failed (rows),
     *               idle (seconds since the last turn of a crawl in progress, when too
     *               long, else 0), purged (seconds since a Purge All after the last
     *               full crawl, when the next one is over an hour away, else 0) and
     *               origin (this server's address, when Server IP should be it, else '').
     */
    private static function state() {
        if (null !== self::$state) {
            return self::$state;
        }
        $state = array(
            'on'       => (bool) SEOProStack_Litespeed::conf('crawler', false),
            'summary'  => array(),
            'crawlers' => 1,
            'interval' => (int) SEOProStack_Litespeed::conf('crawler-crawl_interval', 302400),
            'duration' => 900,
            'stopped'  => 0,
            'failed'   => array(),
            'idle'     => 0,
            'purged'   => 0,
            'origin'   => '',
        );
        if (!$state['on']) {
            self::$state = $state;
            return $state;
        }
        $crawler = \LiteSpeed\Crawler::cls();
        $summary = (array) \LiteSpeed\Crawler::get_summary();
        $state['summary']  = $summary;
        $state['duration'] = (int) $crawler->get_crawler_duration();
        if (is_object($crawler) && is_callable(array($crawler, 'list_crawlers'))) {
            $state['crawlers'] = max(1, count((array) $crawler->list_crawlers()));
        }

        $path              = $crawler->json_local_path();
        $state['stopped']  = self::stopped($path . '.pid', $state['duration']);
        $state['failed']   = self::failed();
        $state['purged']   = self::purged($path . '.reset', $summary, $state['interval']);

        $last = (int) ($summary['last_start_time'] ?? 0);
        if (!$state['stopped'] && 'touchedEnd' !== ($summary['done'] ?? '') && $last && time() - $last > self::IDLE_AFTER) {
            $state['idle'] = time() - $last;
        }

        $state['origin'] = self::origin();
        self::$state     = $state;
        return $state;
    }

    /**
     * How long ago a turn that stopped without finishing last touched its
     * lane file, which LiteSpeed Cache keeps until it is an hour old.
     *
     * @param string $lane     Lane file.
     * @param int    $duration Turn length, seconds.
     * @return int Seconds, or 0 when no turn stopped.
     */
    private static function stopped($lane, $duration) {
        if (!file_exists($lane)) {
            return 0;
        }
        $age = time() - (int) filemtime($lane);
        return $age > $duration + self::STOPPED_AFTER ? $age : 0;
    }

    /**
     * Blocklisted pages that failed (B: no cache header, an error or a
     * timeout), not those that cannot be cached (N).
     *
     * @return array[] Rows with id.
     */
    private static function failed() {
        $failed = array();
        foreach ((array) \LiteSpeed\Crawler_Map::cls()->list_blacklist(self::BLOCKLIST_MAX) as $row) {
            $row = (array) $row;
            if (isset($row['id'], $row['res']) && false !== strpos((string) $row['res'], 'B')) {
                $failed[] = $row;
            }
        }
        return $failed;
    }

    /**
     * How long ago a Purge All emptied the page cache after the last full
     * crawl. Purge All (and rebuilding the list) leaves the reset file for
     * the next turn to start the crawl again from the top; after a finished
     * crawl, WP-Cron's turns stop before reading it until the crawl interval
     * has passed.
     *
     * @param string $reset    Reset file.
     * @param array  $summary  LiteSpeed Cache's crawler summary.
     * @param int    $interval Crawl interval, seconds.
     * @return int Seconds, or 0 when there was none or the next full crawl is within the hour.
     */
    private static function purged($reset, array $summary, $interval) {
        $began = (int) ($summary['this_full_beginning_time'] ?? 0);
        if (!$began || 'touchedEnd' !== ($summary['done'] ?? '') || !file_exists($reset)) {
            return 0;
        }
        $finished = $began + (int) ($summary['last_full_time_cost'] ?? 0);
        $purged   = (int) filemtime($reset);
        if ($purged < $finished || $finished + $interval - time() <= self::IDLE_AFTER) {
            return 0;
        }
        return max(1, time() - $purged);
    }

    /**
     * This server's address, when Server IP is empty and the site's host
     * name points elsewhere (a CDN or proxy), so the crawler should go
     * straight here. Only on web requests, which know the address.
     *
     * @return string IPv4 address, or ''.
     */
    private static function origin() {
        if ('' !== trim((string) SEOProStack_Litespeed::conf('server_ip', ''))) {
            return '';
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP address next.
        $address = isset($_SERVER['SERVER_ADDR']) ? (string) wp_unslash($_SERVER['SERVER_ADDR']) : '';
        // Public IPv4 only: LiteSpeed Cache's crawler resolves to it as host:port:address.
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return '';
        }
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ('' === $host) {
            return '';
        }
        $resolved = gethostbynamel($host);
        if (!is_array($resolved) || !$resolved || in_array($address, $resolved, true)) {
            return '';
        }
        return $address;
    }

    /**
     * Whether a request for the home page at this address reaches LiteSpeed
     * itself, as the crawler's requests would: not a CDN, not an error.
     *
     * @param string $address IPv4 address.
     * @return bool
     */
    private static function reaches_server($address) {
        $url  = home_url('/');
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $port = 'https' === wp_parse_url($url, PHP_URL_SCHEME) ? 443 : 80;
        // The HTTP API has no option for this; its cURL handle does.
        $resolve = function ($handle) use ($host, $port, $address) {
            if (defined('CURLOPT_RESOLVE')) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the HTTP API's own handle, at its http_api_curl hook.
                curl_setopt($handle, CURLOPT_RESOLVE, array($host . ':' . $port . ':' . $address));
            }
        };
        add_action('http_api_curl', $resolve);
        $response = wp_remote_head($url, array(
            'timeout'     => 10, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- only when an administrator clicks the fix; an uncached home page can take a few seconds.
            'redirection' => 0,
            // Core's filter for requests to the site itself, as its loopback test uses; the address is this server's.
            'sslverify'   => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        ));
        remove_action('http_api_curl', $resolve);
        if (is_wp_error($response)) {
            return false;
        }
        $code   = (int) wp_remote_retrieve_response_code($response);
        // A header sent more than once comes back as a list.
        $server = strtolower(implode(' ', (array) wp_remote_retrieve_header($response, 'server')));
        $ray    = implode('', (array) wp_remote_retrieve_header($response, 'cf-ray'));
        return $code >= 200 && $code < 500 && false !== strpos($server, 'litespeed') && '' === $ray;
    }

    /**
     * Link to one of the fixes.
     *
     * @param string $fix   crawl, release, retry or server_ip.
     * @param string $label Link text.
     * @return array url, label.
     */
    private static function link($fix, $label) {
        return array(
            'url'   => wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION . '&fix=' . $fix), self::ACTION . '-' . $fix),
            'label' => $label,
        );
    }

    /**
     * A length of time in words.
     *
     * @param int $seconds Seconds.
     * @return string
     */
    private static function span($seconds) {
        return human_time_diff(0, max(1, (int) $seconds));
    }

    /**
     * Lines for the Hosting needs row: the status, then each problem with
     * its fix.
     *
     * @return array[] Each status (recommended or info), text and actions (list of url, label).
     */
    public static function advice() {
        if (!self::applies()) {
            return array();
        }
        $state  = self::state();
        $can    = self::can();
        $screen = $can ? array('url' => admin_url('admin.php?page=' . self::PAGE), 'label' => __('Crawler screen', 'seoprostack')) : array();
        if (!$state['on']) {
            if (!SEOProStack_Litespeed::condition(false, 'litespeed_crawler')) {
                return array();
            }
            return array(array('info', __('LiteSpeed Cache’s crawler is off, so after each purge (every plugin, theme or core update) pages are cached again only when someone visits them. Apply preset for LiteSpeed Cache turns it on, daily, with the site’s sitemap.', 'seoprostack'), array()));
        }

        $crawl    = $can ? array(self::link('crawl', __('Crawl now', 'seoprostack'))) : array();
        $problems = array();
        foreach (self::problems($state) as $problem) {
            $problems[] = array('recommended', $problem['text'], $can && $problem['fix'] ? array($problem['fix']) : array());
            // Crawl now once: on the problem it fixes, not also on the status.
            if ($crawl && $problem['fix'] && $problem['fix']['url'] === $crawl[0]['url']) {
                $crawl = array();
            }
        }
        $status = array('info', self::status_text($state), array_values(array_filter(array_merge($crawl, array($screen)))));
        return array_merge(array($status), $problems);
    }

    /**
     * How the crawl is going, as a sentence.
     *
     * @param array $state From state().
     * @return string
     */
    private static function status_text(array $state) {
        $summary = $state['summary'];
        $pages   = (int) ($summary['list_size'] ?? 0);
        $began   = (int) ($summary['this_full_beginning_time'] ?? 0);
        $cost    = (int) ($summary['last_full_time_cost'] ?? 0);
        if ('touchedEnd' === ($summary['done'] ?? '') && $began) {
            $finished = $began + $cost;
            $next     = $finished + $state['interval'];
            return sprintf(
                /* translators: 1: number of pages, 2: time ago, 3: time taken, 4: time until the next crawl. */
                __('LiteSpeed Cache’s crawler last crawled all %1$s pages %2$s ago, in %3$s. The next full crawl starts in about %4$s; Crawl now starts one at once, such as after updates.', 'seoprostack'),
                number_format_i18n($pages),
                self::span(time() - $finished),
                self::span($cost),
                $next > time() ? self::span($next - time()) : __('10 minutes', 'seoprostack')
            );
        }
        if ($began) {
            return sprintf(
                /* translators: 1: crawler number, 2: number of crawlers, 3: page number, 4: number of pages, 5: time ago, 6: turn length. */
                __('LiteSpeed Cache’s crawler is caching pages: crawler %1$s of %2$s, at page %3$s of %4$s, in a crawl that started %5$s ago. It works in turns of up to %6$s every 10 minutes.', 'seoprostack'),
                number_format_i18n((int) ($summary['curr_crawler'] ?? 0) + 1),
                number_format_i18n($state['crawlers']),
                number_format_i18n((int) ($summary['last_pos'] ?? 0)),
                number_format_i18n($pages),
                self::span(time() - $began),
                self::span($state['duration'])
            );
        }
        if ((int) ($summary['last_start_time'] ?? 0) && 'touchedEnd' !== ($summary['done'] ?? '')) {
            // A new crawl's first turn rebuilds the list and stops; the next starts at the top.
            return sprintf(
                /* translators: %s: number of pages. */
                __('LiteSpeed Cache’s crawler is starting a full crawl of %s pages: it has made its list, and WP-Cron starts caching them within 10 minutes.', 'seoprostack'),
                number_format_i18n($pages)
            );
        }
        return __('LiteSpeed Cache’s crawler is on and has not crawled yet. WP-Cron starts it within 10 minutes; Crawl now starts it at once.', 'seoprostack');
    }

    /**
     * What stops the crawler, each with its fix.
     *
     * @param array $state From state().
     * @return array[] Each text and fix (url, label, or an empty array).
     */
    private static function problems(array $state) {
        // State key (seconds) => text with %s for that time, fix, fix label.
        $timed = array(
            'purged'  => array(
                /* translators: %s: time ago. */
                __('The page cache was emptied %s ago (Purge All, as every plugin, theme or core update does), after the crawler’s last full crawl. It waits for its next full crawl, so until then pages are cached again only when visitors open them.', 'seoprostack'),
                'crawl',
                __('Crawl now', 'seoprostack'),
            ),
            'stopped' => array(
                /* translators: %s: time ago. */
                __('A crawler turn stopped without finishing %s ago, often because the host ended the request. LiteSpeed Cache waits until an hour has passed before it starts another.', 'seoprostack'),
                'release',
                __('Carry on now', 'seoprostack'),
            ),
            'idle'    => array(
                /* translators: %s: length of time. */
                __('The crawl in progress has not had a turn for %s. WP-Cron starts one every 10 minutes, so it may not be running: with DISABLE_WP_CRON set, check the server’s cron job.', 'seoprostack'),
                'crawl',
                __('Crawl now', 'seoprostack'),
            ),
        );
        $problems = array();
        foreach ($timed as $key => $problem) {
            if ($state[$key]) {
                $problems[] = array(
                    'text' => sprintf($problem[0], self::span($state[$key])),
                    'fix'  => self::link($problem[1], $problem[2]),
                );
            }
        }
        $failed = count($state['failed']);
        if ($failed) {
            $problems[] = array(
                'text' => sprintf(
                    /* translators: %s: number of pages. */
                    _n(
                        '%s page failed when the crawler last tried it (a timeout or server error), so LiteSpeed Cache blocklisted it and no longer caches it ahead of visitors. Pages that cannot be cached, such as the basket, stay blocklisted.',
                        '%s pages failed when the crawler last tried them (a timeout or server error), so LiteSpeed Cache blocklisted them and no longer caches them ahead of visitors. Pages that cannot be cached, such as the basket, stay blocklisted.',
                        $failed,
                        'seoprostack'
                    ),
                    number_format_i18n($failed)
                ),
                'fix'  => self::link('retry', _n('Try it again', 'Try them again', $failed, 'seoprostack')),
            );
        }
        if ('' !== $state['origin']) {
            $problems[] = array(
                'text' => __('The site’s address points at another server, such as a CDN, and Server IP is empty in LiteSpeed Cache → General, so the crawler goes through that server: slower, and its timeouts blocklist pages. Server IP sends it straight to this server.', 'seoprostack'),
                'fix'  => SEOProStack_Litespeed::network_active() ? array() : self::link('server_ip', sprintf(
                    /* translators: %s: IP address. */
                    __('Use %s', 'seoprostack'),
                    $state['origin']
                )),
            );
        }
        return $problems;
    }

    /**
     * Run a fix, then go back with what it did.
     */
    public static function act() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- names the nonce checked next.
        $fix = isset($_GET['fix']) ? sanitize_key(wp_unslash($_GET['fix'])) : '';
        check_admin_referer(self::ACTION . '-' . $fix);
        if (!self::can()) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        $done  = 'failed';
        $count = 0;
        if (self::applies()) {
            switch ($fix) {
                case 'crawl':
                    $done = self::start() ? 'crawl' : 'failed';
                    break;
                case 'release':
                    if (self::state()['stopped']) {
                        \LiteSpeed\Crawler::cls()->Release_lane();
                    }
                    $done = self::start() ? 'release' : 'failed';
                    break;
                case 'retry':
                    $map = \LiteSpeed\Crawler_Map::cls();
                    if (is_object($map) && is_callable(array($map, 'blacklist_del'))) {
                        foreach (self::state()['failed'] as $row) {
                            $map->blacklist_del((int) $row['id']);
                            ++$count;
                        }
                        $done = 'retry';
                    }
                    break;
                case 'server_ip':
                    $done = self::save_origin();
                    break;
            }
        }
        $back = wp_get_referer();
        wp_safe_redirect(add_query_arg(array(self::DONE => $done, self::COUNT => $count), $back ? $back : admin_url('plugins.php')));
        exit;
    }

    /**
     * Start a crawler turn now, as LiteSpeed Cache's Manually run button
     * does: a background request, which carries on from the current page,
     * or starts a full crawl when the last one finished.
     *
     * @return bool Whether LiteSpeed Cache's code was there to start it.
     */
    private static function start() {
        if (!is_callable(array('\LiteSpeed\Task', 'async_call'))) {
            return false;
        }
        \LiteSpeed\Task::async_call('crawler_force');
        return true;
    }

    /**
     * Set Server IP to this server's address, after a test request to it
     * reaches LiteSpeed, through LiteSpeed Cache's own save code.
     *
     * @return string server_ip, or server_ip_failed.
     */
    private static function save_origin() {
        $address = self::state()['origin'];
        if ('' === $address || SEOProStack_Litespeed::network_active() || !self::reaches_server($address)
            || !class_exists('\LiteSpeed\Conf') || !is_callable(array('\LiteSpeed\Conf', 'cls'))) {
            return 'server_ip_failed';
        }
        $conf = \LiteSpeed\Conf::cls();
        if (!is_object($conf) || !is_callable(array($conf, 'update_confs'))) {
            return 'server_ip_failed';
        }
        $conf->update_confs(array('server_ip' => $address));
        return 'server_ip';
    }

    /**
     * Say what a fix did.
     */
    public static function notice() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only picks which message to show.
        $done  = isset($_GET[self::DONE]) ? sanitize_key(wp_unslash($_GET[self::DONE])) : '';
        $count = isset($_GET[self::COUNT]) ? absint(wp_unslash($_GET[self::COUNT])) : 0;
        // phpcs:enable
        $messages = array(
            'crawl'            => array('success', __('LiteSpeed Cache’s crawler has started. After a finished crawl it first makes a new list of pages, then WP-Cron caches them in turns of a few minutes, every 10 minutes, until every page is cached.', 'seoprostack')),
            'release'          => array('success', __('The stopped crawler turn was cleared, and the crawler has carried on from where it stopped.', 'seoprostack')),
            'retry'            => array('success', sprintf(
                /* translators: %s: number of pages. */
                _n('%s page is back on the crawler’s list. It is tried again on the crawler’s next pass.', '%s pages are back on the crawler’s list. They are tried again on the crawler’s next pass.', $count, 'seoprostack'),
                number_format_i18n($count)
            )),
            'server_ip'        => array('success', __('LiteSpeed Cache’s crawler now goes straight to this server (Server IP in LiteSpeed Cache → General).', 'seoprostack')),
            'server_ip_failed' => array('error', __('Server IP was not changed: a test request to this server’s address did not reach LiteSpeed, so the crawler could not use it. Ask your host for the server’s IP address.', 'seoprostack')),
            'failed'           => array('error', __('Nothing was changed: LiteSpeed Cache’s crawler is not available.', 'seoprostack')),
        );
        if (isset($messages[$done])) {
            printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($messages[$done][0]), esc_html($messages[$done][1]));
        }
    }

    /**
     * Take the fix's result out of the address after it is shown.
     *
     * @param string[] $args Query arguments.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = self::DONE;
        $args[] = self::COUNT;
        return $args;
    }

    /**
     * Add the Site Health test while the crawler applies.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function tests($tests) {
        if (self::applies() && self::state()['on']) {
            $tests['direct']['seoprostack_litespeed_crawler'] = array(
                'label' => __('LiteSpeed Cache crawler', 'seoprostack'),
                'test'  => array(__CLASS__, 'test'),
            );
        }
        return $tests;
    }

    /**
     * Site Health test.
     *
     * @return array
     */
    public static function test() {
        $state    = self::state();
        $problems = self::problems($state);
        $text     = '<p>' . esc_html__('LiteSpeed Cache’s crawler visits every page in the sitemap, so pages are cached again after a purge (every plugin, theme or core update) and visitors get cached pages.', 'seoprostack') . '</p>'
            . '<p>' . esc_html(self::status_text($state)) . '</p>';
        $actions  = array();
        foreach ($problems as $problem) {
            $text .= '<p>' . esc_html($problem['text']) . '</p>';
            if ($problem['fix']) {
                $actions[] = $problem['fix'];
            }
        }
        if (self::can()) {
            $actions[] = self::link('crawl', __('Crawl now', 'seoprostack'));
            $actions[] = array('url' => admin_url('admin.php?page=' . self::PAGE), 'label' => __('Crawler screen', 'seoprostack'));
        }
        $links = array();
        foreach ($actions as $action) {
            // The same fix can come from a problem and the shortcuts.
            $links[$action['url']] = sprintf('<a href="%1$s">%2$s</a>', esc_url($action['url']), esc_html($action['label']));
        }
        return array(
            'label'       => $problems ? __('LiteSpeed Cache’s crawler needs a look', 'seoprostack') : __('LiteSpeed Cache’s crawler is working', 'seoprostack'),
            'status'      => $problems ? 'recommended' : 'good',
            'badge'       => array(
                'label' => __('Performance', 'seoprostack'),
                'color' => 'blue',
            ),
            'description' => $text,
            'actions'     => $links ? '<p>' . implode(' | ', $links) . '</p>' : '',
            'test'        => 'seoprostack_litespeed_crawler',
        );
    }
}
