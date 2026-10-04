<?php
/**
 * No fade between admin screens.
 *
 * WordPress 7.0 fades from one wp-admin screen to the next with the
 * browser's cross-document view transitions (wp-admin/css/view-transitions.css,
 * enqueued by wp_enqueue_view_transitions_admin_css()). The fade can flash
 * and makes every screen change wait for it. This stops it, so screens
 * change straight away, as before 7.0.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Admin_Page_Fade extends SEOProStack_Feature {

    const KEY = 'admin_no_fade';

    /** Core's style handle (WordPress 7.0+). */
    const HANDLE = 'wp-view-transitions-admin';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request: the fade can flash.
                'default'     => true,
                'tab'         => 'admin',
                'label'       => __('No fade between admin screens', 'seoprostack'),
                'description' => __('Change screens in wp-admin straight away, without the fade added in WordPress 7.0 or a white flash where the toolbar goes.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        // Core's own opt-in. boot() runs on init, before admin_enqueue_scripts.
        remove_action('admin_enqueue_scripts', 'wp_enqueue_view_transitions_admin_css');
        // In case something else enqueues core's style.
        add_action('admin_enqueue_scripts', array(__CLASS__, 'dequeue'), PHP_INT_MAX);
        // Plugins that add their own fade: the last @view-transition rule wins.
        add_action('admin_head', array(__CLASS__, 'print_style'), PHP_INT_MAX);
    }

    /**
     * Dequeue core's view transitions style.
     */
    public static function dequeue() {
        wp_dequeue_style(self::HANDLE);
    }

    /**
     * Opt this screen out of fades between pages. A fade needs both the
     * screen being left and the next one to opt in, so one rule is enough.
     *
     * Also paints the toolbar's strip before the toolbar arrives. Core prints
     * the toolbar after the admin menu, so on a long menu the browser's first
     * paint shows the menu with the light page background where the toolbar
     * goes, then the toolbar: a white flash at the top on every screen change.
     * The strip takes the menu's background, which every colour scheme also
     * gives the toolbar, and the toolbar covers it once it is drawn.
     */
    public static function print_style() {
        echo "<style id=\"seoprostack-no-fade\">@view-transition{navigation:none}"
            . 'html.wp-toolbar #adminmenuback::after{content:"";position:fixed;top:0;left:0;right:0;height:32px;background-color:inherit;pointer-events:none}'
            . '@media screen and (max-width:782px){html.wp-toolbar #adminmenuback::after{height:46px}}'
            . "</style>\n";
    }
}
