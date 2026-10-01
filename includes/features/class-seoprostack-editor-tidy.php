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
 * Welcome guides and fullscreen mode are each person's editor preferences:
 * when one is on as an editor opens, it is switched off and saved, as if
 * the person had closed it. The rest use core hooks; nothing is stored.
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
            'welcome_guide'   => __('Welcome guides: closed for each person the next time an editor opens', 'seoprostack'),
            'block_directory' => __('Block directory: installing blocks from WordPress.org while searching for a block', 'seoprostack'),
            'core_patterns'   => __('WordPress’s own block patterns and those from the Pattern Directory (theme and plugin patterns stay)', 'seoprostack'),
            'template_editor' => __('Template editor in the post editor (classic themes only)', 'seoprostack'),
            'fullscreen'      => __('Fullscreen mode: the editor opens with the admin menu', 'seoprostack'),
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
        if (isset($items['welcome_guide']) || isset($items['fullscreen'])) {
            add_action('enqueue_block_editor_assets', array(__CLASS__, 'preferences_script'));
        }
    }

    /**
     * Switch off the chosen editor preferences as the editor starts.
     */
    public static function preferences_script() {
        $items = (array) SEOProStack_Settings::get(self::ITEMS_KEY);
        $prefs = array();
        foreach (self::PREFERENCES as $choice => $list) {
            if (in_array($choice, $items, true)) {
                $prefs = array_merge($prefs, $list);
            }
        }
        if (!$prefs) {
            return;
        }

        wp_register_script('seoprostack-editor-tidy', false, array('wp-data'), SEOPROSTACK_VERSION, true);
        wp_enqueue_script('seoprostack-editor-tidy');
        // Each editor sets its defaults as it starts, so wait for a value
        // before switching it off; preferences of other editors stay unread.
        $js = <<<'JS'
(function (wp, prefs) {
    if (!wp || !wp.data || !wp.data.subscribe) {
        return;
    }
    var data = wp.data, done = [], left = prefs.length, stop = null;
    function check() {
        var get = data.select('core/preferences'), set = data.dispatch('core/preferences');
        if (!get || !set || !set.set) {
            return;
        }
        prefs.forEach(function (pref, i) {
            if (done[i]) {
                return;
            }
            var value = get.get(pref[0], pref[1]);
            if (undefined === value) {
                return;
            }
            done[i] = true;
            left--;
            if (value) {
                set.set(pref[0], pref[1], false);
            }
        });
        if (left <= 0 && stop) {
            stop();
            stop = null;
        }
    }
    stop = data.subscribe(check);
    check();
    setTimeout(function () {
        if (stop) {
            stop();
            stop = null;
        }
    }, 30000);
})(window.wp, %s);
JS;
        wp_add_inline_script('seoprostack-editor-tidy', sprintf($js, wp_json_encode($prefs)));
    }
}
