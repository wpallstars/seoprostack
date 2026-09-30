<?php
/**
 * Turn off update checks and automatic updates.
 *
 * For each chosen type (WordPress, plugins, themes) this removes the core
 * callbacks and cron events that check WordPress.org, blocks those requests
 * if something else starts them, reports "no updates" from the cached
 * update data, and stops automatic updates. Only filters and actions are
 * used; no constants are defined, so turning the feature off restores core
 * behaviour on the next request (core reschedules its own checks).
 *
 * WordPress's automatic updater runs after its own version check, so turning
 * off WordPress checks also stops automatic plugin and theme updates. With
 * "Still install WordPress security releases" the check keeps running and
 * only minor WordPress releases install by themselves.
 *
 * Updating by uploading a new version still works.
 *
 * Replaces "Disable All WordPress Updates"; its Security Mode setting is
 * imported once.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Disable_Updates extends SEOProStack_Feature {

    const KEY = 'disable_updates';

    /**
     * Types turned off in this request.
     *
     * @var array{core:bool,plugins:bool,themes:bool}
     */
    private static $off = array('core' => false, 'plugins' => false, 'themes' => false);

    /**
     * Whether WordPress updates are chosen, with or without security releases.
     *
     * @var bool
     */
    private static $core = false;

    /**
     * WordPress.org request paths, and the cron hooks, per type.
     */
    const PATHS = array(
        'core'    => '/core/version-check/',
        'plugins' => '/plugins/update-check/',
        'themes'  => '/themes/update-check/',
    );
    const CRON = array(
        'core'    => array('wp_version_check', 'wp_maybe_auto_update'),
        'plugins' => array('wp_update_plugins'),
        'themes'  => array('wp_update_themes'),
    );

    /**
     * Settings. Update data is shared by the whole network, so on multisite
     * the feature is only offered on the main site, which the network admin
     * uses too.
     *
     * @return array
     */
    public static function settings() {
        if (is_multisite() && !is_main_site()) {
            return array();
        }
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'maintenance',
                'label'       => __('Turn off updates', 'seoprostack'),
                'description' => __('Stop WordPress checking for new versions and updating by itself. Use this when updates are handled another way, such as by your host or a deploy process. You can still update by uploading a new version.', 'seoprostack'),
                'replaces'    => array('disable-wordpress-updates' => 'Disable All WordPress Updates'),
            ),
            'disable_updates_types' => array(
                'type'        => 'multi',
                'default'     => array('core', 'plugins', 'themes'),
                'parent'      => self::KEY,
                'label'       => __('Turn off updates for', 'seoprostack'),
                'description' => __('Turning off WordPress also stops plugins and themes updating by themselves, because WordPress runs those updates after its own check.', 'seoprostack'),
                'options'     => array(__CLASS__, 'type_options'),
            ),
            'disable_updates_security' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Still install WordPress security releases', 'seoprostack'),
                'description' => __('Minor releases, such as 6.2.1, install by themselves. New major versions do not.', 'seoprostack'),
            ),
        );
    }

    /**
     * Update types.
     *
     * @return array<string,string>
     */
    public static function type_options() {
        return array(
            'core'    => __('WordPress', 'seoprostack'),
            'plugins' => __('Plugins', 'seoprostack'),
            'themes'  => __('Themes', 'seoprostack'),
        );
    }

    /**
     * Import Disable All WordPress Updates' Security Mode, stored only once
     * its settings page has been saved.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $mode = get_option('osdwp_security_mode', null);
        return self::import_setting($options, 'disable_updates_security', null === $mode ? null : (bool) $mode);
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }

        $types = array_flip((array) SEOProStack_Settings::get('disable_updates_types'));
        if (!$types) {
            return;
        }
        self::$core = isset($types['core']);
        $security   = self::$core && (bool) SEOProStack_Settings::get('disable_updates_security');

        self::$off = array(
            'core'    => isset($types['core']) && !$security,
            'plugins' => isset($types['plugins']),
            'themes'  => isset($types['themes']),
        );

        if (self::$off['plugins']) {
            foreach (array('load-plugins.php', 'load-update.php', 'load-update-core.php', 'wp_update_plugins') as $hook) {
                remove_action($hook, 'wp_update_plugins');
            }
            remove_action('admin_init', '_maybe_update_plugins');
            add_filter('pre_site_transient_update_plugins', array(__CLASS__, 'no_updates'));
            add_filter('auto_update_plugin', '__return_false', 99);
            add_filter('plugins_auto_update_enabled', '__return_false');
        }

        if (self::$off['themes']) {
            foreach (array('load-themes.php', 'load-update.php', 'load-update-core.php', 'wp_update_themes') as $hook) {
                remove_action($hook, 'wp_update_themes');
            }
            remove_action('admin_init', '_maybe_update_themes');
            add_filter('pre_site_transient_update_themes', array(__CLASS__, 'no_updates'));
            add_filter('auto_update_theme', '__return_false', 99);
            add_filter('themes_auto_update_enabled', '__return_false');
        }

        if (self::$off['core']) {
            remove_action('admin_init', '_maybe_update_core');
            remove_action('wp_version_check', 'wp_version_check');
            remove_action('wp_maybe_auto_update', 'wp_maybe_auto_update');
            add_filter('pre_site_transient_update_core', array(__CLASS__, 'no_core_updates'));
            add_filter('automatic_updater_disabled', '__return_true', 99);
            add_filter('auto_update_core', '__return_false', 99);
        } elseif ($security) {
            add_filter('allow_dev_auto_core_updates', '__return_false', 99);
            add_filter('allow_minor_auto_core_updates', '__return_true', 99);
            add_filter('allow_major_auto_core_updates', '__return_false', 99);
        }

        if (self::$off['core'] && self::$off['plugins'] && self::$off['themes']) {
            remove_action('init', 'wp_schedule_update_checks');
        }

        add_filter('schedule_event', array(__CLASS__, 'filter_event'));
        add_filter('pre_http_request', array(__CLASS__, 'block_request'), 10, 3);
        add_filter('site_status_tests', array(__CLASS__, 'site_status_tests'));
        add_action('admin_init', array(__CLASS__, 'admin_init'));
        add_action('load-update-core.php', array(__CLASS__, 'updates_screen'));
    }

    /**
     * Admin hooks that core adds after `init`, and events already scheduled.
     */
    public static function admin_init() {
        // "WordPress x is available": new major versions are not wanted, and
        // security releases install by themselves.
        if (self::$core) {
            remove_action('admin_notices', 'update_nag', 3);
            remove_action('network_admin_notices', 'update_nag', 3);
        }

        $checks = array('core' => 'wp_version_check', 'plugins' => 'wp_update_plugins', 'themes' => 'wp_update_themes');
        foreach ($checks as $type => $callback) {
            if (self::$off[$type]) {
                remove_action('upgrader_process_complete', $callback, 10);
            }
        }

        foreach (self::off_hooks() as $hook) {
            if (wp_next_scheduled($hook)) {
                wp_clear_scheduled_hook($hook);
            }
        }
    }

    /**
     * Cron hooks of the types turned off.
     *
     * @return string[]
     */
    private static function off_hooks() {
        $hooks = array();
        foreach (self::CRON as $type => $list) {
            if (self::$off[$type]) {
                $hooks = array_merge($hooks, $list);
            }
        }
        return $hooks;
    }

    /**
     * Do not schedule (or reschedule) update checks that are turned off.
     *
     * @param object|false $event Event.
     * @return object|false
     */
    public static function filter_event($event) {
        if (is_object($event) && isset($event->hook) && in_array($event->hook, self::off_hooks(), true)) {
            return false;
        }
        return $event;
    }

    /**
     * Answer update checks to WordPress.org without contacting it. A non-200
     * response (not an error) makes core give up quietly, without the
     * "could not establish a secure connection" warning and plain-HTTP retry.
     *
     * @param false|array|WP_Error $pre  Short-circuit value.
     * @param array                $args Request arguments.
     * @param string               $url  Request URL.
     * @return false|array|WP_Error
     */
    public static function block_request($pre, $args, $url) {
        if (false !== $pre || !is_string($url) || false === stripos($url, 'api.wordpress.org')) {
            return $pre;
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if ('api.wordpress.org' !== $host) {
            return $pre;
        }
        foreach (self::PATHS as $type => $prefix) {
            if (self::$off[$type] && 0 === strpos($path, $prefix)) {
                return array(
                    'headers'  => array(),
                    'body'     => '',
                    'response' => array('code' => 403, 'message' => 'Update checks are turned off'),
                    'cookies'  => array(),
                    'filename' => null,
                );
            }
        }
        return $pre;
    }

    /**
     * Cached plugin or theme update data with nothing to update.
     *
     * @return object
     */
    public static function no_updates() {
        return (object) array(
            'last_checked' => time(),
            'checked'      => array(),
            'response'     => array(),
            'translations' => array(),
            'no_update'    => array(),
        );
    }

    /**
     * Cached WordPress update data with nothing to update.
     *
     * @return object
     */
    public static function no_core_updates() {
        return (object) array(
            'updates'         => array(),
            'version_checked' => function_exists('wp_get_wp_version') ? wp_get_wp_version() : $GLOBALS['wp_version'],
            'last_checked'    => time(),
            'translations'    => array(),
        );
    }

    /**
     * Drop Site Health tests that would only report the updates as broken.
     *
     * @param array $tests Tests.
     * @return array
     */
    public static function site_status_tests($tests) {
        if (self::$off['core']) {
            unset($tests['async']['background_updates']);
        }
        if (self::$off['plugins'] || self::$off['themes']) {
            unset($tests['direct']['plugin_theme_auto_updates']);
        }
        return $tests;
    }

    /**
     * Explain on Dashboard → Updates why nothing new is listed.
     */
    public static function updates_screen() {
        if (self::$off['core'] || self::$off['plugins'] || self::$off['themes']) {
            add_action(is_network_admin() ? 'network_admin_notices' : 'admin_notices', array(__CLASS__, 'updates_notice'));
        }
    }

    /**
     * The notice. `sps-keep` keeps it on the page when notices are hidden.
     */
    public static function updates_notice() {
        $link = '';
        if (current_user_can('manage_options') && class_exists('SEOProStack_Admin_Manager')) {
            $link = sprintf(' <a href="%s">%s</a>', esc_url(SEOProStack_Admin_Manager::tab_url('maintenance')), esc_html__('Change this', 'seoprostack'));
        }
        printf(
            '<div class="notice notice-warning sps-keep"><p>%s%s</p></div>',
            esc_html__('SEO Pro Stack has turned off some update checks, so this page may not list every new version.', 'seoprostack'),
            $link // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
        );
    }
}
