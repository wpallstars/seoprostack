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
 * With Developer admins on (SEOProStack_Developers, which says who is a
 * developer and what only developers can do), people who are not
 * developers do not see or open Developers pages, and do not see or switch
 * developer plugins on the Plugins screen. Until 0.13.0 this and the
 * developer list were this feature's "Client safeguards".
 *
 * Writers (on by default): people who can write posts but not edit other
 * people's, such as contributors and authors, do not see or open plugin
 * pages that ask only for a capability every writer has, plugin post types
 * without the content editor (such as short links), nor Tools.
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

    /** Setting: writers see only screens for writing. */
    const WRITERS_KEY = 'admin_menu_writers';

    /** Setting: "address = place" lines. */
    const MOVES_KEY = 'admin_menu_moves';

    /** Setting: section headings fold. */
    const FOLD_KEY = 'admin_menu_fold';

    /** Setting: the menu widens to fit its names. */
    const FIT_KEY = 'admin_menu_fit';

    /** Option: which plugin owns each admin page, until plugins change. */
    const CACHE = 'seoprostack_admin_menu';

    /**
     * Option: plugin post type => whether it is not for writing, kept for
     * screens where Load plugins only where needed skips its plugin (the
     * menu entry is put back, the post type is not registered).
     */
    const WRITER_TYPES = 'seoprostack_writer_types';

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

    /**
     * Icons for pages that become menu entries (address => icon): a dashicon
     * or, like core's own menu icons, a base64 SVG that core paints in the
     * menu's colours.
     *
     * fluent-mail: FluentSMTP's logo (assets/images/logo.svg in that plugin),
     * one shape with the two bars cut out, in place of the plain cog:
     * <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 300"><path fill="#a7aaad" fill-rule="evenodd" d="M300,30c0,-16.557 -13.443,-30 -30,-30l-240,0c-16.557,0 -30,13.443 -30,30l0,240c0,16.557 13.443,30 30,30l240,0c16.557,0 30,-13.443 30,-30Z M165,25c0,0 -80.084,21.458 -119.113,31.916c-12.32,3.301 -20.887,14.466 -20.887,27.221c0,3.784 0,6.536 0,6.536c0,0 72.08,-19.314 112.8,-30.225c16.044,-4.298 27.2,-18.837 27.2,-35.447Z M111.266,83.11c0,0 -39.848,10.677 -65.379,17.518c-12.32,3.301 -20.887,14.466 -20.887,27.221c0,3.784 0,6.536 0,6.536c0,0 33.783,-9.052 59.066,-15.827c16.044,-4.299 27.2,-18.838 27.2,-35.447Z"/></svg>
     *
     * Also for menus whose icon a plugin draws only with its own CSS on its
     * top-level entry (icon "none"), so it has none in the Administrators and
     * Developers menus:
     *
     * snippets: Code Snippets' logo (assets/menu-icon.svg in that plugin),
     * with a viewBox added so it scales:
     * <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 18.025"><path transform="translate(.032 -50.5)" d="M3.718 58.581h3.104a.833.833 0 0 1 .599 1.412l.004.009L5.3 62.125a3.747 3.747 0 0 0 0 5.304 3.755 3.755 0 0 0 5.304 0 3.755 3.755 0 0 0-1.454-6.207l.558-.558h7.134c1.7 0 3.083-1.446 3.124-3.25 0-.03.004-.054 0-.083a.92.92 0 0 0-.916-.834h-5.175l3.737-3.736a.92.92 0 0 0 .062-1.238c-.02-.025-.04-.042-.062-.062-1.3-1.242-3.304-1.288-4.503-.088l-5.124 5.124h-.91a3.75 3.75 0 1 0-3.358 2.084m1.667-3.75a1.666 1.666 0 1 1-3.333.002 1.666 1.666 0 0 1 3.333-.001m3.745 8.771c.65.65.65 1.704 0 2.354a1.663 1.663 0 0 1-2.358 0 1.664 1.664 0 0 1 0-2.354 1.66 1.66 0 0 1 2.358 0"/></svg>
     */
    const ICONS = array(
        'seoprostack' => 'dashicons-star-filled',
        'fluent-mail' => 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMDAgMzAwIj48cGF0aCBmaWxsPSIjYTdhYWFkIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0zMDAsMzBjMCwtMTYuNTU3IC0xMy40NDMsLTMwIC0zMCwtMzBsLTI0MCwwYy0xNi41NTcsMCAtMzAsMTMuNDQzIC0zMCwzMGwwLDI0MGMwLDE2LjU1NyAxMy40NDMsMzAgMzAsMzBsMjQwLDBjMTYuNTU3LDAgMzAsLTEzLjQ0MyAzMCwtMzBaIE0xNjUsMjVjMCwwIC04MC4wODQsMjEuNDU4IC0xMTkuMTEzLDMxLjkxNmMtMTIuMzIsMy4zMDEgLTIwLjg4NywxNC40NjYgLTIwLjg4NywyNy4yMjFjMCwzLjc4NCAwLDYuNTM2IDAsNi41MzZjMCwwIDcyLjA4LC0xOS4zMTQgMTEyLjgsLTMwLjIyNWMxNi4wNDQsLTQuMjk4IDI3LjIsLTE4LjgzNyAyNy4yLC0zNS40NDdaIE0xMTEuMjY2LDgzLjExYzAsMCAtMzkuODQ4LDEwLjY3NyAtNjUuMzc5LDE3LjUxOGMtMTIuMzIsMy4zMDEgLTIwLjg4NywxNC40NjYgLTIwLjg4NywyNy4yMjFjMCwzLjc4NCAwLDYuNTM2IDAsNi41MzZjMCwwIDMzLjc4MywtOS4wNTIgNTkuMDY2LC0xNS44MjdjMTYuMDQ0LC00LjI5OSAyNy4yLC0xOC44MzggMjcuMiwtMzUuNDQ3WiIvPjwvc3ZnPg==',
        'snippets'    => 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAxOC4wMjUiPjxwYXRoIHRyYW5zZm9ybT0idHJhbnNsYXRlKC4wMzIgLTUwLjUpIiBkPSJNMy43MTggNTguNTgxaDMuMTA0YS44MzMuODMzIDAgMCAxIC41OTkgMS40MTJsLjAwNC4wMDlMNS4zIDYyLjEyNWEzLjc0NyAzLjc0NyAwIDAgMCAwIDUuMzA0IDMuNzU1IDMuNzU1IDAgMCAwIDUuMzA0IDAgMy43NTUgMy43NTUgMCAwIDAtMS40NTQtNi4yMDdsLjU1OC0uNTU4aDcuMTM0YzEuNyAwIDMuMDgzLTEuNDQ2IDMuMTI0LTMuMjUgMC0uMDMuMDA0LS4wNTQgMC0uMDgzYS45Mi45MiAwIDAgMC0uOTE2LS44MzRoLTUuMTc1bDMuNzM3LTMuNzM2YS45Mi45MiAwIDAgMCAuMDYyLTEuMjM4Yy0uMDItLjAyNS0uMDQtLjA0Mi0uMDYyLS4wNjItMS4zLTEuMjQyLTMuMzA0LTEuMjg4LTQuNTAzLS4wODhsLTUuMTI0IDUuMTI0aC0uOTFhMy43NSAzLjc1IDAgMSAwLTMuMzU4IDIuMDg0bTEuNjY3LTMuNzVhMS42NjYgMS42NjYgMCAxIDEtMy4zMzMuMDAyIDEuNjY2IDEuNjY2IDAgMCAxIDMuMzMzLS4wMDFtMy43NDUgOC43NzFjLjY1LjY1LjY1IDEuNzA0IDAgMi4zNTRhMS42NjMgMS42NjMgMCAwIDEtMi4zNTggMCAxLjY2NCAxLjY2NCAwIDAgMSAwLTIuMzU0IDEuNjYgMS42NiAwIDAgMSAyLjM1OCAwIi8+PC9zdmc+',
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

    /**
     * Plugins' entries that lead their section, in this order, before the
     * rest A–Z: the shop plugins' own menus in Shop.
     */
    const LEAD = array(
        'shop' => array('woocommerce', 'fluent-cart'),
    );

    /** Menus sorted like sections: WordPress's entries, then plugins' A–Z. */
    const SORTED_PARENTS = array('options-general.php', 'tools.php', 'themes.php');

    /**
     * Capabilities every contributor or author has. A plugin page that asks
     * only for one of these is open to anyone who can write, usually by
     * accident (settings pages that ask for "read"), so writers do not get it.
     */
    const WRITER_CAPS = array(
        'exist'                  => true,
        'read'                   => true,
        'level_0'                => true,
        'level_1'                => true,
        'level_2'                => true,
        'edit_posts'             => true,
        'delete_posts'           => true,
        'edit_published_posts'   => true,
        'delete_published_posts' => true,
        'publish_posts'          => true,
        'upload_files'           => true,
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
                'default'     => true,
                'tab'         => 'admin',
                'label'       => __('Organise the admin menu', 'seoprostack'),
                'description' => __('Group the menu into Content, Communications, SEO, Shop and Admin, with Administrators and Developers menus that open to the side, the same way on every site. WordPress’s own entries come first, then plugins’ in A–Z order.', 'seoprostack'),
                'replaces'    => array(
                    'admin-menu-editor-pro' => 'Admin Menu Editor Pro',
                    'admin-menu-editor'     => 'Admin Menu Editor',
                ),
            ),
            self::WRITERS_KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'reload'      => true,
                'label'       => __('Writers see only writing', 'seoprostack'),
                'description' => __('Contributors and authors see their posts, media, comments and profile. Plugin pages that any writer could open, such as plugin settings or short links, are hidden and refused. Pages a plugin gives their role on purpose stay.', 'seoprostack'),
            ),
            self::FOLD_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'reload'      => true,
                'label'       => __('Fold sections', 'seoprostack'),
                'description' => __('Click a section heading to hide its entries. Each person’s folded sections are remembered.', 'seoprostack'),
            ),
            self::FIT_KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'reload'      => true,
                'label'       => __('Fit the menu to its names', 'seoprostack'),
                'description' => __('The menu widens, up to 280 pixels, so entry names fit on one line.', 'seoprostack'),
            ),
        );

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
     * Register hooks.
     */
    public static function boot() {
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
            add_filter('all_plugins', array(__CLASS__, 'all_plugins'));
            if (is_admin()) {
                add_action('admin_init', array(__CLASS__, 'block_page'));
            }
        }
        if (is_admin() && SEOProStack_Settings::get(self::WRITERS_KEY)) {
            // Post type screens before admin_menu, where some plugins
            // redirect their lists (Lasso Lite to its welcome page).
            add_action('admin_menu', array(__CLASS__, 'block_writer_type'), 0);
            add_action('admin_init', array(__CLASS__, 'block_writer_page'));
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
        // FluentCRM blanks other plugins' script addresses on its screens
        // unless they are on its list, so print_flyouts() cannot bring it back.
        add_filter('fluent_crm_asset_listed_slugs', array(__CLASS__, 'fluent_crm_scripts'));
        add_action('shutdown', array(__CLASS__, 'save_owners'));
    }

    /**
     * Whether people who are not developers are kept out of the Developers
     * menu and developer plugins: while Developer admins is on.
     *
     * @return bool
     */
    public static function safeguards_on() {
        return SEOProStack_Developers::enabled();
    }

    /**
     * Whether a user is a developer (SEOProStack_Developers::is_developer()).
     * Not while the current user previews another role: see
     * not_developer_in_view().
     *
     * @param int $user_id User ID; the current user by default.
     * @return bool
     */
    public static function is_developer($user_id = 0) {
        return SEOProStack_Developers::is_developer($user_id);
    }

    /**
     * While previewing another role, the person previewing is not a
     * developer anywhere (Developer admins too), so they see what that
     * role sees.
     *
     * @param bool $developer Whether the user is a developer.
     * @param int  $user_id   User ID.
     * @return bool
     */
    public static function not_developer_in_view($developer, $user_id) {
        return '' !== self::$view_as && get_current_user_id() === (int) $user_id ? false : $developer;
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
     * @return array{menus: array<string,string>, plugins: array<string,string>, hidden: array<string,bool>}
     */
    public static function catalog() {
        static $catalog = null;
        if (null === $catalog) {
            $data = require SEOPROSTACK_DIR . 'admin/data/admin-menu.php';
            $data['plugins'][dirname(plugin_basename(SEOPROSTACK_FILE))] = 'super-admin';
            /**
             * Filter where menu entries go.
             *
             * @param array $catalog `menus` (address => place), `plugins`
             *                       (plugin folder => place) and `hidden`
             *                       (addresses left out of the menu). A place
             *                       is a section key or another menu's address.
             */
            $catalog = (array) apply_filters('seoprostack_admin_menu_catalog', $data);
            $catalog += array('menus' => array(), 'plugins' => array(), 'hidden' => array());
            // A list of addresses, or address => true.
            $hidden = array();
            foreach ((array) $catalog['hidden'] as $k => $v) {
                $hidden[is_string($k) ? $k : (string) $v] = true;
            }
            $catalog['hidden'] = $hidden;
        }
        return $catalog;
    }

    /**
     * Whether an entry is left out of the menu: listed as hidden, an
     * upgrade link, or a page without a title. Its page still opens, and
     * keeps its place for the safeguards. A place chosen by hand shows it.
     *
     * @param string $slug   Entry address.
     * @param string $parent Menu it is in ('' for top-level entries).
     * @param array  $item   Menu entry.
     * @return bool
     */
    private static function is_hidden($slug, $parent, array $item) {
        if (null === self::$moves) {
            self::$moves = self::moves();
        }
        foreach (array($parent . '>' . $slug, $slug) as $key) {
            if (isset(self::$moves[$key])) {
                return false;
            }
        }
        $hidden = self::catalog()['hidden'];
        if (isset($hidden[$slug]) || ('' !== $parent && isset($hidden[$parent . '>' . $slug]))) {
            return true;
        }
        if ('' === $parent) {
            return false;
        }
        $title = isset($item[0]) ? (string) $item[0] : '';
        if ('' === trim(wp_strip_all_tags($title)) && false === stripos($title, '<img')) {
            return true;
        }
        return self::is_upsell($title);
    }

    /**
     * Whether a submenu title is only an upgrade link: Upgrade, Upgrade to
     * Pro, Go Pro, Get Pro, Unlock Pro, Premium Upgrade and the like,
     * whatever the case, arrows or exclamation marks.
     *
     * @param string $title Menu title.
     * @return bool
     */
    private static function is_upsell($title) {
        $plain = html_entity_decode(self::plain_title($title), ENT_QUOTES, 'UTF-8');
        $plain = strtolower(trim((string) preg_replace('/[^a-z]+/i', ' ', $plain)));
        return (bool) preg_match('/^(upgrade( to (pro|premium))?|premium upgrade|(go|get|buy|unlock) pro|pricing)$/', $plain);
    }

    /**
     * The "Move menu entries" setting as address (or plugin folder) => place.
     *
     * @return array<string,string>
     */
    public static function moves() {
        $moves = array();
        foreach (preg_split('/[\r\n]+/', (string) SEOProStack_Settings::get(self::MOVES_KEY)) ?: array() as $line) {
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
        if (!$real) {
            // Only stand-ins: the plugin was skipped on this screen. Use the
            // owner Load plugins only where needed learned with it loaded.
            return self::skipped_owner($slug);
        }
        self::$owners[$hook]  = $found;
        self::$owners_changed = true;
        return $found;
    }

    /**
     * Folder of the skipped plugin that owns a page, from what Load plugins
     * only where needed learned, or ''.
     *
     * @param string $slug Page address.
     * @return string
     */
    private static function skipped_owner($slug) {
        if (!class_exists('SEOProStack_Plugin_Loader', false)) {
            return '';
        }
        $state = SEOProStack_Plugin_Loader::state();
        $pages = isset($state['map']['pages']) && is_array($state['map']['pages']) ? $state['map']['pages'] : array();
        if (empty($pages[$slug]) || !is_array($pages[$slug])) {
            return '';
        }
        $file = (string) reset($pages[$slug]);
        return false === strpos($file, '/') ? (string) preg_replace('/\.php$/', '', $file) : (string) strtok($file, '/');
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
     * Hash of the active plugins, the same on screens where Load plugins
     * only where needed skips some (they are still active).
     *
     * @return string
     */
    private static function fingerprint() {
        $plugins = array_values(SEOProStack_Feature::active_plugins());
        if (class_exists('SEOProStack_Plugin_Loader', false)) {
            $state   = SEOProStack_Plugin_Loader::state();
            foreach ((array) $state['skipped'] as $file) {
                // As active_plugins() lists them: plugins in folders.
                if (false !== strpos((string) $file, '/')) {
                    $plugins[] = (string) $file;
                }
            }
        }
        $plugins = array_values(array_unique($plugins));
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
        $writer   = self::is_writer();
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
            $item  = self::image_title($item);
            $slug  = (string) $item[2];
            $place = self::place($slug, '');
            if (!isset($sections[$place]) && isset($renamed[$slug])) {
                $place = self::place((string) $renamed[$slug], '');
            }
            if (!isset($sections[$place])) {
                // Top-level entries go into sections only.
                $place = self::fallback_section($slug, $parents);
            }
            $top[$slug] = $place;
            if (($writer && self::writer_hides_menu($slug, $item, $out_sub)) || self::is_hidden($slug, '', $item)) {
                $entries[] = array('slug' => $slug, 'section' => $place, 'top' => true, 'hidden' => true);
                continue;
            }
            $groups[$place][] = $item;
            $entries[]        = array('slug' => $slug, 'section' => $place, 'top' => true);
        }

        foreach ($out_sub as $parent => $items) {
            $parent = (string) $parent;
            if (!isset($top[$parent])) {
                // Pages registered without a menu ('' or options.php, such as
                // setup wizards): core never shows them, so neither does this.
                foreach ((array) $items as $item) {
                    if (is_array($item) && isset($item[2])) {
                        $place     = self::place((string) $item[2], $parent);
                        $entries[] = array('slug' => (string) $item[2], 'section' => isset($sections[$place]) ? $place : '', 'from' => $parent, 'hidden' => true);
                    }
                }
                continue;
            }
            $own    = $top[$parent];
            $first  = true;
            foreach ((array) $items as $position => $item) {
                if (!is_array($item) || !isset($item[2])) {
                    continue;
                }
                $slug     = (string) $item[2];
                $is_first = $first;
                $first    = false;
                if (($writer && self::writer_hides($slug, $item)) || (!$is_first && $slug !== $parent && self::is_hidden($slug, $parent, $item))) {
                    unset($out_sub[$parent][$position]);
                    $entries[] = array('slug' => $slug, 'section' => $own, 'from' => $parent, 'hidden' => true);
                    continue;
                }
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
                        'from' => $parent,
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
            foreach ($items as $i => $item) {
                $items[$i][0] = self::brand_title($item[0]);
            }
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
     * @return array{file: string, args: array<int|string,string>}
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
        // Counts come after the name in a span; a title that is all in a
        // span (such as a styled "Upgrade" link) keeps its text.
        $plain = trim(wp_strip_all_tags((string) preg_replace('#<span\b.*$#s', '', (string) $title)));
        return '' !== $plain ? $plain : trim(wp_strip_all_tags((string) $title));
    }

    /**
     * A top-level entry whose logo is an image in its title (such as Meow
     * Apps), with the logo moved to the icon. The plugin's own CSS places
     * such an image, only on screens that load the plugin and only in its
     * own menu, so elsewhere it shows full size next to a cog. An SVG logo
     * becomes the icon when the entry has none of its own; core then draws
     * it like every other icon. Other images are left as they are.
     *
     * @param array $item Top-level menu entry.
     * @return array
     */
    public static function image_title(array $item) {
        if (!isset($item[0]) || !is_string($item[0]) || false === stripos($item[0], '<img')) {
            return $item;
        }
        if (!preg_match('#<img\b[^>]*\bsrc=(["\'])(data:image/svg\+xml;base64,[A-Za-z0-9+/=]+)\1[^>]*>#i', $item[0], $img)) {
            return $item;
        }
        $icon    = isset($item[6]) ? (string) $item[6] : '';
        $class   = isset($item[4]) ? (string) $item[4] : '';
        $default = '' === $icon || 'none' === $icon || 'div' === $icon
            || ('dashicons-admin-generic' === $icon && preg_match('/(^|\s)menu-icon-generic(\s|$)/', $class));
        if (!$default) {
            return $item;
        }
        $title = trim((string) preg_replace('#<img\b[^>]*>#i', '', $item[0]));
        if ('' === trim(wp_strip_all_tags($title))) {
            // A logo alone: its alt text, else the page title.
            $title = preg_match('#\balt=(["\'])([^"\']+)\1#i', $img[0], $alt) ? esc_html($alt[2]) : (isset($item[3]) ? (string) $item[3] : '');
        }
        $item[0] = $title;
        // Core hides every icon image on entries marked generic.
        $item[4] = trim((string) preg_replace('/(^|\s)menu-icon-generic(?=\s|$)/', ' ', $class));
        $item[6] = $img[2];
        return $item;
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
        if (isset(self::LEAD[$key])) {
            $rank = array_flip(self::LEAD[$key]);
            $lead = array();
            $rest = array();
            foreach ($other as $item) {
                if (isset($rank[$item[2]])) {
                    $lead[$rank[$item[2]]] = $item;
                } else {
                    $rest[] = $item;
                }
            }
            ksort($lead);
            $other = array_merge(array_values($lead), $rest);
        }
        if ($core && $other) {
            $other[0][4] = trim((isset($other[0][4]) ? $other[0][4] : '') . ' sps-menu-divider');
        }
        return array_merge($core, $other);
    }

    /**
     * A top-level title with the Fluent plugins' names spaced like their
     * others ("Fluent Forms"): FluentCRM becomes Fluent CRM, FluentSMTP
     * Fluent SMTP. Only the name shown in the menu changes.
     *
     * @param mixed $title Menu title.
     * @return mixed
     */
    private static function brand_title($title) {
        if (!is_string($title)) {
            return $title;
        }
        return (string) preg_replace('/^(\s*)Fluent(?=[A-Z])/', '$1Fluent ', $title, 1);
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

        if ($current && !isset($current['parent_file']) && isset($current['from']) && empty($current['hidden']) && !self::is_top($layout['entries'], (string) $parent_file)) {
            // A post type listed in another plugin's menu (such as Kadence's
            // Headers): core passes its own list address here and finds the
            // real menu only after this filter. Use that menu now, so the
            // Admin and Developers menus see which entry holds the page.
            $parent_file = $current['from'];
            if (null === $sub_file) {
                $sub_file = $current['slug'];
            }
        }

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
                    // On a post type's screen, core sets the menu itself as
                    // the entry when it finds no Add New entry (Kadence's Add
                    // New Header marked its Settings page); the post type's
                    // list is marked below instead.
                    if ($here && !($sub_file === $slug && !empty($GLOBALS['typenow']))) {
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
            $icon = isset($item[6]) ? (string) $item[6] : '';
            // "none" and "div": the plugin draws the icon with its own CSS,
            // only on its top-level entry.
            if (in_array($icon, array('', 'none', 'div'), true) && isset(self::ICONS[$slug])) {
                $icon = self::ICONS[$slug];
            }
            $entries[] = array(
                self::icon_html($icon) . $item[0],
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
                        't'    => $item[0],
                        'u'    => esc_url_raw(str_replace(array('&#038;', '&amp;'), '&', (string) $item[2])),
                        'c'    => (string) $item[2] === $parent_file,
                        'd'    => false,
                        'from' => isset($item['from']) ? (string) $item['from'] : '',
                    );
                }
            } elseif (isset($order[(string) $item[2]])) {
                $core[] = $item;
            } else {
                $other[] = $item;
            }
        }
        if ($kids) {
            $kids   = self::name_twins($kids);
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
     * Pages with the same name (such as Plugin Check under Tools and under
     * Settings) get the name of the menu they came from after it.
     *
     * @param array $kids Third-level entries, with `from`.
     * @return array The entries, without `from`.
     */
    private static function name_twins(array $kids) {
        global $menu;
        $count = array_count_values(array_map(function ($kid) {
            return strtolower(self::plain_title($kid['t']));
        }, $kids));
        $names = array();
        foreach ((array) $menu as $item) {
            if (is_array($item) && isset($item[2])) {
                $names[(string) $item[2]] = self::plain_title($item[0]);
            }
        }
        foreach ($kids as $k => $kid) {
            $from = $kid['from'];
            unset($kids[$k]['from']);
            if ($count[strtolower(self::plain_title($kid['t']))] > 1 && isset($names[$from]) && '' !== $names[$from]) {
                $kids[$k]['t'] = $kid['t'] . ' (' . esc_html($names[$from]) . ')';
            }
        }
        return $kids;
    }

    /**
     * An entry's icon for a submenu: a dashicon, an image, or a blank of
     * the same width.
     *
     * An SVG given as a base64 data address is drawn in the text colour, through a
     * mask, as core repaints such icons in its own menu (svg-painter.js only
     * paints top-level icons). Drawn as they are, many are dark (no fill,
     * `currentColor` or grey) and do not show on the dark menu. Other images
     * keep their own colours, as core shows them.
     *
     * @param string $icon Menu icon (item[6]).
     * @return string
     */
    private static function icon_html($icon) {
        if (0 === strpos($icon, 'dashicons-')) {
            return '<span class="sps-menu-icon dashicons-before ' . esc_attr($icon) . '" aria-hidden="true"></span>';
        }
        if (preg_match('#^data:image/svg\+xml;base64,[A-Za-z0-9+/=]+$#', $icon)) {
            return '<span class="sps-menu-icon sps-menu-icon-svg" style="--sps-menu-icon:url(&quot;' . esc_attr($icon) . '&quot;)" aria-hidden="true"></span>';
        }
        if (0 === strpos($icon, 'data:image/') || preg_match('#^https?://#', $icon)) {
            return '<span class="sps-menu-icon" style="background-image:url(&quot;' . esc_attr($icon) . '&quot;)" aria-hidden="true"></span>';
        }
        return '<span class="sps-menu-icon" aria-hidden="true"></span>';
    }

    /**
     * Print the third level of the Admin and Super Admin menus, for the
     * script to add (inside the menu list, where a script may sit).
     *
     * Some plugins take every other plugin's scripts off their own screens
     * (Fluent Forms, Fluent Booking and Fluent Boards do, in
     * wp_print_scripts), which left the menu there unfitted, unfolded and
     * without its third level. The menu script is then printed here, with
     * its settings, just before it is needed. Only this script: the
     * plugin's own choice stands for everything else. FluentCRM blanks the
     * address instead, so it gets the script through its own list
     * (fluent_crm_scripts()).
     */
    public static function print_flyouts() {
        if (!did_action('admin_head')) {
            return;
        }
        if (wp_script_is(self::HANDLE, 'registered') && !wp_script_is(self::HANDLE, 'done')) {
            // do_items(), not wp_print_scripts(): that would run the
            // wp_print_scripts hooks a second time.
            wp_scripts()->do_items(array(self::HANDLE));
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
     * Whether an address is a top-level menu entry.
     *
     * @param array  $entries Entries from layout().
     * @param string $slug    Address.
     * @return bool
     */
    private static function is_top(array $entries, $slug) {
        foreach ($entries as $entry) {
            if (!empty($entry['top']) && $entry['slug'] === $slug) {
                return true;
            }
        }
        return false;
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
                    'setting'  => self::FOLD,
                    'fold'     => (bool) SEOProStack_Settings::get(self::FOLD_KEY),
                    'fit'      => (bool) SEOProStack_Settings::get(self::FIT_KEY),
                    'widthKey' => self::width_key(),
                ));
            }
        }
    }

    /**
     * Key for the remembered menu width: the widest width the menu has
     * needed is kept per person until the active plugins, the language or
     * SEO Pro Stack change. Uses the stored plugin list, not the one this
     * screen loads (Load plugins only where needed skips some per screen).
     *
     * @return string
     */
    private static function width_key() {
        $all     = wp_load_alloptions();
        $plugins = isset($all['active_plugins']) ? maybe_unserialize($all['active_plugins']) : get_option('active_plugins', array());
        return substr(md5((string) wp_json_encode(array(
            is_array($plugins) ? $plugins : array(),
            is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', array())) : array(),
            get_user_locale(),
            SEOPROSTACK_VERSION,
        ))), 0, 8);
    }

    /**
     * Let the menu script load on FluentCRM's screens, which keep only the
     * scripts whose address matches its list (regular expressions joined
     * with "|" between "/" delimiters). Adds this one script, nothing else.
     *
     * @param mixed $slugs Patterns from FluentCRM.
     * @return mixed
     */
    public static function fluent_crm_scripts($slugs) {
        if (!is_array($slugs)) {
            return $slugs;
        }
        $path    = wp_parse_url(SEOPROSTACK_URL . 'admin/js/seoprostack-admin-menu.js', PHP_URL_PATH);
        $slugs[] = preg_quote(is_string($path) ? $path : 'seoprostack-admin-menu.js', '/');
        return $slugs;
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

    /* --------------------------------------------------------------------- */
    /* Writers (contributors and authors)                                     */
    /* --------------------------------------------------------------------- */

    /**
     * Whether the current person is a writer: they can write posts but not
     * edit other people's, such as contributors and authors. Editors, shop
     * managers and administrators are not.
     *
     * @return bool
     */
    public static function is_writer() {
        static $cache = array();
        $user_id = get_current_user_id();
        $key     = $user_id . '|' . self::$view_as;
        if (!isset($cache[$key])) {
            $writer = $user_id
                && SEOProStack_Settings::get(self::WRITERS_KEY)
                && current_user_can('edit_posts')
                && !current_user_can('edit_others_posts')
                && !current_user_can('manage_options');
            /**
             * Filter whether the current person sees only writing screens.
             *
             * @param bool $writer  Whether they are a writer.
             * @param int  $user_id User ID.
             */
            $cache[$key] = (bool) apply_filters('seoprostack_is_writer', $writer, $user_id);
        }
        return $cache[$key];
    }

    /**
     * Whether writers do not get a menu entry's page: a plugin's page that
     * asks only for a capability every writer has, and Tools, which offers
     * writers nothing of WordPress's own, and the screens of plugin post
     * types that are not for writing (see writer_hides_type()). Other
     * WordPress screens keep WordPress's own checks.
     *
     * @param string $slug Entry address.
     * @param array  $item Menu entry (capability in [1]).
     * @return bool
     */
    private static function writer_hides($slug, array $item) {
        $cap   = isset($item[1]) && is_string($item[1]) ? $item[1] : '';
        $parts = self::parse_slug($slug);
        $wp    = !isset($parts['args']['page']) && preg_match('/^[a-z0-9-]+\.php$/', $parts['file']) && file_exists(ABSPATH . 'wp-admin/' . $parts['file']);
        $hide  = 'tools.php' === $slug || ('' !== $cap && isset(self::WRITER_CAPS[$cap]) && !$wp)
            || ($wp && isset($parts['args']['post_type']) && self::writer_hides_type($parts['args']['post_type']));
        /**
         * Filter whether writers do not get a menu page.
         *
         * @param bool   $hide Whether the page is hidden and refused.
         * @param string $slug Menu address, such as a plugin page's slug.
         * @param string $cap  Capability the page asks for.
         */
        return (bool) apply_filters('seoprostack_writer_hides_page', $hide, $slug, $cap);
    }

    /**
     * Whether a post type is not for writing: a plugin's post type without
     * the content editor, such as short or affiliate links (Lasso Lite),
     * which often uses the same permissions as posts. Post types with the
     * editor are writing, and WordPress's own keep core's checks.
     *
     * @param string $type Post type name.
     * @return bool
     */
    private static function writer_hides_type($type) {
        static $cache = array();
        $type = (string) $type;
        if (!isset($cache[$type])) {
            $known = self::learn_writer_types();
            if (get_post_type_object($type)) {
                $hide = empty(get_post_type_object($type)->_builtin) && !post_type_supports($type, 'editor');
            } else {
                // Its plugin is skipped on this screen: use what was seen
                // where it loads.
                $hide = !empty($known[$type]);
            }
            /**
             * Filter whether writers do not get a post type's screens.
             *
             * @param bool   $hide Whether its list, new and edit screens are hidden and refused.
             * @param string $type Post type name.
             */
            $cache[$type] = (bool) apply_filters('seoprostack_writer_hides_post_type', $hide, $type);
        }
        return $cache[$type];
    }

    /**
     * Whether writers do not get a top-level entry: every page in its
     * submenu is hidden from them, or, without a submenu, its own page.
     * (With a submenu, core links the entry to the first page in it and
     * prints an empty entry when there is none, whatever the entry's own
     * capability, which is often one the writer lacks.)
     *
     * @param string $slug    Entry address.
     * @param array  $item    Menu entry.
     * @param array  $submenu Submenus.
     * @return bool
     */
    private static function writer_hides_menu($slug, array $item, array $submenu) {
        $children = array();
        foreach (isset($submenu[$slug]) ? (array) $submenu[$slug] : array() as $child) {
            if (is_array($child) && isset($child[2])) {
                $children[] = $child;
            }
        }
        if (!$children) {
            return self::writer_hides($slug, $item);
        }
        foreach ($children as $child) {
            if (!self::writer_hides((string) $child[2], $child)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Note which plugin post types registered here are not for writing, for
     * screens that skip their plugin. Runs on every admin screen (for anyone)
     * and writes only when a post type is new or changes.
     *
     * @return array<string,bool> Post type => not for writing.
     */
    private static function learn_writer_types() {
        static $known = null;
        if (null !== $known) {
            return $known;
        }
        $stored = get_option(self::WRITER_TYPES);
        $known  = is_array($stored) ? array_map('boolval', $stored) : array();
        foreach (get_post_types(array('_builtin' => false, 'show_ui' => true)) as $type) {
            $known[$type] = !post_type_supports($type, 'editor');
        }
        if ($known !== $stored) {
            update_option(self::WRITER_TYPES, $known, true);
        }
        return $known;
    }

    /**
     * Refuse writers the list, Add New, edit and term screens of post types
     * hidden from them.
     */
    public static function block_writer_type() {
        global $plugin_page, $pagenow, $typenow;
        self::learn_writer_types();
        if (wp_doing_ajax() || (is_string($plugin_page) && '' !== $plugin_page) || !self::is_writer()) {
            return;
        }
        $type = '';
        if (in_array($pagenow, array('edit.php', 'post-new.php', 'edit-tags.php', 'term.php'), true)) {
            $type = '' !== (string) $typenow ? (string) $typenow : 'post';
        } elseif ('post.php' === $pagenow) {
            // phpcs:ignore WordPress.Security.NonceVerification -- only read to refuse the screen.
            $id   = isset($_GET['post']) ? absint($_GET['post']) : (isset($_POST['post_ID']) ? absint($_POST['post_ID']) : 0);
            $type = $id ? (string) get_post_type($id) : '';
        }
        if ('' !== $type && self::writer_hides_type($type)) {
            wp_die(esc_html__('Sorry, this page is not for writers.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
        }
    }

    /**
     * Refuse writers the plugin pages hidden from them.
     */
    public static function block_writer_page() {
        global $plugin_page, $menu, $submenu;
        if (wp_doing_ajax() || !is_string($plugin_page) || '' === $plugin_page || !self::is_writer()) {
            return;
        }
        $items = is_array($menu) ? $menu : array();
        foreach (is_array($submenu) ? $submenu : array() as $children) {
            $items = array_merge($items, (array) $children);
        }
        foreach ($items as $item) {
            if (is_array($item) && isset($item[2]) && $item[2] === $plugin_page) {
                if (self::writer_hides($plugin_page, $item)) {
                    wp_die(esc_html__('Sorry, this page is not for writers.', 'seoprostack'), '', array('response' => 403, 'back_link' => true));
                }
                return;
            }
        }
    }

    /**
     * Take away switching developer plugins on or off. (Everything else
     * only developers can do is in SEOProStack_Developers.)
     *
     * @param string[] $caps    Primitive capabilities.
     * @param string   $cap     Capability checked.
     * @param int      $user_id User ID.
     * @param array    $args    Extra arguments.
     * @return string[]
     */
    public static function map_meta_cap($caps, $cap, $user_id, $args) {
        // Return before is_developer(): it checks manage_options, which comes
        // back through this filter.
        if (('activate_plugin' !== $cap && 'deactivate_plugin' !== $cap) || !$user_id || !isset($args[0]) || self::is_developer((int) $user_id)) {
            return $caps;
        }
        return isset(self::developer_plugins()[dirname((string) $args[0])]) ? array('do_not_allow') : $caps;
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
            if (!empty($entry['hidden'])) {
                /* translators: %s: menu entry name. */
                $title = sprintf(__('%s (hidden)', 'seoprostack'), $title);
            }
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
        echo '<p class="description">' . esc_html__('Choose where each entry goes. Reload the page to see the menu change. Upgrade links, pages without a name and setup prompts are hidden; another place, or a typed line, shows them.', 'seoprostack') . '</p>';
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
        add_filter('seoprostack_is_developer', array(__CLASS__, 'not_developer_in_view'), PHP_INT_MAX, 2);
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
