<?php
/**
 * Switch plugins on and off from the admin bar.
 *
 * Adds a plugin icon to the right of the admin bar (in wp-admin and on the
 * site) that opens a one-column list of every plugin; active ones are bold.
 * Plugins that "Load plugins only where needed" skips on the current
 * screen still show as active, since they are. Choosing one asks for
 * confirmation, naming the plugin, then runs core's own activate or
 * deactivate action and returns to the page you were on. If that page
 * belonged to the plugin just switched off, you land on the Plugins screen
 * instead of an error. Replaces "Plugin Toggle", which has no settings.
 *
 * Plugin names are cached in one site transient, cleared whenever a plugin
 * is activated, deactivated, deleted or updated and when the Plugins screen
 * is opened, so ordinary page loads never read plugin files.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Plugin_Toggle extends SEOProStack_Feature {

    const KEY = 'plugin_toggle';

    /** Site transient holding plugin file => name. */
    const CACHE = 'seoprostack_plugin_names';

    /** Query arg: where to return after the switch. */
    const RETURN_ARG = 'seoprostack_return';

    /** Query arg: nonce that allows leaving a page the plugin owned. */
    const REVIVE_ARG = 'seoprostack_revive';

    /** Admin bar node ID. */
    const NODE = 'seoprostack-plugins';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'plugins',
                'label'       => __('Plugins menu in the admin bar', 'seoprostack'),
                'description' => __('Switch any plugin on or off from the admin bar, then return to the page you were on. You are asked to confirm first. Only shown to people who can activate plugins.', 'seoprostack'),
                'replaces'    => array('plugin-toggle' => 'Plugin Toggle'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        // Keep the name cache fresh even while the menu is off.
        foreach (array('activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'upgrader_process_complete', 'load-plugins.php') as $hook) {
            add_action($hook, array(__CLASS__, 'flush_cache'));
        }

        if (!self::enabled() || !current_user_can('activate_plugins')) {
            return;
        }
        if (is_multisite() && is_network_admin()) {
            return; // Network activation stays on the Network Plugins screen.
        }

        add_action('admin_bar_menu', array(__CLASS__, 'menu'), 100);
        // Next to the account menu, with the notices bell and then other plugins' items to its left.
        SEOProStack_Admin_Bar::pin(self::NODE, 0);
        add_action('admin_bar_init', array(__CLASS__, 'assets'));
        add_filter('wp_redirect', array(__CLASS__, 'return_to_page'), 1);
        add_action('admin_page_access_denied', array(__CLASS__, 'leave_removed_page'));
        add_filter('removable_query_args', array(__CLASS__, 'removable_query_args'));
    }

    /**
     * Let core drop the one-off argument from the address bar.
     *
     * @param string[] $args Query args core removes.
     * @return string[]
     */
    public static function removable_query_args($args) {
        $args[] = self::REVIVE_ARG;
        return $args;
    }

    /**
     * Forget cached plugin names.
     */
    public static function flush_cache() {
        delete_site_transient(self::CACHE);
    }

    /**
     * Installed plugins, file => name, sorted by name.
     *
     * @return array<string,string>
     */
    public static function plugin_names() {
        $names = get_site_transient(self::CACHE);
        if (is_array($names)) {
            return $names;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $names = array();
        foreach (get_plugins() as $file => $data) {
            $names[$file] = '' !== trim((string) $data['Name']) ? wp_strip_all_tags($data['Name']) : $file;
        }
        natcasesort($names);

        set_site_transient(self::CACHE, $names, WEEK_IN_SECONDS);
        return $names;
    }

    /**
     * Add the menu.
     *
     * @param WP_Admin_Bar $bar Admin bar.
     */
    public static function menu($bar) {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php'; // Not loaded on the front end.
        }
        $return  = self::current_url();
        $skipped = self::skipped_here();
        $items   = array();
        foreach (self::plugin_names() as $file => $name) {
            if (is_multisite() && (is_plugin_active_for_network($file) || is_network_only_plugin($file))) {
                continue;
            }
            $active = isset($skipped[$file]) || is_plugin_active($file);
            $cap    = $active ? 'deactivate_plugin' : 'activate_plugin';
            if (!current_user_can($cap, $file)) {
                continue;
            }
            $items[$file] = array('name' => $name, 'active' => $active, 'skipped' => isset($skipped[$file]));
        }
        if (!$items) {
            return;
        }

        $active_count = count(array_filter(wp_list_pluck($items, 'active')));
        /* translators: 1: active plugins, 2: all plugins */
        $summary = sprintf(__('Plugins: %1$d of %2$d active', 'seoprostack'), $active_count, count($items));
        // No tooltip, like core's menus: it would cover the open list. Screen
        // readers read the summary from the hidden text.
        $bar->add_node(array(
            'id'     => self::NODE,
            'parent' => 'top-secondary',
            'title'  => '<span class="ab-icon" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($summary) . '</span>',
            'href'   => self_admin_url('plugins.php'),
        ));
        $bar->add_group(array(
            'id'     => self::NODE . '-list',
            'parent' => self::NODE,
        ));

        foreach ($items as $file => $item) {
            $action = $item['active'] ? 'deactivate' : 'activate';
            $url    = wp_nonce_url(
                add_query_arg(array(
                    'action'         => $action,
                    'plugin'         => rawurlencode($file),
                    self::RETURN_ARG => rawurlencode($return),
                ), self_admin_url('plugins.php')),
                $action . '-plugin_' . $file
            );
            $meta = array('class' => $item['active'] ? 'is-active' : 'is-inactive');
            if ($item['skipped']) {
                $meta['class'] .= ' is-skipped';
                $meta['title']  = __('Active. Not loaded on this screen, to make it faster.', 'seoprostack');
            }
            $bar->add_node(array(
                'id'     => self::NODE . '-' . substr(md5($file), 0, 12),
                'parent' => self::NODE . '-list',
                'title'  => esc_html($item['name']),
                'href'   => $url,
                'meta'   => $meta,
            ));
        }
    }

    /**
     * Active plugins that "Load plugins only where needed" left out of this
     * request. It hides them from the active list, so is_plugin_active()
     * says no; they are still active.
     *
     * @return array<string,bool> Plugin file => true.
     */
    private static function skipped_here() {
        if (!class_exists('SEOProStack_Plugin_Loader', false)) {
            return array();
        }
        $state = SEOProStack_Plugin_Loader::state();
        return 'filter' === $state['mode'] ? array_fill_keys($state['skipped'], true) : array();
    }

    /**
     * Path of the page being viewed, without one-off query args.
     *
     * A path (not a full address) keeps the return on the same host;
     * wp_validate_redirect() checks it again before use.
     *
     * @return string
     */
    private static function current_url() {
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        return remove_query_arg(array('_wpnonce', 'activate', 'deactivate', 'error', self::REVIVE_ARG), $uri);
    }

    /**
     * Styles and the confirmation script, added to the admin bar's own assets.
     */
    public static function assets() {
        $node = '#wpadminbar #wp-admin-bar-' . self::NODE;
        // Icon only; one column that wraps long names and scrolls when it is
        // taller than the window.
        $css = "{$node}>.ab-item .ab-icon{margin-right:0}"
            . "{$node}>.ab-item .ab-icon:before{content:\"\\f106\";top:2px}"
            . "{$node} .ab-sub-wrapper{width:max-content;max-width:min(24rem,calc(100vw - 16px));max-height:calc(100vh - var(--wp-admin--admin-bar--height,32px));overflow-y:auto;overscroll-behavior:contain}"
            . "{$node} .ab-submenu .ab-item{height:auto;min-width:0;padding-block:3px;line-height:1.5;white-space:normal}"
            . "{$node} .ab-submenu .is-inactive .ab-item{opacity:.7}"
            . "{$node} .ab-submenu .is-active .ab-item{font-weight:600}"
            . "{$node} .ab-submenu .ab-item:hover,{$node} .ab-submenu .ab-item:focus{opacity:1}";
        wp_add_inline_style('admin-bar', $css);

        $i18n = array(
            /* translators: %s: plugin name */
            'deactivate' => __('Deactivate %s?', 'seoprostack'),
            /* translators: %s: plugin name */
            'activate'   => __('Activate %s?', 'seoprostack'),
        );
        $js = '(function(n,t){document.addEventListener("click",function(e){'
            . 'var a=e.target.closest&&e.target.closest("#wp-admin-bar-"+n+"-list a");if(!a){return;}'
            . 'var on=a.parentNode.classList.contains("is-active");'
            . 'if(!window.confirm((on?t.deactivate:t.activate).replace("%s",a.textContent.trim()))){e.preventDefault();}'
            . '});})(' . wp_json_encode(self::NODE) . ',' . wp_json_encode($i18n) . ');';
        wp_add_inline_script('admin-bar', $js);
    }

    /**
     * After core switches the plugin, go back to the page it was switched from.
     *
     * @param string $location Redirect target chosen by core.
     * @return string
     */
    public static function return_to_page($location) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- core has verified the activate/deactivate nonce before redirecting.
        if (empty($_REQUEST[self::RETURN_ARG]) || empty($_REQUEST['action']) || false === strpos($location, 'plugins.php') || false !== strpos($location, 'error=true')) {
            return $location;
        }
        $action = sanitize_key(wp_unslash($_REQUEST['action']));
        if (!in_array($action, array('activate', 'deactivate'), true)) {
            return $location;
        }
        $return = is_string($_REQUEST[self::RETURN_ARG]) ? wp_sanitize_redirect(wp_unslash($_REQUEST[self::RETURN_ARG])) : '';
        // phpcs:enable
        $return = '' !== $return ? wp_validate_redirect($return, '') : '';
        if ('' === $return || 0 !== strpos($return, '/') || 0 === strpos($return, '//')) {
            return $location;
        }
        $admin_path = (string) wp_parse_url(admin_url(), PHP_URL_PATH);
        if ('deactivate' === $action && '' !== $admin_path && 0 === strpos($return, $admin_path)) {
            // The admin page may have belonged to the plugin; see leave_removed_page().
            $return = add_query_arg(self::REVIVE_ARG, wp_create_nonce(self::REVIVE_ARG), $return);
        }
        return $return;
    }

    /**
     * Send people to the Plugins screen instead of "not allowed" when the
     * page they returned to was removed with the plugin.
     */
    public static function leave_removed_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the value is the nonce.
        $nonce = isset($_GET[self::REVIVE_ARG]) ? sanitize_key(wp_unslash($_GET[self::REVIVE_ARG])) : '';
        if ('' !== $nonce && wp_verify_nonce($nonce, self::REVIVE_ARG)) {
            wp_safe_redirect(self_admin_url('plugins.php?deactivate=true'));
            exit;
        }
    }
}
