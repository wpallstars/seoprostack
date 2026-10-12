<?php
/**
 * HTTPS and security headers.
 *
 * When the site address is https: sends http GET and HEAD requests to the
 * same address on https with a 301, and, over HTTPS only, sends the security
 * headers every site should have (HSTS, nosniff, frames from this site only,
 * a referrer policy, an opener policy and an upgrade of insecure requests in
 * place of a mixed content fixer), and drops PHP's X-Powered-By. Nothing is
 * written to files: the headers come from PHP, on the site through
 * `wp_headers` and in wp-admin and on the login screen directly.
 *
 * Proxies: a request counts as HTTPS when the proxy says so (CF-Visitor,
 * X-Forwarded-Proto, X-Forwarded-SSL), so a site behind Cloudflare or a load
 * balancer is not sent round in circles. WP-CLI, cron and /.well-known/
 * (certificate checks) are never redirected.
 *
 * Replaces Really Simple Security (free and Pro): migrate() imports its
 * switches once, reading its options only (its network option when it is
 * network-active), and the switches of its basic hardening go to the
 * features that do the same (choices()). Its login protection is FluentAuth's
 * job; while it is active the Plugins screen names what it still does
 * (extras()). Also imports Hostinger Tools' "Redirect to HTTPS".
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

class SEOProStack_Security_Headers extends SEOProStack_Feature {

    const KEY = 'security_headers';

    /** How far HSTS reaches: site or subdomains. */
    const HSTS_KEY = 'security_headers_hsts';

    /** Parts left out while troubleshooting. */
    const BYPASS_KEY = 'security_headers_bypass';

    /** Plugins this feature replaces, folder => name. */
    const REPLACES = array(
        'really-simple-ssl'               => 'Really Simple Security',
        'really-simple-ssl-pro'           => 'Really Simple Security Pro',
        'really-simple-ssl-pro-multisite' => 'Really Simple Security Pro Multisite',
    );

    /** Really Simple Security's settings. */
    const RSSSL_OPTION = 'rsssl_options';

    /**
     * Really Simple Security's hardening switches other features cover:
     * option => [feature switch, list setting, choice].
     */
    const MAP = array(
        'disable_xmlrpc'                => array('hardening', 'hardening_items', 'xmlrpc'),
        'disable_application_passwords' => array('hardening', 'hardening_items', 'app_passwords'),
        'change_debug_log_location'     => array('hardening', 'hardening_items', 'files'),
        'block_code_execution_uploads'  => array('hardening', 'hardening_items', 'uploads_php'),
        'disable_user_enumeration'      => array('hardening', 'hardening_items', 'usernames'),
        'disable_login_feedback'        => array('login_screen', 'login_screen_items', 'login_errors'),
        'disable_file_editing'          => array('admin_tidy', 'admin_tidy_items', 'file_editor'),
        'hide_wordpress_version'        => array('wp_extras', 'wp_extras_items', 'generator'),
    );

    /** Its switches this feature covers. */
    const HEADER_SWITCHES = array('hsts', 'x_content_type_options', 'x_frame_options', 'upgrade_insecure_requests', 'mixed_content_fixer', 'referrer_policy', 'cross_origin_opener_policy', 'disable_x_powered_by_header');

    /** HSTS max-age, in seconds: one year, and two for the preload list. */
    const HSTS_YEAR = 31536000;
    const HSTS_PRELOAD = 63072000;

    /** Explicit cleanup action and Site Health test, independent of the switch. */
    const CLEANUP = 'seoprostack_rsssl_cleanup';

    /** Keep the file: PHP may still cache an auto_prepend_file pointing to it. */
    const RSSSL_STUB = "<?php\n// This file was created by Really Simple Security\n// It is no longer used and safe to delete";

    /**
     * Settings; off by default.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('HTTPS and security headers', 'seoprostack'),
                'description' => __('When the site address starts with https, send visitors who open an http address to the https one, and tell browsers to use HTTPS only, load http pictures and scripts over HTTPS, show the site in frames on this site only, and send less to other sites. Works behind Cloudflare and other proxies.', 'seoprostack'),
                'replaces'    => self::REPLACES,
            ),
            self::HSTS_KEY => array(
                'type'        => 'select',
                'default'     => 'site',
                'parent'      => self::KEY,
                'label'       => __('Use HTTPS only for', 'seoprostack'),
                'description' => __('Browsers remember it for a year, or two with subdomains. Choose subdomains only when every subdomain has a certificate.', 'seoprostack'),
                'options'     => array(
                    'site'       => __('This site', 'seoprostack'),
                    'subdomains' => __('This site and its subdomains, ready for browsers’ preload list', 'seoprostack'),
                ),
            ),
            self::BYPASS_KEY => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Leave out (to find a problem)', 'seoprostack'),
                'options'     => array(__CLASS__, 'bypass_options'),
            ),
        );
    }

    /**
     * Parts that can be left out while finding a problem.
     *
     * @return array<string,string>
     */
    public static function bypass_options() {
        return array(
            'redirect' => __('Sending http addresses to https', 'seoprostack'),
            'frames'   => __('Frames on this site only (other sites can show its pages in a frame)', 'seoprostack'),
            'upgrade'  => __('Loading http pictures and scripts over HTTPS', 'seoprostack'),
            'opener'   => __('Opener policy (pop-up sign-ins and payments that need the page that opened them)', 'seoprostack'),
        );
    }

    /**
     * Import Really Simple Security's and Hostinger Tools' switches once,
     * while they are active, into settings that are not stored yet.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $theirs = self::rsssl_options();
        $on     = false;
        if ($theirs) {
            $redirect = isset($theirs['redirect']) ? $theirs['redirect'] : '';
            $on       = in_array($redirect, array('wp_redirect', 'htaccess'), true);
            foreach (self::HEADER_SWITCHES as $switch) {
                $on = $on || self::on($theirs, $switch);
            }
            if ($on) {
                $options = self::import_setting($options, self::HSTS_KEY, self::on($theirs, 'hsts') && self::on($theirs, 'hsts_subdomains') ? 'subdomains' : 'site');
                // What it was told to leave out stays out.
                $bypass = array();
                if ('none' === $redirect) {
                    $bypass[] = 'redirect';
                }
                if (isset($theirs['csp_frame_ancestors']) && 'disabled' === $theirs['csp_frame_ancestors']) {
                    $bypass[] = 'frames';
                }
                $options = self::import_setting($options, self::BYPASS_KEY, $bypass);
            }
        }
        if (isset(self::active_plugins()['hostinger'])) {
            $hostinger = get_option('hostinger_tools', array());
            $on        = $on || (is_array($hostinger) && !empty($hostinger['force_https']));
        }
        if ($on) {
            $options = self::import_setting($options, self::KEY, true);
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_filter('site_status_tests', array(__CLASS__, 'cleanup_tests'));
        // Replacement advice is needed even while the feature waits or is off.
        if (is_admin()) {
            add_filter('seoprostack_replaced_plugin_extras', array(__CLASS__, 'extras'), 10, 2);
            add_action('admin_post_' . self::CLEANUP, array(__CLASS__, 'cleanup_action'));
        }
        if (!self::enabled() || 'https' !== wp_parse_url(home_url('/'), PHP_URL_SCHEME)) {
            return;
        }
        if (!self::https_request()) {
            self::maybe_redirect();
            return;
        }
        add_filter('wp_headers', array(__CLASS__, 'wp_headers'), 99, 2);
        add_action('send_headers', array(__CLASS__, 'remove_powered_by'));
        // After core's send_frame_options_header() (admin_init 10, added when
        // wp-admin loads), whose policy header replaces any sent before it.
        add_action('admin_init', array(__CLASS__, 'send'), 100);
        add_action('login_init', array(__CLASS__, 'send'), 100);
    }

    /**
     * Whether http addresses are sent to https (switched on, redirect not
     * left out), even while the feature waits.
     *
     * @return bool
     */
    public static function redirect_on() {
        return self::switched_on() && !self::bypassed('redirect');
    }

    /**
     * Whether a part is left out.
     *
     * @param string $part Part.
     * @return bool
     */
    private static function bypassed($part) {
        return in_array($part, (array) SEOProStack_Settings::get(self::BYPASS_KEY), true);
    }

    /**
     * Whether this request reached the site over HTTPS, also when a proxy
     * or Cloudflare ended HTTPS before the server.
     *
     * @return bool
     */
    public static function https_request() {
        if (is_ssl()) {
            return true;
        }
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && is_string($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            // The first proxy's answer comes first in a list.
            $proto = strtolower(trim(explode(',', wp_unslash($_SERVER['HTTP_X_FORWARDED_PROTO']))[0]));
            if ('https' === $proto) {
                return true;
            }
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && 'on' === strtolower((string) wp_unslash($_SERVER['HTTP_X_FORWARDED_SSL']))) {
            return true;
        }
        if (isset($_SERVER['HTTP_CF_VISITOR']) && is_string($_SERVER['HTTP_CF_VISITOR'])) {
            $visitor = json_decode(wp_unslash($_SERVER['HTTP_CF_VISITOR']), true);
            if (is_array($visitor) && isset($visitor['scheme']) && 'https' === $visitor['scheme']) {
                return true;
            }
        }
        // phpcs:enable
        return false;
    }

    /**
     * Send an http GET or HEAD request to the same address on https with a
     * 301, on the site's own host. Never for WP-CLI, cron or /.well-known/.
     */
    private static function maybe_redirect() {
        if (self::bypassed('redirect') || (defined('WP_CLI') && WP_CLI) || wp_doing_cron() || headers_sent()) {
            return;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the path is sent back as it came, to the site's own host.
        if ('' === $uri || '/' !== $uri[0] || 0 === strpos($uri, '/.well-known/') || 0 === strpos($uri, '//')) {
            return;
        }
        $home = wp_parse_url(home_url('/'));
        if (empty($home['host'])) {
            return;
        }
        $target = 'https://' . $home['host'] . (isset($home['port']) ? ':' . (int) $home['port'] : '') . $uri;
        // wp_redirect(), not wp_safe_redirect(): the host is the site's own.
        wp_redirect($target, 301, 'SEO Pro Stack'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the site's own host.
        exit;
    }

    /**
     * The headers to send, name => value.
     *
     * @param bool $frames Whether frame headers apply (not for embeds).
     * @return array<string,string>
     */
    public static function headers($frames = true) {
        $frames  = $frames && !self::bypassed('frames');
        $policy  = array();
        if (!self::bypassed('upgrade')) {
            $policy[] = 'upgrade-insecure-requests';
        }
        if ($frames) {
            $policy[] = "frame-ancestors 'self'";
        }
        $hsts    = 'subdomains' === SEOProStack_Settings::get(self::HSTS_KEY)
            ? 'max-age=' . self::HSTS_PRELOAD . '; includeSubDomains; preload'
            : 'max-age=' . self::HSTS_YEAR;
        $headers = array(
            'Strict-Transport-Security' => $hsts,
            'X-Content-Type-Options'    => 'nosniff',
            'Referrer-Policy'           => 'strict-origin-when-cross-origin',
        );
        if ($policy) {
            $headers['Content-Security-Policy'] = implode('; ', $policy);
        }
        if ($frames) {
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }
        if (!self::bypassed('opener')) {
            $headers['Cross-Origin-Opener-Policy'] = 'same-origin-allow-popups';
        }
        return $headers;
    }

    /**
     * Add the headers to the site's pages, keeping any of the same name
     * that something else set (a page's own value wins). A content security
     * policy set elsewhere gets only the directives it does not have.
     *
     * @param array $headers Headers.
     * @param WP    $wp      Request.
     * @return array
     */
    public static function wp_headers($headers, $wp = null) {
        $headers = (array) $headers;
        // Embeds of this site's posts are meant to be shown in other sites' frames.
        $embed = is_object($wp) && !empty($wp->query_vars['embed']);
        $have  = array();
        foreach (array_keys($headers) as $key) {
            $have[strtolower((string) $key)] = $key;
        }
        foreach (self::headers(!$embed) as $name => $value) {
            $lower = strtolower($name);
            if (!isset($have[$lower])) {
                $headers[$name] = $value;
            } elseif ('content-security-policy' === $lower) {
                $key     = $have[$lower];
                $missing = self::missing_directives((string) $headers[$key], $value);
                if ($missing) {
                    $headers[$key] = rtrim(trim((string) $headers[$key]), ';') . '; ' . $missing;
                }
            }
        }
        return $headers;
    }

    /**
     * The directives of a policy that another policy does not have, such as
     * upgrade-insecure-requests when only frame-ancestors is set.
     *
     * @param string $existing Policy already sent.
     * @param string $ours     This feature's policy.
     * @return string Missing directives, or an empty string.
     */
    private static function missing_directives($existing, $ours) {
        $names = array();
        foreach (explode(';', strtolower($existing)) as $directive) {
            $parts = preg_split('/\s+/', trim($directive));
            if ($parts && '' !== $parts[0]) {
                $names[$parts[0]] = true;
            }
        }
        $missing = array();
        foreach (explode(';', $ours) as $directive) {
            $directive = trim($directive);
            $parts     = preg_split('/\s+/', $directive);
            if ('' !== $directive && $parts && !isset($names[strtolower($parts[0])])) {
                $missing[] = $directive;
            }
        }
        return implode('; ', $missing);
    }

    /**
     * Drop PHP's X-Powered-By header.
     */
    public static function remove_powered_by() {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }

    /**
     * wp-admin and the login screen: send the headers directly, without
     * replacing one already sent with the same name. Core sends
     * X-Frame-Options and a frame-ancestors policy here itself, so the
     * policy's other directives go in a second header, which browsers apply
     * as well.
     */
    public static function send() {
        if (headers_sent()) {
            return;
        }
        $have   = array();
        $policy = '';
        foreach (headers_list() as $line) {
            $parts = explode(':', $line, 2);
            $name  = strtolower(trim($parts[0]));
            $have[$name] = true;
            if ('content-security-policy' === $name && isset($parts[1])) {
                $policy .= $parts[1] . ';';
            }
        }
        foreach (self::headers() as $name => $value) {
            if (!isset($have[strtolower($name)])) {
                header($name . ': ' . $value);
            } elseif ('Content-Security-Policy' === $name) {
                $missing = self::missing_directives($policy, $value);
                if ($missing) {
                    header($name . ': ' . $missing, false);
                }
            }
        }
        header_remove('X-Powered-By');
    }

    /* ------------------------------------------------------------------
     * Really Simple Security
     * ------------------------------------------------------------------ */

    /**
     * Register the read-only test even when headers are switched off.
     *
     * @param array $tests Site Health tests.
     * @return array
     */
    public static function cleanup_tests($tests) {
        $tests['direct'][self::CLEANUP] = array(
            'label' => __('Really Simple Security leftovers', 'seoprostack'),
            'test'  => array(__CLASS__, 'test_leftovers'),
        );
        return $tests;
    }

    /**
     * Read the actual saved activation lists, not the request's skipped plugins.
     *
     * @phpstan-impure Depends on the current blog after switch_to_blog().
     * @return bool
     */
    private static function rsssl_active() {
        // The loader's raw list belongs to the original site, not a switched
        // subsite. Temporarily bypass only its known activation-list filter.
        $filter   = array('SEOProStack_Plugin_Loader', 'filter_active');
        $priority = has_filter('option_active_plugins', $filter);
        if (false !== $priority) {
            remove_filter('option_active_plugins', $filter, $priority);
        }
        try {
            $plugins = (array) get_option('active_plugins', array());
        } finally {
            if (false !== $priority) {
                add_filter('option_active_plugins', $filter, $priority);
            }
        }
        if (is_multisite()) {
            $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        foreach ($plugins as $plugin) {
            if (isset(self::REPLACES[dirname((string) $plugin)])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Shared files, resolved on the network's main site by callers.
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    private static function leftover_blocks() {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $root    = get_home_path();
        $uploads = wp_get_upload_dir();
        $blocks  = array(
            $root . '.htaccess' => array('Really Simple Auto Prepend File', '# BEGIN ', '# END '),
            $root . '.user.ini' => array('Really Simple Auto Prepend File', '; BEGIN ', '; END '),
        );
        $ini = (string) ini_get('user_ini.filename');
        if ($ini && basename($ini) === $ini) {
            $blocks[$root . $ini] = array('Really Simple Auto Prepend File', '; BEGIN ', '; END ');
        }
        if (empty($uploads['error']) && !empty($uploads['basedir'])) {
            $blocks[trailingslashit($uploads['basedir']) . '.htaccess'] = array('Really Simple Security', '#Begin ', '#End ');
        }
        return $blocks;
    }

    /**
     * Read a local file without treating unreadable files as clean.
     *
     * @param string $file Fixed local path.
     * @return string|false
     */
    private static function leftover_contents($file) {
        if (!file_exists($file)) {
            return '';
        }
        if (!is_readable($file) || !is_file($file)) {
            return false;
        }
        return file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixed local configuration file, never a URL.
    }

    /**
     * Cron hook names on the current site (including events with arguments).
     *
     * @return string[]
     */
    private static function leftover_cron() {
        $hooks = array();
        foreach ((array) _get_cron_array() as $events) {
            foreach (array_keys($events) as $hook) {
                if (0 === strpos($hook, 'rsssl_')) {
                    $hooks[$hook] = $hook;
                }
            }
        }
        return array_values($hooks);
    }

    /**
     * Files left on the main site; incomplete blocks remain visible for repair.
     *
     * @return string[]
     */
    private static function leftover_files() {
        $found = array();
        foreach (self::leftover_blocks() as $file => $block) {
            $contents = self::leftover_contents($file);
            if (false === $contents || self::block_leftover($contents, $block)) {
                $found[] = $file . ': ' . $block[0];
            }
            if ('.htaccess' === basename($file) && '# BEGIN ' === $block[1]
                && self::block_leftover((string) $contents, array('Really Simple Security Disable directory indexing', '# BEGIN ', '# END '))) {
                $found[] = $file . ': Really Simple Security Disable directory indexing';
            }
        }
        $file     = WP_CONTENT_DIR . '/advanced-headers.php';
        $contents = self::leftover_contents($file);
        if (false === $contents || ('' !== trim($contents) && trim(self::RSSSL_STUB) !== trim(str_replace("\r\n", "\n", $contents)))) {
            $found[] = $file;
        }
        return $found;
    }

    /**
     * Empty markers left by core are harmless; an incomplete block is not.
     *
     * @param string   $contents Local file contents.
     * @param string[] $block    Marker definition.
     * @return bool
     */
    private static function block_leftover($contents, array $block) {
        $begin = $block[1] . $block[0];
        if (false === strpos($contents, $begin)) {
            return false;
        }
        // insert_with_markers() adds explanatory comments even with no rules.
        $empty = '/^' . preg_quote($begin, '/') . '[\t ]*\r?\n(?:[\t ]*(?:#[^\r\n]*)?\r?\n)*' . preg_quote($block[2] . $block[0], '/') . '[\t ]*(?:\r?\n|$)/m';
        return false !== strpos((string) preg_replace($empty, '', $contents), $begin);
    }

    /**
     * Site Health: check this site and the main site, never scan a whole network.
     *
     * @return array
     */
    public static function test_leftovers() {
        $result = array(
            'label'       => __('No Really Simple Security leftovers were found', 'seoprostack'),
            'status'      => 'good',
            'badge'       => array('label' => __('Security', 'seoprostack'), 'color' => 'blue'),
            'description' => '<p>' . esc_html__('Checks files and scheduled tasks left by Really Simple Security. Cleanup keeps the HTTPS redirect and an empty headers file, never edits wp-config.php and never changes plugin settings.', 'seoprostack') . '</p>',
            'actions'     => '',
            'test'        => self::CLEANUP,
        );
        if (self::rsssl_active()) {
            $result['label'] = __('Really Simple Security is active; its files and tasks are left alone', 'seoprostack');
            return $result;
        }
        $found = array_merge(self::leftover_cron(), self::leftover_files());
        if (is_multisite()) {
            switch_to_blog(get_main_site_id());
        }
        try {
            if (self::rsssl_active()) {
                $result['label'] = __('Really Simple Security is active on the main site; cleanup is unavailable', 'seoprostack');
                return $result;
            }
            $found = array_unique(array_merge($found, self::leftover_files(), self::leftover_cron()));
        } finally {
            if (is_multisite()) {
                restore_current_blog();
            }
        }
        if (is_multisite()) {
            $result['description'] .= '<p>' . esc_html__('Only this site and the network’s main site were checked. Cleanup first checks that Really Simple Security is inactive on every site, then clears scheduled tasks in batches across the network.', 'seoprostack') . '</p>';
        }
        if ($found) {
            $result['status'] = 'recommended';
            $result['label']  = __('Really Simple Security left files or scheduled tasks behind', 'seoprostack');
            $result['description'] .= '<ul><li><code>' . implode('</code></li><li><code>', array_map('esc_html', $found)) . '</code></li></ul>';
        }
        if (($found || is_multisite()) && current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            $result['actions'] = self::cleanup_form();
        }
        return $result;
    }

    /**
     * POST only, including continuation requests; state stays on the server.
     *
     * @return string
     */
    private static function cleanup_form() {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::CLEANUP) . '">'
            . wp_nonce_field(self::CLEANUP, '_wpnonce', true, false)
            . '<p><button type="submit" class="button">' . esc_html__('Check and clear Really Simple Security leftovers', 'seoprostack') . '</button></p></form>';
    }

    /**
     * Clear only a complete exact marked block. Core handles canonical htaccess;
     * its marker API cannot handle semicolon INI or legacy #Begin markers.
     *
     * @param string   $file  Fixed local path.
     * @param string[] $block Marker name, begin prefix and end prefix.
     * @return bool
     */
    private static function clear_leftover_block($file, array $block) {
        $contents = self::leftover_contents($file);
        if (false === $contents || is_link($file)) {
            return false;
        }
        $begin = $block[1] . $block[0];
        if (false === strpos($contents, $begin)) {
            return true;
        }
        $end     = $block[2] . $block[0];
        $pattern = '/^' . preg_quote($begin, '/') . '[\t ]*\r?\n(.*?)^' . preg_quote($end, '/') . '[\t ]*(?:\r?\n|$)/ms';
        // Core's marker writer uses substring matches. Reject duplicates,
        // prefix collisions and nested/malformed blocks before calling it.
        if (1 !== substr_count($contents, $begin) || 1 !== substr_count($contents, $end)
            || !preg_match($pattern, $contents, $matches) || !wp_is_writable($file)
            || preg_match('/^(?:# ?BEGIN |#Begin |; BEGIN |# ?END |#End |; END )/m', $matches[1])) {
            return false;
        }
        if ('# BEGIN ' === $block[1]) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            return insert_with_markers($file, $block[0], array());
        }
        $new = preg_replace($pattern, '', $contents);
        if (null === $new) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- core's marker writer does not support these delimiters.
        return strlen($new) === file_put_contents($file, $new, LOCK_EX);
    }

    /**
     * Keep the stub first, even if removal of a cached prepend later fails.
     *
     * @return bool
     */
    private static function clear_leftover_files() {
        $file = WP_CONTENT_DIR . '/advanced-headers.php';
        if (file_exists($file)) {
            $contents = self::leftover_contents($file);
            if (false === $contents || is_link($file) || !is_file($file) || !wp_is_writable($file)
                || !preg_match('/This file (?:is|was) created by Really Simple Security/', $contents)) {
                return false;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- retain the replaced plugin's stub for cached prepend references.
            if (strlen(self::RSSSL_STUB) !== file_put_contents($file, self::RSSSL_STUB, LOCK_EX)) {
                return false;
            }
        }
        $done = true;
        foreach (self::leftover_blocks() as $file => $block) {
            $done = self::clear_leftover_block($file, $block) && $done;
            if ('.htaccess' === basename($file) && '# BEGIN ' === $block[1]) {
                $done = self::clear_leftover_block($file, array('Really Simple Security Disable directory indexing', '# BEGIN ', '# END ')) && $done;
            }
        }
        return $done;
    }

    /**
     * Each subsite may have its own uploads rules as well as the shared ones.
     *
     * @return bool
     */
    private static function clear_site_uploads() {
        foreach (self::leftover_blocks() as $file => $block) {
            if ('#Begin ' === $block[1] && !self::clear_leftover_block($file, $block)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Explicit cleanup. Network requests preflight then clean at most 50 sites.
     * A short-lived user/network-scoped cursor cannot be forged by POST fields.
     */
    public static function cleanup_action() {
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            wp_die(esc_html__('You cannot clear these leftovers.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::CLEANUP);
        if (!isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']) {
            wp_die(esc_html__('Use the cleanup button in Site Health.', 'seoprostack'), '', array('response' => 405));
        }
        $key   = self::CLEANUP . '_' . get_current_blog_id() . '_' . get_current_user_id();
        $state = get_site_transient($key);
        $state = is_array($state) ? $state : array('phase' => 'check', 'offset' => 0);
        $sites = is_multisite() ? get_sites(array('network_id' => get_current_network_id(), 'fields' => 'ids', 'number' => 50, 'offset' => $state['offset'], 'orderby' => 'id', 'order' => 'ASC')) : array(get_current_blog_id());
        foreach ($sites as $site) {
            if (is_multisite()) {
                switch_to_blog((int) $site);
            }
            try {
                if (self::rsssl_active()) {
                    delete_site_transient($key);
                    wp_die(esc_html__('Really Simple Security is still active on a site. Deactivate all its versions before clearing leftovers.', 'seoprostack'));
                }
                if ('clean' === $state['phase'] || !is_multisite()) {
                    if (!self::clear_site_uploads()) {
                        delete_site_transient($key);
                        wp_die(esc_html__('An uploads rules file could not be cleared. Check its permissions and marked blocks, then run the check again.', 'seoprostack'));
                    }
                    foreach (self::leftover_cron() as $hook) {
                        if (false === wp_unschedule_hook($hook)) {
                            delete_site_transient($key);
                            wp_die(esc_html__('A scheduled task could not be cleared. Run the check again.', 'seoprostack'));
                        }
                    }
                }
            } finally {
                if (is_multisite()) {
                    restore_current_blog();
                }
            }
        }
        $more = is_multisite() && 50 === count($sites);
        if ($more) {
            $state['offset'] += count($sites);
        } elseif ('check' === $state['phase'] && is_multisite()) {
            $state = array('phase' => 'clean', 'offset' => 0);
        } else {
            if (is_multisite()) {
                switch_to_blog(get_main_site_id());
            }
            try {
                $done = !self::rsssl_active() && self::clear_leftover_files();
            } finally {
                if (is_multisite()) {
                    restore_current_blog();
                }
            }
            delete_site_transient($key);
            wp_die(esc_html($done ? __('Really Simple Security leftovers cleared. The HTTPS redirect, wp-config.php and plugin settings were kept. The headers stub remains for cached prepend references.', 'seoprostack') : __('Some files could not be cleared. Check their permissions and run Site Health again; the headers file was never deleted.', 'seoprostack')), '', array('back_link' => true));
        }
        set_site_transient($key, $state, 15 * MINUTE_IN_SECONDS);
        wp_die('<p>' . esc_html__('This batch is complete. Continue to check or clear the next batch of sites. Files are cleared only after the network checks and scheduled tasks are complete.', 'seoprostack') . '</p>' . self::cleanup_form(), '', array('response' => 200)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form escapes its fixed URL and values and uses core's nonce field.
    }

    /**
     * Really Simple Security's settings while it is active (its network
     * option when it is network-active), else none. Only read.
     *
     * @return array
     */
    public static function rsssl_options() {
        if (!array_intersect_key(self::REPLACES, self::active_plugins())) {
            return array();
        }
        $network = false;
        if (is_multisite()) {
            foreach (array_keys((array) get_site_option('active_sitewide_plugins', array())) as $file) {
                $network = $network || isset(self::REPLACES[dirname((string) $file)]);
            }
        }
        $options = $network ? get_site_option(self::RSSSL_OPTION, array()) : get_option(self::RSSSL_OPTION, array());
        return is_array($options) ? $options : array();
    }

    /**
     * Whether a switch is on in its settings. Selects store "disabled",
     * some checkboxes too, for off.
     *
     * @param array  $theirs Its settings.
     * @param string $key    Setting.
     * @return bool
     */
    private static function on(array $theirs, $key) {
        if (!isset($theirs[$key])) {
            return false;
        }
        $value = $theirs[$key];
        return !empty($value) && !in_array($value, array('disabled', 'none', 'no', 'false', '0'), true);
    }

    /**
     * Choices for a list setting switched on in Really Simple Security,
     * while it is active.
     *
     * @param string $setting List setting, such as `hardening_items`.
     * @return string[]
     */
    public static function choices($setting) {
        $theirs  = self::rsssl_options();
        $choices = array();
        foreach (self::MAP as $option => $target) {
            if ($target[1] === $setting && self::on($theirs, $option)) {
                $choices[] = $target[2];
            }
        }
        return array_values(array_unique($choices));
    }

    /**
     * Plugins screen: what Really Simple Security does here that SEO Pro
     * Stack, as set up, does not. Its login protection is FluentAuth's job.
     *
     * @param string[] $extras Plain names.
     * @param string   $slug   Plugin folder.
     * @return string[]
     */
    public static function extras($extras, $slug) {
        if (!isset(self::REPLACES[$slug])) {
            return $extras;
        }
        $extras = (array) $extras;
        $theirs = self::rsssl_options();
        $names  = self::names();
        foreach (self::MAP as $option => $target) {
            if (self::on($theirs, $option)
                && !(SEOProStack_Settings::get($target[0]) && in_array($target[2], (array) SEOProStack_Settings::get($target[1]), true))) {
                $extras[] = $names[$option];
            }
        }
        $redirect = isset($theirs['redirect']) && in_array($theirs['redirect'], array('wp_redirect', 'htaccess'), true);
        if (($redirect && (!self::switched_on() || self::bypassed('redirect')))
            || (!self::switched_on() && array_filter(self::HEADER_SWITCHES, function ($switch) use ($theirs) {
                return self::on($theirs, $switch);
            }))) {
            $extras[] = __('HTTPS redirect and security headers', 'seoprostack');
        }
        foreach (self::uncovered() as $option => $name) {
            if (self::on($theirs, $option)) {
                $extras[] = $name;
            }
        }
        return array_values(array_unique($extras));
    }

    /**
     * Names of its switches SEO Pro Stack covers, as its screens word them.
     *
     * @return array<string,string>
     */
    private static function names() {
        return array(
            'disable_xmlrpc'                => __('Disable XML-RPC', 'seoprostack'),
            'disable_application_passwords' => __('Disable application passwords', 'seoprostack'),
            'change_debug_log_location'     => __('Change debug.log file location', 'seoprostack'),
            'block_code_execution_uploads'  => __('Prevent code execution in the public Uploads folder', 'seoprostack'),
            'disable_user_enumeration'      => __('Disable user enumeration', 'seoprostack'),
            'disable_login_feedback'        => __('Prevent login feedback', 'seoprostack'),
            'disable_file_editing'          => __('Disable the built-in file editors', 'seoprostack'),
            'hide_wordpress_version'        => __('Hide your WordPress version', 'seoprostack'),
        );
    }

    /**
     * Its switches SEO Pro Stack does not cover. Login protection is
     * FluentAuth's (in Free Plugins → Admin). "Disable anyone can register"
     * and "Rename admin user" need nothing in their place: WordPress keeps
     * the setting and the name they leave.
     *
     * @return array<string,string>
     */
    private static function uncovered() {
        return array(
            'login_protection_enabled'          => __('Two-step login (FluentAuth has it)', 'seoprostack'),
            'enable_passkey_login'              => __('Passkey login (FluentAuth has it)', 'seoprostack'),
            'enable_limited_login_attempts'     => __('Limit login attempts (FluentAuth has it)', 'seoprostack'),
            'event_log_enabled'                 => __('Login event log (FluentAuth has it)', 'seoprostack'),
            'enable_firewall'                   => __('Firewall and IP blocking (FluentAuth blocks IP addresses)', 'seoprostack'),
            'enforce_password_security_enabled' => __('Enforce strong passwords', 'seoprostack'),
            'enforce_frequent_password_change'  => __('Enforce frequent password change', 'seoprostack'),
            'enable_hibp_check'                 => __('Compromised password check', 'seoprostack'),
            'enable_vulnerability_scanner'      => __('Vulnerability scanning', 'seoprostack'),
            'permission_detection'              => __('File permissions check', 'seoprostack'),
            'change_login_url_enabled'          => __('Custom login address', 'seoprostack'),
            'block_admin_creation'              => __('Block administrator creation outside WordPress', 'seoprostack'),
            'disable_http_methods'              => __('Disable HTTP methods', 'seoprostack'),
            'block_display_is_login'            => __('Block registrations whose display name is the login name', 'seoprostack'),
            'disable_indexing'                  => __('Disable directory browsing (Tools → Site Health checks it)', 'seoprostack'),
            'csp_status'                        => __('Content Security Policy', 'seoprostack'),
            'enable_permissions_policy'         => __('Permissions Policy', 'seoprostack'),
            'cross_origin_resource_policy'      => __('Cross-Origin Resource Policy', 'seoprostack'),
            'cross_origin_embedder_policy'      => __('Cross-Origin Embedder Policy', 'seoprostack'),
        );
    }
}
