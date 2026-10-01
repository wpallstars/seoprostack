<?php
/**
 * Faster editor with Kadence Blocks.
 *
 * Kadence Blocks prints its whole design library (every pattern's
 * pre-rendered HTML) into each block editor screen as the
 * kadence_blocks_params_library script variable: about 15 MB on a test
 * site, which made post-new.php about 24 MB. This turns that preload off
 * through Kadence's own kadence_blocks_preload_design_library filter, which
 * Kadence itself turns off when Gravity Forms is active. The editor then
 * fetches the library from Kadence's REST route
 * (kb-design-library/v1/get_library) the first time the design library is
 * opened, and its AI wizard settings the same way.
 *
 * @package SEOProStack
 * @since 0.5.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Library extends SEOProStack_Feature {

    const KEY = 'kadence_library_on_demand';

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
                'tab'         => 'speed',
                'label'       => __('Faster editor with Kadence Blocks', 'seoprostack'),
                'description' => __('Kadence Blocks puts its whole design library into every editor page, often more than 10 MB. This loads the library when you open it instead, so the editor opens faster. Nothing changes if Kadence Blocks is not active.', 'seoprostack'),
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
        // Default priority, so a site's own filter at a higher one still wins.
        add_filter('kadence_blocks_preload_design_library', '__return_false');
    }
}
