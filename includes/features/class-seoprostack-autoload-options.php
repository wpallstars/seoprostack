<?php
/**
 * Learn large settings before ordinary plugins load, then change only autoload.
 *
 * A removable MU sampler observes pre_option, including plugin bootstrap reads.
 * Unselected requests read no learning option and perform no database work.
 * A daily safety scan pauses changes if any other active plugin, MU plugin or
 * theme calls wp_load_alloptions(): dynamic keys cannot safely be attributed to
 * one plugin. Incomplete scans also pause changes. Core defaults are extracted
 * from the installed schema (without calling populate_options()). No values are
 * collected, displayed or deleted, including the rebuildable dirsize cache.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Autoload_Options extends SEOProStack_Feature {
    const KEY = 'autoload_options';
    const STATE = 'seoprostack_autoload_learning';
    const CHANGES = 'seoprostack_autoload_changes';
    const RESET = 'seoprostack_autoload_reset';
    const FILE = '000-seoprostack-autoload.php';
    const MARKER = 'SEO Pro Stack autoload sampler';

    private static $state = array();
    private static $seen = array();
    private static $kind = '';
    private static $lock = '';
    private static $generation = '';

    public static function settings() {
        return array(self::KEY => array(
            'type' => 'bool',
            'default' => false,
            'tab' => 'speed',
            'label' => __('Load large settings only where they are used', 'seoprostack'),
            'description' => __('Other plugins save settings that load on every request. Learn which large ones site pages do not use, then load those only when asked for. Turning this off puts them back. Single sites only.', 'seoprostack'),
        ));
    }

    public static function boot() {
        if (is_multisite()) {
            return;
        }
        add_action('update_option_seoprostack_options', array(__CLASS__, 'settings_saved'), 10, 2);
        add_action('seoprostack_settings_tab_after', array(__CLASS__, 'render'));
        register_deactivation_hook(SEOPROSTACK_FILE, array(__CLASS__, 'stop'));
        add_action('activated_plugin', array(__CLASS__, 'stop'));
        add_action('deactivated_plugin', array(__CLASS__, 'stop'));
        add_action('after_switch_theme', array(__CLASS__, 'stop'));
        if (is_admin() && current_user_can('manage_options') && !wp_doing_ajax()) {
            if (!self::enabled() && get_option(self::CHANGES)) {
                self::stop(); // Retry an undo interrupted by a busy database.
            }
            self::sync_sampler(self::enabled());
        }
    }

    /** Install only our own marked file; do not overwrite an unrelated MU plugin. */
    private static function sync_sampler($on) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $fs = new WP_Filesystem_Direct(null);
        $path = WPMU_PLUGIN_DIR . '/' . self::FILE;
        $old = is_file($path) ? $fs->get_contents($path) : false;
        if ($old && false === strpos($old, self::MARKER)) {
            return;
        }
        if (!$on) {
            if ($old) {
                $fs->delete($path);
            }
            return;
        }
        $base = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(SEOPROSTACK_FILE)) . '/includes/';
        $code = "<?php\n// " . self::MARKER . "\n"
            . 'if (defined(\'ABSPATH\') && is_file(' . var_export($base . 'features/class-seoprostack-autoload-options.php', true) . ")) {\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates a PHP string literal.
            . '    require_once ' . var_export($base . 'class-seoprostack-feature.php', true) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates a PHP string literal.
            . '    require_once ' . var_export($base . 'features/class-seoprostack-autoload-options.php', true) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- generates a PHP string literal.
            . "    SEOProStack_Autoload_Options::start();\n}\n";
        if ($old !== $code && wp_mkdir_p(WPMU_PLUGIN_DIR)) {
            $fs->put_contents($path, $code, 0644);
        }
    }

    public static function settings_saved($old, $new) {
        $on = !empty($new[self::KEY]);
        if (!$on && !empty($old[self::KEY])) {
            self::stop();
        }
        self::sync_sampler($on);
    }

    /** Called by the MU file only: learning must precede plugin bootstrap. */
    public static function start() {
        if (self::$lock || is_multisite() || wp_installing() || wp_doing_ajax() || wp_doing_cron()
            || (defined('WP_CLI') && WP_CLI) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return;
        }
        $options = get_option('seoprostack_options', array());
        // pluggable.php (and wp_rand) is not loaded yet in a must-use plugin.
        if (empty($options[self::KEY]) || 1 !== random_int(1, 100)) {
            return;
        }
        self::$kind = is_admin() ? 'admin' : 'site';
        $state = get_option(self::STATE, array());
        self::$state = is_array($state) ? $state : array();
        $hour = gmdate('Y-m-d-H');
        if ((int) (self::$state['hours'][$hour][self::$kind] ?? 0) >= 3) {
            return;
        }
        global $wpdb;
        self::$lock = 'sps_autoload_' . md5($wpdb->options);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-owned lock, no option/cache can arbitrate concurrent samples.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::$lock))) {
            self::$lock = '';
            return;
        }
        // Read again under the lock so overlapping samples cannot lose evidence.
        wp_cache_delete(self::STATE, 'options');
        self::$state = (array) get_option(self::STATE, array());
        self::$generation = self::generation();
        if ((self::$state['generation'] ?? '') !== self::$generation) {
            self::restore_records();
            self::$state = array();
        }
        self::$state['generation'] = self::$generation;
        if ((int) (self::$state['hours'][$hour][self::$kind] ?? 0) >= 3) {
            self::unlock();
            return;
        }
        if (empty(self::$state['surveyed']) || time() - self::$state['surveyed'] >= DAY_IN_SECONDS) {
            self::survey();
        }
        add_filter('pre_option', array(__CLASS__, 'observe'), -PHP_INT_MAX, 3);
        add_action('shutdown', array(__CLASS__, 'record'), PHP_INT_MAX);
    }

    public static function observe($pre, $option, $default) {
        if (isset(self::$state['large'][$option])) {
            self::$seen[$option] = true;
        }
        return $pre;
    }

    public static function record() {
        remove_filter('pre_option', array(__CLASS__, 'observe'), -PHP_INT_MAX);
        $valid = 'admin' === self::$kind ? is_user_logged_in() :
            (did_action('wp') && !is_user_logged_in() && !(defined('REST_REQUEST') && REST_REQUEST));
        if (!$valid) {
            self::unlock();
            return;
        }
        if (self::$generation !== self::generation() || !self::still_on()) {
            self::restore_records();
            self::unlock();
            return;
        }
        $hour = gmdate('Y-m-d-H');
        self::$state['hours'] = array($hour => self::$state['hours'][$hour] ?? array());
        self::$state['hours'][$hour][self::$kind] = (int) (self::$state['hours'][$hour][self::$kind] ?? 0) + 1;
        self::$state['counts'][self::$kind] = (int) (self::$state['counts'][self::$kind] ?? 0) + 1;
        foreach (self::$seen as $name => $seen) {
            self::$state['large'][$name]['seen'][self::$kind] = true;
        }
        if (empty(self::$state['decided']) || time() - self::$state['decided'] >= DAY_IN_SECONDS) {
            self::decide();
            self::$state['decided'] = time();
        }
        if (self::$generation !== self::generation() || !self::still_on()) {
            self::restore_records();
        } else {
            update_option(self::STATE, self::$state, false);
        }
        self::unlock();
    }

    private static function unlock() {
        if (self::$lock) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release the connection-owned sample lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::$lock));
            self::$lock = '';
        }
    }

    /**
     * Read current configuration without another request's stale alloptions cache.
     *
     * @phpstan-impure
     */
    private static function still_on() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- sampled shutdown must see a concurrent off switch immediately.
        $options = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'seoprostack_options')));
        return is_array($options) && !empty($options[self::KEY]);
    }

    /** @phpstan-impure */
    private static function generation() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cancellation token must not use request-local or persistent cached evidence.
        return (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::RESET));
    }

    /** One names/sizes/flags query a day; never fetch option values for the survey. */
    private static function survey() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- option metadata is not available through the option API.
        $rows = $wpdb->get_results("SELECT option_name, LENGTH(option_value) AS bytes, autoload FROM {$wpdb->options}", ARRAY_A);
        $defaults = self::core_defaults();
        self::$state['paused'] = !$defaults ? 'schema' : self::direct_readers();
        self::$state['surveyed'] = time();
        self::$state['total'] = 0;
        $names = array_column($rows, 'autoload', 'option_name');
        $large = array();
        $changes = (array) get_option(self::CHANGES, array());
        foreach ($rows as $row) {
            $name = $row['option_name'];
            $loaded = in_array($row['autoload'], array('yes', 'on', 'auto', 'auto-on'), true);
            if ($loaded) {
                self::$state['total'] += (int) $row['bytes'];
            }
            $protected = self::protected_option($name, $defaults)
                || (0 === strpos($name, '_transient_') && isset($names['_transient_timeout_' . substr($name, 11)]));
            if (!isset($changes[$name]) && ((int) $row['bytes'] < 10 * KB_IN_BYTES || !$loaded || $protected)) {
                continue;
            }
            $large[$name] = self::$state['large'][$name] ?? array('since' => time(), 'counts' => self::$state['counts'] ?? array(), 'seen' => array());
            $large[$name]['size'] = (int) $row['bytes'];
            $large[$name]['autoload'] = $row['autoload'];
            $large[$name]['protected'] = (bool) $protected;
        }
        self::$state['large'] = $large;
    }

    private static function protected_option($name, $defaults) {
        global $wpdb;
        return isset($defaults[$name]) || $name === $wpdb->prefix . 'user_roles'
            || in_array($name, array('cron', 'rewrite_rules', 'sidebars_widgets', 'active_plugins', 'active_sitewide_plugins', 'recently_activated', 'uninstall_plugins'), true)
            || preg_match('/^(widget_|theme_mods_|seoprostack_|wp_allstars_|_site_transient_|_transient_timeout_|_transient_wp_|_transient_seoprostack_)/', $name);
    }

    private static function core_defaults() {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $fs = new WP_Filesystem_Direct(null);
        $source = $fs->get_contents(ABSPATH . 'wp-admin/includes/schema.php');
        if (!$source || !preg_match_all('/[\'"]([a-zA-Z0-9_]+)[\'"]\s*=>/', $source, $matches)) {
            return array();
        }
        preg_match_all('/\b(?:add|update)_option\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $source, $extra);
        return array_fill_keys(array_merge($matches[1], $extra[1]), true);
    }

    /** Pause globally rather than guess ownership of dynamically indexed alloptions. */
    private static function direct_readers() {
        $roots = array(WPMU_PLUGIN_DIR, get_template_directory(), get_stylesheet_directory());
        foreach (self::stored_active_plugins() as $file) {
            if ($file !== plugin_basename(dirname(__DIR__, 2) . '/seoprostack.php')) {
                // A root-level plugin can include sibling files; scan that
                // directory too rather than claim its includes are safe.
                $roots[] = '.' === dirname($file) ? WP_PLUGIN_DIR : WP_PLUGIN_DIR . '/' . dirname($file);
            }
        }
        $fs = new WP_Filesystem_Direct(null);
        $count = 0;
        try {
            foreach (array_unique($roots) as $root) {
                if (!file_exists($root)) {
                    continue;
                }
                $files = is_dir($root) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) : array(new SplFileInfo($root));
                foreach ($files as $file) {
                    $path = $file->getPathname();
                    if (0 === strpos($path, dirname(__DIR__, 2) . '/')) {
                        continue; // Our only alloptions lookup tests protected active_plugins.
                    }
                    if (dirname($path) === WPMU_PLUGIN_DIR && strtolower($file->getExtension()) === 'php' && strcmp($file->getFilename(), self::FILE) < 0) {
                        return 'scan'; // An earlier MU plugin may read options before observation starts.
                    }
                    if ($file->isLink() && $file->isDir()) {
                        return 'scan'; // Do not silently skip executable symlinked includes.
                    }
                    if (!in_array(strtolower($file->getExtension()), array('php', 'inc', 'phtml', 'php5', 'php7', 'php8'), true) || self::FILE === $file->getFilename() || $file->isDir()) {
                        continue;
                    }
                    $source = $fs->get_contents($path);
                    if (++$count > 4000 || false === $source) {
                        return 'scan';
                    }
                    if (preg_match('/\bwp_load_alloptions\s*\(/', $source)) {
                        return 'direct';
                    }
                }
            }
        } catch (UnexpectedValueException $e) {
            return 'scan';
        }
        return '';
    }

    private static function decide() {
        $changes = (array) get_option(self::CHANGES, array());
        $n = 0;
        foreach (self::$state['large'] ?? array() as $name => $entry) {
            if ($n >= 30) {
                break;
            }
            if (isset($changes[$name])) {
                if (!empty($entry['seen']['site']) || !empty(self::$state['paused']) || !empty($entry['protected'])) {
                    if (self::restore_one($name, $changes[$name])) {
                        unset($changes[$name]);
                    }
                    ++$n;
                } elseif ($entry['autoload'] !== $changes[$name]['set']) {
                    unset($changes[$name]); // A plugin chose a different flag; learn again from today.
                    self::$state['large'][$name]['since'] = time();
                    self::$state['large'][$name]['counts'] = self::$state['counts'];
                }
                continue;
            }
            if (!empty(self::$state['paused']) || !empty($entry['seen']['site']) || time() - $entry['since'] < 3 * DAY_IN_SECONDS
                || (self::$state['counts']['site'] ?? 0) - ($entry['counts']['site'] ?? 0) < 30
                || (self::$state['counts']['admin'] ?? 0) - ($entry['counts']['admin'] ?? 0) < 10) {
                continue;
            }
            $row = self::row($name);
            if (!$row || !in_array($row['autoload'], array('yes', 'on', 'auto', 'auto-on'), true)) {
                continue;
            }
            global $wp_version;
            $set = version_compare($wp_version, '6.6', '>=') ? 'off' : 'no';
            $changes[$name] = array('previous' => $row['autoload'], 'set' => $set, 'size' => $entry['size'], 'date' => time(), 'hash' => $row['hash']);
            // Persist undo evidence BEFORE changing the flag; a failed write must not lose it.
            update_option(self::CHANGES, $changes, false);
            if (get_option(self::CHANGES) !== $changes) {
                break;
            }
            // Core's bulk API cannot condition on the previous flag/value and
            // can overwrite an intervening plugin save. Keep the same flag and
            // cache behaviour, with an atomic compare-and-set on every version.
            self::set_flag($name, $row['autoload'], $set, $row['hash']);
            self::clear_cache($name);
            ++$n;
        }
        update_option(self::CHANGES, $changes, false);
    }

    private static function row($name) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- verify flag and fingerprint, never read or record an option value.
        return $wpdb->get_row($wpdb->prepare("SELECT autoload, MD5(option_value) AS hash FROM {$wpdb->options} WHERE option_name = %s", $name), ARRAY_A);
    }

    private static function set_flag($name, $from, $to, $hash) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-set preserves intervening plugin writes and exact legacy autoload spelling.
        $result = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET autoload = %s WHERE option_name = %s AND autoload = %s AND MD5(option_value) = %s", $to, $name, $from, $hash));
        self::clear_cache($name);
        return false !== $result;
    }

    private static function clear_cache($name) {
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private static function restore_one($name, $change) {
        if (isset($change['previous'], $change['set'], $change['hash']) && in_array($change['previous'], array('yes', 'on', 'auto', 'auto-on'), true)) {
            return self::set_flag($name, $change['set'], $change['previous'], $change['hash']);
        }
        return false;
    }

    public static function stop() {
        if (is_multisite()) {
            return;
        }
        $options = get_option('seoprostack_options', array());
        if (empty($options[self::KEY]) && !self::$lock && !get_option(self::STATE) && !get_option(self::CHANGES)) {
            self::sync_sampler(false);
            return;
        }
        update_option(self::RESET, bin2hex(random_bytes(16)), false);
        global $wpdb;
        $own_lock = !empty(self::$lock);
        $lock = 'sps_autoload_' . md5($wpdb->options);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- serialize owner-requested undo with an in-flight sample; its cancellation token also invalidates stale state.
        $acquired = $own_lock || '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock));
        if ($acquired) {
            self::restore_records();
            if (!$own_lock) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release the undo operation's connection-owned lock.
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
        self::sync_sampler(false);
    }

    private static function restore_records() {
        $remaining = array();
        wp_cache_delete(self::CHANGES, 'options');
        foreach ((array) get_option(self::CHANGES, array()) as $name => $change) {
            if (!self::restore_one($name, $change)) {
                $remaining[$name] = $change;
            }
        }
        if ($remaining) {
            update_option(self::CHANGES, $remaining, false);
        } else {
            delete_option(self::CHANGES);
        }
        delete_option(self::STATE);
    }

    /** Admin-only read: also used by Hosting needs when the feature is off. */
    public static function largest($limit = 3) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- names and sizes only, for an explicitly requested admin report.
        return $wpdb->get_results($wpdb->prepare("SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on') ORDER BY bytes DESC LIMIT %d", $limit), ARRAY_A);
    }

    private static function owner($name) {
        $known = array('ws_' => 'Admin Menu Editor', 'eos_' => 'Freesoul Deactivate Plugins', 'stellarwp_uplink_' => 'StellarWP', 'astra_' => 'Astra');
        foreach ($known as $prefix => $owner) {
            if (0 === strpos($name, $prefix)) {
                return $owner;
            }
        }
        return '';
    }

    public static function render($tab) {
        if ('speed' !== $tab || !current_user_can('manage_options')) {
            return;
        }
        $state = (array) get_option(self::STATE, array());
        $reasons = array(
            'schema' => __('The WordPress settings safety list could not be read.', 'seoprostack'),
            'scan' => __('The active code safety scan could not finish; no settings will be changed.', 'seoprostack'),
            'direct' => __('Active code reads the full settings list directly; no settings will be changed.', 'seoprostack'),
        );
        echo '<h3>' . esc_html__('Large settings', 'seoprostack') . '</h3><p>';
        echo esc_html__('Turn off “Load large settings only where they are used” to put changed settings back. Learning needs at least 3 days, 30 visitor pages and 10 admin screens for each new large setting.', 'seoprostack');
        echo '</p><p>' . esc_html($reasons[$state['paused'] ?? ''] ?? '') . '</p><ul>';
        $total = array_sum(array_map('strlen', wp_load_alloptions()));
        /* translators: %s: combined size of autoloaded options. */
        echo '<li>' . esc_html(sprintf(__('Loaded on every request: %s', 'seoprostack'), size_format($total))) . '</li>';
        foreach (self::largest(15) as $row) {
            echo '<li>' . esc_html($row['option_name'] . ': ' . size_format($row['bytes']) . ' ' . self::owner($row['option_name'])) . '</li>';
        }
        foreach ((array) get_option(self::CHANGES, array()) as $name => $change) {
            echo '<li>' . esc_html($name . ': ' . size_format($change['size']) . ' — ' . __('not used on site pages', 'seoprostack')) . '</li>';
        }
        echo '</ul>';
    }
}
