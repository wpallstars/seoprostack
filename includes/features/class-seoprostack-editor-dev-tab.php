<?php
/**
 * Dev tab in the block editor.
 *
 * Adds a Dev tab after the Post and Block tabs of the post editor's
 * settings sidebar, for developers only (SEOProStack_Developers: super
 * admins on multisite; the administrators ticked under Developer admins on
 * single sites, or every administrator while that is off). Panels that are
 * for developers (PANELS, such as LiteSpeed's and Webmention's)
 * leave the Post tab and show there, in A–Z order. Everyone else sees
 * neither the tab nor those panels, nor developer tools' toolbar buttons
 * (TOOLBAR_SCRIPTS: YellowPencil's).
 *
 * The panels stay where WordPress puts them (side meta boxes stay in the
 * meta box form, so their fields still save with the post): the Dev tab is
 * the Post tab with only those panels showing. Nothing is stored.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.41
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Editor_Dev_Tab extends SEOProStack_Feature {

    const KEY = 'editor_dev_tab';

    /** Script and style handle. */
    const HANDLE = 'seoprostack-editor-dev-tab';

    /**
     * Panels moved to the Dev tab. Each is found by its meta box ID, or by
     * its title as it shows in the sidebar: the English title, and the title
     * translated with the plugin's text domain (`domain`) for panels with no
     * meta box ID.
     */
    const PANELS = array(
        // Complianz.
        array('id' => 'cmplz_hide_banner_meta_box', 'title' => 'Cookiebanner'),
        // LiteSpeed Cache.
        array('id' => 'litespeed_meta_boxes', 'title' => 'LiteSpeed'),
        // Readabler (Merkulove).
        array('id' => 'readabler', 'title' => 'Readabler'),
        // Kadence Simple Share.
        array('id' => '_kt_share_share_control', 'title' => 'Simple Share Control'),
        // Webmention: a block editor panel, not a meta box.
        array('id' => '', 'title' => 'Webmentions', 'domain' => 'webmention'),
    );

    /**
     * Rows of the Post tab's summary (PluginPostStatusInfo) moved to the
     * top of the Dev tab, in this order. Each is found by its class, or by
     * its label as it shows (English, and translated with `domain`).
     */
    const ROWS = array(
        // SEO Pro Stack: Change post type.
        array('class' => 'seoprostack-post-type', 'title' => '', 'domain' => ''),
        // Rank Math.
        array('class' => '', 'title' => 'Lock Modified Date', 'domain' => 'seo-by-rank-math'),
    );

    /**
     * Scripts that add developer tools' buttons to the block editor's
     * toolbar: handle => tool. Left out for people who are not developers.
     */
    const TOOLBAR_SCRIPTS = array(
        // YellowPencil (free and Pro): its pencil button opens its editor.
        'yellow-pencil-admin' => 'YellowPencil',
    );

    /** Toolbar buttons hidden for people who are not developers, should a script above load anyway. */
    const TOOLBAR_BUTTONS = '.wyp-btn';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'content',
                'label'       => __('Dev tab in the block editor', 'seoprostack'),
                'description' => __('Move developer panels (LiteSpeed, Complianz’s Cookiebanner, Readabler, Simple Share Control and Webmentions) from the editor’s Post tab to a Dev tab beside Block that only developers see (Developer admins), with Post type and Rank Math’s Lock Modified Date at the top. Everyone else sees none of them, nor YellowPencil’s toolbar button.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin()) {
            return;
        }
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'block_editor'));
        // After plugins enqueue theirs (YellowPencil: priority 10).
        add_action('admin_enqueue_scripts', array(__CLASS__, 'toolbar_scripts'), 100);
    }

    /**
     * Whether the current screen is the post editor (not the site or widget
     * editors).
     *
     * @return bool
     */
    private static function post_editor() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && 'post' === $screen->base && $screen->is_block_editor();
    }

    /**
     * Panels moved to the Dev tab.
     *
     * @return array<int,array{id:string,title:string,domain:string}>
     */
    public static function panels() {
        /**
         * Filter the panels the block editor's Dev tab holds.
         *
         * @param array[] $panels Each: `id`, a meta box ID (or ''); `title`,
         *                        the panel's English title; and optionally
         *                        `domain`, the plugin's text domain, to
         *                        match the title in other languages too.
         */
        $panels = (array) apply_filters('seoprostack_editor_dev_panels', self::PANELS);
        $clean  = array();
        foreach ($panels as $panel) {
            if (!is_array($panel)) {
                continue;
            }
            $id     = isset($panel['id']) ? sanitize_html_class((string) $panel['id']) : '';
            $title  = isset($panel['title']) ? trim(wp_strip_all_tags((string) $panel['title'])) : '';
            $domain = isset($panel['domain']) ? sanitize_key((string) $panel['domain']) : '';
            if ('' !== $id || '' !== $title) {
                $clean[] = array('id' => $id, 'title' => $title, 'domain' => $domain);
            }
        }
        return $clean;
    }

    /**
     * Post editor: the Dev tab for developers, the panels hidden for
     * everyone else.
     */
    public static function block_editor() {
        if (!self::post_editor()) {
            return;
        }
        $panels    = self::panels();
        $developer = SEOProStack_Developers::is_developer();

        wp_register_style(self::HANDLE, false, array(), SEOPROSTACK_VERSION);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, self::css($developer));

        wp_register_script(self::HANDLE, SEOPROSTACK_URL . 'admin/js/seoprostack-editor-dev-tab.js', array('wp-data', 'wp-dom-ready', 'wp-i18n'), SEOPROSTACK_VERSION, true);
        wp_add_inline_script(self::HANDLE, 'window.seoprostackEditorDevTab = ' . wp_json_encode(array(
            'developer' => $developer,
            'panels'    => $panels,
            'rows'      => self::ROWS,
            'label'     => _x('Dev', 'block editor sidebar tab', 'seoprostack'),
        )) . ';', 'before');
        wp_enqueue_script(self::HANDLE);
    }

    /**
     * Styles: panels marked by the script show only in the Dev tab, flat
     * and in the order the script gives them.
     *
     * @param bool $developer Whether the current person is a developer.
     * @return string
     */
    private static function css($developer) {
        $css = '.sps-dev-panel{display:none!important}';
        if (!$developer) {
            return $css . self::TOOLBAR_BUTTONS . '{display:none!important}';
        }
        return $css
            . '.sps-dev-view{display:flex;flex-direction:column}'
            . '.sps-dev-view .sps-dev-panel:not(.is-hidden){display:block!important}'
            // No sideways scrolling from a meta box a pixel or two too wide (Kadence Simple Share's).
            . '.sps-dev-view .sps-dev-panel{min-width:0;max-width:100%;box-sizing:border-box;overflow-x:clip}'
            // Rows of the summary, out of their padded panel.
            . '.sps-dev-view .sps-dev-row{padding:12px 16px}'
            . '.sps-dev-view .sps-dev-row-last{padding-bottom:16px;border-bottom:1px solid var(--sps-ui-border,#e0e0e0)}'
            . '.sps-dev-view .sps-dev-flat{display:contents!important}'
            . '.sps-dev-view>:not(.sps-dev-panel):not(.sps-dev-flat),.sps-dev-view .sps-dev-flat>:not(.sps-dev-panel):not(.sps-dev-flat){display:none!important}'
            // The tab line under Dev while it is open (the script sets where).
            . '.sps-dev-tab-open{--selected-left:var(--sps-dev-left)!important;--selected-right:var(--sps-dev-right)!important;--selected-width:var(--sps-dev-width)!important}';
    }

    /**
     * Leave developer tools' toolbar scripts out of the block editor for
     * people who are not developers.
     *
     * @param string $hook Admin screen.
     */
    public static function toolbar_scripts($hook) {
        if (('post.php' !== $hook && 'post-new.php' !== $hook) || !self::post_editor() || SEOProStack_Developers::is_developer()) {
            return;
        }
        foreach (array_keys(self::TOOLBAR_SCRIPTS) as $handle) {
            wp_dequeue_script($handle);
        }
    }
}
