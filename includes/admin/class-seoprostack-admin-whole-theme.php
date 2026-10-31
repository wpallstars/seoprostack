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
 * (SEO Pro Analytics, formerly SEO Pro Stats): one user meta,
 * `wpallstars_admin_theme`, which each reads
 * first, then its own (`seoprostack_admin_theme`), then Light. A change
 * made here or in another plugin's control is sent on `document` as the
 * `wpallstars-admin-theme` event, so every control on the screen follows.
 * Plugins join with the `wpallstars_admin_theme_participants` filter.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Admin_Whole_Theme {

    /** This plugin's own copy of the choice, so it works alone. */
    const META = 'seoprostack_admin_theme';

    /** The choice shared by every wpallstars plugin with a dark mode. */
    const SHARED_META = 'wpallstars_admin_theme';

    /** Plugins taking part in the shared colour mode (slug => true). */
    const PARTICIPANTS = 'wpallstars_admin_theme_participants';

    const ACTION = 'seoprostack_admin_whole_theme';
    const MODES  = array('light', 'dark', 'system');

    /** The admin bar item. */
    const NODE = 'seoprostack-colour-mode';

    /** The stylesheet's handle (and `<link>` ID, with `-css`). */
    const STYLE = 'seoprostack-whole-theme';

    /** The stylesheet, relative to the plugin's folder. */
    const STYLESHEET = 'admin/css/seoprostack-whole-theme.css';

    /** FluentCRM's preview of mail apps' dark mode: the script's handle, and the script. */
    const MAIL_DARK    = 'seoprostack-mail-dark';
    const MAIL_DARK_JS = 'admin/js/seoprostack-mail-dark.js';

    /**
     * Icons (24×24): sun, moon and a half-filled circle, as SEO Pro Analytics'.
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
        add_action('admin_enqueue_scripts', array(__CLASS__, 'keep_style'), PHP_INT_MAX);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'fluent_mail_dark'), 20);
        // Before other plugins' own colour mode scripts (Fluent's run at 1).
        add_action('admin_head', array(__CLASS__, 'head'), 0);
        // The Customizer prints its own page: no admin_head, and admin_enqueue_scripts only with its widgets.
        add_action('customize_controls_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('customize_controls_print_styles', array(__CLASS__, 'head'), 0);
        add_filter('style_loader_tag', array(__CLASS__, 'standalone_tag'), 10, 2);
        add_filter('admin_body_class', array(__CLASS__, 'body_class'));
        add_action('in_admin_header', array(__CLASS__, 'fluent_dark_early'), 0);
        // After FluentCRM's own stylesheet in its email designer's <head> (10).
        add_action('fluent_crm/block_editor_head', array(__CLASS__, 'fluent_editor_head'), 20);
        add_action('admin_bar_init', array(__CLASS__, 'bar_init'));
        add_action('wp_ajax_' . self::ACTION, array(__CLASS__, 'save'));
        // The admin bar's links, when the script has not loaded.
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'save_and_return'));
        // Fluent plugins' "no conflict" mode removes other plugins' scripts from their screens.
        add_filter('fluentform/exclude_js_slugs_from_dequeue', array(__CLASS__, 'fluent_keep_path'));
        foreach (array('fluent_crm_asset_listed_slugs', 'fluent_boards/asset_listed_slugs', 'fluent_booking/asset_listed_slugs') as $hook) {
            add_filter($hook, array(__CLASS__, 'fluent_keep_pattern'));
        }
        add_filter('vg_sheet_editor/js_data', array(__CLASS__, 'sheet_editor_mode'));
    }

    /**
     * WP Sheet Editor's spreadsheet has its own dark mode, one setting for
     * the whole site (Auto, which follows the computer, Light or Dark). For
     * someone who has chosen a mode here, the spreadsheet opens in that mode
     * instead (System as Auto), so it matches the rest of wp-admin; its
     * setting is not changed. The script keeps the two in step on the
     * screen (admin/js/seoprostack-theme.js).
     *
     * @param mixed $settings The spreadsheet's settings for its script.
     * @return mixed
     */
    public static function sheet_editor_mode($settings) {
        if (!is_array($settings) || !self::chosen() || !self::screen_applies()) {
            return $settings;
        }
        $mode                   = self::mode();
        $settings['color_mode'] = 'system' === $mode ? '' : $mode;
        return $settings;
    }

    /**
     * Keep SEO Pro Stack's scripts on Fluent Forms' screens, which its "no
     * conflict" mode would remove: the colour mode's menu, the contrast
     * guard and the other admin-wide tools (faster navigation, notices).
     * Fluent Forms matches part of each script's address.
     *
     * @param mixed $slugs Parts of addresses to keep.
     * @return array<int,string>
     */
    public static function fluent_keep_path($slugs) {
        $slugs   = is_array($slugs) ? $slugs : array();
        $slugs[] = '/' . basename(SEOPROSTACK_DIR) . '/';
        return $slugs;
    }

    /**
     * The same for FluentCRM, Fluent Boards and Fluent Booking, which match
     * addresses with regular expression parts. Scripts only: Fluent Booking
     * applies the list to styles too, and its screens keep their own colour
     * mode, without SEO Pro Stack's stylesheet.
     *
     * @param mixed $slugs Regular expression parts of addresses to keep.
     * @return array<int,string>
     */
    public static function fluent_keep_pattern($slugs) {
        $slugs = is_array($slugs) ? $slugs : array();
        if (doing_action('wp_print_styles')) {
            return $slugs;
        }
        $slugs[] = '\/' . preg_quote(basename(SEOPROSTACK_DIR), '/') . '\/';
        return $slugs;
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
     * Whether the current person has saved a mode, in either plugin, rather
     * than having Light as the default.
     *
     * @return bool
     */
    private static function chosen() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }
        foreach (array(self::SHARED_META, self::META) as $key) {
            if (in_array(get_user_meta($user_id, $key, true), self::MODES, true)) {
                return true;
            }
        }
        return false;
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
     * screen does but SEO Pro Analytics' own, unless a filter keeps one light.
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
     * Whether the current person's Dark (or System) styles load on this
     * screen: for the fallback (SEOProStack_Dark_Fallback).
     *
     * @return bool
     */
    public static function darkens() {
        return 'light' !== self::mode() && self::screen_applies();
    }

    /**
     * Whether Dark checks the screen's text for contrast and fixes text that
     * does not read (the contrast guard in admin/js/seoprostack-theme.js).
     *
     * @return bool
     */
    private static function guards() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        /**
         * Filter whether Dark mode's contrast guard runs on an admin screen:
         * it gives text that reads below WCAG AA (4.5:1) against what is
         * behind it the nearest colour that does.
         *
         * @param bool           $guards Whether it runs. Default true.
         * @param WP_Screen|null $screen The screen.
         */
        return (bool) apply_filters('seoprostack_admin_dark_contrast_guard', true, $screen);
    }

    /**
     * Whether Dark recolours the styles a screen's own script sets (CSS in
     * JS, under hashed class names the stylesheet cannot name; see
     * admin/js/seoprostack-theme-scripted.js): Tutor LMS's screens (Emotion),
     * and its course builder (page=create-course), which the script draws
     * whole; Meow Apps' screens (styled-components).
     *
     * @return bool
     */
    private static function script_styles() {
        global $plugin_page;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $tutor  = defined('TUTOR_VERSION') && is_string($plugin_page) && (0 === strpos($plugin_page, 'tutor') || 'create-course' === $plugin_page);
        $meow   = is_string($plugin_page) && self::meow_screen($plugin_page);
        /**
         * Filter whether Dark mode recolours the styles an admin screen's
         * own script sets (Emotion's and styled-components'): light
         * grounds, dark grey text and light lines take the dark palette's
         * colours.
         *
         * @param bool           $recolours Whether it does. Default true on Tutor LMS's and Meow Apps' screens.
         * @param WP_Screen|null $screen    The screen.
         */
        return (bool) apply_filters('seoprostack_admin_dark_script_styles', $tutor || $meow, $screen);
    }

    /**
     * Whether a plugin page is one of Meow Apps' (its dashboard, AI Engine,
     * Media File Renamer and the others in its menu). Their panels, as the
     * dashboard's cards and AI Engine's, are white by styled-components
     * under hashed class names.
     *
     * @param string $page The plugin page (`page` query argument).
     * @return bool
     */
    private static function meow_screen($page) {
        global $submenu;
        if ('meowapps-main-menu' === $page) {
            return true;
        }
        if (!isset($submenu['meowapps-main-menu']) || !is_array($submenu['meowapps-main-menu'])) {
            return false;
        }
        foreach ($submenu['meowapps-main-menu'] as $item) {
            if (isset($item[2]) && $page === $item[2]) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether this is one of SEO Pro Analytics' own screens (Statistics,
     * Settings), including its former name, SEO Pro Stats. It styles those
     * with its own colour mode, which
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
        if (class_exists('SEOProAnalytics_Dashboard', false) && defined('SEOProAnalytics_Dashboard::HOOK')) {
            $hooks[] = (string) constant('SEOProAnalytics_Dashboard::HOOK');
        }
        if (class_exists('SEOProAnalytics_Admin_Manager', false) && is_callable(array('SEOProAnalytics_Admin_Manager', 'hook'))) {
            $hooks[] = (string) call_user_func(array('SEOProAnalytics_Admin_Manager', 'hook'));
        }
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
     * scheme's colour at a contrast that reads (the stylesheet). With a
     * space after it too: Tutor LMS adds its class with none before, which
     * made "sps-own-screentutor-lms".
     *
     * @param string $classes Space-separated classes.
     * @return string
     */
    public static function body_class($classes) {
        return self::own_screen() ? $classes . ' sps-own-screen ' : $classes;
    }

    /**
     * FluentCRM's and Fluent Boards' screens draw their top bar with the
     * page, but mark the body dark (their own classes) only when their
     * script runs, at the end: the bar showed white while the screen loaded.
     * Marked here, at the top of the page, when the screen is dark (the
     * <head> script's class, so System follows the computer too). Their
     * script keeps the mode it reads, which is the same (head_script()).
     */
    public static function fluent_dark_early() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || false === strpos((string) $screen->id, 'fluent') || 'light' === self::mode() || !self::screen_applies()) {
            return;
        }
        $script = '(function(r,b){if(!b||!r.classList.contains("sps-dark")){return;}'
            . 'b.classList.add("fluent_theme_dark","fluent_boards_theme_dark","fbs-dark");b.classList.remove("fbs-light");'
            . 'r.classList.add("fluent_boards_theme_dark","fbs-dark");})(document.documentElement,document.body);';
        echo wp_get_inline_script_tag($script, array('id' => 'seoprostack-fluent-dark')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag() output of a fixed script.
    }

    /**
     * FluentCRM's email designer (campaigns, templates, sequences and
     * automations' emails) is a page of its own, shown in a frame on its
     * screens (post-new.php?fluent_crm_block_editor=1). It is printed at
     * admin_init and stops there: no admin_head, and no other plugin's
     * enqueued scripts or styles. So its <head> gets the mode's classes
     * here, and in Dark or System the stylesheet, after FluentCRM's own.
     * The screen around it keeps the frame in step when the mode changes
     * (admin/js/seoprostack-whole-theme.js). The email itself is in a frame
     * of its own inside, and keeps the colours it is sent with; in every
     * mode, a button in the header previews it as mail apps show it in
     * their own dark mode (fluent_mail_dark()).
     */
    public static function fluent_editor_head() {
        if (!is_user_logged_in()) {
            return;
        }
        $data = 'window.seoprostackMailDark = ' . wp_json_encode(self::mail_dark_data()) . ';';
        $src  = add_query_arg('ver', (string) filemtime(SEOPROSTACK_DIR . self::MAIL_DARK_JS), SEOPROSTACK_URL . self::MAIL_DARK_JS);
        echo wp_get_inline_script_tag($data, array('id' => self::MAIL_DARK . '-js-before')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag() output of JSON-encoded strings.
        echo wp_get_script_tag(array('src' => $src, 'id' => self::MAIL_DARK . '-js', 'defer' => true)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_script_tag() escapes its attributes.
        if (!self::screen_applies()) {
            return;
        }
        echo self::head_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag() output; the mode is one of MODES, JSON-encoded.
        if ('light' === self::mode()) {
            return;
        }
        $css = self::STYLESHEET;
        $url = add_query_arg('ver', (string) filemtime(SEOPROSTACK_DIR . $css), SEOPROSTACK_URL . $css);
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- the designer's page prints no enqueued styles of other plugins; as standalone_tag().
        echo '<link rel="stylesheet" id="' . esc_attr(self::STYLE) . '-css" href="' . esc_url($url) . '" media="all" />' . "\n";
    }

    /**
     * Mail apps (Apple Mail, Outlook, Gmail) show an email in their own
     * dark mode by darkening light backgrounds and lightening dark text,
     * when it sets no dark colours of its own, as FluentCRM's emails do
     * not. A button in the email designer's header (fluent_editor_head())
     * and in its Email Preview, on FluentCRM's screens (here), shows the
     * email that way, in any colour mode; the email that is saved and sent
     * is unchanged (admin/js/seoprostack-mail-dark.js).
     */
    public static function fluent_mail_dark() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || false === strpos((string) $screen->id, 'fluentcrm')) {
            return;
        }
        $js = self::MAIL_DARK_JS;
        wp_enqueue_script(self::MAIL_DARK, SEOPROSTACK_URL . $js, array(), (string) filemtime(SEOPROSTACK_DIR . $js), true);
        wp_add_inline_script(self::MAIL_DARK, 'window.seoprostackMailDark = ' . wp_json_encode(self::mail_dark_data()) . ';', 'before');
    }

    /**
     * The preview menu's and the email's dark mode setting's words, and the
     * dark colours (SEOProStack_FluentCRM_Email_Dark). Not "Preview" in the
     * button's name: FluentCRM hides the header's other buttons with it.
     *
     * @return array<string,mixed>
     */
    private static function mail_dark_data() {
        $labels     = array(
            'b' => __('Body background', 'seoprostack'),
            'c' => __('Content background', 'seoprostack'),
            'h' => __('Headings', 'seoprostack'),
            't' => __('Text', 'seoprostack'),
            'a' => __('Links', 'seoprostack'),
        );
        $properties = array(
            'b' => '--fcrm-body-bg',
            'c' => '--fcrm-content-bg',
            'h' => '--fcrm-headings-color',
            't' => '--theme-text-color',
            'a' => '--fcrm-link-color',
        );
        $colours    = array();
        foreach (SEOProStack_FluentCRM_Email_Dark::COLOURS as $letter => $colour) {
            $colours[] = array(
                'key'      => $colour[0],
                'global'   => $colour[1],
                'property' => $properties[$letter],
                'fallback' => $colour[2],
                'label'    => $labels[$letter],
            );
        }
        return array(
            'colours' => $colours,
            'text'    => array(
                'label'    => __('Mail apps’ dark mode', 'seoprostack'),
                'hint'     => __('Shows the email as mail apps show it in their own dark mode. A preview only: the email is unchanged.', 'seoprostack'),
                'modes'    => self::mail_dark_options(
                    'hint',
                    array(
                        ''       => array(__('Off', 'seoprostack'), __('The email in light mode.', 'seoprostack')),
                        'apple'  => array(__('Apple Mail', 'seoprostack'), __('And other apps that follow the email’s dark mode setting, such as Outlook for Mac.', 'seoprostack')),
                        'forced' => array(__('Gmail and Outlook', 'seoprostack'), __('Gmail, Outlook for Windows and the Outlook apps darken the email themselves, whatever it sets.', 'seoprostack')),
                    )
                ),
                'panel'    => __('Dark mode in mail apps', 'seoprostack'),
                'setting'  => __('In mail apps’ dark mode', 'seoprostack'),
                'colours'  => __('Dark colours', 'seoprostack'),
                'settings' => self::mail_dark_options(
                    'help',
                    array(
                        'auto'   => array(__('Let the mail app choose', 'seoprostack'), __('Each mail app darkens the email in its own way.', 'seoprostack')),
                        'light'  => array(__('Keep light', 'seoprostack'), __('Apple Mail and apps like it keep the email light. Gmail and Outlook for Windows still darken it.', 'seoprostack')),
                        'custom' => array(__('Use my dark colours', 'seoprostack'), __('Apple Mail and apps like it use these colours in place of the global colours. Blocks with colours of their own keep them. Gmail and Outlook for Windows still darken the email themselves.', 'seoprostack')),
                    )
                ),
            ),
        );
    }

    /**
     * A list of options for the preview menu or the setting.
     *
     * @param string                               $note The key for each option's note (hint or help).
     * @param array<string,array{0:string,1:string}> $rows Value => array(label, note).
     * @return array<int,array<string,string>>
     */
    private static function mail_dark_options($note, $rows) {
        $out = array();
        foreach ($rows as $value => $row) {
            $out[] = array(
                'value' => (string) $value,
                'label' => $row[0],
                $note   => $row[1],
            );
        }
        return $out;
    }

    /**
     * The stylesheet and scripts. The stylesheet loads at once only when it
     * is needed (Dark, System, or SEO Pro Stack's own screen); otherwise the
     * script loads it on the first switch to dark. On screens styled by
     * their own script, the script that recolours those styles loads in the
     * <head> in every mode, before the screen draws, so Dark needs no
     * redraw and nothing shows light first.
     */
    public static function enqueue() {
        // Once a screen: the Customizer runs admin_enqueue_scripts too, for its widgets.
        if (wp_script_is(self::STYLE, 'enqueued')) {
            return;
        }
        $toggle  = self::shows_toggle();
        $applies = self::screen_applies();
        $mode    = self::mode();
        if (!$toggle && (!$applies || 'light' === $mode)) {
            return;
        }
        $css = self::STYLESHEET;
        $js  = 'admin/js/seoprostack-whole-theme.js';
        $url = SEOPROSTACK_URL . $css . '?ver=' . filemtime(SEOPROSTACK_DIR . $css);
        if ($applies && ('light' !== $mode || self::own_screen())) {
            wp_enqueue_style(self::STYLE, SEOPROSTACK_URL . $css, array(), (string) filemtime(SEOPROSTACK_DIR . $css));
        }
        if ($applies && self::script_styles()) {
            $scripted = 'admin/js/seoprostack-theme-scripted.js';
            wp_enqueue_script(self::STYLE . '-scripted', SEOPROSTACK_URL . $scripted, array(), (string) filemtime(SEOPROSTACK_DIR . $scripted), false);
        }
        wp_enqueue_script(self::STYLE, SEOPROSTACK_URL . $js, array('wp-i18n', 'wp-a11y'), (string) filemtime(SEOPROSTACK_DIR . $js), true);
        wp_set_script_translations(self::STYLE, 'seoprostack');
        wp_add_inline_script(self::STYLE, 'window.seoprostackWholeTheme = ' . wp_json_encode(array(
            'mode'    => $mode,
            'user'    => get_current_user_id(),
            'applies' => $applies,
            'css'     => $applies ? $url : '',
            'guard'   => $applies && self::guards(),
            'node'    => $toggle ? 'wp-admin-bar-' . self::NODE : '',
            'icons'   => self::ICONS,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce(self::ACTION),
        )) . ';', 'before');
    }

    /**
     * Put the stylesheet back where another plugin has taken it off the
     * page. Revive.so's screens keep only stylesheets named for it (at
     * admin_enqueue_scripts, 100), which left Dark with no styles there.
     * The stylesheet is registered only when enqueue() wants it, so Light
     * stays as it was.
     */
    public static function keep_style() {
        if (wp_style_is(self::STYLE, 'registered') && !wp_style_is(self::STYLE, 'enqueued')) {
            wp_enqueue_style(self::STYLE);
        }
    }

    /**
     * Set the mode's classes on <html> before anything is drawn.
     *
     * Once someone has chosen a mode (Light included, so a choice made in
     * another browser reaches this one), it also passes it to the Fluent
     * plugins' own colour mode (FluentCRM, Fluent Booking, FluentSMTP, Fluent
     * Support...), LiteSpeed Cache's and Bit Social's, kept in the browser,
     * before their own scripts read it, so their screens open in the same
     * mode, without a flash. Light as the default, never chosen, leaves their
     * own choice.
     */
    public static function head() {
        if (!is_user_logged_in()) {
            return;
        }
        echo self::head_script(self::screen_applies()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag() output; the mode is one of MODES, JSON-encoded.
    }

    /**
     * The <script> tag that sets the mode's classes (head()).
     *
     * sps-mode-dark says the person's wp-admin is dark, on every screen,
     * including those that keep their own look (screen_applies()): what
     * shows while the next screen loads (SEOProStack_Preload_Pages) takes
     * the dark canvas from it, as the next screen will be dark. Leaving SEO
     * Pro Stats' screens showed it light (#725). The mode's own classes and
     * the other plugins' modes only where the screen turns dark.
     *
     * @param bool $applies Whether this screen turns dark (screen_applies()).
     * @return string
     */
    private static function head_script($applies = true) {
        $mode   = self::mode();
        $chosen = 'light' !== $mode || self::chosen();
        $script = '(function(r,m,c,a){var q=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)"),d=m==="dark"||(m==="system"&&!!q&&q.matches);'
            . 'if(d){r.classList.add("sps-mode-dark");}'
            . 'if(!a){return;}'
            . 'r.classList.add("sps-theme-"+m);'
            . 'if(d){r.classList.add("sps-dark");}'
            . 'if(c){try{var s=localStorage;s.setItem("fluent_theme_mode",m);s.setItem("fs-theme",m==="system"?"system:"+(d?"dark":"light"):m);'
            . 'if(m==="system"){s.removeItem("litespeed-dark-preference");}else{s.setItem("litespeed-dark-preference",m);}'
            . 'var b=JSON.parse(s.getItem("bit-social-config")||"{}")||{};b.isDarkTheme=d;s.setItem("bit-social-config",JSON.stringify(b));}catch(e){}}'
            . '})(document.documentElement,' . wp_json_encode($mode) . ',' . ($chosen ? 'true' : 'false') . ',' . ($applies ? 'true' : 'false') . ');';
        return wp_get_inline_script_tag($script, array('id' => 'seoprostack-whole-theme-mode'));
    }

    /**
     * Screens that draw their own page, without wp-admin's header (Rank
     * Math's setup wizard, on admin_init): with their stylesheet, the
     * mode's classes and the dark stylesheet too. In Light, nothing.
     *
     * @param string $tag    The stylesheet's <link> tag.
     * @param string $handle Its handle.
     * @return string
     */
    public static function standalone_tag($tag, $handle) {
        /**
         * Stylesheet handles of admin screens that print their own page
         * (no admin_head), so Dark reaches them with their styles.
         *
         * @param string[] $handles Handles. Default Rank Math's setup wizard.
         */
        $handles = (array) apply_filters('seoprostack_admin_dark_standalone_styles', array('rank-math-wizard'));
        if (!is_admin() || did_action('admin_head') || !in_array($handle, $handles, true) || 'light' === self::mode() || !self::screen_applies()) {
            return $tag;
        }
        $css = self::STYLESHEET;
        $url = add_query_arg('ver', (string) filemtime(SEOPROSTACK_DIR . $css), SEOPROSTACK_URL . $css);
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- the screen prints only its own handles; this follows its stylesheet.
        return $tag . self::head_script() . '<link rel="stylesheet" id="' . esc_attr(self::STYLE) . '-css" href="' . esc_url($url) . '" media="all" />' . "\n";
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
