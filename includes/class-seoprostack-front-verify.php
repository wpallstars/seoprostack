<?php
/**
 * Check which plugins a kind of page renders the same without.
 *
 * Part of Load plugins only where needed. Most plugins add something to
 * pages (wp_head, scripts, body classes), so learning keeps them on every
 * page of the site, even where what they add is nothing. When a page kind
 * is learned, SEOProStack_Plugin_Loading::front_propose() lists the plugins
 * it might also skip, and a scheduled task renders the learned page through
 * the site's own address:
 *
 * - twice as it is served now ("base"), to see that the page renders the
 *   same each time;
 * - then without the listed plugins ("trial"), halving the list when the
 *   page changes, a few times at most.
 *
 * Each render carries a one-time token (SEOProStack_Plugin_Loader::
 * verify_request()), is kept out of page caches, and says in a header which
 * plugins it skipped. Only plugins whose absence left the status, the
 * headers that matter and the HTML the same are saved for the kind, and a
 * post of the same type is then checked too. Pages of that kind skip them
 * until anything makes the site's pages learn again. A site whose requests
 * to itself fail, time out or are answered by a cache skips nothing more.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.104
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Front_Verify {

    /** Trial renders per check: the whole list, then halves. */
    const TRIALS = 6;

    /** Seconds one render may take. */
    const TIMEOUT = 15;

    /** Seconds after which a check starts no more renders. */
    const BUDGET = 60;

    /** Most words that may change on each render (random IDs) for a page to be compared. */
    const VOLATILE = 50;

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
     * Check one page kind, once per learning.
     *
     * @param string $key Page kind, such as "single:post".
     */
    public static function run($key) {
        if (!SEOProStack_Plugin_Loader::front_current()) {
            return;
        }
        $front = get_option(SEOProStack_Plugin_Loader::FRONT, array());
        $kind  = $front['kinds'][$key] ?? null;
        if (!is_array($kind) || empty($kind['propose']) || empty($kind['url']) || isset($kind['verify'])) {
            return;
        }
        $learned = (int) ($kind['learned'] ?? 0);
        if (!self::local((string) $kind['url'])) {
            self::save($key, $learned, array('skip' => array(), 'result' => 'address', 'note' => '', 'pages' => 0));
            return;
        }
        $busy = get_option(SEOProStack_Plugin_Loader::FRONT_VERIFY, array());
        if (is_array($busy) && (int) ($busy['expires'] ?? 0) > time()) {
            wp_schedule_single_event(time() + 2 * MINUTE_IN_SECONDS, SEOProStack_Plugin_Loading::VERIFY_HOOK, array($key));
            return; // One check at a time.
        }
        $check = array(
            'token'   => wp_generate_password(32, false, false),
            'kind'    => $key,
            'skip'    => array(),
            'expires' => time() + self::BUDGET + 5 * self::TIMEOUT,
        );
        update_option(SEOProStack_Plugin_Loader::FRONT_VERIFY, $check, false);
        $result = self::check($key, $kind, $check);
        SEOProStack_Plugin_Loader::drop_option(SEOProStack_Plugin_Loader::FRONT_VERIFY);
        self::save($key, $learned, $result);
        self::next();
    }

    /**
     * Render the page as served, then without the proposed plugins.
     *
     * @param string $key   Page kind.
     * @param array  $kind  What was learned for it.
     * @param array  $check The check under way (token, kind, skip, expires).
     * @return array{skip: string[], result: string, note: string, pages: int}
     */
    private static function check($key, array $kind, array $check) {
        $start = microtime(true);
        $url   = (string) $kind['url'];
        $note  = '';
        $base  = self::baseline($url, $check, $note);
        if (!is_array($base)) {
            return array('skip' => array(), 'result' => $base, 'note' => $note, 'pages' => 0);
        }

        $groups = array(array_values((array) $kind['propose']));
        $tried  = array();
        $passed = null;
        $trials = 0;
        $first  = '';
        while ($groups && $trials < self::TRIALS && SEOProStack_Feature::more_time($start, self::BUDGET)) {
            $group = array_shift($groups);
            $check['skip'] = array_values(array_unique(array_merge($tried, $group)));
            update_option(SEOProStack_Plugin_Loader::FRONT_VERIFY, $check, false);
            $trial = self::render($url, 'trial', $check);
            $trials++;
            if (is_array($trial) && self::same($base, $trial, $note)) {
                $tried  = $check['skip'];
                $passed = $trial; // Each pass renders everything that passed before, together.
                continue;
            }
            $first = '' !== $first ? $first : (is_array($trial) ? $note : $trial);
            if (count($group) > 1) {
                $half = (int) ceil(count($group) / 2);
                array_unshift($groups, array_slice($group, 0, $half), array_slice($group, $half));
            }
        }
        $skip = $passed ? array_values(array_diff($passed['skipped'], $base['skipped'])) : array();
        if (!$skip) {
            return array('skip' => array(), 'result' => 'differs', 'note' => $first, 'pages' => 1);
        }

        // One page is one sample: a post of the same type must agree.
        $pages = 1;
        if (0 === strpos($key, 'single:') && SEOProStack_Feature::more_time($start, self::BUDGET)) {
            $check['skip'] = $tried;
            update_option(SEOProStack_Plugin_Loader::FRONT_VERIFY, $check, false);
            foreach (self::other_posts(substr($key, 7), (int) ($kind['id'] ?? 0)) as $other) {
                $other_base = self::baseline($other, $check, $note);
                if ('failed' === $other_base) {
                    continue; // Not a page this kind serves (its own template or content), or it failed as served.
                }
                $other_trial = is_array($other_base) ? self::render($other, 'trial', $check) : $other_base;
                if (!is_array($other_base) || !is_array($other_trial) || !self::same($other_base, $other_trial, $note)) {
                    return array('skip' => array(), 'result' => 'differs', 'note' => is_array($other_trial) ? $note : $other_trial, 'pages' => 2);
                }
                $pages = 2;
                break;
            }
        }
        return array('skip' => $skip, 'result' => 'verified', 'note' => '', 'pages' => $pages);
    }

    /**
     * A page rendered as served, twice: the first render, with the words
     * that changed between the two (random IDs a plugin makes on each
     * render), which a trial may change too.
     *
     * @param string $url   Page address.
     * @param array  $check The check under way.
     * @param string $note  Set to why there is none.
     * @return array|string The render with 'volatile' positions, or 'failed' or 'unstable'.
     */
    private static function baseline($url, array $check, &$note) {
        $base = self::render($url, 'base', $check);
        if (!is_array($base)) {
            $note = $base;
            return 'failed';
        }
        $again = self::render($url, 'base', $check);
        if (!is_array($again)) {
            $note = $again;
            return 'failed';
        }
        $base['volatile'] = array();
        if (self::same($base, $again, $note)) {
            return $base;
        }
        $words = self::words($base['html']);
        $other = self::words($again['html']);
        if ($base['headers'] !== $again['headers'] || $base['skipped'] !== $again['skipped'] || count($words) !== count($other)) {
            return 'unstable'; // Changes shape on each render: nothing can be compared.
        }
        $volatile = array_keys(array_diff_assoc($words, $other));
        if (count($volatile) > self::VOLATILE) {
            $note = sprintf('%d words change on each render', count($volatile));
            return 'unstable';
        }
        $base['volatile'] = array_fill_keys($volatile, true);
        return $base;
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
     * Render a page through the site's own address.
     *
     * @param string $url   Page address.
     * @param string $mode  'base' or 'trial'.
     * @param array  $check The check under way.
     * @return array{html: string, headers: array<string,string>, skipped: string[]}|string What it rendered, or why not.
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
            return substr($response->get_error_message(), 0, 200);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return 'status ' . $code;
        }
        // Without the header, the page was not rendered as asked: a cache
        // answered, or the loader did not see this kind of page.
        $said = wp_remote_retrieve_header($response, strtolower(SEOProStack_Plugin_Loader::VERIFY_HEADER));
        if (!is_string($said) || !preg_match('/^(base|trial)(?: (\S*))?$/', trim($said), $match) || $match[1] !== $mode) {
            return 'not rendered as asked';
        }
        $html = self::normalise((string) wp_remote_retrieve_body($response));
        if (null === $html) {
            return 'page too large to compare';
        }
        $found = array();
        foreach (self::HEADERS as $name) {
            $value = wp_remote_retrieve_header($response, $name);
            $found[$name] = is_array($value) ? implode("\n", $value) : (string) $value;
        }
        $cookies = array();
        foreach (wp_remote_retrieve_cookies($response) as $cookie) {
            $cookies[] = $cookie->name;
        }
        sort($cookies);
        $found['set-cookie'] = implode(',', $cookies);
        $skipped = array_filter(array_map('rawurldecode', explode(',', (string) ($match[2] ?? ''))));
        sort($skipped);
        return array('html' => $html, 'headers' => $found, 'skipped' => $skipped);
    }

    /**
     * HTML as tags and text: no comments (cache and timing notes), no
     * asset versions or nonces, and white space collapsed.
     *
     * @param string $html Page.
     * @return string|null Null when it could not be read.
     */
    private static function normalise($html) {
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
     * Up to two other published posts of a type, most recently changed first.
     *
     * @param string $type Post type.
     * @param int    $not  The post that was learned.
     * @return string[] Addresses.
     */
    private static function other_posts($type, $not) {
        $ids = get_posts(array(
            'post_type'     => $type,
            'post_status'   => 'publish',
            'has_password'  => false,
            'numberposts'   => 3,
            'orderby'       => 'modified',
            'order'         => 'DESC',
            'fields'        => 'ids',
            'no_found_rows' => true,
        ));
        $urls = array();
        foreach ($ids as $id) {
            if ((int) $id === $not || count($urls) >= 2) {
                continue;
            }
            $url = get_permalink((int) $id);
            if (is_string($url) && self::local($url)) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * Whether an address is on the site's own host.
     *
     * @param string $url Address.
     * @return bool
     */
    private static function local($url) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        return is_string($host) && '' !== $host && strtolower($host) === strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))
            && in_array(wp_parse_url($url, PHP_URL_SCHEME), array('http', 'https'), true);
    }

    /**
     * Save the result for the kind, if it was not learned again meanwhile.
     *
     * @param string $key     Page kind.
     * @param int    $learned When it was learned.
     * @param array  $result  skip (plugins it renders the same without),
     *                        result ('verified', 'differs', 'unstable',
     *                        'failed' or 'address'), note (first difference
     *                        or failure) and pages (how many were compared).
     */
    private static function save($key, $learned, array $result) {
        wp_cache_delete(SEOProStack_Plugin_Loader::FRONT, 'options');
        wp_cache_delete('alloptions', 'options');
        $front = get_option(SEOProStack_Plugin_Loader::FRONT, array());
        if (!SEOProStack_Plugin_Loader::front_current() || !isset($front['kinds'][$key]) || !is_array($front['kinds'][$key])
            || (int) ($front['kinds'][$key]['learned'] ?? 0) !== $learned) {
            return;
        }
        $front['kinds'][$key]['verify'] = array(
            'time'   => time(),
            'result' => (string) $result['result'],
            'note'   => wp_check_invalid_utf8(substr((string) $result['note'], 0, 200), true),
            'pages'  => (int) ($result['pages'] ?? 0),
        );
        if (!empty($result['skip'])) {
            $front['kinds'][$key]['verified'] = array_values((array) $result['skip']);
        }
        SEOProStack_Plugin_Loader::save_option(SEOProStack_Plugin_Loader::FRONT, $front, true);
    }

    /** Schedule the next learned kind that has not been checked. */
    private static function next() {
        $front = get_option(SEOProStack_Plugin_Loader::FRONT, array());
        foreach ((array) ($front['kinds'] ?? array()) as $key => $kind) {
            if (is_array($kind) && !empty($kind['propose']) && !isset($kind['verify'])) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, SEOProStack_Plugin_Loading::VERIFY_HOOK, array((string) $key));
                return;
            }
        }
    }
}
