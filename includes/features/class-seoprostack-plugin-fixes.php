<?php
/**
 * Fixes for other plugins.
 *
 * Works around bugs in other plugins that slow sites down, through those
 * plugins' own hooks. Nothing is stored and no other plugin's settings are
 * changed: switching this off brings each bug back as it was.
 *
 * Lasso Lite (Simple URLs) 159 saves the reply of lasso.link's
 * server-lite/getinfo, {"site_id": "…"}, as its site ID instead of the
 * 32-character ID inside it. Its own check then rejects the ID, so on every
 * admin request by an administrator (admin-ajax included) it asks lasso.link
 * again, which takes about 400 ms, and its one-time install report never
 * goes. Its `lasso_lite_estimate_earning_site_id` filter gets the ID from
 * the saved reply. Lasso Lite then sends its install report once and stops
 * asking; its other reports (each setup step once, a weekly earnings
 * estimate in the background) run as its makers intended.
 *
 * Freesoul Deactivate Plugins 2.6.9 and its PRO add-on (1.3.0.0, which
 * needs the free plugin) leave pieces behind when switched off. Deactivating
 * one left the other active, and PRO's own deactivation hook is registered
 * for a file that is not the plugin's, so it never runs. The free plugin
 * deletes its must-use file (mu-plugins/eos-deactivate-plugins.php) only on
 * single sites, and only when its own code runs during the deactivation; a
 * file left behind keeps skipping plugins by Freesoul's saved rules on single
 * sites and shows an error on every admin screen. Deactivating either one,
 * from any screen or WP-CLI, now deactivates both in the same place (this
 * site, or the network), and once neither is active anywhere the must-use
 * file is deleted, if it is Freesoul's. Activating the free plugin puts the
 * file back.
 *
 * Readabler 2.0.18 (and other Merkulove plugins built on the same "Unity"
 * code) asks its server for product information on every load of the
 * Plugins screen, waiting up to 60 seconds, even while it has the answer
 * cached for a day. When the request fails (a timeout, the server down, or
 * HTTP Requests Manager blocking it), it adds an admin notice that throws an
 * exception outside its own error handling, so the Plugins screen stops
 * with "There has been a critical error on this website". The request is
 * now skipped while its cached answer is there, and the throwing notice is
 * removed before it runs (Readabler already logs the failure itself).
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Plugin_Fixes extends SEOProStack_Feature {

    const KEY = 'plugin_fixes';

    /** Freesoul Deactivate Plugins: PRO first, as it needs the free plugin. */
    const FDP = array(
        'freesoul-deactivate-plugins-pro/freesoul-deactivate-plugins-pro.php',
        'freesoul-deactivate-plugins/freesoul-deactivate-plugins.php',
    );

    /** Freesoul's must-use file, in WPMU_PLUGIN_DIR. */
    const FDP_MU = 'eos-deactivate-plugins.php';

    /** Sites checked for Freesoul on a network; more than this counts as in use. */
    const FDP_MAX_SITES = 1000;

    /**
     * Where Freesoul was deactivated in this request: 'site' and/or 'network'.
     *
     * @var array<string, bool>
     */
    private static $fdp_scopes = array();

    /** Whether the shutdown step is running (it deactivates too). */
    private static $fdp_finishing = false;

    /** Merkulove "Unity" classes: {Plugin} is the plugin's own namespace part. */
    const UNITY = '/^Merkulove\\\\(\w+)\\\\Unity\\\\(UnityActions|EnvatoItem)$/';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request: each fix only undoes a bug.
                'default'     => true,
                'tab'         => 'plugins',
                'label'       => __('Fixes for other plugins', 'seoprostack'),
                'description' => __('Works around bugs in other plugins that slow your site down, without changing their settings. Lasso Lite (Simple URLs) stops contacting its server on every admin screen. Deactivating Freesoul Deactivate Plugins or its PRO add-on deactivates both and removes the must-use file it leaves behind. Readabler no longer contacts its server on every Plugins screen load, or stops that screen with a critical error when it cannot. Turn this off if a fix causes a problem.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks. Each filter belongs to the plugin it fixes and costs
     * nothing when that plugin is not active.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('lasso_lite_estimate_earning_site_id', array(__CLASS__, 'lasso_site_id'));
        add_action('deactivated_plugin', array(__CLASS__, 'fdp_deactivated'), 10, 2);
        add_action('deleted_plugin', array(__CLASS__, 'fdp_deleted'), 10, 2);
        // A file left by an earlier deactivation, or by removing the plugin
        // folders by hand.
        add_action('load-plugins.php', array(__CLASS__, 'fdp_remove_mu'));
        // Before Merkulove's own callbacks, which use priority 10.
        add_action('load-plugins.php', array(__CLASS__, 'unity_skip_request'), 0);
        add_action('admin_notices', array(__CLASS__, 'unity_drop_throwing_notice'), 0);
    }

    /**
     * Plugins screen: skip a Merkulove plugin's product information request
     * while its cached answer (kept for a day) is there.
     */
    public static function unity_skip_request() {
        global $wp_filter;
        if (!isset($wp_filter['load-plugins.php'])) {
            return;
        }
        foreach ($wp_filter['load-plugins.php']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                if (!is_array($function) || !is_object($function[0]) || 'load_plugins' !== $function[1]
                    || !preg_match(self::UNITY, get_class($function[0]), $match) || 'UnityActions' !== $match[2]) {
                    continue;
                }
                $item = 'Merkulove\\' . $match[1] . '\\Unity\\EnvatoItem';
                if (is_callable(array($item, 'plugin_info')) && call_user_func(array($item, 'plugin_info'))) {
                    remove_action('load-plugins.php', $function, $priority);
                }
            }
        }
    }

    /**
     * Remove the admin notice a Merkulove plugin adds when its product
     * information request fails: it throws an exception, which stops the
     * screen. The plugin logs the failure itself.
     */
    public static function unity_drop_throwing_notice() {
        global $wp_filter;
        if (!isset($wp_filter['admin_notices'])) {
            return;
        }
        foreach ($wp_filter['admin_notices']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if (!$callback['function'] instanceof Closure) {
                    continue;
                }
                $scope = (new ReflectionFunction($callback['function']))->getClosureScopeClass();
                if ($scope && preg_match(self::UNITY, $scope->getName(), $match) && 'EnvatoItem' === $match[2]) {
                    remove_action('admin_notices', $callback['function'], $priority);
                }
            }
        }
    }

    /**
     * Freesoul or its PRO add-on was deactivated. Core saves the list of
     * active plugins after this hook, so the other one is deactivated at
     * the end of the request.
     *
     * @param string $file         Plugin file.
     * @param bool   $network_wide Deactivated for the whole network.
     */
    public static function fdp_deactivated($file, $network_wide = false) {
        if (self::$fdp_finishing || !in_array($file, self::FDP, true)) {
            return;
        }
        if (!self::$fdp_scopes) {
            add_action('shutdown', array(__CLASS__, 'fdp_finish'), 0);
        }
        self::$fdp_scopes[$network_wide ? 'network' : 'site'] = true;
    }

    /**
     * End of a request that deactivated Freesoul or PRO: deactivate both in
     * the same place, then remove the must-use file if nothing uses it.
     */
    public static function fdp_finish() {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        self::$fdp_finishing = true;
        if (!empty(self::$fdp_scopes['network']) && is_multisite()) {
            $left = array_values(array_filter(self::FDP, 'is_plugin_active_for_network'));
            if ($left) {
                deactivate_plugins($left, false, true);
            }
        }
        if (!empty(self::$fdp_scopes['site'])) {
            self::load_plugin_state();
            $left = array_values(array_intersect(self::FDP, SEOProStack_Replaced_Plugins::stored_plugins()));
            if ($left) {
                deactivate_plugins($left);
            }
            // Freesoul guards saves of the list, so make sure they are saved.
            foreach (self::FDP as $file) {
                SEOProStack_Replaced_Plugins::save_plugin_state($file, false);
            }
        }
        self::$fdp_finishing = false;
        self::$fdp_scopes    = array();
        self::fdp_remove_mu();
    }

    /**
     * Freesoul or PRO deleted: remove the must-use file if nothing uses it.
     *
     * @param string $file    Plugin file.
     * @param bool   $deleted Whether the files were deleted.
     */
    public static function fdp_deleted($file, $deleted) {
        if ($deleted && in_array($file, self::FDP, true)) {
            self::fdp_remove_mu();
        }
    }

    /**
     * Delete Freesoul's must-use file when neither Freesoul plugin is active
     * anywhere on the install. Only a file that says it is Freesoul's.
     */
    public static function fdp_remove_mu() {
        $path = WPMU_PLUGIN_DIR . '/' . self::FDP_MU;
        if (!is_file($path) || (!current_user_can('activate_plugins') && !(defined('WP_CLI') && WP_CLI))) {
            return;
        }
        $head = (string) file_get_contents($path, false, null, 0, 1024); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file's header.
        if (false === stripos($head, 'freesoul deactivate plugins') || self::fdp_in_use()) {
            return;
        }
        wp_delete_file($path);
    }

    /**
     * Whether Freesoul or PRO is active on this site, for the network, or on
     * any site of the network, as stored (not as filtered for this request).
     *
     * @return bool
     */
    private static function fdp_in_use() {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        self::load_plugin_state();
        if (!is_multisite()) {
            return (bool) array_intersect(self::FDP, SEOProStack_Replaced_Plugins::stored_plugins());
        }
        if (array_filter(self::FDP, 'is_plugin_active_for_network')) {
            return true;
        }
        $sites = get_sites(array('fields' => 'ids', 'number' => self::FDP_MAX_SITES + 1));
        if (count($sites) > self::FDP_MAX_SITES) {
            return true;
        }
        foreach ($sites as $site_id) {
            switch_to_blog($site_id);
            $used = (bool) array_intersect(self::FDP, SEOProStack_Replaced_Plugins::stored_plugins());
            restore_current_blog();
            if ($used) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reading and saving the stored list of active plugins past other
     * plugins' filters (admin requests load it already; WP-CLI does not).
     */
    private static function load_plugin_state() {
        if (!class_exists('SEOProStack_Replaced_Plugins')) {
            require_once SEOPROSTACK_DIR . 'admin/includes/class-replaced-plugins.php';
        }
    }

    /**
     * Lasso Lite's site ID, taken from the saved server reply when Lasso
     * Lite saved the reply instead of the ID.
     *
     * @param mixed $site_id Site ID as Lasso Lite read it ('' when unusable).
     * @return mixed
     */
    public static function lasso_site_id($site_id) {
        if ('' !== $site_id) {
            return $site_id;
        }
        $settings = get_option('lassolite_settings');
        $saved    = is_array($settings) && isset($settings['site_id']) ? $settings['site_id'] : null;
        if (is_object($saved)) {
            $saved = get_object_vars($saved);
        }
        if (is_array($saved) && isset($saved['site_id']) && is_string($saved['site_id'])
            && preg_match('/^[a-f0-9]{32}$/i', $saved['site_id'])) {
            return strtolower($saved['site_id']);
        }
        return $site_id;
    }
}
