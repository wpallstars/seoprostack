<?php
/**
 * Calls to other sites: which plugin, theme or must-use plugin calls which
 * host through WordPress's HTTP API, how often, how long the calls take and
 * where they are made, with slow calls marked and the owner's choice for
 * each source and host: allow, limit to 3 seconds while someone waits, block
 * on visitor pages or block everywhere. Replaces HTTP Requests Manager.
 *
 * Counted per source (plugin, theme, must-use plugin, WordPress, SEO Pro
 * Stack) and host. A call another filter answered without sending (Ask
 * before licence checks, a block here) counts as not sent. Calls someone
 * waits for (visitor pages, admin, AJAX, REST) are also timed on their own,
 * to mark slow ones. Only the path of the last call is kept, without its
 * query, so no keys are stored.
 *
 * Always allowed: payment hosts, WordPress.org, the site itself, calls by
 * WordPress or SEO Pro Stack, and anything WordPress's update code runs.
 *
 * Stored, none autoloaded: counts in seoprostack_outbound_calls; choices in
 * seoprostack_outbound_blocks (named when there were only blocks: an entry
 * without a mode blocks everywhere); the last changes to the choices in
 * seoprostack_outbound_history. Counts are written at shutdown, only on
 * requests that made calls; visitor pages are sampled (SAMPLE), so most page
 * views write nothing.
 *
 * Nothing is imported from HTTP Requests Manager: its rules cover a whole
 * plugin or a whole host and apply only in its Smart block mode, so they do
 * not fit one source and host. While it is active, the Plugins screen names
 * what it does that this does not (hrm_extras()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Outbound_Calls extends SEOProStack_Feature {

    const KEY = 'outbound_calls';

    /** Plugins this feature replaces. */
    const REPLACES = array('http-requests-manager' => 'HTTP Requests Manager');

    /** Counts: since, rows. */
    const OPTION = 'seoprostack_outbound_calls';

    /** Choices: ID => source, host, mode, time, user. */
    const BLOCKS = 'seoprostack_outbound_blocks';

    /** Changes to the choices, newest first: time, user, source, host, from, to. */
    const HISTORY = 'seoprostack_outbound_history';

    const PAGE   = 'seoprostack-calls';
    const ACTION = 'seoprostack_outbound_calls';

    /** Rows kept; the least recently seen go first. */
    const MAX_ROWS = 200;

    /** Changes to the choices kept. */
    const MAX_HISTORY = 50;

    /** Visitor pages counted: 1 in this many, each counting this many times, so most page views write nothing. */
    const SAMPLE = 20;

    /** Seconds a limited call may take while someone waits; a wait this long also counts as slow. */
    const LIMIT = 3;

    /** Average wait, in milliseconds, from which calls count as slow. */
    const SLOW_MS = 1000;

    /** Hosts never blocked: payments, WordPress.org and WordPress.com (WooPayments, Jetpack). */
    const NEVER_BLOCK = array(
        'stripe.com', 'stripe.network', 'paypal.com', 'paypalobjects.com', 'braintreegateway.com',
        'braintree-api.com', 'squareup.com', 'squareupsandbox.com', 'mollie.com', 'klarna.com',
        'klarnaservices.com', 'adyen.com', 'adyenpayments.com', 'authorize.net', 'checkout.com',
        'razorpay.com', 'paystack.co', 'payfast.co.za', 'gocardless.com', 'wordpress.org', 'w.org',
        'wordpress.com', 'wp.com',
    );

    /**
     * This request's counts: ID => row changes.
     *
     * @var array<string,array>
     */
    private static $found = array();

    /**
     * Calls under way: [ID, address, start].
     *
     * @var array<int,array{0:string,1:string,2:int|float}>
     */
    private static $open = array();

    /**
     * Choices, loaded on the first call of the request.
     *
     * @var array<string,array>|null
     */
    private static $blocks = null;

    /**
     * Hosts with a choice, by the modes asked for.
     *
     * @var array<string,string[]>
     */
    private static $hosts = array();

    /**
     * How many times this request's calls count: 1, SAMPLE on a sampled
     * visitor page, 0 on other visitor pages; null until the first call.
     *
     * @var int|null
     */
    private static $weight = null;

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
                'tab'         => 'server',
                'label'       => __('Calls to other sites', 'seoprostack'),
                'description' => __('Lists which plugin calls which other site, how often, how long people wait and where, under Tools → Calls to other sites, and marks slow calls. For each, choose Allow, Limit to 3 seconds, Block on visitor pages or Block everywhere; payment services, WordPress.org and updates are always allowed.', 'seoprostack'),
                'replaces'    => self::REPLACES,
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        // Replacement advice is needed even while the feature waits or is off.
        if (is_admin()) {
            add_filter('seoprostack_replaced_plugin_extras', array(__CLASS__, 'hrm_extras'), 10, 2);
        }
        if (!self::enabled()) {
            return;
        }
        // Late, so a limit chosen here holds whatever timeout other filters set.
        add_filter('http_request_args', array(__CLASS__, 'limit'), PHP_INT_MAX, 2);
        // Before Ask before licence checks (100), so a block wins.
        add_filter('pre_http_request', array(__CLASS__, 'block'), 90, 3);
        // Last, so calls other filters answered count as not sent.
        add_filter('pre_http_request', array(__CLASS__, 'start'), PHP_INT_MAX, 3);
        add_action('http_api_debug', array(__CLASS__, 'end'), 10, 5);
        add_action('shutdown', array(__CLASS__, 'save'), PHP_INT_MAX);
        if (is_admin()) {
            add_action('admin_menu', array(__CLASS__, 'menu'));
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
            add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
        }
    }

    /**
     * What HTTP Requests Manager does on this site that this feature does
     * not, for the Plugins screen while it is active: Smart block's limits
     * and block rules, or blocking every call.
     *
     * @param string[] $extras Plain names.
     * @param string   $slug   Plugin folder.
     * @return string[]
     */
    public static function hrm_extras($extras, $slug) {
        if (!isset(self::REPLACES[$slug])) {
            return $extras;
        }
        $theirs = get_option('http-requests-manager', array());
        $theirs = is_array($theirs) ? $theirs : array();
        $mode   = isset($theirs['mode']) && is_string($theirs['mode']) ? $theirs['mode'] : 'log';
        if ('block_external' === $mode) {
            $extras[] = __('Blocking every call to other sites', 'seoprostack');
        } elseif ('block_external_no_wp' === $mode) {
            $extras[] = __('Blocking every call to other sites except WordPress.org', 'seoprostack');
        } elseif ('block_smart' === $mode) {
            $extras[] = __('Smart block’s limits on every call (2 seconds, one redirect, 3 calls or 3 seconds a page)', 'seoprostack');
            $blocked = array();
            foreach (isset($theirs['custom_rules']) && is_array($theirs['custom_rules']) ? $theirs['custom_rules'] : array() as $rule) {
                if (!is_array($rule) || !isset($rule['block'])) {
                    continue;
                }
                if (!empty($rule['domain'])) {
                    $blocked[] = (string) $rule['domain'];
                } elseif (!empty($rule['plugin'])) {
                    $blocked[] = (string) $rule['plugin'];
                } else {
                    $blocked[] = __('every call', 'seoprostack');
                }
            }
            $blocked = array_values(array_unique($blocked));
            if (1 === count($blocked)) {
                /* translators: %s: host name or plugin folder */
                $extras[] = sprintf(__('A rule blocking %s', 'seoprostack'), $blocked[0]);
            } elseif ($blocked) {
                $more = count($blocked) - 1;
                /* translators: 1: host name or plugin folder; 2: how many more */
                $extras[] = sprintf(_n('Rules blocking %1$s and %2$s more', 'Rules blocking %1$s and %2$s more', $more, 'seoprostack'), $blocked[0], number_format_i18n($more));
            }
        }
        return array_values(array_unique($extras));
    }

    /**
     * Drop the done notice from the address after showing it.
     *
     * @param string[] $args Query args.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = 'sps_done';
        return $args;
    }

    /* ------------------------------------------------------------------
     * Choices: limiting and blocking
     * ------------------------------------------------------------------ */

    /**
     * Cut the timeout of a call the owner limited, while someone waits.
     *
     * @param array  $args Request arguments.
     * @param string $url  Address.
     * @return array
     */
    public static function limit($args, $url) {
        // Downloads keep their own time. Calls that do not read the answer
        // are limited too: with cURL, WordPress still waits for them, up to
        // their timeout.
        if (!is_array($args) || !empty($args['stream']) || !empty($args['filename'])) {
            return $args;
        }
        $timeout = isset($args['timeout']) ? (float) $args['timeout'] : 0.0;
        // A timeout of 0 never gives up.
        if ($timeout > 0 && $timeout <= self::LIMIT) {
            return $args;
        }
        $host = self::host((string) $url);
        if ('' === $host || !in_array($host, self::hosts(array('limit')), true) || !self::someone_waits()) {
            return $args;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the code making a call to a limited host, only for such calls.
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        if (SEOProStack_Licence_Calls::during_updates($trace)) {
            return $args;
        }
        $source = SEOProStack_Licence_Calls::source($trace, true);
        $choice = self::blocks()[self::id($source, $host)] ?? null;
        if (!is_array($choice) || 'limit' !== self::mode($choice) || self::never_block($host, $source)) {
            return $args;
        }
        $args['timeout'] = self::LIMIT;
        return $args;
    }

    /**
     * Block a source's calls to a host the owner blocked, everywhere or on
     * visitor pages.
     *
     * @param false|array|WP_Error $pre  Answer from an earlier filter.
     * @param array                $args Request arguments.
     * @param string               $url  Address.
     * @return false|array|WP_Error
     */
    public static function block($pre, $args, $url) { // NOSONAR: $args is in the pre_http_request signature, before $url.
        if (false !== $pre) {
            return $pre;
        }
        $modes = 'page' === self::where() ? array('block', 'pages') : array('block');
        $host  = self::host((string) $url);
        if ('' === $host || !in_array($host, self::hosts($modes), true)) {
            return $pre;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the code making a call to a blocked host, only for such calls.
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        if (SEOProStack_Licence_Calls::during_updates($trace)) {
            return $pre;
        }
        $source = SEOProStack_Licence_Calls::source($trace, true);
        $choice = self::blocks()[self::id($source, $host)] ?? null;
        if (!is_array($choice) || !in_array(self::mode($choice), $modes, true) || self::never_block($host, $source)) {
            return $pre;
        }
        return new WP_Error(
            'seoprostack_blocked',
            /* translators: %s: host name */
            sprintf(__('SEO Pro Stack blocked this call to %s (Tools → Calls to other sites).', 'seoprostack'), $host)
        );
    }

    /**
     * Choices.
     *
     * @return array<string,array>
     */
    private static function blocks() {
        if (null === self::$blocks) {
            $blocks       = get_option(self::BLOCKS, array());
            self::$blocks = is_array($blocks) ? array_filter($blocks, 'is_array') : array();
        }
        return self::$blocks;
    }

    /**
     * Hosts with a choice in one of the modes.
     *
     * @param string[] $modes Modes.
     * @return string[]
     */
    private static function hosts(array $modes) {
        $key = implode(',', $modes);
        if (!isset(self::$hosts[$key])) {
            self::$hosts[$key] = array();
            foreach (self::blocks() as $choice) {
                if (isset($choice['host']) && in_array(self::mode($choice), $modes, true)) {
                    self::$hosts[$key][] = (string) $choice['host'];
                }
            }
        }
        return self::$hosts[$key];
    }

    /**
     * Mode of a choice: allow, limit, pages (block on visitor pages) or
     * block. Blocks saved before there were modes block everywhere.
     *
     * @param array $choice Choice.
     * @return string
     */
    private static function mode(array $choice) {
        $mode = isset($choice['mode']) ? (string) $choice['mode'] : 'block';
        return isset(self::choices()[$mode]) ? $mode : 'block';
    }

    /**
     * The choices, by mode.
     *
     * @return array<string,string>
     */
    private static function choices() {
        return array(
            'allow' => __('Allow', 'seoprostack'),
            /* translators: %s: seconds */
            'limit' => sprintf(__('Limit to %s seconds', 'seoprostack'), number_format_i18n(self::LIMIT)),
            'pages' => __('Block on visitor pages', 'seoprostack'),
            'block' => __('Block everywhere', 'seoprostack'),
        );
    }

    /* ------------------------------------------------------------------
     * Counting
     * ------------------------------------------------------------------ */

    /**
     * A call starts, or another filter answered it.
     *
     * @param false|array|WP_Error $pre  Answer from an earlier filter.
     * @param array                $args Request arguments.
     * @param string               $url  Address.
     * @return false|array|WP_Error
     */
    public static function start($pre, $args, $url) { // NOSONAR: $args is in the pre_http_request signature, before $url.
        $host = self::host((string) $url);
        if ('' === $host) {
            return $pre;
        }
        if (null === self::$weight) {
            self::$weight = 1;
            if ('page' === self::where()) {
                self::$weight = 1 === wp_rand(1, self::SAMPLE) ? self::SAMPLE : 0;
            }
        }
        if (!self::$weight) {
            return $pre;
        }
        // A plugin's own update checks count for that plugin (they are never blocked).
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the code making a call, once per call.
        $source = SEOProStack_Licence_Calls::source(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), true);
        $id     = self::id($source, $host);
        if (!isset(self::$found[$id])) {
            self::$found[$id] = array('source' => $source, 'host' => $host);
        }
        self::$found[$id]['path']  = substr((string) wp_parse_url((string) $url, PHP_URL_PATH), 0, 100);
        self::$found[$id]['where'] = self::where();
        self::add($id, 'calls', 1);
        if (false !== $pre) {
            self::add($id, 'skipped', 1);
            return $pre;
        }
        self::$open[] = array($id, (string) $url, hrtime(true));
        return $pre;
    }

    /**
     * A call ended.
     *
     * @param array|WP_Error $response Response or error.
     * @param string         $context  Context ("response").
     * @param string         $class    Transport class.
     * @param array          $args     Request arguments.
     * @param string         $url      Address.
     */
    public static function end($response, $context, $class, $args, $url) {
        unset($class);
        if ('response' !== $context) {
            return;
        }
        // The innermost open call to this address; calls refused before
        // they were sent (an unsafe address) never end, so skip past them.
        for ($i = count(self::$open) - 1; $i >= 0; $i--) {
            if (self::$open[$i][1] === (string) $url) {
                break;
            }
        }
        if ($i < 0) {
            return;
        }
        list($id, , $started) = self::$open[$i];
        array_splice(self::$open, $i);
        $ms = (hrtime(true) - $started) / 1e6;
        self::add($id, 'sent', 1);
        self::add($id, 'ms', $ms);
        self::$found[$id]['max'] = max($ms, self::$found[$id]['max'] ?? 0);
        if (self::someone_waits()) {
            // Someone waited for this call: timed apart, to mark slow calls.
            // Calls that do not read the answer count too: with cURL,
            // WordPress still waits for them, up to their timeout.
            self::add($id, 'waits', 1);
            self::add($id, 'wait_ms', $ms);
            self::$found[$id]['wait_max'] = max($ms, self::$found[$id]['wait_max'] ?? 0);
        }
        if (is_wp_error($response)) {
            self::add($id, 'errors', 1);
            self::$found[$id]['answer'] = substr($response->get_error_message(), 0, 120);
        } elseif (is_array($args) && isset($args['blocking']) && !$args['blocking']) {
            // The caller did not ask for the answer (WordPress's cron spawn): there is none to judge.
            self::$found[$id]['answer'] = __('Answer not read', 'seoprostack');
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 400 || 0 === $code) {
                self::add($id, 'errors', 1);
            }
            self::$found[$id]['answer'] = 0 === $code ? __('No answer', 'seoprostack') : (string) $code;
        }
    }

    /**
     * Add to a row's count.
     *
     * @param string    $id    Row ID.
     * @param string    $what  Count.
     * @param int|float $value Amount.
     */
    private static function add($id, $what, $value) {
        self::$found[$id][$what] = (self::$found[$id][$what] ?? 0) + $value;
    }

    /**
     * Merge this request's counts into the stored ones.
     */
    public static function save() {
        if (!self::$found) {
            return;
        }
        $found       = self::$found;
        self::$found = array();
        $stored      = self::stored();
        $now         = time();
        foreach ($found as $id => $change) {
            $row = $stored['rows'][$id] ?? array(
                'source'   => $change['source'],
                'host'     => $change['host'],
                'calls'    => 0,
                'sent'     => 0,
                'skipped'  => 0,
                'errors'   => 0,
                'ms'       => 0.0,
                'max'      => 0.0,
                'waits'    => 0,
                'wait_ms'  => 0.0,
                'wait_max' => 0.0,
                'where'    => array(),
                'first'    => $now,
            );
            foreach (array('calls', 'sent', 'skipped', 'errors', 'ms', 'waits', 'wait_ms') as $sum) {
                $row[$sum] = ($row[$sum] ?? 0) + ($change[$sum] ?? 0) * (int) self::$weight;
            }
            $row['ms']       = round((float) $row['ms'], 1);
            $row['wait_ms']  = round((float) $row['wait_ms'], 1);
            $row['max']      = round(max((float) ($row['max'] ?? 0), (float) ($change['max'] ?? 0)), 1);
            $row['wait_max'] = round(max((float) ($row['wait_max'] ?? 0), (float) ($change['wait_max'] ?? 0)), 1);
            $row['where'][$change['where']] = true;
            $row['path'] = $change['path'] ?? '';
            if (isset($change['answer'])) {
                $row['answer'] = $change['answer'];
            }
            $row['last'] = $now;
            $stored['rows'][$id] = $row;
        }
        if (count($stored['rows']) > self::MAX_ROWS) {
            uasort($stored['rows'], function ($a, $b) {
                return (int) $b['last'] <=> (int) $a['last'];
            });
            $stored['rows'] = array_slice($stored['rows'], 0, self::MAX_ROWS, true);
        }
        update_option(self::OPTION, $stored, false);
    }

    /**
     * Stored counts.
     *
     * @return array{since:int,rows:array<string,array>}
     */
    private static function stored() {
        $stored = get_option(self::OPTION, array());
        if (!is_array($stored) || !isset($stored['rows']) || !is_array($stored['rows'])) {
            return array('since' => time(), 'rows' => array());
        }
        return array(
            'since' => isset($stored['since']) ? (int) $stored['since'] : time(),
            'rows'  => $stored['rows'],
        );
    }

    /**
     * Whether a row's calls are slow: someone waited 3 seconds or more for
     * one, or 1 second or more on average.
     *
     * @param array $row Counts.
     * @return bool
     */
    public static function slow(array $row) {
        $waits = (int) ($row['waits'] ?? 0);
        if ($waits < 1) {
            return false;
        }
        return (float) ($row['wait_max'] ?? 0) >= self::LIMIT * 1000 || (float) ($row['wait_ms'] ?? 0) / $waits >= self::SLOW_MS;
    }

    /**
     * Sites that visitors' pages waited for, most waiting first (for Hosting
     * needs), leaving out calls the owner blocked. Empty while the feature
     * is off.
     *
     * @param int $limit Most hosts to return.
     * @return string[] Host names.
     */
    public static function page_hosts($limit = 3) {
        if (!self::enabled()) {
            return array();
        }
        $waits  = array();
        $blocks = self::blocks();
        // The site's own address: WordPress starts cron by calling it.
        // Calls that do not read the answer count: with cURL, the page
        // still waits for them, up to their timeout.
        $own = array(self::host(home_url()), self::host(site_url()));
        foreach (self::stored()['rows'] as $id => $row) {
            $blocked = isset($blocks[$id]) && in_array(self::mode($blocks[$id]), array('block', 'pages'), true);
            if (!$blocked && !empty($row['where']['page']) && !empty($row['sent']) && !empty($row['host']) && !in_array($row['host'], $own, true)) {
                $host         = (string) $row['host'];
                $waits[$host] = ($waits[$host] ?? 0) + (float) ($row['ms'] ?? 0);
            }
        }
        arsort($waits);
        return array_slice(array_map('strval', array_keys($waits)), 0, max(1, (int) $limit));
    }

    /**
     * Lower-case host of an address.
     *
     * @param string $url Address.
     * @return string
     */
    private static function host($url) {
        return strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    }

    /**
     * Row ID of a source and host.
     *
     * @param string $source Source.
     * @param string $host   Host.
     * @return string
     */
    private static function id($source, $host) {
        return substr(md5($source . '|' . $host), 0, 12); // NOSONAR: a row key, not security.
    }

    /**
     * Where this request runs.
     *
     * @return string
     */
    private static function where() {
        if (defined('WP_CLI') && WP_CLI) {
            return 'cli';
        }
        if (wp_doing_cron()) {
            return 'cron';
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'rest';
        }
        if (wp_doing_ajax()) {
            return 'ajax';
        }
        return is_admin() ? 'admin' : 'page';
    }

    /**
     * Whether someone waits for this request: a visitor page, an admin
     * screen, AJAX or REST, not cron or WP-CLI.
     *
     * @return bool
     */
    private static function someone_waits() {
        return !in_array(self::where(), array('cli', 'cron'), true);
    }

    /**
     * Whether a source's calls to a host are always allowed (never limited
     * or blocked).
     *
     * @param string $host   Host.
     * @param string $source Source.
     * @return bool
     */
    public static function never_block($host, $source) {
        $never = in_array($source, array('core', 'seoprostack'), true);
        foreach (self::NEVER_BLOCK as $domain) {
            if ($host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain) {
                $never = true;
                break;
            }
        }
        foreach (array(home_url(), site_url()) as $own) {
            if (strtolower((string) wp_parse_url($own, PHP_URL_HOST)) === $host) {
                $never = true;
            }
        }
        /**
         * Filter whether a source's calls to a host are always allowed in
         * Calls to other sites, never limited or blocked (payment services,
         * WordPress.org, the site itself, WordPress and SEO Pro Stack by
         * default).
         *
         * @param bool   $never  Whether they are always allowed.
         * @param string $host   Host.
         * @param string $source "plugin:folder", "mu:file", "theme:folder", "core" or "seoprostack".
         */
        return (bool) apply_filters('seoprostack_outbound_never_block', $never, $host, $source);
    }

    /* ------------------------------------------------------------------
     * Tools → Calls to other sites
     * ------------------------------------------------------------------ */

    /**
     * Add the Tools page.
     */
    public static function menu() {
        add_management_page(__('Calls to other sites', 'seoprostack'), __('Calls to other sites', 'seoprostack'), 'manage_options', self::PAGE, array(__CLASS__, 'page'));
    }

    /**
     * Save a choice, or clear the list.
     */
    public static function handle() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        $do   = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $id   = isset($_POST['row']) ? sanitize_key(wp_unslash($_POST['row'])) : '';
        $done = '';
        if ('forget' === $do) {
            update_option(self::OPTION, array('since' => time(), 'rows' => array()), false);
            $done = 'forgot';
        } elseif ('choose' === $do) {
            $done = self::choose($id, isset($_POST['choice']) ? sanitize_key(wp_unslash($_POST['choice'])) : '');
        }
        wp_safe_redirect(add_query_arg('sps_done', $done, admin_url('tools.php?page=' . self::PAGE)));
        exit;
    }

    /**
     * Save the choice for a row and note the change.
     *
     * @param string $id     Row ID.
     * @param string $choice Mode, or '' for none.
     * @return string What was done, for the notice; '' for nothing.
     */
    private static function choose($id, $choice) {
        if ('' !== $choice && !isset(self::choices()[$choice])) {
            return '';
        }
        wp_cache_delete(self::BLOCKS, 'options');
        self::$blocks = null;
        $blocks       = self::blocks();
        $rows         = self::stored()['rows'];
        $known        = $rows[$id] ?? $blocks[$id] ?? null;
        if (!is_array($known) || !isset($known['source'], $known['host']) || self::never_block((string) $known['host'], (string) $known['source'])) {
            return '';
        }
        $before = isset($blocks[$id]) ? self::mode($blocks[$id]) : '';
        if ($before === $choice) {
            return '';
        }
        $now = time();
        if ('' === $choice) {
            unset($blocks[$id]);
        } else {
            $blocks[$id] = array(
                'source' => (string) $known['source'],
                'host'   => (string) $known['host'],
                'mode'   => $choice,
                'time'   => $now,
                'user'   => get_current_user_id(),
            );
        }
        update_option(self::BLOCKS, $blocks, false);
        self::$blocks = $blocks;
        self::$hosts  = array();

        $history = get_option(self::HISTORY, array());
        $history = is_array($history) ? array_values(array_filter($history, 'is_array')) : array();
        array_unshift($history, array(
            'time'   => $now,
            'user'   => get_current_user_id(),
            'source' => (string) $known['source'],
            'host'   => (string) $known['host'],
            'from'   => $before,
            'to'     => $choice,
        ));
        update_option(self::HISTORY, array_slice($history, 0, self::MAX_HISTORY), false);
        return '' === $choice ? 'cleared' : $choice;
    }

    /**
     * The Tools page.
     */
    public static function page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'));
        }
        $stored = self::stored();
        $rows   = $stored['rows'];
        $blocks = self::blocks();
        // Choices for calls not made since the list was cleared still show, so they can be changed.
        foreach ($blocks as $id => $choice) {
            if (!isset($rows[$id]) && isset($choice['source'], $choice['host'])) {
                $rows[$id] = array('source' => $choice['source'], 'host' => $choice['host'], 'calls' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0, 'ms' => 0, 'max' => 0, 'where' => array(), 'last' => 0);
            }
        }
        uasort($rows, function ($a, $b) {
            return array((float) $b['ms'], (int) $b['calls']) <=> array((float) $a['ms'], (int) $a['calls']);
        });
        $done    = isset($_GET['sps_done']) ? sanitize_key(wp_unslash($_GET['sps_done'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice after a redirect.
        $notices = array(
            'allow'   => __('Saved: those calls go ahead as before, and the row shows you have checked it.', 'seoprostack'),
            /* translators: %s: seconds */
            'limit'   => sprintf(__('Saved: while someone waits, those calls now give up after %s seconds.', 'seoprostack'), number_format_i18n(self::LIMIT)),
            'pages'   => __('Saved: on visitor pages, those calls now fail straight away.', 'seoprostack'),
            'block'   => __('Saved: those calls now fail straight away.', 'seoprostack'),
            'cleared' => __('Choice removed: those calls go ahead as before.', 'seoprostack'),
            'forgot'  => __('The list is cleared. Choices stay.', 'seoprostack'),
        );
        echo '<div class="wrap"><h1>' . esc_html__('Calls to other sites', 'seoprostack') . '</h1>';
        if (isset($notices[$done])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notices[$done]) . '</p></div>';
        }
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: how long ago counting started */
            __('Calls made through WordPress since %s ago, slowest first. A page waits for each call it makes, up to the call’s time limit, even when the call does not read the answer. Not sent: another plugin, Ask before licence checks or a choice here answered without calling. Visitor pages are counted on 1 view in 20, each counting 20 times, so their numbers are estimates.', 'seoprostack'),
            human_time_diff((int) $stored['since'])
        )) . '</p>';
        if (!$rows) {
            echo '<p>' . esc_html__('No calls yet.', 'seoprostack') . '</p></div>';
            return;
        }
        $waiting = 0;
        foreach ($rows as $id => $row) {
            if (!isset($blocks[$id]) && self::slow($row) && !self::never_block((string) $row['host'], (string) $row['source'])) {
                $waiting++;
            }
        }
        if ($waiting) {
            echo '<p><strong>' . esc_html(sprintf(
                /* translators: %s: number of rows */
                _n('%s slow call has no choice yet. It is marked Slow below.', '%s slow calls have no choice yet. They are marked Slow below.', $waiting, 'seoprostack'),
                number_format_i18n($waiting)
            )) . '</strong></p>';
        }
        self::guide();
        // Scrolls on its own on narrow screens, so the page does not.
        echo '<div class="sps-calls__scroll"><table class="widefat striped sps-calls"><thead><tr>';
        foreach (array(__('From', 'seoprostack'), __('Site', 'seoprostack'), __('Calls', 'seoprostack'), __('Time', 'seoprostack'), __('Last answer', 'seoprostack'), __('Where', 'seoprostack'), __('Last call', 'seoprostack'), __('Choice', 'seoprostack')) as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $id => $row) {
            self::row((string) $id, $row, $blocks[$id] ?? null);
        }
        echo '</tbody></table></div><p>';
        self::form('forget', __('Clear the list', 'seoprostack'));
        echo '</p>';
        self::history();
        echo '</div>';
        ?>
        <style>
            /* Relative, so the screen reader labels in it (absolutely placed) scroll with it, not the page. */
            .sps-calls__scroll { position: relative; overflow-x: auto; max-width: 100%; }
            .sps-calls__slow { color: #b32d2e; font-weight: 600; }
            .sps-calls__choose { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
            .sps-calls__by { display: block; margin-top: 4px; color: #646970; }
            .sps-calls-guide { margin: 0 0 12px 18px; list-style: disc; }
        </style>
        <?php
    }

    /**
     * What each choice does.
     */
    private static function guide() {
        $lines = array(
            array(__('Allow', 'seoprostack'), __('the calls go ahead as now, and the row shows you have checked it.', 'seoprostack')),
            array(
                self::choices()['limit'],
                /* translators: %s: seconds */
                sprintf(__('while someone waits for a page, an admin screen, AJAX or REST, each call gives up after %s seconds. Cron, WP-CLI and downloads are not limited.', 'seoprostack'), number_format_i18n(self::LIMIT)),
            ),
            array(__('Block on visitor pages', 'seoprostack'), __('on visitor pages the calls fail straight away, as if the other site could not be reached; elsewhere they go ahead.', 'seoprostack')),
            array(__('Block everywhere', 'seoprostack'), __('the calls fail straight away.', 'seoprostack')),
            array(__('Always allowed', 'seoprostack'), __('payment services, WordPress.org and WordPress.com, this site, WordPress, SEO Pro Stack, and updates.', 'seoprostack')),
        );
        echo '<ul class="sps-calls-guide">';
        foreach ($lines as $line) {
            echo '<li><strong>' . esc_html($line[0]) . ':</strong> ' . esc_html($line[1]) . '</li>';
        }
        echo '</ul>';
    }

    /**
     * One row of the Tools page.
     *
     * @param string     $id     Row ID.
     * @param array      $row    Counts.
     * @param array|null $choice The owner's choice, if any.
     */
    private static function row($id, array $row, $choice) {
        $names = array(
            'page'  => __('visitor pages', 'seoprostack'),
            'admin' => __('admin', 'seoprostack'),
            'ajax'  => __('AJAX', 'seoprostack'),
            'cron'  => __('cron', 'seoprostack'),
            'rest'  => __('REST', 'seoprostack'),
            'cli'   => __('WP-CLI', 'seoprostack'),
        );
        $sent = (int) $row['sent'];
        /* translators: 1: calls sent; 2: calls not sent */
        $calls = sprintf(__('%1$s sent, %2$s not sent', 'seoprostack'), number_format_i18n($sent), number_format_i18n((int) $row['skipped']));
        if ((int) $row['errors']) {
            /* translators: %s: number of failed calls */
            $calls .= ', ' . sprintf(_n('%s failed', '%s failed', (int) $row['errors'], 'seoprostack'), number_format_i18n((int) $row['errors']));
        }
        $time = '–';
        if ($sent) {
            /* translators: 1: total time; 2: average time; 3: slowest call */
            $time = esc_html(sprintf(__('%1$s in all, %2$s on average, slowest %3$s', 'seoprostack'), self::ms((float) $row['ms']), self::ms((float) $row['ms'] / $sent), self::ms((float) $row['max'])));
        }
        if (self::slow($row)) {
            $time .= '<br><span class="sps-calls__slow">' . esc_html__('Slow', 'seoprostack') . '</span> ' . esc_html(sprintf(
                /* translators: 1: longest wait; 2: average wait */
                __('people waited up to %1$s, %2$s on average', 'seoprostack'),
                self::ms((float) $row['wait_max']),
                self::ms((float) $row['wait_ms'] / max(1, (int) $row['waits']))
            ));
        }
        /* translators: %s: time ago */
        $last = $row['last'] ? sprintf(__('%s ago', 'seoprostack'), human_time_diff((int) $row['last'])) : '–';
        $from = self::source_name((string) $row['source']);
        echo '<tr><td>' . esc_html($from) . '</td>';
        echo '<td>' . esc_html($row['host']) . (empty($row['path']) ? '' : '<br><code>' . esc_html($row['path']) . '</code>') . '</td>';
        echo '<td>' . esc_html($calls) . '</td><td>' . $time . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
        echo '<td>' . esc_html((string) ($row['answer'] ?? '–')) . '</td><td>' . esc_html(implode(', ', array_intersect_key($names, (array) $row['where']))) . '</td><td>' . esc_html($last) . '</td><td>';
        // Checked first: a stored choice here is never applied (see limit() and block()).
        if (self::never_block((string) $row['host'], (string) $row['source'])) {
            echo esc_html__('Always allowed', 'seoprostack');
        } else {
            self::choice_form($id, $from . ' → ' . $row['host'], $choice);
        }
        echo '</td></tr>';
    }

    /**
     * The choice of a row: a list and Save, then who chose and when.
     *
     * @param string     $id     Row ID.
     * @param string     $label  Plugin and host, for screen readers.
     * @param array|null $choice The owner's choice, if any.
     */
    private static function choice_form($id, $label, $choice) {
        $mode = is_array($choice) ? self::mode($choice) : '';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="sps-calls__choose">';
        wp_nonce_field(self::ACTION, '_wpnonce', true, true);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="do" value="choose"><input type="hidden" name="row" value="' . esc_attr($id) . '">';
        /* translators: %s: plugin and host */
        echo '<label class="screen-reader-text" for="sps-choice-' . esc_attr($id) . '">' . esc_html(sprintf(__('Choice for %s', 'seoprostack'), $label)) . '</label>';
        echo '<select name="choice" id="sps-choice-' . esc_attr($id) . '">';
        foreach (array_merge(array('' => __('Not decided', 'seoprostack')), self::choices()) as $value => $text) {
            echo '<option value="' . esc_attr((string) $value) . '"' . selected($mode, (string) $value, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select>';
        submit_button(__('Save', 'seoprostack'), 'secondary small', 'submit', false);
        echo '</form>';
        if (is_array($choice) && !empty($choice['time'])) {
            /* translators: 1: person; 2: time ago */
            echo '<span class="sps-calls__by">' . esc_html(sprintf(__('Chosen by %1$s, %2$s ago', 'seoprostack'), self::person((int) ($choice['user'] ?? 0)), human_time_diff((int) $choice['time']))) . '</span>';
        }
    }

    /**
     * The last changes to the choices.
     */
    private static function history() {
        $history = get_option(self::HISTORY, array());
        $history = is_array($history) ? array_filter($history, 'is_array') : array();
        if (!$history) {
            return;
        }
        $names  = array_merge(array('' => __('Not decided', 'seoprostack')), self::choices());
        $format = get_option('date_format') . ' ' . get_option('time_format');
        echo '<h2>' . esc_html__('Choices made', 'seoprostack') . '</h2>';
        /* translators: %s: number of changes kept */
        echo '<p>' . esc_html(sprintf(__('The last %s changes, newest first.', 'seoprostack'), number_format_i18n(self::MAX_HISTORY))) . '</p><ul class="sps-calls-guide">';
        foreach ($history as $entry) {
            $from = (string) ($entry['from'] ?? '');
            $to   = (string) ($entry['to'] ?? '');
            echo '<li>' . esc_html(sprintf(
                /* translators: 1: date and time; 2: person; 3: plugin; 4: host; 5: new choice; 6: earlier choice */
                __('%1$s, %2$s: %3$s → %4$s set to %5$s (was %6$s)', 'seoprostack'),
                (string) wp_date($format, (int) ($entry['time'] ?? 0)),
                self::person((int) ($entry['user'] ?? 0)),
                self::source_name((string) ($entry['source'] ?? '')),
                (string) ($entry['host'] ?? ''),
                $names[$to] ?? $to,
                $names[$from] ?? $from
            )) . '</li>';
        }
        echo '</ul>';
    }

    /**
     * Name of WordPress, SEO Pro Stack, or a plugin, theme or must-use plugin.
     *
     * @param string $source Source.
     * @return string
     */
    private static function source_name($source) {
        $sources = array(
            'core'        => __('WordPress', 'seoprostack'),
            'seoprostack' => __('SEO Pro Stack', 'seoprostack'),
        );
        return $sources[$source] ?? SEOProStack_Licence_Calls::source_name($source);
    }

    /**
     * Name of the person who made a choice.
     *
     * @param int $user User ID.
     * @return string
     */
    private static function person($user) {
        $data = $user ? get_userdata($user) : false;
        /* translators: %d: user ID */
        return $data ? (string) $data->display_name : sprintf(__('user %d', 'seoprostack'), $user);
    }

    /**
     * A one-button form.
     *
     * @param string $do    Action.
     * @param string $label Button label.
     * @param string $id    Row ID.
     */
    private static function form($do, $label, $id = '') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="row" value="' . esc_attr($id) . '">';
        submit_button($label, 'secondary small', 'submit', false);
        echo '</form>';
    }

    /**
     * Milliseconds or seconds, for people.
     *
     * @param float $ms Milliseconds.
     * @return string
     */
    private static function ms($ms) {
        if ($ms >= 1000) {
            /* translators: %s: seconds */
            return sprintf(__('%s s', 'seoprostack'), number_format_i18n($ms / 1000, 1));
        }
        /* translators: %s: milliseconds */
        return sprintf(__('%s ms', 'seoprostack'), number_format_i18n($ms, $ms < 10 ? 1 : 0));
    }
}
