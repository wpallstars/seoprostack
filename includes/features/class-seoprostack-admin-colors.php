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
     * User option (per site) marking someone who picked their own scheme
     * while the setting was on and who cannot change SEO Pro Stack's
     * settings: their choice applies to them until the setting is turned on
     * again.
     */
    const OWN_CHOICE = 'seoprostack_own_admin_color';

    /** Set while a profile change turns the setting off (sync_current_user()). */
    private static $from_profile = false;

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
                'description' => __('Use the WordPress “Modern” admin colour scheme for everyone. Your profile is set to Modern when on and back to the WordPress default when off; other users keep their own choice. Picking another scheme in your own profile turns this off; people who cannot change these settings get the scheme they pick, until this is turned on again.', 'seoprostack'),
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
            add_filter('get_user_option_admin_color', array(__CLASS__, 'filter_admin_color'), 10, 3);
            // Before the save, so it also runs when the stored value is
            // unchanged (a scheme picked before the setting was turned on,
            // hidden by filter_admin_color()), and when none is stored yet.
            // Covers the profile form and the picker's live (AJAX) save.
            add_filter('update_user_metadata', array(__CLASS__, 'own_choice'), 10, 4);
        }
    }

    /**
     * Force the modern scheme in the admin, except for people who picked
     * their own while it was on (own_choice()).
     *
     * @param string|false $color Stored scheme.
     * @param string       $option Option name.
     * @param WP_User|null $user   User the scheme is for.
     * @return string|false
     */
    public static function filter_admin_color($color, $option = 'admin_color', $user = null) {
        if (!is_admin() || !self::enabled()) {
            return $color;
        }
        if ($user instanceof WP_User && get_user_option(self::OWN_CHOICE, $user->ID)) {
            return $color;
        }
        return self::SCHEME;
    }

    /**
     * Someone picked another scheme in their own profile while the setting
     * is on. If they may change SEO Pro Stack's settings, turn it off so
     * their choice shows for them (and everyone gets their own scheme back);
     * otherwise let their choice apply to them only.
     *
     * @param null|bool $check      Short-circuit value from other filters.
     * @param int       $user_id    User being changed.
     * @param string    $meta_key   Meta key.
     * @param mixed     $meta_value New value.
     * @return null|bool $check, unchanged: the save goes ahead.
     */
    public static function own_choice($check, $user_id, $meta_key, $meta_value) {
        if ('admin_color' !== $meta_key || self::$from_profile || !self::enabled()) {
            return $check;
        }
        $user_id = (int) $user_id;
        $scheme  = is_string($meta_value) ? $meta_value : '';
        if (self::SCHEME === $scheme || '' === $scheme || $user_id !== get_current_user_id()) {
            return $check;
        }
        if (SEOProStack_Settings::can_change()) {
            self::$from_profile = true;
            $saved = SEOProStack_Settings::set(self::KEY, false);
            if (!is_wp_error($saved)) {
                /** This action is documented in includes/class-seoprostack-settings.php */
                do_action('seoprostack_setting_saved', self::KEY, $saved);
            }
            self::$from_profile = false;
        } else {
            update_user_option($user_id, self::OWN_CHOICE, 1);
        }
        return $check;
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
        if ($value) {
            // Modern for everyone again, including people who had picked
            // their own scheme meanwhile (own_choice()).
            global $wpdb;
            delete_metadata('user', 0, $wpdb->get_blog_prefix() . self::OWN_CHOICE, '', true);
        }
        // Turned off from the profile: keep the scheme just picked.
        if (self::$from_profile) {
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
