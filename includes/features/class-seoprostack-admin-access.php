<?php
/**
 * Admin bar and dashboard access by role.
 *
 * Two independent switches:
 * - hide the front-end admin bar for chosen roles;
 * - send chosen roles away from wp-admin (background requests keep working).
 *
 * People who can manage options are never affected. Replaces
 * "Admin Bar & Dashboard Access Control" and the v0.2.5 Access Manager;
 * both plugins' settings are imported once.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Access extends SEOProStack_Feature {

    const KEY = 'hide_admin_bar';

    /** Dashboard switch. */
    const DASHBOARD_KEY = 'restrict_dashboard';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        // Administrators are never affected, so they are not offered.
        $roles   = array(__CLASS__, 'restrictable_role_options');
        $default = array('subscriber', 'customer');
        $replace = array('admin-bar-dashboard-control' => 'Admin Bar & Dashboard Access Control');

        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('Hide the admin bar', 'seoprostack'),
                'description' => __('Hide the toolbar on the front end for roles that never need the admin, such as subscribers and customers. Administrators always keep it.', 'seoprostack'),
                'replaces'    => $replace,
            ),
            'hide_admin_bar_roles' => array(
                'type'        => 'multi',
                'default'     => $default,
                'parent'      => self::KEY,
                'label'       => __('Hide it for', 'seoprostack'),
                'description' => __('Roles that do not see the admin bar.', 'seoprostack'),
                'options'     => $roles,
            ),
            self::DASHBOARD_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('Block dashboard access', 'seoprostack'),
                'description' => __('Send chosen roles to the site when they open wp-admin. Forms, uploads and other background requests keep working. Administrators are never blocked.', 'seoprostack'),
                'replaces'    => $replace,
            ),
            'restrict_dashboard_roles' => array(
                'type'        => 'multi',
                'default'     => $default,
                'parent'      => self::DASHBOARD_KEY,
                'label'       => __('Block it for', 'seoprostack'),
                'description' => __('Roles that cannot open the dashboard.', 'seoprostack'),
                'options'     => $roles,
            ),
            'restrict_dashboard_redirect' => array(
                'type'        => 'url',
                'default'     => '',
                'parent'      => self::DASHBOARD_KEY,
                'label'       => __('Send them to', 'seoprostack'),
                'placeholder' => '/my-account/',
                'description' => __('A page on this site, such as /my-account/. Leave empty for the home page.', 'seoprostack'),
            ),
        );
    }

    /**
     * Import settings from Admin Bar & Dashboard Access Control and the
     * v0.2.5 Access Manager.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $abdc = get_option('abdc_options');
        if (is_array($abdc)) {
            $options = self::import_setting($options, self::KEY, (isset($abdc['disable_admin_bar']) && 'yes' === $abdc['disable_admin_bar']) ? true : null);
            $options = self::import_setting($options, 'hide_admin_bar_roles', isset($abdc['disable_admin_bar_roles']) ? (array) $abdc['disable_admin_bar_roles'] : null);
            $options = self::import_setting($options, self::DASHBOARD_KEY, (isset($abdc['disable_dashboard_access']) && 'yes' === $abdc['disable_dashboard_access']) ? true : null);
            $options = self::import_setting($options, 'restrict_dashboard_roles', isset($abdc['disable_dashboard_access_roles']) ? (array) $abdc['disable_dashboard_access_roles'] : null);
            $options = self::import_setting($options, 'restrict_dashboard_redirect', !empty($abdc['dashboard_redirect_url']) ? $abdc['dashboard_redirect_url'] : null);
        }

        $legacy = array(
            'wp_allstars_hide_admin_bar'            => self::KEY,
            'wp_allstars_hide_admin_bar_roles'      => 'hide_admin_bar_roles',
            'wp_allstars_restrict_dashboard'        => self::DASHBOARD_KEY,
            'wp_allstars_restrict_dashboard_roles'  => 'restrict_dashboard_roles',
        );
        foreach ($legacy as $old => $key) {
            $options = self::import_setting($options, $key, get_option($old, null));
        }

        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (self::enabled()) {
            add_filter('show_admin_bar', array(__CLASS__, 'filter_admin_bar'), 20);
        }
        if (SEOProStack_Settings::get(self::DASHBOARD_KEY) && !self::replaced_active(self::DASHBOARD_KEY)) {
            add_action('admin_init', array(__CLASS__, 'maybe_block_dashboard'), 0);
        }
    }

    /**
     * Hide the admin bar for the chosen roles.
     *
     * @param bool $show Whether to show the admin bar.
     * @return bool
     */
    public static function filter_admin_bar($show) {
        if ($show && self::current_user_in_roles(SEOProStack_Settings::get('hide_admin_bar_roles'))) {
            return false;
        }
        return $show;
    }

    /**
     * Redirect the chosen roles away from wp-admin screens.
     */
    public static function maybe_block_dashboard() {
        global $pagenow;

        if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        // Form handlers and media uploads used by front-end forms.
        if (in_array($pagenow, array('admin-post.php', 'async-upload.php'), true)) {
            return;
        }
        if (!self::current_user_in_roles(SEOProStack_Settings::get('restrict_dashboard_roles'))) {
            return;
        }

        $target = (string) SEOProStack_Settings::get('restrict_dashboard_redirect');
        if ('' !== $target && '/' === $target[0]) {
            $target = home_url($target);
        }

        /**
         * Filter where blocked users are sent.
         *
         * @param string  $target Redirect URL.
         * @param WP_User $user   Current user.
         */
        $target = (string) apply_filters('seoprostack_dashboard_redirect', $target ? $target : home_url('/'), wp_get_current_user());

        wp_safe_redirect($target);
        exit;
    }
}
