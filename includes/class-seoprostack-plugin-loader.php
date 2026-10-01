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
 * the Plugins, update, settings, menus and widgets screens, the
 * Customizer, screens of plugins it does not know yet, and the site itself.
 *
 * A ticked plugin loads:
 * - on its own screens: pages it added to the menu, and the posts and
 *   terms of post types and taxonomies it registered;
 * - on post, term, list, profile, user, Tools and media upload screens
 *   where it adds boxes, fields, blocks or editor features, or saves
 *   profile fields (learned per screen with every plugin loaded);
 * - wherever a plugin that needs it loads (`Requires Plugins`,
 *   `WC requires at least`, `Elementor tested up to`).
 * Plugins that need a ticked plugin follow it: they load where it loads.
 *
 * Safety: nothing is ever deactivated (writes to `active_plugins` during a
 * filtered request keep every plugin), and a screen that hits a fatal
 * error or makes a plugin try to deactivate itself loads every plugin
 * from then on. `?seoprostack-load-all=1` loads every plugin for one
 * request and learns that screen again; the SEOPROSTACK_LOAD_ALL_PLUGINS
 * constant turns filtering off.
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
    const MAP_VERSION = 2;

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

    /** wp-admin scripts of each screen kind learned from hooks. */
    const KIND_SCRIPTS = array(
        'user'      => array('profile.php', 'user-edit.php', 'user-new.php'),
        'tools'     => array('tools.php'),
        'media-new' => array('media-new.php'),
    );

    /**
     * Whether a hook's callbacks mean a plugin must load on a screen, so
     * forms there keep every field. Hooks for other post types and
     * taxonomies do not count.
     *
     * @param string $kind Screen kind: "post", "terms", "list", "user", "tools" or "media-new".
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
        }
        return false; // Dashboard, comments, the users list, themes and About add no fields to forms.
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
        'async-upload.php', 'site-health.php', 'import.php', 'export.php', 'widgets.php',
        'nav-menus.php', 'theme-editor.php', 'site-editor.php',
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
        if (!is_array($options) || empty($options[self::SWITCH_KEY]) || empty($options[self::LIST_KEY]) || !is_array($options[self::LIST_KEY])) {
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
            // Learn which plugin registers each post type, taxonomy and block.
            self::$attributing = true;
            add_action('registered_post_type', array(__CLASS__, 'note_post_type'));
            add_action('registered_taxonomy', array(__CLASS__, 'note_taxonomy'));
            add_filter('register_block_type_args', array(__CLASS__, 'note_block'), 10, 2);
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

        self::$mode   = 'filter';
        self::$reason = '';
        add_filter('option_active_plugins', array(__CLASS__, 'filter_active'), PHP_INT_MAX);
        add_filter('pre_update_option_active_plugins', array(__CLASS__, 'keep_active'), PHP_INT_MAX, 2);
        add_action('deactivate_plugin', array(__CLASS__, 'flag_screen'));
        // A page whose plugin was skipped is not registered: core would say
        // "not allowed". Load it again with every plugin instead.
        add_action('admin_page_access_denied', array(__CLASS__, 'reload_denied'), 0);
        // Core's fatal error handler shows its message, then exits before
        // later shutdown functions run, so mark the screen from its message.
        add_filter('wp_php_error_message', array(__CLASS__, 'fatal_message'));
        register_shutdown_function(array(__CLASS__, 'on_shutdown'));
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
            if ('seoprostack' === $page) {
                return ''; // SEO Pro Stack's settings, where it also learns.
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
        } else {
            if (!isset($map['screens'][$screen])) {
                return null;
            }
            $wanted = (array) $map['screens'][$screen];
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
            if ('post' === $kind) {
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
     * Mark the current screen to load every plugin from now on.
     */
    public static function flag_screen() {
        if ('filter' !== self::$mode || self::$flagged || '' === self::$screen) {
            return;
        }
        self::$flagged = true;
        wp_cache_delete(self::MAP, 'options');
        wp_cache_delete('alloptions', 'options');
        $map = get_option(self::MAP, array());
        if (is_array($map) && isset($map['load_all']) && is_array($map['load_all'])) {
            $map['load_all'][self::$screen] = time();
            update_option(self::MAP, $map, true);
        }
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
            $message .= '<p>' . esc_html__('SEO Pro Stack loaded fewer plugins on this screen. Reload the page: it now loads every plugin here.', 'seoprostack') . '</p>';
        }
        return $message;
    }

    /**
     * Fallback when core's error handler is switched off.
     */
    public static function on_shutdown() {
        $error = error_get_last();
        if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR), true)) {
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
     * Current request state for SEO Pro Stack's own code.
     *
     * @return array{mode: string, reason: string, screen: string, active: string[], skipped: string[], map: array, attributing: bool, registered: array}
     */
    public static function state() {
        return array(
            'mode'        => self::$mode,
            'reason'      => self::$reason,
            'screen'      => self::$screen,
            'active'      => self::$raw,
            'skipped'     => self::$skipped,
            'map'         => self::$map,
            'attributing' => self::$attributing,
            'registered'  => self::$registered,
        );
    }
}
