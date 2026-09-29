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
     * Built-in features, in the order their cards appear.
     *
     * @var string[]
     */
    private static $core_features = array(
        'SEOProStack_Admin_Colors',
        'SEOProStack_Magic_Login',
        'SEOProStack_Auto_Upload',
        'SEOProStack_Post_Scheduler',
        'SEOProStack_Iframe_Block',
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
        $files = array(
            'includes/class-seoprostack-settings.php',
            'includes/class-seoprostack-feature.php',
            'includes/features/class-seoprostack-admin-colors.php',
            'includes/features/class-seoprostack-magic-login.php',
            'includes/features/class-seoprostack-auto-upload.php',
            'includes/features/class-seoprostack-post-scheduler.php',
            'includes/features/class-seoprostack-iframe-block.php',
        );
        foreach ($files as $file) {
            require_once SEOPROSTACK_DIR . $file;
        }

        SEOProStack_Settings::init();
        // After SEOProStack_Settings::maybe_migrate() (init:5) so features read migrated values.
        add_action('init', array(__CLASS__, 'boot_features'), 10);

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
