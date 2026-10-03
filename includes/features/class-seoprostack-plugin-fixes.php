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
 * Tutor LMS Pro's updater (checked with 4.1.0) reads the version, download
 * address and tested WordPress version from any update reply with status
 * 200, even one whose details are empty, as the "no update" reply of the
 * copies sold by GPL resellers is: six PHP warnings and deprecation notices
 * on every update check, in the debug log and WP-CLI output. Such a reply,
 * from the server or from a filter on `pre_http_request`, is completed with
 * the installed version and no download address, so the updater sees no
 * update, as the reply meant. Status stays 200 (its licence check before
 * updating reads it), and replies that offer an update are left alone.
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
                'description' => __('Works around bugs in other plugins that slow your site down, without changing their settings. Lasso Lite (Simple URLs) stops contacting its server on every admin screen, and Tutor LMS Pro stops adding warnings to the debug log when there is no update. Turn this off if a fix causes a problem.', 'seoprostack'),
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
        // Replies from the server, and replies a filter made up instead
        // (WordPress returns those without the http_response filter).
        add_filter('http_response', array(__CLASS__, 'tutor_pro_no_update'), 10, 3);
        add_filter('pre_http_request', array(__CLASS__, 'tutor_pro_no_update'), PHP_INT_MAX, 3);
    }

    /**
     * Tutor LMS Pro's "no update" reply, completed with the installed
     * version so its updater does not read missing details.
     *
     * @param mixed  $response HTTP response (false from pre_http_request when no filter answered).
     * @param array  $args     Request arguments.
     * @param string $url      Request address.
     * @return mixed
     */
    public static function tutor_pro_no_update($response, $args, $url) {
        if (!is_array($response) || !isset($response['body']) || !is_string($response['body'])
            || !defined('TUTOR_PRO_VERSION') || !is_string($url)
            || !preg_match('#/themeum-products/v1/(plugin-update-status|check-update)/?$#', $url)) {
            return $response;
        }
        $body = isset($args['body']) ? $args['body'] : array();
        if (is_string($body)) {
            parse_str($body, $body);
        }
        if (!is_array($body) || !isset($body['product_slug']) || 'tutor-pro' !== strtolower((string) $body['product_slug'])) {
            return $response;
        }
        $data = json_decode($response['body']);
        if (!is_object($data) || !isset($data->status) || 200 !== (int) $data->status
            || !isset($data->body_response) || isset($data->body_response->version)
            || !(is_object($data->body_response) || array() === $data->body_response)) {
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
            (array) $data->body_response
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
