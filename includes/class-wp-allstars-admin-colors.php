<?php
/**
 * WP Allstars modern admin colours.
 *
 * While `modern_admin_colors` is on, every user sees the core "Modern" scheme
 * (including in the Profile colour picker). Toggling the setting also writes
 * the choice to the toggling user's profile: on selects "Modern", off selects
 * the WordPress default ("Default"/fresh). Other users' saved preferences are
 * never modified and return when the setting is turned off.
 *
 * @package WP_ALLSTARS
 * @since 0.2.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Admin_Colors {

    /** Scheme applied while enabled. */
    const SCHEME = 'modern';

    /** WordPress default scheme. */
    const DEFAULT_SCHEME = 'fresh';

    /**
     * Register hooks.
     */
    public function __construct() {
        add_filter('get_user_option_admin_color', array($this, 'filter_admin_color'));
        add_action('wp_allstars_setting_saved', array($this, 'sync_current_user'), 10, 2);
    }

    /**
     * Whether the override is on.
     *
     * @return bool
     */
    public static function is_enabled() {
        return (bool) WP_Allstars_Settings::get('modern_admin_colors');
    }

    /**
     * Force the modern scheme when enabled.
     *
     * @param string|false $color Stored scheme.
     * @return string|false
     */
    public function filter_admin_color($color) {
        if (!is_admin() || !self::is_enabled()) {
            return $color;
        }
        return self::SCHEME;
    }

    /**
     * Mirror the switch into the current user's profile preference.
     *
     * @param string $key   Setting key.
     * @param mixed  $value Sanitized value.
     */
    public function sync_current_user($key, $value) {
        if ('modern_admin_colors' !== $key) {
            return;
        }
        $user_id = get_current_user_id();
        if ($user_id && current_user_can('edit_user', $user_id)) {
            update_user_meta($user_id, 'admin_color', $value ? self::SCHEME : self::DEFAULT_SCHEME);
        }
    }

    /**
     * Scheme names and stylesheet URLs for the live switch in the settings screen.
     * An empty URL means the scheme ships in core CSS ("fresh").
     *
     * @return array{enabled:array{name:string,url:string},disabled:array{name:string,url:string}}
     */
    public static function scheme_urls() {
        global $_wp_admin_css_colors;

        $describe = function ($scheme) use ($_wp_admin_css_colors) {
            $url = (self::DEFAULT_SCHEME !== $scheme && isset($_wp_admin_css_colors[$scheme]))
                ? (string) $_wp_admin_css_colors[$scheme]->url
                : '';
            return array('name' => $scheme, 'url' => $url);
        };

        return array(
            'enabled'  => $describe(self::SCHEME),
            'disabled' => $describe(self::DEFAULT_SCHEME),
        );
    }
}
