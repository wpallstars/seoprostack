<?php
/**
 * Developer admins: choose which administrators are developers, and keep
 * everyone else (client administrators) away from changes that can break
 * the site or lock people out.
 *
 * Developers are super admins on multisite, and the administrators ticked
 * under Developers on single sites. Nobody can be locked out: the person
 * who switches this on or saves the list is always kept on it, and when
 * none of the people on it is still an administrator, every administrator
 * is a developer. WP-CLI and cron run as nobody and are never limited.
 *
 * Chosen in "Only developers can" (each one a capability taken away with
 * map_meta_cap, or a core setting kept as it is):
 * - code:     install, upload, delete and edit plugins and themes;
 * - activate: switch plugins on or off, and change the theme;
 * - admins:   make administrators (also as the default role for new
 *             accounts), change administrators' roles, and edit or delete
 *             developers;
 * - site:     the WordPress and site addresses and the administration
 *             email, which gets fatal error and recovery mode emails;
 * - search:   permalinks and search engine visibility;
 * - updates:  update WordPress, plugins, themes and translations;
 * - html:     unfiltered HTML, such as scripts, in content.
 *
 * Always, while on: SEO Pro Stack's settings, its admin bar star and its
 * row on the Plugins screen are only for developers, and nobody else can
 * deactivate it. Organise the admin menu adds the Developers menu and the
 * developer plugins placed in it.
 *
 * Until 0.13.0 this was "Client safeguards" in Organise the admin menu;
 * its switch and developer list are imported (settings version 22).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.13.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Developers extends SEOProStack_Feature {

    const KEY = 'developers';

    /** Setting: developer accounts (single sites). */
    const USERS_KEY = 'developers_users';

    /** Setting: what only developers can do. */
    const LIMITS_KEY = 'developers_limits';

    /** Capabilities each choice takes away from people who are not developers. */
    const LIMIT_CAPS = array(
        'code'     => array(
            'install_plugins',
            'upload_plugins',
            'delete_plugins',
            'edit_plugins',
            'install_themes',
            'upload_themes',
            'delete_themes',
            'edit_themes',
            'edit_files',
        ),
        // Per plugin (meta capabilities), so the Plugins screen and its
        // updates stay open; core checks them for each link and bulk action.
        'activate' => array('activate_plugin', 'deactivate_plugin', 'switch_themes'),
        'updates'  => array('update_core', 'update_plugins', 'update_themes', 'update_languages'),
        'html'     => array('unfiltered_html'),
    );

    /** Core settings each choice keeps as they are: option => settings group. */
    const LIMIT_OPTIONS = array(
        'site'   => array(
            'siteurl'         => 'general',
            'home'            => 'general',
            'admin_email'     => 'general',
            'new_admin_email' => 'general',
        ),
        'search' => array(
            'blog_public'        => 'reading',
            // Saved by options-permalink.php itself, not through options.php.
            'permalink_structure' => '',
            'category_base'       => '',
            'tag_base'            => '',
        ),
    );

    /** Settings screens with fields only developers can change => choice. */
    const SCREENS = array(
        'options-general'   => 'site',
        'options-reading'   => 'search',
        'options-permalink' => 'search',
    );

    /**
     * Capabilities taken away, as cap => true (filled in boot()).
     *
     * @var array<string,bool>
     */
    private static $blocked = array();

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        $settings = array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('Developer admins', 'seoprostack'),
                'description' => __('Choose which administrators are developers. Other administrators, such as clients, cannot make the changes chosen below, nor see or change SEO Pro Stack. You are always kept on the list, so you cannot lock yourself out.', 'seoprostack'),
                'reload'      => true,
            ),
        );

        if (!is_multisite()) {
            $settings[self::USERS_KEY] = array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Developers', 'seoprostack'),
                'description' => __('Administrators who are not limited. When you switch this on, you are ticked; tick anyone else who builds or looks after the site. If nobody ticked is still an administrator, every administrator is a developer.', 'seoprostack'),
                'options'     => array(__CLASS__, 'developer_options'),
            );
        }

        $settings[self::LIMITS_KEY] = array(
            'type'        => 'multi',
            'default'     => array('code', 'activate', 'admins', 'site', 'search'),
            'parent'      => self::KEY,
            'reload'      => true,
            'label'       => __('Only developers can', 'seoprostack'),
            'description' => is_multisite()
                ? __('Developers are the network’s super admins.', 'seoprostack')
                : __('Everyone else, administrators included, cannot.', 'seoprostack'),
            'options'     => array(__CLASS__, 'limit_options'),
        );

        return $settings;
    }

    /**
     * What only developers can do.
     *
     * @return array<string,string>
     */
    public static function limit_options() {
        return array(
            'code'     => __('Install, delete or edit the code of plugins and themes', 'seoprostack'),
            'activate' => __('Switch plugins on or off and change the theme', 'seoprostack'),
            'admins'   => __('Make administrators, and edit or delete developers', 'seoprostack'),
            'site'     => __('Change the site’s addresses and administration email', 'seoprostack'),
            'search'   => __('Change permalinks and search engine visibility', 'seoprostack'),
            'updates'  => __('Update WordPress, plugins, themes and translations', 'seoprostack'),
            'html'     => __('Add unfiltered HTML, such as scripts, to content', 'seoprostack'),
        );
    }

    /**
     * Administrators who can be developers.
     *
     * @return array<string,string> User ID => name.
     */
    public static function developer_options() {
        $options = array();
        $users   = get_users(array(
            'capability' => 'manage_options',
            'orderby'    => 'display_name',
            'number'     => 200,
            'fields'     => array('ID', 'display_name', 'user_login'),
        ));
        foreach ($users as $user) {
            $options[(string) $user->ID] = $user->display_name === $user->user_login
                ? $user->display_name
                : sprintf('%1$s (%2$s)', $user->display_name, $user->user_login);
        }
        return $options;
    }

    /**
     * Import Organise the admin menu's client safeguards and developer list
     * (0.5.0 to 0.12.x). The safeguards were on by default and worked only
     * while the menu was organised; they kept people who are not developers
     * from code changes and from making administrators.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $menu_waits = isset(self::active_plugins()['admin-menu-editor']) || isset(self::active_plugins()['admin-menu-editor-pro']);
        $safeguards = !array_key_exists('admin_menu_safeguards', $options) || !empty($options['admin_menu_safeguards']);
        if (!empty($options['admin_menu']) && $safeguards && !$menu_waits) {
            $options = self::import_setting($options, self::KEY, true);
            $options = self::import_setting($options, self::LIMITS_KEY, array('code', 'admins'));
        }
        if (!empty($options['admin_menu_developers'])) {
            $options = self::import_setting($options, self::USERS_KEY, (array) $options['admin_menu_developers']);
        }
        return $options;
    }

    /**
     * Register hooks. Whether someone is a developer is checked when each
     * hook runs, not here, so previews of other roles (Organise the admin
     * menu) apply.
     */
    public static function boot() {
        // Even while off, so switching on keeps the person saving.
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'), 10, 2);
        if (!self::enabled()) {
            return;
        }

        $limits = array_flip((array) SEOProStack_Settings::get(self::LIMITS_KEY));
        foreach (self::LIMIT_CAPS as $limit => $caps) {
            if (isset($limits[$limit])) {
                self::$blocked += array_fill_keys($caps, true);
            }
        }

        add_filter('map_meta_cap', array(__CLASS__, 'map_meta_cap'), 10, 4);
        add_filter('seoprostack_can_change_settings', array(__CLASS__, 'can_change_settings'));
        add_filter('all_plugins', array(__CLASS__, 'all_plugins'));

        if (isset($limits['admins'])) {
            add_filter('editable_roles', array(__CLASS__, 'editable_roles'));
            add_filter('pre_update_option_default_role', array(__CLASS__, 'keep_default_role'), 10, 2);
        }
        foreach (self::LIMIT_OPTIONS as $limit => $names) {
            if (!isset($limits[$limit])) {
                continue;
            }
            foreach (array_keys($names) as $name) {
                add_filter('pre_update_option_' . $name, array(__CLASS__, 'keep_option'), 10, 3);
            }
        }

        if (!is_admin() || !class_exists('SEOProStack_Admin_Manager')) {
            return;
        }
        add_action('admin_menu', array(__CLASS__, 'hide_settings_page'), PHP_INT_MAX);
        add_action('load-' . SEOProStack_Admin_Manager::HOOK, array(__CLASS__, 'block_settings_page'));
        if (isset($limits['site']) || isset($limits['search'])) {
            add_filter('allowed_options', array(__CLASS__, 'allowed_options'));
            add_action('load-options-permalink.php', array(__CLASS__, 'block_permalink_save'));
            add_action('admin_notices', array(__CLASS__, 'screen_notice'));
            foreach (array_keys(self::SCREENS) as $screen) {
                add_action('admin_print_footer_scripts-' . $screen . '.php', array(__CLASS__, 'lock_fields'));
            }
        }
    }

    /**
     * Whether a user is a developer: a super admin on multisite; on single
     * sites, while this is on, an administrator ticked under Developers
     * (every administrator when none of the ticked people still is one),
     * and while it is off, every administrator.
     *
     * @param int $user_id User ID; the current user by default.
     * @return bool
     */
    public static function is_developer($user_id = 0) {
        static $cache = array();
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        if (!isset($cache[$user_id])) {
            if (is_multisite()) {
                $developer = is_super_admin($user_id);
            } elseif (!self::enabled()) {
                $developer = user_can($user_id, 'manage_options');
            } else {
                $listed    = self::listed();
                $developer = $listed ? in_array($user_id, $listed, true) : user_can($user_id, 'manage_options');
            }
            $cache[$user_id] = $developer;
        }
        /**
         * Filter whether a user is a developer (Developer admins, and the
         * Developers menu and widgets of Organise the admin menu and Tidy
         * the dashboard). Previews of other roles return false here.
         *
         * @param bool $developer Whether the user is a developer.
         * @param int  $user_id   User ID.
         */
        return (bool) apply_filters('seoprostack_is_developer', $cache[$user_id], $user_id);
    }

    /**
     * Ticked developers who are still administrators.
     *
     * @return int[]
     */
    private static function listed() {
        return array_values(array_filter(array_map('intval', (array) SEOProStack_Settings::get(self::USERS_KEY)), function ($id) {
            return $id > 0 && user_can($id, 'manage_options');
        }));
    }

    /**
     * Whether the current person is limited.
     *
     * @return bool
     */
    private static function limited() {
        return is_user_logged_in() && !self::is_developer();
    }

    /**
     * After a save: keep the person saving on the developer list when this
     * is switched on or the list changes, so nobody locks themselves out.
     *
     * @param string $key   Setting key.
     * @param mixed  $value Saved value.
     */
    public static function setting_saved($key, $value = null) {
        if (is_multisite() || (self::KEY !== $key && self::USERS_KEY !== $key) || (self::KEY === $key && !$value)) {
            return;
        }
        $me  = get_current_user_id();
        $ids = array_map('intval', (array) SEOProStack_Settings::get(self::USERS_KEY));
        if ($me && user_can($me, 'manage_options') && !in_array($me, $ids, true)) {
            $ids[] = $me;
            SEOProStack_Settings::set(self::USERS_KEY, array_map('strval', $ids));
        }
    }

    /**
     * Take away the chosen capabilities, deactivating SEO Pro Stack, and
     * changing developers and administrators.
     *
     * @param string[] $caps    Primitive capabilities.
     * @param string   $cap     Capability checked.
     * @param int      $user_id User ID.
     * @param array    $args    Extra arguments.
     * @return string[]
     */
    public static function map_meta_cap($caps, $cap, $user_id, $args) {
        static $watched = array(
            'deactivate_plugin' => true,
            'edit_user'         => true,
            'delete_user'       => true,
            'remove_user'       => true,
            'promote_user'      => true,
        );
        // Return before is_developer(): it checks manage_options, which comes
        // back through this filter.
        if ((!isset(self::$blocked[$cap]) && !isset($watched[$cap])) || !$user_id || self::is_developer((int) $user_id)) {
            return $caps;
        }
        if (isset(self::$blocked[$cap])) {
            return array('do_not_allow');
        }
        $target = isset($args[0]) ? $args[0] : null;
        switch ($cap) {
            case 'deactivate_plugin':
                if (plugin_basename(SEOPROSTACK_FILE) === (string) $target) {
                    return array('do_not_allow');
                }
                break;
            case 'edit_user':
            case 'delete_user':
            case 'remove_user':
            case 'promote_user':
                if (self::protects_user((int) $target, (int) $user_id, $cap)) {
                    return array('do_not_allow');
                }
                break;
        }
        return $caps;
    }

    /**
     * Whether a user account is out of reach: developers for editing and
     * deleting, and administrators for changing roles (the role list does
     * not offer Administrator, so a change would demote them).
     *
     * @param int    $target  Account being changed.
     * @param int    $user_id Person changing it.
     * @param string $cap     Capability checked.
     * @return bool
     */
    private static function protects_user($target, $user_id, $cap) {
        if (!$target || !self::limits('admins')) {
            return false;
        }
        if ('promote_user' === $cap) {
            return self::is_developer($target) || user_can($target, 'manage_options');
        }
        return $target !== $user_id && self::is_developer($target);
    }

    /**
     * Whether a choice is ticked under "Only developers can".
     *
     * @param string $limit Choice.
     * @return bool
     */
    private static function limits($limit) {
        return in_array($limit, (array) SEOProStack_Settings::get(self::LIMITS_KEY), true);
    }

    /**
     * Only developers change SEO Pro Stack's settings (this also hides the
     * admin bar star).
     *
     * @param bool $can Whether they may.
     * @return bool
     */
    public static function can_change_settings($can) {
        return $can && !self::limited();
    }

    /**
     * Do not offer roles that can manage options, such as Administrator,
     * to people who are not developers.
     *
     * @param array $roles Roles.
     * @return array
     */
    public static function editable_roles($roles) {
        if (!self::limited()) {
            return $roles;
        }
        foreach ((array) $roles as $role => $data) {
            if (!empty($data['capabilities']['manage_options'])) {
                unset($roles[$role]);
            }
        }
        return $roles;
    }

    /**
     * Keep the default role for new accounts when someone who is not a
     * developer picks one that can manage options.
     *
     * @param mixed $value     New value.
     * @param mixed $old_value Old value.
     * @return mixed
     */
    public static function keep_default_role($value, $old_value) {
        $role = get_role((string) $value);
        if ($role && $role->has_cap('manage_options') && self::limited()) {
            return $old_value;
        }
        return $value;
    }

    /**
     * Keep a core setting only developers may change.
     *
     * @param mixed  $value     New value.
     * @param mixed  $old_value Old value.
     * @param string $option    Option name.
     * @return mixed
     */
    public static function keep_option($value, $old_value, $option = '') {
        return self::limited() ? $old_value : $value;
    }

    /**
     * Leave the settings only developers may change out of the forms that
     * options.php saves, so the rest of the form still saves.
     *
     * @param array $allowed Settings group => option names.
     * @return array
     */
    public static function allowed_options($allowed) {
        if (!self::limited()) {
            return $allowed;
        }
        foreach (self::locked_options() as $name => $group) {
            if ('' !== $group && isset($allowed[$group]) && is_array($allowed[$group])) {
                $allowed[$group] = array_values(array_diff($allowed[$group], array($name)));
            }
        }
        return $allowed;
    }

    /**
     * Core settings locked by the chosen limits.
     *
     * @return array<string,string> Option => settings group.
     */
    private static function locked_options() {
        $locked = array();
        foreach (self::LIMIT_OPTIONS as $limit => $names) {
            if (self::limits($limit)) {
                $locked += $names;
            }
        }
        return $locked;
    }

    /**
     * Refuse saving Settings → Permalinks, which also saves other plugins'
     * address bases in the same form.
     */
    public static function block_permalink_save() {
        if (self::limits('search') && self::limited() && isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD']) {
            wp_die(esc_html__('Sorry, only developers can change permalinks.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
    }

    /**
     * Say why fields cannot be changed, on the screens that have them.
     */
    public static function screen_notice() {
        $limit = self::screen_limit();
        if ('' === $limit) {
            return;
        }
        $text = 'site' === $limit
            ? __('Only developers can change the WordPress address, the site address and the administration email.', 'seoprostack')
            : ('options-permalink' === get_current_screen()->id
                ? __('Only developers can change permalinks.', 'seoprostack')
                : __('Only developers can change search engine visibility.', 'seoprostack'));
        echo '<div class="notice notice-info"><p>' . esc_html($text) . '</p></div>';
    }

    /**
     * The limit that applies to the current settings screen for this
     * person, or ''.
     *
     * @return string
     */
    private static function screen_limit() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !isset(self::SCREENS[$screen->id])) {
            return '';
        }
        $limit = self::SCREENS[$screen->id];
        return self::limits($limit) && self::limited() ? $limit : '';
    }

    /**
     * Show the locked fields as read-only (they are refused on save anyway).
     */
    public static function lock_fields() {
        $limit = self::screen_limit();
        if ('' === $limit) {
            return;
        }
        $screen    = get_current_screen()->id;
        $selectors = array(
            'options-general'   => '#siteurl, #home, #new_admin_email',
            'options-reading'   => '#blog_public',
            'options-permalink' => '#wpbody-content form input, #wpbody-content form select, #wpbody-content form button',
        );
        wp_print_inline_script_tag(sprintf(
            'document.querySelectorAll(%s).forEach(function(el){el.disabled=true;});',
            wp_json_encode($selectors[$screen])
        ));
    }

    /**
     * Hide Settings → SEO Pro Stack from people who are not developers.
     */
    public static function hide_settings_page() {
        if (self::limited()) {
            remove_submenu_page('options-general.php', SEOProStack_Admin_Manager::PAGE);
        }
    }

    /**
     * Refuse SEO Pro Stack's settings screen to people who are not
     * developers.
     */
    public static function block_settings_page() {
        if (self::limited()) {
            wp_die(esc_html__('Sorry, this page is only for developers.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
    }

    /**
     * Hide SEO Pro Stack from the Plugins screen for people who are not
     * developers.
     *
     * @param array $plugins Plugin file => data.
     * @return array
     */
    public static function all_plugins($plugins) {
        if (self::limited()) {
            unset($plugins[plugin_basename(SEOPROSTACK_FILE)]);
        }
        return $plugins;
    }
}
