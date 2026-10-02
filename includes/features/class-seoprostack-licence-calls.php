<?php
/**
 * Ask before licence checks.
 *
 * Premium plugins and themes check their licence with their maker's server,
 * often while a page loads, so the page waits for that server. With this on,
 * each check waits for the site owner's say. Per plugin and server, an
 * administrator chooses:
 * - Allow once now: checks go ahead for the next hour (five minutes after the
 *   first one), then the owner is asked again;
 * - Once a day: the first check each day goes ahead and its answer is reused
 *   for the rest of the day, so pages do not wait;
 * - Never: no check is made.
 * Until then the check is held: the plugin gets a "request failed" error
 * saying why, as if the server could not be reached, and nothing is sent.
 *
 * Administrators are asked in a dialog on the next admin screen, or can be
 * asked again tomorrow, in a week, a month or a year (checks stay held, each
 * plugin keeps its own time, and plugins that start checking meanwhile are
 * still asked about). The Plugins
 * screen has a Licence checks link in each such plugin's row and above the
 * list, to see the choices, choose again or forget them.
 *
 * Only licence calls: addresses or forms that name a licence (licence,
 * license, licensing, EDD's check, activate and deactivate actions), or that
 * developers mark with the seoprostack_licence_call filter. Never held:
 * update checks and downloads (anything WordPress's update code runs, or
 * that asks for versions, update data or packages), WordPress.org, the
 * site itself, and calls from WordPress or SEO Pro Stack.
 *
 * Stored: choices and times in the seoprostack_licence_calls option (not
 * autoloaded), one day's answers in seoprostack_lc_* transients, and who
 * chose "Ask me again" in the seoprostack_licence_later user meta. Request
 * bodies, which can hold licence keys, are never stored; only a hash of them
 * tells answers apart.
 *
 * @package SEOProStack
 * @since 0.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Licence_Calls extends SEOProStack_Feature {

    const KEY = 'licence_calls';

    /** Choices and times. */
    const OPTION = 'seoprostack_licence_calls';

    /** Transient prefix of cached answers. */
    const CACHE = 'seoprostack_lc_';

    /** User meta: the checks the dialog hid ("hidden": ID => when their hold began and until when). */
    const LATER = 'seoprostack_licence_later';

    /** admin-post action. */
    const ACTION = 'seoprostack_licence_calls';

    /** Query arg that opens the dialog on the Plugins screen (plugin folder or "all"). */
    const OPEN = 'sps_licence';

    /** Most entries kept; the least recently seen go first. */
    const MAX_ENTRIES = 100;

    /** Largest answer kept, in bytes; larger ones are not reused. */
    const MAX_BODY = 1048576;

    /**
     * Entries as loaded, with this request's changes.
     *
     * @var array<string,array>|null
     */
    private static $entries = null;

    /**
     * This request's changes, merged into the stored entries at shutdown.
     *
     * @var array<string,array>
     */
    private static $changes = array();

    /**
     * Calls allowed once a day whose answers are kept: request hash => cache key.
     *
     * @var array<string,string>
     */
    private static $capture = array();

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
                'tab'         => 'plugins',
                'label'       => __('Ask before licence checks', 'seoprostack'),
                'description' => __('Premium plugins and themes often check their licence with their maker’s server while a page loads, so the page waits. Each check now waits for your say: allow it once now, once a day (the answer is reused for the rest of the day) or never. Choose again from the Plugins screen. Update checks are left alone.', 'seoprostack'),
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
        // Late, so a plugin that already answered or blocked the call (such
        // as HTTP Requests Manager) keeps its answer.
        add_filter('pre_http_request', array(__CLASS__, 'pre_http'), 100, 3);
        add_action('shutdown', array(__CLASS__, 'save'));
        if (!is_admin()) {
            return;
        }
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        add_action('load-plugins.php', array(__CLASS__, 'load_plugins_screen'));
        add_action('admin_footer', array(__CLASS__, 'dialog'));
    }

    /*
     * Holding calls.
     */

    /**
     * Hold, answer from the cache or let through a licence call.
     *
     * @param false|array|WP_Error $pre  Answer from an earlier filter.
     * @param array                $args Request arguments.
     * @param string               $url  Address.
     * @return false|array|WP_Error
     */
    public static function pre_http($pre, $args, $url) {
        if (false !== $pre || !is_array($args) || !self::is_licence_call((string) $url, $args)) {
            return $pre;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the plugin making a licence call, only for such calls.
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        if (self::during_updates($trace)) {
            return $pre;
        }
        $source = self::source($trace);
        if ('' === $source) {
            return $pre;
        }

        $host  = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $path  = substr((string) wp_parse_url($url, PHP_URL_PATH), 0, 100);
        $id    = self::id($source, $host);
        $entry = self::touch($id, $source, $host, $path);
        $now   = time();

        switch (self::mode($entry)) {
            case 'once':
                if (empty($entry['used'])) {
                    // The first call opens a five-minute window, so a check
                    // made of several calls completes.
                    self::change($id, array('used' => $now, 'until' => min((int) $entry['until'], $now + 5 * MINUTE_IN_SECONDS)));
                }
                self::change($id, array('made' => $now));
                return $pre;

            case 'daily':
                $key    = self::cache_key($id, $url, $args);
                $cached = get_transient(self::CACHE . $key);
                if (is_array($cached) && isset($cached['time']) && (int) $cached['time'] > $now - DAY_IN_SECONDS) {
                    if (!empty($cached['response']) && is_array($cached['response'])) {
                        return self::rebuild($cached['response']);
                    }
                    // The day's check failed: try again after an hour.
                    if ((int) $cached['time'] > $now - HOUR_IN_SECONDS) {
                        return self::error('failed');
                    }
                }
                self::$capture[self::request_hash($url, $args)] = $key;
                if (!has_action('http_api_debug', array(__CLASS__, 'keep'))) {
                    add_action('http_api_debug', array(__CLASS__, 'keep'), 10, 5);
                }
                self::change($id, array('made' => $now));
                return $pre;

            case 'never':
                return self::error('never');

            default:
                if (empty($entry['first'])) {
                    // First held since the last choice: the owner is asked.
                    self::change($id, array('first' => $now));
                }
                return self::error('ask');
        }
    }

    /**
     * Whether a request checks, activates or deactivates a licence.
     *
     * @param string $url  Address.
     * @param array  $args Request arguments.
     * @return bool
     */
    public static function is_licence_call($url, array $args) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $is   = false;
        if ('' !== $host && empty($args['filename']) && !self::exempt_host($host)) {
            $body = self::body_text(isset($args['body']) ? $args['body'] : '');
            $text = strtolower($url . ' ' . $body);
            // Update data, versions and downloads are WordPress's update
            // process, which SEO Pro Stack leaves alone.
            $update = (bool) preg_match('/get_version|package_download|update[-_]?check|plugin_information|version[-_]?check|\/updates?(\/|\.|\?|$)|\/download\b|\/info\b/', $text);
            // A licence in the address, an EDD licence action, or a licence
            // (key) field in the form.
            $is = !$update && (
                (bool) preg_match('/licen[cs]/', strtolower($url))
                || (bool) preg_match('/edd_action=(check|activate|deactivate)_licen[cs]e/', $text)
                || (bool) preg_match('/(^|[&{,"\s])licen[cs]e(_?key)?"?\s*[=:]/', strtolower($body))
            );
        }
        /**
         * Filter whether an outgoing request is a licence call that waits for
         * the site owner's say. Update checks are not held whatever this
         * returns.
         *
         * @param bool   $is   Whether it is a licence call.
         * @param string $url  Address.
         * @param array  $args Request arguments.
         */
        return (bool) apply_filters('seoprostack_licence_call', $is, $url, $args);
    }

    /**
     * Hosts never held: WordPress.org and the site itself.
     *
     * @param string $host Host, lower case.
     * @return bool
     */
    private static function exempt_host($host) {
        if (preg_match('/(^|\.)(wordpress\.org|w\.org)$/', $host)) {
            return true;
        }
        foreach (array(home_url(), site_url()) as $own) {
            if (strtolower((string) wp_parse_url($own, PHP_URL_HOST)) === $host) {
                return true;
            }
        }
        return false;
    }

    /**
     * A request body as text, for matching only (first 4 KB).
     *
     * @param mixed $body Body: array or string.
     * @return string
     */
    private static function body_text($body) {
        if (is_array($body)) {
            $body = http_build_query($body);
        }
        return is_string($body) ? substr($body, 0, 4096) : '';
    }

    /**
     * Whether WordPress's update code made the call: checking for, showing
     * or installing updates. Read from the call stack, so a plugin's licence
     * call that also fetches its updates is never held there.
     *
     * @param array $trace Backtrace.
     * @return bool
     */
    private static function during_updates(array $trace) {
        $functions = array(
            'wp_update_plugins', 'wp_update_themes', 'wp_version_check', 'wp_maybe_auto_update',
            'plugins_api', 'themes_api', 'get_site_transient', 'set_site_transient',
            'wp_get_update_data', 'get_plugin_updates', 'get_theme_updates', 'download_url',
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
     * The plugin, must-use plugin or theme that made the call: the code
     * nearest to the request. Empty for WordPress and SEO Pro Stack.
     *
     * @param array $trace Backtrace.
     * @return string type:slug
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
                return '';
            }
            foreach ($roots as $type => $root) {
                if (0 === strpos($file, $root)) {
                    $rest = substr($file, strlen($root));
                    $slug = false !== strpos($rest, '/') ? substr($rest, 0, strpos($rest, '/')) : $rest;
                    return $type . ':' . $slug;
                }
            }
        }
        return '';
    }

    /**
     * Key of a call's cached answer: the entry, then a hash of the call, so
     * one entry's answers can be dropped together.
     *
     * @param string $id   Entry ID.
     * @param string $url  Address.
     * @param array  $args Request arguments.
     * @return string
     */
    private static function cache_key($id, $url, array $args) {
        return $id . '_' . substr(self::request_hash($url, $args), 0, 20);
    }

    /**
     * Hash of a call: method, address and form, without values that change
     * on every call (times, dates, nonces). Hashed, so licence keys in the
     * form are never stored.
     *
     * @param string $url  Address.
     * @param array  $args Request arguments.
     * @return string
     */
    private static function request_hash($url, array $args) {
        $body = isset($args['body']) ? $args['body'] : '';
        if (is_string($body) && '' !== $body) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $body = $json;
            }
        }
        if (is_array($body)) {
            $body = self::stable($body);
        }
        $method = isset($args['method']) ? strtoupper((string) $args['method']) : 'GET';
        return md5($method . ' ' . $url . ' ' . (is_string($body) ? $body : (string) wp_json_encode($body)));
    }

    /**
     * Sort an array by key and drop values that change on every call.
     *
     * @param array $data Data.
     * @return array
     */
    private static function stable(array $data) {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/time|date|nonce|stamp|random/i', $key)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = self::stable($value);
            }
        }
        ksort($data);
        return $data;
    }

    /**
     * Keep the answer to a call allowed once a day.
     *
     * @param array|WP_Error $response Answer.
     * @param string         $context  "response".
     * @param string         $class    Transport.
     * @param array          $args     Request arguments.
     * @param string         $url      Address.
     */
    public static function keep($response, $context, $class, $args, $url) {
        if ('response' !== $context || !is_array($args)) {
            return;
        }
        $hash = self::request_hash((string) $url, $args);
        if (!isset(self::$capture[$hash])) {
            return;
        }
        $key = self::$capture[$hash];
        unset(self::$capture[$hash]);
        $kept = null;
        if (!is_wp_error($response) && is_array($response) && strlen((string) wp_remote_retrieve_body($response)) <= self::MAX_BODY) {
            $headers = isset($response['headers']) ? $response['headers'] : array();
            if (is_object($headers) && method_exists($headers, 'getAll')) {
                $headers = $headers->getAll();
            }
            $kept = array(
                'headers'  => is_array($headers) ? $headers : array(),
                'body'     => (string) wp_remote_retrieve_body($response),
                'code'     => (int) wp_remote_retrieve_response_code($response),
                'message'  => (string) wp_remote_retrieve_response_message($response),
            );
        } elseif (!is_wp_error($response)) {
            // Too large to keep: not reused, and not counted as a failure.
            return;
        }
        // A day and an hour, so an answer is there for the whole day.
        set_transient(self::CACHE . $key, array('time' => time(), 'response' => $kept), DAY_IN_SECONDS + HOUR_IN_SECONDS);
    }

    /**
     * A kept answer, in the shape WordPress's HTTP functions return.
     *
     * @param array $kept Kept answer.
     * @return array
     */
    private static function rebuild(array $kept) {
        $headers = isset($kept['headers']) && is_array($kept['headers']) ? $kept['headers'] : array();
        if (class_exists('WpOrg\Requests\Utility\CaseInsensitiveDictionary')) {
            $headers = new WpOrg\Requests\Utility\CaseInsensitiveDictionary($headers);
        }
        return array(
            'headers'       => $headers,
            'body'          => isset($kept['body']) ? (string) $kept['body'] : '',
            'response'      => array(
                'code'    => isset($kept['code']) ? (int) $kept['code'] : 200,
                'message' => isset($kept['message']) ? (string) $kept['message'] : '',
            ),
            'cookies'       => array(),
            'filename'      => null,
            'http_response' => null,
        );
    }

    /**
     * The error a held call gets. "http_request_failed" is what plugins see
     * when a server cannot be reached, so they handle it as they already do.
     *
     * @param string $why ask, never or failed.
     * @return WP_Error
     */
    private static function error($why) {
        $messages = array(
            'ask'    => __('SEO Pro Stack held this licence check until the site owner allows it.', 'seoprostack'),
            'never'  => __('SEO Pro Stack did not make this licence check: the site owner chose never.', 'seoprostack'),
            'failed' => __('Today’s licence check failed. SEO Pro Stack tries again within the hour.', 'seoprostack'),
        );
        return new WP_Error('http_request_failed', $messages[$why], array('seoprostack' => $why));
    }

    /*
     * Stored choices.
     */

    /**
     * Entry ID for a source and server.
     *
     * @param string $source type:slug.
     * @param string $host   Server.
     * @return string
     */
    private static function id($source, $host) {
        return substr(md5($source . '|' . $host), 0, 12);
    }

    /**
     * Stored entries, with this request's changes.
     *
     * @return array<string,array>
     */
    public static function entries() {
        if (null === self::$entries) {
            $stored        = get_option(self::OPTION, array());
            self::$entries = is_array($stored) ? $stored : array();
        }
        return self::$entries;
    }

    /**
     * Note that a source asked to call a server, adding its entry if new.
     *
     * @param string $id     Entry ID.
     * @param string $source type:slug.
     * @param string $host   Server.
     * @param string $path   Address path.
     * @return array Entry.
     */
    private static function touch($id, $source, $host, $path) {
        self::entries();
        if (!isset(self::$entries[$id])) {
            self::$entries[$id] = array(
                'source'  => $source,
                'host'    => $host,
                'path'    => $path,
                'mode'    => 'ask',
                'until'   => 0,
                'used'    => 0,
                'first'   => 0,
                'made'    => 0,
                'seen'    => 0,
                'decided' => 0,
            );
            self::$changes[$id]['new'] = self::$entries[$id];
        }
        self::change($id, array('seen' => time(), 'path' => $path));
        return self::$entries[$id];
    }

    /**
     * Change an entry for this request and note it for saving.
     *
     * @param string $id     Entry ID.
     * @param array  $fields Fields.
     */
    private static function change($id, array $fields) {
        foreach ($fields as $field => $value) {
            self::$entries[$id][$field]        = $value;
            self::$changes[$id]['set'][$field] = $value;
        }
    }

    /**
     * Choice in force for an entry: an expired "once" asks again.
     *
     * @param array $entry Entry.
     * @return string ask, once, daily or never.
     */
    private static function mode(array $entry) {
        $mode = isset($entry['mode']) ? (string) $entry['mode'] : 'ask';
        if ('once' === $mode && (int) $entry['until'] <= time()) {
            return 'ask';
        }
        return in_array($mode, array('once', 'daily', 'never'), true) ? $mode : 'ask';
    }

    /**
     * Save this request's changes into what is stored now, so a choice made
     * meanwhile in another request is kept. "Last seen" alone is saved at
     * most once a minute, so held checks do not write on every page.
     */
    public static function save() {
        if (!self::$changes) {
            return;
        }
        $changes       = self::$changes;
        self::$changes = array();
        wp_cache_delete(self::OPTION, 'options');
        $stored = get_option(self::OPTION, array());
        $stored = is_array($stored) ? $stored : array();

        $write = false;
        foreach ($changes as $id => $change) {
            if (!isset($stored[$id])) {
                if (empty($change['new'])) {
                    continue;
                }
                $stored[$id] = $change['new'];
                $write       = true;
            }
            $set = isset($change['set']) ? $change['set'] : array();
            // The window of an "once" choice is only narrowed while that
            // choice stands, and "first held" only noted while still asking.
            if (isset($set['used']) && 'once' !== $stored[$id]['mode']) {
                unset($set['used'], $set['until']);
            }
            if (isset($set['first']) && ('ask' !== self::mode($stored[$id]) || !empty($stored[$id]['first']))) {
                unset($set['first']);
            }
            if (isset($set['used']) || isset($set['made']) || isset($set['first']) || !empty($change['new'])) {
                $write = true;
            }
            if (isset($set['seen']) && (int) $set['seen'] - (int) $stored[$id]['seen'] >= MINUTE_IN_SECONDS) {
                $write = true;
            }
            foreach ($set as $field => $value) {
                $stored[$id][$field] = $value;
            }
        }
        if (!$write) {
            return;
        }
        if (count($stored) > self::MAX_ENTRIES) {
            uasort($stored, function ($a, $b) {
                return (int) $b['seen'] - (int) $a['seen'];
            });
            $stored = array_slice($stored, 0, self::MAX_ENTRIES, true);
        }
        update_option(self::OPTION, $stored, false);
        self::$entries = $stored;
    }

    /*
     * Choosing.
     */

    /**
     * Whether the current person may choose.
     *
     * @return bool
     */
    private static function allowed() {
        return current_user_can('manage_options') && current_user_can('activate_plugins') && !is_network_admin();
    }

    /**
     * Save a choice from the dialog.
     */
    public static function handle() {
        check_admin_referer(self::ACTION);
        if (!self::allowed()) {
            wp_die(esc_html__('You are not allowed to choose this.', 'seoprostack'), '', array('response' => 403));
        }
        $do   = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $id   = isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : '';
        $back = wp_get_referer();
        $back = remove_query_arg(self::OPEN, $back ? $back : admin_url());

        if ('later' === $do) {
            // Hide the dialog for a while. Checks stay held. Only the checks
            // the dialog showed are hidden, each by when its hold began, so a
            // plugin that starts checking meanwhile, or is asked about again
            // after Forget, still shows.
            $spans  = self::later_spans();
            $for    = isset($_POST['for']) ? sanitize_key(wp_unslash($_POST['for'])) : 'day';
            $span   = isset($spans[$for]) ? $spans[$for]['seconds'] : DAY_IN_SECONDS;
            // Each check keeps its own time, so hiding one never brings back
            // another hidden earlier.
            $shown  = isset($_POST['ids']) ? array_map('sanitize_key', explode(',', sanitize_text_field(wp_unslash($_POST['ids'])))) : array();
            $hidden = self::later_hidden();
            foreach (self::entries() as $entry_id => $entry) {
                if (in_array((string) $entry_id, $shown, true) && !empty($entry['first'])) {
                    $hidden[$entry_id] = array('first' => (int) $entry['first'], 'until' => time() + $span);
                }
            }
            update_user_meta(get_current_user_id(), self::LATER, array('hidden' => $hidden));
            wp_safe_redirect($back);
            exit;
        }
        if ('forget_all' === $do) {
            // Start again: every plugin is asked about at its next check.
            delete_option(self::OPTION);
            self::$entries = null;
            self::forget_answers('');
            wp_safe_redirect($back);
            exit;
        }
        if (!in_array($do, array('once', 'daily', 'never', 'forget'), true)) {
            wp_die(esc_html__('Unknown choice.', 'seoprostack'), '', array('response' => 400));
        }

        wp_cache_delete(self::OPTION, 'options');
        $stored = get_option(self::OPTION, array());
        if (!is_array($stored) || !isset($stored[$id])) {
            wp_safe_redirect($back);
            exit;
        }
        $now      = time();
        $previous = self::mode($stored[$id]);
        // Forget: back to asking, at the plugin's next check.
        $do                     = 'forget' === $do ? 'ask' : $do;
        $stored[$id]['mode']    = $do;
        $stored[$id]['until']   = 'once' === $do ? $now + HOUR_IN_SECONDS : 0;
        $stored[$id]['used']    = 0;
        $stored[$id]['first']   = 0;
        $stored[$id]['decided'] = $now;
        update_option(self::OPTION, $stored, false);
        self::$entries = $stored;

        // A new choice starts afresh: answers kept under an earlier "once a
        // day" are not reused.
        if ($do !== $previous) {
            self::forget_answers($id);
        }
        wp_safe_redirect($back);
        exit;
    }

    /**
     * How long "Ask me again" can hide the dialog.
     *
     * @return array<string,array{label:string,seconds:int}>
     */
    private static function later_spans() {
        return array(
            'day'   => array('label' => __('tomorrow', 'seoprostack'), 'seconds' => DAY_IN_SECONDS),
            'week'  => array('label' => __('in a week', 'seoprostack'), 'seconds' => WEEK_IN_SECONDS),
            'month' => array('label' => __('in a month', 'seoprostack'), 'seconds' => MONTH_IN_SECONDS),
            'year'  => array('label' => __('in a year', 'seoprostack'), 'seconds' => YEAR_IN_SECONDS),
        );
    }

    /**
     * Drop kept answers, so the next check makes a fresh call.
     *
     * @param string $id Entry ID, or '' for every entry.
     */
    private static function forget_answers($id) {
        global $wpdb;
        if (wp_using_ext_object_cache()) {
            // Kept in the object cache, which cannot be listed: each expires
            // within a day, and is reused only while "once a day" stands.
            return;
        }
        $prefix = '' === $id ? self::CACHE : self::CACHE . $id . '_';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removes this feature's own transients when a choice changes.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_' . $prefix) . '%',
            $wpdb->esc_like('_transient_timeout_' . $prefix) . '%'
        ));
    }

    /*
     * Plugins screen.
     */

    /**
     * Hook the Plugins screen.
     */
    public static function load_plugins_screen() {
        if (!self::allowed() || !self::entries()) {
            return;
        }
        add_filter('plugin_action_links', array(__CLASS__, 'action_links'), 20, 2);
        add_filter('views_plugins', array(__CLASS__, 'views'));
    }

    /**
     * Folder (or file, for single-file plugins) of a plugin file.
     *
     * @param string $file Plugin file.
     * @return string
     */
    private static function slug_of($file) {
        $dir = dirname((string) $file);
        return '.' === $dir ? (string) $file : $dir;
    }

    /**
     * Address that opens the dialog on the Plugins screen.
     *
     * @param string $which Plugin folder or "all".
     * @return string
     */
    private static function open_url($which) {
        return add_query_arg(self::OPEN, rawurlencode($which), self_admin_url('plugins.php'));
    }

    /**
     * Licence checks link in the row of a plugin that made them.
     *
     * @param string[] $links Action links.
     * @param string   $file  Plugin file.
     * @return string[]
     */
    public static function action_links($links, $file) {
        $slug = self::slug_of($file);
        foreach (self::entries() as $entry) {
            if ('plugin:' . $slug === $entry['source']) {
                $links['seoprostack-licence'] = sprintf('<a href="%1$s">%2$s</a>', esc_url(self::open_url($slug)), esc_html__('Licence checks', 'seoprostack'));
                break;
            }
        }
        return $links;
    }

    /**
     * Licence checks link above the list, for every source.
     *
     * @param string[] $views Views.
     * @return string[]
     */
    public static function views($views) {
        $views['seoprostack-licence'] = sprintf(
            '<a href="%1$s">%2$s <span class="count">(%3$d)</span></a>',
            esc_url(self::open_url('all')),
            esc_html__('Licence checks', 'seoprostack'),
            count(self::entries())
        );
        return $views;
    }

    /*
     * Dialog.
     */

    /**
     * Name of a plugin, must-use plugin or theme.
     *
     * @param string $source type:slug.
     * @return string
     */
    private static function source_name($source) {
        $parts = explode(':', (string) $source, 2);
        $type  = $parts[0];
        $slug  = isset($parts[1]) ? $parts[1] : '';
        if ('plugin' === $type) {
            $active = self::active_plugins();
            $file   = isset($active[$slug]) ? $active[$slug] : $slug;
            if (is_readable(WP_PLUGIN_DIR . '/' . $file) && is_file(WP_PLUGIN_DIR . '/' . $file) && function_exists('get_plugin_data')) {
                $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);
                if (!empty($data['Name'])) {
                    return $data['Name'];
                }
            }
            return $slug;
        }
        if ('theme' === $type) {
            $theme = wp_get_theme($slug);
            /* translators: %s: theme name */
            return sprintf(__('%s (theme)', 'seoprostack'), $theme->exists() ? $theme->get('Name') : $slug);
        }
        /* translators: %s: must-use plugin file */
        return sprintf(__('%s (must-use plugin)', 'seoprostack'), $slug);
    }

    /**
     * Entries to show: those waiting for a choice, or those of the plugin
     * the Plugins screen asked for.
     *
     * @return array<string,array>
     */
    private static function shown_entries() {
        $open    = self::opened();
        $entries = self::entries();
        if ('' !== $open) {
            return 'all' === $open ? $entries : array_filter($entries, function ($entry) use ($open) {
                return 'plugin:' . $open === $entry['source'];
            });
        }
        // While "Ask me again" stands, the checks it hid stay hidden; any
        // other waiting check is asked about.
        $hidden = self::later_hidden();
        return array_filter($entries, function ($entry, $id) use ($hidden) {
            if ('ask' !== self::mode($entry) || empty($entry['first'])) {
                return false;
            }
            return !isset($hidden[$id]) || $hidden[$id]['first'] !== (int) $entry['first'];
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Checks the current person hid with "Ask me again" whose time has not
     * run out: ID => when its hold began and until when it is hidden. Reads
     * the earlier shape too (one "until" for every hidden check).
     *
     * @return array<string,array{first:int,until:int}>
     */
    private static function later_hidden() {
        $later = get_user_meta(get_current_user_id(), self::LATER, true);
        if (!is_array($later) || !isset($later['hidden']) || !is_array($later['hidden'])) {
            return array();
        }
        $shared = isset($later['until']) ? (int) $later['until'] : 0;
        $now    = time();
        $hidden = array();
        foreach ($later['hidden'] as $id => $hide) {
            $first = is_array($hide) ? (isset($hide['first']) ? (int) $hide['first'] : 0) : (int) $hide;
            $until = is_array($hide) && isset($hide['until']) ? (int) $hide['until'] : $shared;
            if ($first && $until > $now) {
                $hidden[(string) $id] = array('first' => $first, 'until' => $until);
            }
        }
        return $hidden;
    }

    /**
     * What the Plugins screen asked to show: a plugin folder, "all" or ''.
     *
     * @return string
     */
    private static function opened() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only chooses what to show.
        return isset($_GET[self::OPEN]) ? sanitize_text_field(wp_unslash($_GET[self::OPEN])) : '';
    }

    /**
     * What an entry is set to, in words.
     *
     * @param array $entry Entry.
     * @return string
     */
    private static function status_text(array $entry) {
        $mode = self::mode($entry);
        $ago  = function ($time) {
            /* translators: %s: time span, such as "5 mins" */
            return sprintf(__('%s ago', 'seoprostack'), human_time_diff((int) $time));
        };
        if ('once' === $mode) {
            /* translators: %s: time */
            $text = sprintf(__('Allowed until %s.', 'seoprostack'), wp_date(get_option('time_format'), (int) $entry['until']));
        } elseif ('daily' === $mode) {
            $text = __('Once a day.', 'seoprostack');
            if ((int) $entry['made']) {
                /* translators: %s: time ago, such as "5 mins ago" */
                $text .= ' ' . sprintf(__('Last checked %s.', 'seoprostack'), $ago($entry['made']));
            }
        } elseif ('never' === $mode) {
            /* translators: %s: time ago, such as "5 mins ago" */
            $text = sprintf(__('Never. Last stopped %s.', 'seoprostack'), $ago($entry['seen']));
        } else {
            /* translators: 1: time ago, 2: time ago */
            $text = sprintf(__('Waiting for your choice. Held since %1$s, last %2$s.', 'seoprostack'), $ago(!empty($entry['first']) ? $entry['first'] : $entry['seen']), $ago($entry['seen']));
        }
        return $text;
    }

    /**
     * Action, nonce and the screen to return to, for each form in the
     * dialog (without wp_nonce_field()'s IDs, which would repeat).
     */
    private static function hidden_fields() {
        $back = isset($_SERVER['REQUEST_URI']) ? remove_query_arg(self::OPEN, esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']))) : '';
        ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>" />
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(self::ACTION)); ?>" />
        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr($back); ?>" />
        <?php
    }

    /**
     * Print the dialog on admin screens when a choice is needed, or when
     * the Plugins screen asks for it.
     */
    public static function dialog() {
        if (wp_doing_ajax() || !self::allowed()) {
            return;
        }
        $entries = self::shown_entries();
        if (!$entries) {
            return;
        }
        uasort($entries, function ($a, $b) {
            return (int) $b['seen'] - (int) $a['seen'];
        });
        $buttons = array(
            'once'  => __('Allow once now', 'seoprostack'),
            'daily' => __('Once a day', 'seoprostack'),
            'never' => __('Never', 'seoprostack'),
        );
        $action = admin_url('admin-post.php');
        ?>
        <dialog id="sps-licence" class="sps-licence" aria-labelledby="sps-licence-title">
            <form method="dialog" class="sps-licence__close">
                <button type="submit" class="button-link" aria-label="<?php esc_attr_e('Close', 'seoprostack'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
            </form>
            <h2 id="sps-licence-title"><?php esc_html_e('Licence checks', 'seoprostack'); ?></h2>
            <p><?php esc_html_e('These plugins want to check their licence with their maker’s server. Until you choose, nothing is sent and the plugin is told the server could not be reached.', 'seoprostack'); ?></p>
            <ul class="sps-licence__list">
                <?php foreach ($entries as $id => $entry) : ?>
                    <?php $mode = self::mode($entry); ?>
                    <li class="sps-licence__item">
                        <p class="sps-licence__who">
                            <strong><?php echo esc_html(self::source_name($entry['source'])); ?></strong>
                            <span class="sps-licence__host"><?php echo esc_html($entry['host']); ?></span>
                        </p>
                        <p class="sps-licence__status"><?php echo esc_html(self::status_text($entry)); ?></p>
                        <form method="post" action="<?php echo esc_url($action); ?>" class="sps-licence__choices">
                            <?php self::hidden_fields(); ?>
                            <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>" />
                            <?php foreach ($buttons as $do => $label) : ?>
                                <button type="submit" name="do" value="<?php echo esc_attr($do); ?>" class="button<?php echo 'once' === $do ? ' button-primary' : ''; ?>" aria-pressed="<?php echo $do === $mode ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button>
                            <?php endforeach; ?>
                            <?php if ('ask' !== $mode) : ?>
                                <button type="submit" name="do" value="forget" class="button-link sps-licence__forget"><?php esc_html_e('Forget my choice', 'seoprostack'); ?></button>
                            <?php endif; ?>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="sps-licence__note"><?php esc_html_e('Allow once now lets the plugin’s next check through when it next asks (within the hour), then asks you again. Once a day lets the first check each day through and gives the plugin that answer for the rest of the day, so pages do not wait. Update checks are never held. Choose again, or forget your choices, from the Licence checks link on the Plugins screen.', 'seoprostack'); ?></p>
            <form method="post" action="<?php echo esc_url($action); ?>" class="sps-licence__later">
                <?php self::hidden_fields(); ?>
                <?php if ('' === self::opened()) : ?>
                    <input type="hidden" name="do" value="later" />
                    <input type="hidden" name="ids" value="<?php echo esc_attr(implode(',', array_keys($entries))); ?>" />
                    <span><?php esc_html_e('Ask me again:', 'seoprostack'); ?></span>
                    <?php foreach (self::later_spans() as $for => $span) : ?>
                        <button type="submit" name="for" value="<?php echo esc_attr($for); ?>" class="button-link"><?php echo esc_html($span['label']); ?></button>
                    <?php endforeach; ?>
                    <span class="sps-licence__aside"><?php esc_html_e('Checks stay held until you choose.', 'seoprostack'); ?></span>
                <?php else : ?>
                    <input type="hidden" name="do" value="forget_all" />
                    <button type="submit" class="button-link sps-licence__forget"><?php esc_html_e('Forget all choices', 'seoprostack'); ?></button>
                    <span class="sps-licence__aside"><?php esc_html_e('Every plugin is asked about again at its next check.', 'seoprostack'); ?></span>
                <?php endif; ?>
            </form>
        </dialog>
        <style>
            .sps-licence { box-sizing: border-box; max-width: 560px; width: calc(100% - 32px); padding: 20px 24px; border: 0; border-radius: 4px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3); }
            .sps-licence::backdrop { background: rgba(0, 0, 0, 0.5); }
            .sps-licence h2 { margin: 0 32px 8px 0; }
            .sps-licence__close { position: absolute; top: 12px; right: 12px; }
            .sps-licence__close .button-link { color: inherit; }
            .sps-licence__list { margin: 12px 0; max-height: 50vh; overflow: auto; }
            .sps-licence__item { margin: 0; padding: 10px 0; border-top: 1px solid rgba(127, 127, 127, 0.25); }
            .sps-licence__item p { margin: 0 0 6px; }
            .sps-licence__host { margin-left: 6px; color: #646970; }
            .sps-licence__status { color: #646970; }
            .sps-licence__choices { display: flex; flex-wrap: wrap; gap: 6px; }
            .sps-licence__choices .button[aria-pressed="true"] { box-shadow: inset 0 0 0 2px #2271b1; }
            .sps-licence__note { color: #646970; }
            .sps-licence__choices .sps-licence__forget { align-self: center; margin-left: auto !important; }
            .sps-licence__later { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; }
            .sps-licence__aside { flex-basis: 100%; color: #646970; }
        </style>
        <script>
        (function () {
            var d = document.getElementById('sps-licence');
            if (d && typeof d.showModal === 'function') { d.showModal(); }
        })();
        </script>
        <?php
    }
}
