<?php
/**
 * Redirect to the main address before WordPress loads.
 *
 * A site at www.example.com usually answers at example.com too, or the
 * other way round, and WordPress loads in full to send the visitor on:
 * redirect_canonical() or a domain mapping plugin answers with a 301. No
 * page cache keeps it (LiteSpeed Cache answers "miss"), so the first byte
 * took 0.6 to 5 seconds on five Hostinger sites behind Cloudflare (7
 * November 2026, GitHub issue #1383), for everyone who typed the bare
 * domain or followed an old link to it.
 *
 * This writes the same redirect at the top of the root .htaccess, so Apache
 * or LiteSpeed answers before PHP starts. Only redirects the site already
 * makes: for each site's address, its other address (with or without www.)
 * is opened from the server, without following redirects, with a query
 * string the rules leave out, so the check always reaches WordPress; it is
 * kept only when the answer is a 301 or 308 to that site's address, at the
 * same path. Hosts that are themselves a site's address are never
 * candidates. Checked daily and soon after a site's address changes; a
 * host whose main address is no longer a site's leaves the block at once,
 * before any check, so a changed address cannot loop.
 *
 * Only GET and HEAD requests are redirected (as WordPress would), never
 * /.well-known/ (certificate checks). One block for a network, from its
 * main site, as the network shares the file. Nothing is written on servers
 * that do not read .htaccess; the block goes when the switch is off or the
 * plugin is deactivated.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.178
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Host_Redirect extends SEOProStack_Feature {

    const KEY = 'host_redirect';

    /** Marker of the .htaccess block. */
    const MARKER = 'SEO Pro Stack main address';

    /** Site option: checked hosts (other host => target), status and time. */
    const STATE = 'seoprostack_host_redirect';

    /**
     * Site option: whether the server reads .htaccess files (yes or no)
     * and the root folder, noted from the main site's web requests, since
     * WP-CLI (and a host's cron through it) can tell neither.
     */
    const SERVER = 'seoprostack_host_redirect_server';

    /** Daily check, and the one soon after an address changes. */
    const HOOK = 'seoprostack_host_redirect_check';

    /** Query string the rules leave out, so checks reach WordPress. */
    const QUERY = 'seoprostack-host-check';

    /** Most addresses checked in one run. */
    const MAX_HOSTS = 100;

    /** Seconds one check may take. */
    const TIMEOUT = 5;

    /** Seconds one run may spend starting checks. */
    const BUDGET = 60;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default (#1383): it only answers sooner with the
                // redirect the site already makes, checked first.
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Redirect to the main address before WordPress loads', 'seoprostack'),
                'description' => __('When the site also answers at its other address, with or without www, WordPress loads in full to send visitors on to the main one, which can take seconds because no page cache keeps the redirect. This puts the same redirect in .htaccess, so the server answers at once. Each address is checked first, and only a redirect the site already makes is added; checked again daily and when a site’s address changes. Apache and LiteSpeed servers; on a network, set from the main site.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks. Also while switched off, so the block and the
     * schedule can be taken away.
     */
    public static function boot() {
        add_action(self::HOOK, array(__CLASS__, 'check'));
        add_action('seoprostack_settings_changed', array(__CLASS__, 'settings_changed'), 10, 2);
        add_action('update_option_home', array(__CLASS__, 'addresses_changed'));
        if (is_multisite()) {
            add_action('wp_initialize_site', array(__CLASS__, 'addresses_changed'), 100);
            add_action('wp_update_site', array(__CLASS__, 'addresses_changed'), 100);
            add_action('wp_delete_site', array(__CLASS__, 'addresses_changed'), 100);
        }
        if (is_admin()) {
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
        }
        if (is_admin() || wp_doing_cron()) {
            self::remember();
        }
        if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            self::maybe_schedule();
        }
    }

    /**
     * Whether this request's server reads .htaccess files (WordPress's
     * $is_apache: Apache or LiteSpeed): yes, no, or '' when it cannot tell
     * (WP-CLI and other command-line runs).
     *
     * @return string
     */
    private static function server_now() {
        global $is_apache;
        if ((defined('WP_CLI') && WP_CLI) || empty($_SERVER['SERVER_SOFTWARE'])) {
            return '';
        }
        return !empty($is_apache) ? 'yes' : 'no';
    }

    /**
     * This web request's note: whether the server reads .htaccess files
     * and the root folder; empty when it cannot tell.
     *
     * @return array{reads?:string,root?:string}
     */
    private static function note_now() {
        $reads = self::server_now();
        if ('' === $reads) {
            return array();
        }
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return array('reads' => $reads, 'root' => trailingslashit(wp_normalize_path(get_home_path())));
    }

    /**
     * Note the server and root folder from the main site's web requests,
     * only when they change, and then check the addresses again soon
     * (first noted, or the site moved).
     */
    public static function remember() {
        if (is_multisite() && !is_main_site()) {
            return;
        }
        $note = self::note_now();
        if (!$note || get_site_option(self::SERVER, array()) === $note) {
            return;
        }
        update_site_option(self::SERVER, $note);
        if (self::enabled() && !wp_next_scheduled(self::HOOK, array('soon'))) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK, array('soon'));
        }
    }

    /**
     * The note from this request, else from an earlier web request.
     *
     * @return array{reads?:string,root?:string}
     */
    private static function note() {
        $note = self::note_now();
        if (!$note) {
            $saved = get_site_option(self::SERVER, array());
            if (is_array($saved) && isset($saved['reads'], $saved['root']) && in_array($saved['reads'], array('yes', 'no'), true) && '' !== $saved['root']) {
                $note = array('reads' => (string) $saved['reads'], 'root' => (string) $saved['root']);
            }
        }
        return $note;
    }

    /**
     * Whether the server reads .htaccess files.
     *
     * @return bool|null Null when no web request has told yet.
     */
    private static function reads_htaccess() {
        $note = self::note();
        return isset($note['reads']) ? 'yes' === $note['reads'] : null;
    }

    /**
     * Keep the daily check in step with the switch, on the main site only,
     * and a written block in step with this version's rules. Switched off:
     * take the block away.
     */
    public static function maybe_schedule() {
        if (is_multisite() && !is_main_site()) {
            return;
        }
        $next = wp_next_scheduled(self::HOOK);
        if (self::enabled()) {
            if (!$next) {
                // The first check soon, then daily.
                wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', self::HOOK);
            }
            $state = get_site_option(self::STATE, false);
            if (is_array($state) && isset($state['status'], $state['hosts']) && 'written' === $state['status'] && is_array($state['hosts'])
                && (!isset($state['hash']) || self::hash($state['hosts']) !== $state['hash'])) {
                // An update changed the rules: write them again, for the same hosts.
                self::save(self::still_valid($state['hosts'], self::main_hosts()));
            }
            return;
        }
        if ($next || wp_next_scheduled(self::HOOK, array('soon'))) {
            wp_unschedule_hook(self::HOOK);
        }
        if (false !== get_site_option(self::STATE, false)) {
            self::remove();
        }
    }

    /**
     * The switch changed on the main site: check soon, or take the block away.
     *
     * @param array $old Settings before.
     * @param array $new Settings after.
     */
    public static function settings_changed($old, $new) {
        $before = !empty($old[self::KEY]);
        $after  = !empty($new[self::KEY]);
        if ($before === $after || (is_multisite() && !is_main_site())) {
            return;
        }
        wp_unschedule_hook(self::HOOK);
        if (!$after) {
            self::remove();
        }
        // On: maybe_schedule() adds the check on the next admin screen or cron run.
    }

    /**
     * A site's address changed, or a site was added or deleted: drop rules
     * whose main address is no longer a site's, or whose other address now
     * is one, at once; then check the addresses soon.
     */
    public static function addresses_changed() {
        $state = get_site_option(self::STATE, false);
        if (is_array($state) && !empty($state['hosts']) && is_array($state['hosts'])) {
            $mains = self::main_hosts();
            $kept  = self::still_valid($state['hosts'], $mains);
            if ($kept !== $state['hosts']) {
                self::save($kept);
            }
        }
        $switched = is_multisite() && !is_main_site();
        if ($switched) {
            switch_to_blog(get_main_site_id());
        }
        if (self::enabled() && !wp_next_scheduled(self::HOOK, array('soon'))) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK, array('soon'));
        }
        if ($switched) {
            restore_current_blog();
        }
    }

    /**
     * Check each site's other address, then write the rules for those that
     * redirect to it. Hosts not reached within the time keep their rule
     * while it is still valid. Nothing is opened on a server that reads
     * no .htaccess files.
     *
     * @param string $when soon for the check after a change, '' daily.
     */
    public static function check($when = '') {
        unset($when); // Only keeps the soon event apart from the daily one.
        if (is_multisite() && !is_main_site()) {
            return;
        }
        if (!self::enabled()) {
            self::remove();
            return;
        }
        if (false === self::reads_htaccess()) {
            self::save(array());
            return;
        }
        $mains = self::main_hosts();
        $state = get_site_option(self::STATE, array());
        $old   = is_array($state) && isset($state['hosts']) && is_array($state['hosts']) ? self::still_valid($state['hosts'], $mains) : array();
        $hosts = array();
        $start = time();
        foreach (self::candidates($mains) as $other => $main) {
            if (time() - $start > self::BUDGET) {
                if (isset($old[$other])) {
                    $hosts[$other] = $old[$other];
                }
                continue;
            }
            $target = self::verify($other, $main);
            if ('' !== $target) {
                $hosts[$other] = $target;
            }
        }
        self::save($hosts);
    }

    /**
     * Every site's address host, lower case: the home address on a single
     * site; each site's domain and the main site's home on a network.
     *
     * @return array<string,bool> Host => true.
     */
    public static function main_hosts() {
        $hosts = array();
        $home  = wp_parse_url(is_multisite() ? get_home_url(get_main_site_id(), '/') : home_url('/'));
        if (is_array($home) && !empty($home['host']) && empty($home['port'])) {
            $hosts[strtolower($home['host'])] = true;
        }
        if (is_multisite()) {
            // Every site, archived and spam ones too: none may be redirected.
            foreach (get_sites(array('number' => 10000, 'fields' => '')) as $site) {
                $hosts[strtolower((string) $site->domain)] = true;
            }
        }
        return $hosts;
    }

    /**
     * Each main host's other address (www. added or taken away) that is not
     * itself a site's address, at most MAX_HOSTS.
     *
     * @param array<string,bool> $mains Main hosts.
     * @return array<string,string> Other host => main host.
     */
    public static function candidates(array $mains) {
        $out = array();
        foreach (array_keys($mains) as $main) {
            if (!self::valid_host($main)) {
                continue;
            }
            $other = 0 === strpos($main, 'www.') ? substr($main, 4) : 'www.' . $main;
            if (isset($mains[$other]) || !self::valid_host($other)) {
                continue;
            }
            $out[$other] = $main;
            if (count($out) >= self::MAX_HOSTS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Whether a host is a domain name with a dot (not an IP address or
     * localhost), safe to put in a rule.
     *
     * @param string $host Host.
     * @return bool
     */
    private static function valid_host($host) {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', (string) $host)
            && !filter_var($host, FILTER_VALIDATE_IP)
            && strlen($host) <= 253;
    }

    /**
     * Open the other address's home page from the server, without following
     * redirects, and return where it sends visitors when that is the main
     * host's home page with a 301 or 308. Sent straight to this server when
     * SEO Pro Stack knows its address (as Keep pages cached does), since
     * some hosts refuse a server's own requests that come back through a
     * CDN (#1242), and the rules answer here, not at the CDN.
     *
     * @param string $other Other host.
     * @param string $main  Main host.
     * @return string The main address's scheme and host, or '' when not.
     */
    public static function verify($other, $main) {
        $scheme  = 'https' === wp_parse_url(home_url('/'), PHP_URL_SCHEME) ? 'https' : 'http';
        $url     = $scheme . '://' . $other . '/?' . self::QUERY . '=' . wp_generate_password(8, false);
        $resolve = null;
        if (is_readable(SEOPROSTACK_DIR . 'includes/class-seoprostack-keep-cached-requests.php')) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-keep-cached-requests.php';
            $resolve = SEOProStack_Keep_Cached_Requests::resolver($url);
        }
        if ($resolve) {
            add_action('http_api_curl', $resolve);
        }
        $response = wp_remote_get($url, array(
            'redirection'         => 0,
            'timeout'             => self::TIMEOUT,
            'limit_response_size' => 1024,
            'headers'             => array('Cache-Control' => 'no-cache'),
            'user-agent'          => 'SEO Pro Stack address check; ' . home_url('/'),
            // Core's filter for requests to the site itself, as its loopback test uses.
            'sslverify'           => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
        ));
        if ($resolve) {
            remove_action('http_api_curl', $resolve);
        }
        if (is_wp_error($response) || !in_array((int) wp_remote_retrieve_response_code($response), array(301, 308), true)) {
            return '';
        }
        $location = wp_remote_retrieve_header($response, 'location');
        if (!is_string($location)) {
            return ''; // None, or more than one: not a plain redirect.
        }
        $to = wp_parse_url($location);
        if (!is_array($to) || empty($to['host']) || strtolower($to['host']) !== $main || !empty($to['port'])
            || !isset($to['scheme']) || !in_array(strtolower($to['scheme']), array('http', 'https'), true)
            || (isset($to['path']) && '/' !== $to['path'] && '' !== $to['path'])) {
            return '';
        }
        return strtolower($to['scheme']) . '://' . $main;
    }

    /**
     * Rules whose main host is still a site's address and whose other host
     * is not.
     *
     * @param array<string,string> $hosts Other host => target.
     * @param array<string,bool>   $mains Main hosts.
     * @return array<string,string>
     */
    private static function still_valid(array $hosts, array $mains) {
        $kept = array();
        foreach ($hosts as $other => $target) {
            $main = (string) wp_parse_url((string) $target, PHP_URL_HOST);
            if (isset($mains[$main]) && !isset($mains[$other]) && self::valid_host($other) && self::valid_host($main)) {
                $kept[$other] = (string) $target;
            }
        }
        return $kept;
    }

    /**
     * The block's rules for the checked hosts.
     *
     * @param array<string,string> $hosts Other host => target (scheme://host).
     * @return string[]
     */
    public static function rules(array $hosts) {
        if (!$hosts) {
            return array();
        }
        $lines = array('<IfModule mod_rewrite.c>', 'RewriteEngine On');
        foreach ($hosts as $other => $target) {
            $lines[] = 'RewriteCond %{HTTP_HOST} ^' . str_replace('.', '\.', $other) . '(:[0-9]+)?$ [NC]';
            $lines[] = 'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$';
            // The request line, not REQUEST_URI: after WordPress's rules send a
            // missing file to index.php, the rules run again with that URI.
            $lines[] = 'RewriteCond %{THE_REQUEST} !\s(https?://[^/\s]+)?/\.well-known/ [NC]';
            $lines[] = 'RewriteCond %{QUERY_STRING} !(^|&)' . self::QUERY . '=';
            $lines[] = 'RewriteRule ^ ' . $target . '%{REQUEST_URI} [R=301,L]';
        }
        $lines[] = '</IfModule>';
        return $lines;
    }

    /**
     * Write the rules (or remove the block when there are none) and save
     * the hosts with how it went.
     *
     * @param array<string,string> $hosts Other host => target.
     */
    private static function save(array $hosts) {
        $reads  = self::reads_htaccess();
        $status = 'written';
        if (null === $reads) {
            // Not known yet from a web request (only WP-CLI so far): write
            // nothing; the first admin screen notes it and checks again.
            $status = 'waiting';
        } elseif (!$reads) {
            // This server reads no .htaccess files: write nothing.
            $status = 'other';
            SEOProStack_Hardening::files_write(self::file(), array(), self::MARKER);
        } elseif (!SEOProStack_Hardening::files_write(self::file(), self::rules($hosts), self::MARKER)) {
            $status = 'unwritable';
        }
        update_site_option(self::STATE, array(
            'hosts'   => $hosts,
            'status'  => $status,
            'hash'    => self::hash($hosts),
            'checked' => time(),
        ));
    }

    /**
     * Fingerprint of the rules for these hosts, so an update that changes
     * the rules writes them again.
     *
     * @param array<string,string> $hosts Other host => target.
     * @return string
     */
    private static function hash(array $hosts) {
        return md5(implode("\n", self::rules($hosts)));
    }

    /**
     * Take the block away and forget the hosts once it is gone.
     */
    public static function remove() {
        if (SEOProStack_Hardening::files_write(self::file(), array(), self::MARKER)) {
            delete_site_option(self::STATE);
        }
    }

    /**
     * The site's root .htaccess (the network's on multisite), from the
     * note, as WP-CLI cannot work out the root when WordPress has its own
     * folder.
     *
     * @return string
     */
    private static function file() {
        $note = self::note();
        if (isset($note['root'])) {
            return $note['root'] . '.htaccess';
        }
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return get_home_path() . '.htaccess';
    }

    /**
     * What the block does now, in the settings panel.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel($key, $field = array()) {
        if (self::KEY !== $key || !self::switched_on()) {
            return;
        }
        echo '<div class="sps-panel-note">';
        if (is_multisite() && !is_main_site()) {
            echo '<p>' . esc_html__('The sites share the main site’s .htaccess, so the main site’s setting sets this for the whole network.', 'seoprostack') . '</p></div>';
            return;
        }
        $state  = get_site_option(self::STATE, array());
        $hosts  = is_array($state) && isset($state['hosts']) && is_array($state['hosts']) ? $state['hosts'] : array();
        $status = is_array($state) && isset($state['status']) ? (string) $state['status'] : '';
        if ('' === $status || 'waiting' === $status) {
            echo '<p>' . esc_html__('The addresses are checked within a few minutes.', 'seoprostack') . '</p>';
        } elseif ('other' === $status) {
            echo '<p>' . esc_html__('This server does not read .htaccess files, so WordPress keeps sending visitors to the main address.', 'seoprostack') . '</p>';
        } elseif ('unwritable' === $status && !$hosts) {
            // A block left from before an address changed could send visitors
            // round in a loop, so it must go.
            echo '<p>' . esc_html(sprintf(
                /* translators: 1: .htaccess file path, 2: block marker */
                __('%1$s could not be changed. If it still has lines between “# BEGIN %2$s” and “# END %2$s”, remove them: they may send visitors to an address that is no longer the main one.', 'seoprostack'),
                self::file(),
                self::MARKER
            )) . '</p>';
        } elseif ('unwritable' === $status) {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: .htaccess file path */
                __('%s could not be changed. To answer these addresses before WordPress loads, put these lines at the top of it, in place of any SEO Pro Stack main address lines already there:', 'seoprostack'),
                self::file()
            )) . '</p>';
            echo '<pre class="sps-code">' . esc_html('# BEGIN ' . self::MARKER . "\n" . implode("\n", self::rules($hosts)) . "\n# END " . self::MARKER) . '</pre>';
        } elseif (!$hosts) {
            echo '<p>' . esc_html__('No other address sends visitors here, so there is nothing to add.', 'seoprostack') . '</p>';
        } else {
            $list = array();
            foreach ($hosts as $other => $target) {
                $list[] = $other . ' → ' . (string) wp_parse_url($target, PHP_URL_HOST);
            }
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: list of "other address → main address" */
                __('The server sends these addresses on without loading WordPress: %s.', 'seoprostack'),
                implode(', ', $list)
            )) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Plugin deactivated: take the block away, unless only a network's
     * subsite deactivated it (the network shares the file).
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        if (is_multisite() && !$network_wide && !is_main_site()) {
            return;
        }
        wp_unschedule_hook(self::HOOK);
        self::remove();
    }
}
