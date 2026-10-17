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
 * Directory listing: a second Site Health test, always on, asks for this
 * month's uploads folder once a day and says when the server lists its
 * files. Advice only: an Options line in .htaccess can stop a whole Apache
 * site loading where the host does not allow it.
 *
 * PHP in uploads: a marked block in the uploads folder's .htaccess denies
 * PHP files there, so a script that got in through an upload cannot run;
 * Nginx gets a rule to copy. Usernames: user lists in the REST API (for
 * people who cannot edit posts, except their own /users/me), numeric
 * ?author= addresses for visitors and core's users sitemap are turned off.
 *
 * Also imports Really Simple Security's matching switches, without
 * replacing it (SEOProStack_Security_Headers does).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
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

    /** Transient: whether the uploads folder lists its files (daily). */
    const LISTING = 'seoprostack_directory_listing';

    /** Site Health test of directory listing. */
    const LISTING_TEST = 'seoprostack-directory-listing';

    /** Most of the folder's page read, in bytes: enough for its title. */
    const LISTING_BYTES = 8192;

    /** File names the block denies and the test looks for. */
    const FILES_PATTERN = '/^(error_log|php_errorlog|wp-config.+\.php)$|\.(log|sql|sql\.gz|bak)$/';

    /** .htaccess marker of the PHP in uploads block. */
    const UPLOADS_MARKER = 'SEO Pro Stack PHP in uploads';

    /** Site option: what the PHP in uploads block holds, where, and how writing went. */
    const UPLOADS_STATE = 'seoprostack_hardening_uploads';

    /**
     * Choices ticked by default: safe on any site. XML-RPC and application
     * passwords stay unticked, as Jetpack, mobile apps and integrations use
     * them.
     */
    const DEFAULT_ITEMS = array('files', 'uploads_php', 'usernames');

    /**
     * Settings; on by default with DEFAULT_ITEMS.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request (#806), with DEFAULT_ITEMS.
                'default'     => true,
                'tab'         => 'security',
                'label'       => __('Turn off unused remote access', 'seoprostack'),
                'description' => __('Turn off XML-RPC or application passwords when this site does not use them, block web access to log and backup files and PHP files in the uploads folder, and hide usernames from visitors. Jetpack and some mobile apps need XML-RPC. Application passwords let apps connect to this site without your login password. Plugins and PHP write log files that can hold visitors’ messages, server paths and database queries; Tools → Site Health names any that anyone can download.', 'seoprostack'),
                'replaces'    => array('hostinger' => 'Hostinger Tools') + SEOProStack_Disable_Bloat::PLUGINS,
                'reload'      => true,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => self::DEFAULT_ITEMS,
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
            'uploads_php'   => __('Block PHP files in the uploads folder', 'seoprostack'),
            'usernames'     => __('Hide usernames from visitors (REST API user lists, ?author= addresses and the users sitemap)', 'seoprostack'),
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
        $choices = array_merge(SEOProStack_Disable_Bloat::choices(self::ITEMS_KEY), SEOProStack_Security_Headers::choices(self::ITEMS_KEY));
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
            // Added to the default choices, which a new install also gets.
            $options = self::import_setting($options, self::KEY, true);
            $options = self::import_setting($options, self::ITEMS_KEY, array_values(array_unique(array_merge(self::DEFAULT_ITEMS, $choices))));
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
            add_action('wp_ajax_health-check-' . self::LISTING_TEST, array(__CLASS__, 'ajax_listing_test'));
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
            if (is_multisite()) {
                add_action('seoprostack_network_setting_panel', array(__CLASS__, 'network_panel'));
            }
        }
        if (!self::enabled()) {
            return;
        }
        $items = array_flip((array) SEOProStack_Settings::get(self::ITEMS_KEY));
        if (isset($items['app_passwords'])) {
            add_filter('wp_is_application_passwords_available', '__return_false');
        }
        if (isset($items['usernames'])) {
            add_filter('rest_request_before_callbacks', array(__CLASS__, 'rest_users'), 10, 3);
            // Before redirect_canonical(), which sends ?author=1 to the author's address.
            add_action('template_redirect', array(__CLASS__, 'author_query'), 1);
            add_filter('wp_sitemaps_add_provider', array(__CLASS__, 'users_sitemap'), 10, 2);
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
     * screens (a network shares its folders). Checks the saved state and
     * complete written blocks; retries while a file cannot be written.
     */
    public static function files_maybe_sync() {
        if (is_multisite() && !is_main_site()) {
            return;
        }
        $on = self::files_on();
        if (self::block_sync(self::FILES_MARKER, self::FILES_STATE, $on ? self::files_targets() : null, self::files_rules())) {
            // What can be downloaded has changed.
            delete_transient(self::EXPOSED);
        }
        $on = self::uploads_on();
        self::block_sync(self::UPLOADS_MARKER, self::UPLOADS_STATE, $on ? self::uploads_targets() : null, self::uploads_rules());
    }

    /**
     * Keep one marked block in step: write it to the target .htaccess files
     * and remove it from those it left. Checks complete blocks even when
     * the saved hash matches; retries while a file cannot be written.
     *
     * @param string        $marker  Block marker.
     * @param string        $state   Site option keeping hash, targets and status.
     * @param string[]|null $targets Files that need it; null when the choice is off.
     * @param string[]      $rules   Rules of the block.
     * @return bool Whether anything was synced.
     */
    private static function block_sync($marker, $state, $targets, array $rules) {
        global $is_apache;
        $on      = null !== $targets;
        $targets = $on ? $targets : array();
        // Servers that do not read .htaccess files get no block. The server
        // is in the hash, so a site moved to another server is synced again.
        $write = ($on && !empty($is_apache)) ? $rules : array();
        $want  = $on ? self::block_hash($rules, $targets, $write) : '';
        $saved = (array) get_site_option($state, array());
        if (self::block_in_step($saved, $want, $targets, $marker, $write)) {
            return false;
        }
        $old    = isset($saved['targets']) && is_array($saved['targets']) ? $saved['targets'] : array();
        $status = self::block_write_all(array_unique(array_merge($old, $targets)), $targets, $write, $marker);
        self::block_save($state, $on, array('hash' => $want, 'targets' => $targets, 'status' => $status), $write);
        return true;
    }

    /**
     * Fingerprint of a block's rules, targets and whether it is written.
     *
     * @param string[] $rules   Rules of the block.
     * @param string[] $targets Files that need it.
     * @param string[] $write   Rules to write; empty when the server reads no .htaccess.
     * @return string
     */
    private static function block_hash(array $rules, array $targets, array $write) {
        return md5(implode("\n", $rules) . "\n" . implode("\n", $targets) . "\n" . ($write ? 'htaccess' : 'none')); // NOSONAR: a fingerprint to notice changes, not security; stored, so it stays md5.
    }

    /**
     * Whether the saved state matches and written blocks are still present.
     *
     * @param array    $saved   Saved state.
     * @param string   $want    Wanted hash; empty when the choice is off.
     * @param string[] $targets Files that need the block.
     * @param string   $marker  Block marker.
     * @param string[] $write   Rules that must be present; empty for no .htaccess.
     * @return bool
     */
    private static function block_in_step(array $saved, $want, array $targets, $marker, array $write) {
        $had = isset($saved['hash']) ? $saved['hash'] : '';
        if ($had !== $want || (isset($saved['status']) && 'unwritable' === $saved['status'])) {
            return false;
        }
        if ($write && isset($saved['status']) && 'written' === $saved['status']) {
            $block = '# BEGIN ' . $marker . "\n" . implode("\n", $write) . "\n# END " . $marker . "\n";
            foreach ($targets as $file) {
                if (!is_readable($file)) {
                    return false;
                }
                $contents = file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local .htaccess file.
                if (false === $contents || false === strpos(str_replace("\r\n", "\n", $contents) . "\n", $block)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Write the block to the files that need it and remove it from the rest.
     *
     * @param string[] $files   Files that had or need the block.
     * @param string[] $targets Files that need it.
     * @param string[] $write   Rules to write.
     * @param string   $marker  Block marker.
     * @return string 'written', or 'unwritable' when a file could not be changed.
     */
    private static function block_write_all(array $files, array $targets, array $write, $marker) {
        $status = 'written';
        foreach ($files as $file) {
            if (!self::files_write($file, in_array($file, $targets, true) ? $write : array(), $marker)) {
                $status = 'unwritable';
            }
        }
        return $status;
    }

    /**
     * Save how the sync went: forgotten once a block that is off is gone;
     * otherwise kept, with the server's kind when nothing could be written.
     *
     * @param string   $state Site option name.
     * @param bool     $on    Whether the choice is on.
     * @param array    $saved Hash, targets and status.
     * @param string[] $write Rules written.
     */
    private static function block_save($state, $on, array $saved, array $write) {
        global $is_nginx;
        if (!$on) {
            if ('written' === $saved['status']) {
                delete_site_option($state);
            }
            return;
        }
        if ('written' === $saved['status'] && !$write) {
            $saved['status'] = !empty($is_nginx) ? 'nginx' : 'other';
        }
        update_site_option($state, $saved);
    }

    /**
     * Whether PHP files in uploads are blocked (the switch only, as files_on()).
     *
     * @return bool
     */
    public static function uploads_on() {
        return self::switched_on() && in_array('uploads_php', (array) SEOProStack_Settings::get(self::ITEMS_KEY), true);
    }

    /**
     * Rules of the PHP in uploads block: every PHP extension a server may run.
     *
     * @return string[]
     */
    public static function uploads_rules() {
        return array(
            '<FilesMatch "\.(php[0-9]?|pht|phtml|phar)$">',
            'Require all denied',
            '</FilesMatch>',
        );
    }

    /**
     * The uploads folder's .htaccess (on multisite the main site's, which
     * holds the other sites' folders).
     *
     * @return string[]
     */
    private static function uploads_targets() {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir']) || !is_dir($uploads['basedir'])) {
            return array();
        }
        return array(trailingslashit(wp_normalize_path($uploads['basedir'])) . '.htaccess');
    }

    /**
     * Nginx lines for PHP in uploads.
     *
     * @return string
     */
    public static function uploads_nginx_rules() {
        $uploads = wp_get_upload_dir();
        $path    = !empty($uploads['baseurl']) ? (string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH) : '/wp-content/uploads';
        return implode("\n", array(
            '# In the server { } block, before the location for .php:',
            'location ~* ^' . untrailingslashit($path) . '/.*\.(php[0-9]?|pht|phtml|phar)$ {',
            '    deny all;',
            '}',
        ));
    }

    /**
     * Write the block at the top of an .htaccess file (where the issue's
     * fix went, ahead of other plugins' blocks), or remove it. A file left
     * empty by the removal is deleted: it was made for the block.
     *
     * @param string   $file   .htaccess path.
     * @param string[] $rules  Rules; empty removes the block.
     * @param string   $marker Block marker.
     * @return bool Whether the file is as asked now.
     */
    private static function files_write($file, array $rules, $marker = self::FILES_MARKER) {
        $exists = is_file($file);
        if (!$rules && !$exists) {
            return true;
        }
        $contents = $exists ? (string) file_get_contents($file) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        $new      = self::block_contents($contents, $rules, $marker);
        if (null === $new) {
            return false;
        }
        if ($new === $contents) {
            return true;
        }
        return self::files_put($file, $new, $exists);
    }

    /**
     * A file's contents with the block at the top, or without it.
     *
     * @param string   $contents Current contents.
     * @param string[] $rules    Rules; empty removes the block.
     * @param string   $marker   Block marker.
     * @return string|null Null when the old block could not be matched.
     */
    private static function block_contents($contents, array $rules, $marker) {
        $quoted  = preg_quote($marker, '/');
        $cleaned = preg_replace('/# BEGIN ' . $quoted . '\r?\n.*?# END ' . $quoted . '[^\n]*(\n|$)\s*/s', '', $contents);
        if (null === $cleaned) {
            return null;
        }
        $block = $rules ? '# BEGIN ' . $marker . "\n" . implode("\n", $rules) . "\n# END " . $marker . "\n\n" : '';
        return $block . ltrim($cleaned, "\r\n");
    }

    /**
     * Save new .htaccess contents, deleting a file left empty.
     *
     * @param string $file   .htaccess path.
     * @param string $new    New contents.
     * @param bool   $exists Whether the file exists.
     * @return bool Whether the file is as asked now.
     */
    private static function files_put($file, $new, $exists) {
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
        if (self::KEY !== $key) {
            return;
        }
        if (is_multisite() && !is_main_site()) {
            self::subsite_panel();
            return;
        }
        if (self::uploads_on()) {
            self::uploads_panel();
        }
        if (self::files_on()) {
            self::files_panel();
        }
    }

    /**
     * On a network's other sites: the blocks are set from the main site.
     */
    private static function subsite_panel() {
        if (self::files_on() || self::uploads_on()) {
            echo '<div class="sps-panel-note"><p>' . esc_html__('Log and backup files and PHP files in uploads are blocked for the whole network from the main site’s settings, which share its .htaccess files.', 'seoprostack') . '</p></div>';
        }
    }

    /**
     * How the log and backup files block went, in the settings panel.
     */
    private static function files_panel() {
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
     * How the PHP in uploads block went, in the settings panel.
     */
    private static function uploads_panel() {
        $state  = (array) get_site_option(self::UPLOADS_STATE, array());
        $status = isset($state['status']) ? $state['status'] : '';
        $files  = isset($state['targets']) && is_array($state['targets']) ? $state['targets'] : array();
        echo '<div class="sps-panel-note">';
        if ('nginx' === $status) {
            echo '<p>' . esc_html__('Nginx does not read .htaccess files. To block PHP files in the uploads folder, add these lines to the site’s Nginx configuration and reload Nginx:', 'seoprostack') . '</p>';
            echo '<pre class="sps-code">' . esc_html(self::uploads_nginx_rules()) . '</pre>';
        } elseif ('other' === $status) {
            echo '<p>' . esc_html__('This server does not read .htaccess files, so PHP files in the uploads folder are not blocked. Ask your host to stop PHP files running in the uploads folder.', 'seoprostack') . '</p>';
        } elseif ('written' === $status && $files) {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: .htaccess file path */
                __('PHP files in the uploads folder are blocked by rules in %s.', 'seoprostack'),
                implode(', ', $files)
            )) . '</p>';
        } else {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: .htaccess file path */
                __('This file could not be changed: %s. To block PHP files in the uploads folder, add these lines to the top of it:', 'seoprostack'),
                $files ? implode(', ', $files) : __('the uploads folder’s .htaccess', 'seoprostack')
            )) . '</p>';
            echo '<pre class="sps-code">' . esc_html('# BEGIN ' . self::UPLOADS_MARKER . "\n" . implode("\n", self::uploads_rules()) . "\n# END " . self::UPLOADS_MARKER) . '</pre>';
        }
        echo '</div>';
    }

    /**
     * On the network screen (multisite): log and backup files are blocked
     * for every site from one place.
     *
     * @param string $key Setting key.
     */
    public static function network_panel($key) {
        if (self::KEY !== $key) {
            return;
        }
        echo '<div class="sps-panel-note"><p>' . esc_html__('The sites share the main site’s .htaccess files, so the main site’s settings block log and backup files and PHP files in uploads for the whole network.', 'seoprostack') . '</p></div>';
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
        self::block_remove(self::FILES_MARKER, self::FILES_STATE, self::files_targets());
        self::block_remove(self::UPLOADS_MARKER, self::UPLOADS_STATE, self::uploads_targets());
    }

    /**
     * Remove a marked block from the files it was written to and those it
     * would go to now; forget its state once every file is clean.
     *
     * @param string   $marker  Block marker.
     * @param string   $state   Site option keeping its targets.
     * @param string[] $targets Files it would go to now.
     */
    private static function block_remove($marker, $state, array $targets) {
        $saved = (array) get_site_option($state, array());
        $old   = isset($saved['targets']) && is_array($saved['targets']) ? $saved['targets'] : array();
        $done  = true;
        foreach (array_unique(array_merge($old, $targets)) as $file) {
            $done = self::files_write($file, array(), $marker) && $done;
        }
        if ($done) {
            delete_site_option($state);
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
        $tests['async'][self::LISTING_TEST] = array(
            'label'             => __('Directory listing', 'seoprostack'),
            'test'              => self::LISTING_TEST,
            'async_direct_test' => array(__CLASS__, 'test_listing'),
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
     * @return array{files:array,checked:int,failed:int,more:int,recorded:bool}
     */
    public static function exposed() {
        $audit  = class_exists('SEOProStack_Health_CLI', false) && SEOProStack_Health_CLI::auditing();
        $cached = $audit ? SEOProStack_Health_CLI::stored_transient(self::EXPOSED) : get_transient(self::EXPOSED);
        if (is_array($cached) && isset($cached['files']) && is_array($cached['files'])) {
            return array(
                'files'   => $cached['files'],
                'checked' => isset($cached['checked']) ? (int) $cached['checked'] : 0,
                'failed'  => isset($cached['failed']) ? (int) $cached['failed'] : 0,
                'more'    => isset($cached['more']) ? (int) $cached['more'] : 0,
                'recorded' => true,
            );
        }
        $result     = array('files' => array(), 'checked' => 0, 'failed' => 0, 'more' => 0, 'recorded' => !$audit);
        if ($audit) {
            return $result; // No loopback request: it could cause writes on the web request.
        }
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
        if (!$exposed['recorded']) {
            $result['status'] = 'not_run';
            $result['label'] = __('No web-request file probe is recorded yet', 'seoprostack');
            $result['description'] .= '<p>' . esc_html__('Open Tools → Site Health once to record this web probe.', 'seoprostack') . '</p>';
        }
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
                '<a href="' . esc_url(admin_url('options-general.php?page=seoprostack&tab=security')) . '">' . esc_html__('Block web access to log and backup files', 'seoprostack') . '</a>'
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

    /* ------------------------------------------------------------------
     * Directory listing: the Site Health test
     * ------------------------------------------------------------------ */

    /**
     * Run the directory listing test for the Site Health screen.
     */
    public static function ajax_listing_test() {
        check_ajax_referer('health-check-site-status');
        if (!current_user_can('view_site_health_checks')) {
            wp_send_json_error();
        }
        wp_send_json_success(self::test_listing());
    }

    /**
     * Folder to ask for: this month's uploads folder, which WordPress gives
     * no index file, else the uploads folder, so the index.php files
     * WordPress ships in its top folders cannot hide the answer. Never
     * creates a folder.
     *
     * @return string URL, or '' when both have an index file or none exists.
     */
    private static function listing_url() {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error'])) {
            return '';
        }
        $folders = array(
            array($uploads['path'], $uploads['url']),
            array($uploads['basedir'], $uploads['baseurl']),
        );
        foreach ($folders as $folder) {
            list($path, $url) = $folder;
            if ('' === (string) $path || '' === (string) $url || !is_dir($path)) {
                continue;
            }
            foreach (array('index.php', 'index.html', 'index.htm') as $index) {
                if (file_exists(trailingslashit($path) . $index)) {
                    continue 2;
                }
            }
            return trailingslashit($url);
        }
        return '';
    }

    /**
     * Whether the uploads folder lists its files, checked at most once a day
     * with one GET that reads only the start of the page.
     *
     * @return array{state:string,url:string} State on, off, failed or skipped.
     */
    public static function listing() {
        $audit  = class_exists('SEOProStack_Health_CLI', false) && SEOProStack_Health_CLI::auditing();
        $cached = $audit ? SEOProStack_Health_CLI::stored_transient(self::LISTING) : get_transient(self::LISTING);
        if (is_array($cached) && isset($cached['state'], $cached['url'])) {
            return array('state' => (string) $cached['state'], 'url' => (string) $cached['url']);
        }
        if ($audit) {
            return array('state' => 'unrecorded', 'url' => '');
        }
        $url    = self::listing_url();
        $result = array('state' => 'skipped', 'url' => $url);
        if ('' !== $url) {
            $response = wp_remote_get($url, array(
                'timeout'             => 5, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout -- Site Health's async test and its weekly cron check only, once a day.
                'redirection'         => 0,
                'limit_response_size' => self::LISTING_BYTES,
                // Core's filter for requests to the site itself, as its loopback test uses.
                'sslverify'           => apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.
            ));
            if (is_wp_error($response)) {
                $result['state'] = 'failed';
            } else {
                // Apache, LiteSpeed and nginx's autoindex all title the page so.
                $code            = (int) wp_remote_retrieve_response_code($response);
                $listed          = $code >= 200 && $code < 300
                    && false !== stripos((string) wp_remote_retrieve_body($response), '<title>Index of');
                $result['state'] = $listed ? 'on' : 'off';
            }
        }
        set_transient(self::LISTING, $result, DAY_IN_SECONDS);
        return $result;
    }

    /**
     * Directory listing test.
     *
     * @return array
     */
    public static function test_listing() {
        $listing = self::listing();
        $result  = array(
            'label'       => __('Folders do not list their files', 'seoprostack'),
            'status'      => 'good',
            'badge'       => array(
                'label' => __('Security', 'seoprostack'),
                'color' => 'blue',
            ),
            'description' => '<p>' . esc_html__('When directory listing is on, anyone who opens a folder without an index file sees every file in it, including private uploads such as invoices and form attachments. Empty index.php files only cover the folder they are in. SEO Pro Stack asks for this month’s uploads folder once a day, as a visitor would.', 'seoprostack') . '</p>',
            'actions'     => '',
            'test'        => 'seoprostack_directory_listing',
        );
        if ('on' === $listing['state']) {
            $result['status']       = 'recommended';
            $result['label']        = __('Anyone can list the files in your uploads folder', 'seoprostack');
            $result['description'] .= '<p><a href="' . esc_url($listing['url']) . '">' . esc_html($listing['url']) . '</a></p>';
            $result['actions']      = '<p>' . sprintf(
                /* translators: 1: Apache and LiteSpeed directive, 2: nginx directive. */
                esc_html__('Ask your host to turn off directory listing for the whole site: %1$s on Apache and LiteSpeed, %2$s on nginx. SEO Pro Stack does not add it to .htaccess itself: where the host does not allow it, that line stops the whole site loading.', 'seoprostack'),
                '<code>Options -Indexes</code>',
                '<code>autoindex off;</code>'
            ) . '</p>';
        } elseif ('failed' === $listing['state']) {
            $result['label']        = __('Directory listing could not be checked', 'seoprostack');
            $result['description'] .= '<p>' . esc_html__('This site could not reach its own uploads folder, so whether it lists its files is unknown.', 'seoprostack') . '</p>';
        } elseif ('skipped' === $listing['state']) {
            $result['label']        = __('Directory listing was not checked', 'seoprostack');
            $result['description'] .= '<p>' . esc_html__('The uploads folder has an index file, so asking for it cannot show whether the server lists files.', 'seoprostack') . '</p>';
        } elseif ('unrecorded' === $listing['state']) {
            $result['status'] = 'not_run';
            $result['label'] = __('No web-request directory listing probe is recorded yet', 'seoprostack');
            $result['description'] .= '<p>' . esc_html__('Open Tools → Site Health once to record this web probe.', 'seoprostack') . '</p>';
        }
        return $result;
    }

    /**
     * Turn REST API user lists and other people's profiles away from people
     * who cannot edit posts (the editor lists authors for those who can).
     * Everyone keeps /wp/v2/users/me.
     *
     * @param mixed           $response Response so far.
     * @param array           $handler  Route handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed
     */
    public static function rest_users($response, $handler, $request) {
        if (null !== $response || !$request instanceof WP_REST_Request) {
            return $response;
        }
        // Only reading: creating and changing users keep core's own checks.
        if (!in_array($request->get_method(), array('GET', 'HEAD'), true) || !self::rest_users_hidden(untrailingslashit((string) $request->get_route()) . '/')) {
            return $response;
        }
        return new WP_Error('rest_user_cannot_view', __('Sorry, you are not allowed to list users.', 'seoprostack'), array('status' => rest_authorization_required_code()));
    }

    /**
     * Whether a users route is hidden from the current user: everything
     * under /wp/v2/users but their own profile (and application passwords),
     * for people who cannot edit posts.
     *
     * @param string $route Route with a trailing slash.
     * @return bool
     */
    private static function rest_users_hidden($route) {
        if (!preg_match('#^/wp/v2/users/#', $route) || current_user_can('edit_posts')) {
            return false;
        }
        if (!preg_match('#^/wp/v2/users/(me|\d+)/#', $route, $own)) {
            return true;
        }
        return 'me' !== $own[1] && (int) $own[1] !== get_current_user_id();
    }

    /**
     * A numeric ?author= address answers "not found" for visitors, instead
     * of redirecting to the author's address, which holds their username.
     */
    public static function author_query() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
        if (is_user_logged_in() || !isset($_GET['author']) || !is_string($_GET['author']) || !preg_match('/\d/', wp_unslash($_GET['author']))) {
            return;
        }
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        remove_action('template_redirect', 'redirect_canonical');
    }

    /**
     * Leave out core's users sitemap, which lists authors' addresses.
     *
     * @param WP_Sitemaps_Provider|false $provider Provider.
     * @param string                     $name     Provider name.
     * @return WP_Sitemaps_Provider|false
     */
    public static function users_sitemap($provider, $name) {
        return 'users' === $name ? false : $provider;
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
        // HTTPS and security headers sends http addresses to https.
        if (SEOProStack_Security_Headers::redirect_on()) {
            unset($other['force_https']);
        }
        foreach ($other as $option => $name) {
            if (!empty($theirs[$option])) {
                $extras[] = $name;
            }
        }
        return array_values(array_unique(array_merge($extras, self::hostinger_uncovered($theirs))));
    }

    /**
     * Hostinger Tools' remote-access switches that are on there but not here.
     *
     * @param array $theirs Hostinger Tools settings.
     * @return string[] Names of the matching choices.
     */
    private static function hostinger_uncovered(array $theirs) {
        $items = self::switched_on() ? (array) SEOProStack_Settings::get(self::ITEMS_KEY) : array();
        $names = self::item_options();
        $found = array();
        foreach (array('disable_xml_rpc' => 'xmlrpc', 'disable_authentication_password' => 'app_passwords') as $option => $choice) {
            if (!empty($theirs[$option]) && !in_array($choice, $items, true)) {
                $found[] = $names[$choice];
            }
        }
        return $found;
    }
}
