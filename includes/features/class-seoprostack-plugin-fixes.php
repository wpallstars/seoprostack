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
 * Tutor LMS Pro (checked with 4.1.0) asks tutorlms.com for updates. When
 * there is none, the reply is {"status": 200, "body_response": {}}, and its
 * updater reads the version, download address and tested version from the
 * empty object: three PHP warnings on every update check, in the debug log
 * and in WP-CLI output. The reply is completed with the installed version
 * (no download address), so Tutor LMS Pro sees no update, as intended. Its
 * licence check before updating still sees status 200, and replies that
 * offer an update are left alone.
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
                'description' => __('Works around bugs in other plugins that slow your site down, without changing their settings. Lasso Lite (Simple URLs) stops contacting its server on every admin screen, and Tutor LMS Pro stops filling the debug log with warnings when there is no update. Turn this off if a fix causes a problem.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks. Each filter belongs to the plugin it fixes, or checks
     * the request address first, and costs nothing when that plugin is not
     * active.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_filter('lasso_lite_estimate_earning_site_id', array(__CLASS__, 'lasso_site_id'));
        add_filter('http_response', array(__CLASS__, 'tutor_pro_no_update'), 10, 3);
    }

    /**
     * Tutor LMS Pro's "no update" reply, completed with the installed
     * version so its updater does not read missing fields.
     *
     * @param array|WP_Error $response HTTP response.
     * @param array          $args     Request arguments.
     * @param string         $url      Request address.
     * @return array|WP_Error
     */
    public static function tutor_pro_no_update($response, $args, $url) {
        if (!defined('TUTOR_PRO_VERSION') || !is_array($response) || !is_string($url)
            || !preg_match('#^https://tutorlms\.com/wp-json/themeum-products/v1/(plugin-update-status|check-update)$#', $url)
            || !isset($args['body']['product_slug']) || 'tutor-pro' !== strtolower((string) $args['body']['product_slug'])) {
            return $response;
        }
        $data = json_decode(wp_remote_retrieve_body($response));
        if (!is_object($data) || !isset($data->status) || 200 !== $data->status
            || !isset($data->body_response) || !is_object($data->body_response)
            || isset($data->body_response->version)) {
            return $response;
        }
        $data->body_response = (object) array_merge(
            array(
                'plugin_name'       => 'Tutor LMS Pro',
                'version'           => (string) TUTOR_PRO_VERSION,
                'download_url'      => '',
                'tested_wp_version' => '',
                'updated_at'        => '',
                'change_log'        => '',
            ),
            get_object_vars($data->body_response)
        );
        $response['body'] = (string) wp_json_encode($data);
        return $response;
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
