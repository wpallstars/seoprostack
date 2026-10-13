<?php
/**
 * Quiet Imagify suggestions.
 *
 * WP Media's "plugin family" library, bundled by Rank Math Pro (checked with
 * 3.0.123, library 1.0.9), WP Rocket and BackWPup, shows "… recommends
 * optimizing your images … using Imagify" with an Install Imagify Plugin
 * button in the Media Library, the editors' media windows and image blocks,
 * and, for Rank Math, on Rank Math → General Settings, until someone with
 * permission to install plugins dismisses it.
 *
 * SEO Pro Stack does that job itself (WebP and AVIF images, Resize large
 * uploads), so the suggestion is turned off through the library's own
 * switches: its `wpmedia_plugin_family_show_imagify_banner` filter (1.0.8
 * and later), and, for older copies, its dismissal option
 * (`plugin_family_dismiss_promote_imagify`), read as dismissed without being
 * saved. Nothing is stored, and switching this off brings the suggestion
 * back. Installing Imagify from the Plugins screen is not affected.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.39
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Imagify_Quiet extends SEOProStack_Feature {

    const KEY = 'imagify_quiet';

    /** The plugin family library's dismissal option. */
    const DISMISSED = 'plugin_family_dismiss_promote_imagify';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request: SEO Pro Stack optimises images itself.
                'default'     => true,
                'tab'         => 'admin',
                'label'       => __('Quiet Imagify suggestions', 'seoprostack'),
                'description' => __('Rank Math Pro, WP Rocket and BackWPup stop suggesting Imagify in the Media Library, the editor and Rank Math’s settings. WebP and AVIF images and Resize large uploads do that job. Their settings are not changed.', 'seoprostack'),
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
        add_filter('wpmedia_plugin_family_show_imagify_banner', '__return_false', PHP_INT_MAX);
        add_filter('pre_option_' . self::DISMISSED, array(__CLASS__, 'dismissed'));
    }

    /**
     * Read the library's dismissal option as set, for copies older than its
     * filter.
     *
     * @param mixed $value Value from earlier filters; false to read the option.
     * @return mixed
     */
    public static function dismissed($value) {
        return false === $value ? '1' : $value;
    }
}
