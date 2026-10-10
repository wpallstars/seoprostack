<?php
/**
 * Per-person colour mode for the whole of wp-admin: Light, Dark or System,
 * chosen from an icon in the admin bar, just left of the account menu.
 *
 * Light is WordPress's own look. Dark gives every admin screen dark
 * surfaces (admin/css/seoprostack-theme.css) while the admin colour scheme
 * (Modern, Default, Sunrise...) keeps its menus, admin bar and accent.
 * System follows the computer's setting, live.
 *
 * The choice is shared with every wpallstars plugin that has a dark mode
 * (SEO Pro Stats): one user meta, `wpallstars_admin_theme`, which each reads
 * first, then its own (`seoprostack_admin_theme`), then Light. A change
 * made here or in another plugin's control is sent on `document` as the
 * `wpallstars-admin-theme` event, so every control on the screen follows.
 * Plugins join with the `wpallstars_admin_theme_participants` filter.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Admin_Theme {

    /** This plugin's own copy of the choice, so it works alone. */
    const META = 'seoprostack_admin_theme';

    /** The choice shared by every wpallstars plugin with a dark mode. */
    const SHARED_META = 'wpallstars_admin_theme';

    /** Plugins taking part in the shared colour mode (slug => true). */
    const PARTICIPANTS = 'wpallstars_admin_theme_participants';

    const ACTION = 'seoprostack_admin_theme';
    const MODES  = array('light', 'dark', 'system');

    /** The admin bar item. */
    const NODE = 'seoprostack-colour-mode';

    /** The stylesheet's handle (and `<link>` ID, with `-css`). */
    const STYLE = 'seoprostack-theme';

    /**
     * Icons (24×24): sun, moon and a half-filled circle, as SEO Pro Stats'.
     */
    const ICONS = array(
        'light'  => 'M12 7.75a4.25 4.25 0 1 0 0 8.5 4.25 4.25 0 0 0 0-8.5zM11.25 2h1.5v3h-1.5zm0 17h1.5v3h-1.5zM2 11.25h3v1.5H2zm17 0h3v1.5h-3zM4.4 5.46 5.46 4.4l2.12 2.12-1.06 1.06zm12.02 12.02 1.06-1.06 2.12 2.12-1.06 1.06zM4.4 18.54l2.12-2.12 1.06 1.06-2.12 2.12zM16.42 6.52l2.12-2.12 1.06 1.06-2.12 2.12z',
        'dark'   => 'M20.5 14.6A8.5 8.5 0 0 1 9.4 3.5a8.5 8.5 0 1 0 11.1 11.1z',
        'system' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm0 1.5v15a7.5 7.5 0 0 1 0-15z',
    );

    /** A tick for the current choice in the menu. */
    const CHECK = 'M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z';

    /** Register only on admin requests. */
    public static function init() {
        add_filter(self::PARTICIPANTS, array(__CLASS__, 'participate'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('admin_head', array(__CLASS__, 'head'), 1);
        add_filter('admin_body_class', array(__CLASS__, 'body_class'));
        add_action('admin_bar_init', array(__CLASS__, 'bar_init'));
        add_action('wp_ajax_' . self::ACTION, array(__CLASS__, 'save'));
        // The admin bar's links, when the script has not loaded.
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'save_and_return'));
    }

    /**
     * Read a person's choice: the shared one first, then this plugin's own,
     * then WordPress's light look.
     *
     * @param int $user_id User; the current one by default.
     * @return string
     */
    public static function mode($user_id = 0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return 'light';
        }
        foreach (array(self::SHARED_META, self::META) as $key) {
            $mode = get_user_meta($user_id, $key, true);
            if (in_array($mode, self::MODES, true)) {
                return $mode;
            }
        }
        return 'light';
    }

    /**
     * Take part for everyone who can open wp-admin: dark mode covers every
     * admin screen.
     *
     * @param mixed $participants Slug => true.
     * @return array<string,bool>
     */
    public static function participate($participants) {
        $participants = is_array($participants) ? $participants : array();
        if (current_user_can('read')) {
            $participants['seoprostack'] = true;
        }
        return $participants;
    }

    /**
     * Whether the admin bar shows the colour mode: in wp-admin, for people
     * who can open at least one participating plugin's dark screens.
     *
     * @return bool
     */
    private static function shows_toggle() {
        if (!is_admin() || is_network_admin() || !is_user_logged_in()) {
            return false;
        }
        /**
         * Plugins taking part in the shared colour mode, for the current
         * person: slug => true. Shared by every wpallstars plugin with a
         * dark mode.
         *
         * @param array<string,bool> $participants Slug => true.
         */
        $participants = apply_filters('wpallstars_admin_theme_participants', array());
        return is_array($participants) && array() !== array_filter($participants);
    }

    /**
     * Whether the current screen turns dark in Dark mode. Every admin
     * screen does, unless a filter keeps one light.
     *
     * @return bool
     */
    private static function screen_applies() {
        if (!is_user_logged_in()) {
            return false;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $applies = !self::stats_screen($screen);
        /**
         * Filter whether an admin screen turns dark in Dark mode, for
         * screens whose own design does not suit it. The colour mode icon
         * still shows and saves the choice there.
         *
         * @param bool           $applies Whether the screen turns dark.
         * @param WP_Screen|null $screen  The screen.
         */
        return (bool) apply_filters('seoprostack_admin_dark_screen', $applies, $screen);
    }

    /**
     * Whether this is one of SEO Pro Stats' own screens (Statistics,
     * Settings). SEO Pro Stats styles those with its own colour mode, which
     * follows the same shared choice, so SEO Pro Stack's dark rules stay off
     * them and the two never mix.
     *
     * @param WP_Screen|null $screen The screen.
     * @return bool
     */
    private static function stats_screen($screen) {
        if (!$screen) {
            return false;
        }
        $hooks = array();
        if (class_exists('SEOProStats_Dashboard', false) && defined('SEOProStats_Dashboard::HOOK')) {
            $hooks[] = (string) constant('SEOProStats_Dashboard::HOOK');
        }
        if (class_exists('SEOProStats_Admin_Manager', false) && is_callable(array('SEOProStats_Admin_Manager', 'hook'))) {
            $hooks[] = (string) call_user_func(array('SEOProStats_Admin_Manager', 'hook'));
        }
        return in_array($screen->id, $hooks, true);
    }

    /**
     * Whether this is SEO Pro Stack's own settings screen.
     *
     * @return bool
     */
    private static function own_screen() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && class_exists('SEOProStack_Admin_Manager', false) && SEOProStack_Admin_Manager::hook() === $screen->id;
    }

    /**
     * Mark SEO Pro Stack's own screen, where Light mode also keeps the
     * scheme's colour at a contrast that reads (the stylesheet).
     *
     * @param string $classes Space-separated classes.
     * @return string
     */
    public static function body_class($classes) {
        return self::own_screen() ? $classes . ' sps-own-screen' : $classes;
    }

    /**
     * The stylesheet and script. The stylesheet loads at once only when it
     * is needed (Dark, System, or SEO Pro Stack's own screen); otherwise the
     * script loads it on the first switch to dark.
     */
    public static function enqueue() {
        $toggle  = self::shows_toggle();
        $applies = self::screen_applies();
        $mode    = self::mode();
        if (!$toggle && (!$applies || 'light' === $mode)) {
            return;
        }
        $css = 'admin/css/seoprostack-theme.css';
        $js  = 'admin/js/seoprostack-theme.js';
        $url = SEOPROSTACK_URL . $css . '?ver=' . filemtime(SEOPROSTACK_DIR . $css);
        if ($applies && ('light' !== $mode || self::own_screen())) {
            wp_enqueue_style(self::STYLE, SEOPROSTACK_URL . $css, array(), (string) filemtime(SEOPROSTACK_DIR . $css));
        }
        wp_enqueue_script(self::STYLE, SEOPROSTACK_URL . $js, array('wp-i18n', 'wp-a11y'), (string) filemtime(SEOPROSTACK_DIR . $js), true);
        wp_set_script_translations(self::STYLE, 'seoprostack');
        wp_add_inline_script(self::STYLE, 'window.seoprostackTheme = ' . wp_json_encode(array(
            'mode'    => $mode,
            'applies' => $applies,
            'css'     => $applies ? $url : '',
            'node'    => $toggle ? 'wp-admin-bar-' . self::NODE : '',
            'icons'   => self::ICONS,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce(self::ACTION),
        )) . ';', 'before');
    }

    /** Set the mode's classes on <html> before anything is drawn. */
    public static function head() {
        if (!self::screen_applies()) {
            return;
        }
        $script = '(function(r,m){var q=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)");'
            . 'r.classList.add("sps-theme-"+m);'
            . 'if(m==="dark"||(m==="system"&&q&&q.matches)){r.classList.add("sps-dark");}'
            . '})(document.documentElement,' . wp_json_encode(self::mode()) . ');';
        wp_print_inline_script_tag($script, array('id' => 'seoprostack-theme-mode'));
    }

    /**
     * Add the admin bar item in wp-admin.
     */
    public static function bar_init() {
        if (!self::shows_toggle()) {
            return;
        }
        add_action('admin_bar_menu', array(__CLASS__, 'bar_node'), 100);
        // Nearest the account menu, right of SEO Pro Stack's star (-1).
        SEOProStack_Admin_Bar::pin(self::NODE, -2);
        $node = '#wpadminbar #wp-admin-bar-' . self::NODE;
        wp_add_inline_style('admin-bar', implode('', array(
            "{$node}>.ab-item{display:flex;align-items:center}",
            "{$node}>.ab-item .ab-icon{display:flex;align-items:center;width:20px;height:32px;margin:0;padding:0}",
            "{$node}>.ab-item .ab-icon:before{content:none}",
            "{$node} svg{width:20px;height:20px;fill:currentColor}",
            "{$node} .ab-submenu .ab-item{display:flex;align-items:center;gap:8px;min-width:136px}",
            "{$node} .ab-submenu svg{width:18px;height:18px;flex:none}",
            "{$node} .ab-submenu .sps-colour-mode__check{margin-inline-start:auto;visibility:hidden}",
            "{$node} .ab-submenu .sps-current .sps-colour-mode__check{visibility:visible}",
        )));
    }

    /**
     * The admin bar item and its menu. Its links work without the script
     * (save_and_return()); the script takes over the clicks.
     *
     * @param WP_Admin_Bar $bar Admin bar.
     */
    public static function bar_node($bar) {
        $mode   = self::mode();
        $labels = self::labels();
        $name   = self::name($mode);
        $bar->add_node(array(
            'id'     => self::NODE,
            'parent' => 'top-secondary',
            'title'  => '<span class="ab-icon" aria-hidden="true">' . self::svg(self::ICONS[$mode]) . '</span><span class="screen-reader-text">' . esc_html($name) . '</span>',
            // Without the script, a click turns Dark on or off.
            'href'   => self::url('dark' === $mode ? 'light' : 'dark'),
            'meta'   => array(
                'title'      => $name,
                'menu_title' => __('Colour mode', 'seoprostack'),
            ),
        ));
        foreach (self::MODES as $which) {
            $bar->add_node(array(
                'id'     => self::NODE . '-' . $which,
                'parent' => self::NODE,
                'title'  => self::svg(self::ICONS[$which]) . '<span>' . esc_html($labels[$which]) . '</span>' . self::svg(self::CHECK, 'sps-colour-mode__check'),
                'href'   => self::url($which),
                'meta'   => array('class' => $which === $mode ? 'sps-current' : ''),
            ));
        }
    }

    /**
     * Mode names.
     *
     * @return array<string,string>
     */
    private static function labels() {
        return array(
            'light'  => __('Light', 'seoprostack'),
            'dark'   => __('Dark', 'seoprostack'),
            'system' => __('System', 'seoprostack'),
        );
    }

    /**
     * The item's accessible name, such as "Colour mode: Dark".
     *
     * @param string $mode Mode.
     * @return string
     */
    private static function name($mode) {
        $labels = self::labels();
        /* translators: %s: Light, Dark or System. */
        return sprintf(__('Colour mode: %s', 'seoprostack'), $labels[$mode]);
    }

    /**
     * An inline icon.
     *
     * @param string $path  SVG path (a constant).
     * @param string $class Class.
     * @return string
     */
    private static function svg($path, $class = '') {
        return '<svg' . ('' !== $class ? ' class="' . esc_attr($class) . '"' : '') . ' viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="' . esc_attr($path) . '"/></svg>';
    }

    /**
     * Link that saves a mode without the script.
     *
     * @param string $mode Mode.
     * @return string
     */
    private static function url($mode) {
        return wp_nonce_url(add_query_arg(array('action' => self::ACTION, 'mode' => $mode), admin_url('admin-post.php')), self::ACTION);
    }

    /**
     * Save a mode for the current person, in the shared meta and this
     * plugin's own.
     *
     * @param string $mode Mode, already allow-listed.
     * @return bool Whether it is saved.
     */
    private static function store($mode) {
        $user_id = get_current_user_id();
        update_user_meta($user_id, self::SHARED_META, $mode);
        update_user_meta($user_id, self::META, $mode);
        return self::mode($user_id) === $mode;
    }

    /**
     * The requested mode, if it is one.
     *
     * @param array<string,mixed> $request $_POST or $_GET.
     * @return string Empty when unknown.
     */
    private static function requested(array $request) {
        $mode = isset($request['mode']) && is_string($request['mode']) ? sanitize_key(wp_unslash($request['mode'])) : '';
        return in_array($mode, self::MODES, true) ? $mode : '';
    }

    /** Save only the authenticated person's allow-listed preference. */
    public static function save() {
        check_ajax_referer(self::ACTION, 'nonce');
        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => __('You cannot change this.', 'seoprostack')), 403);
        }
        $mode = self::requested($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
        if ('' === $mode) {
            wp_send_json_error(array('message' => __('Unknown colour mode.', 'seoprostack')), 400);
        }
        if (!self::store($mode)) {
            wp_send_json_error(array('message' => __('The colour mode could not be saved.', 'seoprostack')), 500);
        }
        wp_send_json_success(array('mode' => $mode));
    }

    /** The admin bar's links without the script: save, then go back. */
    public static function save_and_return() {
        check_admin_referer(self::ACTION);
        if (!current_user_can('read')) {
            wp_die(esc_html__('You cannot change this.', 'seoprostack'), '', array('response' => 403));
        }
        $mode = self::requested($_GET);
        if ('' !== $mode) {
            self::store($mode);
        }
        $back = wp_get_referer();
        wp_safe_redirect($back ? $back : admin_url());
        exit;
    }
}
