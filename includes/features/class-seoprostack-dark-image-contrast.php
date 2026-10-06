<?php
/**
 * Dark mode image contrast.
 *
 * Logos, icons and line art with a transparent background are usually made
 * for a light page. When the Kadence Pro dark mode switcher (or a theme that
 * switches palettes the same way) turns the page dark, a dark logo on a dark
 * section all but disappears. This measures each such image against the
 * background it really sits on, and in dark mode only, recolours the ones
 * that are hard to see with a CSS filter. Photos and images without
 * transparency are left alone.
 *
 * The work is in assets/dark-image-contrast.js, loaded in the footer only on
 * pages whose body has a colour switch class (color-switch-light or
 * color-switch-dark). Nothing is stored and nothing is sent anywhere.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Dark_Image_Contrast extends SEOProStack_Feature {

    const KEY = 'dark_image_contrast';

    const HANDLE = 'seoprostack-dark-image-contrast';

    const WATERMARKS = 'dark_image_contrast_watermarks';

    /** Whether the page's body has a dark mode switch class. @var bool */
    private static $switcher = false;

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
                'tab'         => 'media',
                'label'       => __('Dark mode image contrast', 'seoprostack'),
                'description' => __('In Kadence’s dark mode, transparent logos, icons and drawings that would be hard to see on the background behind them are lightened or darkened to stand out. Photos and images without transparency are left alone. To keep an image as it is, add the class seoprostack-keep-colours to it or its block. Nothing changes without the Kadence Pro dark mode switcher.', 'seoprostack'),
            ),
            self::WATERMARKS => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Keep watermarks subtle', 'seoprostack'),
                'description' => __('Logos and drawings shown faintly on purpose (under 75% opacity, such as a row or column background overlay) stay faint in dark mode: a white watermark that would stand out on a dark page is made fainter, and one that would vanish is lightened, then made faint. Add the class seoprostack-keep-colours to keep one as it is.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin()) {
            return;
        }
        // Last, after Kadence Pro adds its classes.
        add_filter('body_class', array(__CLASS__, 'body_class'), PHP_INT_MAX);
        // Before wp_print_footer_scripts (priority 20) prints footer scripts.
        add_action('wp_footer', array(__CLASS__, 'enqueue'), 5);
    }

    /**
     * Note whether the theme switches between light and dark palettes.
     *
     * @param string[] $classes Body classes.
     * @return string[] Unchanged.
     */
    public static function body_class($classes) {
        self::$switcher = (bool) array_intersect(array('color-switch-dark', 'color-switch-light'), (array) $classes);
        return $classes;
    }

    /**
     * Load the script on pages with a dark mode switcher.
     */
    public static function enqueue() {
        if (!self::$switcher) {
            return;
        }
        $js = 'assets/dark-image-contrast.js';
        wp_enqueue_script(self::HANDLE, SEOPROSTACK_URL . $js, array(), (string) filemtime(SEOPROSTACK_DIR . $js), true);
        $config = array('watermarks' => (bool) SEOProStack_Settings::get(self::WATERMARKS));
        wp_add_inline_script(self::HANDLE, 'window.seoprostackDarkImageContrast = ' . wp_json_encode($config) . ';', 'before');
    }
}
