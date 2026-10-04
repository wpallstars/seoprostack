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
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Plugin_References extends SEOProStack_Feature {

    const KEY = 'plugin_references';

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
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin()) {
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
     * Say what was removed.
     */
    public static function notice() {
        if (!self::$removed) {
            return;
        }

        $names = array_map(function ($file) {
            $dir = dirname((string) $file);
            return '.' === $dir ? basename((string) $file, '.php') : strtok($dir, '/');
        }, self::$removed);
        $names = array_values(array_unique($names));
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
