<?php
/**
 * Fewer Heartbeat requests.
 *
 * WordPress's Heartbeat asks the server for news every minute on every
 * admin screen, and on the site where a plugin uses it. Each request loads
 * WordPress and the plugins in full. The post, site and widget editors keep
 * it for post locks, autosave and the "session expired" prompt; elsewhere
 * this stops it on the site and slows it to once an hour on admin screens
 * (every 2 minutes where heartbeat.js allows no longer).
 * A script that needs Heartbeat still loads it, at the slower pace.
 *
 * Replaces Disable Bloat's Heartbeat switch, which turns it off everywhere,
 * editors included; the switch is imported once.
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Heartbeat extends SEOProStack_Feature {

    const KEY = 'heartbeat_limit';

    /** Where to limit it. */
    const ITEMS_KEY = 'heartbeat_limit_items';

    /** Seconds between requests where it is slowed (Heartbeat's longest; before WordPress 6.7 it stops at 120). */
    const SLOW = 3600;

    /** Admin screens that keep Heartbeat as it is. */
    const EDITORS = array('post.php', 'post-new.php', 'site-editor.php', 'widgets.php', 'customize.php');

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
                'tab'         => 'speed',
                'label'       => __('Fewer Heartbeat requests', 'seoprostack'),
                'description' => __('WordPress asks the server for news every minute while an admin screen is open, and each request loads the whole site. Editors keep it for post locks and autosave. Applies to everyone.', 'seoprostack'),
                'replaces'    => SEOProStack_Disable_Bloat::PLUGINS,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array('site', 'admin'),
                'parent'      => self::KEY,
                'label'       => __('Limit', 'seoprostack'),
                'options'     => array(__CLASS__, 'item_options'),
            ),
        );
    }

    /**
     * Choices.
     *
     * @return array<string,string>
     */
    public static function item_options() {
        return array(
            'site'  => __('On the site: off (only plugins that use it start it there)', 'seoprostack'),
            'admin' => __('On admin screens other than the editors: as seldom as WordPress allows, once an hour instead of every minute (every 2 minutes before WordPress 6.7); news such as an expired login waits for the next page', 'seoprostack'),
        );
    }

    /**
     * Import Disable Bloat's switch: it turns Heartbeat off everywhere.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if (SEOProStack_Disable_Bloat::imports('wp_heartbeat_disable')) {
            $options = self::import_setting($options, self::KEY, true);
            $options = self::import_setting($options, self::ITEMS_KEY, array('site', 'admin'));
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        $items = array_flip((array) SEOProStack_Settings::get(self::ITEMS_KEY));

        if (is_admin()) {
            if (isset($items['admin']) && !self::is_editor()) {
                add_filter('heartbeat_settings', array(__CLASS__, 'slow'), 99);
            }
            return;
        }
        if (isset($items['site'])) {
            // Plugins enqueue it in wp_enqueue_scripts or later; one that
            // needs it as a dependency still loads it, slowed.
            add_action('wp_enqueue_scripts', array(__CLASS__, 'dequeue'), 999);
            add_action('wp_footer', array(__CLASS__, 'dequeue'), 1);
            add_filter('heartbeat_settings', array(__CLASS__, 'slow'), 99);
        }
    }

    /**
     * Whether this admin screen is an editor that keeps Heartbeat.
     *
     * @return bool
     */
    private static function is_editor() {
        global $pagenow;
        return in_array($pagenow, self::EDITORS, true) || wp_doing_ajax();
    }

    /**
     * Heartbeat once an hour.
     *
     * @param mixed $settings Heartbeat settings.
     * @return mixed
     */
    public static function slow($settings) {
        if (!is_array($settings)) {
            return $settings;
        }
        $settings['interval'] = self::SLOW;
        return $settings;
    }

    /**
     * Leave Heartbeat out on the site.
     */
    public static function dequeue() {
        wp_dequeue_script('heartbeat');
    }
}
