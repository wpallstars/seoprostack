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
        'SEOProStack_Admin_Page_Fade',
        'SEOProStack_Admin_Access',
        'SEOProStack_Hardening',
        'SEOProStack_Admin_Menu',
        'SEOProStack_Widget_Control',
        'SEOProStack_Dashboard_Layout',
        'SEOProStack_Admin_Tidy',
        'SEOProStack_Woo_Tidy',
        'SEOProStack_Login_Screen',
        'SEOProStack_Notification_Emails',
        'SEOProStack_Admin_Notices',
        'SEOProStack_Freemius_Quiet',
        'SEOProStack_Admin_Bar_More',
        'SEOProStack_Admin_Bar_Hide',
        'SEOProStack_List_Columns',
        'SEOProStack_Avatar_Privacy',
        'SEOProStack_Magic_Login',
        'SEOProStack_Duplicate_Posts',
        'SEOProStack_Post_Versions',
        'SEOProStack_Revisions',
        'SEOProStack_Preview_Links',
        'SEOProStack_Sticky_Posts',
        'SEOProStack_Bulk_Select_All',
        'SEOProStack_Post_Type_Switch',
        'SEOProStack_Hand_Order',
        'SEOProStack_Term_Tools',
        'SEOProStack_Menu_Visibility',
        'SEOProStack_Field_Search',
        'SEOProStack_Editor_Tidy',
        'SEOProStack_Translatepress_Colours',
        'SEOProStack_Auto_Upload',
        'SEOProStack_Paste_Media',
        'SEOProStack_Svg_Uploads',
        'SEOProStack_Resize_Uploads',
        'SEOProStack_Replace_Media',
        'SEOProStack_Nextgen_Images',
        'SEOProStack_Watermark_Images',
        'SEOProStack_Post_Scheduler',
        'SEOProStack_Iframe_Block',
        'SEOProStack_Screenshots',
        'SEOProStack_Link_Card_Block',
        'SEOProStack_Wikipedia_Previews',
        'SEOProStack_Word_Import',
        'SEOProStack_Spectra_Blocks',
        'SEOProStack_Short_Links',
        'SEOProStack_Remove_Cpt_Base',
        'SEOProStack_Gone_Urls',
        'SEOProStack_Old_Slugs',
        'SEOProStack_Maintenance',
        'SEOProStack_Preload_Pages',
        'SEOProStack_Delay_Scripts',
        'SEOProStack_Delayed_Analytics',
        'SEOProStack_Wp_Extras',
        'SEOProStack_Heartbeat',
        'SEOProStack_Woo_Light',
        'SEOProStack_Kadence_Library',
        'SEOProStack_Plugin_Loading',
        'SEOProStack_Plugin_Toggle',
        'SEOProStack_Plugin_Sizes',
        'SEOProStack_Plugin_Presets',
        'SEOProStack_Database_Keys',
        'SEOProStack_Licence_Calls',
        'SEOProStack_Plugin_Fixes',
        'SEOProStack_Hosting_Needs',
        'SEOProStack_Plugin_References',
    );

    /**
     * Features that some builds leave out, loaded only when their file is
     * present. The WordPress.org build drops GitHub updates: plugins hosted
     * there may not install or update code from anywhere else.
     *
     * @var string[]
     */
    private static $optional_features = array(
        'SEOProStack_Github_Updates',
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
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-admin-bar.php';
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-disable-bloat.php';
        // Usually loaded already by the must-use file of "Load plugins only
        // where needed"; features ask it which plugins are active.
        if (!class_exists('SEOProStack_Plugin_Loader', false)) {
            require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-plugin-loader.php';
        }
        foreach (self::$core_features as $class) {
            require_once self::feature_file($class);
        }
        foreach (self::$optional_features as $class) {
            if (is_readable(self::feature_file($class))) {
                require_once self::feature_file($class);
                self::$core_features[] = $class;
            }
        }

        SEOProStack_Settings::init();
        SEOProStack_Admin_Bar::init();
        SEOProStack_Disable_Bloat::init();
        // Priority 0, added after SEOProStack_Settings::maybe_migrate() so features
        // read migrated values, and early enough to hook widgets_init (init:1).
        add_action('init', array(__CLASS__, 'boot_features'), 0);

        if (is_admin()) {
            require_once SEOPROSTACK_DIR . 'admin/settings.php';
        }
    }

    /**
     * File of a feature class.
     *
     * @param string $class Class name.
     * @return string
     */
    private static function feature_file($class) {
        return SEOPROSTACK_DIR . 'includes/features/class-' . str_replace('_', '-', strtolower($class)) . '.php';
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

    /**
     * Plugin deactivated: let features that changed files or scheduled
     * work outside WordPress's options undo it (a static deactivate() method).
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        foreach (self::features() as $class) {
            if (method_exists($class, 'deactivate')) {
                $class::deactivate((bool) $network_wide);
            }
        }
    }
}
