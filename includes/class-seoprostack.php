<?php
/**
 * SEO Pro Stack bootstrap and feature registry.
 *
 * @package SEOProStack
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack {

    /**
     * Built-in features, in the order their cards appear within each tab.
     * Each lives in includes/features/class-{lowercase-hyphenated-name}.php.
     *
     * @var string[]
     */
    private static $core_features = array(
        'SEOProStack_Admin_Colors',
        'SEOProStack_Admin_Access',
        'SEOProStack_Widget_Control',
        'SEOProStack_Notification_Emails',
        'SEOProStack_Admin_Notices',
        'SEOProStack_Avatar_Privacy',
        'SEOProStack_Magic_Login',
        'SEOProStack_Duplicate_Posts',
        'SEOProStack_Post_Versions',
        'SEOProStack_Preview_Links',
        'SEOProStack_Sticky_Posts',
        'SEOProStack_Bulk_Select_All',
        'SEOProStack_Auto_Upload',
        'SEOProStack_Paste_Media',
        'SEOProStack_Svg_Uploads',
        'SEOProStack_Resize_Uploads',
        'SEOProStack_Replace_Media',
        'SEOProStack_Post_Scheduler',
        'SEOProStack_Iframe_Block',
        'SEOProStack_Gone_Urls',
        'SEOProStack_Preload_Pages',
        'SEOProStack_Delay_Scripts',
        'SEOProStack_Delayed_Analytics',
        'SEOProStack_Plugin_Toggle',
        'SEOProStack_Plugin_Sizes',
        'SEOProStack_Plugin_References',
    );

    /**
     * Resolved feature classes.
     *
     * @var string[]|null
     */
    private static $features = null;

    /**
     * Load files and register hooks.
     */
    public static function load() {
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-settings.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-feature.php';
        foreach (self::$core_features as $class) {
            require_once SEOPROSTACK_DIR . 'includes/features/class-' . str_replace('_', '-', strtolower($class)) . '.php';
        }

        SEOProStack_Settings::init();
        // Priority 0, added after SEOProStack_Settings::maybe_migrate() so features
        // read migrated values, and early enough to hook widgets_init (init:1).
        add_action('init', array(__CLASS__, 'boot_features'), 0);

        if (is_admin()) {
            require_once SEOPROSTACK_DIR . 'admin/settings.php';
        }
    }

    /**
     * Registered feature classes.
     *
     * @return string[]
     */
    public static function features() {
        if (null === self::$features) {
            /**
             * Filter the feature classes. Each must extend SEOProStack_Feature.
             *
             * @param string[] $features Class names.
             */
            $features       = (array) apply_filters('seoprostack_features', self::$core_features);
            self::$features = array_values(array_filter($features, function ($class) {
                return is_string($class) && class_exists($class) && is_subclass_of($class, 'SEOProStack_Feature');
            }));
        }
        return self::$features;
    }

    /**
     * Boot every feature.
     */
    public static function boot_features() {
        foreach (self::features() as $class) {
            $class::boot();
        }
    }
}
