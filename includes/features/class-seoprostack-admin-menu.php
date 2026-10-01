<?php
/**
 * Organise the admin menu into sections, the same way on every site.
 *
 * Menu entries go under the headings Content, Communications, SEO, Shop
 * and Admin; under Admin come the Administrators and Developers menus
 * (section keys `admin` and `super-admin`), then Users. Places come from a
 * catalog of known menus and plugins (admin/data/admin-menu.php) and the
 * "Move menu entries" setting. Within a section WordPress's own entries
 * come first, then plugins' entries in alphabetical order; Settings, Tools
 * and Appearance are sorted the same way. Plugin pages that sit under
 * Settings or Tools but belong elsewhere (such as caching or developer
 * tools) move to their section; in Developers they share one Settings
 * entry. Sections can fold; entries keep their normal flyout submenus.
 *
 * The menu is only rearranged as it is printed (the `parent_file` filter in
 * menu-header.php) and put back straight after (`adminmenu`), so WordPress
 * still finds every page, checks access and runs page hooks exactly as
 * before. Moved entries link to their usual address. Nothing runs on the
 * front end. The plugin that owns each page is looked up once and kept
 * until plugins change.
 *
 * Client safeguards (on by default): people who are not developers do not
 * see or open Developers pages, cannot install, delete or edit the code of
 * plugins and themes, do not see developer plugins on the Plugins screen,
 * cannot change developer accounts, cannot make administrators and cannot
 * change SEO Pro Stack's settings. Updates keep working. Developers are
 * super admins on multisite, and the administrators ticked under Developers
 * on single sites.
 *
 * Replaces Admin Menu Editor (Pro). Its settings are not imported: the
 * places come from the catalog's rules, so every site gets the same menu.
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Menu extends SEOProStack_Feature {

    const KEY = 'admin_menu';

    /** Setting: client safeguards. */
    const SAFEGUARDS_KEY = 'admin_menu_safeguards';

    /** Setting: developer accounts (single sites). */
    const DEVELOPERS_KEY = 'admin_menu_developers';

    /** Setting: "address = place" lines. */
    const MOVES_KEY = 'admin_menu_moves';

    /** Setting: section headings fold. */
    const FOLD_KEY = 'admin_menu_fold';

    /** Option: which plugin owns each admin page, until plugins change. */
    const CACHE = 'seoprostack_admin_menu';

    /** User setting (wp-settings cookie) with the folded sections' codes. */
    const FOLD = 'spsfold';

    /** Cookie: the role a developer is previewing the admin as. */
    const VIEW_COOKIE = 'seoprostack_view_as';

    /** Query argument that starts ("role") or stops ("stop") a preview. */
    const VIEW_ARG = 'sps_view_as';

    /** Preview "role" for an administrator who is not a developer. */
    const VIEW_CLIENT = 'client-admin';

    /** How long a preview lasts, in seconds. */
    const VIEW_TTL = 7200;

    /** Sections that are menus of their own (key => icon); the rest are headings. */
    const MENUS = array(
        'admin'       => 'dashicons-admin-generic',
        'super-admin' => 'dashicons-editor-code',
    );

    /** Icons for pages that become menu entries (address => icon). */
    const ICONS = array(
        'seoprostack' => 'dashicons-star-filled',
    );

    /** Script and style handle. */
    const HANDLE = 'seoprostack-admin-menu';

    /** Heading section => one-letter code for the fold setting. */
    const CODES = array(
        'content'        => 'c',
        'communications' => 'm',
        'seo'            => 's',
        'shop'           => 'p',
        'admin-heading'  => 'a',
    );

    /** Other names a place may be typed as => section key. */
    const ALIASES = array(
        'administrators' => 'admin',
        'developers'     => 'super-admin',
        'developer'      => 'super-admin',
    );

    /** Icon of the Settings entry in the Developers menu. */
    const DEV_SETTINGS_ICON = 'dashicons-admin-settings';

    /** WordPress's own top-level entries, in the order they appear in a section. */
    const CORE_ORDER = array(
        'index.php',
        'edit.php?post_type=page',
        'edit.php',
        'upload.php',
        'link-manager.php',
        'edit-comments.php',
        'users.php',
        'profile.php',
        'themes.php',
        'plugins.php',
        'tools.php',
        'options-general.php',
    );

    /** WordPress menus whose plugin entries move by the plugin's place. */
    const CORE_PARENTS = array(
        'index.php'               => true,
        'edit.php'                => true,
        'upload.php'              => true,
        'edit.php?post_type=page' => true,
        'edit-comments.php'       => true,
        'themes.php'              => true,
        'plugins.php'             => true,
        'users.php'               => true,
        'profile.php'             => true,
        'tools.php'               => true,
        'options-general.php'     => true,
    );

    /** Menus sorted like sections: WordPress's entries, then plugins' A–Z. */
    const SORTED_PARENTS = array('options-general.php', 'tools.php', 'themes.php');

    /** Capabilities people who are not developers lose while safeguards are on. */
    const BLOCKED_CAPS = array(
        'install_plugins' => true,
        'upload_plugins'  => true,
        'delete_plugins'  => true,
        'edit_plugins'    => true,
        'install_themes'  => true,
        'upload_themes'   => true,
        'delete_themes'   => true,
        'edit_themes'     => true,
        'edit_files'      => true,
    );

    /**
     * The real menu while the organised one is printed.
     *
     * @var array|null
     */
    private static $original = null;

    /**
     * Address of the current page's entry inside its new menu, if it moved.
     *
     * @var string|null
     */
    private static $submenu_file = null;

    /**
     * Owner cache as stored, and whether this request added to it.
     *
     * @var array|null
     */
    private static $owners = null;

    /** @var bool */
    private static $owners_changed = false;

    /**
     * "Move menu entries" as address => place, or null to read the setting.
     *
     * @var array|null
     */
    private static $moves = null;

    /**
     * Third-level entries of the Admin and Super Admin menus, printed after
     * the menu: entry class => list of {t: title, u: address, c: current,
     * d: divider before}.
     *
     * @var array
     */
    private static $flyouts = array();

    /**
     * Role being previewed, or ''.
     *
     * @var string
     */
    private static $view_as = '';

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
                'label'       => __('Organise the admin menu', 'seoprostack'),
                'description' => __('Group the menu into Content, Communications, SEO, Shop and Admin, with Administrators and Developers menus that open to the side, the same way on every site. WordPress’s own entries come first, then plugins’ in A–Z order.', 'seoprostack'),
                'replaces'    => array(
                    'admin-menu-editor-pro' => 'Admin Menu Editor Pro',
                    'admin-menu-editor'     => 'Admin Menu Editor',
                ),
            ),
            self::SAFEGUARDS_KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Client safeguards', 'seoprostack'),
                'description' => __('People who are not developers cannot see the Developers menu, install, delete or edit plugins and themes, see developer plugins, change developer accounts, make administrators or change these settings. Updates still work.', 'seoprostack'),
            ),
            self::FOLD_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'reload'      => true,
                'label'       => __('Fold sections', 'seoprostack'),
                'description' => __('Click a section heading to hide its entries. Each person’s folded sections are remembered.', 'seoprostack'),
            ),
        );

        if (!is_multisite()) {
            $settings[self::DEVELOPERS_KEY] = array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Developers', 'seoprostack'),
                'description' => __('Administrators who see the Developers menu and are not limited by the safeguards. Everyone is ticked when you switch this on; administrators added later are not. You stay on the list.', 'seoprostack'),
                'options'     => array(__CLASS__, 'developer_options'),
            );
        }

        $settings[self::MOVES_KEY] = array(
            'type'        => 'lines',
            'default'     => '',
            'parent'      => self::KEY,
            'rows'        => 4,
            'reload'      => true,
            'label'       => __('Move menu entries', 'seoprostack'),
            'placeholder' => 'rank-math = seo',
            'description' => __('Filled in by the places chosen above. You can also type lines: a menu address or plugin folder, then = and a place: top, content, communications, seo, shop, admin-heading (under Admin), administrators, developers, or another menu’s address to go inside it.', 'seoprostack'),
        );

        return $settings;
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
     * Register hooks.
     */
    public static function boot() {
        // Even while off, so switching on fills in the developers.
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'), 10, 2);
        add_action('seoprostack_setting_panel', array(__CLASS__, 'panel'), 10, 2);
        add_action('upgrader_process_complete', array(__CLASS__, 'forget'));

        // Stopping a preview works even with the feature off.
        self::handle_view_request();
        if (!self::enabled()) {
            return;
        }
        self::start_view();

        if (self::safeguards_on()) {
            add_filter('map_meta_cap', array(__CLASS__, 'map_meta_cap'), 10, 4);
        }
        if (self::safeguards_on() && is_user_logged_in() && !self::is_developer()) {
            add_filter('editable_roles', array(__CLASS__, 'editable_roles'));
            add_filter('all_plugins', array(__CLASS__, 'all_plugins'));
            add_filter('seoprostack_can_change_settings', '__return_false');
            if (is_admin()) {
                add_action('admin_init', array(__CLASS__, 'block_page'));
            }
        }

        if (!is_admin() || is_network_admin() || is_user_admin()) {
            return;
        }
        add_filter('parent_file', array(__CLASS__, 'organise'), PHP_INT_MAX);
        add_filter('submenu_file', array(__CLASS__, 'submenu_file'), PHP_INT_MAX);
        // Before anything else reads the menu after it is printed (such as
        // Load plugins only where needed, which compares it with its copy).
        add_action('adminmenu', array(__CLASS__, 'restore'), 1);
        add_action('adminmenu', array(__CLASS__, 'print_flyouts'), 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('shutdown', array(__CLASS__, 'save_owners'));
    }

    /**
     * Whether client safeguards are on.
     *
     * @return bool
     */
    public static function safeguards_on() {
        return (bool) SEOProStack_Settings::get(self::SAFEGUARDS_KEY);
    }

    /**
     * Whether a user is a developer: a super admin on multisite; on single
     * sites an administrator ticked under Developers. When none of the
     * ticked people is still an administrator (or nobody is ticked), every
     * administrator is, so nobody is ever locked out.
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
        if ('' !== self::$view_as && get_current_user_id() === $user_id) {
            // Previewing another role: see what they see.
            return false;
        }
        if (!isset($cache[$user_id])) {
            if (is_multisite()) {
                $developer = is_super_admin($user_id);
            } else {
                $listed = array_filter(array_map('intval', (array) SEOProStack_Settings::get(self::DEVELOPERS_KEY)), function ($id) {
                    return $id > 0 && user_can($id, 'manage_options');
                });
                $developer = $listed ? in_array($user_id, $listed, true) : user_can($user_id, 'manage_options');
            }
            /**
             * Filter whether a user is a developer for Organise the admin menu.
             *
             * @param bool $developer Whether the user is a developer.
             * @param int  $user_id   User ID.
             */
            $cache[$user_id] = (bool) apply_filters('seoprostack_is_developer', $developer, $user_id);
        }
        return $cache[$user_id];
    }

    /**
     * After a save: tick every administrator when the feature is switched on
     * with nobody ticked (which already makes every administrator a
     * developer), and keep the person saving on the developer list.
     *
     * @param string $key   Setting key.
     * @param mixed  $value Saved value.
     */
    public static function setting_saved($key, $value = null) {
        if (is_multisite()) {
            return;
        }
        if (self::KEY === $key && $value) {
            // Settings are stored with every default, so an empty list is
            // the only sign that nobody has been chosen yet.
            if (!array_filter((array) SEOProStack_Settings::get(self::DEVELOPERS_KEY))) {
                SEOProStack_Settings::set(self::DEVELOPERS_KEY, array_map('strval', array_keys(self::developer_options())));
            }
        } elseif (self::DEVELOPERS_KEY === $key) {
            $ids = array_map('intval', (array) $value);
            $me  = get_current_user_id();
            if ($ids && $me && !in_array($me, $ids, true)) {
                $ids[] = $me;
                SEOProStack_Settings::set(self::DEVELOPERS_KEY, array_map('strval', $ids));
            }
        }
    }

    /* --------------------------------------------------------------------- */
    /* Places                                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Sections in menu order. Under the Admin heading come the
     * Administrators and Developers menus (keys `admin` and `super-admin`),
     * then entries placed under the heading itself (`admin-heading`), such
     * as Users.
     *
     * @return array<string,string> Key => label.
     */
    public static function sections() {
        return array(
            'top'            => '',
            'content'        => __('Content', 'seoprostack'),
            'communications' => __('Communications', 'seoprostack'),
            'seo'            => __('SEO', 'seoprostack'),
            'shop'           => __('Shop', 'seoprostack'),
            'admin-heading'  => __('Admin', 'seoprostack'),
            'admin'          => __('Administrators', 'seoprostack'),
            'super-admin'    => __('Developers', 'seoprostack'),
        );
    }

    /**
     * Known menus and plugins (see admin/data/admin-menu.php).
     *
     * @return array{menus: array<string,string>, plugins: array<string,string>}
     */
    public static function catalog() {
        static $catalog = null;
        if (null === $catalog) {
            $data = require SEOPROSTACK_DIR . 'admin/data/admin-menu.php';
            $data['plugins'][dirname(plugin_basename(SEOPROSTACK_FILE))] = 'super-admin';
            /**
             * Filter where menu entries go.
             *
             * @param array $catalog `menus` (address => place) and `plugins`
             *                       (plugin folder => place). A place is a
             *                       section key or another menu's address.
             */
            $catalog = (array) apply_filters('seoprostack_admin_menu_catalog', $data);
            $catalog += array('menus' => array(), 'plugins' => array());
        }
        return $catalog;
    }

    /**
     * The "Move menu entries" setting as address (or plugin folder) => place.
     *
     * @return array<string,string>
     */
    public static function moves() {
        $moves = array();
        foreach (preg_split('/[\r\n]+/', (string) SEOProStack_Settings::get(self::MOVES_KEY)) as $line) {
            $line = trim($line);
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            // Addresses may hold "=", so split at the last " = ", or the last "=".
            $at  = strrpos($line, ' = ');
            $len = 3;
            if (false === $at) {
                $at  = strrpos($line, '=');
                $len = 1;
            }
            if (false === $at) {
                continue;
            }
            $key   = trim(substr($line, 0, $at));
            $place = self::normalise_place(trim(substr($line, $at + $len)));
            if ('' !== $key && '' !== $place) {
                $moves[$key] = $place;
            }
        }
        return $moves;
    }

    /**
     * A place as typed: section keys and menu names in any case (Super
     * Admin, super-admin, Developers), otherwise an address as it is.
     *
     * @param string $place Place.
     * @return string
     */
    private static function normalise_place($place) {
        $key = str_replace(array(' ', '_'), '-', strtolower($place));
        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }
        return isset(self::sections()[$key]) ? $key : $place;
    }

    /**
     * Where an entry goes: a section, another menu's address, or '' to
     * stay where it is.
     *
     * @param string $slug   Entry address.
     * @param string $parent Menu it is in ('' for top-level entries).
     * @return string
     */
    private static function place($slug, $parent) {
        if (null === self::$moves) {
            self::$moves = self::moves();
        }
        $moves   = self::$moves;
        $catalog = self::catalog();

        foreach (array($parent . '>' . $slug, $slug) as $key) {
            if (isset($moves[$key])) {
                return $moves[$key];
            }
        }
        $owner = self::owner($slug, $parent);
        if ('' !== $owner && isset($moves[$owner])) {
            return $moves[$owner];
        }
        if (isset($catalog['menus'][$slug])) {
            return (string) $catalog['menus'][$slug];
        }
        // A plugin's place moves its top-level entries and its pages under
        // WordPress's menus, never pages inside its own or another plugin's menu.
        if ('' !== $owner && isset($catalog['plugins'][$owner]) && ('' === $parent || isset(self::CORE_PARENTS[$parent]))) {
            return (string) $catalog['plugins'][$owner];
        }
        return '';
    }

    /**
     * Section of a top-level entry that no rule places: a link to a page
     * inside another menu (such as WooCommerce's Payments, a link to its
     * settings) goes with that menu, post types go to Content, the rest
     * to Admin.
     *
     * @param string $slug         Entry address.
     * @param array  $page_parents Page => menu it is in.
     * @return string
     */
    private static function fallback_section($slug, array $page_parents) {
        $sections = self::sections();
        $args     = self::parse_slug($slug)['args'];
        if (isset($args['page']) && $args['page'] !== $slug) {
            $place = self::place($args['page'], '');
            if (!isset($sections[$place]) && isset($page_parents[$args['page']]) && $page_parents[$args['page']] !== $slug) {
                $place = self::place($page_parents[$args['page']], '');
            }
            if (isset($sections[$place])) {
                return $place;
            }
        }
        return 0 === strpos($slug, 'edit.php?post_type=') ? 'content' : 'admin';
    }

    /* --------------------------------------------------------------------- */
    /* Page owners                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * Folder of the plugin that handles a menu page, or ''.
     *
     * @param string $slug   Entry address.
     * @param string $parent Menu it is in ('' for top-level entries).
     * @return string
     */
    private static function owner($slug, $parent) {
        global $admin_page_hooks, $wp_filter;

        if ('' === $parent) {
            // As get_plugin_page_hookname() would, without its side effects.
            $hook = (isset($admin_page_hooks[$slug]) ? 'toplevel' : 'admin') . '_page_' . preg_replace('!\.php!', '', $slug);
        } else {
            $hook = get_plugin_page_hookname($slug, $parent);
        }
        if (empty($wp_filter[$hook]) || !($wp_filter[$hook] instanceof WP_Hook)) {
            return '';
        }

        $owners = self::owners();
        if (isset($owners[$hook])) {
            return $owners[$hook];
        }

        $found = '';
        $real  = false;
        foreach ($wp_filter[$hook]->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                // Load plugins only where needed puts back skipped plugins' pages with a stand-in.
                if ('__return_null' === $callback['function']) {
                    continue;
                }
                $real   = true;
                $folder = self::folder_for_callback($callback['function']);
                if ('' !== $folder) {
                    $found = $folder;
                    break 2;
                }
            }
        }
        if ($real) {
            self::$owners[$hook]  = $found;
            self::$owners_changed = true;
        }
        return $found;
    }

    /**
     * Stored owners, while plugins are unchanged.
     *
     * @return array<string,string> Page hook => plugin folder.
     */
    private static function owners() {
        if (null === self::$owners) {
            $stored       = get_option(self::CACHE);
            self::$owners = is_array($stored) && isset($stored['plugins'], $stored['owners']) && self::fingerprint() === $stored['plugins'] && is_array($stored['owners'])
                ? $stored['owners']
                : array();
        }
        return self::$owners;
    }

    /**
     * Save owners looked up in this request.
     */
    public static function save_owners() {
        if (self::$owners_changed) {
            update_option(self::CACHE, array('plugins' => self::fingerprint(), 'owners' => self::$owners), false);
            self::$owners_changed = false;
        }
    }

    /**
     * Forget the owners when plugin code changes.
     */
    public static function forget() {
        delete_option(self::CACHE);
        self::$owners = null;
    }

    /**
     * Hash of the active plugins.
     *
     * @return string
     */
    private static function fingerprint() {
        $plugins = array_values(SEOProStack_Feature::active_plugins());
        sort($plugins);
        return md5(implode('|', $plugins));
    }

    /**
     * Folder of the plugin that defined a callback, or ''.
     *
     * @param mixed $callback Callback.
     * @return string
     */
    private static function folder_for_callback($callback) {
        try {
            if (is_string($callback) && false !== strpos($callback, '::')) {
                $callback = explode('::', $callback, 2);
            }
            if (is_array($callback) && 2 === count($callback)) {
                $class = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
                if (!method_exists($class, (string) $callback[1])) {
                    return is_object($callback[0]) ? self::folder_for_file((string) (new ReflectionClass($callback[0]))->getFileName()) : '';
                }
                $reflection = new ReflectionMethod($class, (string) $callback[1]);
            } elseif (is_object($callback) && !($callback instanceof Closure)) {
                $reflection = new ReflectionMethod($callback, '__invoke');
            } elseif (is_string($callback) || $callback instanceof Closure) {
                $reflection = new ReflectionFunction($callback);
            } else {
                return '';
            }
        } catch (ReflectionException $e) {
            return '';
        }
        $file = $reflection->getFileName();
        return $file ? self::folder_for_file($file) : '';
    }

    /**
     * Plugin folder (or single-file plugin name) of a file, or ''.
     *
     * @param string $file Path.
     * @return string
     */
    private static function folder_for_file($file) {
        $dir  = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
        $file = wp_normalize_path((string) $file);
        if (0 !== strpos($file, $dir)) {
            return '';
        }
        $rest  = substr($file, strlen($dir));
        $slash = strpos($rest, '/');
        return false === $slash ? (string) preg_replace('/\.php$/', '', $rest) : substr($rest, 0, $slash);
    }

    /* --------------------------------------------------------------------- */
    /* Layout                                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Work out the organised menu from the real one.
     *
     * @return array{groups: array<string,array>, submenu: array, entries: array}
     */
    private static function layout() {
        global $menu, $submenu, $_wp_real_parent_file;

        $sections = self::sections();
        $groups   = array_fill_keys(array_keys($sections), array());
        $out_sub  = is_array($submenu) ? $submenu : array();
        $entries  = array();
        $top      = array();
        // Core renames a menu to its first entry; places may use the old name.
        $renamed  = array_flip(array_map('strval', (array) $_wp_real_parent_file));
        $parents  = array();
        foreach ($out_sub as $parent => $items) {
            foreach ((array) $items as $item) {
                if (is_array($item) && isset($item[2]) && !isset($parents[(string) $item[2]])) {
                    $parents[(string) $item[2]] = (string) $parent;
                }
            }
        }

        foreach (is_array($menu) ? $menu : array() as $item) {
            if (!is_array($item) || !isset($item[2]) || self::is_separator($item)) {
                continue;
            }
            $slug  = (string) $item[2];
            $place = self::place($slug, '');
            if (!isset($sections[$place]) && isset($renamed[$slug])) {
                $place = self::place((string) $renamed[$slug], '');
            }
            if (!isset($sections[$place])) {
                // Top-level entries go into sections only.
                $place = self::fallback_section($slug, $parents);
            }
            $groups[$place][] = $item;
            $top[$slug]       = $place;
            $entries[]        = array('slug' => $slug, 'section' => $place, 'top' => true);
        }

        foreach ($out_sub as $parent => $items) {
            $parent = (string) $parent;
            $own    = isset($top[$parent]) ? $top[$parent] : '';
            $first  = true;
            foreach ((array) $items as $position => $item) {
                if (!is_array($item) || !isset($item[2])) {
                    continue;
                }
                $slug     = (string) $item[2];
                $is_first = $first;
                $first    = false;
                $place    = $is_first || $slug === $parent ? '' : self::place($slug, $parent);

                if ('' === $place || $place === $parent || $place === $own || (!isset($sections[$place]) && !isset($top[$place]))) {
                    $entries[] = array('slug' => $slug, 'section' => $own, 'from' => $parent, 'first' => $is_first);
                    continue;
                }

                $url = self::entry_url($slug, $parent);
                unset($out_sub[$parent][$position]);
                if (isset($sections[$place])) {
                    // Becomes a top-level entry in its section. Core prints
                    // top-level addresses as they are; escape like submenus.
                    // Pages without an icon of their own are "plain": in the
                    // Developers menu they go under its Settings entry.
                    $url              = esc_url($url);
                    $groups[$place][] = array(
                        $item[0],
                        $item[1],
                        $url,
                        isset($item[3]) ? $item[3] : $item[0],
                        'menu-top sps-menu-moved' . (isset(self::ICONS[$slug]) ? '' : ' sps-menu-plain'),
                        'sps-menu-moved-' . sanitize_html_class($slug),
                        isset(self::ICONS[$slug]) ? self::ICONS[$slug] : 'dashicons-admin-generic',
                    );
                    $entries[] = array('slug' => $slug, 'section' => $place, 'parent_file' => $url, 'submenu_file' => null, 'from' => $parent);
                } else {
                    // Goes inside another menu.
                    if (empty($out_sub[$place])) {
                        foreach ((array) $menu as $owner_item) {
                            if (isset($owner_item[2]) && $owner_item[2] === $place) {
                                // A menu without entries gets itself as the first one, as core does.
                                $out_sub[$place] = array(array(self::plain_title($owner_item[0]), $owner_item[1], $place, ''));
                                break;
                            }
                        }
                    }
                    $out_sub[$place][] = array($item[0], $item[1], $url, isset($item[3]) ? $item[3] : $item[0], 'sps-menu-moved');
                    $entries[]         = array('slug' => $slug, 'section' => $top[$place], 'parent_file' => $place, 'submenu_file' => $url, 'from' => $parent);
                }
            }
        }

        foreach ($groups as $key => $items) {
            $groups[$key] = self::sort_section($key, $items);
        }
        foreach (self::SORTED_PARENTS as $parent) {
            if (!empty($out_sub[$parent])) {
                $out_sub[$parent] = self::sort_submenu($out_sub[$parent]);
            }
        }

        return array('groups' => $groups, 'submenu' => $out_sub, 'entries' => $entries);
    }

    /**
     * Address that opens a submenu entry where it is registered, so it keeps
     * working from another menu. Mirrors _wp_menu_output().
     *
     * @param string $slug   Entry address.
     * @param string $parent Menu it is in.
     * @return string
     */
    private static function entry_url($slug, $parent) {
        $file = (string) strtok($slug, '?');
        $hook = get_plugin_page_hook($slug, $parent);
        if (empty($hook) && !('index.php' !== $slug && file_exists(WP_PLUGIN_DIR . '/' . $file) && !file_exists(ABSPATH . 'wp-admin/' . $file))) {
            return $slug;
        }
        $parent_file = (string) strtok($parent, '?');
        $base        = file_exists(ABSPATH . 'wp-admin/' . $parent_file) ? $parent : 'admin.php';
        return add_query_arg(array('page' => $slug), $base);
    }

    /**
     * Whether a menu entry is a separator.
     *
     * @param array $item Menu entry.
     * @return bool
     */
    private static function is_separator(array $item) {
        return isset($item[4]) && false !== strpos((string) $item[4], 'wp-menu-separator');
    }

    /**
     * Whether an address is one of WordPress's own screens (not a plugin's
     * page or a plugin's post type or taxonomy).
     *
     * @param string $slug Address.
     * @return bool
     */
    private static function is_core($slug) {
        static $cache = array();
        if (isset($cache[$slug])) {
            return $cache[$slug];
        }
        $parts = self::parse_slug($slug);
        $core  = (bool) preg_match('/^[a-z0-9-]+\.php$/', $parts['file']) && file_exists(ABSPATH . 'wp-admin/' . $parts['file']) && !isset($parts['args']['page']);
        if ($core && isset($parts['args']['post_type'])) {
            $type = get_post_type_object($parts['args']['post_type']);
            $core = $type && !empty($type->_builtin);
        }
        if ($core && isset($parts['args']['taxonomy'])) {
            $tax  = get_taxonomy($parts['args']['taxonomy']);
            $core = $tax && !empty($tax->_builtin);
        }
        $cache[$slug] = $core;
        return $core;
    }

    /**
     * File and query arguments of an address.
     *
     * @param string $slug Address.
     * @return array{file: string, args: array<string,string>}
     */
    private static function parse_slug($slug) {
        $slug  = str_replace('&amp;', '&', (string) $slug);
        $slug  = (string) strtok($slug, '#');
        $parts = explode('?', $slug, 2);
        $args  = array();
        if (isset($parts[1])) {
            wp_parse_str($parts[1], $args);
        }
        return array('file' => $parts[0], 'args' => array_map('strval', array_filter($args, 'is_scalar')));
    }

    /**
     * A title without counts or markup, for sorting.
     *
     * @param string $title Menu title.
     * @return string
     */
    private static function plain_title($title) {
        $title = (string) preg_replace('#<span\b.*$#s', '', (string) $title);
        return trim(wp_strip_all_tags($title));
    }

    /**
     * Order a section: WordPress's entries in their usual order, then the
     * rest A–Z, the first of them marked to show a divider. The top keeps
     * its order after Dashboard.
     *
     * @param string $key   Section key.
     * @param array  $items Menu entries.
     * @return array
     */
    private static function sort_section($key, array $items) {
        if ('top' === $key) {
            usort($items, function ($a, $b) {
                return ('index.php' === $b[2]) - ('index.php' === $a[2]);
            });
            return $items;
        }
        $order = array_flip(self::CORE_ORDER);
        $core  = array();
        $other = array();
        foreach ($items as $item) {
            if (isset($order[$item[2]])) {
                $core[] = $item;
            } else {
                $other[] = $item;
            }
        }
        usort($core, function ($a, $b) use ($order) {
            return $order[$a[2]] - $order[$b[2]];
        });
        usort($other, function ($a, $b) {
            return strnatcasecmp(self::plain_title($a[0]), self::plain_title($b[0]));
        });
        if ($core && $other) {
            $other[0][4] = trim((isset($other[0][4]) ? $other[0][4] : '') . ' sps-menu-divider');
        }
        return array_merge($core, $other);
    }

    /**
     * Order a WordPress menu (Settings, Tools, Appearance) the same way.
     *
     * @param array $items Submenu entries.
     * @return array
     */
    private static function sort_submenu(array $items) {
        $items = array_values($items);
        $first = array_shift($items);
        $core  = array();
        $other = array();
        foreach ($items as $item) {
            if (isset($item[2]) && self::is_core((string) $item[2])) {
                $core[] = $item;
            } else {
                $other[] = $item;
            }
        }
        usort($other, function ($a, $b) {
            return strnatcasecmp(self::plain_title($a[0]), self::plain_title($b[0]));
        });
        if ($other) {
            $other[0][4] = trim((isset($other[0][4]) ? $other[0][4] : '') . ' sps-menu-divider');
        }
        return array_merge(array($first), $core, $other);
    }

    /* --------------------------------------------------------------------- */
    /* Printing                                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Swap in the organised menu just before it is printed.
     *
     * @param string $parent_file Current page's menu.
     * @return string
     */
    public static function organise($parent_file) {
        global $menu, $submenu, $submenu_file;

        // Only in menu-header.php, once.
        if (null !== self::$original || !did_action('admin_head') || !is_array($menu)) {
            return $parent_file;
        }

        $layout    = self::layout();
        $developer = !self::safeguards_on() || self::is_developer();
        $current   = self::current_entry($layout['entries']);
        $out_sub   = $layout['submenu'];
        $self      = isset($GLOBALS['self']) ? $GLOBALS['self'] : null;
        $sub_file  = isset($submenu_file) ? (string) $submenu_file : null;

        if ($current && isset($current['parent_file'])) {
            // The current page moved: open its new place.
            $parent_file        = $current['parent_file'];
            self::$submenu_file = $current['submenu_file'];
            $sub_file           = $current['submenu_file'];
            // Core also marks the menu whose file is the page's file (Tools
            // for tools.php?page=…), where the page no longer is.
            $GLOBALS['self'] = $parent_file; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored on adminmenu.
            if (null !== $current['submenu_file']) {
                // Core looks for the page in the menus once more, first match
                // wins; let it find it in its new menu first, with an entry
                // that is never printed.
                global $plugin_page;
                if (isset($plugin_page) && is_string($plugin_page)) {
                    $out_sub[$parent_file][] = array('', 'do_not_allow', $plugin_page, '');
                }
                $out_sub = array($parent_file => $out_sub[$parent_file]) + $out_sub;
            }
        }

        // Administrators and Developers: menus whose entries open their own
        // submenus to the side (built by the script after the menu), first
        // under the Admin heading.
        $groups        = $layout['groups'];
        $menus         = array();
        self::$flyouts = array();
        foreach (self::MENUS as $key => $icon) {
            $items = $groups[$key];
            unset($groups[$key]);
            if (!$items || ('super-admin' === $key && !$developer)) {
                continue;
            }
            $slug   = 'sps-menu-' . $key;
            $built  = self::build_menu($key, $items, $out_sub, $parent_file, $sub_file, $self);
            if (!$built['entries']) {
                continue;
            }
            $menus[] = array(
                esc_html(self::sections()[$key]),
                'read',
                $slug,
                esc_html(self::sections()[$key]),
                'menu-top sps-menu-group',
                $slug,
                $icon,
            );
            $out_sub[$slug] = $built['entries'];
            if (null !== $built['current']) {
                // The page is in this menu: open it, marking its entry.
                $parent_file        = $slug;
                self::$submenu_file = $built['current'];
                $GLOBALS['self']    = $slug; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored on adminmenu.
                // Last in its list (core links the menu to its first entry), first among the menus.
                $out_sub = array($slug => array_merge($out_sub[$slug], self::page_markers())) + $out_sub;
            }
        }

        // Entries placed under the Admin heading, such as Users, come after the two menus.
        $groups['admin-heading'] = array_merge($menus, $groups['admin-heading']);

        $fold   = (bool) SEOProStack_Settings::get(self::FOLD_KEY);
        $open   = '';
        $folded = $fold ? (string) get_user_setting(self::FOLD, '') : '';
        $new    = array();
        foreach ($groups as $key => $items) {
            foreach ($items as $item) {
                if ($item[2] === $parent_file) {
                    $open = $key;
                }
            }
        }
        foreach ($groups as $key => $items) {
            if (!$items) {
                continue;
            }
            $is_folded = isset(self::CODES[$key]) && $key !== $open && false !== strpos($folded, self::CODES[$key]);
            if ('top' !== $key) {
                $new[] = array(
                    esc_html(self::sections()[$key]),
                    'read',
                    // Not a bare "#…": scripts that match menu links to the
                    // current address (such as WooCommerce's) would take
                    // it for the current page. Without JavaScript it opens
                    // the Dashboard.
                    'index.php#sps-menu-' . $key,
                    '',
                    'sps-menu-heading sps-menu-code-' . self::CODES[$key] . ($fold ? '' : ' sps-menu-static') . ($is_folded ? ' is-folded' : ''),
                    'sps-menu-' . $key,
                    'none',
                );
            }
            foreach ($items as $item) {
                if ($is_folded) {
                    $item[4] = trim((isset($item[4]) ? $item[4] : '') . ' sps-menu-folded');
                }
                $new[] = $item;
            }
        }

        self::$original = array($menu, $submenu, $self);
        $menu           = $new; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- printed now, restored on adminmenu.
        $submenu        = $out_sub; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.
        return $parent_file;
    }

    /**
     * Entries of the Admin or Super Admin menu, with each entry's own
     * submenu kept for the third level (self::$flyouts).
     *
     * @param string      $key         Section key.
     * @param array       $items       Its top-level entries, sorted.
     * @param array       $out_sub     Submenus.
     * @param string      $parent_file Current page's menu.
     * @param string|null $sub_file    Current page's submenu entry.
     * @param string|null $self        Current screen file, as core has it.
     * @return array{entries: array, current: string|null} Entries, and the
     *         address of the one holding the current page.
     */
    private static function build_menu($key, array $items, array $out_sub, $parent_file, $sub_file, $self) {
        global $plugin_page;

        $entries = array();
        $current = null;
        if ('super-admin' === $key) {
            $items = self::developer_settings($items, $parent_file);
        }
        foreach (array_values($items) as $n => $item) {
            $slug  = (string) $item[2];
            $class = isset($item[4]) ? (string) $item[4] : '';
            $moved = false !== strpos($class, 'sps-menu-moved');
            $here  = $slug === $parent_file;
            $kids  = array();
            $first = '';

            if (isset($item['kids'])) {
                // Developers › Settings: its pages are already listed.
                $kids  = $item['kids'];
                $here  = (bool) array_filter(wp_list_pluck($kids, 'c'));
                $moved = true;
            } elseif (!$moved && !empty($out_sub[$slug])) {
                foreach ($out_sub[$slug] as $child) {
                    if (!is_array($child) || !isset($child[2]) || '' === (string) $child[0] || 'do_not_allow' === $child[1] || !current_user_can($child[1])) {
                        continue;
                    }
                    $child_class = isset($child[4]) ? (string) $child[4] : '';
                    if (preg_match('/(^|\s)hidden(\s|$)/', $child_class)) {
                        continue;
                    }
                    $child_slug = (string) $child[2];
                    $url        = str_replace(array('&#038;', '&amp;'), '&', self::entry_url($child_slug, $slug));
                    if ('' === $first) {
                        $first = $url;
                    }
                    $is_current = false;
                    if ($here) {
                        $is_current = null !== $sub_file
                            ? $sub_file === $child_slug
                            : (isset($plugin_page) ? $plugin_page === $child_slug : $self === $child_slug);
                    }
                    $kids[] = array(
                        't'    => $child[0],
                        'u'    => esc_url_raw($url),
                        'c'    => $is_current,
                        'd'    => false !== strpos($child_class, 'sps-menu-divider'),
                        'slug' => $child_slug,
                    );
                }
                if ($here) {
                    $kids = self::mark_post_type_list($kids);
                }
                foreach ($kids as $k => $kid) {
                    unset($kids[$k]['slug']);
                }
            }

            if ($moved) {
                $url = $slug; // Already a full, escaped address.
            } else {
                $url = esc_url('' !== $first ? $first : self::entry_url($slug, 'admin.php'));
            }
            $id      = 'sps-menu-sub-' . $key . '-' . $n;
            $classes = 'sps-menu-entry ' . $id . (false !== strpos($class, 'sps-menu-divider') ? ' sps-menu-divider' : '');
            if ($kids) {
                $classes            .= ' sps-menu-has-sub';
                self::$flyouts[$id] = $kids;
            }
            $entries[] = array(
                self::icon_html(isset($item[6]) ? (string) $item[6] : '') . $item[0],
                $item[1],
                $url,
                isset($item[3]) ? $item[3] : self::plain_title($item[0]),
                $classes,
            );
            if ($here) {
                $current = $url;
            }
        }
        return array('entries' => $entries, 'current' => $current);
    }

    /**
     * When no entry is marked on a post type's screen (such as adding a
     * field group, when the plugin hides its Add New entry), mark the post
     * type's list instead.
     *
     * @param array $kids Third-level entries, with `slug`.
     * @return array
     */
    private static function mark_post_type_list(array $kids) {
        global $typenow;
        if (empty($typenow) || array_filter(wp_list_pluck($kids, 'c'))) {
            return $kids;
        }
        foreach ($kids as $k => $kid) {
            if ('edit.php?post_type=' . $typenow === $kid['slug']) {
                $kids[$k]['c'] = true;
                break;
            }
        }
        return $kids;
    }

    /**
     * Entries that are never printed, for a menu that holds the current
     * page. After the filters, core's get_admin_page_parent() looks for the
     * page in the submenus once more and opens the first menu that has it
     * (by post type, plugin page or screen file); these make that our menu,
     * not the page's own menu, which is not printed.
     *
     * @return array
     */
    private static function page_markers() {
        global $pagenow, $typenow, $plugin_page;
        $files = array();
        if (!empty($typenow)) {
            $files[] = $pagenow . '?post_type=' . $typenow;
        } elseif (is_string($pagenow) && '' !== $pagenow) {
            $files[] = $pagenow;
        }
        if (isset($plugin_page) && is_string($plugin_page) && '' !== $plugin_page) {
            $files[] = $plugin_page;
        }
        $markers = array();
        foreach ($files as $file) {
            $markers[] = array('', 'do_not_allow', $file, '');
        }
        return $markers;
    }

    /**
     * Developers menu: pages moved out of WordPress's menus without an icon
     * of their own (such as Scheduled Actions or a debug log viewer) go
     * under one Settings entry instead of each showing a cog. Order:
     * WordPress's entries, Settings, then plugins' menus A–Z.
     *
     * @param array  $items       The menu's top-level entries, sorted.
     * @param string $parent_file Current page's menu.
     * @return array Entries; the Settings entry has its pages in `kids`.
     */
    private static function developer_settings(array $items, $parent_file) {
        $order = array_flip(self::CORE_ORDER);
        $core  = array();
        $other = array();
        $kids  = array();
        foreach ($items as $item) {
            $class   = isset($item[4]) ? (string) $item[4] : '';
            $item[4] = trim(preg_replace('/(^|\s)sps-menu-divider(?=\s|$)/', ' ', $class));
            if (false !== strpos($class, 'sps-menu-plain')) {
                if (current_user_can($item[1])) {
                    $kids[] = array(
                        't' => $item[0],
                        'u' => esc_url_raw(str_replace(array('&#038;', '&amp;'), '&', (string) $item[2])),
                        'c' => (string) $item[2] === $parent_file,
                        'd' => false,
                    );
                }
            } elseif (isset($order[(string) $item[2]])) {
                $core[] = $item;
            } else {
                $other[] = $item;
            }
        }
        if ($kids) {
            $title  = esc_html__('Settings', 'seoprostack');
            $core[] = array(
                $title,
                'read',
                esc_url($kids[0]['u']),
                $title,
                'menu-top sps-menu-dev-settings',
                '',
                self::DEV_SETTINGS_ICON,
                'kids' => $kids,
            );
        }
        if ($core && $other) {
            $other[0][4] .= ' sps-menu-divider';
        }
        return array_merge($core, $other);
    }

    /**
     * An entry's icon for a submenu: a dashicon, an image, or a blank of
     * the same width.
     *
     * @param string $icon Menu icon (item[6]).
     * @return string
     */
    private static function icon_html($icon) {
        if (0 === strpos($icon, 'dashicons-')) {
            return '<span class="sps-menu-icon dashicons-before ' . esc_attr($icon) . '" aria-hidden="true"></span>';
        }
        if (0 === strpos($icon, 'data:image/') || preg_match('#^https?://#', $icon)) {
            return '<span class="sps-menu-icon" style="background-image:url(&quot;' . esc_attr($icon) . '&quot;)" aria-hidden="true"></span>';
        }
        return '<span class="sps-menu-icon" aria-hidden="true"></span>';
    }

    /**
     * Print the third level of the Admin and Super Admin menus, for the
     * script to add (inside the menu list, where a script may sit).
     */
    public static function print_flyouts() {
        if (!did_action('admin_head')) {
            return;
        }
        printf(
            '<script>window.seoprostackMenuFlyouts && window.seoprostackMenuFlyouts(%s);</script>',
            wp_json_encode(self::$flyouts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        );
        self::$flyouts = array();
    }

    /**
     * Mark the current page's entry when it moved inside another menu.
     *
     * @param string|null $submenu_file Current submenu entry.
     * @return string|null
     */
    public static function submenu_file($submenu_file) {
        return null !== self::$submenu_file ? self::$submenu_file : $submenu_file;
    }

    /**
     * Put the real menu back once it is printed.
     */
    public static function restore() {
        global $menu, $submenu;
        if (null !== self::$original) {
            list($menu, $submenu, $self) = self::$original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
            if (null !== $self) {
                $GLOBALS['self'] = $self; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
            }
            self::$original = null;
        }
    }

    /**
     * The menu entry of the page being viewed: the closest match.
     *
     * @param array $entries Entries from layout().
     * @return array|null
     */
    private static function current_entry(array $entries) {
        global $plugin_page, $pagenow, $typenow;

        $best  = null;
        $score = 0;
        foreach ($entries as $entry) {
            $slug = $entry['slug'];
            if (isset($plugin_page) && is_string($plugin_page) && '' !== $plugin_page) {
                $match = $slug === $plugin_page ? 1 : 0;
            } else {
                $match = self::match_request($slug, (string) $pagenow, (string) $typenow);
            }
            if ($match > $score) {
                $best  = $entry;
                $score = $match;
            }
        }
        return $best;
    }

    /**
     * How closely an address matches the current request: 0 for no match,
     * more for each matching query argument.
     *
     * @param string $slug    Address.
     * @param string $pagenow Current screen file.
     * @param string $typenow Current post type.
     * @return int
     */
    private static function match_request($slug, $pagenow, $typenow) {
        $parts = self::parse_slug($slug);
        $args  = $parts['args'];
        $file  = $parts['file'];
        $posts = array('edit.php', 'post.php', 'post-new.php');
        if ($file !== $pagenow && !('edit.php' === $file && in_array($pagenow, $posts, true))) {
            return 0;
        }
        if (in_array($file, $posts, true)) {
            $want = isset($args['post_type']) ? $args['post_type'] : 'post';
            if ($want !== ('' !== $typenow ? $typenow : 'post')) {
                return 0;
            }
            unset($args['post_type']);
        }
        foreach ($args as $name => $value) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared with menu addresses.
            $got = isset($_GET[$name]) && is_scalar($_GET[$name]) ? sanitize_text_field(wp_unslash((string) $_GET[$name])) : null;
            if (null === $got || sanitize_text_field($value) !== $got) {
                return 0;
            }
        }
        return 1 + count($args);
    }

    /**
     * Styles and the folding script.
     */
    public static function assets() {
        foreach (array('css' => 'admin/css/seoprostack-admin-menu.css', 'js' => 'admin/js/seoprostack-admin-menu.js') as $type => $file) {
            $ver = file_exists(SEOPROSTACK_DIR . $file) ? (string) filemtime(SEOPROSTACK_DIR . $file) : SEOPROSTACK_VERSION;
            if ('css' === $type) {
                wp_enqueue_style(self::HANDLE, SEOPROSTACK_URL . $file, array(), $ver);
            } else {
                // In the head: the menu calls it as soon as it is printed.
                wp_enqueue_script(self::HANDLE, SEOPROSTACK_URL . $file, array('utils'), $ver, false);
                wp_localize_script(self::HANDLE, 'seoprostackAdminMenu', array(
                    'setting' => self::FOLD,
                    'fold'    => (bool) SEOProStack_Settings::get(self::FOLD_KEY),
                ));
            }
        }
    }

    /* --------------------------------------------------------------------- */
    /* Safeguards (people who are not developers)                             */
    /* --------------------------------------------------------------------- */

    /**
     * Refuse Super Admin pages.
     */
    public static function block_page() {
        if (wp_doing_ajax()) {
            return;
        }
        $layout = self::layout();
        $entry  = self::current_entry($layout['entries']);
        if ($entry && 'super-admin' === $entry['section']) {
            wp_die(esc_html__('Sorry, this page is only for developers.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
    }

    /**
     * Take away installing, deleting and editing code, switching developer
     * plugins on or off, and changing developers and administrators.
     *
     * @param string[] $caps    Primitive capabilities.
     * @param string   $cap     Capability checked.
     * @param int      $user_id User ID.
     * @param array    $args    Extra arguments.
     * @return string[]
     */
    public static function map_meta_cap($caps, $cap, $user_id, $args) {
        static $watched = array(
            'activate_plugin'   => true,
            'deactivate_plugin' => true,
            'edit_user'         => true,
            'delete_user'       => true,
            'remove_user'       => true,
            'promote_user'      => true,
        );
        // Return before is_developer(): it checks manage_options, which comes
        // back through this filter.
        if ((!isset(self::BLOCKED_CAPS[$cap]) && !isset($watched[$cap])) || !$user_id || self::is_developer((int) $user_id)) {
            return $caps;
        }
        if (isset(self::BLOCKED_CAPS[$cap])) {
            return array('do_not_allow');
        }
        switch ($cap) {
            case 'activate_plugin':
            case 'deactivate_plugin':
                if (isset($args[0]) && isset(self::developer_plugins()[dirname((string) $args[0])])) {
                    return array('do_not_allow');
                }
                break;
            case 'edit_user':
            case 'delete_user':
            case 'remove_user':
                if (isset($args[0]) && (int) $args[0] !== (int) $user_id && self::is_developer((int) $args[0])) {
                    return array('do_not_allow');
                }
                break;
            case 'promote_user':
                // Also administrators: their role is not offered (see
                // editable_roles()), so the role list would demote them.
                if (isset($args[0]) && (self::is_developer((int) $args[0]) || user_can((int) $args[0], 'manage_options'))) {
                    return array('do_not_allow');
                }
                break;
        }
        return $caps;
    }

    /**
     * Do not offer roles that can manage options, such as Administrator.
     *
     * @param array $roles Roles.
     * @return array
     */
    public static function editable_roles($roles) {
        foreach ((array) $roles as $role => $data) {
            if (!empty($data['capabilities']['manage_options'])) {
                unset($roles[$role]);
            }
        }
        return $roles;
    }

    /**
     * Hide developer plugins from the Plugins screen.
     *
     * @param array $plugins Plugin file => data.
     * @return array
     */
    public static function all_plugins($plugins) {
        $hidden = self::developer_plugins();
        foreach (array_keys((array) $plugins) as $file) {
            if (isset($hidden[dirname((string) $file)])) {
                unset($plugins[$file]);
            }
        }
        return $plugins;
    }

    /**
     * Folders of plugins placed in Super Admin by the catalog or by "Move
     * menu entries", and SEO Pro Stack itself.
     *
     * @return array<string,bool>
     */
    private static function developer_plugins() {
        static $plugins = null;
        if (null === $plugins) {
            $places  = array_merge(self::catalog()['plugins'], self::moves());
            $plugins = array();
            foreach ($places as $folder => $place) {
                if ('super-admin' === $place) {
                    $plugins[(string) $folder] = true;
                }
            }
            // Single-file plugins have no folder; dirname() gives ".".
            unset($plugins['.']);
        }
        return $plugins;
    }

    /* --------------------------------------------------------------------- */
    /* Settings screen                                                        */
    /* --------------------------------------------------------------------- */

    /**
     * Options panel: links to preview the admin as each role, and this
     * site's menu entries with a place to choose for each. Choosing a place
     * writes the "Move menu entries" lines (seoprostack-admin-menu-panel.js).
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel($key, $field = array()) {
        global $menu, $submenu;
        if (self::KEY !== $key || !is_array($menu)) {
            return;
        }

        if (self::enabled() && self::can_preview()) {
            $links = array();
            foreach (self::view_roles() as $role => $label) {
                $links[] = sprintf(
                    '<a href="%1$s" target="_blank" rel="noopener">%2$s<span class="screen-reader-text"> %3$s</span></a>',
                    esc_url(wp_nonce_url(add_query_arg(self::VIEW_ARG, $role, admin_url()), 'seoprostack_view_as')),
                    esc_html($label),
                    esc_html__('(opens in a new tab)', 'seoprostack')
                );
            }
            echo '<div class="sps-panel-note"><p><strong>' . esc_html__('Preview the admin as:', 'seoprostack') . '</strong> ' . implode(' · ', $links) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            echo '<p class="description">' . esc_html__('Opens in a new tab. Until you stop it (or for two hours), every tab of this browser shows the admin as that role.', 'seoprostack') . '</p></div>';
        }

        $sections = self::sections();
        $names    = array();
        $titles   = array();
        foreach ($menu as $item) {
            if (is_array($item) && isset($item[2]) && !self::is_separator($item)) {
                $names[(string) $item[2]] = self::plain_title($item[0]);
            }
        }
        foreach ((array) $submenu as $parent => $items) {
            foreach ((array) $items as $item) {
                if (is_array($item) && isset($item[2])) {
                    $titles[$parent . '>' . $item[2]] = self::plain_title($item[0]);
                }
            }
        }

        $layout      = self::layout();
        self::$moves = array();
        $usual       = self::layout();
        self::$moves = null;
        $usual_place = array();
        foreach ($usual['entries'] as $entry) {
            $usual_place[self::entry_key($entry)] = self::entry_place($entry);
        }

        // Every top-level entry, and plugins' pages in WordPress's menus.
        $order = array_flip(array_keys($sections));
        $rows  = array();
        foreach ($layout['entries'] as $entry) {
            $from  = isset($entry['from']) ? (string) $entry['from'] : '';
            $moved = isset($entry['parent_file']);
            if (empty($entry['top']) && !$moved && (!empty($entry['first']) || !isset(self::CORE_PARENTS[$from]) || self::is_core($entry['slug']))) {
                continue;
            }
            $id    = self::entry_key($entry);
            $place = self::entry_place($entry);
            $title = '' === $from
                ? (isset($names[$entry['slug']]) ? $names[$entry['slug']] : $entry['slug'])
                : (isset($names[$from]) ? $names[$from] . ' › ' : '') . (isset($titles[$from . '>' . $entry['slug']]) ? $titles[$from . '>' . $entry['slug']] : $entry['slug']);
            $rows[] = array(
                'slug'  => $entry['slug'],
                'from'  => $from,
                'title' => $title,
                'place' => $place,
                'usual' => isset($usual_place[$id]) ? $usual_place[$id] : $place,
                'sort'  => isset($order[$entry['section']]) ? $order[$entry['section']] : 99,
            );
        }
        usort($rows, function ($a, $b) {
            return $a['sort'] - $b['sort'] ?: strnatcasecmp($a['title'], $b['title']);
        });

        echo '<div class="sps-panel-note sps-menu-places">';
        echo '<p><strong>' . esc_html__('Menu entries on this site', 'seoprostack') . '</strong></p>';
        echo '<p class="description">' . esc_html__('Choose where each entry goes. Reload the page to see the menu change.', 'seoprostack') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Entry', 'seoprostack') . '</th><th scope="col">' . esc_html__('Address', 'seoprostack') . '</th><th scope="col">' . esc_html__('Place', 'seoprostack') . '</th></tr></thead><tbody>';
        foreach ($rows as $n => $row) {
            $label = 'sps-menu-place-' . $n;
            echo '<tr><td id="' . esc_attr($label) . '">' . esc_html($row['title']) . '</td><td><code>' . esc_html($row['slug']) . '</code></td><td>';
            printf(
                '<select data-sps-menu-place="%1$s" data-sps-menu-from="%2$s" data-sps-menu-usual="%3$s" aria-labelledby="%4$s">',
                esc_attr($row['slug']),
                esc_attr($row['from']),
                esc_attr($row['usual']),
                esc_attr($label)
            );
            foreach ($sections as $section => $name) {
                if ('top' === $section) {
                    $name = __('Top', 'seoprostack');
                } elseif (isset(self::MENUS[$section])) {
                    $name = $sections['admin-heading'] . ' › ' . $name;
                }
                printf('<option value="%1$s"%2$s>%3$s</option>', esc_attr($section), selected($row['place'], $section, false), esc_html($name));
            }
            if ('' !== $row['from']) {
                // Pages can also go inside any menu, including back home.
                echo '<optgroup label="' . esc_attr__('Inside a menu', 'seoprostack') . '">';
                foreach ($names as $slug => $name) {
                    if ('index.php' !== $slug || 'index.php' === $row['from']) {
                        printf('<option value="%1$s"%2$s>%3$s</option>', esc_attr($slug), selected($row['place'], $slug, false), esc_html($name));
                    }
                }
                echo '</optgroup>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table></div>';

        $file = 'admin/js/seoprostack-admin-menu-panel.js';
        wp_enqueue_script(self::HANDLE . '-panel', SEOPROSTACK_URL . $file, array(), (string) filemtime(SEOPROSTACK_DIR . $file), true);
    }

    /**
     * Key of a layout entry: menu it came from and its address.
     *
     * @param array $entry Entry from layout().
     * @return string
     */
    private static function entry_key(array $entry) {
        return (isset($entry['from']) ? $entry['from'] : '') . '>' . $entry['slug'];
    }

    /**
     * Where a layout entry is: a section, or the menu it is inside.
     *
     * @param array $entry Entry from layout().
     * @return string
     */
    private static function entry_place(array $entry) {
        if (!empty($entry['top'])) {
            return $entry['section'];
        }
        if (isset($entry['parent_file'])) {
            // Moved: to a section (its link is the page) or inside a menu.
            return null === $entry['submenu_file'] ? $entry['section'] : (string) $entry['parent_file'];
        }
        return isset($entry['from']) ? (string) $entry['from'] : '';
    }

    /* --------------------------------------------------------------------- */
    /* Preview the admin as another role                                      */
    /* --------------------------------------------------------------------- */

    /**
     * Roles that can be previewed: an administrator who is not a developer,
     * then every role that can open the admin.
     *
     * @return array<string,string> Role => name.
     */
    public static function view_roles() {
        $roles = array(self::VIEW_CLIENT => __('Client administrator', 'seoprostack'));
        foreach (wp_roles()->roles as $role => $data) {
            if ('administrator' !== $role && !empty($data['capabilities']['read'])) {
                $roles[$role] = translate_user_role($data['name']);
            }
        }
        return $roles;
    }

    /**
     * Whether the current person may preview roles: developers who can
     * manage options.
     *
     * @return bool
     */
    private static function can_preview() {
        return '' === self::$view_as && current_user_can('manage_options') && self::is_developer();
    }

    /**
     * Start (?sps_view_as=role) or stop (?sps_view_as=stop) a preview.
     */
    private static function handle_view_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
        if (!isset($_GET[self::VIEW_ARG]) || !is_user_logged_in()) {
            return;
        }
        $role = sanitize_key(wp_unslash($_GET[self::VIEW_ARG])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next.
        check_admin_referer('seoprostack_view_as');

        if ('stop' === $role) {
            self::view_cookie('', 0);
            wp_safe_redirect(admin_url('options-general.php?page=seoprostack&tab=admin'));
            exit;
        }
        if (!self::enabled() || !self::can_preview() || !isset(self::view_roles()[$role])) {
            wp_die(esc_html__('Sorry, you cannot preview the admin as this role.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
        $expires = time() + self::VIEW_TTL;
        self::view_cookie($role . '|' . get_current_user_id() . '|' . $expires, $expires);
        wp_safe_redirect(admin_url());
        exit;
    }

    /**
     * Set or clear the preview cookie, signed so it cannot be made up.
     *
     * @param string $value   "role|user|expires", or '' to clear.
     * @param int    $expires Expiry time.
     */
    private static function view_cookie($value, $expires) {
        $value   = '' === $value ? '' : $value . '|' . hash_hmac('sha256', $value, wp_salt('auth'));
        $options = array(
            'expires'  => $expires ? $expires : time() - YEAR_IN_SECONDS,
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );
        foreach (array_unique(array(COOKIEPATH, SITECOOKIEPATH)) as $path) {
            setcookie(self::VIEW_COOKIE, $value, $options + array('path' => $path ? $path : '/'));
        }
    }

    /**
     * Apply a valid preview cookie: the person gets only the role's
     * capabilities (never more than their own) and is not a developer.
     */
    private static function start_view() {
        if (empty($_COOKIE[self::VIEW_COOKIE]) || !is_user_logged_in()) {
            return;
        }
        $parts = explode('|', sanitize_text_field(wp_unslash($_COOKIE[self::VIEW_COOKIE])));
        if (4 !== count($parts)) {
            return;
        }
        list($role, $user, $expires, $mac) = $parts;
        $value = $role . '|' . $user . '|' . $expires;
        if (!hash_equals(hash_hmac('sha256', $value, wp_salt('auth')), $mac)
            || (int) $user !== get_current_user_id()
            || (int) $expires < time()
            || !isset(self::view_roles()[$role])) {
            return;
        }
        self::$view_as = $role;
        add_filter('map_meta_cap', array(__CLASS__, 'view_caps'), 20, 4);
        add_action('admin_bar_menu', array(__CLASS__, 'view_bar'), 1);
        add_action('admin_footer', array(__CLASS__, 'view_notice'));
        add_action('wp_footer', array(__CLASS__, 'view_notice'));
    }

    /**
     * While previewing, refuse anything the role cannot do. (A filter on
     * map_meta_cap, so it also holds for super admins.)
     *
     * @param string[] $caps    Primitive capabilities.
     * @param string   $cap     Capability checked.
     * @param int      $user_id User ID.
     * @param array    $args    Extra arguments.
     * @return string[]
     */
    public static function view_caps($caps, $cap, $user_id, $args) {
        static $have = null;
        if ((int) $user_id !== get_current_user_id() || '' === self::$view_as) {
            return $caps;
        }
        if (null === $have) {
            $name = self::VIEW_CLIENT === self::$view_as ? 'administrator' : self::$view_as;
            $role = get_role($name);
            $have = $role ? array_filter((array) $role->capabilities) : array();
            $have[$name] = true;
        }
        foreach ((array) $caps as $primitive) {
            if ('exist' !== $primitive && empty($have[$primitive])) {
                return array('do_not_allow');
            }
        }
        return $caps;
    }

    /**
     * Link to stop the preview.
     *
     * @return string
     */
    private static function stop_url() {
        return wp_nonce_url(add_query_arg(self::VIEW_ARG, 'stop', admin_url()), 'seoprostack_view_as');
    }

    /**
     * Admin bar: "Previewing as …: stop".
     *
     * @param WP_Admin_Bar $bar Admin bar.
     */
    public static function view_bar($bar) {
        $bar->add_node(array(
            'id'     => 'sps-view-as',
            'parent' => 'top-secondary',
            /* translators: %s: role name */
            'title'  => esc_html(sprintf(__('Previewing as %s: stop', 'seoprostack'), self::view_roles()[self::$view_as])),
            'href'   => self::stop_url(),
        ));
    }

    /**
     * A notice in the corner with a stop link, also where the admin bar is
     * hidden for the role (such as WooCommerce customers).
     */
    public static function view_notice() {
        printf(
            '<div class="sps-view-as" role="status" style="position:fixed;right:16px;bottom:16px;z-index:100000;padding:8px 14px;border-radius:999px;background:#1d2327;color:#fff;font:13px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.3)">%1$s <a href="%2$s" style="color:#72aee6;margin-left:6px">%3$s</a></div>',
            /* translators: %s: role name */
            esc_html(sprintf(__('Previewing the admin as %s.', 'seoprostack'), self::view_roles()[self::$view_as])),
            esc_url(self::stop_url()),
            esc_html__('Stop', 'seoprostack')
        );
    }
}
