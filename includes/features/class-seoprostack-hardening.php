<?php
/**
 * Turn off unused remote access methods with core hooks, and block web
 * access to log and backup files.
 *
 * Imports the matching Hostinger Tools and Disable Bloat switches, without
 * changing their options. Jetpack and some mobile apps need XML-RPC.
 *
 * Log and backup files: a marked block at the top of the site's .htaccess
 * (and of a folder's own .htaccess when wp-content or uploads is outside
 * the site's folder) denies them; Nginx gets rules to copy. A Site Health
 * test, always on, names the files in the site's main folders that anyone
 * can download, checked once a day. Files are never deleted or moved: they
 * belong to the plugins that write them.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Hardening extends SEOProStack_Feature {

    const KEY = 'hardening';
    const ITEMS_KEY = 'hardening_items';

    /** .htaccess marker of the log and backup files block. */
    const FILES_MARKER = 'SEO Pro Stack log and backup files';

    /** Site option: what the block holds, where, and how writing went. */
    const FILES_STATE = 'seoprostack_hardening_files';

    /** Transient: files found that anyone can download (daily). */
    const EXPOSED = 'seoprostack_exposed_files';

    /** Site Health test. */
    const TEST = 'seoprostack-exposed-files';

    /** Most files requested per check. */
    const MAX_REQUESTS = 10;

    /** File names the block denies and the test looks for. */
    const FILES_PATTERN = '/^(error_log|php_errorlog|wp-config.+\.php)$|\.(log|sql|sql\.gz|bak)$/';

    /**
     * Settings; the card and both choices are off by default.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('Turn off unused remote access', 'seoprostack'),
                'description' => __('Turn off XML-RPC or application passwords when this site does not use them, and block web access to log and backup files. Jetpack and some mobile apps need XML-RPC. Application passwords let apps connect to this site without your login password. Plugins and PHP write log files that can hold visitors’ messages, server paths and database queries; Tools → Site Health names any that anyone can download.', 'seoprostack'),
                'replaces'    => array('hostinger' => 'Hostinger Tools') + SEOProStack_Disable_Bloat::PLUGINS,
                'reload'      => true,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Turn off', 'seoprostack'),
                'options'     => array(__CLASS__, 'item_options'),
                'reload'      => true,
            ),
        );
    }

    /**
     * Independent choices.
     *
     * @return array<string,string>
     */
    public static function item_options() {
        return array(
            'xmlrpc'        => __('Turn off XML-RPC', 'seoprostack'),
            'app_passwords' => __('Turn off application passwords', 'seoprostack'),
            'files'         => __('Block web access to log and backup files', 'seoprostack'),
        );
    }

    /**
     * Import only enabled switches from active plugins, filling unset keys.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $choices = SEOProStack_Disable_Bloat::choices(self::ITEMS_KEY);
        if (isset(self::active_plugins()['hostinger'])) {
            $theirs = get_option('hostinger_tools', array());
            if (is_array($theirs)) {
                if (!empty($theirs['disable_xml_rpc'])) {
                    $choices[] = 'xmlrpc';
                }
                if (!empty($theirs['disable_authentication_password'])) {
                    $choices[] = 'app_passwords';
                }
            }
        }
        if ($choices) {
            $options = self::import_setting($options, self::KEY, true);
            $options = self::import_setting($options, self::ITEMS_KEY, array_values(array_unique($choices)));
        }
        return $options;
    }

    /** Register hooks. */
    public static function boot() {
        // Replacement advice is needed even while the feature waits or is off.
        add_filter('seoprostack_replaced_plugin_extras', array(__CLASS__, 'hostinger_extras'), 10, 2);
        // The test is always on: it only gives advice. Site Health also runs
        // its tests from cron, outside wp-admin.
        add_filter('site_status_tests', array(__CLASS__, 'tests'));
        if (is_admin()) {
            // Also when switched off, to remove the block.
            add_action('admin_init', array(__CLASS__, 'files_maybe_sync'));
            add_action('wp_ajax_health-check-' . self::TEST, array(__CLASS__, 'ajax_test'));
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
        }
        if (!self::enabled()) {
            return;
        }
        $items = array_flip((array) SEOProStack_Settings::get(self::ITEMS_KEY));
        if (isset($items['app_passwords'])) {
            add_filter('wp_is_application_passwords_available', '__return_false');
        }
        if (isset($items['xmlrpc'])) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('wp_headers', array(__CLASS__, 'headers'));
            remove_action('wp_head', 'rsd_link');
            // Core defines this before loading WordPress for xmlrpc.php.
            // The filter alone does not block unauthenticated XML-RPC methods.
            if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
                status_header(403);
                nocache_headers();
                exit;
            }
        }
    }

    /* ------------------------------------------------------------------
     * Log and backup files: the block
     * ------------------------------------------------------------------ */

    /**
     * Whether the block is chosen. Only the switch counts: the plugins this
     * feature waits for do not block these files.
     *
     * @return bool
     */
    public static function files_on() {
        return self::switched_on() && in_array('files', (array) SEOProStack_Settings::get(self::ITEMS_KEY), true);
    }

    /**
     * Rules for the block. Only the real wp-config.php is left out of the
     * wp-config pattern (.+ needs at least one character before .php),
     * without a lookahead that some servers may not read.
     *
     * @return string[]
     */
    public static function files_rules() {
        return array(
            '<FilesMatch "^(error_log|php_errorlog|wp-config.+\.php)$|\.(log|sql|sql\.gz|bak)$">',
            'Require all denied',
            '</FilesMatch>',
        );
    }

    /**
     * Nginx configuration lines.
     *
     * @return string
     */
    public static function nginx_rules() {
        return implode("\n", array(
            '# In the server { } block, before other locations (such as the one for .php):',
            'location ~ (/(error_log|php_errorlog|wp-config[^/]+\.php)|\.(log|sql|sql\.gz|bak))$ {',
            '    deny all;',
            '}',
        ));
    }

    /**
     * The site's folder, wp-content and the uploads folder, each once,
     * with a trailing slash. On multisite, the main site's uploads folder,
     * which holds the other sites' folders.
     *
     * @return string[]
     */
    private static function folders() {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $folders = array(get_home_path(), ABSPATH, WP_CONTENT_DIR);
        $uploads = wp_get_upload_dir();
        if (empty($uploads['error']) && !empty($uploads['basedir'])) {
            $folders[] = $uploads['basedir'];
        }
        $unique = array();
        foreach ($folders as $folder) {
            $unique[] = trailingslashit(wp_normalize_path($folder));
        }
        return array_values(array_unique($unique));
    }

    /**
     * The .htaccess files that need the block: rules in a folder's
     * .htaccess cover the folders below it, so only folders outside the
     * others get their own.
     *
     * @return string[]
     */
    private static function files_targets() {
        $folders = self::folders();
        $targets = array();
        foreach ($folders as $folder) {
            foreach ($folders as $other) {
                if ($other !== $folder && 0 === strpos($folder, $other)) {
                    continue 2;
                }
            }
            $targets[] = $folder . '.htaccess';
        }
        return $targets;
    }

    /**
     * Keep the block in step with the choice, from the main site's admin
     * screens (a network shares its folders). Reads one site option when
     * nothing changed; retries while a file cannot be written.
     */
    public static function files_maybe_sync() {
        if (is_multisite() && !is_main_site()) {
            return;
        }
        global $is_apache, $is_nginx;
        $on      = self::files_on();
        $targets = $on ? self::files_targets() : array();
        // Servers that do not read .htaccess files get no block. The server
        // is in the hash, so a site moved to another server is synced again.
        $rules = ($on && !empty($is_apache)) ? self::files_rules() : array();
        $want  = $on ? md5(implode("\n", self::files_rules()) . "\n" . implode("\n", $targets) . "\n" . ($rules ? 'htaccess' : 'none')) : ''; // NOSONAR: a fingerprint to notice changes, not security; stored, so it stays md5.
        $state = (array) get_site_option(self::FILES_STATE, array());
        $had   = isset($state['hash']) ? $state['hash'] : '';
        if ($had === $want && (!isset($state['status']) || 'unwritable' !== $state['status'])) {
            return;
        }
        $old    = isset($state['targets']) && is_array($state['targets']) ? $state['targets'] : array();
        $status = 'written';
        foreach (array_unique(array_merge($old, $targets)) as $file) {
            if (!self::files_write($file, in_array($file, $targets, true) ? $rules : array())) {
                $status = 'unwritable';
            }
        }
        if (!$on) {
            if ('written' === $status) {
                delete_site_option(self::FILES_STATE);
            }
        } else {
            if ('written' === $status && !$rules) {
                $status = !empty($is_nginx) ? 'nginx' : 'other';
            }
            update_site_option(self::FILES_STATE, array('hash' => $want, 'targets' => $targets, 'status' => $status));
        }
        // What can be downloaded has changed.
        delete_transient(self::EXPOSED);
    }

    /**
     * Write the block at the top of an .htaccess file (where the issue's
     * fix went, ahead of other plugins' blocks), or remove it. A file left
     * empty by the removal is deleted: it was made for the block.
     *
     * @param string   $file  .htaccess path.
     * @param string[] $rules Rules; empty removes the block.
     * @return bool Whether the file is as asked now.
     */
    private static function files_write($file, array $rules) {
        $exists = is_file($file);
        if (!$rules && !$exists) {
            return true;
        }
        $contents = $exists ? (string) file_get_contents($file) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        $quoted   = preg_quote(self::FILES_MARKER, '/');
        $cleaned  = preg_replace('/# BEGIN ' . $quoted . '\r?\n.*?# END ' . $quoted . '[^\n]*(\n|$)\s*/s', '', $contents);
        if (null === $cleaned) {
            return false;
        }
        $block = $rules ? '# BEGIN ' . self::FILES_MARKER . "\n" . implode("\n", $rules) . "\n# END " . self::FILES_MARKER . "\n\n" : '';
        $new   = $block . ltrim($cleaned, "\r\n");
        if ($new === $contents) {
            return true;
        }
        if (!($exists ? wp_is_writable($file) : wp_is_writable(dirname($file)))) {
            return false;
        }
        if ('' === trim($new)) {
            // wp_delete_file() returns nothing before WordPress 6.6, so check.
            wp_delete_file($file);
            return !file_exists($file);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- as insert_with_markers(), which can only append a new block.
        return false !== file_put_contents($file, $new, LOCK_EX);
    }

    /**
     * How the block went, in the settings panel.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel($key, $field = array()) {
        if (self::KEY !== $key || !self::files_on()) {
            return;
        }
        if (is_multisite() && !is_main_site()) {
            echo '<div class="sps-panel-note"><p>' . esc_html__('Log and backup files are blocked for the whole network from the main site’s settings, which share its .htaccess files.', 'seoprostack') . '</p></div>';
            return;
        }
        $state  = (array) get_site_option(self::FILES_STATE, array());
        $status = isset($state['status']) ? $state['status'] : '';
        $files  = isset($state['targets']) && is_array($state['targets']) ? $state['targets'] : array();
        echo '<div class="sps-panel-note">';
        if ('nginx' === $status) {
            echo '<p>' . esc_html__('Nginx does not read .htaccess files. To block log and backup files, add these lines to the site’s Nginx configuration and reload Nginx:', 'seoprostack') . '</p>';
            echo '<pre class="sps-code">' . esc_html(self::nginx_rules()) . '</pre>';
        } elseif ('other' === $status) {
            echo '<p>' . esc_html__('This server does not read .htaccess files, so log and backup files are not blocked. Ask your host to deny web access to .log, .sql, .sql.gz and .bak files, error_log, php_errorlog and copies of wp-config.php.', 'seoprostack') . '</p>';
        } elseif ('written' === $status) {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: .htaccess file paths */
                __('Log and backup files are blocked by rules in %s.', 'seoprostack'),
                implode(', ', $files)
            )) . '</p>';
        } else {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: .htaccess file paths */
                __('These files could not be changed: %s. To block log and backup files, add these lines to the top of each:', 'seoprostack'),
                implode(', ', $files)
            )) . '</p>';
            echo '<pre class="sps-code">' . esc_html('# BEGIN ' . self::FILES_MARKER . "\n" . implode("\n", self::files_rules()) . "\n# END " . self::FILES_MARKER) . '</pre>';
        }
        echo '</div>';
    }

    /**
     * Plugin deactivated: remove the block, unless only a network's subsite
     * deactivated it (the network shares the files).
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        delete_transient(self::EXPOSED);
        if (is_multisite() && !$network_wide && !is_main_site()) {
            return;
        }
        $state   = (array) get_site_option(self::FILES_STATE, array());
        $targets = isset($state['targets']) && is_array($state['targets']) ? $state['targets'] : array();
        $done    = true;
        foreach (array_unique(array_merge($targets, self::files_targets())) as $file) {
            $done = self::files_write($file, array()) && $done;
        }
        if ($done) {
            delete_site_option(self::FILES_STATE);
        }
    }

    /* ------------------------------------------------------------------
     * Log and backup files: the Site Health test
     * ------------------------------------------------------------------ */

    /**
     * Add the test.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function tests($tests) {
        $tests['async'][self::TEST] = array(
            'label'             => __('Log and backup files', 'seoprostack'),
            'test'              => self::TEST,
            'async_direct_test' => array(__CLASS__, 'test_files'),
        );
        return $tests;
    }

    /**
     * Run the test for the Site Health screen.
     */
    public static function ajax_test() {
        check_ajax_referer('health-check-site-status');
        if (!current_user_can('view_site_health_checks')) {
            wp_send_json_error();
        }
        wp_send_json_success(self::test_files());
    }

    /**
     * Files in the top level of the site's folders whose names match, with
     * something in them, largest first: path => URL.
     *
     * @return array<string,array{url:string,size:int}>
     */
    private static function candidates() {
        $urls = array(
            wp_normalize_path(ABSPATH)        => site_url('/'),
            wp_normalize_path(WP_CONTENT_DIR) => content_url('/'),
        );
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $urls[wp_normalize_path(get_home_path())] = home_url('/');
        $uploads = wp_get_upload_dir();
        if (empty($uploads['error']) && !empty($uploads['basedir'])) {
            $urls[wp_normalize_path($uploads['basedir'])] = $uploads['baseurl'];
        }
        $found = array();
        foreach ($urls as $folder => $url) {
            $folder = trailingslashit($folder);
            $names  = is_dir($folder) && is_readable($folder) ? scandir($folder) : false;
            if (!is_array($names)) {
                continue;
            }
            foreach ($names as $name) {
                // Requesting a PHP file runs it: copies of wp-config.php
                // are blocked, but not requested.
                if (!preg_match(self::FILES_PATTERN, $name) || '.php' === substr($name, -4)) {
                    continue;
                }
                $path = $folder . $name;
                $size = is_file($path) ? (int) filesize($path) : 0;
                if ($size > 0) {
                    $found[$path] = array('url' => trailingslashit($url) . rawurlencode($name), 'size' => $size);
                }
            }
        }
        uasort($found, function ($a, $b) {
            return $b['size'] - $a['size'];
        });
        return $found;
    }

    /**
     * Files anyone can download, checked at most once a day with one HEAD
     * request each (at most MAX_REQUESTS). Never reads their contents.
     *
     * @return array{files:array,checked:int,failed:int,more:int}
     */
    public static function exposed() {
        $cached = get_transient(self::EXPOSED);
        if (is_array($cached) && isset($cached['files']) && is_array($cached['files'])) {
            return array(
                'files'   => $cached['files'],
                'checked' => isset($cached['checked']) ? (int) $cached['checked'] : 0,
                'failed'  => isset($cached['failed']) ? (int) $cached['failed'] : 0,
                'more'    => isset($cached['more']) ? (int) $cached['more'] : 0,
            );
        }
        $result     = array('files' => array(), 'checked' => 0, 'failed' => 0, 'more' => 0);
        $candidates = self::candidates();
        $root       = wp_normalize_path(ABSPATH);
        foreach ($candidates as $path => $file) {
            if ($result['checked'] >= self::MAX_REQUESTS) {
                $result['more']++;
                continue;
            }
            $result['checked']++;
            $response = wp_remote_head($file['url'], array(
                'timeout'     => 5, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- Site Health's async test and its weekly cron check only, once a day.
                'redirection' => 0,
                // Core's filter for requests to the site itself, as its loopback test uses.
                'sslverify'   => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
            ));
            if (is_wp_error($response)) {
                $result['failed']++;
                continue;
            }
            if (200 === (int) wp_remote_retrieve_response_code($response)) {
                $name              = 0 === strpos($path, $root) ? substr($path, strlen($root)) : $path;
                $result['files'][] = array('name' => $name, 'size' => $file['size']);
            }
        }
        set_transient(self::EXPOSED, $result, DAY_IN_SECONDS);
        return $result;
    }

    /**
     * Log and backup files test.
     *
     * @return array
     */
    public static function test_files() {
        $exposed = self::exposed();
        $result  = array(
            'label'       => __('No log or backup files can be downloaded from this site’s main folders', 'seoprostack'),
            'status'      => 'good',
            'badge'       => array(
                'label' => __('Security', 'seoprostack'),
                'color' => 'blue',
            ),
            'description' => '<p>' . esc_html__('Plugins and PHP write log files, and backups leave database dumps, that can hold visitors’ messages, server paths and database queries. SEO Pro Stack looks for them in the site’s folder, wp-content and the uploads folder once a day, and asks for each one as a visitor would.', 'seoprostack') . '</p>',
            'actions'     => '',
            'test'        => 'seoprostack_exposed_files',
        );
        if ($exposed['files']) {
            $result['status'] = 'recommended';
            $result['label']  = _n('A log or backup file can be downloaded by anyone', 'Log or backup files can be downloaded by anyone', count($exposed['files']), 'seoprostack');
            $items            = '';
            foreach ($exposed['files'] as $file) {
                $items .= '<li><code>' . esc_html($file['name']) . '</code> (' . esc_html((string) size_format($file['size'])) . ')</li>';
            }
            $result['description'] .= '<ul>' . $items . '</ul>';
            $result['actions']      = '<p>' . sprintf(
                /* translators: %s: link to the settings */
                esc_html__('Turn on %s in SEO Pro Stack’s Turn off unused remote access to block them. The files are not deleted: if a plugin wrote one, you can also turn off its debug logging and delete the file.', 'seoprostack'),
                '<a href="' . esc_url(admin_url('options-general.php?page=seoprostack&tab=admin')) . '">' . esc_html__('Block web access to log and backup files', 'seoprostack') . '</a>'
            ) . '</p>';
        } elseif ($exposed['checked'] && $exposed['failed'] === $exposed['checked']) {
            $result['label'] = __('Log and backup files could not be checked', 'seoprostack');
            $result['description'] .= '<p>' . esc_html__('This site could not reach its own files, so whether they can be downloaded is unknown.', 'seoprostack') . '</p>';
        }
        if ($exposed['more']) {
            $result['description'] .= '<p>' . esc_html(sprintf(
                /* translators: %d: number of files */
                _n('%d smaller file was not checked today.', '%d smaller files were not checked today.', $exposed['more'], 'seoprostack'),
                $exposed['more']
            )) . '</p>';
        }
        return $result;
    }

    /**
     * Drop only the pingback header.
     *
     * @param array $headers Response headers.
     * @return array
     */
    public static function headers($headers) {
        foreach ($headers as $name => $value) {
            if (0 === strcasecmp($name, 'X-Pingback')) {
                unset($headers[$name]);
            }
        }
        return $headers;
    }

    /**
     * Do not suggest removing Hostinger's other tools or uncovered switches.
     * The one list for Hostinger Tools: SEOProStack_Maintenance covers its
     * maintenance mode, so that is not named here.
     *
     * @param string[] $extras Other functions still needed.
     * @param string   $slug   Plugin folder.
     * @return string[]
     */
    public static function hostinger_extras($extras, $slug) {
        if ('hostinger' !== $slug) {
            return $extras;
        }
        $theirs = get_option('hostinger_tools', array());
        if (!is_array($theirs)) {
            return $extras;
        }
        $other = array(
            'force_https'      => __('Redirect to HTTPS', 'seoprostack'),
            'force_www'        => __('Redirect to www', 'seoprostack'),
            'enable_llms_txt'  => __('Generate llms.txt', 'seoprostack'),
            'optin_mcp'        => __('Hostinger MCP connection', 'seoprostack'),
        );
        foreach ($other as $option => $name) {
            if (!empty($theirs[$option])) {
                $extras[] = $name;
            }
        }
        $items  = (array) SEOProStack_Settings::get(self::ITEMS_KEY);
        foreach (array('disable_xml_rpc' => 'xmlrpc', 'disable_authentication_password' => 'app_passwords') as $option => $choice) {
            if (!empty($theirs[$option])
                && !(self::switched_on() && in_array($choice, $items, true))) {
                $names    = self::item_options();
                $extras[] = $names[$choice];
            }
        }
        return array_values(array_unique($extras));
    }
}
