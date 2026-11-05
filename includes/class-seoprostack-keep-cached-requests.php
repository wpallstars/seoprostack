<?php
/**
 * Keep pages cached: the page cache on this site, and opening a page as a
 * visitor so the cache stores it (see SEOProStack_Keep_Pages_Cached).
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

final class SEOProStack_Keep_Cached_Requests {

    /** Seconds each page may take. */
    const TIMEOUT = 15;

    /** As a desktop browser, and as a phone (the cache's mobile copy). */
    const AGENT  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 SEOProStack-KeepCached';
    const MOBILE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 SEOProStack-KeepCached';

    /** Answers that mean the server refuses requests for now (bot protection, limits, maintenance). */
    const REFUSED = array(403, 429, 503);

    /** The server answered 428 to LiteSpeed Cache's crawler: the host turned it off. */
    private static $crawler_off = false;

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
        return self::wp_optimize_config('enable_page_caching') ? 'wp-optimize' : '';
    }

    /**
     * Whether a crawler opens the rest of this site after a purge.
     *
     * @return bool
     */
    public static function covered() {
        return self::litespeed_crawler() || ('wp-optimize' === self::page_cache() && self::wp_optimize_preloads());
    }

    /**
     * Whether LiteSpeed Cache's crawler is on for this site.
     *
     * @return bool
     */
    public static function litespeed_crawler() {
        return 'litespeed' === self::page_cache() && (bool) SEOProStack_Litespeed::conf('crawler', false);
    }

    /**
     * Whether the page cache keeps a separate copy for phones (LiteSpeed
     * Cache's mobile cache, WP-Optimize's mobile caching).
     *
     * @return bool
     */
    public static function mobile_copy() {
        $cache = self::page_cache();
        if ('litespeed' === $cache) {
            return (bool) SEOProStack_Litespeed::conf('cache-mobile', false);
        }
        return 'wp-optimize' === $cache && self::wp_optimize_config('enable_mobile_caching');
    }

    /**
     * Whether WP-Optimize preloads its page cache after a purge: its
     * preload after purge or scheduled preload, with its page cache on.
     *
     * @return bool
     */
    public static function wp_optimize_preloads() {
        return self::wp_optimize_config('enable_page_caching')
            && (self::wp_optimize_config('auto_preload_purged_contents') || self::wp_optimize_config('enable_schedule_preload'));
    }

    /**
     * One of WP-Optimize's page cache settings, through its own code; when
     * "Load plugins only where needed" skipped it on this request, from the
     * option its code reads (a network option on multisite).
     *
     * @param string $key Setting.
     * @return bool
     */
    private static function wp_optimize_config($key) {
        if (class_exists('WPO_Cache_Config') && is_callable(array('WPO_Cache_Config', 'instance'))) {
            $config = WPO_Cache_Config::instance()->get();
        } elseif (SEOProStack_Plugin_Loader::is_active('wp-optimize/wp-optimize.php') || SEOProStack_Plugin_Loader::is_active('wp-optimize-premium/wp-optimize.php')) {
            $config = is_multisite() ? get_site_option('wpo_cache_config') : get_option('wpo_cache_config');
        } else {
            return false;
        }
        return is_array($config) && !empty($config[$key]);
    }

    /**
     * Open one page as a visitor who is not logged in: also as a phone
     * where the page cache keeps a mobile copy, and straight to this
     * server when its address is known (resolver()). On a LiteSpeed page
     * cache, as LiteSpeed Cache's crawler does (its user agent first), so
     * LiteSpeed's bot protection lets the request through.
     *
     * @param string $url   Page address.
     * @param bool   $mobile Also as a phone.
     * @param int    $blog  Site that owns this address, on a network.
     * @return array[] Each user agent's code and cache header (for WP-CLI).
     */
    public static function open($url, $mobile, $blog = 0) {
        if ($blog) {
            switch_to_blog($blog);
        }
        $resolve = self::resolver($url);
        $crawler = 'litespeed' === self::page_cache();
        $answers = array();
        if ($resolve) {
            add_action('http_api_curl', $resolve);
        }
        foreach ($mobile ? array(self::AGENT, self::MOBILE) : array(self::AGENT) as $agent) {
            $answer = null;
            if ($crawler && !self::$crawler_off) {
                // The phone's user agent still matches LiteSpeed Cache's mobile rule.
                $answer = self::get($url, SEOProStack_Litespeed::CRAWLER_AGENT . ' ' . $agent);
                self::$crawler_off = 428 === $answer['code'];
                if (201 === $answer['code'] && '' === $answer['cache']) {
                    // LiteSpeed answers its crawler 201, without the page, when it holds the page already.
                    $answer['cache'] = 'hit';
                }
            }
            $answers[] = null === $answer || self::$crawler_off ? self::get($url, $agent) : $answer;
        }
        if ($resolve) {
            remove_action('http_api_curl', $resolve);
        }
        if ($blog) {
            restore_current_blog();
        }
        return $answers;
    }

    /**
     * Whether the server refused any of these answers for now, so the
     * rest should wait: LiteSpeed's bot protection (403, and a plain 403
     * for a while after too many failed checks), a limit (429) or
     * maintenance (503).
     *
     * @param array[] $answers From open().
     * @return bool
     */
    public static function refused(array $answers) {
        foreach ($answers as $answer) {
            if (in_array($answer['code'], self::REFUSED, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A page's HTML, to read its links, as a visitor who is not logged in
     * gets it. On a LiteSpeed page cache it is opened as open() opens it
     * (LiteSpeed Cache's crawler first, so the page is cached as well), and
     * once more as a visitor when LiteSpeed answers its crawler 201 for a
     * page it holds already, which it sends without the page.
     *
     * @param string $url Page address.
     * @return string The HTML, or '' when the page did not answer 200.
     */
    public static function page_html($url) {
        $resolve = self::resolver($url);
        if ($resolve) {
            add_action('http_api_curl', $resolve);
        }
        $agents = array(self::AGENT);
        if ('litespeed' === self::page_cache() && !self::$crawler_off) {
            array_unshift($agents, SEOProStack_Litespeed::CRAWLER_AGENT . ' ' . self::AGENT);
        }
        $html = '';
        foreach ($agents as $agent) {
            $response = self::request($url, $agent, 4 * MB_IN_BYTES);
            $code     = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            if (200 === $code) {
                $html = (string) wp_remote_retrieve_body($response);
                break;
            }
            if (428 === $code) {
                // As in open(): the host turned LiteSpeed Cache's crawler off.
                self::$crawler_off = true;
            } elseif (201 !== $code) {
                // Refused, missing or no answer: no links this time.
                break;
            }
        }
        if ($resolve) {
            remove_action('http_api_curl', $resolve);
        }
        return $html;
    }

    /**
     * Open the page once with this user agent.
     *
     * @param string $url   Page address.
     * @param string $agent User agent.
     * @return array{code:int|string,cache:string}
     */
    private static function get($url, $agent) {
        return self::answer(self::request($url, $agent));
    }

    /**
     * One request for the page with this user agent.
     *
     * @param string $url   Page address.
     * @param string $agent User agent.
     * @param int    $limit Most bytes of the page to read, or 0 for all.
     * @return array|WP_Error
     */
    private static function request($url, $agent, $limit = 0) {
        $args = array(
            'timeout'     => self::TIMEOUT, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- in WP-Cron, never on a visitor's page: an uncached page can take several seconds to build.
            'redirection' => 0,
            'user-agent'  => $agent,
            'headers'     => array('Accept' => 'text/html,application/xhtml+xml'),
            // Core's filter for requests to the site itself, as its loopback test uses.
            'sslverify'   => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        );
        if ($limit) {
            $args['limit_response_size'] = $limit;
        }
        return wp_remote_get($url, $args);
    }

    /**
     * How a page answered.
     *
     * @param array|WP_Error $response Response.
     * @return array{code:int|string,cache:string}
     */
    private static function answer($response) {
        if (is_wp_error($response)) {
            return array('code' => $response->get_error_message(), 'cache' => '');
        }
        return array(
            'code'  => (int) wp_remote_retrieve_response_code($response),
            'cache' => implode(' ', (array) wp_remote_retrieve_header($response, 'x-litespeed-cache')),
        );
    }

    /**
     * Send requests for this page straight to LiteSpeed Cache's Server IP,
     * as its crawler does, so a CDN in front neither answers nor times out.
     * With Server IP empty, to the address SEO Pro Stack learned from a web
     * request when the host name resolves elsewhere (#1243).
     *
     * @param string $url Page address.
     * @return callable|null http_api_curl callback, or null to use DNS.
     */
    public static function resolver($url) {
        if ('litespeed' !== self::page_cache() || !defined('CURLOPT_RESOLVE')) {
            return null;
        }
        $address = SEOProStack_Litespeed::server_ip();
        $learned = false;
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && class_exists('SEOProStack_Litespeed_Crawler')) {
            // Server IP is empty: the address learned from a web request, if any.
            $address = SEOProStack_Litespeed_Crawler::learned();
            $learned = true;
        }
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ('' === $host || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $port = (int) wp_parse_url($url, PHP_URL_PORT);
        if (!$port) {
            $port = 'https' === wp_parse_url($url, PHP_URL_SCHEME) ? 443 : 80;
        }
        $pin = $host . ':' . $port . ':' . $address;
        return static function ($handle) use ($pin, $learned) {
            if ($learned && '' === SEOProStack_Litespeed_Crawler::learned(false)) {
                return; // Forgotten after a request that could not connect: use DNS.
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the HTTP API's own handle, at its http_api_curl hook.
            curl_setopt($handle, CURLOPT_RESOLVE, array($pin));
            if ($learned) {
                add_action('http_api_debug', array('SEOProStack_Keep_Cached_Requests', 'settle'), 10, 2);
            }
        };
    }

    /**
     * After a request sent to a learned address: forget the address when
     * it could not connect (the server moved) or a CDN answered instead of
     * this server. A slow page that timed out after connecting keeps it.
     * DNS is used until a web request notes this server's address again.
     *
     * @param array|WP_Error $response Response.
     * @param string         $context  response, or the stage of the request.
     */
    public static function settle($response, $context = 'response') {
        remove_action('http_api_debug', array(__CLASS__, 'settle'), 10);
        if ('response' !== $context) {
            return;
        }
        if (is_wp_error($response)) {
            // WpOrg\Requests' message is "cURL error N: ...". 7: could not connect; 28 before
            // connecting: "Connection timed out after ..." or "Failed to connect to ... Timeout was reached".
            $message = $response->get_error_message();
            $forget  = 0 === strpos($message, 'cURL error 7:')
                || (0 === strpos($message, 'cURL error 28:') && (false !== stripos($message, 'connection timed out') || false !== stripos($message, 'failed to connect')));
        } else {
            $forget = '' !== implode('', (array) wp_remote_retrieve_header($response, 'cf-ray'));
        }
        if ($forget) {
            SEOProStack_Litespeed_Crawler::forget();
        }
    }
}
