<?php
/**
 * Simpler block editor.
 *
 * Turns off parts of the block editor that most people close once and
 * never use again: welcome guides, the block directory (which searches
 * WordPress.org for blocks to install), WordPress's own block patterns and
 * the Pattern Directory's, the template editor of classic themes, and
 * fullscreen mode.
 *
 * Welcome guides and fullscreen mode are each person's editor preferences,
 * which core prints into each admin screen from user meta. They are read
 * as off, so every editor opens without them; Help still opens the guide
 * for that visit. The rest use core hooks. Nothing is stored.
 *
 * The post editor's top bar is tidier too (TOP_BAR_KEY): other plugins'
 * panels start unpinned (UNPINNED, read as unpinned until the person pins
 * them), YellowPencil's button is left out, and an eye button opens the
 * page in the same tab, beside core's View button that opens a new tab.
 * With Kadence Blocks, core blocks it has its own version of leave the
 * inserter (KADENCE_KEY, KADENCE_BLOCKS).
 *
 * The editors also open faster: the site details they ask for are built
 * without describing every REST route first (see index_without_routes()).
 *
 * Replaces part of Disable Bloat; its matching switches are imported once.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Editor_Tidy extends SEOProStack_Feature {

    const KEY = 'editor_tidy';

    /** What to turn off. */
    const ITEMS_KEY = 'editor_tidy_items';

    /** The post editor's top bar. */
    const TOP_BAR_KEY = 'editor_tidy_top_bar';

    /** Kadence blocks in place of core's in the inserter. */
    const KADENCE_KEY = 'editor_tidy_kadence';

    /** Script handle. */
    const HANDLE = 'seoprostack-editor-tidy';

    /**
     * Other plugins' sidebar panels that start unpinned from the top bar:
     * the pinned item (plugin name/sidebar name) => panel.
     */
    const UNPINNED = array(
        'kadence-control/kadence-controls'                         => 'Kadence Blocks Controls',
        'rank-math-content-ai/seo-by-rank-math-content-ai-sidebar' => 'Content AI',
        'daexthrmal-hreflang-manager/hreflang-manager-lite-sidebar' => 'Hreflang Manager',
    );

    /**
     * Core blocks that leave the inserter while Kadence Blocks (or Pro)
     * has its own version: core block => Kadence block. Not the paragraph,
     * which is what typing makes.
     */
    const KADENCE_BLOCKS = array(
        'core/heading'      => 'kadence/advancedheading',
        'core/list'         => 'kadence/iconlist',
        'core/image'        => 'kadence/image',
        'core/gallery'      => 'kadence/advancedgallery',
        'core/buttons'      => 'kadence/advancedbtn',
        'core/columns'      => 'kadence/rowlayout',
        'core/cover'        => 'kadence/rowlayout',
        'core/group'        => 'kadence/column',
        'core/spacer'       => 'kadence/spacer',
        'core/separator'    => 'kadence/spacer',
        'core/accordion'    => 'kadence/accordion',
        'core/tabs'         => 'kadence/tabs',
        'core/table'        => 'kadence/table',
        'core/icon'         => 'kadence/icon',
        'core/search'       => 'kadence/search',
        'core/navigation'   => 'kadence/navigation',
        'core/latest-posts' => 'kadence/posts',
        'core/query'        => 'kadence/query',
    );

    /** Editor preferences per choice: [scope, name]. */
    const PREFERENCES = array(
        'welcome_guide' => array(
            array('core/edit-post', 'welcomeGuide'),
            array('core/edit-post', 'welcomeGuideTemplate'),
            array('core/edit-site', 'welcomeGuide'),
            array('core/edit-site', 'welcomeGuideStyles'),
            array('core/edit-site', 'welcomeGuidePage'),
            array('core/edit-site', 'welcomeGuideTemplate'),
            array('core/edit-widgets', 'welcomeGuide'),
            array('core/customize-widgets', 'welcomeGuide'),
        ),
        'fullscreen'    => array(
            array('core/edit-post', 'fullscreenMode'),
        ),
    );

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
                'label'       => __('Simpler block editor', 'seoprostack'),
                'description' => __('Turn off parts of the block editor most people never use, such as the welcome guide and the block directory, tidy its top bar, offer Kadence’s blocks first, and open it faster. Applies to everyone.', 'seoprostack'),
                'replaces'    => SEOProStack_Disable_Bloat::PLUGINS,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array('welcome_guide', 'block_directory'),
                'parent'      => self::KEY,
                'label'       => __('Turn off', 'seoprostack'),
                'options'     => array(__CLASS__, 'item_options'),
            ),
            self::TOP_BAR_KEY => array(
                'type'        => 'multi',
                'default'     => array('view_here', 'unpin_panels', 'yellowpencil'),
                'parent'      => self::KEY,
                'label'       => __('Top bar', 'seoprostack'),
                'options'     => array(__CLASS__, 'top_bar_options'),
            ),
            self::KADENCE_KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Kadence blocks first', 'seoprostack'),
                'description' => __('Core blocks that Kadence Blocks has its own version of, such as Heading, List, Image, Buttons and Columns, leave the inserter, so Kadence’s is the one offered. Pasting, transforms and patterns still use the core blocks, and pages keep them. Nothing changes without Kadence Blocks.', 'seoprostack'),
            ),
        );
    }

    /**
     * Top bar choices.
     *
     * @return array<string,string>
     */
    public static function top_bar_options() {
        return array(
            'view_here'    => __('An eye button that opens the page in this tab, beside the View button that opens a new tab', 'seoprostack'),
            /* translators: %s: panel names, such as Kadence Blocks Controls, Content AI. */
            'unpin_panels' => sprintf(__('Other plugins’ panels start unpinned: %s (open one from Options → Panels and pin it to keep it there)', 'seoprostack'), implode(', ', self::UNPINNED)),
            'yellowpencil' => __('No YellowPencil button (YellowPencil stays in the admin bar on the site)', 'seoprostack'),
        );
    }

    /**
     * Choices.
     *
     * @return array<string,string>
     */
    public static function item_options() {
        return array(
            'welcome_guide'   => __('Welcome guides, whenever an editor opens (Help still opens them)', 'seoprostack'),
            'block_directory' => __('Block directory: installing blocks from WordPress.org while searching for a block', 'seoprostack'),
            'core_patterns'   => __('WordPress’s own block patterns and those from the Pattern Directory (theme and plugin patterns stay)', 'seoprostack'),
            'template_editor' => __('Template editor in the post editor (classic themes only)', 'seoprostack'),
            'fullscreen'      => __('Fullscreen mode: the editor opens with the admin menu', 'seoprostack'),
            'classic_widgets' => __('Block widget editor: Appearance → Widgets and the Customizer use the classic widgets screen (classic themes only)', 'seoprostack'),
        );
    }

    /**
     * Import Disable Bloat's matching switches.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $choices = SEOProStack_Disable_Bloat::choices(self::ITEMS_KEY);
        if ($choices) {
            $options = self::import_setting($options, self::KEY, true);
            $options = self::import_setting($options, self::ITEMS_KEY, $choices);
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        $items = array_flip((array) SEOProStack_Settings::get(self::ITEMS_KEY));

        if (isset($items['block_directory'])) {
            remove_action('enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets');
        }
        if (isset($items['core_patterns'])) {
            // Core registers its patterns on init (priority 10).
            remove_theme_support('core-block-patterns');
            add_filter('should_load_remote_block_patterns', '__return_false', 99);
        }
        if (isset($items['template_editor']) && !(function_exists('wp_is_block_theme') && wp_is_block_theme())) {
            // Block themes need templates; classic themes only add the editor.
            remove_theme_support('block-templates');
        }
        if (isset($items['classic_widgets'])) {
            // Core's own switch (also what the Classic Widgets plugin does).
            add_filter('use_widgets_block_editor', '__return_false', 99);
        }
        if ((isset($items['welcome_guide']) || isset($items['fullscreen'])) && is_admin()) {
            add_filter('get_user_metadata', array(__CLASS__, 'preferences'), 10, 4);
        }
        $top_bar = array_flip((array) SEOProStack_Settings::get(self::TOP_BAR_KEY));
        if (isset($top_bar['unpin_panels']) && is_admin()) {
            // After preferences() above, which answers first.
            add_filter('get_user_metadata', array(__CLASS__, 'unpinned'), 11, 4);
        }
        if (isset($top_bar['yellowpencil']) && is_admin()) {
            // After YellowPencil enqueues its script (priority 10).
            add_action('admin_enqueue_scripts', array(__CLASS__, 'no_yellowpencil'), 100);
        }
        if ((isset($top_bar['view_here']) || isset($top_bar['yellowpencil']) || SEOProStack_Settings::get(self::KADENCE_KEY)) && is_admin()) {
            add_action('enqueue_block_editor_assets', array(__CLASS__, 'block_editor'));
        }
        // Not a choice: the answer is the same, only quicker.
        add_filter('rest_dispatch_request', array(__CLASS__, 'index_without_routes'), PHP_INT_MAX, 3);
    }

    /**
     * Build the site index without its list of routes when the request
     * leaves that list out.
     *
     * Every block editor asks for a few site details from the REST API
     * index (`/?_fields=…`) as it opens. Core builds the whole index first,
     * describing every route of every plugin, then drops what was not asked
     * for. On sites with many plugins that list is most of the work. While
     * the index is built, the route list reads as empty; it is back before
     * other plugins see the index and before the response is sent.
     *
     * @param mixed           $result  Answer from an earlier filter, or null.
     * @param WP_REST_Request $request Request.
     * @param string          $route   Matched route.
     * @return mixed
     */
    public static function index_without_routes($result, $request, $route) {
        if (null !== $result || '/' !== $route || !$request->has_param('_fields') || !function_exists('rest_is_field_included')) {
            return $result;
        }
        // An empty list means every field.
        $fields = wp_parse_list($request['_fields']);
        if (!$fields || rest_is_field_included('routes', $fields)) {
            return $result;
        }
        add_filter('rest_endpoints', array(__CLASS__, 'no_routes'), PHP_INT_MAX);
        add_filter('rest_index', array(__CLASS__, 'routes_back'), PHP_INT_MIN);
        add_filter('rest_request_after_callbacks', array(__CLASS__, 'routes_back'), PHP_INT_MIN);
        return $result;
    }

    /**
     * An empty route list, while the index is built.
     *
     * @return array
     */
    public static function no_routes() {
        return array();
    }

    /**
     * Put the route list back once the index is built (or the request ends
     * some other way), passing the filtered value through.
     *
     * @param mixed $value Filtered value.
     * @return mixed
     */
    public static function routes_back($value) {
        remove_filter('rest_endpoints', array(__CLASS__, 'no_routes'), PHP_INT_MAX);
        remove_filter('rest_index', array(__CLASS__, 'routes_back'), PHP_INT_MIN);
        remove_filter('rest_request_after_callbacks', array(__CLASS__, 'routes_back'), PHP_INT_MIN);
        return $value;
    }

    /**
     * Read the current person's editor preferences with the chosen ones off.
     *
     * Core prints them into each admin screen (`wp-preferences`), and they
     * win over each editor's defaults whenever they arrive, so the editors
     * open with them off. Changes made in the editor are still saved, but
     * read as off again on the next screen.
     *
     * @param mixed  $value    Value from an earlier filter, or null.
     * @param int    $user_id  User ID.
     * @param string $meta_key Meta key.
     * @param bool   $single   Whether one value was asked for.
     * @return mixed
     */
    public static function preferences($value, $user_id, $meta_key, $single) {
        global $wpdb;
        if (null !== $value || $wpdb->get_blog_prefix() . 'persisted_preferences' !== $meta_key || (int) $user_id !== get_current_user_id() || !$user_id) {
            return $value;
        }

        remove_filter('get_user_metadata', array(__CLASS__, 'preferences'), 10);
        $stored = get_user_meta($user_id, $meta_key, true);
        add_filter('get_user_metadata', array(__CLASS__, 'preferences'), 10, 4);

        $stored = is_array($stored) ? $stored : array();
        $items  = (array) SEOProStack_Settings::get(self::ITEMS_KEY);
        foreach (self::PREFERENCES as $choice => $list) {
            if (!in_array($choice, $items, true)) {
                continue;
            }
            foreach ($list as $pref) {
                if (!isset($stored[$pref[0]]) || !is_array($stored[$pref[0]])) {
                    $stored[$pref[0]] = array();
                }
                $stored[$pref[0]][$pref[1]] = false;
            }
        }
        // A list of values: get_metadata() returns its first for a single value.
        return array($stored);
    }

    /**
     * Read the current person's editor preferences with other plugins'
     * panels (UNPINNED) unpinned, unless that person chose for one.
     *
     * Only unset items are read as unpinned: pinning a panel saves it as
     * pinned, which then stays. Runs after preferences(), on its answer.
     *
     * @param mixed  $value    Value from an earlier filter, or null.
     * @param int    $user_id  User ID.
     * @param string $meta_key Meta key.
     * @param bool   $single   Whether one value was asked for.
     * @return mixed
     */
    public static function unpinned($value, $user_id, $meta_key, $single) {
        global $wpdb;
        if ($wpdb->get_blog_prefix() . 'persisted_preferences' !== $meta_key || (int) $user_id !== get_current_user_id() || !$user_id) {
            return $value;
        }
        if (null === $value) {
            remove_filter('get_user_metadata', array(__CLASS__, 'unpinned'), 11);
            $value = array(get_user_meta($user_id, $meta_key, true));
            add_filter('get_user_metadata', array(__CLASS__, 'unpinned'), 11, 4);
        }
        $stored = is_array($value) && isset($value[0]) && is_array($value[0]) ? $value[0] : array();

        // WordPress 6.6 moved the post editor's pinned items to the core
        // scope; before, the editor read them from core/edit-post.
        $scope  = version_compare(get_bloginfo('version'), '6.6', '>=') ? 'core' : 'core/edit-post';
        $pinned = isset($stored[$scope]['pinnedItems']) && is_array($stored[$scope]['pinnedItems']) ? $stored[$scope]['pinnedItems'] : array();
        foreach (array_keys(self::UNPINNED) as $item) {
            if (!isset($pinned[$item])) {
                $pinned[$item] = false;
            }
        }
        if (!isset($stored[$scope]) || !is_array($stored[$scope])) {
            $stored[$scope] = array();
        }
        $stored[$scope]['pinnedItems'] = $pinned;
        return array($stored);
    }

    /**
     * Whether the current screen is the post editor.
     *
     * @return bool
     */
    private static function post_editor() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && 'post' === $screen->base && $screen->is_block_editor();
    }

    /**
     * Core blocks to leave out of the inserter: those whose Kadence block is
     * registered and not switched off in Kadence's settings.
     *
     * @return array<string,string> Core block => Kadence block.
     */
    public static function kadence_blocks() {
        if (!SEOProStack_Settings::get(self::KADENCE_KEY) || !class_exists('WP_Block_Type_Registry')) {
            return array();
        }
        $registry = WP_Block_Type_Registry::get_instance();
        // Blocks switched off on Kadence's Blocks screen; its editor script
        // unregisters them.
        $off    = (array) get_option('kt_blocks_unregistered_blocks', array());
        $blocks = array();
        foreach (self::KADENCE_BLOCKS as $core => $kadence) {
            if ($registry->is_registered($kadence) && !in_array($kadence, $off, true)) {
                $blocks[$core] = $kadence;
            }
        }
        return $blocks;
    }

    /**
     * Post editor: the eye button, Kadence blocks first, and a rule hiding
     * YellowPencil's button should its script load anyway.
     */
    public static function block_editor() {
        if (!self::post_editor()) {
            return;
        }
        $top_bar = (array) SEOProStack_Settings::get(self::TOP_BAR_KEY);
        if (in_array('yellowpencil', $top_bar, true)) {
            wp_register_style(self::HANDLE, false, array(), SEOPROSTACK_VERSION);
            wp_enqueue_style(self::HANDLE);
            wp_add_inline_style(self::HANDLE, SEOProStack_Editor_Dev_Tab::TOOLBAR_BUTTONS . '{display:none!important}');
        }
        $kadence   = self::kadence_blocks();
        $view_here = in_array('view_here', $top_bar, true);
        if (!$kadence && !$view_here) {
            return;
        }
        wp_register_script(self::HANDLE, SEOPROSTACK_URL . 'admin/js/seoprostack-editor-tidy.js', array('wp-blocks', 'wp-components', 'wp-data', 'wp-dom-ready', 'wp-element', 'wp-hooks'), SEOPROSTACK_VERSION, true);
        wp_add_inline_script(self::HANDLE, 'window.seoprostackEditorTidy = ' . wp_json_encode(array(
            'kadence'  => $kadence ? $kadence : null,
            /* translators: %s: the post type's View label, such as View Page. */
            'viewHere' => $view_here ? __('%s in this tab', 'seoprostack') : '',
            'viewPost' => __('View post', 'seoprostack'),
        )) . ';', 'before');
        wp_enqueue_script(self::HANDLE);
    }

    /**
     * Leave YellowPencil's toolbar script out of the block editor, for
     * everyone (Dev tab in the block editor does it for people who are not
     * developers).
     *
     * @param string $hook Admin screen.
     */
    public static function no_yellowpencil($hook) {
        if (('post.php' !== $hook && 'post-new.php' !== $hook) || !self::post_editor()) {
            return;
        }
        foreach (array_keys(SEOProStack_Editor_Dev_Tab::TOOLBAR_SCRIPTS) as $handle) {
            wp_dequeue_script($handle);
        }
    }
}
