<?php
/**
 * Bundled translations for sites without WordPress.org language packs.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Translations {

    /** Register paths only after WordPress has resolved the request's locale. */
    public static function init() {
        // Settings migrations and features start on init:0 and may translate.
        add_action('init', array(__CLASS__, 'load'), -1);
        add_action('wp_print_scripts', array(__CLASS__, 'scripts'), 0);
        add_action('wp_print_footer_scripts', array(__CLASS__, 'scripts'), 0);
        add_action('admin_print_scripts', array(__CLASS__, 'scripts'), 0);
        add_action('admin_print_footer_scripts', array(__CLASS__, 'scripts'), 0);
    }

    /** Register the bundled path; WordPress 6.7+ loads messages just in time. */
    public static function load() {
        load_plugin_textdomain('seoprostack', false, dirname(plugin_basename(SEOPROSTACK_FILE)) . '/languages');
    }

    /**
     * Give registered internationalized scripts their bundled JSON path.
     *
     * Blocks and the shared starter register scripts separately. Resolve them
     * before printing rather than maintaining a second list of their handles.
     * wp_set_script_translations keeps WordPress.org packs as its fallback.
     */
    public static function scripts() {
        $scripts = wp_scripts();
        foreach ($scripts->registered as $handle => $script) {
            if (!is_string($script->src) || 0 !== strpos($script->src, SEOPROSTACK_URL)) {
                continue;
            }
            if ('seoprostack' !== $script->textdomain && !in_array('wp-i18n', $script->deps, true)) {
                continue;
            }
            wp_set_script_translations($handle, 'seoprostack', SEOPROSTACK_DIR . 'languages');
        }
    }
}
