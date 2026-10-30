<?php
/**
 * Check which more plugins each address renders exactly the same without.
 *
 * Part of Load plugins only where needed. Most plugins add something to
 * pages (wp_head, scripts, body classes, blocks), so learning keeps them on
 * every page of their kind, even where what they add is nothing. A sample
 * page cannot show that other pages of the kind need them no more: a form
 * block on one page, a widget on another (GitHub issue #1039). So each
 * address is checked by itself.
 *
 * A visitor's page whose kind lists plugins it might also skip
 * (SEOProStack_Plugin_Loading::front_propose()) and that has not been
 * checked is queued (SEOProStack_Plugin_Loader::queue_address()). A
 * scheduled task renders each queued address through the site's own
 * address:
 *
 * - as it is served now ("base");
 * - without some of the listed plugins ("trial"): first those other
 *   addresses of the kind rendered the same without, then the rest,
 *   halving a group when the page changes;
 * - a second time as served, only when a trial differs, to tell words that
 *   change on every render (random IDs) from a real difference.
 *
 * Each render carries a one-time token (SEOProStack_Plugin_Loader::
 * verify_request()), is kept out of page caches, says in a header which
 * plugins it skipped and reports which plugins wrote to the database, sent
 * a request or mail while it rendered. Plugins that do are never skipped
 * (a view counter or log keeps a record of each page view), and a trial
 * whose plugins do something the page as served did not fails. Only
 * plugins whose absence left the status, the headers that matter and the
 * HTML the same are saved for the address. That address skips them while
 * the content revision and the plugin set stay the same, for a week at
 * most, after which it is checked again. A site whose requests to itself
 * fail pauses checks for an hour and skips nothing more.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.108
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Front_Verify {

    /** Trial renders per address at most. */
    const TRIALS = 16;

    /** Seconds one render may take. */
    const TIMEOUT = 15;

    /** Seconds after which a check of one address starts no more renders. */
    const BUDGET = 60;

    /** Seconds after which a scheduled run starts no more addresses. */
    const RUN_BUDGET = 50;

    /** Most words that may change on each render (random IDs) for a page to be compared. */
    const VOLATILE = 50;

    /** Most addresses kept; the oldest checks go first. */
    const MAX_PAGES = 5000;

    /** Failed checks in a row (the site's requests to itself) that pause checking. */
    const FAILURES = 5;

    /** Response headers that must be the same, lower case. */
    const HEADERS = array(
        'content-type', 'content-language', 'link', 'x-robots-tag', 'cache-control', 'expires', 'vary', 'location',
        'x-litespeed-cache-control', 'x-litespeed-tag', 'x-litespeed-vary', 'content-security-policy',
        'content-security-policy-report-only', 'x-frame-options', 'x-content-type-options', 'referrer-policy',
        'permissions-policy', 'strict-transport-security', 'access-control-allow-origin',
    );

    /** Render counter, so each address is new to caches. */
    private static $count = 0;

    /**
     * Check queued addresses until the run's time is up. The task repeats
     * (SEOProStack_Plugin_Loader::schedule_verify()) until the queue is
     * empty: paused, busy or learning again, a run just waits for the next.
     */
    public static function run() {
        $start = microtime(true);
        $map   = get_option(SEOProStack_Plugin_Loader::FRONT_PAGES, array());
        $pause = is_array($map) ? (int) ($map['paused'] ?? 0) : 0;
        if ($pause > time()) {
            return;
        }
        $busy = get_option(SEOProStack_Plugin_Loader::FRONT_VERIFY, array());
        if (is_array($busy) && (int) ($busy['expires'] ?? 0) > time()) {
            return; // One check at a time.
        }
        while (SEOProStack_Feature::more_time($start, self::RUN_BUDGET)) {
            if (!SEOProStack_Plugin_Loader::front_current()) {
                return; // Learned again: visitors queue what they open.
            }
            $item = self::take();
            if (null === $item) {
                wp_clear_scheduled_hook(SEOProStack_Plugin_Loader::VERIFY_HOOK); // Visitors schedule it again.
                return;
            }
            $front = get_option(SEOProStack_Plugin_Loader::FRONT, array());
            $kind  = $front['kinds'][$item['kind']] ?? null;
            if (!is_array($kind) || empty($kind['propose'])) {
                continue;
            }
            $revision = (string) get_option(SEOProStack_Plugin_Loader::FRONT_REVISION, '');
            $map      = self::map($revision, (string) $front['active']);
            $entry    = SEOProStack_Plugin_Loader::page_entry($map, $item['key']);
            if (null !== $entry && $entry[1] * HOUR_IN_SECONDS >= time() - SEOProStack_Plugin_Loader::PAGES_MAX_AGE) {
                continue; // Checked meanwhile.
            }
            $result = self::check($item, $kind, $map);
            SEOProStack_Plugin_Loader::drop_option(SEOProStack_Plugin_Loader::FRONT_VERIFY);
            if (self::save($item, $result, $revision, (string) $front['active'])) {
                return; // Paused for an hour.
            }
        }
    }

    /**
     * Take the oldest queued address off the queue. One that stops a run
     * (time limit, fatal error) is queued again by its next visitor.
     *
     * @return array{key: string, path: string, kind: string}|null
     */
    private static function take() {
        wp_cache_delete(SEOProStack_Plugin_Loader::FRONT_QUEUE, 'options');
        $queue = get_option(SEOProStack_Plugin_Loader::FRONT_QUEUE, array());
        if (!is_array($queue) || !$queue) {
            return null;
        }
        $key  = (string) array_key_first($queue);
        $item = $queue[$key];
        unset($queue[$key]);
        if ($queue) {
            update_option(SEOProStack_Plugin_Loader::FRONT_QUEUE, $queue, false);
        } else {
            SEOProStack_Plugin_Loader::drop_option(SEOProStack_Plugin_Loader::FRONT_QUEUE);
        }
        if (!is_array($item) || !preg_match('/^[0-9a-f]{12}$/', $key) || empty($item['path']) || empty($item['kind'])
            || !is_string($item['path']) || !is_string($item['kind']) || '/' !== $item['path'][0]) {
            return self::take();
        }
        return array('key' => $key, 'path' => $item['path'], 'kind' => $item['kind']);
    }

    /**
     * The checked addresses for this revision and plugin set; a new list
     * when either changed.
     *
     * @param string $revision Content revision.
     * @param string $active   Plugin set fingerprint.
     * @return array
     */
    private static function map($revision, $active) {
        wp_cache_delete(SEOProStack_Plugin_Loader::FRONT_PAGES, 'options');
        $map = get_option(SEOProStack_Plugin_Loader::FRONT_PAGES, array());
        if (is_array($map) && ($map['revision'] ?? null) === $revision && ($map['active'] ?? null) === $active) {
            return $map;
        }
        return array(
            'revision' => $revision,
            'active'   => $active,
            'sets'     => array(),
            'pages'    => '',
            'kinds'    => array(),
            'failures' => is_array($map) ? (int) ($map['failures'] ?? 0) : 0,
            'paused'   => 0,
        );
    }

    /**
     * Render one address as served, then without plugins its kind might
     * also skip.
     *
     * @param array $item Queued address: key, path, kind.
     * @param array $kind What was learned for its kind.
     * @param array $map  The checked addresses (map()).
     * @return array{skip: string[], result: string, note: string, needed: string[], retry: bool}
     */
    private static function check(array $item, array $kind, array $map) {
        $start  = microtime(true);
        $result = array('skip' => array(), 'result' => 'failed', 'note' => '', 'needed' => array(), 'retry' => false);
        $url    = self::address((string) $item['path']);
        if ('' === $url) {
            $result['note'] = 'address';
            return $result;
        }
        $check = array(
            'token'   => wp_generate_password(32, false, false),
            'key'     => $item['key'],
            'kind'    => $item['kind'],
            'skip'    => array(),
            'expires' => time() + self::BUDGET + 2 * self::TIMEOUT,
        );
        update_option(SEOProStack_Plugin_Loader::FRONT_VERIFY, $check, false);
        $base = self::render($url, 'base', $check);
        if (isset($base['error'])) {
            $result['note']  = $base['error'];
            $result['retry'] = $base['retry'];
            return $result;
        }

        // Plugins that wrote, sent or mailed while the page rendered keep
        // a record of the view: never skipped.
        $propose = array_values(array_diff((array) $kind['propose'], $base['effects'], $base['skipped']));
        $stats   = (array) ($map['kinds'][$item['kind']] ?? array());
        $hint    = isset($stats['hint']) ? array_values(array_intersect((array) ($map['sets'][(int) $stats['hint']] ?? array()), $propose)) : array();
        // Plugins this kind needed on other addresses are not tried again:
        // fewer renders, and never a reason to skip.
        $rest    = array_values(array_diff($propose, $hint, (array) ($stats['needed'] ?? array())));
        $groups  = array_values(array_filter(array($hint, $rest)));
        $tried   = array();
        $passed  = null;
        $trials  = 0;
        $first   = '';
        while ($groups && $trials < self::TRIALS && SEOProStack_Feature::more_time($start, self::BUDGET)) {
            $group = array_shift($groups);
            $check['skip'] = array_values(array_unique(array_merge($tried, $group)));
            update_option(SEOProStack_Plugin_Loader::FRONT_VERIFY, $check, false);
            $trial = self::render($url, 'trial', $check);
            $trials++;
            $failed = isset($trial['error']);
            $note   = $failed ? $trial['error'] : '';
            if (!$failed && self::matches($base, $trial, $url, $check, $note)) {
                $tried  = $check['skip']; // Each later trial skips these too.
                $passed = $trial;
                continue;
            }
            if (!empty($base['unstable'])) {
                return array('skip' => array(), 'result' => 'unstable', 'note' => $base['unstable'], 'needed' => array(), 'retry' => false);
            }
            $first = '' !== $first ? $first : $note;
            if (count($group) > 1) {
                $half = (int) ceil(count($group) / 2);
                array_unshift($groups, array_slice($group, 0, $half), array_slice($group, $half));
            } elseif (!$failed) {
                $result['needed'][] = $group[0];
            }
        }
        // Only what the last passing trial did skip: a plugin another
        // loading plugin needs loads anyway (front_skipped()), and leaving
        // it out gives the same set.
        $result['skip']   = $passed ? array_values(array_diff(array_intersect($tried, $passed['skipped']), $base['skipped'], $base['effects'])) : array();
        $result['result'] = $result['skip'] ? 'verified' : ($propose ? 'differs' : 'none');
        $result['note']   = $result['skip'] ? '' : $first;
        return $result;
    }

    /**
     * Whether a trial rendered the same as the page as served and did
     * nothing it did not. When it differs, the page is rendered once more
     * as served: words that change on every render then do not count.
     *
     * @param array  $base  The render as served; gains 'volatile', or 'unstable' when it cannot be compared.
     * @param array  $trial The trial.
     * @param string $url   Address.
     * @param array  $check The check under way.
     * @param string $note  Set to the first difference.
     * @return bool
     */
    private static function matches(array &$base, array $trial, $url, array $check, &$note) {
        if (self::within($base, $trial, $check, $note) && self::same($base, $trial, $note)) {
            return true;
        }
        if (isset($base['volatile'])) {
            return false;
        }
        $base['volatile'] = array();
        $again = self::render($url, 'base', $check);
        if (isset($again['error'])) {
            return false;
        }
        $base['effects'] = array_values(array_unique(array_merge($base['effects'], $again['effects'])));
        $diff = '';
        if (self::same($base, $again, $diff)) {
            return self::within($base, $trial, $check, $note) && self::same($base, $trial, $note);
        }
        $words = self::words($base['html']);
        $other = self::words($again['html']);
        if ($base['headers'] !== $again['headers'] || $base['skipped'] !== $again['skipped'] || count($words) !== count($other)) {
            $base['unstable'] = 'changes on each render: ' . $diff;
            return false;
        }
        $volatile = array_keys(array_diff_assoc($words, $other));
        if (count($volatile) > self::VOLATILE) {
            $base['unstable'] = sprintf('%d words change on each render', count($volatile));
            return false;
        }
        $base['volatile'] = array_fill_keys($volatile, true);
        return self::within($base, $trial, $check, $note) && self::same($base, $trial, $note);
    }

    /**
     * Whether a trial skipped no plugin that keeps records and no plugin
     * did anything the page as served did not.
     *
     * @param array  $base  The render as served.
     * @param array  $trial The trial.
     * @param array  $check The check under way.
     * @param string $note  Set to why not.
     * @return bool
     */
    private static function within(array $base, array $trial, array $check, &$note) {
        $writers = array_intersect((array) $check['skip'], $base['effects']);
        $more    = array_diff($trial['effects'], $base['effects']);
        if ($writers || $more) {
            $note = 'writes, requests or mail: ' . implode(', ', array_merge($writers, $more));
            return false;
        }
        return true;
    }

    /**
     * A page as words and what is between them, in order.
     *
     * @param string $html Normalised page.
     * @return string[]
     */
    private static function words($html) {
        $words = preg_split('/([A-Za-z0-9_]+)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        return is_array($words) ? $words : array($html);
    }

    /**
     * The full address of a path on the site's own host.
     *
     * @param string $path Path and arguments.
     * @return string
     */
    private static function address($path) {
        $home = wp_parse_url(home_url('/'));
        if (!is_array($home) || empty($home['host']) || empty($home['scheme']) || !in_array($home['scheme'], array('http', 'https'), true)
            || '' === $path || '/' !== $path[0] || 0 === strpos($path, '//')) {
            return '';
        }
        return $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . (int) $home['port'] : '') . $path;
    }

    /**
     * Render a page through the site's own address.
     *
     * @param string $url   Page address.
     * @param string $mode  'base' or 'trial'.
     * @param array  $check The check under way.
     * @return array{html: string, headers: array<string,string>, skipped: string[], effects: string[]}|array{error: string, retry: bool}
     *         What it rendered, or why not and whether the site's requests to itself failed.
     */
    private static function render($url, $mode, array $check) {
        self::$count++;
        $headers = array('Cache-Control' => 'no-cache');
        // As Site Health's loopback test: pass on HTTP authentication.
        if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication, passed on unchanged, as core does.
            $headers['Authorization'] = 'Basic ' . base64_encode(wp_unslash($_SERVER['PHP_AUTH_USER']) . ':' . wp_unslash($_SERVER['PHP_AUTH_PW']));
        }
        $response = wp_remote_get(add_query_arg(SEOProStack_Plugin_Loader::VERIFY_ARG, $mode . '.' . $check['token'] . '.' . self::$count, $url), array(
            'timeout'     => self::TIMEOUT, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- scheduled task; an uncached page can take seconds.
            'redirection' => 0,
            'headers'     => $headers,
            'cookies'     => array(),
            // Core's filter for requests to the site itself, as its loopback test uses.
            'sslverify'   => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        ));
        if (is_wp_error($response)) {
            return array('error' => substr($response->get_error_message(), 0, 200), 'retry' => true);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return array('error' => 'status ' . $code, 'retry' => false);
        }
        // Without the header, the page was not rendered as asked: a cache
        // answered, or the loader did not see this address and kind.
        $said = wp_remote_retrieve_header($response, strtolower(SEOProStack_Plugin_Loader::VERIFY_HEADER));
        if (!is_string($said) || !preg_match('/^(base|trial)(?: (\S*))?$/', trim($said), $match) || $match[1] !== $mode) {
            return array('error' => 'not rendered as asked', 'retry' => false);
        }
        $effects = self::effects(self::$count);
        if (null === $effects) {
            return array('error' => 'what the page did was not reported', 'retry' => true);
        }
        $html = self::normalise((string) wp_remote_retrieve_body($response));
        if (null === $html) {
            return array('error' => 'page too large to compare', 'retry' => false);
        }
        $found = array();
        foreach (self::HEADERS as $name) {
            $value = wp_remote_retrieve_header($response, $name);
            $value = is_array($value) ? implode("\n", $value) : (string) $value;
            $found[$name] = 'expires' === $name ? self::expires($value, wp_remote_retrieve_header($response, 'date')) : $value;
        }
        $cookies = array();
        foreach (wp_remote_retrieve_cookies($response) as $cookie) {
            $cookies[] = $cookie->name;
        }
        sort($cookies);
        $found['set-cookie'] = implode(',', $cookies);
        $skipped = array_values(array_filter(array_map('rawurldecode', explode(',', (string) ($match[2] ?? '')))));
        sort($skipped);
        return array('html' => $html, 'headers' => $found, 'skipped' => $skipped, 'effects' => $effects);
    }

    /**
     * An Expires header as minutes from the response's Date: caches and
     * plugins send the time of the response plus a lifetime, so the date
     * itself is new on every render (GitHub issue #1039).
     *
     * @param string       $expires Expires header.
     * @param string|array $date    Date header.
     * @return string
     */
    private static function expires($expires, $date) {
        $at   = '' !== $expires ? strtotime($expires) : false;
        $date = is_array($date) ? (string) reset($date) : (string) $date;
        $now  = '' !== $date ? strtotime($date) : false;
        if (false === $at) {
            return $expires; // Not a date ("0", "-1"): as sent.
        }
        $delta = $at - (false === $now ? time() : $now);
        // Expired, or within half a minute of the response: "now" (the two
        // clocks are read a moment apart).
        return $delta < MINUTE_IN_SECONDS / 2 ? 'now' : '+' . (int) round($delta / MINUTE_IN_SECONDS) . 'm';
    }

    /**
     * The plugins that wrote, sent or mailed while a render ran, as it saved
     * them before WordPress flushed the page (SEOProStack_Plugin_Loader::
     * report_effects()); null when that render saved nothing.
     *
     * @param int $number The render's number.
     * @return string[]|null
     */
    private static function effects($number) {
        for ($try = 0; $try < 2; $try++) {
            wp_cache_delete(SEOProStack_Plugin_Loader::FRONT_VERIFY_SEEN, 'options');
            wp_cache_delete('notoptions', 'options');
            $seen = get_option(SEOProStack_Plugin_Loader::FRONT_VERIFY_SEEN, array());
            if (is_array($seen) && (int) ($seen['n'] ?? 0) === $number) {
                return array_values(array_filter((array) ($seen['effects'] ?? array()), 'is_string'));
            }
            if (0 === $try) {
                sleep(1); // A plugin may end the response before the shutdown tasks.
            }
        }
        return null;
    }

    /**
     * HTML as tags and text: no comments (cache and timing notes), no
     * asset versions or nonces, and white space collapsed.
     *
     * @param string $html Page.
     * @return string|null Null when it could not be read.
     */
    private static function normalise($html) {
        // Cloudflare's Rocket Loader marks each script and inline handler
        // with a new token on every response (type="TOKEN-text/javascript",
        // data-cf-modified-TOKEN-), named in its own data-cf-settings: on a
        // page with many scripts, too many words changed to compare it.
        if (preg_match('/\sdata-cf-settings=["\']([0-9a-f]{16,40})-/', $html, $rocket)) {
            $html = str_replace($rocket[1] . '-', '', $html);
        }
        $patterns = array(
            '/<!--.*?-->/s'                                                                => '',
            '/([?&](?:#038;|amp;)?ver=)[^"\'&\s<>]*/'                                      => '$1',
            '/((?:nonce|_wpnonce)["\']?\s*(?::|=|value=)\s*["\']?)[0-9a-f]{10}(?![0-9a-f])/i' => '$1',
            '/\s+/'                                                                        => ' ',
        );
        foreach ($patterns as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html);
            if (null === $html) {
                return null;
            }
        }
        return trim($html);
    }

    /**
     * Whether two renders are the same, but for words that change on each
     * render of the first (its 'volatile' positions); if not, where they
     * first differ.
     *
     * @param array  $a    The render as served.
     * @param array  $b    The other.
     * @param string $note Set to the first difference.
     * @return bool
     */
    private static function same(array $a, array $b, &$note) {
        foreach ($a['headers'] as $name => $value) {
            if ($value !== ($b['headers'][$name] ?? '')) {
                $note = substr($name . ': ' . $value . ' | ' . ($b['headers'][$name] ?? ''), 0, 200);
                return false;
            }
        }
        if ($a['html'] === $b['html']) {
            return true;
        }
        if (!empty($a['volatile'])) {
            $words = self::words($a['html']);
            $other = self::words($b['html']);
            if (count($words) === count($other) && !array_diff_key(array_diff_assoc($words, $other), $a['volatile'])) {
                return true;
            }
        }
        $at   = strspn($a['html'] ^ $b['html'], "\0");
        $from = max(0, $at - 40);
        $note = substr($a['html'], $from, 80) . ' | ' . substr($b['html'], $from, 80);
        return false;
    }

    /**
     * Save what a check found for an address, if the content and plugins
     * did not change while it ran.
     *
     * @param array  $item     Queued address: key, path, kind.
     * @param array  $result   What check() found.
     * @param string $revision Content revision when it started.
     * @param string $active   Plugin set fingerprint when it started.
     * @return bool Whether checks are now paused.
     */
    private static function save(array $item, array $result, $revision, $active) {
        wp_cache_delete(SEOProStack_Plugin_Loader::FRONT_REVISION, 'options');
        wp_cache_delete('alloptions', 'options');
        if ((string) get_option(SEOProStack_Plugin_Loader::FRONT_REVISION, '') !== $revision || !SEOProStack_Plugin_Loader::front_current()) {
            return false;
        }
        $map = self::map($revision, $active);
        $map['last'] = array(
            'time'   => time(),
            'result' => (string) $result['result'],
            'skip'   => count($result['skip']),
            'note'   => wp_check_invalid_utf8(substr((string) $result['note'], 0, 200), true),
        );
        if (!empty($result['retry'])) {
            // The site could not render the page for the check: try later.
            $map['failures'] = (int) ($map['failures'] ?? 0) + 1;
            $paused = $map['failures'] >= self::FAILURES;
            if ($paused) {
                $map['paused']   = time() + HOUR_IN_SECONDS;
                $map['failures'] = 0;
            }
            SEOProStack_Plugin_Loader::save_option(SEOProStack_Plugin_Loader::FRONT_PAGES, $map, false);
            return $paused;
        }
        $map['failures'] = 0;
        $map['paused']   = 0;
        $set = -1;
        if ($result['skip']) {
            $skip = array_values(array_unique($result['skip']));
            sort($skip);
            $set = array_search($skip, (array) $map['sets'], true);
            if (false === $set) {
                $map['sets'][] = $skip;
                $set = count($map['sets']) - 1;
            }
            $set = (int) $set;
            $map['kinds'][$item['kind']]['hint'] = $set;
        }
        if ($result['needed']) {
            $needed = array_merge((array) ($map['kinds'][$item['kind']]['needed'] ?? array()), $result['needed']);
            $map['kinds'][$item['kind']]['needed'] = array_slice(array_values(array_unique($needed)), 0, 100);
        }
        $pages = (string) preg_replace('/\|' . $item['key'] . ':[^|]*/', '', (string) $map['pages']);
        $pages .= '|' . $item['key'] . ':' . $set . ':' . intdiv(time(), HOUR_IN_SECONDS);
        $over = substr_count($pages, '|') - self::MAX_PAGES;
        if ($over > 0) {
            $cut   = 0;
            for ($i = 0; $i < $over; $i++) {
                $cut = (int) strpos($pages, '|', $cut + 1);
            }
            $pages = substr($pages, $cut);
        }
        $map['pages'] = $pages;
        SEOProStack_Plugin_Loader::save_option(SEOProStack_Plugin_Loader::FRONT_PAGES, self::compact($map), false);
        return false;
    }

    /**
     * Drop skip sets no address uses any more, once there are many.
     *
     * @param array $map The checked addresses.
     * @return array
     */
    private static function compact(array $map) {
        if (count((array) $map['sets']) <= 64) {
            return $map;
        }
        preg_match_all('/:(-?\d+):\d+/', (string) $map['pages'], $used);
        $keep = array();
        $sets = array();
        foreach (array_unique(array_map('intval', $used[1])) as $old) {
            if ($old >= 0 && isset($map['sets'][$old])) {
                $keep[$old] = count($sets);
                $sets[]     = $map['sets'][$old];
            }
        }
        $map['pages'] = (string) preg_replace_callback('/:(-?\d+):(\d+)/', function ($match) use ($keep) {
            return ':' . ((int) $match[1] >= 0 && isset($keep[(int) $match[1]]) ? $keep[(int) $match[1]] : -1) . ':' . $match[2];
        }, (string) $map['pages']);
        $map['sets'] = $sets;
        foreach ((array) $map['kinds'] as $kind => $stats) {
            if (isset($stats['hint'])) {
                if (isset($keep[(int) $stats['hint']])) {
                    $map['kinds'][$kind]['hint'] = $keep[(int) $stats['hint']];
                } else {
                    unset($map['kinds'][$kind]['hint']);
                }
            }
        }
        return $map;
    }

    /**
     * What the checks found, for the settings screen.
     *
     * @return array{checked: int, skipping: int, average: float, queued: int, paused: int, last: array}
     */
    public static function summary() {
        $map   = get_option(SEOProStack_Plugin_Loader::FRONT_PAGES, array());
        $map   = is_array($map) ? $map : array();
        $queue = get_option(SEOProStack_Plugin_Loader::FRONT_QUEUE, array());
        $front = get_option(SEOProStack_Plugin_Loader::FRONT, array());
        $current = is_array($front) && ($map['revision'] ?? null) === (string) get_option(SEOProStack_Plugin_Loader::FRONT_REVISION, '')
            && ($map['active'] ?? null) === ($front['active'] ?? false);
        $checked  = 0;
        $skipping = 0;
        $total    = 0;
        if ($current) {
            preg_match_all('/:(-?\d+):\d+/', (string) ($map['pages'] ?? ''), $sets);
            foreach ($sets[1] as $set) {
                $checked++;
                if ((int) $set >= 0) {
                    $skipping++;
                    $total += count((array) ($map['sets'][(int) $set] ?? array()));
                }
            }
        }
        return array(
            'checked'  => $checked,
            'skipping' => $skipping,
            'average'  => $skipping ? round($total / $skipping, 1) : 0.0,
            'queued'   => is_array($queue) ? count($queue) : 0,
            'paused'   => (int) ($map['paused'] ?? 0) > time() ? (int) $map['paused'] : 0,
            'last'     => (array) ($map['last'] ?? array()),
        );
    }
}
