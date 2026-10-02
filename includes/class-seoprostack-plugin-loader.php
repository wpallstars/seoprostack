<?php
/**
 * Load chosen plugins in wp-admin only on the screens that need them.
 *
 * Runs from a small must-use file that SEO Pro Stack writes when "Load
 * plugins only where needed" is switched on (see
 * SEOProStack_Plugin_Loading), so it starts before any plugin loads and may
 * use only WordPress core and its own options.
 *
 * It filters the `active_plugins` option for the whole request, so skipped
 * plugins look inactive to everything else too. It only does so on
 * GET requests for known wp-admin screens by visitors with a login cookie,
 * and only once SEO Pro Stack has seen the screen with every plugin
 * loaded ("learning"). Everything else loads every plugin: saving
 * (POST), links carrying a nonce or an action, AJAX, REST, cron, WP-CLI,
 * the Plugins, update, settings and widgets screens, the
 * Customizer, screens of plugins it does not know yet, and the site itself.
 *
 * A ticked plugin loads:
 * - on its own screens: pages it added to the menu, and the posts and
 *   terms of post types and taxonomies it registered;
 * - on post, term, list, profile, user, Tools and media upload screens
 *   where it adds boxes, fields, blocks or editor features, or saves
 *   profile fields (learned per screen with every plugin loaded);
 * - on post, media, term and user lists where it adds a column that shows,
 *   a filter, a view, a row link or a bulk action (seen in what it changes
 *   or prints there while every plugin loads, so plugins whose columns are
 *   removed do not count);
 * - on the Dashboard when one of its boxes shows there (after Tidy the
 *   dashboard has hidden the ones nobody sees);
 * - on Appearance > Menus when it changes menus or their items there,
 *   saves item fields, or owns a post type or taxonomy that can be added
 *   to menus or is in one (every plugin when a skipped one adds a menu
 *   location: check_menu_locations());
 * - on Appearance > Editor when it registers blocks, adds editor features,
 *   templates or styles, or (with a block theme) owns a post type or
 *   taxonomy people view;
 * - on SEO Pro Stack's settings page when it registers a post type,
 *   taxonomy or widget, changes permissions or uses SEO Pro Stack's hooks
 *   (the settings offer those as choices);
 * - wherever a plugin that needs it loads (`Requires Plugins`,
 *   `WC requires at least`, `Elementor tested up to`).
 * Plugins that need a ticked plugin follow it: they load where it loads.
 *
 * On the site itself, plugins ticked for the site ("Plugins to skip on the
 * site") are skipped on GET requests for pages (index.php, not the REST
 * API) whose query arguments are only search, page numbers and campaign
 * tags, for visitors who are not logged in, and for logged-in people too
 * when chosen. Plugins that change logins or the plugin list, or replace
 * a core (pluggable) function, always load, and so does a ticked plugin
 * that a loading plugin needs. What each plugin adds to the site is
 * learned once per set of active plugins, on a page view that loads every
 * plugin (SEOProStack_Plugin_Loading::learn_front()).
 *
 * Safety: nothing is ever deactivated (writes to `active_plugins` during a
 * filtered request keep every plugin), rewrite rules generated with fewer
 * plugins are never saved, and a screen that hits a fatal error or makes
 * a plugin try to deactivate itself loads every plugin from then on (on
 * the site: every page, until the list of plugins for the site is saved
 * again). `?seoprostack-load-all=1` loads every plugin for one request and
 * learns that screen again; the SEOPROSTACK_LOAD_ALL_PLUGINS constant
 * turns filtering off.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Plugin_Loader {

    /** Learned map: owners, per-screen needs, dependencies. Autoloaded. */
    const MAP = 'seoprostack_plugin_map';

    /** Admin menu as seen with every plugin loaded. Not autoloaded. */
    const MENU = 'seoprostack_plugin_menu';

    /** Query arg that loads every plugin for one request. */
    const LOAD_ALL_ARG = 'seoprostack-load-all';

    /** Settings keys (read straight from the stored options). */
    const SWITCH_KEY = 'plugin_loading';
    const LIST_KEY   = 'plugin_loading_only';

    /** Map format; a change makes SEO Pro Stack learn again. */
    const MAP_VERSION = 5;

    /** SEO Pro Stack's own settings page (Settings > SEO Pro Stack). */
    const SETTINGS_PAGE = 'seoprostack';

    /** What each plugin adds to the site, learned on a page view. Autoloaded. */
    const FRONT = 'seoprostack_plugin_front';

    /** Time the site's learning started, so one request at a time learns. Not autoloaded. */
    const FRONT_LOCK = 'seoprostack_plugin_front_lock';

    /** Settings keys for the site: plugins to skip, and whether for logged-in people too. */
    const FRONT_KEY       = 'plugin_loading_front';
    const FRONT_USERS_KEY = 'plugin_loading_front_users';

    /** Format of what is learned on the site; a change makes it learn again. */
    const FRONT_VERSION = 2;

    /** Opt-in page learning and plugins the owner wants to keep loading. */
    const PAGES_KEY = 'plugin_loading_pages';
    const KEEP_KEY  = 'plugin_loading_pages_keep';

    /** Bound anonymous learning storage; unknown pages always load everything. */
    const PAGE_LIMIT = 100;

    /**
     * Query arguments that leave a page of the site as it is: search, page
     * numbers and campaign tags. Any other argument may be a link that a
     * plugin acts on (unsubscribe, download, login, add to cart), so such
     * requests load every plugin.
     */
    const FRONT_ARGS = array(
        's', 'paged', 'page', 'cpage', 'p', 'page_id',
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'dclid', 'twclid', 'ttclid', 'mc_cid', 'mc_eid', '_ga', '_gl',
    );

    /**
     * Hooks whose callbacks show that a plugin adds boxes, fields, blocks or
     * editor features to the post editor.
     */
    const EDITOR_HOOKS = array(
        'do_meta_boxes', 'post_edit_form_tag', 'edit_form_top', 'edit_form_before_permalink',
        'edit_form_after_title', 'edit_form_after_editor', 'edit_form_advanced', 'edit_page_form',
        'post_submitbox_minor_actions', 'post_submitbox_misc_actions', 'post_submitbox_start',
        'page_attributes_misc_attributes', 'dbx_post_sidebar',
        'enqueue_block_editor_assets', 'enqueue_block_assets', 'block_editor_settings_all',
        'use_block_editor_for_post', 'use_block_editor_for_post_type', 'replace_editor',
        'wp_editor_settings', 'the_editor', 'media_buttons', 'user_can_richedit',
        'tiny_mce_before_init', 'mce_external_plugins', 'mce_buttons', 'mce_buttons_2',
        'default_content', 'default_title', 'load-post.php', 'load-post-new.php',
        'admin_head-post.php', 'admin_head-post-new.php', 'admin_footer-post.php', 'admin_footer-post-new.php',
    );

    /** Editor hooks for media items only. */
    const ATTACHMENT_HOOKS = array('attachment_submitbox_misc_actions');

    /** Hooks that add fields to the add and edit term forms. */
    const TERM_HOOKS = array('load-edit-tags.php', 'load-term.php');

    /** Term form hooks, after the taxonomy name. */
    const TERM_HOOK_SUFFIXES = array(
        '_add_form_fields', '_edit_form_fields', '_add_form', '_edit_form',
        '_pre_add_form', '_pre_edit_form', '_term_edit_form_top', '_term_edit_form_tag',
    );

    /** Hooks that add fields to Quick Edit and Bulk Edit on list screens. */
    const LIST_HOOKS = array('bulk_edit_custom_box', 'quick_edit_custom_box', 'add_inline_data');

    /**
     * Hooks that add fields to the profile, edit user and add user forms,
     * or save them. Saving loads every plugin, so a plugin that saves
     * profile fields must show them too, or saving would clear them.
     */
    const USER_HOOKS = array(
        'user_edit_form_tag', 'admin_color_scheme_picker', 'personal_options', 'profile_personal_options',
        'show_user_profile', 'edit_user_profile', 'user_contactmethods', 'user_profile_picture_description',
        'show_password_fields', 'additional_capabilities_display', 'wp_create_application_password_form',
        'enable_edit_any_user_configuration', 'user_new_form', 'user_new_form_tag',
        'personal_options_update', 'edit_user_profile_update', 'user_profile_update_errors', 'edit_user_created_user',
    );

    /** Hooks that add tools to Tools > Available tools. */
    const TOOLS_HOOKS = array('tool_box');

    /** Hooks that change the upload form on Media > Add New. */
    const UPLOAD_HOOKS = array(
        'pre-upload-ui', 'pre-plupload-upload-ui', 'post-plupload-upload-ui', 'pre-html-upload-ui',
        'post-html-upload-ui', 'post-upload-ui', 'upload_post_params', 'plupload_init',
        'plupload_default_settings', 'plupload_default_params', 'upload_ui_over_quota',
    );

    /**
     * Hooks that add fields, columns or boxes to Appearance > Menus, change
     * menus or their items as shown there, or save them. Saving loads every
     * plugin, so a plugin that saves item fields must show them too.
     */
    const MENU_HOOKS = array(
        'wp_nav_menu_item_custom_fields', 'wp_edit_nav_menu_walker', 'wp_setup_nav_menu_item', 'nav_menu_meta_box_object',
        'manage_nav-menus_columns', 'wp_update_nav_menu_item', 'wp_update_nav_menu', 'wp_add_nav_menu_item',
        'wp_create_nav_menu', 'wp_get_nav_menu_items', 'wp_get_nav_menus', 'wp_get_nav_menu_object', 'wp_get_nav_menu_name',
        'theme_mod_nav_menu_locations', 'pre_set_theme_mod_nav_menu_locations', 'wp_nav_menu_max_depth',
    );

    /**
     * Hooks that add to Appearance > Editor: blocks and editor features,
     * and the templates, styles and settings it reads through REST in the
     * same request. Saving goes through REST, which loads every plugin.
     */
    const SITE_EDITOR_HOOKS = array(
        'enqueue_block_editor_assets', 'enqueue_block_assets', 'block_editor_settings_all', 'block_categories_all',
        'allowed_block_types_all', 'block_editor_rest_api_preload_paths', 'should_load_remote_block_patterns',
        'get_block_templates', 'pre_get_block_templates', 'get_block_template', 'pre_get_block_template',
        'get_block_file_template', 'pre_get_block_file_template', 'default_template_types', 'default_wp_template_part_areas',
        'wp_theme_json_data_default', 'wp_theme_json_data_blocks', 'wp_theme_json_data_theme', 'wp_theme_json_data_user',
        'wp_theme_json_get_style_nodes', 'site_editor_no_javascript_message',
    );

    /** wp-admin scripts of each screen kind learned from hooks. */
    const KIND_SCRIPTS = array(
        'user'        => array('profile.php', 'user-edit.php', 'user-new.php'),
        'tools'       => array('tools.php'),
        'media-new'   => array('media-new.php'),
        'menus'       => array('nav-menus.php'),
        'site-editor' => array('site-editor.php'),
    );

    /**
     * Whether a hook's callbacks mean a plugin must load on a screen, so
     * forms there keep every field. Hooks for other post types and
     * taxonomies do not count.
     *
     * @param string $kind Screen kind: "post", "terms", "list", "user", "tools", "media-new", "menus" or "site-editor".
     * @param string $name Post type or taxonomy of the screen.
     * @param string $hook Hook name.
     * @return bool
     */
    public static function screen_needs_hook($kind, $name, $hook) {
        // Hooks for these screens only, such as "load-profile.php" or
        // "admin_footer-tools.php".
        if (array_key_exists($kind, self::KIND_SCRIPTS)) {
            foreach (self::KIND_SCRIPTS[$kind] as $script) {
                $suffix = '-' . $script;
                if (strlen($hook) > strlen($suffix) && substr($hook, -strlen($suffix)) === $suffix) {
                    return true;
                }
            }
        }
        switch ($kind) {
            case 'post':
                return in_array($hook, self::EDITOR_HOOKS, true)
                    || 'add_meta_boxes' === $hook || 'add_meta_boxes_' . $name === $hook
                    || ('attachment' === $name && in_array($hook, self::ATTACHMENT_HOOKS, true));
            case 'terms':
                if (in_array($hook, self::TERM_HOOKS, true)) {
                    return true;
                }
                return '' !== $name && 0 === strpos($hook, $name . '_')
                    && in_array(substr($hook, strlen($name)), self::TERM_HOOK_SUFFIXES, true);
            case 'list':
                return in_array($hook, self::LIST_HOOKS, true);
            case 'user':
                // Labels of contact fields: "user_{field}_label".
                return in_array($hook, self::USER_HOOKS, true)
                    || (0 === strpos($hook, 'user_') && '_label' === substr($hook, -6) && strlen($hook) > 11);
            case 'tools':
                return in_array($hook, self::TOOLS_HOOKS, true);
            case 'media-new':
                return in_array($hook, self::UPLOAD_HOOKS, true);
            case 'menus':
                // Items of each kind in the Add menu items boxes: "nav_menu_items_{type}".
                return in_array($hook, self::MENU_HOOKS, true) || 0 === strpos($hook, 'nav_menu_items_');
            case 'site-editor':
                return in_array($hook, self::SITE_EDITOR_HOOKS, true);
        }
        return false; // Comments, the users list, themes and About add no fields to forms; Dashboard boxes are learned from the boxes themselves.
    }

    /**
     * Hooks that make a plugin load everywhere: it changes the login
     * address, or it filters the plugin list itself.
     */
    const ALWAYS_HOOKS = array('login_url', 'auth_redirect', 'option_active_plugins', 'pre_option_active_plugins');

    /** Permission hooks, flagged in the plugin list so admins can decide. */
    const PERMISSION_HOOKS = array('user_has_cap', 'map_meta_cap', 'role_has_cap', 'editable_roles', 'determine_current_user');

    /** Scripts that are never filtered, even with a page argument. */
    const NEVER_PAGES = array(
        'plugins.php', 'plugin-install.php', 'update.php', 'update-core.php', 'upgrade.php',
        'customize.php', 'options.php', 'admin-post.php', 'admin-ajax.php', 'async-upload.php',
    );

    /** Scripts that are never filtered without a page argument. */
    const NEVER = array(
        'plugins.php', 'plugin-install.php', 'plugin-editor.php', 'update.php', 'update-core.php',
        'upgrade.php', 'customize.php', 'options.php', 'admin-post.php', 'admin-ajax.php',
        'async-upload.php', 'site-health.php', 'import.php', 'export.php', 'theme-editor.php',
        // Opening Widgets saves the sidebars without widgets and sidebars
        // that are not registered (retrieve_widgets()), so a skipped plugin's
        // widgets would be dropped.
        'widgets.php',
    );

    /** Read-only About screens: they need no plugin. */
    const ABOUT = array('about.php', 'credits.php', 'freedoms.php', 'privacy.php', 'contribute.php');

    /**
     * Request state: '' (not started or off), 'full' (every plugin
     * loads; SEO Pro Stack may learn), 'filter' (some plugins skipped).
     *
     * @var string
     */
    private static $mode = '';

    /** @var string Screen key, such as "post:product" or "page:wpforms-overview". */
    private static $screen = '';

    /** @var string[] Active plugin files as stored. */
    private static $raw = array();

    /** @var string[] Plugin files skipped on this request. */
    private static $skipped = array();

    /** @var array Learned map, or empty when it must be learned. */
    private static $map = array();

    /** @var bool Whether post types, taxonomies and blocks are being attributed. */
    private static $attributing = false;

    /** @var array{types: array, taxes: array, blocks: array} Attributions made on this request. */
    private static $registered = array('types' => array(), 'taxes' => array(), 'blocks' => array());

    /** @var string This plugin's main file, relative to the plugins folder. */
    private static $self = '';

    /** @var bool Whether the current screen has been marked to load everything. */
    private static $flagged = false;

    /** @var bool Whether someone asked a page of the site to learn again (?seoprostack-load-all=1). */
    private static $relearn = false;

    /**
     * Why a 'full' request loads every plugin: 'always' (a screen that is
     * never filtered), 'learning' (not learned yet, or learned again),
     * 'error' (a plugin failed here with fewer plugins) or 'needed' (the
     * screen needs every ticked plugin).
     *
     * @var string
     */
    private static $reason = '';

    /**
     * Start: decide whether this request may be filtered.
     *
     * @param string $self SEO Pro Stack's plugin file, such as "seoprostack/seoprostack.php".
     */
    public static function start($self) {
        if ('' !== self::$mode) {
            return;
        }
        self::$mode = 'off';
        self::$self = (string) $self;

        $raw = get_option('active_plugins', array());
        self::$raw = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : array();

        $network = is_multisite() ? (array) get_site_option('active_sitewide_plugins', array()) : array();
        if (!in_array(self::$self, self::$raw, true) && !isset($network[self::$self])) {
            return; // SEO Pro Stack is not active here; the must-use file is left over.
        }

        $options = get_option('seoprostack_options', array());
        if (!is_array($options) || empty($options[self::SWITCH_KEY])) {
            return;
        }
        if (!is_admin()) {
            self::start_front($options);
            return;
        }
        if (empty($options[self::LIST_KEY]) || !is_array($options[self::LIST_KEY])) {
            return;
        }
        $chosen = array_values(array_intersect(self::$raw, $options[self::LIST_KEY]));
        $chosen = array_values(array_diff($chosen, array(self::$self)));
        if (!$chosen || !self::filterable_request()) {
            return;
        }

        self::$mode   = 'full';
        $script       = self::script();
        self::$screen = '' !== $script ? self::screen_key($script) : '';
        $map          = get_option(self::MAP, array());
        self::$map    = self::map_is_current($map) ? $map : array();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only loads more plugins.
        $relearn = isset($_GET[self::LOAD_ALL_ARG]);
        if (!self::$map || $relearn) {
            self::attribute();
        }
        if ('' === self::$screen) {
            self::$reason = 'always';
            return;
        }
        // Asked to load every plugin: this request learns the screen again.
        self::$reason = 'learning';
        if (!self::$map || $relearn) {
            return;
        }
        if (isset(self::$map['load_all'][self::$screen])) {
            self::$reason = 'error';
            return;
        }

        $loaded = self::plugins_for_screen(self::$screen, $chosen);
        if (null === $loaded) {
            return; // Not learned yet: this request loads everything and learns it.
        }
        self::$skipped = array_values(array_diff(self::$raw, $loaded));
        if (!self::$skipped) {
            self::$reason = 'needed';
            return;
        }
        self::filter();
        // A page whose plugin was skipped is not registered: core would say
        // "not allowed". Load it again with every plugin instead.
        add_action('admin_page_access_denied', array(__CLASS__, 'reload_denied'), 0);
        if ('menus' === self::$screen) {
            add_action('load-nav-menus.php', array(__CLASS__, 'check_menu_locations'), 0);
        }
    }

    /**
     * Appearance > Menus without a menu location that was there with every
     * plugin: a skipped plugin adds it. Saving a menu (which loads every
     * plugin) would take the menu out of locations that were not shown, so
     * the screen needs every plugin from now on. Reload it that way.
     */
    public static function check_menu_locations() {
        if ('filter' !== self::$mode || !function_exists('get_registered_nav_menus')) {
            return;
        }
        $learned = isset(self::$map['menu_locations']) ? (array) self::$map['menu_locations'] : array();
        if (!array_diff($learned, array_keys(get_registered_nav_menus()))) {
            return;
        }
        wp_cache_delete(self::MAP, 'options');
        wp_cache_delete('alloptions', 'options');
        $map = get_option(self::MAP, array());
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if ('' === $uri) {
            return;
        }
        $saved = false;
        if (self::map_is_current($map)) {
            $map['screens'][self::$screen] = self::$raw;
            $saved = get_option(self::MAP) === $map || update_option(self::MAP, $map, true);
        }
        // Not saved: this once, with every plugin.
        if (wp_safe_redirect($saved ? $uri : add_query_arg(self::LOAD_ALL_ARG, '1', $uri))) {
            exit;
        }
    }

    /**
     * Skip self::$skipped for the rest of this request, safely.
     */
    private static function filter() {
        self::$mode   = 'filter';
        self::$reason = '';
        add_filter('option_active_plugins', array(__CLASS__, 'filter_active'), PHP_INT_MAX);
        add_filter('pre_update_option_active_plugins', array(__CLASS__, 'keep_active'), PHP_INT_MAX, 2);
        // Rules generated now would leave out the skipped plugins' addresses
        // for everyone, so only a request with every plugin saves them.
        add_filter('pre_update_option_rewrite_rules', array(__CLASS__, 'keep_rewrite_rules'), PHP_INT_MAX, 2);
        add_action('deactivate_plugin', array(__CLASS__, 'flag_screen'));
        // Core's fatal error handler shows its message, then exits before
        // later shutdown functions run, so mark the screen from its message.
        add_filter('wp_php_error_message', array(__CLASS__, 'fatal_message'));
        register_shutdown_function(array(__CLASS__, 'on_shutdown'));
    }

    /**
     * Learn which plugin registers each post type, taxonomy and block.
     */
    private static function attribute() {
        self::$attributing = true;
        add_action('registered_post_type', array(__CLASS__, 'note_post_type'));
        add_action('registered_taxonomy', array(__CLASS__, 'note_taxonomy'));
        add_filter('register_block_type_args', array(__CLASS__, 'note_block'), 10, 2);
    }

    /**
     * Start on a page of the site: skip the plugins ticked for the site, or
     * learn what each plugin adds there.
     *
     * @param array $options SEO Pro Stack's stored settings.
     */
    private static function start_front(array $options) {
        if (!self::front_request()) {
            return;
        }
        $logged_in    = self::has_login_cookie();
        self::$screen = 'front';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only loads more plugins.
        if (isset($_GET[self::LOAD_ALL_ARG])) {
            // Every plugin for this request. For an administrator (checked
            // when learning) it also learns the site again.
            self::$mode    = 'full';
            self::$reason  = 'learning';
            self::$relearn = $logged_in;
            if ($logged_in) {
                self::attribute();
            }
            return;
        }
        if (!self::front_current()) {
            // Learn once per set of active plugins, one request at a time.
            if (self::take_front_lock()) {
                self::$mode   = 'full';
                self::$reason = 'learning';
                self::attribute();
            }
            return;
        }
        $front = get_option(self::FRONT, array());
        if (!empty($front['failed']) || ($logged_in && empty($options[self::FRONT_USERS_KEY]))) {
            return;
        }
        $chosen = isset($options[self::FRONT_KEY]) && is_array($options[self::FRONT_KEY]) ? array_values(array_intersect(self::$raw, $options[self::FRONT_KEY])) : array();
        if (!empty($options[self::PAGES_KEY])) {
            // Never learn a visitor's session or personalise a public page map.
            if ($logged_in || !empty($_COOKIE)) {
                return;
            }
            $key = self::front_page_key();
            if ('' === $key) {
                return;
            }
            $page = isset($front['pages'][$key]) ? $front['pages'][$key] : array();
            if (empty($page['learned']) || $page['learned'] < time() - HOUR_IN_SECONDS) {
                self::$mode   = 'full';
                self::$reason = 'learning';
                self::attribute();
                return;
            }
            $keep = isset($options[self::KEEP_KEY]) ? (array) $options[self::KEEP_KEY] : array();
            $candidates = array_diff((array) ($front['candidates'] ?? array()), $keep);
            $chosen = array_unique(array_merge($chosen, $candidates));
            // Even an explicitly ticked content plugin stays on pages using it.
            $chosen = array_diff($chosen, (array) ($page['needs'] ?? array()));
        }
        self::$skipped = self::front_skipped($chosen, $front);
        if (self::$skipped) {
            self::filter();
        }
    }

    /**
     * Whether this request is a page of the site that may skip plugins:
     * GET for index.php, not the REST API, with harmless query arguments.
     *
     * @return bool
     */
    private static function front_request() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            || (defined('REST_REQUEST') && REST_REQUEST) || (defined('IFRAME_REQUEST') && IFRAME_REQUEST) || (defined('WP_INSTALLING') && WP_INSTALLING)) {
            return false;
        }
        if (defined('SEOPROSTACK_LOAD_ALL_PLUGINS') && SEOPROSTACK_LOAD_ALL_PLUGINS) {
            return false;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        if ('GET' !== $method && 'HEAD' !== $method) {
            return false;
        }
        // Only the site's own front controller: not wp-login.php, wp-signup.php,
        // wp-activate.php, xmlrpc.php, wp-cron.php or a file of a plugin.
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) wp_unslash($_SERVER['SCRIPT_NAME'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
        if ('index.php' !== basename($script) || '/wp-admin' === substr(dirname($script), -9) || false !== strpos($script, '/wp-content/') || false !== strpos($script, '/wp-includes/')) {
            return false;
        }
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
        $path   = (string) strtok($uri, '?');
        $prefix = function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json';
        if (false !== strpos($path, '/' . trim($prefix, '/'))) {
            return false; // The REST API, before core has said so.
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reading which arguments there are.
        foreach (array_keys($_GET) as $name) {
            $name = (string) $name;
            if (self::LOAD_ALL_ARG !== $name && !in_array($name, self::FRONT_ARGS, true) && 0 !== strpos($name, 'utm_')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the request carries a login cookie (checked before WordPress
     * knows who is logged in; a stale cookie counts, which loads more).
     *
     * @return bool
     */
    private static function has_login_cookie() {
        foreach (array_keys($_COOKIE) as $name) {
            if (0 === strpos((string) $name, 'wordpress_logged_in_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Exact public URL identity, available before the query and plugins load.
     * Query variants are not learned: searches, pagination and actions keep all
     * plugins. Campaign tags share the same map as their untagged page.
     *
     * @return string
     */
    public static function front_page_key() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only choosing to load more plugins.
        foreach (array_keys($_GET) as $name) {
            if (self::LOAD_ALL_ARG !== $name && 0 !== strpos((string) $name, 'utm_')
                && !in_array($name, array_diff(self::FRONT_ARGS, array('s', 'paged', 'page', 'cpage', 'p', 'page_id')), true)) {
                return '';
            }
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hashed, never printed.
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        return '' !== $path && '/' === $path[0] && strlen($path) <= 2048 ? hash('sha256', $path) : '';
    }

    /**
     * Whether what each plugin adds to the site was learned for the current
     * set of active plugins.
     *
     * @return bool
     */
    public static function front_current() {
        $front = get_option(self::FRONT, array());
        return is_array($front)
            && isset($front['version'], $front['active'], $front['deps'], $front['always'], $front['notes'])
            && self::FRONT_VERSION === $front['version']
            && self::fingerprint(self::stored_active_plugins()) === $front['active'];
    }

    /**
     * Start learning the site unless another request is (for two minutes at
     * most, in case that request failed). Two requests at once may both
     * learn, which does no harm.
     *
     * @return bool
     */
    private static function take_front_lock() {
        $now = time();
        if (add_option(self::FRONT_LOCK, $now, '', false)) {
            return true;
        }
        if ((int) get_option(self::FRONT_LOCK, 0) > $now - 2 * MINUTE_IN_SECONDS) {
            return false;
        }
        update_option(self::FRONT_LOCK, $now, false);
        return true;
    }

    /**
     * Ticked plugins this page of the site skips: not those that always
     * load, and not those a loading plugin needs.
     *
     * @param string[] $chosen Ticked plugins that are active.
     * @param array    $front  What was learned on the site.
     * @return string[]
     */
    private static function front_skipped(array $chosen, array $front) {
        $never = array_merge(array(self::$self), (array) $front['always']);
        $skip  = array_fill_keys(array_diff($chosen, $never), true);
        $deps  = (array) $front['deps'];
        do {
            $added = false;
            foreach (self::$raw as $file) {
                if (isset($skip[$file]) || empty($deps[$file])) {
                    continue;
                }
                foreach ((array) $deps[$file] as $dep) {
                    if (isset($skip[$dep])) {
                        unset($skip[$dep]);
                        $added = true; // It loads now, so what it needs must load too.
                    }
                }
            }
        } while ($added);
        return array_keys($skip);
    }

    /**
     * Whether this request is one that may load fewer plugins, or learn
     * what its screen needs.
     *
     * @return bool
     */
    private static function filterable_request() {
        if (!is_admin() || is_network_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }
        if ((defined('WP_CLI') && WP_CLI) || (defined('REST_REQUEST') && REST_REQUEST) || (defined('IFRAME_REQUEST') && IFRAME_REQUEST)) {
            return false;
        }
        if (defined('SEOPROSTACK_LOAD_ALL_PLUGINS') && SEOPROSTACK_LOAD_ALL_PLUGINS) {
            return false;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        if ('GET' !== $method && 'HEAD' !== $method) {
            return false;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- only reading which screen this is.
        if (isset($_GET['_wpnonce']) || isset($_GET['bulk_edit']) || isset($_GET['doaction'])) {
            return false;
        }
        foreach (array('action', 'action2') as $arg) {
            $action = isset($_GET[$arg]) && is_string($_GET[$arg]) ? sanitize_key(wp_unslash($_GET[$arg])) : '';
            if ('' !== $action && '-1' !== $action && 'edit' !== $action) {
                return false;
            }
        }
        // phpcs:enable
        // Only people who are logged in, so the login screen and anything a
        // plugin does for visitors are never affected.
        foreach (array_keys($_COOKIE) as $name) {
            if (0 === strpos((string) $name, 'wordpress_logged_in_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * The wp-admin script being run, such as "edit.php", or '' when it is
     * not directly in wp-admin.
     *
     * @return string
     */
    private static function script() {
        $path = isset($_SERVER['SCRIPT_NAME']) ? (string) wp_unslash($_SERVER['SCRIPT_NAME']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared below.
        $path = str_replace('\\', '/', $path);
        if ('/wp-admin' !== substr(dirname($path), -9)) {
            return '';
        }
        $script = basename($path);
        return preg_match('/^[a-z0-9-]+\.php$/', $script) ? $script : '';
    }

    /**
     * Key for the screen being requested, or '' when it is never filtered.
     *
     * @param string $script wp-admin script.
     * @return string
     */
    private static function screen_key($script) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- only reading which screen this is.
        if (isset($_GET['page'])) {
            // A plugin's own page, wherever its menu entry is (Settings, Tools).
            $page = is_string($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
            if (in_array($script, self::NEVER_PAGES, true) || !preg_match('#^[A-Za-z0-9_.\-/]{1,200}$#', $page)) {
                return '';
            }
            return 'page:' . $page;
        }
        // Core settings screens show fields from many plugins, and saving
        // them without those fields would clear their values.
        if (in_array($script, self::NEVER, true) || 0 === strpos($script, 'options-')) {
            return '';
        }
        if (in_array($script, self::ABOUT, true)) {
            return 'about';
        }

        $post_type = isset($_GET['post_type']) && is_string($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
        $taxonomy  = isset($_GET['taxonomy']) && is_string($_GET['taxonomy']) ? sanitize_key(wp_unslash($_GET['taxonomy'])) : 'post_tag';
        switch ($script) {
            case 'index.php':
                return 'dashboard';
            case 'edit.php':
                return 'list:' . $post_type;
            case 'upload.php':
                return 'list:attachment';
            case 'post-new.php':
                return 'post:' . $post_type;
            case 'post.php':
                $id = isset($_GET['post']) && is_scalar($_GET['post']) ? absint($_GET['post']) : 0;
                if (!$id) {
                    return '';
                }
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- runs before plugins load; post types are not registered yet.
                $type = $wpdb->get_var($wpdb->prepare("SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $id));
                return $type ? 'post:' . sanitize_key($type) : '';
            case 'edit-tags.php':
            case 'term.php':
                return 'terms:' . $taxonomy;
            case 'edit-comments.php':
                return 'comments';
            case 'users.php':
                return 'users';
            case 'themes.php':
                return 'themes';
            // Profile and user forms: learned from the plugins that show or
            // save fields there (USER_HOOKS).
            case 'profile.php':
                return 'user:profile';
            case 'user-edit.php':
                return 'user:edit';
            case 'user-new.php':
                return 'user:new';
            case 'tools.php':
                return 'tools';
            case 'media-new.php':
                return 'media-new';
            // Appearance > Menus: learned from the plugins that change menus
            // there (MENU_HOOKS) and own what can be added to them.
            case 'nav-menus.php':
                return 'menus';
            // Appearance > Editor: blocks, editor features, templates and
            // styles (SITE_EDITOR_HOOKS). It edits only core post types.
            case 'site-editor.php':
                return 'site-editor';
        }
        // phpcs:enable
        return '';
    }

    /**
     * Plugins to load on a screen, or null when it must be learned first.
     *
     * @param string   $screen Screen key.
     * @param string[] $chosen Ticked plugins that are active.
     * @return string[]|null
     */
    private static function plugins_for_screen($screen, array $chosen) {
        $map    = self::$map;
        $deps   = $map['deps'];
        $always = array_merge(array(self::$self), $map['always']);

        // Ticked plugins, plus unticked ones that need a ticked plugin.
        $restricted = array_fill_keys(array_diff($chosen, $always), true);
        $followers  = array();
        do {
            $added = false;
            foreach (self::$raw as $file) {
                if (isset($restricted[$file]) || in_array($file, $always, true) || empty($deps[$file])) {
                    continue;
                }
                if (array_intersect($deps[$file], array_keys($restricted))) {
                    $restricted[$file] = true;
                    $followers[$file]  = true;
                    $added             = true;
                }
            }
        } while ($added);

        list($kind, $name) = array_pad(explode(':', $screen, 2), 2, '');
        $wanted = array();
        if ('page' === $kind) {
            if (!isset($map['pages'][$name])) {
                return null;
            }
            $wanted = (array) $map['pages'][$name];
            if (self::SETTINGS_PAGE === $name) {
                $wanted = array_merge($wanted, self::settings_plugins($map));
            }
        } else {
            if (!isset($map['screens'][$screen])) {
                return null;
            }
            $wanted = (array) $map['screens'][$screen];
            // Columns, filters and links plugins add to lists.
            if (isset($map['tables'][$screen])) {
                $wanted = array_merge($wanted, (array) $map['tables'][$screen]);
            }
            if ('site-editor' === $kind && (!isset($map['site_editor_theme']) || get_option('stylesheet') !== $map['site_editor_theme'])) {
                return null; // Learned with another theme: what it offers differs.
            }
            if ('list' === $kind || 'post' === $kind) {
                if (!array_key_exists($name, $map['types'])) {
                    return null; // A post type nobody was seen registering.
                }
                $wanted[] = $map['types'][$name];
            } elseif ('terms' === $kind) {
                if (!array_key_exists($name, $map['taxes'])) {
                    return null;
                }
                $wanted[] = $map['taxes'][$name];
            }
            if ('post' === $kind || 'site-editor' === $kind) {
                $wanted = array_merge($wanted, $map['blocks']);
            } elseif ('user' === $kind && isset($map['permissions'])) {
                // Role and permission plugins change which roles and
                // capabilities these forms offer.
                $wanted = array_merge($wanted, (array) $map['permissions']);
            }
        }

        $loaded = array();
        foreach (self::$raw as $file) {
            if (!isset($restricted[$file]) || in_array($file, $wanted, true)) {
                $loaded[$file] = true;
            }
        }

        // Pull in what loaded plugins need, and followers of loaded plugins.
        do {
            $added = false;
            foreach (self::$raw as $file) {
                if (isset($loaded[$file])) {
                    foreach (isset($deps[$file]) ? (array) $deps[$file] : array() as $dep) {
                        if (!isset($loaded[$dep]) && in_array($dep, self::$raw, true)) {
                            $loaded[$dep] = true;
                            $added        = true;
                        }
                    }
                } elseif (isset($followers[$file]) && array_intersect((array) $deps[$file], array_keys($loaded))) {
                    $loaded[$file] = true;
                    $added         = true;
                }
            }
        } while ($added);

        return array_keys($loaded);
    }

    /**
     * Plugins SEO Pro Stack's settings page needs besides its own: its
     * settings offer post types, taxonomies, roles and widgets, so the
     * plugins that register them load, and so do plugins that add to its
     * settings or tabs through its hooks.
     *
     * @param array $map Learned map.
     * @return string[]
     */
    private static function settings_plugins(array $map) {
        // Owners of post types and taxonomies offered as choices; every
        // owner when that was not learned.
        $choices = isset($map['choices']) ? (array) $map['choices'] : array_merge(array_values((array) $map['types']), array_values((array) $map['taxes']));
        $plugins = array_merge(
            $choices,
            isset($map['permissions']) ? (array) $map['permissions'] : array(),
            isset($map['settings']) ? (array) $map['settings'] : array(),
            isset($map['widgets']) ? (array) $map['widgets'] : array()
        );
        return array_values(array_unique(array_filter($plugins, function ($plugin) {
            return is_string($plugin) && '' !== $plugin; // Core post types and taxonomies have no plugin.
        })));
    }

    /**
     * Whether a stored map was learned for the current set of active plugins.
     *
     * @param mixed $map Stored map.
     * @return bool
     */
    private static function map_is_current($map) {
        return is_array($map)
            && isset($map['version'], $map['active'], $map['pages'], $map['screens'], $map['types'], $map['taxes'], $map['blocks'], $map['deps'], $map['always'], $map['load_all'])
            && self::MAP_VERSION === $map['version']
            && self::fingerprint(self::$raw) === $map['active'];
    }

    /**
     * Fingerprint of a list of active plugins.
     *
     * @param string[] $plugins Plugin files.
     * @return string
     */
    public static function fingerprint(array $plugins) {
        sort($plugins);
        return md5(implode("\n", $plugins));
    }

    /**
     * Filter `active_plugins`: leave out the skipped plugins.
     *
     * @param mixed $plugins Stored value.
     * @return mixed
     */
    public static function filter_active($plugins) {
        return is_array($plugins) ? array_values(array_diff($plugins, self::$skipped)) : $plugins;
    }

    /**
     * Before `active_plugins` is saved during a filtered request, keep every
     * plugin that is stored: this request never deactivates anything.
     * A plugin that tries is usually missing another plugin, so the screen
     * loads everything from then on.
     *
     * @param mixed $value New value.
     * @return mixed
     */
    public static function keep_active($value) {
        if (!is_array($value)) {
            return $value;
        }
        remove_filter('option_active_plugins', array(__CLASS__, 'filter_active'), PHP_INT_MAX);
        $stored = get_option('active_plugins', array());
        add_filter('option_active_plugins', array(__CLASS__, 'filter_active'), PHP_INT_MAX);
        $stored = is_array($stored) ? $stored : array();

        if (array_diff($stored, $value, self::$skipped)) {
            self::flag_screen();
        }
        return array_values(array_unique(array_merge($stored, $value)));
    }

    /**
     * During a filtered request, keep the stored rewrite rules: rules made
     * now would leave out the skipped plugins' addresses. Emptying them (the
     * first step of a flush) is let through, so the next request that loads
     * every plugin makes them again.
     *
     * @param mixed $value New value.
     * @param mixed $old   Stored value.
     * @return mixed
     */
    public static function keep_rewrite_rules($value, $old) {
        return is_array($value) && $value ? $old : $value;
    }

    /**
     * Mark the current screen to load every plugin from now on; on the
     * site, every page, until the list of plugins for the site is saved.
     *
     * @param string $plugin Plugin that tried to deactivate, if any.
     */
    public static function flag_screen($plugin = '') {
        if ('filter' !== self::$mode || self::$flagged || '' === self::$screen) {
            return;
        }
        self::$flagged = true;
        if ('front' === self::$screen) {
            self::flag_front(is_string($plugin) ? $plugin : '');
            return;
        }
        wp_cache_delete(self::MAP, 'options');
        wp_cache_delete('alloptions', 'options');
        $map = get_option(self::MAP, array());
        if (is_array($map) && isset($map['load_all']) && is_array($map['load_all'])) {
            $map['load_all'][self::$screen] = time();
            update_option(self::MAP, $map, true);
        }
    }

    /**
     * A page of the site failed with fewer plugins: note when, where and in
     * which plugin, so the site loads every plugin and the settings say so.
     *
     * @param string $plugin Plugin that tried to deactivate, if any.
     */
    private static function flag_front($plugin) {
        wp_cache_delete(self::FRONT, 'options');
        wp_cache_delete('alloptions', 'options');
        $front = get_option(self::FRONT, array());
        if (!is_array($front) || !isset($front['version'])) {
            return;
        }
        if ('' === $plugin) {
            $error  = error_get_last();
            $plugin = $error && !empty($error['file']) ? self::plugin_for_file((string) $error['file']) : '';
        }
        $uri             = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $front['failed'] = array(
            'time'   => time(),
            'plugin' => $plugin,
            'path'   => (string) strtok($uri, '?'),
        );
        update_option(self::FRONT, $front, true);
    }

    /**
     * Core is about to refuse a screen that may belong to a skipped plugin:
     * reload it with every plugin. For administrators, who were allowed
     * there when it was learned, it also loads every plugin from now on.
     * Anyone else may simply not be allowed, so nothing is saved: they get
     * one reload, and core's own answer after that.
     */
    public static function reload_denied() {
        if ('filter' !== self::$mode) {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if ('' === $uri) {
            return;
        }
        if (current_user_can('manage_options')) {
            self::flag_screen();
            if (!self::$flagged) {
                return;
            }
        } else {
            $uri = add_query_arg(self::LOAD_ALL_ARG, '1', $uri);
        }
        if (wp_safe_redirect($uri)) {
            exit;
        }
    }

    /**
     * A fatal error on a filtered screen: load every plugin there next time,
     * and say so in core's "critical error" message.
     *
     * @param string $message HTML message.
     * @return string
     */
    public static function fatal_message($message) {
        self::flag_screen();
        if (self::$flagged) {
            $text     = 'front' === self::$screen
                ? __('SEO Pro Stack loaded fewer plugins on this page. Reload it: the site now loads every plugin.', 'seoprostack')
                : __('SEO Pro Stack loaded fewer plugins on this screen. Reload the page: it now loads every plugin here.', 'seoprostack');
            $message .= '<p>' . esc_html($text) . '</p>';
        }
        return $message;
    }

    /**
     * Fallback when core's error handler does not run: it is switched off,
     * or another handler (such as Query Monitor's) shows an uncaught error
     * and exits, which leaves no last error but a 500 status.
     */
    public static function on_shutdown() {
        $error = error_get_last();
        if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR), true)) {
            self::flag_screen();
            return;
        }
        if (http_response_code() >= 500) {
            self::flag_screen();
        }
    }

    /**
     * Note which plugin registered a post type.
     *
     * @param string $post_type Post type.
     */
    public static function note_post_type($post_type) {
        if (empty($GLOBALS['wp_post_types'][$post_type]->_builtin)) {
            self::$registered['types'][$post_type] = self::calling_plugin();
        } else {
            self::$registered['types'][$post_type] = '';
        }
    }

    /**
     * Note which plugin registered a taxonomy.
     *
     * @param string $taxonomy Taxonomy.
     */
    public static function note_taxonomy($taxonomy) {
        if (empty($GLOBALS['wp_taxonomies'][$taxonomy]->_builtin)) {
            self::$registered['taxes'][$taxonomy] = self::calling_plugin();
        } else {
            self::$registered['taxes'][$taxonomy] = '';
        }
    }

    /**
     * Note which plugin registered a block.
     *
     * @param array  $args       Block type arguments.
     * @param string $block_type Block name.
     * @return array
     */
    public static function note_block($args, $block_type = '') {
        $plugin = self::calling_plugin();
        if ('' !== $plugin) {
            self::$registered['blocks'][$plugin] = true;
            self::$registered['block_names'][(string) $block_type] = $plugin;
        }
        return $args;
    }

    /**
     * The active plugin whose code made the current call, or ''.
     *
     * @return string
     */
    private static function calling_plugin() {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- only while learning, with every plugin loaded.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            // This file sits in SEO Pro Stack's folder; skip its own frames.
            $plugin = empty($frame['file']) || wp_normalize_path(__FILE__) === wp_normalize_path($frame['file']) ? '' : self::plugin_for_file($frame['file']);
            if ('' !== $plugin) {
                return $plugin;
            }
        }
        return '';
    }

    /**
     * The active plugin a PHP file belongs to, or ''.
     *
     * @param string $file Absolute path.
     * @return string Plugin file relative to the plugins folder.
     */
    public static function plugin_for_file($file) {
        static $dir = null, $by_folder = null;
        if (null === $dir) {
            $dir       = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
            $by_folder = array();
            foreach (self::$raw as $plugin) {
                $folder             = false === strpos($plugin, '/') ? $plugin : strtok($plugin, '/');
                $by_folder[$folder] = $plugin;
            }
        }
        $file = wp_normalize_path((string) $file);
        if (0 !== strpos($file, $dir)) {
            return '';
        }
        $relative = substr($file, strlen($dir));
        $folder   = false === strpos($relative, '/') ? $relative : strtok($relative, '/');
        return isset($by_folder[$folder]) ? $by_folder[$folder] : '';
    }

    /**
     * The active plugin that defined a hook callback, or ''.
     *
     * @param mixed $callback Callback.
     * @return string
     */
    public static function plugin_for_callback($callback) {
        try {
            if (is_string($callback) && false !== strpos($callback, '::')) {
                $callback = explode('::', $callback, 2);
            }
            if (is_array($callback) && 2 === count($callback)) {
                $owner = self::plugin_for_sdk($callback[0]);
                if ('' !== $owner) {
                    return $owner;
                }
                $class = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
                if (!method_exists($class, (string) $callback[1])) {
                    return is_object($callback[0]) ? self::plugin_for_file((string) (new ReflectionClass($callback[0]))->getFileName()) : '';
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
        return $file ? self::plugin_for_file($file) : '';
    }

    /**
     * Other active plugins that bundle the same shared code as a callback.
     * Some plugin families each ship a copy of one framework and use the
     * copy that loads first (WP Sheet Editor and its spreadsheets for posts,
     * users, products and terms): the framework registers every sheet's
     * page, but a sheet only works when the plugin it belongs to loads too.
     * A copy counts when another plugin has the same file, at the same place
     * in its folder, declaring the same class or function. Files at the top
     * of a plugin's folder and Freemius (see plugin_for_sdk()) do not count.
     *
     * @param mixed $callback Callback.
     * @return string[] Plugin files, without the one the code was loaded from.
     */
    public static function plugins_sharing_callback($callback) {
        try {
            if (is_string($callback) && false !== strpos($callback, '::')) {
                $callback = explode('::', $callback, 2);
            }
            if (is_array($callback) && 2 === count($callback)) {
                if ('' !== self::plugin_for_sdk($callback[0])) {
                    return array();
                }
                $reflection = new ReflectionClass($callback[0]);
                $declares   = 'class\s+' . preg_quote($reflection->getShortName(), '/');
            } elseif (is_string($callback)) {
                $reflection = new ReflectionFunction($callback);
                $declares   = 'function\s+' . preg_quote($reflection->getShortName(), '/');
            } else {
                return array();
            }
        } catch (ReflectionException $e) {
            return array();
        }
        static $seen = array();
        $file = $reflection->getFileName();
        $own  = $file ? self::plugin_for_file($file) : '';
        if ('' === $own || false === strpos($own, '/')) {
            return array();
        }
        $key = $file . '|' . $declares;
        if (isset($seen[$key])) {
            return $seen[$key];
        }
        $dir      = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
        $folder   = substr($own, 0, strpos($own, '/'));
        $relative = substr(wp_normalize_path($file), strlen($dir . $folder . '/'));
        $shared = array();
        if (false === strpos($relative, '/')) {
            return $seen[$key] = $shared;
        }
        foreach (self::$raw as $plugin) {
            if ($plugin === $own || false === strpos($plugin, '/')) {
                continue;
            }
            $copy = $dir . substr($plugin, 0, strpos($plugin, '/')) . '/' . $relative;
            if (is_readable($copy) && preg_match('/\b' . $declares . '\b/i', (string) file_get_contents($copy))) {
                $shared[] = $plugin;
            }
        }
        return $seen[$key] = $shared;
    }

    /**
     * The plugin a shared SDK object works for, or ''. Plugins that bundle
     * the Freemius SDK share the newest copy, loaded from whichever plugin
     * holds it, and Freemius replaces a plugin's welcome or opt-in page with
     * its own callback. That page belongs to the plugin the object serves,
     * not to the plugin whose folder the SDK code was loaded from.
     *
     * @param mixed $object Callback object or class.
     * @return string
     */
    private static function plugin_for_sdk($object) {
        if (!is_object($object) || !class_exists('Freemius', false) || !($object instanceof Freemius) || !method_exists($object, 'get_plugin_basename')) {
            return '';
        }
        $basename = (string) $object->get_plugin_basename();
        return '' !== $basename ? self::plugin_for_file(trailingslashit(WP_PLUGIN_DIR) . $basename) : '';
    }

    /**
     * Active plugin files as stored, including any skipped on this request.
     * SEO Pro Stack's own code uses this wherever it shows or decides
     * something about which plugins are active, so a skipped plugin still
     * counts as active (get_option('active_plugins') and is_plugin_active()
     * leave skipped plugins out).
     *
     * @return string[]
     */
    public static function stored_active_plugins() {
        if ('filter' === self::$mode) {
            return self::$raw;
        }
        $raw = get_option('active_plugins', array());
        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : array();
    }

    /**
     * Whether a plugin is active, here or network-wide, even when it was
     * skipped on this request.
     *
     * @param string $file Plugin file, such as "akismet/akismet.php".
     * @return bool
     */
    public static function is_active($file) {
        if (in_array((string) $file, self::stored_active_plugins(), true)) {
            return true;
        }
        if (!is_multisite()) {
            return false;
        }
        $network = get_site_option('active_sitewide_plugins', array());
        return is_array($network) && isset($network[$file]);
    }

    /**
     * Whether this request loads fewer plugins.
     *
     * @return bool
     */
    public static function is_filtered() {
        return 'filter' === self::$mode;
    }

    /**
     * Current request state for SEO Pro Stack's own code.
     *
     * @return array{mode: string, reason: string, screen: string, relearn: bool, active: string[], skipped: string[], map: array, attributing: bool, registered: array}
     */
    public static function state() {
        return array(
            'mode'        => self::$mode,
            'reason'      => self::$reason,
            'screen'      => self::$screen,
            'relearn'     => self::$relearn,
            'active'      => self::$raw,
            'skipped'     => self::$skipped,
            'map'         => self::$map,
            'attributing' => self::$attributing,
            'registered'  => self::$registered,
        );
    }
}
