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
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
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
     * Option with the roles seen so far (to spot roles that plugins add) and
     * whether "Send them to" has been pointed at WooCommerce's My Account.
     */
    const STATE = 'seoprostack_access_roles';

    /** The role lists that new roles are ticked in. */
    const ROLE_KEYS = array('hide_admin_bar_roles', 'restrict_dashboard_roles');

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        // Administrators are never affected, so they are not offered.
        $roles   = array(__CLASS__, 'restrictable_role_options');
        $default = array_values(array_unique(array_merge(array('subscriber', 'customer'), self::visitor_roles())));
        $later   = __('Roles that plugins add later are ticked unless they can write posts.', 'seoprostack');
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
                'description' => __('Roles that do not see the admin bar.', 'seoprostack') . ' ' . $later,
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
                'description' => __('Roles that cannot open the dashboard.', 'seoprostack') . ' ' . $later,
                'options'     => $roles,
            ),
            'restrict_dashboard_redirect' => array(
                'type'        => 'url',
                'default'     => '',
                'parent'      => self::DASHBOARD_KEY,
                'label'       => __('Send them to', 'seoprostack'),
                'placeholder' => '/my-account/',
                'description' => __('A page on this site, such as /my-account/. Leave empty for the home page. With WooCommerce, its My Account page is filled in.', 'seoprostack'),
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
        // Even with both switches off, so the lists are right when switched on.
        self::sync_roles();

        if (self::enabled()) {
            add_filter('show_admin_bar', array(__CLASS__, 'filter_admin_bar'), 20);
        }
        if (SEOProStack_Settings::get(self::DASHBOARD_KEY) && !self::replaced_active(self::DASHBOARD_KEY)) {
            add_action('admin_init', array(__CLASS__, 'maybe_block_dashboard'), 0);
        }
    }

    /**
     * Roles that never need the admin: they can neither manage options nor
     * write posts, such as subscribers and customers.
     *
     * @return string[]
     */
    public static function visitor_roles() {
        $roles = array();
        foreach (wp_roles()->roles as $role => $data) {
            $caps = isset($data['capabilities']) ? (array) $data['capabilities'] : array();
            if (empty($caps['manage_options']) && empty($caps['edit_posts'])) {
                $roles[] = (string) $role;
            }
        }
        return $roles;
    }

    /**
     * Tick roles that plugins add in both role lists when they never need the
     * admin, and fill in "Send them to" with WooCommerce's My Account page.
     * Only writes when the roles change or WooCommerce first appears.
     */
    public static function sync_roles() {
        $state   = get_option(self::STATE, array());
        $state   = is_array($state) ? $state : array();
        $current = array_map('strval', array_keys(wp_roles()->roles));
        $known   = isset($state['roles']) && is_array($state['roles']) ? $state['roles'] : null;
        $changed = null === $known || array_diff($current, $known) || array_diff($known, $current);
        $shop    = empty($state['my_account']) && class_exists('WooCommerce');

        if (!$changed && !$shop) {
            return;
        }

        if ($changed) {
            $visitors = self::visitor_roles();
            // First run (new install, or an update from 0.4.0 or earlier):
            // lists still at the old default missed roles that plugins added
            // after they were saved, such as WooCommerce's Customer.
            $added = null === $known ? $visitors : array_intersect(array_diff($current, $known), $visitors);
            foreach (self::ROLE_KEYS as $key) {
                $value = (array) SEOProStack_Settings::get($key);
                if ($added && (null !== $known || self::is_old_default($value))) {
                    SEOProStack_Settings::set($key, array_merge($value, $added));
                }
            }
            $state['roles'] = $current;
        }
        if ($shop) {
            $state['my_account'] = self::default_to_my_account();
        }

        update_option(self::STATE, $state, true);
    }

    /**
     * Whether a role list holds a default from 0.4.0 or earlier.
     *
     * @param array $roles Role slugs.
     * @return bool
     */
    private static function is_old_default(array $roles) {
        $roles = array_map('strval', $roles);
        sort($roles);
        return array('subscriber') === $roles || array('customer', 'subscriber') === $roles;
    }

    /**
     * Fill in an empty "Send them to" with WooCommerce's My Account page.
     *
     * @return bool Whether this is settled: filled in now, or already set.
     */
    private static function default_to_my_account() {
        if ('' !== (string) SEOProStack_Settings::get('restrict_dashboard_redirect')) {
            return true;
        }
        $page = (int) get_option('woocommerce_myaccount_page_id');
        if (!$page || 'publish' !== get_post_status($page)) {
            // WooCommerce may still be making its pages; try again later.
            return false;
        }
        // A path from the home page, as maybe_block_dashboard() expects.
        $url  = (string) get_permalink($page);
        $home = home_url('/');
        if (0 !== strpos($url, $home)) {
            return true;
        }
        SEOProStack_Settings::set('restrict_dashboard_redirect', '/' . substr($url, strlen($home)));
        return true;
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
