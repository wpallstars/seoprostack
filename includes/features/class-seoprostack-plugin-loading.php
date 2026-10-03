<?php
/**
 * Load plugins in wp-admin only where they are needed.
 *
 * On a site with 200 plugins every admin screen runs all 200, which can
 * take seconds; with 2 it takes about as long as WordPress alone. This
 * feature chooses plugins automatically, with an always-load bypass list.
 * SEOProStack_Plugin_Loader does the filtering from a
 * must-use file; this class:
 *
 * - writes that file when the setting is on and removes it when it is
 *   switched off, SEO Pro Stack is deactivated or uninstalled;
 * - learns, whenever an administrator opens a screen with every plugin
 *   loaded, which plugin owns each admin page, post type and taxonomy,
 *   which plugins add boxes, fields or blocks to each post, term and list
 *   screen, which plugins need which, and the full admin menu;
 * - on screens that load fewer plugins, puts the skipped plugins' menu
 *   entries back as links and shows how many plugins loaded, with links
 *   that reload the screen with every plugin and learn it, or every
 *   screen, again (each asks first): at the top of the Plugins menu in the
 *   admin bar, or under "N of M plugins" on its own when that menu is off.
 *
 * - learns, on one page view of the site with every plugin loaded, what
 *   each plugin adds there (shortcodes, blocks, widgets, content types,
 *   page output, email, logins, the admin bar), shown next to each plugin
 *   in "Plugins to skip on the site", and which plugins must always load.
 *
 * What was learned is forgotten when plugins are activated, deactivated or
 * updated, so screens load every plugin once more while it is relearned.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('SEOProStack_Plugin_Loader', false)) {
    require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-plugin-loader.php';
}

class SEOProStack_Plugin_Loading extends SEOProStack_Feature {

    const KEY = SEOProStack_Plugin_Loader::SWITCH_KEY;

    /** Setting: plugins the administrator always wants loaded. */
    const LIST_KEY = SEOProStack_Plugin_Loader::ADMIN_KEEP_KEY;

    /** Must-use file name. */
    const FILE = 'seoprostack-plugin-loading.php';

    /** Text that identifies the must-use file as ours. */
    const MARKER = 'seoprostack-plugin-loading';

    /** Admin bar node ID. */
    const NODE = 'seoprostack-plugin-loading';

    /** admin-post.php action (and nonce action) that checks every screen again. */
    const RESET = 'seoprostack_plugin_loading_reset';

    /** Query arg: the screen to return to after checking every screen again. */
    const RETURN_ARG = 'return';

    /** Settings: plugins to skip on the site, and whether for logged-in people too. */
    const FRONT_KEY       = SEOProStack_Plugin_Loader::FRONT_KEY;
    const FRONT_USERS_KEY = SEOProStack_Plugin_Loader::FRONT_USERS_KEY;

    /**
     * Hooks that change who is logged in or where logins go: plugins on
     * them always load on the site (with the loader's ALWAYS_HOOKS).
     */
    const FRONT_ALWAYS_HOOKS = array(
        'determine_current_user', 'auth_cookie', 'auth_cookie_expiration', 'secure_auth_cookie', 'secure_logged_in_cookie',
        'logout_url', 'register_url', 'lostpassword_url', 'user_has_cap', 'map_meta_cap',
    );

    /** Hooks that check logins or block requests. */
    const FRONT_LOGIN_HOOKS = array(
        'authenticate', 'wp_authenticate_user', 'wp_login', 'wp_login_failed', 'login_init', 'login_form',
        'xmlrpc_enabled', 'xmlrpc_methods', 'rest_authentication_errors', 'registration_errors', 'lostpassword_post',
    );

    /** Hooks that change how email is sent. */
    const FRONT_EMAIL_HOOKS = array(
        'phpmailer_init', 'wp_mail', 'pre_wp_mail', 'wp_mail_from', 'wp_mail_from_name', 'wp_mail_content_type',
        'wp_mail_charset', 'wp_mail_failed', 'wp_mail_succeeded',
    );

    /** Hooks that add to the admin bar. */
    const FRONT_BAR_HOOKS = array(
        'admin_bar_init', 'add_admin_bar_menus', 'admin_bar_menu', 'wp_before_admin_bar_render', 'wp_after_admin_bar_render', 'show_admin_bar',
    );

    /** Hooks that change pages of the site, their addresses or what they show. */
    const FRONT_PAGE_HOOKS = array(
        'wp_head', 'wp_footer', 'wp_body_open', 'wp_enqueue_scripts', 'template_redirect', 'template_include',
        'parse_request', 'do_parse_request', 'parse_query', 'request', 'query_vars', 'wp', 'send_headers', 'wp_headers',
        'status_header', 'pre_handle_404', 'robots_txt', 'body_class', 'post_class', 'loop_start', 'loop_end',
        'wp_nav_menu_items', 'wp_nav_menu_objects', 'wp_get_nav_menu_items', 'walker_nav_menu_start_el',
        'get_avatar', 'get_avatar_url', 'get_avatar_data', 'document_title', 'wp_title', 'wp_resource_hints',
        'post_link', 'post_type_link', 'page_link', 'term_link', 'attachment_link', 'home_url', 'site_url', 'wp_redirect',
        'wp_get_attachment_url', 'wp_get_attachment_image_attributes', 'wp_calculate_image_srcset', 'wp_content_img_tag',
        'dynamic_sidebar_params', 'sidebars_widgets', 'rewrite_rules_array', 'generate_rewrite_rules', 'get_header',
        'get_footer', 'get_sidebar', 'shortcode_atts', 'do_shortcode_tag', 'pre_do_shortcode_tag', 'final_output',
        'pre_get_posts', 'pre_get_document_title', 'pre_get_avatar_data', 'pre_get_terms', 'pre_get_shortlink',
    );

    /** Prefixes and suffixes of hooks that change pages of the site. */
    const FRONT_PAGE_PREFIXES = array(
        'the_', 'get_the_', 'wp_sitemaps_', 'wp_robots', 'comment_form', 'comments_', 'widget_', 'render_block', 'pre_render_block',
        'wp_print_', 'wp_enqueue_', 'oembed_', 'embed_', 'nav_menu_', 'redirect_', 'posts_', 'script_loader_',
        'style_loader_', 'rss', 'atom_', 'rdf_', 'do_feed', 'wp_feed',
    );
    const FRONT_PAGE_SUFFIXES = array('_template', '_template_hierarchy');

    /**
     * Core functions a plugin may replace (wp-includes/pluggable.php). A
     * plugin that does always loads on the site: without it, core's own
     * version would run (another way to send email, check passwords or log in).
     */
    const PLUGGABLE = array(
        'wp_set_current_user', 'wp_get_current_user', 'get_userdata', 'get_user_by', 'cache_users', 'wp_mail',
        'wp_authenticate', 'wp_logout', 'wp_validate_auth_cookie', 'wp_generate_auth_cookie', 'wp_parse_auth_cookie',
        'wp_set_auth_cookie', 'wp_clear_auth_cookie', 'is_user_logged_in', 'auth_redirect', 'check_admin_referer',
        'check_ajax_referer', 'wp_redirect', 'wp_sanitize_redirect', 'wp_safe_redirect', 'wp_validate_redirect',
        'wp_notify_postauthor', 'wp_notify_moderator', 'wp_password_change_notification', 'wp_new_user_notification',
        'wp_nonce_tick', 'wp_verify_nonce', 'wp_create_nonce', 'wp_salt', 'wp_hash', 'wp_hash_password',
        'wp_check_password', 'wp_generate_password', 'wp_rand', 'wp_set_password', 'get_avatar', 'wp_text_diff',
    );

    /**
     * Admin menu captured on this request.
     *
     * @var array|null
     */
    private static $menu = null;

    /**
     * Page slug => owner info captured on this request.
     *
     * @var array|null
     */
    private static $pages = null;

    /**
     * Plugins seen changing the list on this request: plugin file => true,
     * or the column keys it added.
     *
     * @var array<string,true|string[]>
     */
    private static $table_seen = array();

    /**
     * List hook callbacks already watched: "hook|priority|id" => true.
     *
     * @var array<string,true>
     */
    private static $table_watched = array();

    /**
     * Plugins seen printing Quick Edit or Bulk Edit fields, or row data for
     * them, on this request: plugin file => true.
     *
     * @var array<string,true>
     */
    private static $form_seen = array();

    /** Content actually rendered, including theme templates and secondary loops. */
    private static $front_seen = array();

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY      => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'plugins',
                'label'       => __('Load plugins only where needed', 'seoprostack'),
                'description' => __('Makes wp-admin faster by loading plugins only on screens that need them. After learning, plain site page views also skip plugins with nothing seen there. Login and permission plugins always load. The menu stays the same. Saving, background tasks, and the Plugins and core settings screens load every plugin.', 'seoprostack'),
            ),
            self::LIST_KEY => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Always load these plugins', 'seoprostack'),
                'description' => __('Add a plugin if a box, field or menu entry is missing or something breaks; for a one-off problem, use the reload links in the admin bar Plugins menu.', 'seoprostack'),
                'options'     => array(__CLASS__, 'plugin_options'),
            ),
            self::FRONT_KEY => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Plugins to skip on the site', 'seoprostack'),
                'description' => __('After learning, plugins with nothing seen on the site are skipped automatically on plain page views. Tick other plugins only if you want them skipped too; leave security, caching, cookie and analytics plugins unticked. Logins, sending forms, background tasks and addresses with extra arguments still load every plugin, and so does a plugin that another loading plugin needs.', 'seoprostack'),
                'options'     => array(__CLASS__, 'front_plugin_options'),
            ),
            self::FRONT_USERS_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Also skip them for people who are logged in', 'seoprostack'),
                'description' => __('When off, people who are logged in, such as editors, get every plugin on the site, including what plugins add to the admin bar there.', 'seoprostack'),
            ),
            SEOProStack_Plugin_Loader::PAGES_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Learn which plugins each page needs', 'seoprostack'),
                'description' => __('For visitors without cookies. Each page kind learns once, then new posts of that type use their own blocks and shortcodes to keep the plugins they need. Decisions stay until content or settings change. Unknown pages or content load every plugin. Lists and search keep content plugins; plugins that change every page stay loaded. WooCommerce stays for shop pages, cart displays and store notices; without Lighter WooCommerce pages it keeps loading everywhere. Requests that change things load every plugin.', 'seoprostack'),
            ),
            SEOProStack_Plugin_Loader::KEEP_KEY => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Always load these plugins on the site', 'seoprostack'),
                'description' => __('Add a plugin here if something it shows on the site is missing.', 'seoprostack'),
                'options'     => array(__CLASS__, 'plugin_options'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        // Forget what was learned when plugin code changes (activation and
        // deactivation change the fingerprint by themselves).
        add_action('upgrader_process_complete', array(__CLASS__, 'forget'));
        add_action('seoprostack_setting_saved', array(__CLASS__, 'setting_saved'));
        // A route must not keep an old decision after its content or layout changes.
        if (self::enabled() && SEOProStack_Settings::get(SEOProStack_Plugin_Loader::PAGES_KEY)) {
            foreach (array('save_post', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'deleted_post',
                'edited_term', 'delete_term', 'switch_theme', 'set_object_terms') as $hook) {
                add_action($hook, array(__CLASS__, 'forget_front'));
            }
        }
        add_action('updated_option', array(__CLASS__, 'front_option_changed'), 10, 1);
        add_action('added_option', array(__CLASS__, 'front_option_changed'), 10, 1);
        add_action('deleted_option', array(__CLASS__, 'front_option_changed'), 10, 1);
        add_action('seoprostack_setting_panel', array(__CLASS__, 'panel_status'), 10, 2);
        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'maybe_sync'));
        } elseif (self::enabled()) {
            self::boot_front();
        }

        if (!self::enabled() || !is_admin()) {
            return;
        }
        add_action('admin_enqueue_scripts', array(__CLASS__, 'background_learning'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
        add_action('admin_post_' . self::RESET, array(__CLASS__, 'reset'));
        $state = SEOProStack_Plugin_Loader::state();
        if ('filter' === $state['mode']) {
            add_action('admin_menu', array(__CLASS__, 'restore_menu'), PHP_INT_MAX);
            // Before the Plugins menu adds its list (priority 100), so these
            // items come first in it.
            add_action('admin_bar_menu', array(__CLASS__, 'admin_bar_in_menu'), 99);
            add_action('admin_bar_menu', array(__CLASS__, 'admin_bar'), 999);
            add_action('admin_bar_init', array(__CLASS__, 'admin_bar_style'));
            add_action('admin_bar_init', array(__CLASS__, 'admin_bar_script'));
        } elseif ('full' === $state['mode']) {
            // Say in the Plugins menu why this screen loads every plugin.
            add_action('admin_bar_menu', array(__CLASS__, 'admin_bar_full'), 99);
            add_action('admin_bar_init', array(__CLASS__, 'admin_bar_style'));
            add_action('admin_bar_init', array(__CLASS__, 'admin_bar_script'));
            if (current_user_can('activate_plugins')) {
                add_action('admin_menu', array(__CLASS__, 'capture_menu'), PHP_INT_MAX);
                add_action('adminmenu', array(__CLASS__, 'prune_menu'));
                add_action('admin_footer', array(__CLASS__, 'learn'), PHP_INT_MAX);
                if ('customizer' === $state['screen']) {
                    // customize.php has its own footer, not admin_footer.
                    add_action('customize_controls_print_footer_scripts', array(__CLASS__, 'learn'), PHP_INT_MAX);
                }
                if (self::table_hooks($state['screen'])) {
                    // Before each list hook runs: see which plugins change it.
                    add_action('all', array(__CLASS__, 'watch_table_hook'));
                }
            }
        }
        // After learn(), so a remembered visit carries the completed map generation.
        add_action('admin_footer', array(__CLASS__, 'remember_screen'), PHP_INT_MAX);
    }

    /** Preserve saved selections; a new site starts with no bypasses. */
    public static function migrate(array $options, $from_version) {
        if (!array_key_exists(self::LIST_KEY, $options)) {
            $old = SEOProStack_Plugin_Loader::LIST_KEY;
            $options[self::LIST_KEY] = array_key_exists($old, $options)
                ? array_values(array_diff(SEOProStack_Plugin_Loader::stored_active_plugins(), (array) $options[$old]))
                : array();
        }
        return $options;
    }

    /**
     * Replay only read-only core views with an explicit query allowlist.
     * Plugin pages, editors that create auto-drafts, actions and nonces are
     * deliberately excluded: a GET alone does not mean read-only.
     *
     * @param string $url Local URL as visited, or stored in the history.
     * @return bool
     */
    private static function safe_screen_url($url) {
        if (!is_string($url) || strlen($url) > 2048) {
            return false;
        }
        $parts = wp_parse_url($url);
        $path = (string) wp_parse_url(admin_url(), PHP_URL_PATH);
        if (!is_array($parts) || isset($parts['host']) || isset($parts['scheme']) || isset($parts['fragment']) || empty($parts['path'])
            || dirname($parts['path']) . '/' !== $path) {
            return false;
        }
        if (!in_array(basename($parts['path']), array('index.php', 'edit.php', 'upload.php', 'edit-tags.php', 'edit-comments.php', 'users.php', 'themes.php', 'tools.php'), true)) {
            return false;
        }
        $query = array();
        wp_parse_str($parts['query'] ?? '', $query);
        foreach ($query as $key => $value) {
            if (!in_array($key, array('post_type', 'taxonomy', 'paged', 'orderby', 'order', 's', 'mode'), true) || !is_string($value)) {
                return false;
            }
        }
        return true;
    }

    /** Count safe ordinary visits, retaining at most 30 most-used URLs. */
    public static function remember_screen() {
        $state = SEOProStack_Plugin_Loader::state();
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if (!current_user_can('activate_plugins') || 'GET' !== $method || '' === $state['screen']
            || !in_array($state['mode'], array('full', 'filter'), true) || in_array($state['reason'], array('always', 'error'), true)) {
            return;
        }
        $url = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if (!self::safe_screen_url($url)) {
            return;
        }
        wp_cache_delete(SEOProStack_Plugin_Loader::HISTORY, 'options');
        wp_cache_delete('alloptions', 'options');
        $history = (array) get_option(SEOProStack_Plugin_Loader::HISTORY, array());
        $map = (array) get_option(SEOProStack_Plugin_Loader::MAP, array());
        $entry = (array) ($history[$url] ?? array());
        $learned = SEOProStack_Plugin_Loader::MAP_VERSION === ($map['version'] ?? 0)
            && SEOProStack_Plugin_Loader::fingerprint($state['active']) === ($map['active'] ?? '')
            && isset($map['screens'][$state['screen']]);
        // Browser replays acknowledge learning, not popularity. This header
        // grants no access and never changes which URL may be replayed.
        $replay = isset($_SERVER['HTTP_X_SEOPROSTACK_LEARNING']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_SEOPROSTACK_LEARNING'])) : '';
        $hit = '1' === $replay ? 0 : 1;
        $history[$url] = array(
            'screen' => $state['screen'],
            'hits' => min(1000000, (int) ($entry['hits'] ?? 0) + $hit),
            'generation' => $learned ? ($map['generation'] ?? '') : '',
        );
        uasort($history, function ($a, $b) {
            return (int) ($b['hits'] ?? 0) <=> (int) ($a['hits'] ?? 0);
        });
        update_option(SEOProStack_Plugin_Loader::HISTORY, array_slice($history, 0, 30, true), false);
    }

    /** Queue only retained URLs that have not been learned in this map. */
    public static function background_learning() {
        $state = SEOProStack_Plugin_Loader::state();
        if (!current_user_can('activate_plugins') || is_network_admin() || wp_doing_ajax()
            || !in_array($state['mode'], array('full', 'filter'), true)
            || (defined('SEOPROSTACK_LOAD_ALL_PLUGINS') && SEOPROSTACK_LOAD_ALL_PLUGINS)) {
            return;
        }
        $map = (array) get_option(SEOProStack_Plugin_Loader::MAP, array());
        $current = SEOProStack_Plugin_Loader::MAP_VERSION === ($map['version'] ?? 0)
            && SEOProStack_Plugin_Loader::fingerprint(SEOProStack_Plugin_Loader::stored_active_plugins()) === ($map['active'] ?? '');
        $urls = array();
        foreach ((array) get_option(SEOProStack_Plugin_Loader::HISTORY, array()) as $url => $entry) {
            if (self::safe_screen_url($url) && is_array($entry)
                && (!$current || !isset($map['load_all'][$entry['screen'] ?? '']))
                && (!$current || empty($entry['generation']) || $entry['generation'] !== ($map['generation'] ?? ''))) {
                $urls[] = $url;
            }
        }
        if (!$urls) {
            return;
        }
        wp_enqueue_script('seoprostack-plugin-learning', SEOPROSTACK_URL . 'admin/js/seoprostack-plugin-learning.js', array(), SEOPROSTACK_VERSION, true);
        wp_localize_script('seoprostack-plugin-learning', 'seoprostackPluginLearning', array(
            'urls' => array_slice($urls, 0, 30),
            'lock' => 'seoprostack-plugin-learning-' . get_current_blog_id(),
        ));
    }

    /**
     * On a page of the site: learn what each plugin adds there, or, when
     * plugins were skipped, show the count in the admin bar.
     */
    private static function boot_front() {
        $state = SEOProStack_Plugin_Loader::state();
        if ('front' !== $state['screen']) {
            return;
        }
        if ('full' === $state['mode'] && 'learning' === $state['reason']) {
            // Last, once every plugin has added its hooks.
            add_action('shutdown', array(__CLASS__, 'learn_front'), 0);
            add_action('loop_start', array(__CLASS__, 'front_loop'));
            add_filter('render_block', array(__CLASS__, 'front_block'), 10, 2);
            add_filter('do_shortcode_tag', array(__CLASS__, 'front_shortcode'), 10, 2);
            add_action('dynamic_sidebar', array(__CLASS__, 'front_widget'));
            foreach (array('woocommerce_get_cart_url', 'woocommerce_cart_contents_count', 'woocommerce_cart_total', 'woocommerce_cart_subtotal') as $hook) {
                add_filter($hook, array(__CLASS__, 'front_cart_value'));
            }
        } elseif ('filter' === $state['mode']) {
            add_action('admin_bar_menu', array(__CLASS__, 'admin_bar_in_menu'), 99);
            add_action('admin_bar_menu', array(__CLASS__, 'admin_bar'), 999);
            add_action('admin_bar_init', array(__CLASS__, 'admin_bar_style'));
            add_action('admin_bar_init', array(__CLASS__, 'admin_bar_script'));
        }
    }

    /* --------------------------------------------------------------------- */
    /* The must-use file                                                      */
    /* --------------------------------------------------------------------- */

    /**
     * Path of the must-use file.
     *
     * @return string
     */
    private static function path() {
        return WPMU_PLUGIN_DIR . '/' . self::FILE;
    }

    /**
     * Contents of the must-use file.
     *
     * @return string
     */
    private static function code() {
        $main   = plugin_basename(SEOPROSTACK_FILE);
        $loader = '/' . dirname($main) . '/includes/class-seoprostack-plugin-loader.php';
        return "<?php\n"
            . "/**\n"
            . " * Plugin Name: SEO Pro Stack: load plugins only where needed\n"
            . " * Description: Added by SEO Pro Stack for its \"Load plugins only where needed\" setting. It is removed when that setting is switched off or SEO Pro Stack is deactivated.\n"
            . " *\n"
            . ' * ' . self::MARKER . " 1\n"
            . " */\n\n"
            . "if (defined('ABSPATH') && is_file(WP_PLUGIN_DIR . " . var_export($loader, true) . ")) {\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes a PHP string literal.
            . "    require_once WP_PLUGIN_DIR . " . var_export($loader, true) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- as above.
            . "    SEOProStack_Plugin_Loader::start(" . var_export($main, true) . ");\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- as above.
            . "}\n";
    }

    /**
     * Whether the must-use file is in place and current.
     *
     * @return bool
     */
    private static function installed() {
        return is_file(self::path()) && self::fs()->get_contents(self::path()) === self::code();
    }

    /**
     * Keep the must-use file in step with the setting, for people who can
     * manage plugins (so ordinary admin requests do no file work).
     */
    public static function maybe_sync() {
        if (current_user_can('activate_plugins') && !wp_doing_ajax()) {
            self::sync();
        }
    }

    /**
     * Write or remove the must-use file.
     *
     * On multisite the file serves every site and does nothing where the
     * setting is off, so it is only removed when SEO Pro Stack is
     * deactivated network-wide or uninstalled.
     *
     * @return bool Whether the file is as it should be.
     */
    private static function sync() {
        $path = self::path();
        if (self::enabled()) {
            if (self::installed()) {
                return true;
            }
            return wp_mkdir_p(WPMU_PLUGIN_DIR) && self::fs()->put_contents($path, self::code(), 0644);
        }
        if (!is_multisite() && is_file($path)) {
            self::remove_file();
            self::forget();
        }
        return true;
    }

    /**
     * Remove the must-use file if it is ours.
     */
    private static function remove_file() {
        $path = self::path();
        if (is_file($path) && false !== strpos((string) self::fs()->get_contents($path), self::MARKER)) {
            self::fs()->delete($path);
        }
    }

    /**
     * After the switch or the list is saved, update the file at once.
     *
     * @param string $key Setting key.
     */
    public static function setting_saved($key) {
        if (self::KEY === $key) {
            self::sync();
            if (!self::switched_on()) {
                self::forget();
            }
        } elseif (self::LIST_KEY === $key) {
            self::sync();
        } elseif (self::FRONT_KEY === $key || self::FRONT_USERS_KEY === $key
            || SEOProStack_Plugin_Loader::PAGES_KEY === $key || SEOProStack_Plugin_Loader::KEEP_KEY === $key) {
            // A page of the site failed with fewer plugins: saving the list
            // tries again.
            delete_option(SEOProStack_Plugin_Loader::FRONT_FAILED);
            $front = get_option(SEOProStack_Plugin_Loader::FRONT);
            if (is_array($front) && !empty($front['failed'])) {
                $front['failed'] = array();
                update_option(SEOProStack_Plugin_Loader::FRONT, $front, true);
            }
        }
    }

    /**
     * SEO Pro Stack deactivated: remove the must-use file.
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        if (!is_multisite() || $network_wide) {
            self::remove_file();
        }
        self::forget();
    }

    /**
     * Forget what was learned, so it is learned again.
     */
    public static function forget() {
        update_option(SEOProStack_Plugin_Loader::FRONT_REVISION, wp_generate_uuid4(), true);
        delete_option(SEOProStack_Plugin_Loader::FRONT_LOCK);
        delete_option(SEOProStack_Plugin_Loader::FRONT_FAILED);
        if (false !== get_option(SEOProStack_Plugin_Loader::MAP)) {
            delete_option(SEOProStack_Plugin_Loader::MAP);
        }
        if (false !== get_option(SEOProStack_Plugin_Loader::MENU)) {
            delete_option(SEOProStack_Plugin_Loader::MENU);
        }
        if (false !== get_option(SEOProStack_Plugin_Loader::FRONT)) {
            delete_option(SEOProStack_Plugin_Loader::FRONT);
        }
    }

    /**
     * "Check every screen again" from the admin bar: forget what was learned,
     * then return to the screen it was chosen on. With nothing learned, that
     * screen loads every plugin and is learned again, and so is every other
     * screen the next time an administrator opens it.
     */
    public static function reset() {
        if (!current_user_can('activate_plugins')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'seoprostack'), '', array('response' => 403));
        }
        check_admin_referer(self::RESET);
        self::forget();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
        $back       = isset($_GET[self::RETURN_ARG]) && is_string($_GET[self::RETURN_ARG]) ? wp_sanitize_redirect(wp_unslash($_GET[self::RETURN_ARG])) : '';
        $back       = '' !== $back ? wp_validate_redirect($back, '') : '';
        $admin_path = (string) wp_parse_url(admin_url(), PHP_URL_PATH);
        $home_path  = trailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        // Only a path on this site: in wp-admin, or a page of the site.
        if ('' === $back || '' === $admin_path || 0 === strpos($back, '//') || (0 !== strpos($back, $admin_path) && 0 !== strpos($back, $home_path))) {
            $back = admin_url();
        }
        wp_safe_redirect($back);
        exit;
    }

    /**
     * Filesystem access for the must-use file (wp-content is written the
     * same way as the uploads folder elsewhere in SEO Pro Stack).
     *
     * @return WP_Filesystem_Direct
     */
    private static function fs() {
        static $fs = null;
        if (null === $fs) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            $fs = new WP_Filesystem_Direct(null);
        }
        return $fs;
    }

    /* --------------------------------------------------------------------- */
    /* Settings UI                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * Active plugins, file => name, with notes for plugins that change
     * permissions or always load.
     *
     * @return array<string,string>
     */
    public static function plugin_options() {
        $names   = SEOProStack_Plugin_Toggle::plugin_names();
        $map     = get_option(SEOProStack_Plugin_Loader::MAP, array());
        $always  = is_array($map) && isset($map['always']) ? (array) $map['always'] : array();
        $changes = is_array($map) && isset($map['permissions']) ? (array) $map['permissions'] : array();
        $self    = plugin_basename(SEOPROSTACK_FILE);

        $options = array();
        // As stored: plugins skipped on this screen must stay in the list, or
        // saving it would untick them.
        foreach (SEOProStack_Plugin_Loader::stored_active_plugins() as $file) {
            if (!is_string($file) || $self === $file) {
                continue;
            }
            $label = isset($names[$file]) ? $names[$file] : $file;
            if (in_array($file, $always, true)) {
                $label .= ' ' . __('(always loads: it changes the login address or the plugin list)', 'seoprostack');
            } elseif (in_array($file, $changes, true)) {
                $label .= ' ' . __('(changes permissions)', 'seoprostack');
            }
            $options[$file] = $label;
        }
        natcasesort($options);
        return $options;
    }

    /**
     * Active plugins for "Plugins to skip on the site", file => name, with
     * what SEO Pro Stack saw each add to the site, and whether Freesoul
     * Deactivate Plugins skips it there.
     *
     * @return array<string,string>
     */
    public static function front_plugin_options() {
        $names   = SEOProStack_Plugin_Toggle::plugin_names();
        $front   = get_option(SEOProStack_Plugin_Loader::FRONT, array());
        $learned = SEOProStack_Plugin_Loader::front_current();
        $notes   = $learned && isset($front['notes']) ? (array) $front['notes'] : array();
        $active  = SEOProStack_Plugin_Loader::stored_active_plugins();
        $automatic = $learned ? SEOProStack_Plugin_Loader::front_automatic($active, $front) : array();
        $chosen = array_diff(array_unique(array_merge($automatic, (array) SEOProStack_Settings::get(self::FRONT_KEY))), (array) SEOProStack_Settings::get(SEOProStack_Plugin_Loader::KEEP_KEY));
        $skipped = $learned ? SEOProStack_Plugin_Loader::front_skipped($chosen, $front) : array();
        $self    = plugin_basename(SEOPROSTACK_FILE);
        $texts   = array(
            'always'  => __('always loads: it changes logins or the plugin list', 'seoprostack'),
            'core'    => __('always loads: it replaces a WordPress function', 'seoprostack'),
            'content' => __('adds shortcodes, blocks, widgets or content types', 'seoprostack'),
            'pages'   => __('adds to pages', 'seoprostack'),
            'extends' => __('extends a plugin that adds to pages', 'seoprostack'),
            'login'   => __('checks logins', 'seoprostack'),
            'email'   => __('changes how email is sent', 'seoprostack'),
            'bar'     => __('adds to the admin bar', 'seoprostack'),
        );
        // Freesoul Deactivate Plugins' lists for the whole site (read only).
        $fdp = array();
        foreach (array('eos_dp_frontend_everywhere', 'eos_dp_unlogged') as $option) {
            $list = get_option($option);
            $fdp  = array_merge($fdp, is_array($list) ? array_filter($list, 'is_string') : array());
        }
        $fdp = array_flip($fdp);

        $options = array();
        foreach (SEOProStack_Plugin_Loader::stored_active_plugins() as $file) {
            if (!is_string($file) || $self === $file) {
                continue;
            }
            $parts = array();
            if ($learned) {
                foreach (isset($notes[$file]) ? (array) $notes[$file] : array() as $note) {
                    if (isset($texts[$note])) {
                        $parts[] = $texts[$note];
                    }
                }
                if (!$parts) {
                    $parts[] = in_array($file, $skipped, true) ? __('skipped: nothing seen on the site', 'seoprostack') : __('nothing seen on the site', 'seoprostack');
                }
            }
            if (isset($fdp[$file])) {
                $parts[] = __('Freesoul Deactivate Plugins skips it on the site', 'seoprostack');
            }
            $label          = isset($names[$file]) ? $names[$file] : $file;
            $options[$file] = $parts ? $label . ' (' . implode('; ', $parts) . ')' : $label;
        }
        natcasesort($options);
        return $options;
    }

    /**
     * Status above the options.
     *
     * @param string $key   Setting key.
     * @param array  $field Schema entry.
     */
    public static function panel_status($key, $field = array()) {
        if (self::KEY !== $key || !self::enabled()) {
            return;
        }
        if (!self::installed()) {
            echo '<div class="sps-panel-note sps-panel-note--warning"><p>';
            esc_html_e('SEO Pro Stack could not add its loader to the wp-content/mu-plugins folder, so every plugin still loads everywhere. Make that folder writable by the web server, then open this screen again.', 'seoprostack');
            echo '</p></div>';
            return;
        }

        $map = get_option(SEOProStack_Plugin_Loader::MAP, array());
        echo '<div class="sps-panel-note">';
        if (!is_array($map) || empty($map['version'])) {
            echo '<p>' . esc_html__('Screens are learned as you use them: the first time you open a screen it loads every plugin, then only the ones it needs.', 'seoprostack') . '</p>';
        } else {
            $screens = count((array) $map['screens']) + count((array) $map['pages']);
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: number of screens */
                _n('%s screen learned so far. Other screens load every plugin the first time you open them.', '%s screens learned so far. Other screens load every plugin the first time you open them.', $screens, 'seoprostack'),
                number_format_i18n($screens)
            )) . '</p>';
            $failed = count((array) $map['load_all']);
            if ($failed) {
                echo '<p>' . esc_html(sprintf(
                    /* translators: %s: number of screens */
                    _n('%s screen loads every plugin because a plugin failed there with fewer plugins loaded.', '%s screens load every plugin because a plugin failed there with fewer plugins loaded.', $failed, 'seoprostack'),
                    number_format_i18n($failed)
                )) . '</p>';
            }
        }
        echo '<p>' . esc_html__('If something is missing from a screen, reload it with every plugin from the plugin icon in the admin bar (or the plugin count, when the Plugins menu is off). The screen is then checked again. The same menu can also check every screen again.', 'seoprostack') . '</p>';
        echo '</div>';
        self::front_status();
    }

    /**
     * What the settings say about the site: not checked yet, or a page that
     * failed with fewer plugins.
     */
    private static function front_status() {
        $front = get_option(SEOProStack_Plugin_Loader::FRONT, array());
        $failed = SEOProStack_Plugin_Loader::front_failed();
        if (!$failed && !empty($front['failed']) && is_array($front['failed']) && SEOProStack_Plugin_Loader::front_current()) {
            $failed = $front['failed'];
        }
        if ($failed) {
            $names  = SEOProStack_Plugin_Toggle::plugin_names();
            $plugin = !empty($failed['plugin']) ? (isset($names[$failed['plugin']]) ? $names[$failed['plugin']] : $failed['plugin']) : '';
            echo '<div class="sps-panel-note sps-panel-note--warning"><p>';
            echo esc_html(sprintf(
                /* translators: 1: date and time, 2: page address */
                __('A page of the site (%2$s) failed on %1$s while it skipped plugins, so the site loads every plugin again.', 'seoprostack'),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $failed['time']),
                isset($failed['path']) ? (string) $failed['path'] : '/'
            ));
            if ('' !== $plugin) {
                echo ' ' . esc_html(sprintf(
                    /* translators: %s: plugin name */
                    __('The error was in %s, which may need a plugin you ticked for the site.', 'seoprostack'),
                    $plugin
                ));
            }
            echo ' ' . esc_html__('Untick the plugin it needs, or save the list again to try again.', 'seoprostack');
            echo '</p></div>';
            return;
        }
        if (!SEOProStack_Plugin_Loader::front_current()) {
            echo '<div class="sps-panel-note"><p>';
            esc_html_e('Open any page of the site once: SEO Pro Stack then checks what each plugin adds there and shows it next to each plugin below. Until then the site loads every plugin.', 'seoprostack');
            echo '</p></div>';
        }
    }

    /* --------------------------------------------------------------------- */
    /* Learning (every plugin loaded)                                         */
    /* --------------------------------------------------------------------- */

    /**
     * Keep a copy of the admin menu and who owns each page, after every
     * plugin has added its entries.
     */
    public static function capture_menu() {
        global $menu, $submenu, $_registered_pages, $admin_page_hooks, $_parent_pages;

        $submenus = array();
        foreach ((array) $submenu as $parent => $items) {
            $submenus[$parent] = array_map(array(__CLASS__, 'copy_item'), (array) $items);
        }
        $hooks = array_keys((array) $_registered_pages);
        // Capabilities this administrator has here. Plugins often grant
        // their own capabilities with filters, which do not run on screens
        // that skip them.
        $caps = array();
        foreach (array_merge((array) $menu, ...array_values(array_map('array_values', $submenus))) as $item) {
            if (isset($item[1]) && is_string($item[1]) && !isset($caps[$item[1]]) && current_user_can($item[1])) {
                $caps[$item[1]] = true;
            }
        }
        self::$menu = array(
            'caps'             => array_keys($caps),
            'menu'             => array_map(array(__CLASS__, 'copy_item'), (array) $menu),
            'submenu'          => $submenus,
            'hooks'            => $hooks,
            // Pages with a handler; entries that are plain URLs have none.
            'handled'          => array_values(array_filter($hooks, 'has_action')),
            'admin_page_hooks' => (array) $admin_page_hooks,
            'parents'          => (array) $_parent_pages,
        );
        self::$pages = self::page_owners();
    }

    /**
     * After the menu is printed, drop entries from the copy that plugins
     * removed later (such as setup wizards hidden on `admin_head`), so they
     * are not put back on other screens.
     */
    public static function prune_menu() {
        global $menu, $submenu, $_wp_real_parent_file;
        if (null === self::$menu) {
            return;
        }
        // After admin_menu, core renames a parent whose first entry has
        // another slug to that slug. The copy keeps the original names.
        $renamed = function ($slug) use (&$_wp_real_parent_file) {
            return isset($_wp_real_parent_file[$slug]) ? $_wp_real_parent_file[$slug] : $slug;
        };
        $shown = array();
        foreach ((array) $menu as $item) {
            if (isset($item[2])) {
                $shown[$item[2]] = true;
            }
        }
        foreach (self::$menu['menu'] as $position => $item) {
            $slug = isset($item[2]) ? self::here_slug($item[2]) : null;
            if (null !== $slug && !isset($shown[$slug]) && !isset($shown[$renamed($slug)])) {
                unset(self::$menu['menu'][$position]);
            }
        }
        foreach (self::$menu['submenu'] as $parent => $items) {
            $kept       = array();
            $parent_now = isset($submenu[$parent]) ? $parent : $renamed($parent);
            foreach (isset($submenu[$parent_now]) ? (array) $submenu[$parent_now] : array() as $item) {
                if (isset($item[2])) {
                    $kept[$item[2]] = true;
                }
            }
            foreach ($items as $position => $item) {
                if (isset($item[2]) && !isset($kept[self::here_slug($item[2])])) {
                    unset(self::$menu['submenu'][$parent][$position]);
                }
            }
            if (empty(self::$menu['submenu'][$parent])) {
                unset(self::$menu['submenu'][$parent]);
            }
        }
    }

    /**
     * A menu entry as kept in the copy: without counts, and with links back
     * to this screen (such as Customize's return=) marked, so they point to
     * the screen the copy is shown on.
     *
     * @param mixed $item Menu entry.
     * @return mixed
     */
    public static function copy_item($item) {
        $item = self::strip_counts($item);
        if (is_array($item) && isset($item[2]) && is_string($item[2])) {
            foreach (self::here_forms() as $mark => $form) {
                // Only a whole query value: "=" before, "&", "#" or the end after.
                $item[2] = (string) preg_replace('/(?<==)' . preg_quote($form, '/') . '(?=$|&|#)/', $mark, $item[2]);
            }
        }
        return $item;
    }

    /**
     * A copied entry's link, pointing back to the current screen.
     *
     * @param string $slug Slug from the copy.
     * @return string
     */
    private static function here_slug($slug) {
        $slug = (string) $slug;
        if (false === strpos($slug, '{sps-here:')) {
            return $slug;
        }
        $forms = self::here_forms();
        return str_replace(array_keys($forms), array_values($forms), $slug);
    }

    /**
     * Forms of the current screen's address that entries use as a value:
     * encoded (urlencode, rawurlencode), HTML-escaped and plain.
     *
     * @return array<string,string> Placeholder => form.
     */
    private static function here_forms() {
        // As core builds Customize's return= link; core escapes menu links when it prints them.
        $uri = isset($_SERVER['REQUEST_URI']) ? remove_query_arg(wp_removable_query_args(), wp_unslash($_SERVER['REQUEST_URI'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared with menu links and put back into them.
        if (!is_string($uri) || '' === $uri) {
            $uri = wp_parse_url(admin_url('/'), PHP_URL_PATH);
        }
        return array(
            '{sps-here:u}' => urlencode($uri), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- form encoding on purpose; {sps-here:r} is the raw one.
            '{sps-here:r}' => rawurlencode($uri),
            '{sps-here:h}' => str_replace('&', '&#038;', $uri),
            '{sps-here:p}' => (string) $uri,
        );
    }

    /**
     * Remove update and pending counts from a menu entry's title: the copy
     * is shown later, when the numbers may have changed.
     *
     * @param mixed $item Menu entry.
     * @return mixed
     */
    public static function strip_counts($item) {
        if (is_array($item) && isset($item[0]) && is_string($item[0]) && false !== strpos($item[0], '<span')) {
            $item[0] = trim(preg_replace('#\s*<span[^>]*class="[^"]*\b(awaiting-mod|update-plugins|menu-counter|count-\d+)\b[^"]*"[^>]*>(?:[^<]|<span[^>]*>[^<]*</span>)*</span>#', '', $item[0]));
        }
        return $item;
    }

    /**
     * Plugins that own each admin page: those whose code handles the page.
     * Pages handled only by the theme or must-use code need no plugin.
     * Pages with no handler are left out, so they load every plugin.
     *
     * @return array<string,array{plugins: string[], parent: string}>
     */
    private static function page_owners() {
        global $_parent_pages, $admin_page_hooks, $wp_filter;

        $owners = array();
        foreach ((array) $_parent_pages as $slug => $parent) {
            if (!is_string($slug) || '' === $slug) {
                continue;
            }
            $parent = is_string($parent) ? $parent : '';
            if ('' === $parent) {
                // As get_plugin_page_hookname() would, without its side effects.
                $hook = (isset($admin_page_hooks[$slug]) ? 'toplevel' : 'admin') . '_page_' . preg_replace('!\.php!', '', $slug);
            } else {
                $hook = get_plugin_page_hookname($slug, $parent);
            }

            $found = null;
            foreach (array($hook, 'load-' . $hook) as $name) {
                if (empty($wp_filter[$name]) || !($wp_filter[$name] instanceof WP_Hook)) {
                    continue;
                }
                foreach ($wp_filter[$name]->callbacks as $callbacks) {
                    foreach ($callbacks as $callback) {
                        $found  = null === $found ? array() : $found;
                        $plugin = SEOProStack_Plugin_Loader::plugin_for_callback($callback['function']);
                        if ('' !== $plugin) {
                            $found[$plugin] = true;
                        }
                        // A shared framework's page needs every plugin that bundles it.
                        foreach (SEOProStack_Plugin_Loader::plugins_sharing_callback($callback['function']) as $shared) {
                            $found[$shared] = true;
                        }
                    }
                }
            }
            if (null !== $found) {
                $owners[$slug] = array('plugins' => array_keys($found), 'parent' => $parent);
            }
        }
        return $owners;
    }

    /**
     * Save what this request showed.
     */
    public static function learn() {
        $state = SEOProStack_Plugin_Loader::state();
        if (isset($_COOKIE['wp-health-check-disable-plugins']) || 'full' !== $state['mode']) {
            return;
        }

        // Read the stored copy, not the one loaded at the start: another
        // request may have learned something meanwhile.
        wp_cache_delete(SEOProStack_Plugin_Loader::MAP, 'options');
        wp_cache_delete('alloptions', 'options');
        $stored = get_option(SEOProStack_Plugin_Loader::MAP, array());
        $print  = SEOProStack_Plugin_Loader::fingerprint($state['active']);
        $fresh  = is_array($stored) && isset($stored['version'], $stored['active']) && SEOProStack_Plugin_Loader::MAP_VERSION === $stored['version'] && $print === $stored['active'];

        if ($fresh) {
            $map = $stored;
            if ($state['attributing']) {
                // Learning a screen again: owners as seen now. Plugins that
                // stopped adding blocks keep loading in editors (the safe side).
                $map['types']  = array_merge((array) $map['types'], $state['registered']['types']);
                $map['taxes']  = array_merge((array) $map['taxes'], $state['registered']['taxes']);
                $map['sidebars'] = array_merge((array) ($map['sidebars'] ?? array()), $state['registered']['sidebars']);
                $map['blocks'] = array_values(array_unique(array_merge((array) $map['blocks'], array_keys($state['registered']['blocks']))));
            }
        } elseif ($state['attributing']) {
            list($always, $permissions) = self::sensitive_plugins();
            $map = array(
                'version'     => SEOProStack_Plugin_Loader::MAP_VERSION,
                'generation'  => wp_generate_uuid4(),
                'active'      => $print,
                'types'       => $state['registered']['types'],
                'taxes'       => $state['registered']['taxes'],
                'blocks'      => array_keys($state['registered']['blocks']),
                'deps'        => self::dependencies($state['active']),
                'always'      => $always,
                'permissions' => $permissions,
                'settings'    => self::plugins_on_hooks(function ($name) {
                    return 0 === strpos($name, 'seoprostack_');
                }),
                'widgets'     => self::widget_plugins(),
                'sidebars'    => $state['registered']['sidebars'],
                'pages'       => array(),
                'screens'     => array(),
                'tables'      => array(),
                'load_all'    => array(),
            );
        } else {
            return; // Plugins changed during this request; the next one learns.
        }
        if ($state['attributing']) {
            $map['choices'] = self::choice_plugins($map);
        }

        if (null !== self::$pages) {
            foreach (self::$pages as $slug => $page) {
                $plugins = $page['plugins'];
                $parent  = $page['parent'];
                // A page also needs the plugin whose menu it sits in.
                if (isset(self::$pages[$parent])) {
                    $plugins = array_merge($plugins, self::$pages[$parent]['plugins']);
                } elseif (preg_match('/^edit\.php\?post_type=([a-z0-9_-]+)$/', $parent, $match) && !empty($map['types'][$match[1]])) {
                    $plugins[] = $map['types'][$match[1]];
                }
                $map['pages'][$slug] = array_values(array_unique($plugins));
            }
        }

        $screen = $state['screen'];
        if ('' !== $screen && 0 !== strpos($screen, 'page:')) {
            list($kind, $name) = array_pad(explode(':', $screen, 2), 2, '');
            if ('menus' === $kind) {
                $map['screens'][$screen] = self::menu_plugins($map);
                $map['menu_locations']   = array_keys(get_registered_nav_menus());
            } elseif ('site-editor' === $kind) {
                $map['screens'][$screen]  = self::site_editor_plugins($map);
                $map['site_editor_theme'] = get_option('stylesheet');
            } elseif ('customizer' === $kind) {
                global $wp_registered_sidebars, $wp_widget_factory;
                // A safety reload kept every plugin. Do not relearn it back
                // to the incomplete form on that reload; an explicit check
                // (reason "learning") can try again later.
                $kept_full = 'needed' === $state['reason'] && $state['active'] === (array) ($map['screens'][$screen] ?? array());
                if (!$kept_full) {
                    $map['screens'][$screen] = array_values(array_unique(array_merge(
                        self::form_plugins($kind, $name), self::menu_plugins($map), self::widget_plugins(), array_values((array) $map['sidebars']), (array) $map['blocks']
                    )));
                }
                $map['customizer_block_widgets'] = wp_use_widgets_block_editor();
                $map['customizer_theme']    = get_option('stylesheet');
                $map['customizer_sidebars'] = array_keys((array) $wp_registered_sidebars);
                $map['customizer_widgets']  = isset($wp_widget_factory->widgets) ? array_keys($wp_widget_factory->widgets) : array();
                $map['customizer_menus']    = array_keys(get_registered_nav_menus());
            } elseif ('import' === $kind) {
                $map['screens'][$screen] = self::import_plugins();
            } elseif ('export' === $kind) {
                // The form offers every exportable post type, not just posts.
                $map['screens'][$screen] = array_values(array_unique(array_merge(
                    self::form_plugins($kind, $name), array_values((array) $map['types']), array_values((array) $map['taxes'])
                )));
            } elseif ('list' === $kind && self::list_form_shown($name)) {
                // Quick Edit and Bulk Edit: only plugins that put fields or
                // row data in them on this list. Plugins that hook them for
                // other post types (WooCommerce for products) add nothing here.
                $map['screens'][$screen] = array_keys(self::$form_seen);
            } else {
                $map['screens'][$screen] = self::form_plugins($kind, $name);
            }
            if (self::table_hooks($screen)) {
                // Added to what was seen before: rows, and with them row links,
                // only show when the list has items.
                $tables          = isset($map['tables']) ? (array) $map['tables'] : array();
                $before          = isset($tables[$screen]) ? (array) $tables[$screen] : array();
                $tables[$screen] = array_values(array_unique(array_merge($before, self::table_plugins())));
                $map['tables']   = $tables;
            }
        }

        if ($map !== $stored) {
            update_option(SEOProStack_Plugin_Loader::MAP, $map, true);
        }
        if (null !== self::$menu && get_option(SEOProStack_Plugin_Loader::MENU) !== self::$menu) {
            update_option(SEOProStack_Plugin_Loader::MENU, self::$menu, false);
        }
    }

    /**
     * After a page of the site loaded every plugin: note what each plugin
     * adds there, which must always load, and which plugins need which.
     */
    public static function learn_front() {
        $state = SEOProStack_Plugin_Loader::state();
        if (isset($_COOKIE['wp-health-check-disable-plugins']) || 'front' !== $state['screen'] || 'full' !== $state['mode'] || 'learning' !== $state['reason']) {
            return;
        }
        // Learning again on request is for administrators.
        if ($state['relearn'] && !current_user_can('activate_plugins')) {
            return;
        }
        $error = error_get_last();
        if (http_response_code() >= 500 || ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true))) {
            return; // A failed full request is not evidence of page ownership.
        }
        $print = SEOProStack_Plugin_Loader::fingerprint($state['active']);
        if (SEOProStack_Plugin_Loader::fingerprint(SEOProStack_Plugin_Loader::stored_active_plugins()) !== $print) {
            return; // Plugins changed during this request; the next one learns.
        }
        $deps   = self::dependencies($state['active']);
        $notes  = self::front_notes($state, $deps);
        $previous = get_option(SEOProStack_Plugin_Loader::FRONT, array());
        $current = SEOProStack_Plugin_Loader::front_current();
        if ($current && !empty($previous['failed']) && !$state['relearn']) {
            return; // Do not overwrite a failure recorded by another request.
        }
        if ($current) {
            foreach ((array) $previous['notes'] as $file => $list) {
                $notes[$file] = array_values(array_unique(array_merge((array) $list, (array) ($notes[$file] ?? array()))));
            }
        }
        $always = array();
        foreach ($notes as $file => $list) {
            if (array_intersect($list, array('always', 'core'))) {
                $always[] = $file;
            }
        }
        $kinds = $current ? (array) ($previous['kinds'] ?? array()) : array();
        $candidates = array();
        foreach ($notes as $file => $list) {
            // Unknown global callbacks are not proof that a plugin is unused.
            if (in_array('content', $list, true) && !array_intersect($list, array('always', 'core', 'pages', 'login'))) {
                $candidates[] = $file;
            }
        }
        $woo = 'woocommerce/woocommerce.php';
        if (in_array($woo, $state['active'], true) && self::woo_can_skip()) {
            $candidates[] = $woo;
            if (SEOProStack_Settings::get(SEOProStack_Plugin_Loader::PAGES_KEY)) {
                // Its known auth and lost-password hooks do not apply to a
                // cookie-free public request; those requests are guarded above.
                $always = array_values(array_diff($always, array($woo)));
            }
        }
        $key = SEOProStack_Settings::get(SEOProStack_Plugin_Loader::PAGES_KEY) ? self::front_kind() : '';
        $learned_kind = false;
        if ('' !== $key && empty($_COOKIE) && !is_user_logged_in() && !is_404() && !is_feed()
            && !is_preview() && empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            && empty($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['REQUEST_METHOD']) && 'GET' === $_SERVER['REQUEST_METHOD']
            && did_action('wp_footer') && 200 === http_response_code()) {
            $kinds[$key] = array('needs' => self::page_needs($state), 'learned' => time());
            $learned_kind = true;
        }
        if ($current && !$state['relearn'] && !$learned_kind) {
            delete_option(SEOProStack_Plugin_Loader::FRONT_LOCK);
            return; // An unsupported or unsuccessful public request must not rewrite the learned map.
        }
        if (!SEOProStack_Plugin_Loader::front_revision_current()) {
            return; // Content/settings changed while this page was being rendered.
        }
        if (self::migrate_front($state['active'], $notes)) {
            delete_option(SEOProStack_Plugin_Loader::FRONT_LOCK);
            return; // Preserving choices invalidates this map; the next full visit learns it.
        }
        update_option(SEOProStack_Plugin_Loader::FRONT, array(
            'version' => SEOProStack_Plugin_Loader::FRONT_VERSION,
            'revision' => $state['front_revision'],
            'active'  => $print,
            'deps'    => $deps,
            'always'  => $always,
            'notes'   => $notes,
            'learned' => time(),
            'failed'  => array(),
            'kinds'   => $kinds,
            'routes'  => self::front_routes(),
            'blocks'  => self::front_blocks($state),
            'shortcodes' => self::front_shortcodes(),
            'woo_shop' => in_array($woo, $state['active'], true) ? (int) get_option('woocommerce_shop_page_id') : 0,
            'woo_pages' => array_values(array_filter(array_map('intval', array(
                get_option('woocommerce_shop_page_id'), get_option('woocommerce_cart_page_id'),
                get_option('woocommerce_checkout_page_id'), get_option('woocommerce_myaccount_page_id'),
            )))),
            'candidates' => array_values(array_unique($candidates)),
        ), true);
        if (!$state['relearn']) {
            delete_option(SEOProStack_Plugin_Loader::FRONT_LOCK);
        }
    }

    /** The bounded query kinds whose templates and global parts are learned. */
    private static function front_kind() {
        if (is_front_page()) {
            return 'front';
        }
        if (is_home()) {
            return 'home';
        }
        if (function_exists('is_shop') && is_shop()) {
            return 'archive:product'; // WooCommerce may retain the shop page as its queried object.
        }
        if (is_singular()) {
            $template = get_post_meta(get_queried_object_id(), '_wp_page_template', true);
            return !$template || 'default' === $template ? 'single:' . get_post_type(get_queried_object_id()) : '';
        }
        if (is_search()) {
            return 'search';
        }
        if (is_category() || is_tag() || is_tax()) {
            $object = get_queried_object();
            return $object instanceof WP_Term ? 'taxonomy:' . $object->taxonomy : '';
        }
        if (is_post_type_archive()) {
            $object = get_queried_object();
            return $object instanceof WP_Post_Type ? 'archive:' . $object->name : '';
        }
        if (is_author()) {
            return 'author';
        }
        return is_date() ? 'date' : '';
    }

    /** Public query variables, saved while plugins have registered their types. */
    private static function front_routes() {
        $routes = array('public_types' => array(), 'type_vars' => array(), 'tax_vars' => array(), 'archives' => array());
        foreach (get_post_types(array(), 'objects') as $name => $object) {
            if ((!$object->publicly_queryable && 'page' !== $name) || 'attachment' === $name) {
                continue;
            }
            $routes['public_types'][] = $name;
            if (is_string($object->query_var) && '' !== $object->query_var) {
                $routes['type_vars'][$object->query_var] = $name;
            }
            if ($object->has_archive) {
                $routes['archives'][] = $name;
            }
        }
        foreach (get_taxonomies(array(), 'objects') as $name => $object) {
            if ($object->publicly_queryable && is_string($object->query_var) && '' !== $object->query_var) {
                $routes['tax_vars'][$object->query_var] = $name;
            }
        }
        return $routes;
    }

    /** Include core-owned registrations too: absence, not an empty owner, is unknown. */
    private static function front_blocks(array $state) {
        $blocks = array();
        foreach (WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block) {
            $blocks[$name] = $state['registered']['block_names'][$name] ?? (0 === strpos($name, 'core/') ? '' : false);
        }
        return $blocks;
    }

    /** Shortcode owners available before plugins load on the next request. */
    private static function front_shortcodes() {
        global $shortcode_tags;
        $owners = array();
        foreach ((array) $shortcode_tags as $tag => $callback) {
            $owners[$tag] = SEOProStack_Plugin_Loader::plugin_for_callback($callback);
        }
        return $owners;
    }

    /**
     * Preserve saved site-wide choices once, after successful learning.
     *
     * @param string[] $active Active plugins on the full request.
     * @param array    $notes  Learned site contributions.
     * @return bool Whether settings changed, invalidating the current map.
     */
    private static function migrate_front(array $active, array $notes) {
        if (get_option(SEOProStack_Plugin_Loader::FRONT_MIGRATED, false)) {
            return false;
        }
        $changed = false;
        $options = get_option(SEOProStack_Settings::OPTION, array());
        if (is_array($options) && array_key_exists(self::FRONT_KEY, $options)) {
            $automatic = SEOProStack_Plugin_Loader::front_automatic($active, array('notes' => $notes));
            $keep = array_values(array_unique(array_merge((array) ($options[SEOProStack_Plugin_Loader::KEEP_KEY] ?? array()), array_diff($automatic, (array) $options[self::FRONT_KEY], array(plugin_basename(SEOPROSTACK_FILE))))));
            $options[SEOProStack_Plugin_Loader::KEEP_KEY] = $keep;
            $changed = update_option(SEOProStack_Settings::OPTION, $options);
        }
        update_option(SEOProStack_Plugin_Loader::FRONT_MIGRATED, true, false);
        return $changed;
    }

    /** Forget page ownership when settings used before plugins load change. */
    public static function front_option_changed($name) {
        if ('seoprostack_options' !== $name && (!self::enabled() || !SEOProStack_Settings::get(SEOProStack_Plugin_Loader::PAGES_KEY))) {
            return;
        }
        // Settings of any content plugin can change what an old route needs.
        // Exclude volatile core caches and this loader's own learning writes.
        if (0 === strpos((string) $name, '_transient_') || 0 === strpos((string) $name, '_site_transient_')
            || in_array($name, array('cron', SEOProStack_Plugin_Loader::FRONT, SEOProStack_Plugin_Loader::FRONT_LOCK,
                SEOProStack_Plugin_Loader::FRONT_REVISION, SEOProStack_Plugin_Loader::FRONT_FAILED, SEOProStack_Plugin_Loader::FRONT_MIGRATED,
                SEOProStack_Plugin_Loader::MAP, SEOProStack_Plugin_Loader::MENU, SEOProStack_Plugin_Loader::HISTORY), true)) {
            return;
        }
        self::forget_front();
    }

    /** Invalidate the public map without changing the admin map. */
    public static function forget_front() {
        if (defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }
        $revision = wp_generate_uuid4();
        update_option(SEOProStack_Plugin_Loader::FRONT_REVISION, $revision, true);
        $front = get_option(SEOProStack_Plugin_Loader::FRONT, array());
        if (is_array($front) && !empty($front['failed'])) {
            $front['kinds'] = array();
            $front['revision'] = $revision;
            update_option(SEOProStack_Plugin_Loader::FRONT, $front, true);
        } else {
            delete_option(SEOProStack_Plugin_Loader::FRONT);
        }
        delete_option(SEOProStack_Plugin_Loader::FRONT_LOCK);
    }

    /** Capture content types in secondary loops as well as the main query. */
    public static function front_loop($query) {
        $state = SEOProStack_Plugin_Loader::state();
        foreach ((array) $query->posts as $post) {
            if ($post instanceof WP_Post && !empty($state['registered']['types'][$post->post_type])) {
                self::$front_seen[] = $state['registered']['types'][$post->post_type];
            }
        }
    }

    /** Keep the owner of blocks rendered from theme files or template parts. */
    public static function front_block($output, $block) {
        $name = isset($block['blockName']) ? (string) $block['blockName'] : '';
        if (self::front_primary_content('wp:' . (0 === strpos($name, 'core/') ? substr($name, 5) : $name))) {
            return $output; // The requested post is inspected separately before plugins load.
        }
        $state = SEOProStack_Plugin_Loader::state();
        if (!empty($state['registered']['block_names'][$name])) {
            self::$front_seen[] = $state['registered']['block_names'][$name];
        }
        if (0 === strpos($name, 'woocommerce/')) {
            self::$front_seen[] = 'woocommerce/woocommerce.php';
        }
        return $output;
    }

    /** Keep shortcode owners even when a theme renders them outside a post. */
    public static function front_shortcode($output, $tag) {
        if (self::front_primary_content('[' . $tag)) {
            return $output;
        }
        global $shortcode_tags;
        if (isset($shortcode_tags[$tag])) {
            self::$front_seen[] = SEOProStack_Plugin_Loader::plugin_for_callback($shortcode_tags[$tag]);
        }
        return $output;
    }

    /** Only exclude syntax actually present in the primary post, not secondary content. */
    private static function front_primary_content($syntax) {
        global $post;
        return doing_filter('the_content') && is_singular() && $post instanceof WP_Post
            && (int) $post->ID === get_queried_object_id() && false !== strpos($post->post_content, $syntax);
    }

    /** Capture widgets inserted by theme or plugin filters, not just saved ones. */
    public static function front_widget($widget) {
        if (isset($widget['callback'])) {
            self::$front_seen[] = SEOProStack_Plugin_Loader::plugin_for_callback($widget['callback']);
        }
    }

    /** Configured sidebars through public option/filter APIs. */
    private static function front_sidebars() {
        $widgets = (array) get_option('sidebars_widgets', array());
        unset($widgets['array_version']);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's existing sidebar filter, not a new plugin hook.
        return (array) apply_filters('sidebars_widgets', $widgets);
    }

    /** Record cart getters called by a theme or another plugin, not WC setup. */
    public static function front_cart_value($value) {
        $self = plugin_basename(SEOPROSTACK_FILE);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- only on a full learning request.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (empty($frame['file'])) {
                continue;
            }
            $file = wp_normalize_path($frame['file']);
            $plugin = SEOProStack_Plugin_Loader::plugin_for_file($file);
            if (false !== strpos($file, '/themes/') || ('' !== $plugin && $plugin !== $self && 'woocommerce/woocommerce.php' !== $plugin)) {
                self::$front_seen[] = 'woocommerce/woocommerce.php';
                break;
            }
        }
        return $value;
    }

    /**
     * WooCommerce's global assets are intentional unless the owner has already
     * chosen to remove them. Configured WooCommerce widgets are retained: their
     * cart may appear only on some devices or for some visitors. Unknown security
     * hooks in a future WooCommerce version fall back to loading it everywhere.
     *
     * @return bool
     */
    private static function woo_can_skip() {
        global $wp_filter;
        if (!SEOProStack_Woo_Light::enabled()
            || 'yes' === get_option('woocommerce_demo_store', 'no')) {
            return false;
        }
        // Known callbacks in WooCommerce 8.2.2 and 11.1.2. These authenticate
        // REST/WooCommerce.com requests or grant capabilities to signed-in users.
        // Any new callback falls back to loading WooCommerce on every page.
        $public_hooks = array(
            'determine_current_user' => array('WC_REST_Authentication::authenticate', 'WC_WCCOM_Site::authenticate_wccom',
                'Automattic\\Jetpack\\Connection\\Rest_Authentication::wp_rest_authenticate'),
            'lostpassword_url' => array('wc_lostpassword_url'),
            'map_meta_cap' => array('wc_modify_map_meta_cap', 'Automattic\\Jetpack\\Connection\\Manager::jetpack_connection_custom_caps',
                'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController::maybe_translate_order_caps'),
            'user_has_cap' => array('wc_customer_has_capability', 'wc_shop_manager_has_capability'),
        );
        foreach ((array) $wp_filter as $name => $hook) {
            if (!($hook instanceof WP_Hook) || 'always' !== self::front_hook_kind($name)) {
                continue;
            }
            foreach ($hook->callbacks as $callbacks) {
                foreach ($callbacks as $callback) {
                    $function = $callback['function'];
                    if ('woocommerce/woocommerce.php' !== SEOProStack_Plugin_Loader::plugin_for_callback($function)) {
                        continue;
                    }
                    $id = is_array($function) ? (is_object($function[0]) ? get_class($function[0]) : $function[0]) . '::' . $function[1] : $function;
                    if (!isset($public_hooks[$name]) || !in_array($id, $public_hooks[$name], true)) {
                        return false;
                    }
                }
            }
        }
        $items = (array) SEOProStack_Settings::get(SEOProStack_Woo_Light::ITEMS_KEY);
        if (!in_array('scripts', $items, true) || !in_array('fragments', $items, true)) {
            return false;
        }
        foreach (self::front_sidebars() as $sidebar => $widgets) {
            if ('wp_inactive_widgets' === $sidebar) {
                continue;
            }
            foreach ((array) $widgets as $widget) {
                if (0 === strpos((string) $widget, 'woocommerce_')) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Content owners on a fully rendered page, including secondary loops.
     * Actual shortcode and block output outside the main post is also retained
     * conservatively by examining registered global templates and widgets.
     *
     * @param array $state Full-request registration owners.
     * @return string[]
     */
    private static function page_needs(array $state) {
        global $wp_query, $shortcode_tags;
        $needs = self::$front_seen;
        $own_content = is_singular() ? SEOProStack_Plugin_Loader::content_needs((string) get_post_field('post_content', get_queried_object_id()), array(
            'blocks' => self::front_blocks($state), 'shortcodes' => self::front_shortcodes(),
        )) : array();
        if (false === $own_content) {
            return $state['active'];
        }
        // Remaining assets (including analytics and dependencies) mean a plugin
        // still contributes to this page after other features have dequeued it.
        $root = (string) wp_parse_url(plugins_url('/'), PHP_URL_PATH);
        foreach (array(wp_scripts(), wp_styles()) as $assets) {
            // array_values: array_unique keeps keys, and the loop below reads
            // 0..count-1 while appending dependencies, so gaps would skip handles.
            $handles = array_values(array_unique(array_merge($assets->queue, $assets->done)));
            for ($i = 0; $i < count($handles); $i++) {
                $handle = $handles[$i];
                if (empty($assets->registered[$handle])) {
                    continue;
                }
                $asset = $assets->registered[$handle];
                foreach ((array) $asset->deps as $dep) {
                    if (!in_array($dep, $handles, true)) {
                        $handles[] = $dep;
                    }
                }
                $path = (string) wp_parse_url((string) $asset->src, PHP_URL_PATH);
                if ('' !== $root && 0 === strpos($path, $root)) {
                    $owner = SEOProStack_Plugin_Loader::plugin_for_file(WP_PLUGIN_DIR . '/' . substr($path, strlen($root)));
                    if (!in_array($owner, $own_content, true) || in_array($owner, self::$front_seen, true)) {
                        $needs[] = $owner;
                    }
                }
            }
        }
        foreach (self::front_sidebars() as $sidebar => $widgets) {
            if ('wp_inactive_widgets' === $sidebar) {
                continue;
            }
            foreach ((array) $widgets as $widget) {
                if (isset($GLOBALS['wp_registered_widgets'][$widget]['callback'])) {
                    $needs[] = SEOProStack_Plugin_Loader::plugin_for_callback($GLOBALS['wp_registered_widgets'][$widget]['callback']);
                }
            }
        }
        $posts = !is_singular() && $wp_query instanceof WP_Query ? (array) $wp_query->posts : array();
        if (is_singular()) {
            $type = get_post_type(get_queried_object_id());
            $needs[] = $state['registered']['types'][$type] ?? '';
        }
        if (is_search()) {
            // Any searchable type may appear on another search, even if absent now.
            foreach (get_post_types(array('exclude_from_search' => false), 'names') as $type) {
                $needs[] = $state['registered']['types'][$type] ?? '';
            }
        }
        if (!is_singular()) {
            // Other searches, terms and pagination pages can render different content.
            $needs = array_merge($needs, array_values(self::front_blocks($state)), array_values(self::front_shortcodes()));
        }
        // Global template parts and reusable blocks can add content outside the
        // post. Page-specific templates are captured while they render above.
        $posts = array_merge($posts, get_posts(array('post_type' => array('wp_template_part', 'wp_block'),
            'numberposts' => 100, 'post_status' => 'publish', 'suppress_filters' => false)));
        if (count($posts) >= 100) {
            return $state['active']; // Too much global content to inspect cheaply.
        }
        $texts = array(wp_json_encode(get_option('widget_block', array())), wp_json_encode(get_option('widget_text', array())));
        foreach ($posts as $post) {
            if (!($post instanceof WP_Post)) {
                continue;
            }
            $texts[] = $post->post_content;
            if (!empty($state['registered']['types'][$post->post_type])) {
                $needs[] = $state['registered']['types'][$post->post_type];
            }
        }
        $object = get_queried_object();
        if ($object instanceof WP_Term && !empty($state['registered']['taxes'][$object->taxonomy])) {
            $needs[] = $state['registered']['taxes'][$object->taxonomy];
        }
        if ($object instanceof WP_Post_Type && !empty($state['registered']['types'][$object->name])) {
            $needs[] = $state['registered']['types'][$object->name];
        }
        $content = implode("\n", $texts);
        $global_needs = SEOProStack_Plugin_Loader::content_needs($content, array('blocks' => self::front_blocks($state), 'shortcodes' => self::front_shortcodes()));
        if (false === $global_needs) {
            return $state['active'];
        }
        $needs = array_merge($needs, $global_needs);
        if (class_exists('WooCommerce', false) && (did_action('woocommerce_before_mini_cart') || did_filter('lostpassword_url')
            || false !== strpos($content, 'wp:woocommerce/'))) {
            $needs[] = 'woocommerce/woocommerce.php';
        }
        return array_values(array_unique(array_filter($needs)));
    }

    /**
     * What each plugin adds to the site, from what it registered on this
     * page view: plugin file => notes, in the order they are shown.
     *
     * @param array                  $state Loader state.
     * @param array<string,string[]> $deps  Which plugins each plugin needs.
     * @return array<string,string[]>
     */
    private static function front_notes(array $state, array $deps) {
        global $wp_filter, $shortcode_tags;
        $self  = plugin_basename(SEOPROSTACK_FILE);
        $found = array();
        $note  = function ($plugin, $kind) use (&$found, $self) {
            if (is_string($plugin) && '' !== $plugin && $self !== $plugin) {
                $found[$plugin][$kind] = true;
            }
        };

        // Shortcodes, blocks, widgets and public content types.
        foreach ((array) $shortcode_tags as $callback) {
            $note(SEOProStack_Plugin_Loader::plugin_for_callback($callback), 'content');
        }
        foreach (array_keys((array) $state['registered']['blocks']) as $plugin) {
            $note($plugin, 'content');
        }
        foreach (self::widget_plugins() as $plugin) {
            $note($plugin, 'content');
        }
        foreach ((array) $state['registered']['types'] as $name => $plugin) {
            $object = get_post_type_object((string) $name);
            if ($object && ($object->public || $object->publicly_queryable)) {
                $note($plugin, 'content');
            }
        }
        foreach ((array) $state['registered']['taxes'] as $name => $plugin) {
            $object = get_taxonomy((string) $name);
            if ($object && ($object->public || $object->publicly_queryable)) {
                $note($plugin, 'content');
            }
        }

        // Hooks: pages, logins, email, the admin bar, and what always loads.
        foreach ((array) $wp_filter as $name => $hook) {
            if (!is_string($name) || !($hook instanceof WP_Hook)) {
                continue;
            }
            $kind = self::front_hook_kind($name);
            if ('' === $kind) {
                continue;
            }
            foreach ($hook->callbacks as $callbacks) {
                foreach ($callbacks as $callback) {
                    $note(SEOProStack_Plugin_Loader::plugin_for_callback($callback['function']), $kind);
                }
            }
        }

        // Core functions a plugin replaced.
        foreach (self::PLUGGABLE as $function) {
            if (!function_exists($function)) {
                continue;
            }
            try {
                $file = (string) (new ReflectionFunction($function))->getFileName();
            } catch (ReflectionException $e) {
                $file = '';
            }
            $note('' !== $file ? SEOProStack_Plugin_Loader::plugin_for_file($file) : '', 'core');
        }

        // Plugins that need one that adds to pages, such as WooCommerce
        // extensions, often add to its pages through its own hooks.
        foreach ($deps as $file => $needs) {
            if (!empty($found[$file]['pages']) || !empty($found[$file]['content'])) {
                continue;
            }
            foreach ((array) $needs as $need) {
                if (!empty($found[$need]['pages']) || !empty($found[$need]['content'])) {
                    $note($file, 'extends');
                    break;
                }
            }
        }

        $order = array('always', 'core', 'content', 'pages', 'extends', 'login', 'email', 'bar');
        $notes = array();
        foreach ($found as $plugin => $kinds) {
            $notes[$plugin] = array_values(array_intersect($order, array_keys($kinds)));
        }
        return $notes;
    }

    /**
     * What a hook says about a plugin on it, on the site: 'always',
     * 'login', 'email', 'bar', 'pages' or '' (nothing to note).
     *
     * @param string $name Hook name.
     * @return string
     */
    private static function front_hook_kind($name) {
        if (in_array($name, SEOProStack_Plugin_Loader::ALWAYS_HOOKS, true) || in_array($name, self::FRONT_ALWAYS_HOOKS, true)) {
            return 'always';
        }
        if (in_array($name, self::FRONT_LOGIN_HOOKS, true)) {
            return 'login';
        }
        if (in_array($name, self::FRONT_EMAIL_HOOKS, true)) {
            return 'email';
        }
        if (in_array($name, self::FRONT_BAR_HOOKS, true)) {
            return 'bar';
        }
        if (in_array($name, self::FRONT_PAGE_HOOKS, true)) {
            return 'pages';
        }
        foreach (self::FRONT_PAGE_PREFIXES as $prefix) {
            if (0 === strpos($name, $prefix)) {
                return 'pages';
            }
        }
        foreach (self::FRONT_PAGE_SUFFIXES as $suffix) {
            if (strlen($name) > strlen($suffix) && substr($name, -strlen($suffix)) === $suffix) {
                return 'pages';
            }
        }
        return '';
    }

    /**
     * Plugins with callbacks on any of the hooks.
     *
     * @param callable $match Receives a hook name; returns whether it counts.
     * @return string[]
     */
    private static function plugins_on_hooks($match) {
        global $wp_filter;
        $self    = plugin_basename(SEOPROSTACK_FILE);
        $plugins = array();
        foreach ((array) $wp_filter as $name => $hook) {
            if (!is_string($name) || !($hook instanceof WP_Hook) || !$match($name)) {
                continue;
            }
            foreach ($hook->callbacks as $callbacks) {
                foreach ($callbacks as $callback) {
                    $plugin = SEOProStack_Plugin_Loader::plugin_for_callback($callback['function']);
                    if ('' !== $plugin && $self !== $plugin) {
                        $plugins[$plugin] = true;
                    }
                }
            }
        }
        return array_keys($plugins);
    }

    /**
     * Whether this request showed the list's Quick Edit and Bulk Edit form,
     * so the plugins that printed into it are all that it needs. WordPress
     * prints the form only when the list has rows; an empty list falls back
     * to every plugin hooked to the form. Media lists have no Quick Edit.
     *
     * @param string $name Post type of the list.
     * @return bool
     */
    private static function list_form_shown($name) {
        global $wp_list_table;
        if ('attachment' === $name) {
            return true;
        }
        return $wp_list_table instanceof WP_Posts_List_Table && $wp_list_table->has_items();
    }

    /**
     * Plugins that add boxes, fields or editor features to a screen
     * ("post", "terms", "list", "user", "tools", "media-new"), or boxes
     * to the Dashboard ("dashboard"); none for other screens.
     *
     * @param string $kind Screen kind.
     * @param string $name Post type or taxonomy.
     * @return string[]
     */
    private static function form_plugins($kind, $name) {
        if ('dashboard' === $kind) {
            return self::dashboard_plugins();
        }
        if (!in_array($kind, array('post', 'terms', 'list', 'user', 'tools', 'media-new', 'menus', 'site-editor', 'customizer', 'import', 'export'), true)) {
            return array();
        }
        return self::plugins_on_hooks(function ($hook) use ($kind, $name) {
            return SEOProStack_Plugin_Loader::screen_needs_hook($kind, $name, $hook);
        });
    }

    /**
     * Hooks through which plugins add to a list screen, and how to tell
     * whether a callback added something: "columns" (column keys it adds),
     * "filter" (it changes the value or prints) or "action" (it prints).
     * Hooks for both post and page lists are listed: only those that run count.
     *
     * @param string $screen Screen key.
     * @return array<string,string> Hook => kind; empty for screens without a list.
     */
    private static function table_hooks($screen) {
        list($kind, $name) = array_pad(explode(':', (string) $screen, 2), 2, '');
        if ('list' === $kind && 'attachment' === $name) {
            return array(
                'manage_media_columns'        => 'columns',
                'manage_upload_columns'       => 'columns',
                'media_row_actions'           => 'filter',
                'views_upload'                => 'filter',
                'bulk_actions-upload'         => 'filter',
                'restrict_manage_posts'       => 'action',
                'manage_posts_extra_tablenav' => 'action',
                'manage_media_custom_column'  => 'action',
            );
        }
        if ('list' === $kind && '' !== $name) {
            return array(
                'manage_posts_columns'                     => 'columns',
                'manage_pages_columns'                     => 'columns',
                'manage_' . $name . '_posts_columns'       => 'columns',
                'manage_edit-' . $name . '_columns'        => 'columns',
                'post_row_actions'                         => 'filter',
                'page_row_actions'                         => 'filter',
                'views_edit-' . $name                      => 'filter',
                'bulk_actions-edit-' . $name               => 'filter',
                'restrict_manage_posts'                    => 'action',
                'manage_posts_extra_tablenav'              => 'action',
                'manage_posts_custom_column'               => 'action',
                'manage_pages_custom_column'               => 'action',
                'manage_' . $name . '_posts_custom_column' => 'action',
            );
        }
        if ('terms' === $kind && '' !== $name) {
            return array(
                'manage_edit-' . $name . '_columns'  => 'columns',
                $name . '_row_actions'               => 'filter',
                'views_edit-' . $name                => 'filter',
                'bulk_actions-edit-' . $name         => 'filter',
                'manage_' . $name . '_custom_column' => 'filter',
            );
        }
        if ('users' === $kind) {
            return array(
                'manage_users_columns'        => 'columns',
                'user_row_actions'            => 'filter',
                'views_users'                 => 'filter',
                'bulk_actions-users'          => 'filter',
                'manage_users_custom_column'  => 'filter',
                'restrict_manage_users'       => 'action',
                'manage_users_extra_tablenav' => 'action',
            );
        }
        return array();
    }

    /**
     * Just before a list hook runs (on the `all` hook, while learning a list
     * screen): wrap each plugin's callback on it, so that what it adds is
     * noted. The callbacks keep their keys, so removing them still works.
     *
     * @param string $hook Hook about to run.
     */
    public static function watch_table_hook($hook) {
        global $wp_filter;
        static $hooks = null;
        if (null === $hooks) {
            $state = SEOProStack_Plugin_Loader::state();
            $hooks = self::table_hooks($state['screen']);
            // Quick Edit and Bulk Edit fields on post lists: noted apart.
            if (0 === strpos((string) $state['screen'], 'list:')) {
                $hooks += array_fill_keys(SEOProStack_Plugin_Loader::LIST_HOOKS, 'form');
            }
        }
        if (!is_string($hook) || !isset($hooks[$hook]) || empty($wp_filter[$hook]) || !($wp_filter[$hook] instanceof WP_Hook)) {
            return;
        }
        $type = $hooks[$hook];
        $self = plugin_basename(SEOPROSTACK_FILE);
        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $id => $callback) {
                $key = $hook . '|' . $priority . '|' . $id;
                if (isset(self::$table_watched[$key])) {
                    continue;
                }
                self::$table_watched[$key] = true;
                $plugin = SEOProStack_Plugin_Loader::plugin_for_callback($callback['function']);
                if ('' === $plugin || $self === $plugin) {
                    continue;
                }
                $wp_filter[$hook]->callbacks[$priority][$id]['function'] = self::table_watcher($callback['function'], $plugin, $type);
            }
        }
    }

    /**
     * A callback that runs the plugin's own and notes what it added.
     *
     * @param callable $original Plugin's callback.
     * @param string   $plugin   Plugin file.
     * @param string   $type     "columns", "filter", "action", or "form" (Quick Edit and Bulk Edit).
     * @return Closure
     */
    private static function table_watcher($original, $plugin, $type) {
        return function (...$args) use ($original, $plugin, $type) {
            ob_start();
            $value  = call_user_func_array($original, $args);
            $output = (string) ob_get_clean();
            echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own output, passed on as it was.
            $before  = isset($args[0]) ? $args[0] : null;
            $printed = '' !== trim($output);
            if ('form' === $type) {
                if ($printed) {
                    self::$form_seen[$plugin] = true;
                }
            } elseif ('columns' === $type && is_array($value)) {
                $added = array_diff(array_map('strval', array_keys($value)), is_array($before) ? array_map('strval', array_keys($before)) : array());
                if ($added) {
                    self::table_saw($plugin, array_values($added));
                } elseif ($printed) {
                    self::table_saw($plugin, true);
                }
            } elseif ($printed || ('filter' === $type && $value !== $before)) {
                self::table_saw($plugin, true);
            }
            return $value;
        };
    }

    /**
     * Note that a plugin added to the list.
     *
     * @param string        $plugin Plugin file.
     * @param true|string[] $what   True, or the column keys it added.
     */
    private static function table_saw($plugin, $what) {
        $seen = isset(self::$table_seen[$plugin]) ? self::$table_seen[$plugin] : array();
        if (true === $seen || true === $what) {
            self::$table_seen[$plugin] = true;
            return;
        }
        self::$table_seen[$plugin] = array_values(array_unique(array_merge($seen, $what)));
    }

    /**
     * Plugins that added to the list on this request. A plugin that only
     * added columns counts when one of them shows (not removed later, by
     * Readable list columns or another plugin).
     *
     * @return string[]
     */
    private static function table_plugins() {
        $shown  = null;
        $result = array();
        foreach (self::$table_seen as $plugin => $what) {
            if (true !== $what) {
                if (null === $shown) {
                    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                    $shown  = $screen && function_exists('get_column_headers') ? array_map('strval', array_keys((array) get_column_headers($screen))) : array();
                }
                if (!array_intersect($what, $shown)) {
                    continue;
                }
            }
            $result[] = $plugin;
        }
        return $result;
    }

    /**
     * Plugins Appearance > Editor needs besides block owners (added when
     * the screen loads): those on SITE_EDITOR_HOOKS, and with a block theme
     * the owners of post types and taxonomies people view, since it offers
     * templates for them. With a classic theme it shows only styles and
     * patterns.
     *
     * @param array $map Learned map.
     * @return string[]
     */
    private static function site_editor_plugins(array $map) {
        $plugins = self::form_plugins('site-editor', '');
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            foreach (get_post_types(array('public' => true)) as $type) {
                if (!empty($map['types'][$type]) && is_string($map['types'][$type])) {
                    $plugins[] = $map['types'][$type];
                }
            }
            foreach (get_taxonomies(array('public' => true)) as $tax) {
                if (!empty($map['taxes'][$tax]) && is_string($map['taxes'][$tax])) {
                    $plugins[] = $map['taxes'][$tax];
                }
            }
        }
        return array_values(array_unique($plugins));
    }

    /**
     * Importers have no registration hook: learn their registered callbacks
     * after import.php has loaded them, along with screen-specific hooks.
     *
     * @return string[]
     */
    private static function import_plugins() {
        global $wp_importers;
        $plugins = self::form_plugins('import', '');
        foreach ((array) $wp_importers as $importer) {
            if (is_array($importer) && isset($importer[2])) {
                $plugin = SEOProStack_Plugin_Loader::plugin_for_callback($importer[2]);
                if ('' !== $plugin) {
                    $plugins[] = $plugin;
                }
            }
        }
        return array_values(array_unique($plugins));
    }

    /**
     * Plugins Appearance > Menus needs: those that add fields, columns or
     * boxes there, change menus or their items, or save item fields (saving
     * loads every plugin, so fields it saves must be in the form), and the
     * owners of post types and taxonomies that can be added to menus or are
     * already in one (without them, those items show as "Invalid").
     * Plugins that add menu locations are caught when the screen is shown:
     * see SEOProStack_Plugin_Loader::check_menu_locations().
     *
     * @param array $map Learned map.
     * @return string[]
     */
    private static function menu_plugins(array $map) {
        global $wpdb, $wp_meta_boxes;
        $plugins = self::form_plugins('menus', '');

        if (!empty($wp_meta_boxes['nav-menus']) && is_array($wp_meta_boxes['nav-menus'])) {
            foreach ($wp_meta_boxes['nav-menus'] as $priorities) {
                foreach ((array) $priorities as $boxes) {
                    foreach ((array) $boxes as $box) {
                        if (is_array($box) && !empty($box['callback'])) {
                            $plugins[] = SEOProStack_Plugin_Loader::plugin_for_callback($box['callback']);
                        }
                    }
                }
            }
        }

        $types = get_post_types(array('show_in_nav_menus' => true));
        $taxes = get_taxonomies(array('show_in_nav_menus' => true));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- what menus hold now; read once while learning.
        $used = (array) $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_object'");
        foreach (array_merge(array_values($types), array_values($taxes), $used) as $object) {
            foreach (array('types', 'taxes') as $list) {
                if (!empty($map[$list][$object]) && is_string($map[$list][$object])) {
                    $plugins[] = $map[$list][$object];
                }
            }
        }

        $self = plugin_basename(SEOPROSTACK_FILE);
        return array_values(array_unique(array_filter($plugins, function ($plugin) use ($self) {
            return is_string($plugin) && '' !== $plugin && $self !== $plugin;
        })));
    }

    /**
     * Plugins whose boxes show on the Dashboard: the widgets left once every
     * plugin and Tidy the dashboard have added and removed theirs, as seen
     * by the administrator learning the screen (who sees the most). Boxes
     * someone hid in Screen Options still count: they can show them again.
     *
     * @return string[]
     */
    private static function dashboard_plugins() {
        global $wp_meta_boxes;
        $self    = plugin_basename(SEOPROSTACK_FILE);
        $plugins = array();
        if (empty($wp_meta_boxes['dashboard']) || !is_array($wp_meta_boxes['dashboard'])) {
            return array();
        }
        foreach ($wp_meta_boxes['dashboard'] as $priorities) {
            foreach ((array) $priorities as $boxes) {
                foreach ((array) $boxes as $box) {
                    // Removed boxes are left as false.
                    if (!is_array($box) || empty($box['callback'])) {
                        continue;
                    }
                    $plugin = SEOProStack_Plugin_Loader::plugin_for_callback($box['callback']);
                    if ('' !== $plugin && $self !== $plugin) {
                        $plugins[$plugin] = true;
                    }
                }
            }
        }
        return array_keys($plugins);
    }

    /**
     * Plugins that always load, and plugins that change permissions.
     *
     * @return array{0: string[], 1: string[]}
     */
    private static function sensitive_plugins() {
        return array(
            self::plugins_on_hooks(function ($name) {
                return in_array($name, SEOProStack_Plugin_Loader::ALWAYS_HOOKS, true)
                    || in_array($name, self::FRONT_ALWAYS_HOOKS, true)
                    || in_array($name, self::FRONT_LOGIN_HOOKS, true);
            }),
            self::plugins_on_hooks(function ($name) {
                return in_array($name, SEOProStack_Plugin_Loader::PERMISSION_HOOKS, true);
            }),
        );
    }

    /**
     * Plugins whose post types or taxonomies SEO Pro Stack's settings can
     * offer as choices: post types that are public or have screens, and
     * public taxonomies with screens. Internal ones are left out.
     *
     * @param array $map Learned map.
     * @return string[]
     */
    private static function choice_plugins(array $map) {
        $plugins = array();
        foreach ((array) $map['types'] as $name => $plugin) {
            $object = get_post_type_object((string) $name);
            if ('' !== $plugin && $object && ($object->public || $object->show_ui)) {
                $plugins[$plugin] = true;
            }
        }
        foreach ((array) $map['taxes'] as $name => $plugin) {
            $object = get_taxonomy((string) $name);
            if ('' !== $plugin && $object && $object->public && $object->show_ui) {
                $plugins[$plugin] = true;
            }
        }
        return array_keys($plugins);
    }

    /**
     * Plugins that register sidebar widgets, including widgets SEO Pro
     * Stack hides (Widget control lists them as choices).
     *
     * @return string[]
     */
    private static function widget_plugins() {
        $classes = array();
        if (isset($GLOBALS['wp_widget_factory']->widgets) && is_array($GLOBALS['wp_widget_factory']->widgets)) {
            $classes = array_keys($GLOBALS['wp_widget_factory']->widgets);
        }
        $hidden  = SEOProStack_Settings::get('disabled_sidebar_widgets');
        $classes = array_merge($classes, is_array($hidden) ? $hidden : array());

        $plugins = array();
        foreach (array_unique(array_map('strval', $classes)) as $class) {
            if (!class_exists($class, false)) {
                continue;
            }
            $plugin = SEOProStack_Plugin_Loader::plugin_for_file((string) (new ReflectionClass($class))->getFileName());
            if ('' !== $plugin) {
                $plugins[$plugin] = true;
            }
        }
        return array_keys($plugins);
    }

    /**
     * Which active plugins each active plugin needs: its `Requires Plugins`
     * header, WooCommerce and Elementor add-on headers, and add-ons named
     * after WooCommerce, Elementor or Contact Form 7.
     *
     * `WC requires at least` counts only when the plugin's name says
     * WooCommerce (as WordPress.org asks of add-ons): general plugins that
     * work with WooCommerce when it is there (TranslatePress, Cloudflare
     * Turnstile, WP Sheet Editor) declare it too, and counting theirs loaded
     * WooCommerce wherever they loaded, which was nearly every screen.
     *
     * @param string[] $active Active plugin files.
     * @return array<string,string[]>
     */
    private static function dependencies(array $active) {
        $by_slug = array();
        foreach ($active as $file) {
            $by_slug[false === strpos($file, '/') ? basename($file, '.php') : strtok($file, '/')] = $file;
        }
        $families = array(
            'woocommerce'    => '/(^|-)(woocommerce|woo|wc)(-|$)/',
            'elementor'      => '/(^|-)elementor(-|$)/',
            'contact-form-7' => '/(^|-)(contact-form-7|cf7|wpcf7)(-|$)/',
        );

        $deps = array();
        foreach ($by_slug as $slug => $file) {
            $headers = get_file_data(WP_PLUGIN_DIR . '/' . $file, array(
                'requires'  => 'Requires Plugins',
                'name'      => 'Plugin Name',
                'wc'        => 'WC requires at least',
                'elementor' => 'Elementor tested up to',
                'pro'       => 'Elementor Pro tested up to',
            ));
            $needs = array_map('trim', explode(',', (string) $headers['requires']));
            if ('' !== $headers['wc'] && preg_match('/\b(woocommerce|woo|wc)\b/i', (string) $headers['name'])) {
                $needs[] = 'woocommerce';
            }
            if ('' !== $headers['elementor'] || '' !== $headers['pro']) {
                $needs[] = 'elementor';
            }
            foreach ($families as $parent => $pattern) {
                if ($slug !== $parent && preg_match($pattern, $slug)) {
                    $needs[] = $parent;
                }
            }
            $files = array();
            foreach (array_unique(array_filter($needs)) as $need) {
                if ($need !== $slug && isset($by_slug[$need])) {
                    $files[] = $by_slug[$need];
                }
            }
            if ($files) {
                $deps[$file] = $files;
            }
        }
        return $deps;
    }

    /* --------------------------------------------------------------------- */
    /* Screens with fewer plugins                                            */
    /* --------------------------------------------------------------------- */

    /**
     * Put back the skipped plugins' menu entries, in their usual places.
     * They are plain links: opening one loads the plugins that page needs.
     */
    public static function restore_menu() {
        global $menu, $submenu, $_registered_pages, $admin_page_hooks, $_parent_pages;

        $copy = get_option(SEOProStack_Plugin_Loader::MENU);
        if (!is_array($copy) || !isset($copy['menu'], $copy['submenu'], $copy['hooks'], $copy['handled'], $copy['admin_page_hooks'], $copy['parents'])) {
            return;
        }
        $menu    = is_array($menu) ? $menu : array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring entries.
        $submenu = is_array($submenu) ? $submenu : array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.

        // Links back to the screen the copy was made on now point here.
        $here = function ($item) {
            if (is_array($item) && isset($item[2])) {
                $item[2] = self::here_slug($item[2]);
            }
            return $item;
        };
        $copy['menu'] = array_map($here, (array) $copy['menu']);
        foreach ($copy['submenu'] as $parent => $items) {
            $copy['submenu'][$parent] = array_map($here, (array) $items);
        }

        // Each capability is checked once: a menu of a few hundred entries
        // shares a few dozen, and every check runs other plugins' filters.
        $answers = array();
        $can     = function ($cap) use (&$answers) {
            if (!isset($answers[$cap])) {
                $answers[$cap] = current_user_can($cap);
            }
            return $answers[$cap];
        };

        // A skipped plugin cannot grant its own capabilities here. Show its
        // entries to administrators when an administrator had them on a
        // screen with every plugin; the page itself checks access, with
        // that plugin loaded.
        $granted = isset($copy['caps']) && $can('manage_options') ? array_flip((array) $copy['caps']) : array();
        $grant   = function ($item) use ($granted, $can) {
            if (isset($item[1]) && is_string($item[1]) && isset($granted[$item[1]]) && !$can($item[1])) {
                $item[1] = 'manage_options';
            }
            return $item;
        };
        // The copy is an administrator's menu. Core checks access when an
        // entry is added, so only put back entries this person may open.
        $allowed = function ($item) use ($grant, $can) {
            $item = $grant($item);
            return isset($item[1]) && is_string($item[1]) && $can($item[1]);
        };

        $present = array();
        foreach ($menu as $item) {
            if (isset($item[2])) {
                $present[$item[2]] = true;
            }
        }
        foreach ($copy['menu'] as $position => $item) {
            if (!isset($item[2]) || isset($present[$item[2]]) || (isset($item[4]) && false !== strpos((string) $item[4], 'wp-menu-separator'))) {
                continue;
            }
            // Core keeps a top-level entry the person cannot open while it
            // has submenu entries they can, and removes it otherwise.
            if (!$allowed($item) && (empty($copy['submenu'][$item[2]]) || !array_filter((array) $copy['submenu'][$item[2]], $allowed))) {
                continue;
            }
            $key = $position;
            for ($n = 1; isset($menu[$key]); $n++) {
                $key = (string) ((float) $position + $n / 10000);
            }
            // The skipped plugin's CSS for a logo in the title is missing here.
            $menu[$key] = SEOProStack_Admin_Menu::image_title($grant($item)); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.
        }

        foreach ($copy['submenu'] as $parent => $items) {
            $items   = array_filter((array) $items, $allowed);
            $current = isset($submenu[$parent]) ? (array) $submenu[$parent] : array();
            $by_slug = array();
            foreach ($current as $item) {
                if (isset($item[2])) {
                    $by_slug[$item[2]] = $item;
                }
            }
            $missing = false;
            foreach ($items as $item) {
                if (isset($item[2]) && !isset($by_slug[$item[2]])) {
                    $missing = true;
                    break;
                }
            }
            if (!$missing) {
                continue;
            }
            // Rebuild in the usual order; entries added only on this screen go last.
            $rebuilt = array();
            foreach ($items as $position => $item) {
                if (isset($item[2])) {
                    $rebuilt[$position] = isset($by_slug[$item[2]]) ? $by_slug[$item[2]] : $grant($item);
                    unset($by_slug[$item[2]]);
                }
            }
            $next = $rebuilt ? max(array_map('intval', array_keys($rebuilt))) + 1 : 0;
            foreach ($by_slug as $item) {
                $rebuilt[$next++] = $item;
            }
            ksort($rebuilt);
            $submenu[$parent] = $rebuilt; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.
        }

        foreach ($copy['hooks'] as $hook) {
            if (!isset($_registered_pages[$hook])) {
                $_registered_pages[$hook] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.
            }
        }
        // Pages that had a handler link to admin.php?page=... (core checks
        // has_action() on the page hook); plain URL entries keep their URL.
        // The stand-in handler never runs: those pages are not this screen.
        foreach ($copy['handled'] as $hook) {
            if (!has_action($hook)) {
                add_action($hook, '__return_null');
            }
        }
        $admin_page_hooks = (array) $admin_page_hooks + $copy['admin_page_hooks']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.
        $_parent_pages    = (array) $_parent_pages + $copy['parents']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.
    }

    /**
     * Plugins loaded on this screen, plugins active, and the address that
     * reloads it with every plugin.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private static function bar_counts() {
        $state  = SEOProStack_Plugin_Loader::state();
        $total  = count($state['active']);
        $loaded = $total - count($state['skipped']);
        $uri    = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        return array($loaded, $total, add_query_arg(SEOProStack_Plugin_Loader::LOAD_ALL_ARG, '1', $uri));
    }

    /**
     * The links that reload the screen with every plugin: one learns again
     * what this screen needs, the other forgets what every screen needs.
     * Both ask first (admin_bar_script()), which explains what happens.
     *
     * @param WP_Admin_Bar $bar    Admin bar.
     * @param string       $parent Parent node or group.
     * @param string       $url    Address with every plugin.
     */
    private static function reload_item($bar, $parent, $url) {
        $bar->add_node(array(
            'id'     => self::NODE . '-all',
            'parent' => $parent,
            'title'  => self::on_site()
                ? esc_html__('Reload with every plugin and check the site again', 'seoprostack')
                : esc_html__('Reload with every plugin and check this screen again', 'seoprostack'),
            'href'   => $url,
        ));
        // Admin screens are learned one by one; the site is learned as a
        // whole, so the reload above already checks it all again.
        if (!self::on_site()) {
            self::reset_item($bar, $parent, $url);
        }
    }

    /**
     * Whether this request is a page of the site, not an admin screen.
     *
     * @return bool
     */
    private static function on_site() {
        $state = SEOProStack_Plugin_Loader::state();
        return 'front' === $state['screen'];
    }

    /**
     * The link that forgets what every screen needs and reloads this one.
     *
     * @param WP_Admin_Bar $bar    Admin bar.
     * @param string       $parent Parent node or group.
     * @param string       $url    This screen's address.
     */
    private static function reset_item($bar, $parent, $url) {
        // This screen without one-off args (removable_query_args() adds the load-all one).
        $here = remove_query_arg(wp_removable_query_args(), $url);
        $bar->add_node(array(
            'id'     => self::NODE . '-reset',
            'parent' => $parent,
            'title'  => esc_html__('Reload with every plugin and check every screen again', 'seoprostack'),
            'href'   => wp_nonce_url(
                add_query_arg(array(
                    'action'         => self::RESET,
                    self::RETURN_ARG => rawurlencode($here),
                ), admin_url('admin-post.php')),
                self::RESET
            ),
        ));
    }

    /**
     * Put the count and the reload at the top of the Plugins menu in the
     * admin bar. Runs before that menu adds its list, so they come first;
     * if the menu is not added, core ignores them and admin_bar() adds the
     * stand-alone count instead.
     *
     * @param WP_Admin_Bar $bar Admin bar.
     */
    public static function admin_bar_in_menu($bar) {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        list($loaded, $total, $url) = self::bar_counts();
        $group = self::NODE . '-menu';
        $bar->add_group(array(
            'id'     => $group,
            'parent' => SEOProStack_Plugin_Toggle::NODE,
        ));
        $bar->add_node(array(
            'id'     => self::NODE . '-count',
            'parent' => $group,
            'title'  => esc_html(sprintf(
                self::on_site()
                    /* translators: 1: plugins loaded, 2: active plugins */
                    ? _n('%1$d of %2$d plugin loaded on this page', '%1$d of %2$d plugins loaded on this page', $total, 'seoprostack')
                    /* translators: 1: plugins loaded, 2: active plugins */
                    : _n('%1$d of %2$d plugin loaded on this screen', '%1$d of %2$d plugins loaded on this screen', $total, 'seoprostack'),
                $loaded,
                $total
            )),
        ));
        self::reload_item($bar, $group, $url);
    }

    /**
     * On a screen that loads every plugin, say why at the top of the
     * Plugins menu, so it is clear the setting is working. Without that
     * menu nothing is added: these screens look as they would without the
     * setting.
     *
     * @param WP_Admin_Bar $bar Admin bar.
     */
    public static function admin_bar_full($bar) {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        $state = SEOProStack_Plugin_Loader::state();
        $total = count($state['active']);
        switch ($state['reason']) {
            case 'learning':
                /* translators: %d: active plugins */
                $title = _n('%d plugin loaded while SEO Pro Stack checks this screen', 'All %d plugins loaded while SEO Pro Stack checks this screen', $total, 'seoprostack');
                break;
            case 'error':
                /* translators: %d: active plugins */
                $title = _n('%d plugin loads here: a plugin failed when fewer were loaded', 'All %d plugins load here: a plugin failed when fewer were loaded', $total, 'seoprostack');
                break;
            case 'needed':
                /* translators: %d: active plugins */
                $title = _n('%d plugin loaded: this screen needs it', 'All %d plugins loaded: this screen needs them all', $total, 'seoprostack');
                break;
            default:
                /* translators: %d: active plugins */
                $title = _n('This screen always loads its %d plugin', 'This screen always loads all %d plugins', $total, 'seoprostack');
        }
        $group = self::NODE . '-menu';
        $bar->add_group(array(
            'id'     => $group,
            'parent' => SEOProStack_Plugin_Toggle::NODE,
        ));
        $bar->add_node(array(
            'id'     => self::NODE . '-count',
            'parent' => $group,
            'title'  => esc_html(sprintf($title, $total)),
        ));
        if ('error' === $state['reason']) {
            // Checking every screen again also clears this screen's mark.
            $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            self::reset_item($bar, $group, $uri);
        }
    }

    /**
     * Without the Plugins menu, "N of M plugins" on its own in the admin bar,
     * with the reload under it. With the menu, the count is already at the
     * top of it (admin_bar_in_menu()).
     *
     * @param WP_Admin_Bar $bar Admin bar.
     */
    public static function admin_bar($bar) {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        if ($bar->get_node(SEOProStack_Plugin_Toggle::NODE)) {
            return;
        }
        list($loaded, $total, $url) = self::bar_counts();

        $bar->remove_node(self::NODE . '-menu');
        $bar->remove_node(self::NODE . '-count');
        $bar->add_node(array(
            'id'     => self::NODE,
            'parent' => 'top-secondary',
            /* translators: 1: plugins loaded, 2: active plugins */
            'title'  => esc_html(sprintf(__('%1$d of %2$d plugins', 'seoprostack'), $loaded, $total)),
            'href'   => $url,
            'meta'   => array('title' => self::on_site()
                ? __('The plugins you chose to skip on the site are not loaded. Choose to reload with every plugin.', 'seoprostack')
                : __('Only the plugins this screen needs are loaded. Choose to reload with every plugin.', 'seoprostack')),
        ));
        self::reload_item($bar, self::NODE, $url);
    }

    /**
     * In the Plugins menu, a line between the count and the plugin list,
     * and the count in quieter text.
     */
    public static function admin_bar_style() {
        $menu = '#wpadminbar #wp-admin-bar-' . self::NODE . '-menu';
        wp_add_inline_style(
            'admin-bar',
            "{$menu}+.ab-submenu{border-top:1px solid rgba(240,246,252,.2)}"
            . "#wpadminbar #wp-admin-bar-" . self::NODE . "-count>.ab-item{opacity:.7}"
        );
    }

    /**
     * Ask before reloading with every plugin, and say what will happen.
     * Covers both reload links and the stand-alone "N of M plugins" count,
     * which reloads this screen too.
     */
    public static function admin_bar_script() {
        $i18n = array(
            'screen' => self::on_site()
                ? __('Reload this page with every plugin?', 'seoprostack') . "\n\n"
                    . __('Every active plugin loads on this page once, so it may take a little longer. SEO Pro Stack then checks again what each plugin adds to the site. If something is missing from the page, untick its plugin under "Plugins to skip on the site".', 'seoprostack')
                : __('Reload this screen with every plugin?', 'seoprostack') . "\n\n"
                    . __('Every active plugin loads on this screen once, so it may take a little longer. SEO Pro Stack then checks again which plugins this screen needs. Use this when a box, field, block or menu item is missing here. Other screens do not change.', 'seoprostack'),
            'reset'  => __('Reload with every plugin and check every screen again?', 'seoprostack') . "\n\n"
                . __('SEO Pro Stack forgets which plugins each admin screen needs. This screen reloads with every plugin now. Every other screen also loads every plugin until an administrator next opens it and it is checked again, so the first visit to each screen is slower.', 'seoprostack') . "\n\n"
                . __('Screens set to load every plugin after an error are checked again too. Your settings and always-load choices do not change.', 'seoprostack'),
        );
        $js = '(function(n,t){document.addEventListener("click",function(e){'
            . 'if(e.defaultPrevented||!e.target.closest){return;}'
            . 'var a=e.target.closest("#wp-admin-bar-"+n+">a,#wp-admin-bar-"+n+"-all>a,#wp-admin-bar-"+n+"-reset>a");if(!a){return;}'
            . 'if(!window.confirm(a.parentNode.id==="wp-admin-bar-"+n+"-reset"?t.reset:t.screen)){e.preventDefault();}'
            . '});})(' . wp_json_encode(self::NODE) . ',' . wp_json_encode($i18n) . ');';
        wp_add_inline_script('admin-bar', $js);
    }

    /**
     * Let core drop the one-off argument from the address bar.
     *
     * @param string[] $args Query args core removes.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = SEOProStack_Plugin_Loader::LOAD_ALL_ARG;
        return $args;
    }
}
