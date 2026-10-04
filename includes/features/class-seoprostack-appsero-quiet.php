<?php
/**
 * Quiet Appsero prompts.
 *
 * Appsero is a usage-tracking and licensing kit that many plugins bundle
 * (Easy Video Reviews and others, each with its own copy). Each copy shows
 * an "Allow … to collect diagnostic data" notice on every admin screen until
 * it is answered, prints a "Goodbyes are always hard" survey on the Plugins
 * screen that opens when the plugin is deactivated and sends the answer with
 * the site's details, and sends the site's details when a theme using it is
 * switched away from, whether or not the owner opted in.
 *
 * This removes those hooks from each copy's Insights object (Appsero\Insights,
 * or a copy under another namespace or a subclass), found by class rather than
 * by file, since versions and namespaces differ between plugins. Appsero has no
 * filter for them. Nothing is stored: no plugin is opted in or out, and
 * switching this off brings every prompt back.
 *
 * Kept: licence pages and notices (Appsero\License), the plugin's own opt-in
 * and opt-out links, and weekly reports from plugins already opted in (that
 * choice is the owner's, in the plugin).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.13.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Appsero_Quiet extends SEOProStack_Feature {

    const KEY = 'appsero_quiet';

    /** Insights methods removed, by the hook they are on. */
    const HOOKS = array(
        'admin_notices' => 'admin_notice',
        'admin_footer'  => 'deactivate_scripts',
        'switch_theme'  => 'theme_deactivated',
    );

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // On by default at the owner's request: fewer nags out of the box.
                'default'     => true,
                'tab'         => 'admin',
                'label'       => __('Quiet Appsero prompts', 'seoprostack'),
                'description' => __('Plugins that use Appsero stop asking to collect usage data, stop asking why you deactivate them, and send nothing when they are deactivated. Licences stay, and their settings are not changed: plugins you already allowed keep sending their weekly report until you opt out in the plugin.', 'seoprostack'),
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
        // After plugins have set up Appsero (plugins_loaded, init or
        // admin_init) and before notices, the Plugins screen and AJAX run.
        add_action('admin_init', array(__CLASS__, 'unhook'), PHP_INT_MAX);
    }

    /**
     * Whether an object is Appsero's Insights class, under any namespace.
     *
     * @param object $object Object.
     * @return bool
     */
    private static function is_insights($object) {
        foreach (array_merge(array(get_class($object)), array_values((array) class_parents($object))) as $class) {
            // Appsero\Insights or {Vendor}\Appsero\Insights.
            if ('\\appsero\\insights' === strtolower(substr('\\' . $class, -17))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Method name of an Insights callback, or ''.
     *
     * @param mixed $function Callback.
     * @return string
     */
    private static function insights_method($function) {
        if (!is_array($function) || 2 !== count($function) || !is_object($function[0]) || !is_string($function[1])) {
            return '';
        }
        return self::is_insights($function[0]) ? $function[1] : '';
    }

    /**
     * Remove the notice, survey, survey submission and theme-switch report
     * from every Appsero copy.
     */
    public static function unhook() {
        global $wp_filter;
        foreach (array_keys((array) $wp_filter) as $hook) {
            $hook = (string) $hook;
            if (isset(self::HOOKS[$hook])) {
                $method = self::HOOKS[$hook];
            } elseif (0 === strpos($hook, 'plugin_action_links_')) {
                // Marks the Deactivate link so it opens the survey.
                $method = 'plugin_action_links';
            } elseif (0 === strpos($hook, 'wp_ajax_') && '_submit-uninstall-reason' === substr($hook, -24)) {
                $method = 'uninstall_reason_submission';
            } else {
                continue;
            }
            if (!$wp_filter[$hook] instanceof WP_Hook) {
                continue;
            }
            foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    if ($method === self::insights_method($callback['function'])) {
                        remove_filter($hook, $callback['function'], $priority);
                    }
                }
            }
        }
    }
}
