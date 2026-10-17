<?php
/**
 * HTTPS check: the site's certificate, TLS versions, cipher and HSTS.
 *
 * Connects from the server to the site's own address, as a visitor does
 * (behind Cloudflare or another proxy, that is the proxy's certificate and
 * settings), and reports what SSL Labs' summary shows: whether the
 * certificate is trusted, matches the host name and how long it has left;
 * the best TLS version and cipher; whether TLS 1.0 and 1.1 are refused; and
 * whether the home page sends HSTS. For the full grade it links to the SSL
 * Labs report for the host, opened by the person who clicks: SSL Labs'
 * terms forbid using its API commercially or on a public web site without
 * Qualys's permission, so nothing is sent to it.
 *
 * Runs in Site Health's async test (also in its weekly cron check) and from
 * Check now on the Security tab, never on a visitor's page. The last result
 * is stored, not autoloaded, for the Security tab.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.53
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Https_Check extends SEOProStack_Feature {

    /**
     * Site Health test, also the admin-ajax action's suffix. Hyphens only:
     * site-health.js turns the first underscore into a hyphen.
     */
    const TEST = 'seoprostack-https';

    /** Last result, not autoloaded. */
    const OPTION = 'seoprostack_https_check';

    /** Check now (admin-post). */
    const ACTION = 'seoprostack_https_check';

    /** Seconds each connection may take. */
    const TIMEOUT = 5;

    /** Days left on the certificate below which Site Health warns. */
    const EXPIRY_DAYS = 14;

    /** Shortest HSTS max-age without a warning: six months, as SSL Labs asks. */
    const HSTS_MIN = 15768000;

    /** SSL Labs' report for a host. */
    const SSL_LABS = 'https://www.ssllabs.com/ssltest/analyze.html';

    /**
     * Register hooks. Nothing to check on a site whose address is http.
     */
    public static function boot() {
        if (is_admin()) {
            add_action('seoprostack_settings_tab_after', array(__CLASS__, 'render'));
        }
        if (!self::https_site()) {
            return;
        }
        add_filter('site_status_tests', array(__CLASS__, 'tests'));
        if (is_admin()) {
            add_action('wp_ajax_health-check-' . self::TEST, array(__CLASS__, 'ajax_test'));
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
        }
    }

    /**
     * Whether the site address starts with https.
     *
     * @return bool
     */
    private static function https_site() {
        return 'https' === wp_parse_url(home_url('/'), PHP_URL_SCHEME);
    }

    /**
     * The site's host name, in lower case.
     *
     * @return string
     */
    private static function host() {
        return strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    }

    /**
     * The site's HTTPS port.
     *
     * @return int
     */
    private static function port() {
        $port = wp_parse_url(home_url('/'), PHP_URL_PORT);
        return $port ? (int) $port : 443;
    }

    /**
     * Whether SSL Labs can reach the host: a public name, not an IP address
     * or a local one (.local, .test, localhost).
     *
     * @param string $host Host name.
     * @return bool
     */
    private static function public_host($host) {
        if ('' === $host || false === strpos($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        $parts = explode('.', $host);
        return !in_array(end($parts), array('local', 'localhost', 'test', 'invalid', 'example', 'internal', 'lan', 'home', 'arpa'), true);
    }

    /**
     * SSL Labs' report for the site's host.
     *
     * @return string Empty for hosts SSL Labs cannot reach.
     */
    public static function ssl_labs_url() {
        $host = self::host();
        return self::public_host($host) ? self::SSL_LABS . '?d=' . rawurlencode($host) : '';
    }

    /* ------------------------------------------------------------------
     * The check
     * ------------------------------------------------------------------ */

    /**
     * Check the site's HTTPS now.
     *
     * @return array{checked:int,host:string,items:array<int,array{part:string,status:string,text:string}>}
     */
    public static function check() {
        $host   = self::host();
        $result = array('checked' => time(), 'host' => $host, 'items' => array());
        if (!function_exists('openssl_x509_parse') || !function_exists('stream_socket_client')) {
            $result['items'][] = self::item('certificate', 'unknown', __('PHP’s OpenSSL extension is not loaded, so this server cannot check the certificate. Use the SSL Labs report.', 'seoprostack'));
            return $result;
        }
        $port    = self::port();
        $trusted = self::connect($host, $port, array(
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'peer_name'         => $host,
            'cafile'            => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
            'capture_peer_cert' => true,
        ));
        $conn = $trusted['ok'] ? $trusted : self::connect($host, $port, array(
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'peer_name'         => $host,
            'capture_peer_cert' => true,
        ));
        if (!$conn['ok'] && preg_match('/unsupported protocol|wrong version number|protocol version/i', $conn['error'])
            && 'recommended' === self::old_tls_item($host, $port)['status']) {
            $result['items'][] = self::item('protocol', 'critical', __('The site accepts only TLS 1.0 or 1.1, which browsers no longer use: turn on TLS 1.2 and 1.3 in the server’s or CDN’s TLS settings.', 'seoprostack'));
            return $result;
        }
        if (!$conn['ok']) {
            $result['items'][] = self::item('certificate', 'unknown', sprintf(
                /* translators: 1: host and port, 2: error message */
                __('This server could not connect to %1$s over HTTPS (%2$s). Some hosts block it; use the SSL Labs report.', 'seoprostack'),
                $host . ':' . $port,
                $conn['error']
            ));
            return $result;
        }
        $result['items'][] = self::certificate_item($host, $conn['cert'], $trusted['ok'], $trusted['error']);
        $result['items'][] = self::protocol_item($conn['crypto']);
        $result['items'][] = self::old_tls_item($host, $port);
        $result['items'][] = self::hsts_item();
        return $result;
    }

    /**
     * One finding.
     *
     * @param string $part   certificate, protocol, old_tls or hsts.
     * @param string $status good, recommended, critical or unknown.
     * @param string $text   Plain text.
     * @return array{part:string,status:string,text:string}
     */
    private static function item($part, $status, $text) {
        return array('part' => $part, 'status' => $status, 'text' => $text);
    }

    /**
     * Open a TLS connection and close it again.
     *
     * @param string $host    Host name.
     * @param int    $port    Port.
     * @param array  $options `ssl` stream context options.
     * @return array{ok:bool,error:string,cert:mixed,crypto:array}
     */
    private static function connect($host, $port, array $options) {
        $options['SNI_enabled'] = true;
        $context = stream_context_create(array('ssl' => $options));
        $message = '';
        // Handshake failures arrive as PHP warnings; keep their text and
        // keep them out of the log.
        set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- restored straight after the connection.
            static function ($errno, $errstr) use (&$message) {
                $message .= ('' === $message ? '' : "\n") . $errstr;
                return true;
            }
        );
        try {
            $socket = stream_socket_client('ssl://' . $host . ':' . $port, $code, $error, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }
        if (!$socket) {
            // OpenSSL's reasons come as warnings; the error string has the socket's.
            return array('ok' => false, 'error' => self::short_error(trim($message . "\n" . (string) $error)), 'cert' => null, 'crypto' => array());
        }
        $meta   = stream_get_meta_data($socket);
        $params = stream_context_get_params($socket);
        fclose($socket); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a network socket, not a file.
        return array(
            'ok'     => true,
            'error'  => '',
            'cert'   => isset($params['options']['ssl']['peer_certificate']) ? $params['options']['ssl']['peer_certificate'] : null,
            'crypto' => isset($meta['crypto']) && is_array($meta['crypto']) ? $meta['crypto'] : array(),
        );
    }

    /**
     * OpenSSL's reason, without PHP's wrapping.
     *
     * @param string $error Error text.
     * @return string
     */
    private static function short_error($error) {
        // OpenSSL 1.1 and 3: error:0A000086:SSL routines:[function]:reason.
        if (preg_match_all('/error:[0-9A-Fa-f]+:[^:\n]*:[^:\n]*:([^\n]+)/', $error, $matches)) {
            return implode('; ', array_unique(array_map('trim', $matches[1])));
        }
        $lines = array_filter(array_map('trim', explode("\n", $error)));
        $error = $lines ? (string) end($lines) : '';
        $error = trim(preg_replace('/^stream_socket_client\(\):\s*/', '', $error) ?? $error);
        return '' !== $error ? substr($error, 0, 200) : __('no answer', 'seoprostack');
    }

    /**
     * The certificate: trusted, for this host, days left.
     *
     * @param string $host    Host name.
     * @param mixed  $cert    Peer certificate.
     * @param bool   $trusted Whether the verified connection worked.
     * @param string $why     Why it did not, from OpenSSL.
     * @return array{part:string,status:string,text:string}
     */
    private static function certificate_item($host, $cert, $trusted, $why) {
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (!is_array($info) || empty($info['validTo_time_t'])) {
            return self::item('certificate', $trusted ? 'good' : 'critical', $trusted
                ? __('The certificate is trusted and matches the site’s address.', 'seoprostack')
                : __('The certificate is not trusted, and this server could not read it.', 'seoprostack'));
        }
        $issuer = '';
        foreach (array('O', 'CN') as $field) {
            if ('' === $issuer && !empty($info['issuer'][$field])) {
                $issuer = is_array($info['issuer'][$field]) ? (string) reset($info['issuer'][$field]) : (string) $info['issuer'][$field];
            }
        }
        $expires = (int) $info['validTo_time_t'];
        $days    = (int) floor(($expires - time()) / DAY_IN_SECONDS);
        $date    = wp_date(get_option('date_format'), $expires);
        if ($expires <= time()) {
            /* translators: %s: date */
            return self::item('certificate', 'critical', sprintf(__('The certificate expired on %s. Browsers warn visitors away from the site: renew it now.', 'seoprostack'), $date));
        }
        if (!self::names_match($host, $info)) {
            /* translators: %s: host name */
            return self::item('certificate', 'critical', sprintf(__('The certificate is not for %s. Browsers warn visitors away from the site: get a certificate that names it.', 'seoprostack'), $host));
        }
        if (!$trusted) {
            return self::item('certificate', 'critical', sprintf(
                /* translators: 1: issuer, 2: why */
                __('The certificate from %1$s is not trusted (%2$s). It may be self-signed, or the server may not send the certificates that link it to a trusted one (an incomplete chain).', 'seoprostack'),
                '' !== $issuer ? $issuer : __('an unknown issuer', 'seoprostack'),
                $why
            ));
        }
        $text = sprintf(
            /* translators: 1: issuer, 2: number of days, 3: date */
            _n('Valid certificate from %1$s, with %2$d day left (%3$s).', 'Valid certificate from %1$s, with %2$d days left (%3$s).', $days, 'seoprostack'),
            '' !== $issuer ? $issuer : __('a trusted issuer', 'seoprostack'),
            $days,
            $date
        );
        if ($days < self::EXPIRY_DAYS) {
            return self::item('certificate', 'recommended', $text . ' ' . __('Most certificates renew themselves about 30 days before; check the host’s or CDN’s certificate settings.', 'seoprostack'));
        }
        return self::item('certificate', 'good', $text);
    }

    /**
     * Whether the certificate names the host, wildcards included.
     *
     * @param string $host Host name.
     * @param array  $info Parsed certificate.
     * @return bool
     */
    private static function names_match($host, array $info) {
        $names = array();
        if (!empty($info['extensions']['subjectAltName'])) {
            foreach (explode(',', (string) $info['extensions']['subjectAltName']) as $entry) {
                $entry = trim($entry);
                if (0 === stripos($entry, 'DNS:')) {
                    $names[] = strtolower(substr($entry, 4));
                }
            }
        }
        if (!$names && !empty($info['subject']['CN'])) {
            $names[] = strtolower((string) $info['subject']['CN']);
        }
        // *.example.com covers one level: www.example.com, not example.com.
        $dot    = strpos($host, '.');
        $parent = false === $dot ? '' : substr($host, $dot);
        foreach ($names as $name) {
            if ($name === $host || ('' !== $parent && '*' . $parent === $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The best TLS version and the cipher the connection used.
     *
     * @param array $crypto Stream metadata's crypto part.
     * @return array{part:string,status:string,text:string}
     */
    private static function protocol_item(array $crypto) {
        $protocol = isset($crypto['protocol']) ? (string) $crypto['protocol'] : '';
        $cipher   = isset($crypto['cipher_name']) ? (string) $crypto['cipher_name'] : '';
        $bits     = isset($crypto['cipher_bits']) ? (int) $crypto['cipher_bits'] : 0;
        if ('' === $protocol) {
            return self::item('protocol', 'unknown', __('PHP did not say which TLS version and cipher the connection used.', 'seoprostack'));
        }
        $name = str_replace('TLSv', 'TLS ', $protocol);
        /* translators: 1: TLS version, 2: cipher name, 3: key length in bits */
        $text = sprintf(__('Connects with %1$s and %2$s (%3$d-bit).', 'seoprostack'), $name, $cipher, $bits);
        if (!in_array($protocol, array('TLSv1.2', 'TLSv1.3'), true)) {
            return self::item('protocol', 'critical', $text . ' ' . __('Browsers need TLS 1.2 or 1.3: turn them on in the server’s or CDN’s TLS settings.', 'seoprostack'));
        }
        if ($bits && $bits < 128) {
            return self::item('protocol', 'recommended', $text . ' ' . __('That cipher is weak: allow only 128-bit or stronger ciphers.', 'seoprostack'));
        }
        return self::item('protocol', 'good', $text);
    }

    /**
     * Whether TLS 1.0 and 1.1 are refused. Says so when this server's
     * OpenSSL will not offer them, so it cannot tell.
     *
     * @param string $host Host name.
     * @param int    $port Port.
     * @return array{part:string,status:string,text:string}
     */
    private static function old_tls_item($host, $port) {
        $versions = array();
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')) {
            $versions['TLS 1.0'] = STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
            $versions['TLS 1.1'] = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }
        $accepted = array();
        $unknown  = array();
        foreach ($versions as $name => $method) {
            $options = array(
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'peer_name'        => $host,
                'crypto_method'    => $method,
            );
            // OpenSSL 1.1 and later refuse these versions' ciphers at their
            // default security level; level 0 lets this one test offer them.
            if (defined('OPENSSL_VERSION_NUMBER') && OPENSSL_VERSION_NUMBER >= 0x10100000) {
                $options['ciphers'] = 'DEFAULT@SECLEVEL=0';
            }
            $conn = self::connect($host, $port, $options);
            if ($conn['ok']) {
                $accepted[] = $name;
            } elseif (preg_match('/no protocols available|no ciphers available|no cipher match|unknown protocol|timed out|refused|getaddrinfo|no answer/i', $conn['error'])) {
                // This side could not offer it, or there was no TLS answer.
                $unknown[] = $name;
            }
        }
        if ($accepted) {
            return self::item('old_tls', 'recommended', sprintf(
                /* translators: %s: TLS 1.0, TLS 1.1 or both */
                __('The site still accepts %s, which browsers no longer use; SSL Labs caps the grade at B for it. Set the minimum TLS version to 1.2 in the host’s or CDN’s settings (on Cloudflare: SSL/TLS → Edge Certificates → Minimum TLS Version).', 'seoprostack'),
                implode(__(' and ', 'seoprostack'), $accepted)
            ));
        }
        $refused = array_values(array_diff(array_keys($versions), $unknown));
        if (!$refused) {
            return self::item('old_tls', 'unknown', __('This server’s OpenSSL will not offer TLS 1.0 or 1.1, so it cannot tell whether the site refuses them. The SSL Labs report shows it.', 'seoprostack'));
        }
        if (count($refused) < 2) {
            return self::item('old_tls', 'unknown', sprintf(
                /* translators: 1: version refused, 2: version not tested */
                __('%1$s is refused; this server could not test %2$s. The SSL Labs report shows it.', 'seoprostack'),
                $refused[0],
                'TLS 1.0' === $refused[0] ? 'TLS 1.1' : 'TLS 1.0'
            ));
        }
        return self::item('old_tls', 'good', __('TLS 1.0 and 1.1 are refused.', 'seoprostack'));
    }

    /**
     * Whether the home page sends HSTS, and for how long.
     *
     * @return array{part:string,status:string,text:string}
     */
    private static function hsts_item() {
        // The site's own home page, with a short timeout. Not
        // wp_safe_remote_head(): a site may resolve its own name locally.
        // The certificate is checked above, so an untrusted one does not
        // hide the header. No redirects: browsers keep HSTS for the host
        // that sent it, and a redirect's own response counts.
        $response = wp_remote_head(home_url('/'), array('timeout' => self::TIMEOUT, 'redirection' => 0, 'sslverify' => false));
        if (is_wp_error($response)) {
            /* translators: %s: error message */
            return self::item('hsts', 'unknown', sprintf(__('The home page could not be loaded from this server to check HSTS (%s).', 'seoprostack'), $response->get_error_message()));
        }
        $header = wp_remote_retrieve_header($response, 'strict-transport-security');
        $header = is_array($header) ? (string) reset($header) : (string) $header;
        if ('' === trim($header)) {
            return self::item('hsts', 'recommended', __('No HSTS header: browsers may still try http first, where the connection can be intercepted. Turn on HTTPS and security headers on SEO Pro Stack’s Security tab.', 'seoprostack'));
        }
        $age = preg_match('/max-age\s*=\s*"?(\d+)/i', $header, $match) ? (int) $match[1] : 0;
        $sub = false !== stripos($header, 'includesubdomains');
        if ($age < self::HSTS_MIN) {
            /* translators: %s: the header's value */
            return self::item('hsts', 'recommended', sprintf(__('HSTS is sent, but for less than six months (%s). SSL Labs asks for six months or more.', 'seoprostack'), $header));
        }
        $days = (int) floor($age / DAY_IN_SECONDS);
        return self::item('hsts', 'good', $sub
            /* translators: %d: number of days */
            ? sprintf(_n('HSTS is sent, for %d day, with subdomains.', 'HSTS is sent, for %d days, with subdomains.', $days, 'seoprostack'), $days)
            /* translators: %d: number of days */
            : sprintf(_n('HSTS is sent, for %d day.', 'HSTS is sent, for %d days.', $days, 'seoprostack'), $days));
    }

    /**
     * The overall status: the worst finding. One that could not be checked
     * counts only when nothing could be.
     *
     * @param array $items Findings.
     * @return string good, recommended or critical.
     */
    private static function status(array $items) {
        $statuses = wp_list_pluck($items, 'status');
        if (in_array('critical', $statuses, true)) {
            return 'critical';
        }
        if (in_array('recommended', $statuses, true) || !array_diff($statuses, array('unknown'))) {
            return 'recommended';
        }
        return 'good';
    }

    /**
     * Run and keep the result, except in the read-only WP-CLI audit.
     *
     * @return array
     */
    public static function run() {
        $result = self::check();
        if (!class_exists('SEOProStack_Health_CLI') || !SEOProStack_Health_CLI::auditing()) {
            update_option(self::OPTION, $result, false);
        }
        return $result;
    }

    /**
     * The last result for this host, if any.
     *
     * @return array|null
     */
    private static function stored() {
        $stored = get_option(self::OPTION);
        return is_array($stored) && isset($stored['host'], $stored['items'], $stored['checked']) && self::host() === $stored['host'] ? $stored : null;
    }

    /* ------------------------------------------------------------------
     * Site Health
     * ------------------------------------------------------------------ */

    /**
     * Add the test, run after the page loads: it connects to the site.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function tests($tests) {
        $tests['async'][self::TEST] = array(
            'test'              => self::TEST,
            'async_direct_test' => array(__CLASS__, 'test'),
            'label'             => __('HTTPS certificate and settings', 'seoprostack'),
        );
        return $tests;
    }

    /**
     * Answer the Site Health screen.
     */
    public static function ajax_test() {
        check_ajax_referer('health-check-site-status');
        if (current_user_can('view_site_health_checks')) {
            wp_send_json_success(self::test());
        }
        wp_send_json_error();
    }

    /**
     * The Site Health result.
     *
     * @return array
     */
    public static function test() {
        $result = self::run();
        $status = self::status($result['items']);
        $label  = self::label($result['items'], $status);
        $actions = '';
        $report  = self::ssl_labs_url();
        if ('' !== $report) {
            $actions .= '<p><a href="' . esc_url($report) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Full report and grade on SSL Labs', 'seoprostack') . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'seoprostack') . '</span></a></p>';
        }
        return array(
            'test'        => self::TEST,
            'status'      => $status,
            'label'       => $label,
            'badge'       => array('label' => __('Security', 'seoprostack'), 'color' => 'blue'),
            'description' => '<p>' . esc_html(self::about()) . '</p>' . self::items_html($result['items'], 'ul'),
            'actions'     => $actions,
        );
    }

    /**
     * Site Health's heading: the first finding with the overall status.
     *
     * @param array  $items  Findings.
     * @param string $status Overall status.
     * @return string
     */
    private static function label(array $items, $status) {
        $labels = array(
            'certificate:critical'    => __('The site’s certificate has a problem', 'seoprostack'),
            'certificate:recommended' => __('The site’s certificate expires soon', 'seoprostack'),
            'protocol:critical'       => __('The site does not use TLS 1.2 or 1.3', 'seoprostack'),
            'protocol:recommended'    => __('The site uses a weak cipher', 'seoprostack'),
            'old_tls:recommended'     => __('The site still accepts TLS 1.0 or 1.1', 'seoprostack'),
            'hsts:recommended'        => __('The site does not send a lasting HSTS header', 'seoprostack'),
        );
        if ('good' === $status) {
            return __('The site’s HTTPS is set up well', 'seoprostack');
        }
        foreach ($items as $item) {
            if (isset($labels[$item['part'] . ':' . $item['status']]) && $status === $item['status']) {
                return $labels[$item['part'] . ':' . $item['status']];
            }
        }
        return __('The site’s HTTPS could not be checked', 'seoprostack');
    }

    /**
     * What the check does.
     *
     * @return string
     */
    private static function about() {
        return __('SEO Pro Stack connects to the site’s address from the server, as a visitor does, and checks its certificate, TLS versions, cipher and HSTS header. Behind Cloudflare or another proxy, that is the proxy’s certificate and settings, which visitors get.', 'seoprostack');
    }

    /**
     * Findings as a list.
     *
     * @param array  $items Findings.
     * @param string $style ul for Site Health, plan for the Security tab.
     * @return string
     */
    private static function items_html(array $items, $style) {
        $words = array(
            'good'        => __('OK', 'seoprostack'),
            'recommended' => __('Improve', 'seoprostack'),
            'critical'    => __('Fix', 'seoprostack'),
            'unknown'     => __('Not checked', 'seoprostack'),
        );
        $html = 'plan' === $style ? '<ul class="sps-plan__items">' : '<ul>';
        foreach ($items as $item) {
            $word  = isset($words[$item['status']]) ? $words[$item['status']] : $item['status'];
            $html .= 'plan' === $style
                ? '<li class="sps-plan__item' . ('good' === $item['status'] ? '' : ' sps-plan__item--recommended') . '"><p><span class="sps-plan__state">' . esc_html($word) . '</span> ' . esc_html($item['text']) . '</p></li>'
                : '<li><strong>' . esc_html($word) . ':</strong> ' . esc_html($item['text']) . '</li>';
        }
        return $html . '</ul>';
    }

    /* ------------------------------------------------------------------
     * Security tab
     * ------------------------------------------------------------------ */

    /**
     * The section on the Security tab.
     *
     * @param string $tab Tab slug.
     */
    public static function render($tab) {
        if ('security' !== $tab || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="sps-examples sps-plan" id="sps-https-check">';
        echo '<h2 class="sps-section__title">' . esc_html__('HTTPS check', 'seoprostack') . '</h2>';
        if (!self::https_site()) {
            echo '<p class="sps-section__desc">' . esc_html__('The site address (Settings → General) starts with http, so there is no HTTPS to check. Tools → Site Health says how to move the site to HTTPS.', 'seoprostack') . '</p></div>';
            return;
        }
        echo '<p class="sps-section__desc">' . esc_html(self::about()) . '</p>';
        $stored = self::stored();
        if ($stored) {
            echo self::items_html($stored['items'], 'plan'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in items_html().
            /* translators: %s: time since, such as 3 hours */
            echo '<p>' . esc_html(sprintf(__('Checked %s ago.', 'seoprostack'), human_time_diff((int) $stored['checked']))) . '</p>';
        } else {
            echo '<p>' . esc_html__('Not checked yet. Site Health checks it too.', 'seoprostack') . '</p>';
        }
        echo '<div class="sps-examples__actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        wp_nonce_field(self::ACTION);
        echo '<button type="submit" class="button">' . esc_html__('Check now', 'seoprostack') . '</button></form>';
        $report = self::ssl_labs_url();
        if ('' !== $report) {
            echo '<a class="button" href="' . esc_url($report) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Full report and grade on SSL Labs', 'seoprostack') . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'seoprostack') . '</span></a>';
        }
        echo '</div></div>';
    }

    /**
     * Check now, then back to the section.
     */
    public static function handle() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot run this check.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        self::run();
        wp_safe_redirect(SEOProStack_Admin_Manager::tab_url('security') . '#sps-https-check');
        exit;
    }
}
