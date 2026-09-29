<?php
/**
 * Base class for Allstars features.
 *
 * A feature is a self-contained class that:
 * - declares its settings in settings() (the on/off switch first, keyed by KEY,
 *   then any options with 'parent' => KEY);
 * - registers its hooks in boot(), which runs on `init` for every registered
 *   feature. Disabled features should return early so they cost nothing.
 *
 * Register extra features with the `allstars_features` filter.
 *
 * @package Allstars
 * @since 0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class Allstars_Feature {

    /** Setting key of the feature's on/off switch. */
    const KEY = '';

    /**
     * Settings schema entries for this feature.
     *
     * @return array<string,array>
     */
    public static function settings() {
        return array();
    }

    /**
     * Whether the feature is switched on.
     *
     * @return bool
     */
    public static function enabled() {
        return '' !== static::KEY && (bool) Allstars_Settings::get(static::KEY);
    }

    /**
     * Register hooks.
     */
    abstract public static function boot();
}
