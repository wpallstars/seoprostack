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
    const FILE = '000-seoprostack-autoload.php';
    const MARKER = 'SEO Pro Stack autoload sampler';

    private static $state = array();
    private static $seen = array();
    private static $kind = '';
    private static $lock = '';

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
        if (is_multisite() || wp_installing() || wp_doing_ajax() || wp_doing_cron()
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
        $options = get_option('seoprostack_options', array());
        if (!$valid || empty($options[self::KEY])) {
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
        update_option(self::STATE, self::$state, false);
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
            if ((int) $row['bytes'] < 10 * KB_IN_BYTES || (!$loaded && !isset($changes[$name])) || self::protected_option($name, $defaults)
                || (0 === strpos($name, '_transient_') && isset($names['_transient_timeout_' . substr($name, 11)]))) {
                continue;
            }
            $large[$name] = self::$state['large'][$name] ?? array('since' => time(), 'counts' => self::$state['counts'] ?? array(), 'seen' => array());
            $large[$name]['size'] = (int) $row['bytes'];
            $large[$name]['autoload'] = $row['autoload'];
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
        return array_fill_keys($matches[1], true);
    }

    /** Pause globally rather than guess ownership of dynamically indexed alloptions. */
    private static function direct_readers() {
        $roots = array(WPMU_PLUGIN_DIR, get_template_directory(), get_stylesheet_directory());
        foreach (self::stored_active_plugins() as $file) {
            if ($file !== plugin_basename(dirname(__DIR__, 2) . '/seoprostack.php')) {
                $roots[] = '.' === dirname($file) ? WP_PLUGIN_DIR . '/' . $file : WP_PLUGIN_DIR . '/' . dirname($file);
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
                    if ('php' !== $file->getExtension() || self::FILE === $file->getFilename() || $file->isDir()) {
                        continue;
                    }
                    $source = $fs->get_contents($file->getPathname());
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
                if (!empty($entry['seen']['site']) || !empty(self::$state['paused'])) {
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
            if (function_exists('wp_set_option_autoload_values')) {
                wp_set_option_autoload_values(array($name => false));
            } else {
                self::set_flag($name, $row['autoload'], $set, $row['hash']);
            }
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
        $remaining = array();
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
        self::sync_sampler(false);
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
