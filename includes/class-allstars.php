<?php
/**
 * Allstars bootstrap and feature registry.
 *
 * @package Allstars
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Allstars {

    /**
     * Built-in features, in the order their cards appear.
     *
     * @var string[]
     */
    private static $core_features = array(
        'Allstars_Admin_Colors',
        'Allstars_Magic_Login',
        'Allstars_Auto_Upload',
        'Allstars_Post_Scheduler',
        'Allstars_Iframe_Block',
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
            'includes/class-allstars-settings.php',
            'includes/class-allstars-feature.php',
            'includes/features/class-allstars-admin-colors.php',
            'includes/features/class-allstars-magic-login.php',
            'includes/features/class-allstars-auto-upload.php',
            'includes/features/class-allstars-post-scheduler.php',
            'includes/features/class-allstars-iframe-block.php',
        );
        foreach ($files as $file) {
            require_once ALLSTARS_DIR . $file;
        }

        Allstars_Settings::init();
        // After Allstars_Settings::maybe_migrate() (init:5) so features read migrated values.
        add_action('init', array(__CLASS__, 'boot_features'), 10);

        if (is_admin()) {
            require_once ALLSTARS_DIR . 'admin/settings.php';
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
             * Filter the feature classes. Each must extend Allstars_Feature.
             *
             * @param string[] $features Class names.
             */
            $features       = (array) apply_filters('allstars_features', self::$core_features);
            self::$features = array_values(array_filter($features, function ($class) {
                return is_string($class) && class_exists($class) && is_subclass_of($class, 'Allstars_Feature');
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
