<?php
/**
 * Modern admin colours.
 *
 * While on, every user sees the core "Modern" scheme (including in the
 * Profile colour picker). Toggling the setting also writes the choice to the
 * toggling user's profile: on selects "Modern", off selects the WordPress
 * default. Other users' saved preferences are never modified and return when
 * the setting is turned off.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.2.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Colors extends SEOProStack_Feature {

    const KEY = 'modern_admin_colors';

    /** Scheme applied while enabled. */
    const SCHEME = 'modern';

    /** WordPress default scheme. */
    const DEFAULT_SCHEME = 'fresh';

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
                'tab'         => 'admin',
                'label'       => __('Modern admin colours', 'seoprostack'),
                'description' => __('Use the WordPress “Modern” admin colour scheme for everyone. Your profile is set to Modern when on and back to the WordPress default when off; other users keep their own choice.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        // Always listen for the switch so turning it off can reset the profile.
        add_action('seoprostack_setting_saved', array(__CLASS__, 'sync_current_user'), 10, 2);

        if (self::enabled()) {
            add_filter('get_user_option_admin_color', array(__CLASS__, 'filter_admin_color'));
        }
    }

    /**
     * Force the modern scheme in the admin.
     *
     * @param string|false $color Stored scheme.
     * @return string|false
     */
    public static function filter_admin_color($color) {
        if (!is_admin() || !self::enabled()) {
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
    public static function sync_current_user($key, $value) {
        if (self::KEY !== $key) {
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
