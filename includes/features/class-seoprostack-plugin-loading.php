<?php
/**
 * Load plugins in wp-admin only where they are needed.
 *
 * On a site with 200 plugins every admin screen runs all 200, which can
 * take seconds; with 2 it takes about as long as WordPress alone. This
 * feature lets the administrator tick plugins that should load only on
 * their own screens. SEOProStack_Plugin_Loader does the filtering from a
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

    /** Setting: plugins that load only where needed. */
    const LIST_KEY = SEOProStack_Plugin_Loader::LIST_KEY;

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
        'logout_url', 'register_url', 'lostpassword_url',
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
                'description' => __('Makes wp-admin faster on sites with many plugins. The plugins you tick load only on their own screens, and on post, term and list screens where they add boxes, fields or blocks. Elsewhere, such as the Dashboard, they do not load, so their boxes and notices do not show there (with Hide admin notices on, their notices still show behind the bell). The menu stays the same. Saving, background tasks, and the Plugins and settings screens always load every plugin. So does the site, unless you choose plugins to skip there.', 'seoprostack'),
            ),
            self::LIST_KEY => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Plugins to load only where needed', 'seoprostack'),
                'description' => __('Leave security, login and user role plugins unticked. Plugins that need a ticked plugin, such as WooCommerce extensions, follow it.', 'seoprostack'),
                'options'     => array(__CLASS__, 'plugin_options'),
            ),
            self::FRONT_KEY => array(
                'type'        => 'multi',
                'default'     => array(),
                'parent'      => self::KEY,
                'label'       => __('Plugins to skip on the site', 'seoprostack'),
                'description' => __('For admin tools that add nothing to the pages people see. They then do not load when someone views the site, so pages that are not cached open faster. Next to each plugin is what SEO Pro Stack saw it add to the site: leave those unticked, and leave security, caching, cookie and analytics plugins unticked too. Logins, sending forms, background tasks and addresses with extra arguments still load every plugin, and so does a ticked plugin that another plugin needs.', 'seoprostack'),
                'options'     => array(__CLASS__, 'front_plugin_options'),
            ),
            self::FRONT_USERS_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Also skip them for people who are logged in', 'seoprostack'),
                'description' => __('When off, people who are logged in, such as editors, get every plugin on the site, including what plugins add to the admin bar there.', 'seoprostack'),
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
        add_action('seoprostack_setting_panel', array(__CLASS__, 'panel_status'), 10, 2);
        if (is_admin()) {
            add_action('admin_init', array(__CLASS__, 'maybe_sync'));
        } elseif (self::enabled()) {
            self::boot_front();
        }

        if (!self::enabled() || !is_admin()) {
            return;
        }
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
            if (current_user_can('manage_options')) {
                add_action('admin_menu', array(__CLASS__, 'capture_menu'), PHP_INT_MAX);
                add_action('adminmenu', array(__CLASS__, 'prune_menu'));
                add_action('admin_footer', array(__CLASS__, 'learn'), PHP_INT_MAX);
            }
        }
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
        } elseif (self::FRONT_KEY === $key || self::FRONT_USERS_KEY === $key) {
            // A page of the site failed with fewer plugins: saving the list
            // tries again.
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
                    $parts[] = __('nothing seen on the site', 'seoprostack');
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
        if (!empty($front['failed']) && is_array($front['failed']) && SEOProStack_Plugin_Loader::front_current()) {
            $failed = $front['failed'];
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
            '{sps-here:u}' => urlencode($uri),
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
        if ('full' !== $state['mode']) {
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
                $map['blocks'] = array_values(array_unique(array_merge((array) $map['blocks'], array_keys($state['registered']['blocks']))));
            }
        } elseif ($state['attributing']) {
            list($always, $permissions) = self::sensitive_plugins();
            $map = array(
                'version'     => SEOProStack_Plugin_Loader::MAP_VERSION,
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
                'pages'       => array(),
                'screens'     => array(),
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
            list($kind, $name)       = array_pad(explode(':', $screen, 2), 2, '');
            $map['screens'][$screen] = self::form_plugins($kind, $name);
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
        if ('front' !== $state['screen'] || 'full' !== $state['mode'] || 'learning' !== $state['reason']) {
            return;
        }
        // Learning again on request is for administrators.
        if ($state['relearn'] && !current_user_can('activate_plugins')) {
            return;
        }
        $print = SEOProStack_Plugin_Loader::fingerprint($state['active']);
        if (SEOProStack_Plugin_Loader::fingerprint(SEOProStack_Plugin_Loader::stored_active_plugins()) !== $print) {
            return; // Plugins changed during this request; the next one learns.
        }
        $deps   = self::dependencies($state['active']);
        $notes  = self::front_notes($state, $deps);
        $always = array();
        foreach ($notes as $file => $list) {
            if (array_intersect($list, array('always', 'core'))) {
                $always[] = $file;
            }
        }
        update_option(SEOProStack_Plugin_Loader::FRONT, array(
            'version' => SEOProStack_Plugin_Loader::FRONT_VERSION,
            'active'  => $print,
            'deps'    => $deps,
            'always'  => $always,
            'notes'   => $notes,
            'learned' => time(),
            'failed'  => array(),
        ), true);
        if (!$state['relearn']) {
            delete_option(SEOProStack_Plugin_Loader::FRONT_LOCK);
        }
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
     * Plugins that add boxes, fields or editor features to a screen
     * ("post", "terms", "list", "user", "tools", "media-new"; none for
     * other screens).
     *
     * @param string $kind Screen kind.
     * @param string $name Post type or taxonomy.
     * @return string[]
     */
    private static function form_plugins($kind, $name) {
        if (!in_array($kind, array('post', 'terms', 'list', 'user', 'tools', 'media-new'), true)) {
            return array();
        }
        return self::plugins_on_hooks(function ($hook) use ($kind, $name) {
            return SEOProStack_Plugin_Loader::screen_needs_hook($kind, $name, $hook);
        });
    }

    /**
     * Plugins that always load, and plugins that change permissions.
     *
     * @return array{0: string[], 1: string[]}
     */
    private static function sensitive_plugins() {
        return array(
            self::plugins_on_hooks(function ($name) {
                return in_array($name, SEOProStack_Plugin_Loader::ALWAYS_HOOKS, true);
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
                'wc'        => 'WC requires at least',
                'elementor' => 'Elementor tested up to',
                'pro'       => 'Elementor Pro tested up to',
            ));
            $needs = array_map('trim', explode(',', (string) $headers['requires']));
            if ('' !== $headers['wc']) {
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

        // A skipped plugin cannot grant its own capabilities here. Show its
        // entries to administrators when an administrator had them on a
        // screen with every plugin; the page itself checks access, with
        // that plugin loaded.
        $granted = isset($copy['caps']) && current_user_can('manage_options') ? array_flip((array) $copy['caps']) : array();
        $grant   = function ($item) use ($granted) {
            if (isset($item[1]) && is_string($item[1]) && isset($granted[$item[1]]) && !current_user_can($item[1])) {
                $item[1] = 'manage_options';
            }
            return $item;
        };
        // The copy is an administrator's menu. Core checks access when an
        // entry is added, so only put back entries this person may open.
        $allowed = function ($item) use ($grant) {
            $item = $grant($item);
            return isset($item[1]) && is_string($item[1]) && current_user_can($item[1]);
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
                . __('Screens set to load every plugin after an error are checked again too. Your settings and the plugins you ticked do not change.', 'seoprostack'),
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
