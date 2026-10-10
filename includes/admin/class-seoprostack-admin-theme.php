<?php
/**
 * Per-person colour mode for SEO Pro Stack's settings screen.
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

    const META = 'seoprostack_admin_theme';
    const ACTION = 'seoprostack_admin_theme';
    const MODES = array('light', 'dark', 'system');

    /** Register only on admin requests. */
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('admin_head', array(__CLASS__, 'head'), 1);
        add_action('wp_ajax_' . self::ACTION, array(__CLASS__, 'save'));
    }

    /**
     * Read the current person's preference, defaulting to WordPress light.
     *
     * @return string
     */
    public static function mode() {
        $mode = get_user_meta(get_current_user_id(), self::META, true);
        return in_array($mode, self::MODES, true) ? $mode : 'light';
    }

    /**
     * Screens that own this theme, not the editor or other feature screens.
     *
     * @return string[]
     */
    private static function screens() {
        return array(SEOProStack_Admin_Manager::hook());
    }

    /**
     * Enqueue after the screen's own styles.
     *
     * @param string $hook Current screen hook.
     */
    public static function enqueue($hook) {
        if (!in_array($hook, self::screens(), true)) {
            return;
        }
        $css = 'admin/css/seoprostack-theme.css';
        $js  = 'admin/js/seoprostack-theme.js';
        wp_enqueue_style('seoprostack-theme', SEOPROSTACK_URL . $css, array('seoprostack-tabs'), (string) filemtime(SEOPROSTACK_DIR . $css));
        wp_enqueue_script('seoprostack-theme', SEOPROSTACK_URL . $js, array('wp-i18n', 'wp-a11y'), (string) filemtime(SEOPROSTACK_DIR . $js), true);
        wp_set_script_translations('seoprostack-theme', 'seoprostack');
        wp_add_inline_script('seoprostack-theme', 'window.seoprostackTheme = ' . wp_json_encode(array(
            'mode'    => self::mode(),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce(self::ACTION),
        )) . ';', 'before');
    }

    /** Set classes before first paint, only on our screen. */
    public static function head() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, self::screens(), true)) {
            return;
        }
        $script = '(function(r,m){var q=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)");'
            . 'r.classList.add("sps-theme-"+m);'
            . 'if(m==="dark"||(m==="system"&&q&&q.matches)){r.classList.add("sps-dark");}'
            . '})(document.documentElement,' . wp_json_encode(self::mode()) . ');';
        wp_print_inline_script_tag($script, array('id' => 'seoprostack-theme-mode'));
    }

    /** Save only the authenticated person's allow-listed preference. */
    public static function save() {
        check_ajax_referer(self::ACTION, 'nonce');
        if (!current_user_can('read')) {
            wp_send_json_error(array('message' => __('You cannot change this.', 'seoprostack')), 403);
        }
        $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        if (!in_array($mode, self::MODES, true)) {
            wp_send_json_error(array('message' => __('Unknown colour mode.', 'seoprostack')), 400);
        }
        update_user_meta(get_current_user_id(), self::META, $mode);
        if (self::mode() !== $mode) {
            wp_send_json_error(array('message' => __('The colour mode could not be saved.', 'seoprostack')), 500);
        }
        wp_send_json_success(array('mode' => $mode));
    }
}
