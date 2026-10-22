<?php
/**
 * Editors use the site's business plugins.
 *
 * Lets chosen roles (editors by default) use WooCommerce as shop managers
 * do, and Rank Math, Tutor LMS, FluentCRM, Fluent Support, Fluent Booking,
 * Fluent Boards and FluentSMTP as administrators do. Nothing is stored:
 * capabilities are added at run time with user_has_cap, so switching the
 * feature off, or taking someone out of the role, takes them back.
 *
 * Most of these plugins treat anyone who can manage_options as their
 * administrator. Editors are answered yes to manage_options (and to the
 * administrator role, for Tutor LMS) only when the code asking is a file of
 * a chosen plugin, or core registering that plugin's admin page. WordPress's
 * own screens and every other plugin still get no. The chosen plugins'
 * menu entries that ask for manage_options ask for read instead, for these
 * people only, including the entries Load plugins only where needed puts
 * back on screens that skip them.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.70
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Editor_Access extends SEOProStack_Feature {

    const KEY = 'editor_access';

    /** Capability the chosen plugins' admin-only menu entries ask for instead. */
    const MENU_CAP = 'read';

    /**
     * Plugins covered: folders (with their Pro add-ons) and the start of
     * their admin page slugs. WooCommerce gets only its own capabilities, as
     * shop managers have, so no folders: its code is never answered yes to
     * manage_options.
     */
    const PLUGINS = array(
        'woocommerce'    => array(
            'name'  => 'WooCommerce',
            'dirs'  => array(),
            'pages' => array(),
        ),
        'rank-math'      => array(
            'name'  => 'Rank Math',
            'dirs'  => array('seo-by-rank-math', 'seo-by-rank-math-pro'),
            'pages' => array('rank-math'),
        ),
        'tutor'          => array(
            'name'  => 'Tutor LMS',
            'dirs'  => array('tutor', 'tutor-pro'),
            'pages' => array('tutor'),
        ),
        'fluent-crm'     => array(
            'name'  => 'FluentCRM',
            'dirs'  => array('fluent-crm', 'fluentcampaign-pro'),
            'pages' => array('fluentcrm'),
        ),
        'fluent-support' => array(
            'name'  => 'Fluent Support',
            'dirs'  => array('fluent-support', 'fluent-support-pro'),
            'pages' => array('fluent-support'),
        ),
        'fluent-booking' => array(
            'name'  => 'Fluent Booking',
            'dirs'  => array('fluent-booking', 'fluent-booking-pro'),
            'pages' => array('fluent-booking'),
        ),
        'fluent-boards'  => array(
            'name'  => 'Fluent Boards',
            'dirs'  => array('fluent-boards', 'fluent-boards-pro'),
            'pages' => array('fluent-boards'),
        ),
        'fluent-smtp'    => array(
            'name'  => 'FluentSMTP',
            'dirs'  => array('fluent-smtp'),
            'pages' => array('fluent-mail'),
        ),
    );

    /** WooCommerce's own capabilities, as WC_Install gives shop managers. */
    const WOO_CAPS = array('manage_woocommerce', 'create_customers', 'view_woocommerce_reports');

    /** Menu entries left for administrators: they install or buy plugins. */
    const NOT_PAGES = '/add-?ons|upgrade|get-pro|licen[cs]e|go-pro|extensions|marketplace/i';

    /**
     * Folder prefixes of the chosen plugins' files, cached per request.
     *
     * @var string[]|null
     */
    private static $prefixes = null;

    /**
     * Chosen plugins, keyed by the stored list they came from.
     *
     * @var array<string,array>
     */
    private static $chosen = array();

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        $plugins = array();
        foreach (self::PLUGINS as $key => $plugin) {
            $plugins[$key] = $plugin['name'];
        }
        return array(
            self::KEY                => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'admin',
                'label'       => __('Editors use the business plugins', 'seoprostack'),
                'description' => __('Let editors use WooCommerce as shop managers do, and Rank Math, Tutor LMS and the Fluent plugins as administrators do. WordPress’s own settings, plugins, themes and users stay for administrators. Nothing is stored: switch it off and editors have only what their role gives.', 'seoprostack'),
            ),
            'editor_access_roles'   => array(
                'type'        => 'multi',
                'default'     => array('editor'),
                'parent'      => self::KEY,
                'label'       => __('Roles', 'seoprostack'),
                'description' => __('Roles that get the plugins below. Roles that can manage options have them already.', 'seoprostack'),
                'options'     => array(__CLASS__, 'restrictable_role_options'),
            ),
            'editor_access_plugins' => array(
                'type'        => 'multi',
                'default'     => array_keys($plugins),
                'parent'      => self::KEY,
                'label'       => __('Plugins', 'seoprostack'),
                'description' => __('In WooCommerce the roles above can do what shop managers can. In the others, what administrators can, except install add-ons and, in Rank Math, change who can do what.', 'seoprostack'),
                'options'     => $plugins,
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !self::chosen()) {
            return;
        }
        add_filter('user_has_cap', array(__CLASS__, 'grant'), 10, 4);
        add_action('admin_menu', array(__CLASS__, 'menu'), PHP_INT_MAX - 1);
        add_filter('seoprostack_restored_menu_capability', array(__CLASS__, 'menu_capability'), 10, 2);
    }

    /**
     * The chosen plugins.
     *
     * @return array<string,array>
     */
    private static function chosen() {
        $keys = array_map('strval', (array) SEOProStack_Settings::get('editor_access_plugins'));
        $key  = implode(',', $keys);
        if (!isset(self::$chosen[$key])) {
            self::$chosen   = array($key => array_intersect_key(self::PLUGINS, array_flip($keys)));
            self::$prefixes = null;
        }
        return self::$chosen[$key];
    }

    /**
     * Whether a user has one of the chosen roles and cannot manage options
     * through their role. Reads the user's stored capabilities, not
     * user_can(), which would come back through user_has_cap.
     *
     * @param WP_User $user User.
     * @return bool
     */
    private static function covers($user) {
        if (!$user instanceof WP_User || !$user->exists() || !empty($user->allcaps['manage_options'])) {
            return false;
        }
        $roles = (array) SEOProStack_Settings::get('editor_access_roles');
        return (bool) array_intersect((array) $user->roles, array_map('strval', $roles));
    }

    /* --------------------------------------------------------------------- */
    /* Capabilities                                                           */
    /* --------------------------------------------------------------------- */

    /**
     * Add the chosen plugins' capabilities, and manage_options or the
     * administrator role when a chosen plugin asks.
     *
     * @param array   $allcaps Capabilities the user has.
     * @param array   $caps    Primitive capabilities asked for.
     * @param array   $args    Capability check arguments.
     * @param WP_User $user    User.
     * @return array
     */
    public static function grant($allcaps, $caps, $args, $user) {
        if (!is_array($allcaps) || !is_array($caps) || !$caps || !empty($allcaps['manage_options']) || !self::covers($user)) {
            return $allcaps;
        }
        $chosen = self::chosen();
        foreach ($caps as $cap) {
            $cap = (string) $cap;
            if (!empty($allcaps[$cap])) {
                continue;
            }
            if (self::plugin_cap($cap, $chosen)) {
                $allcaps[$cap] = true;
            } elseif (('manage_options' === $cap || 'administrator' === $cap) && self::asked_by_chosen()) {
                $allcaps[$cap] = true;
            }
        }
        return $allcaps;
    }

    /**
     * Whether a capability is one of the chosen plugins' own.
     *
     * @param string               $cap    Capability.
     * @param array<string,array>  $chosen Chosen plugins.
     * @return bool
     */
    private static function plugin_cap($cap, array $chosen) {
        if (isset($chosen['woocommerce'])
            && (in_array($cap, self::WOO_CAPS, true) || preg_match('/^(edit|read|delete|publish|manage|assign)_(others_|private_|published_)?(product|shop_order|shop_coupon)(s|_terms)?$/', $cap))) {
            return true;
        }
        if (isset($chosen['rank-math']) && 0 === strpos($cap, 'rank_math_') && 'rank_math_role_manager' !== $cap) {
            return true;
        }
        return isset($chosen['tutor']) && (bool) preg_match('/(^|_)tutor(_|$)/', $cap);
    }

    /**
     * Whether the capability check was made by a chosen plugin's file, or by
     * core registering that plugin's admin page (add_menu_page() and the
     * other add_*_page() functions check the capability themselves).
     *
     * @return bool
     */
    private static function asked_by_chosen() {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- only for the chosen roles' manage_options checks, to see which plugin asks.
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24);
        $at     = -1;
        foreach ($frames as $i => $frame) {
            if (self::check_frame($frame)) {
                $at = $i;
            } elseif ($at >= 0) {
                break;
            }
        }
        if ($at < 0 || !isset($frames[$at]['file'])) {
            return false;
        }
        $file = wp_normalize_path((string) $frames[$at]['file']);
        $core = wp_normalize_path(ABSPATH . 'wp-admin/includes/plugin.php');
        // Core's add_*_page() functions: the file that called them asks.
        for ($next = $at + 1; $file === $core && isset($frames[$next]['file']); $next++) {
            if (!empty($frames[$next]['class']) || !preg_match('/^add_[a-z_]*page$/', $frames[$next]['function'])) {
                break;
            }
            $file = wp_normalize_path($frames[$next]['file']);
        }
        foreach (self::prefixes() as $prefix) {
            if (0 === strpos($file, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a stack frame is part of a capability check.
     *
     * @param array $frame Stack frame.
     * @return bool
     */
    private static function check_frame(array $frame) {
        $function = isset($frame['function']) ? (string) $frame['function'] : '';
        if (!empty($frame['class'])) {
            return 'WP_User' === $frame['class'] && 'has_cap' === $function;
        }
        return in_array($function, array('current_user_can', 'user_can', 'current_user_can_for_site', 'current_user_can_for_blog', 'user_can_for_site'), true);
    }

    /**
     * Folder prefixes of the chosen plugins, as plain and resolved paths.
     *
     * @return string[]
     */
    private static function prefixes() {
        $chosen = self::chosen(); // Clears the prefixes when the choice changed.
        if (null === self::$prefixes) {
            $roots = array(wp_normalize_path(WP_PLUGIN_DIR));
            $real  = realpath(WP_PLUGIN_DIR);
            if (is_string($real)) {
                $roots[] = wp_normalize_path($real);
            }
            self::$prefixes = array();
            foreach ($chosen as $plugin) {
                foreach ($plugin['dirs'] as $dir) {
                    foreach (array_unique($roots) as $root) {
                        self::$prefixes[] = trailingslashit($root) . $dir . '/';
                    }
                }
            }
        }
        return self::$prefixes;
    }

    /* --------------------------------------------------------------------- */
    /* Menu                                                                   */
    /* --------------------------------------------------------------------- */

    /**
     * Let the chosen roles open the chosen plugins' admin-only entries. Core
     * checks the entry's capability itself when it prints the menu and opens
     * the page, so the plugin's own answer is not asked.
     */
    public static function menu() {
        global $menu, $submenu;
        if (!self::covers(wp_get_current_user())) {
            return;
        }
        foreach (is_array($menu) ? $menu : array() as $position => $item) {
            if (is_array($item) && isset($item[1], $item[2])) {
                $menu[$position][1] = self::menu_capability($item[1], $item[2]); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- changing who can open an entry.
            }
        }
        foreach (is_array($submenu) ? $submenu : array() as $parent => $items) {
            $closed = null;
            foreach ((array) $items as $position => $item) {
                if (!is_array($item) || !isset($item[1], $item[2])) {
                    continue;
                }
                $cap = self::menu_capability($item[1], $item[2]);
                if ($cap === $item[1]) {
                    continue;
                }
                $submenu[$parent][$position][1] = $cap; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.
                if (null === $closed) {
                    $closed = !self::can_open((string) $parent);
                }
                if ($closed) {
                    self::alias_page((string) $item[2], (string) $parent);
                }
            }
        }
    }

    /**
     * Whether the current person can open a top-level menu entry. Entries
     * that are not in the menu count as open.
     *
     * @param string $parent Top-level entry address.
     * @return bool
     */
    private static function can_open($parent) {
        global $menu;
        foreach (is_array($menu) ? $menu : array() as $item) {
            if (is_array($item) && isset($item[1], $item[2]) && $item[2] === $parent) {
                return current_user_can((string) $item[1]);
            }
        }
        return true;
    }

    /**
     * Let a page under a menu the person cannot open (such as FluentSMTP
     * under Settings) open. Core then moves the menu's remaining pages
     * under the first of them and looks for the page as admin_page_{slug},
     * which the plugin never registered, so it refuses the page. Register
     * that name and run the plugin's own page and load hooks for it.
     *
     * @param string $slug   Page slug.
     * @param string $parent Menu it was added to.
     */
    private static function alias_page($slug, $parent) {
        global $_registered_pages;
        if (false !== strpos($slug, '.php') || false !== strpos($slug, '#')) {
            return;
        }
        $hook  = get_plugin_page_hookname($slug, $parent);
        $alias = 'admin_page_' . $slug;
        if ($hook === $alias || empty($_registered_pages[$hook]) || !empty($_registered_pages[$alias])) {
            return;
        }
        $_registered_pages[$alias] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- registering the page under the name core looks for.
        add_action($alias, function () use ($hook) {
            do_action($hook); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the plugin's own page hook.
        });
        add_action('load-' . $alias, function () use ($hook) {
            do_action('load-' . $hook); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's load hook for the plugin's page.
        });
    }

    /**
     * Capability a menu entry asks of the current person: read instead of
     * manage_options for the chosen plugins' pages.
     *
     * @param mixed $cap  Capability the entry asks for.
     * @param mixed $slug Entry address.
     * @return mixed
     */
    public static function menu_capability($cap, $slug) {
        if (('manage_options' !== $cap && 'administrator' !== $cap) || !is_string($slug) || '' === $slug
            || false !== strpos($slug, '://') || preg_match(self::NOT_PAGES, $slug)
            || !self::covers(wp_get_current_user())) {
            return $cap;
        }
        foreach (self::chosen() as $plugin) {
            foreach ($plugin['pages'] as $page) {
                if (0 === strpos($slug, $page)) {
                    return self::MENU_CAP;
                }
            }
        }
        return $cap;
    }
}
