<?php
/**
 * Calls to other sites: which plugin, theme or must-use plugin calls which
 * host through WordPress's HTTP API, how often, how long the calls take and
 * where they are made, with a block per source and host.
 *
 * Counted per source (plugin, theme, must-use plugin, WordPress, SEO Pro
 * Stack) and host. A call another filter answered without sending (Ask
 * before licence checks, HTTP Requests Manager, a block here) counts as not
 * sent. Only the path of the last call is kept, without its query, so no
 * keys are stored.
 *
 * Never blocked: payment hosts, WordPress.org, the site itself, calls by
 * WordPress or SEO Pro Stack, and anything WordPress's update code runs.
 *
 * Stored: counts in seoprostack_outbound_calls and blocks in
 * seoprostack_outbound_blocks (both not autoloaded). Counts are written at
 * shutdown, only on requests that made calls.
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

class SEOProStack_Outbound_Calls extends SEOProStack_Feature {

    const KEY = 'outbound_calls';

    /** Counts: since, rows. */
    const OPTION = 'seoprostack_outbound_calls';

    /** Blocks: ID => source, host, time, user. */
    const BLOCKS = 'seoprostack_outbound_blocks';

    const PAGE   = 'seoprostack-calls';
    const ACTION = 'seoprostack_outbound_calls';

    /** Rows kept; the least recently seen go first. */
    const MAX_ROWS = 200;

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
     * Blocks, loaded on the first call of the request.
     *
     * @var array<string,array>|null
     */
    private static $blocks = null;

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
                'description' => __('Lists which plugin calls which other site, how often, how long pages wait and where, under Tools → Calls to other sites. Block a plugin’s calls to a site there; payment services, WordPress.org and updates are never blocked.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        // Before Ask before licence checks (100), so a block wins.
        add_filter('pre_http_request', array(__CLASS__, 'block'), 90, 3);
        // Last, so calls other filters answered count as not sent.
        add_filter('pre_http_request', array(__CLASS__, 'start'), PHP_INT_MAX, 3);
        add_action('http_api_debug', array(__CLASS__, 'end'), 10, 5);
        add_action('shutdown', array(__CLASS__, 'save'), PHP_INT_MAX);
        if (is_admin()) {
            add_action('admin_menu', array(__CLASS__, 'menu'));
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        }
    }

    /* ------------------------------------------------------------------
     * Counting and blocking
     * ------------------------------------------------------------------ */

    /**
     * Block a source's calls to a host the owner blocked.
     *
     * @param false|array|WP_Error $pre  Answer from an earlier filter.
     * @param array                $args Request arguments.
     * @param string               $url  Address.
     * @return false|array|WP_Error
     */
    public static function block($pre, $args, $url) {
        if (false !== $pre) {
            return $pre;
        }
        $host = self::host((string) $url);
        if ('' === $host || !in_array($host, array_column(self::blocks(), 'host'), true)) {
            return $pre;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the code making a call to a blocked host, only for such calls.
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        if (self::during_updates($trace)) {
            return $pre;
        }
        $source = self::source($trace);
        if (!isset(self::blocks()[self::id($source, $host)]) || self::never_block($host, $source)) {
            return $pre;
        }
        return new WP_Error(
            'seoprostack_blocked',
            /* translators: %s: host name */
            sprintf(__('SEO Pro Stack blocked this call to %s (Tools → Calls to other sites).', 'seoprostack'), $host)
        );
    }

    /**
     * A call starts, or another filter answered it.
     *
     * @param false|array|WP_Error $pre  Answer from an earlier filter.
     * @param array                $args Request arguments.
     * @param string               $url  Address.
     * @return false|array|WP_Error
     */
    public static function start($pre, $args, $url) {
        $host = self::host((string) $url);
        if ('' === $host) {
            return $pre;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the code making a call, once per call.
        $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $source = self::during_updates($trace) ? 'core' : self::source($trace);
        $id     = self::id($source, $host);
        $path   = substr((string) wp_parse_url((string) $url, PHP_URL_PATH), 0, 100);
        self::count($id, $source, $host, $path);
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
        unset($class, $args);
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
        if (is_wp_error($response)) {
            self::add($id, 'errors', 1);
            self::$found[$id]['answer'] = substr($response->get_error_message(), 0, 120);
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 400 || 0 === $code) {
                self::add($id, 'errors', 1);
            }
            self::$found[$id]['answer'] = 0 === $code ? __('No answer', 'seoprostack') : (string) $code;
        }
    }

    /**
     * Start a row's changes for this request.
     *
     * @param string $id     Row ID.
     * @param string $source type:slug, "core" or "seoprostack".
     * @param string $host   Host.
     * @param string $path   Path of the call.
     */
    private static function count($id, $source, $host, $path) {
        if (!isset(self::$found[$id])) {
            self::$found[$id] = array('source' => $source, 'host' => $host);
        }
        self::$found[$id]['path']  = $path;
        self::$found[$id]['where'] = self::where();
        self::add($id, 'calls', 1);
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
                'source' => $change['source'],
                'host'   => $change['host'],
                'calls'  => 0,
                'sent'   => 0,
                'skipped' => 0,
                'errors' => 0,
                'ms'     => 0.0,
                'max'    => 0.0,
                'where'  => array(),
                'first'  => $now,
            );
            foreach (array('calls', 'sent', 'skipped', 'errors', 'ms') as $sum) {
                $row[$sum] = ($row[$sum] ?? 0) + ($change[$sum] ?? 0);
            }
            $row['ms']  = round((float) $row['ms'], 1);
            $row['max'] = round(max((float) ($row['max'] ?? 0), (float) ($change['max'] ?? 0)), 1);
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
     * Blocks.
     *
     * @return array<string,array>
     */
    private static function blocks() {
        if (null === self::$blocks) {
            $blocks       = get_option(self::BLOCKS, array());
            self::$blocks = is_array($blocks) ? $blocks : array();
        }
        return self::$blocks;
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
     * Whether WordPress's update code made the call.
     *
     * @param array $trace Backtrace.
     * @return bool
     */
    private static function during_updates(array $trace) {
        $functions = array(
            'wp_update_plugins', 'wp_update_themes', 'wp_version_check', 'wp_maybe_auto_update',
            'plugins_api', 'themes_api', 'wp_get_update_data', 'get_plugin_updates', 'get_theme_updates', 'download_url',
        );
        foreach ($trace as $frame) {
            if (empty($frame['class']) && isset($frame['function']) && in_array($frame['function'], $functions, true)) {
                return true;
            }
            if (!empty($frame['class']) && (is_a($frame['class'], 'WP_Upgrader', true) || is_a($frame['class'], 'WP_Automatic_Updater', true))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The code nearest to the call: plugin, must-use plugin or theme
     * (type:slug), SEO Pro Stack ("seoprostack") or WordPress ("core").
     *
     * @param array $trace Backtrace.
     * @return string
     */
    private static function source(array $trace) {
        $roots = array(
            'plugin' => wp_normalize_path(WP_PLUGIN_DIR) . '/',
            'mu'     => wp_normalize_path(WPMU_PLUGIN_DIR) . '/',
            'theme'  => wp_normalize_path(get_theme_root()) . '/',
        );
        $own = wp_normalize_path(SEOPROSTACK_DIR);
        foreach ($trace as $frame) {
            if (empty($frame['file'])) {
                continue;
            }
            $file = wp_normalize_path($frame['file']);
            if (0 === strpos($file, $own)) {
                return 'seoprostack';
            }
            foreach ($roots as $type => $root) {
                if (0 === strpos($file, $root)) {
                    $rest = substr($file, strlen($root));
                    $slug = false !== strpos($rest, '/') ? substr($rest, 0, strpos($rest, '/')) : $rest;
                    return $type . ':' . $slug;
                }
            }
        }
        return 'core';
    }

    /**
     * Whether a source's calls to a host may never be blocked.
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
         * Filter whether a source's calls to a host may never be blocked in
         * Calls to other sites (payment services, WordPress.org, the site
         * itself, WordPress and SEO Pro Stack by default).
         *
         * @param bool   $never  Whether they may never be blocked.
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
     * Block, Allow or Forget.
     */
    public static function handle() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $id = isset($_POST['row']) ? sanitize_key(wp_unslash($_POST['row'])) : '';
        $blocks = self::blocks();
        $done   = '';
        if ('forget' === $do) {
            delete_option(self::OPTION);
            $done = 'forgot';
        } elseif ('allow' === $do && isset($blocks[$id])) {
            unset($blocks[$id]);
            update_option(self::BLOCKS, $blocks, false);
            $done = 'allowed';
        } elseif ('block' === $do) {
            $rows = self::stored()['rows'];
            if (isset($rows[$id]) && !self::never_block($rows[$id]['host'], $rows[$id]['source'])) {
                $blocks[$id] = array(
                    'source' => $rows[$id]['source'],
                    'host'   => $rows[$id]['host'],
                    'time'   => time(),
                    'user'   => get_current_user_id(),
                );
                update_option(self::BLOCKS, $blocks, false);
                $done = 'blocked';
            }
        }
        wp_safe_redirect(add_query_arg('sps_done', $done, admin_url('tools.php?page=' . self::PAGE)));
        exit;
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
        // Blocked pairs that made no call since the list was forgotten still show, so they can be allowed.
        foreach ($blocks as $id => $block) {
            if (!isset($rows[$id])) {
                $rows[$id] = array('source' => $block['source'], 'host' => $block['host'], 'calls' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0, 'ms' => 0, 'max' => 0, 'where' => array(), 'last' => 0);
            }
        }
        uasort($rows, function ($a, $b) {
            return array((float) $b['ms'], (int) $b['calls']) <=> array((float) $a['ms'], (int) $a['calls']);
        });
        $done = isset($_GET['sps_done']) ? sanitize_key(wp_unslash($_GET['sps_done'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice after a redirect.
        $notices = array(
            'blocked' => __('Blocked. That plugin’s calls to that site now fail straight away.', 'seoprostack'),
            'allowed' => __('Allowed again.', 'seoprostack'),
            'forgot'  => __('The list is cleared. Blocks stay.', 'seoprostack'),
        );
        $names = array(
            'page'  => __('visitor pages', 'seoprostack'),
            'admin' => __('admin', 'seoprostack'),
            'ajax'  => __('AJAX', 'seoprostack'),
            'cron'  => __('cron', 'seoprostack'),
            'rest'  => __('REST', 'seoprostack'),
            'cli'   => __('WP-CLI', 'seoprostack'),
        );
        echo '<div class="wrap"><h1>' . esc_html__('Calls to other sites', 'seoprostack') . '</h1>';
        if (isset($notices[$done])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notices[$done]) . '</p></div>';
        }
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: how long ago counting started */
            __('Calls made through WordPress since %s ago, slowest first. A page waits for each call it makes, unless the call is sent without waiting for the answer. Not sent: another plugin, Ask before licence checks or a block here answered without calling.', 'seoprostack'),
            human_time_diff((int) $stored['since'])
        )) . '</p>';
        if (!$rows) {
            echo '<p>' . esc_html__('No calls yet.', 'seoprostack') . '</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach (array(__('From', 'seoprostack'), __('Site', 'seoprostack'), __('Calls', 'seoprostack'), __('Time', 'seoprostack'), __('Last answer', 'seoprostack'), __('Where', 'seoprostack'), __('Last call', 'seoprostack'), __('Action', 'seoprostack')) as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $id => $row) {
            $sent = (int) $row['sent'];
            /* translators: 1: calls sent; 2: calls not sent */
            $calls = sprintf(__('%1$s sent, %2$s not sent', 'seoprostack'), number_format_i18n($sent), number_format_i18n((int) $row['skipped']));
            if ((int) $row['errors']) {
                /* translators: %s: number of failed calls */
                $calls .= ', ' . sprintf(_n('%s failed', '%s failed', (int) $row['errors'], 'seoprostack'), number_format_i18n((int) $row['errors']));
            }
            $time = $sent ? sprintf(
                /* translators: 1: total time; 2: average time; 3: slowest call */
                __('%1$s in all, %2$s on average, slowest %3$s', 'seoprostack'),
                self::ms((float) $row['ms']),
                self::ms((float) $row['ms'] / $sent),
                self::ms((float) $row['max'])
            ) : '–';
            $where = implode(', ', array_intersect_key($names, (array) $row['where']));
            $last  = $row['last'] ? sprintf(
                /* translators: %s: time ago */
                __('%s ago', 'seoprostack'),
                human_time_diff((int) $row['last'])
            ) : '–';
            echo '<tr><td>' . esc_html(self::source_name($row['source'])) . '</td>';
            echo '<td>' . esc_html($row['host']) . (empty($row['path']) ? '' : '<br><code>' . esc_html($row['path']) . '</code>') . '</td>';
            echo '<td>' . esc_html($calls) . '</td><td>' . esc_html($time) . '</td>';
            echo '<td>' . esc_html((string) ($row['answer'] ?? '–')) . '</td><td>' . esc_html($where) . '</td><td>' . esc_html($last) . '</td><td>';
            if (isset($blocks[$id])) {
                echo '<strong>' . esc_html__('Blocked', 'seoprostack') . '</strong> ';
                self::form('allow', __('Allow', 'seoprostack'), $id);
            } elseif (self::never_block($row['host'], $row['source'])) {
                echo esc_html__('Never blocked', 'seoprostack');
            } else {
                self::form('block', __('Block', 'seoprostack'), $id);
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p>';
        self::form('forget', __('Clear the list', 'seoprostack'));
        echo '</p></div>';
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
        /* translators: %s: seconds */
        return $ms >= 1000 ? sprintf(__('%s s', 'seoprostack'), number_format_i18n($ms / 1000, 1)) : sprintf(
            /* translators: %s: milliseconds */
            __('%s ms', 'seoprostack'),
            number_format_i18n($ms, $ms < 10 ? 1 : 0)
        );
    }

    /**
     * Name of a source.
     *
     * @param string $source Source.
     * @return string
     */
    private static function source_name($source) {
        if ('core' === $source) {
            return __('WordPress', 'seoprostack');
        }
        if ('seoprostack' === $source) {
            return __('SEO Pro Stack', 'seoprostack');
        }
        $parts = explode(':', (string) $source, 2);
        $slug  = $parts[1] ?? '';
        if ('plugin' === $parts[0]) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            foreach (get_plugins() as $file => $data) {
                if (dirname($file) === $slug || $file === $slug) {
                    return $data['Name'];
                }
            }
            return $slug;
        }
        if ('theme' === $parts[0]) {
            $theme = wp_get_theme($slug);
            /* translators: %s: theme name */
            return sprintf(__('%s (theme)', 'seoprostack'), $theme->exists() ? $theme->get('Name') : $slug);
        }
        /* translators: %s: must-use plugin file */
        return sprintf(__('%s (must-use plugin)', 'seoprostack'), $slug);
    }
}
