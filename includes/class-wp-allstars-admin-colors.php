<?php
/**
 * WP Allstars modern admin colours.
 *
 * Overrides the admin colour scheme at read time while the
 * `modern_admin_colors` setting is on. Users' own `admin_color` meta is never
 * modified, so turning the setting off restores everyone's choice.
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

    /**
     * Register hooks.
     */
    public function __construct() {
        add_filter('get_user_option_admin_color', array($this, 'filter_admin_color'));
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
     * The profile screens are skipped so users can still see and change
     * their own preference.
     *
     * @param string|false $color Stored scheme.
     * @return string|false
     */
    public function filter_admin_color($color) {
        if (!is_admin() || !self::is_enabled()) {
            return $color;
        }

        global $pagenow;
        if (in_array($pagenow, array('profile.php', 'user-edit.php'), true)) {
            return $color;
        }

        return self::SCHEME;
    }

    /**
     * Scheme names and stylesheet URLs for the live switch in the settings screen.
     * An empty URL means the scheme ships in core CSS (e.g. "fresh").
     *
     * @return array{enabled:array{name:string,url:string},disabled:array{name:string,url:string}}
     */
    public static function scheme_urls() {
        global $_wp_admin_css_colors;

        $user_scheme = get_user_meta(get_current_user_id(), 'admin_color', true);
        if (empty($user_scheme) || !isset($_wp_admin_css_colors[$user_scheme])) {
            $user_scheme = 'fresh';
        }

        $describe = function ($scheme) use ($_wp_admin_css_colors) {
            $url = isset($_wp_admin_css_colors[$scheme]) ? (string) $_wp_admin_css_colors[$scheme]->url : '';
            return array('name' => $scheme, 'url' => $url);
        };

        return array(
            'enabled'  => $describe(self::SCHEME),
            'disabled' => $describe($user_scheme),
        );
    }
}
