<?php
/**
 * Clean up references to deleted plugins.
 *
 * When a plugin folder is deleted without using the Plugins screen (by FTP,
 * a host's file manager or a migration), WordPress keeps pointing at it:
 * - `active_plugins`: WordPress shows "Plugin file does not exist" and
 *   deactivates it the next time the Plugins screen is opened, so this
 *   feature leaves that to core;
 * - `uninstall_plugins`: uninstall callbacks for plugins that are gone, kept
 *   and loaded on every request forever;
 * - `recently_activated`: the "Recently active" list on the Plugins screen.
 *
 * When the Plugins screen is opened, entries whose plugin file no longer
 * exists are removed from the last two lists and a notice says how many.
 * Replaces the maintainer's "Fix 'Plugin file does not exist' Notices".
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Plugin_References extends SEOProStack_Feature {

    const KEY = 'plugin_references';

    /** Admin-post action of Clean up every site now (networks). */
    const NETWORK_ACTION = 'seoprostack_plugin_references_network';

    /** Sites checked by Clean up every site now. */
    const MAX_SITES = 1000;

    /** Transient prefix (plus user ID) handing the result to the next screen. */
    const RESULT = 'seoprostack_plugin_refs_';

    /**
     * Plugin files removed on this request (the notice shows on the same screen).
     *
     * @var string[]
     */
    private static $removed = array();

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'plugins',
                'label'       => __('Clean up deleted plugins', 'seoprostack'),
                'description' => __('When plugin folders were deleted outside the Plugins screen, WordPress keeps their uninstall and “Recently active” entries. Opening the Plugins screen removes them. WordPress itself switches off missing active plugins there.', 'seoprostack'),
                'replaces'    => array('wp-fix-plugin-does-not-exist-notices' => 'Fix ‘Plugin file does not exist’ Notices'),
                // Clean up every site now, for network administrators.
                'panel'       => is_multisite(),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!is_admin()) {
            return;
        }
        // The button works whether the switch is on or off.
        if (is_multisite()) {
            add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
            add_action('admin_post_' . self::NETWORK_ACTION, array(__CLASS__, 'clean_network'));
            add_action('admin_notices', array(__CLASS__, 'network_notice'));
        }
        if (!self::enabled()) {
            return;
        }
        add_action('load-plugins.php', array(__CLASS__, 'clean_up'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('network_admin_notices', array(__CLASS__, 'notice'));
    }

    /**
     * Whether a plugin reference points at a file that exists.
     *
     * @param mixed $file Plugin file relative to the plugins directory.
     * @return bool
     */
    public static function exists($file) {
        return is_string($file) && '' !== $file && 0 === validate_file($file) && file_exists(WP_PLUGIN_DIR . '/' . $file);
    }

    /**
     * Remove references to missing plugin files.
     */
    public static function clean_up() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $removed = array();
        $network = is_multisite() && is_network_admin();

        if ($network) {
            if (current_user_can('manage_network_plugins')) {
                $removed = array_merge($removed, self::clean_keys('recently_activated', true));
            }
        } else {
            $removed = array_merge($removed, self::clean_keys('recently_activated', false));
            if (current_user_can('delete_plugins')) {
                $removed = array_merge($removed, self::clean_keys('uninstall_plugins', false));
            }
        }

        self::$removed = array_values(array_unique($removed));
    }

    /**
     * Drop the keys of an option (file => data) whose plugin file is missing.
     *
     * @param string $option  Option name.
     * @param bool   $network Network-wide option.
     * @return string[] Removed plugin files.
     */
    private static function clean_keys($option, $network) {
        $value = $network ? get_site_option($option, array()) : get_option($option, array());
        if (!is_array($value) || !$value) {
            return array();
        }

        $removed = array();
        foreach (array_keys($value) as $file) {
            if (!self::exists($file)) {
                unset($value[$file]);
                $removed[] = (string) $file;
            }
        }
        if ($removed) {
            if ($network) {
                update_site_option($option, $value);
            } else {
                update_option($option, $value);
            }
        }
        return $removed;
    }

    /**
     * Options panel on a network: Clean up every site now.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel($key, $field = array()) {
        if (self::KEY !== $key || !is_multisite()) {
            return;
        }
        echo '<div class="sps-panel-note">';
        if (!current_user_can('manage_network_plugins') || !SEOProStack_Settings::can_change()) {
            echo '<p>' . esc_html__('Only network administrators can clean up every site of the network.', 'seoprostack') . '</p></div>';
            return;
        }
        echo '<p>' . esc_html__('Plugins screens of most sites are rarely opened, so leftovers of deleted plugins can stay there. This checks every site of the network now and removes entries for plugins whose files are gone, active ones included, as WordPress does on the Plugins screen.', 'seoprostack') . '</p>';
        printf(
            '<form method="post" action="%1$s"><input type="hidden" name="action" value="%2$s" />',
            esc_url(admin_url('admin-post.php')),
            esc_attr(self::NETWORK_ACTION)
        );
        wp_nonce_field(self::NETWORK_ACTION);
        printf('<p><button type="submit" class="button">%s</button></p></form>', esc_html__('Clean up every site now', 'seoprostack'));
        echo '</div>';
    }

    /**
     * Clean up every site of the network: active plugins through WordPress's
     * own check (as on the Plugins screen), uninstall and recently active
     * entries, and the network's own lists.
     */
    public static function clean_network() {
        if (!is_multisite() || !current_user_can('manage_network_plugins') || !SEOProStack_Settings::can_change()) {
            wp_die(esc_html__('You cannot change these settings.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::NETWORK_ACTION);
        if (!function_exists('validate_active_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $removed = array();
        $sites   = get_sites(array('fields' => 'ids', 'number' => self::MAX_SITES + 1));
        $capped  = count($sites) > self::MAX_SITES;
        $sites   = array_slice($sites, 0, self::MAX_SITES);
        foreach ($sites as $site_id) {
            switch_to_blog((int) $site_id);
            // Also checks the network's active plugins, for a network administrator.
            $removed = array_merge(
                $removed,
                array_map('strval', array_keys(validate_active_plugins())),
                self::clean_keys('recently_activated', false),
                self::clean_keys('uninstall_plugins', false)
            );
            restore_current_blog();
        }
        $removed = array_merge($removed, self::clean_keys('recently_activated', true));

        set_transient(self::RESULT . get_current_user_id(), array(
            'names'  => self::names($removed),
            'sites'  => count($sites),
            'capped' => $capped,
        ), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('options-general.php?page=seoprostack&tab=plugins'));
        exit;
    }

    /**
     * Say what Clean up every site now found.
     */
    public static function network_notice() {
        $key    = self::RESULT . get_current_user_id();
        $result = get_transient($key);
        if (!is_array($result)) {
            return;
        }
        delete_transient($key);

        $names = isset($result['names']) ? (array) $result['names'] : array();
        $sites = isset($result['sites']) ? (int) $result['sites'] : 0;
        /* translators: %s: number of sites */
        $text = sprintf(_n('Checked %s site.', 'Checked %s sites.', $sites, 'seoprostack'), number_format_i18n($sites));
        if ($names) {
            $text .= ' ' . sprintf(
                /* translators: 1: number of plugins, 2: comma-separated plugin folder names */
                _n('Removed leftover entries for %1$s deleted plugin: %2$s.', 'Removed leftover entries for %1$s deleted plugins: %2$s.', count($names), 'seoprostack'),
                number_format_i18n(count($names)),
                implode(', ', $names)
            );
        } else {
            $text .= ' ' . __('No leftover entries for deleted plugins.', 'seoprostack');
        }
        if (!empty($result['capped'])) {
            /* translators: %s: number of sites */
            $text .= ' ' . sprintf(__('Only the first %s sites were checked.', 'seoprostack'), number_format_i18n(self::MAX_SITES));
        }
        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    /**
     * Plugin folder names (or file names for single-file plugins) of plugin files.
     *
     * @param string[] $files Plugin files.
     * @return string[]
     */
    private static function names(array $files) {
        $names = array_map(function ($file) {
            $dir = dirname((string) $file);
            return '.' === $dir ? basename((string) $file, '.php') : (string) strtok($dir, '/');
        }, $files);
        return array_values(array_unique($names));
    }

    /**
     * Say what was removed.
     */
    public static function notice() {
        if (!self::$removed) {
            return;
        }

        $names = self::names(self::$removed);
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: number of plugins, 2: comma-separated plugin folder names */
                    _n('Removed leftover settings for %1$d deleted plugin: %2$s.', 'Removed leftover settings for %1$d deleted plugins: %2$s.', count($names), 'seoprostack'),
                    count($names),
                    implode(', ', $names)
                ));
                ?>
            </p>
        </div>
        <?php
    }
}
