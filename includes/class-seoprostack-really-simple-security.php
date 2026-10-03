<?php
/**
 * Explicit Really Simple Security preset saves, verified against 9.8.3.
 *
 * The native atomic writer owns the rules. Never write .htaccess ourselves,
 * bypass a plugin lockout, or change anything on an ordinary request.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Really_Simple_Security {

    const SLUG   = 'really-simple-ssl';
    const OPTION = 'rsssl_options';
    const MARKER = 'Really Simple Security Redirect';

    /** Register the conditional preset and its error-returning native writer. */
    public static function init() {
        add_filter('seoprostack_preset_condition', array(__CLASS__, 'condition'), 10, 2);
        add_filter('seoprostack_preset_write_check', array(__CLASS__, 'check'), 10, 2);
        add_filter('seoprostack_preset_store', array(__CLASS__, 'store'), 10, 4);
    }

    /**
     * Check local prerequisites without network requests or writes.
     *
     * @return WP_Error|null Explanation, or null when ready.
     */
    private static function readiness() {
        if (is_multisite() || !defined('rsssl_version') || '9.8.3' !== rsssl_version || !function_exists('RSSSL') || !function_exists('rsssl_update_option') || !function_exists('rsssl_get_option') || !function_exists('rsssl_user_can_manage') || !function_exists('rsssl_should_skip_managed_htaccess_writes')) {
            return new WP_Error('seoprostack_rsssl_api', __('Use Really Simple Security’s own settings: this preset requires its active, supported 9.8.3 save API on a single site.', 'seoprostack'));
        }
        $plugin = RSSSL();
        if (!isset($plugin->admin, $plugin->server) || !is_callable(array($plugin->admin, 'htaccess_redirect_allowed')) || !is_callable(array($plugin->admin, 'get_redirect_rules')) || !isset($plugin->admin->htaccess_file_manager)) {
            return new WP_Error('seoprostack_rsssl_api', __('Really Simple Security’s save API is unavailable on this request. Open its settings first.', 'seoprostack'));
        }
        $manager = $plugin->admin->htaccess_file_manager;
        foreach (array('get_root_htaccess_target_path', 'get_rule_content_for_path', 'get_rule_lines_for_path', 'is_valid_htaccess_file_path') as $method) {
            if (!is_callable(array($manager, $method))) {
                return new WP_Error('seoprostack_rsssl_api', __('Really Simple Security’s rule-file API is unsupported.', 'seoprostack'));
            }
        }
        if (!$plugin->admin->htaccess_redirect_allowed() || !rsssl_get_option('ssl_enabled')) {
            return new WP_Error('seoprostack_rsssl_server', __('Enable working HTTPS in Really Simple Security first. This preset needs a supported Apache or LiteSpeed setup.', 'seoprostack'));
        }
        $path = $manager->get_root_htaccess_target_path();
        if (rsssl_should_skip_managed_htaccess_writes() || !$path || !$manager->is_valid_htaccess_file_path($path) || is_link($path) || !is_file($path) || !wp_is_writable($path)) {
            return new WP_Error('seoprostack_rsssl_file', __('Allow Really Simple Security to write an existing, writable root .htaccess before using this preset.', 'seoprostack'));
        }
        return null;
    }

    /**
     * Resolve the JSON condition, without probing TLS on page views.
     *
     * @param bool   $holds Existing answer.
     * @param string $condition Condition name.
     * @return bool
     */
    public static function condition($holds, $condition) {
        return 'rsssl_htaccess_ready' === $condition ? null === self::readiness() : $holds;
    }

    /**
     * Return a useful error even when the conditional preset is unavailable.
     *
     * @param mixed  $error Existing error.
     * @param string $slug Plugin folder.
     * @return mixed
     */
    public static function check($error, $slug) {
        return self::SLUG === $slug && null === $error ? self::readiness() : $error;
    }

    /**
     * Save only redirect, preserving current unrelated settings even on undo.
     * Failures leave the engine's previous undo snapshot intact.
     *
     * @param mixed  $handled Previous writer result.
     * @param string $slug Plugin folder.
     * @param string $name Option name.
     * @param mixed  $value Merged target value (null for fresh defaults).
     * @return mixed True when handled, WP_Error on failure, otherwise unchanged.
     */
    public static function store($handled, $slug, $name, $value) {
        if (null !== $handled || self::SLUG !== $slug || self::OPTION !== $name) {
            return $handled;
        }
        $error = self::readiness();
        if (is_wp_error($error)) {
            return $error;
        }
        if (!function_exists('rsssl_user_can_manage') || !rsssl_user_can_manage()) {
            return new WP_Error('seoprostack_rsssl_permission', __('Run this action as a user who can manage Really Simple Security (WP-CLI: --user=<administrator>).', 'seoprostack'));
        }
        $wanted = is_array($value) && array_key_exists('redirect', $value) ? $value['redirect'] : null;
        if (!in_array($wanted, array(null, false, '', 'none', 'wp_redirect', 'htaccess'), true)) {
            return new WP_Error('seoprostack_rsssl_value', __('The saved redirect method is unsupported; restore it in Really Simple Security.', 'seoprostack'));
        }
        // Bounded, certificate-verified requests, only for an actual mutation.
        if (!self::https_works()) {
            return new WP_Error('seoprostack_rsssl_tls', __('HTTPS could not be verified. Check the certificate and HTTPS site address before changing the redirect.', 'seoprostack'));
        }
        $before = get_option(self::OPTION, array());
        $before = is_array($before) ? $before : array();
        $old    = array_key_exists('redirect', $before) ? $before['redirect'] : null;
        try {
            $saved = self::save($wanted) && self::https_works();
        } catch (Throwable $error) {
            $saved = false;
        }
        if ($saved) {
            return true;
        }
        try {
            $restored = self::save($old) && self::https_works();
        } catch (Throwable $error) {
            $restored = false;
        }
        return new WP_Error($restored ? 'seoprostack_rsssl_restored' : 'seoprostack_rsssl_save', $restored
            ? __('Really Simple Security could not reconcile its redirect rules. The previous redirect preference and rules were restored; no undo copy was replaced.', 'seoprostack')
            : __('Redirect save and rollback are incomplete. Check Really Simple Security’s redirect setting and root .htaccess immediately; the previous undo copy was kept.', 'seoprostack'));
    }

    /**
     * Allow up to three same-origin HTTPS redirects (such as language pages).
     * Never follow a downgrade, a different host/port, or an endless loop.
     *
     * @return bool
     */
    private static function https_works() {
        $url    = set_url_scheme(home_url('/'), 'https');
        $origin = wp_parse_url($url);
        if (!is_array($origin) || empty($origin['host'])) {
            return false;
        }
        for ($hop = 0; $hop <= 3; $hop++) {
            $response = wp_remote_get($url, array('timeout' => 3, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 1024));
            $status   = wp_remote_retrieve_response_code($response);
            if (is_wp_error($response)) {
                return false;
            }
            if ($status >= 200 && $status < 300) {
                return true;
            }
            $location = wp_remote_retrieve_header($response, 'location');
            if (!in_array($status, array(301, 302, 303, 307, 308), true) || !is_string($location) || '' === $location) {
                return false;
            }
            $url  = WP_Http::make_absolute_url($location, $url);
            $next = wp_parse_url($url);
            if (!is_array($next) || 'https' !== ($next['scheme'] ?? '') || strtolower($next['host'] ?? '') !== strtolower($origin['host']) || ($next['port'] ?? 443) !== ($origin['port'] ?? 443) || isset($next['user']) || isset($next['pass'])) {
                return false;
            }
        }
        return false;
    }

    /**
     * Use the verified native save and confirm its dedicated marker agrees.
     *
     * @param mixed $redirect Redirect preference; null means not stored.
     * @return bool
     */
    private static function save($redirect) {
        if (!function_exists('rsssl_update_option') || !function_exists('RSSSL')) {
            return false;
        }
        rsssl_update_option('redirect', is_string($redirect) ? $redirect : 'none');
        // Native select sanitisation turns false into ''. Keep the original
        // representation after reconciling its equivalent "No redirect" rules.
        if (null === $redirect || false === $redirect) {
            $options = get_option(self::OPTION, array());
            if (is_array($options)) {
                if (null === $redirect) {
                    unset($options['redirect']);
                } else {
                    $options['redirect'] = false;
                }
                if ($options) {
                    update_option(self::OPTION, $options);
                } else {
                    delete_option(self::OPTION);
                }
            }
        }
        $options = get_option(self::OPTION, array());
        $stored  = is_array($options) && array_key_exists('redirect', $options) ? $options['redirect'] : null;
        $manager = RSSSL()->admin->htaccess_file_manager;
        $block   = $manager->get_rule_content_for_path($manager->get_root_htaccess_target_path(), self::MARKER);
        if ($stored !== $redirect) {
            return false;
        }
        if ('htaccess' !== $redirect) {
            return null === $block || '' === trim($block);
        }
        $rules = RSSSL()->admin->get_redirect_rules();
        $lines = $manager->get_rule_lines_for_path($manager->get_root_htaccess_target_path(), self::MARKER);
        return is_string($block) && '' !== trim($rules) && trim(implode("\n", $lines)) === trim($rules);
    }
}
