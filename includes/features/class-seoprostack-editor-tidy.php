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
 * Replaces part of Disable Bloat; its matching switches are imported once.
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
                'default'     => false,
                'tab'         => 'content',
                'label'       => __('Simpler block editor', 'seoprostack'),
                'description' => __('Turn off parts of the block editor most people never use, such as the welcome guide and the block directory. Applies to everyone.', 'seoprostack'),
                'replaces'    => SEOProStack_Disable_Bloat::PLUGINS,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array('welcome_guide', 'block_directory'),
                'parent'      => self::KEY,
                'label'       => __('Turn off', 'seoprostack'),
                'options'     => array(__CLASS__, 'item_options'),
            ),
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
}
