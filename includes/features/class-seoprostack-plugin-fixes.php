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
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Plugin_Fixes extends SEOProStack_Feature {

    const KEY = 'plugin_fixes';

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
                'description' => __('Works around bugs in other plugins that slow your site down, without changing their settings. Lasso Lite (Simple URLs) stops contacting its server on every admin screen. Turn this off if a fix causes a problem.', 'seoprostack'),
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
