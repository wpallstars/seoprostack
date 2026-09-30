<?php
/**
 * Hide dashboard widgets and disable sidebar widgets.
 *
 * Dashboard widgets are only registered while the Dashboard loads, so the
 * list of choices is remembered from the last Dashboard visit (plus the core
 * widgets). Sidebar widgets are unregistered on widgets_init before core
 * registers their instances. Replaces "Widget Disable"; its settings are
 * imported once.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Widget_Control extends SEOProStack_Feature {

    const KEY = 'hide_dashboard_widgets';

    /** Sidebar widgets switch. */
    const SIDEBAR_KEY = 'disable_sidebar_widgets';

    /** Dashboard widgets seen on the last Dashboard visit (id => title). */
    const SEEN_OPTION = 'seoprostack_dashboard_widgets';

    /** Pseudo widget for the Welcome panel. */
    const WELCOME = 'dashboard_welcome_panel';

    /**
     * Sidebar widget classes and names, captured before any are removed.
     *
     * @var array<string,string>|null
     */
    private static $sidebar_widgets = null;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        $replace = array('wp-widget-disable' => 'Widget Disable');

        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'general',
                'label'       => __('Hide dashboard widgets', 'seoprostack'),
                'description' => __('Remove Dashboard boxes nobody uses, such as WordPress Events and News or plugin promotions. Applies to everyone.', 'seoprostack'),
                'replaces'    => $replace,
            ),
            'hidden_dashboard_widgets' => array(
                'type'        => 'multi',
                'open'        => true,
                'default'     => array(self::WELCOME, 'dashboard_primary'),
                'parent'      => self::KEY,
                'label'       => __('Widgets to hide', 'seoprostack'),
                'description' => __('Widgets added by plugins appear here after you next open the Dashboard.', 'seoprostack'),
                'options'     => array(__CLASS__, 'dashboard_widget_options'),
            ),
            self::SIDEBAR_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'general',
                'label'       => __('Disable sidebar widgets', 'seoprostack'),
                'description' => __('Remove classic widgets you never use from the Widgets screen and the Customizer, and stop them showing in sidebars.', 'seoprostack'),
                'replaces'    => $replace,
            ),
            'disabled_sidebar_widgets' => array(
                'type'        => 'multi',
                'open'        => true,
                'default'     => array('WP_Widget_Meta'),
                'parent'      => self::SIDEBAR_KEY,
                'label'       => __('Widgets to disable', 'seoprostack'),
                'options'     => array(__CLASS__, 'sidebar_widget_options'),
            ),
        );
    }

    /**
     * Import Widget Disable settings.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $dashboard = get_option('rplus_wp_widget_disable_dashboard_option');
        if (is_array($dashboard) && $dashboard) {
            $options = self::import_setting($options, self::KEY, true);
            $options = self::import_setting($options, 'hidden_dashboard_widgets', array_keys($dashboard));
        }

        $sidebar = get_option('rplus_wp_widget_disable_sidebar_option');
        if (is_array($sidebar) && $sidebar) {
            $options = self::import_setting($options, self::SIDEBAR_KEY, true);
            // Stored with an optional leading namespace separator.
            $options = self::import_setting($options, 'disabled_sidebar_widgets', array_map(function ($class) {
                return ltrim((string) $class, '\\');
            }, array_keys($sidebar)));
        }

        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (is_admin()) {
            // Keep the list of choices current even while the feature is off.
            add_action('wp_dashboard_setup', array(__CLASS__, 'remember_dashboard_widgets'), 9998);
        }
        if (self::enabled()) {
            add_action('wp_dashboard_setup', array(__CLASS__, 'remove_dashboard_widgets'), 9999);
            add_action('wp_network_dashboard_setup', array(__CLASS__, 'remove_dashboard_widgets'), 9999);
        }

        // Capture names for the settings screen, then unregister before core
        // turns widget classes into instances (WP_Widget_Factory, priority 100).
        add_action('widgets_init', array(__CLASS__, 'capture_sidebar_widgets'), 98);
        if (SEOProStack_Settings::get(self::SIDEBAR_KEY)) {
            add_action('widgets_init', array(__CLASS__, 'unregister_sidebar_widgets'), 99);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Dashboard                                                              */
    /* --------------------------------------------------------------------- */

    /**
     * Remember which widgets the Dashboard registers.
     */
    public static function remember_dashboard_widgets() {
        global $wp_meta_boxes;

        $seen = array();
        if (!empty($wp_meta_boxes['dashboard']) && is_array($wp_meta_boxes['dashboard'])) {
            foreach ($wp_meta_boxes['dashboard'] as $priorities) {
                foreach ((array) $priorities as $boxes) {
                    foreach ((array) $boxes as $id => $box) {
                        if (is_array($box) && !empty($box['title'])) {
                            $seen[(string) $id] = trim(wp_strip_all_tags((string) $box['title']));
                        }
                    }
                }
            }
        }

        if ($seen && get_option(self::SEEN_OPTION) !== $seen) {
            update_option(self::SEEN_OPTION, $seen, false);
        }
    }

    /**
     * Remove the chosen widgets.
     */
    public static function remove_dashboard_widgets() {
        $hidden = (array) SEOProStack_Settings::get('hidden_dashboard_widgets');
        $screen = is_network_admin() ? 'dashboard-network' : 'dashboard';

        foreach ($hidden as $id) {
            if (self::WELCOME === $id) {
                remove_action('welcome_panel', 'wp_welcome_panel');
                continue;
            }
            foreach (array('normal', 'side', 'column3', 'column4') as $context) {
                remove_meta_box($id, $screen, $context);
            }
        }
    }

    /**
     * Choices for the dashboard widget list.
     *
     * @return array<string,string>
     */
    public static function dashboard_widget_options() {
        $core = array(
            self::WELCOME             => __('Welcome', 'seoprostack'),
            'dashboard_site_health'   => __('Site Health Status', 'seoprostack'),
            'dashboard_right_now'     => __('At a Glance', 'seoprostack'),
            'dashboard_activity'      => __('Activity', 'seoprostack'),
            'dashboard_quick_press'   => __('Quick Draft', 'seoprostack'),
            'dashboard_primary'       => __('WordPress Events and News', 'seoprostack'),
            'dashboard_browser_nag'   => __('Browser update notice', 'seoprostack'),
            'dashboard_php_nag'       => __('PHP update notice', 'seoprostack'),
        );

        $seen = get_option(self::SEEN_OPTION, array());
        $seen = is_array($seen) ? array_diff_key($seen, $core) : array();
        asort($seen, SORT_NATURAL | SORT_FLAG_CASE);

        return $core + $seen;
    }

    /* --------------------------------------------------------------------- */
    /* Sidebar                                                                */
    /* --------------------------------------------------------------------- */

    /**
     * Record registered widget classes before any are removed.
     */
    public static function capture_sidebar_widgets() {
        global $wp_widget_factory;

        self::$sidebar_widgets = array();
        if (!$wp_widget_factory instanceof WP_Widget_Factory) {
            return;
        }
        foreach ($wp_widget_factory->widgets as $key => $widget) {
            $class = is_object($widget) ? get_class($widget) : (string) $key;
            $name  = (is_object($widget) && !empty($widget->name)) ? (string) $widget->name : $class;
            self::$sidebar_widgets[$class] = $name;
        }
    }

    /**
     * Unregister the chosen widget classes.
     */
    public static function unregister_sidebar_widgets() {
        global $wp_widget_factory;

        if (!$wp_widget_factory instanceof WP_Widget_Factory) {
            return;
        }
        $disabled = array_flip((array) SEOProStack_Settings::get('disabled_sidebar_widgets'));
        foreach ($wp_widget_factory->widgets as $key => $widget) {
            $class = is_object($widget) ? get_class($widget) : (string) $key;
            if (isset($disabled[$class])) {
                // Widgets can be registered by class name or instance; remove by the stored key.
                unset($wp_widget_factory->widgets[$key]);
            }
        }
    }

    /**
     * Choices for the sidebar widget list.
     *
     * @return array<string,string>
     */
    public static function sidebar_widget_options() {
        $widgets = is_array(self::$sidebar_widgets) ? self::$sidebar_widgets : array();
        $options = array();
        foreach ($widgets as $class => $name) {
            $options[$class] = sprintf('%1$s (%2$s)', $name, $class);
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }
}
