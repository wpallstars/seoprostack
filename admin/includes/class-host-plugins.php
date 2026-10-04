<?php
/**
 * Plugins screen: plugins web hosts add to new sites.
 *
 * Some hosts install and activate their own plugins on every new site, such
 * as Hostinger AI and Hostinger Easy Onboarding. They are not needed to run
 * the site and add admin screens, prompts and requests. While one is active,
 * a note under its row says what it is for and recommends deactivating it
 * unless that is used, with core's Deactivate link. The owner decides:
 * nothing is deactivated or deleted here.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.12.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Host_Plugins {

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('load-plugins.php', array(__CLASS__, 'load_screen'));
    }

    /**
     * Plugins screen: add the notes, for people who can manage plugins.
     */
    public static function load_screen() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        add_action('after_plugin_row', array(__CLASS__, 'row_note'), 10, 1);
        add_action('admin_head', array(__CLASS__, 'row_note_style'));
    }

    /**
     * Plugins hosts add to new sites, keyed by plugin folder.
     *
     * @return array<string,array{host:string,use:string}> folder => host name and what the plugin is for.
     */
    public static function plugins() {
        $plugins = array(
            'hostinger-ai-assistant'    => array(
                'host' => 'Hostinger',
                'use'  => __('writing posts and pages with Hostinger’s AI and connecting AI tools to the site', 'seoprostack'),
            ),
            'hostinger-easy-onboarding' => array(
                'host' => 'Hostinger',
                'use'  => __('the checklist and guides for setting up a new site', 'seoprostack'),
            ),
        );
        /**
         * Plugins web hosts add to new sites, which the Plugins screen
         * recommends deactivating while they are active.
         *
         * @param array<string,array{host:string,use:string}> $plugins Plugin folder => host name and what it is for (plain text, completing "It is not needed to run your site, only for …").
         */
        $plugins = apply_filters('seoprostack_host_plugins', $plugins);
        return is_array($plugins) ? $plugins : array();
    }

    /**
     * Join a note to its plugin's row, as core does for update notes.
     */
    public static function row_note_style() {
        echo '<style>.plugins tr:has(+ tr.sps-host-row) th, .plugins tr:has(+ tr.sps-host-row) td { box-shadow: none; }</style>' . "\n";
    }

    /**
     * A note under an active host plugin's row.
     *
     * @param string $file Plugin file.
     */
    public static function row_note($file) {
        global $wp_list_table;
        $plugins = self::plugins();
        $slug    = dirname((string) $file);
        if (!isset($plugins[$slug]) || !is_array($plugins[$slug])) {
            return;
        }
        // Active where this screen can deactivate it: network-wide in the
        // network admin, on this site otherwise.
        $network = is_multisite() && is_network_admin();
        if ($network ? !is_plugin_active_for_network($file) : (!is_plugin_active($file) || is_plugin_active_for_network($file))) {
            return;
        }
        $host = isset($plugins[$slug]['host']) ? (string) $plugins[$slug]['host'] : '';
        $use  = isset($plugins[$slug]['use']) ? (string) $plugins[$slug]['use'] : '';

        /* translators: 1: web host name, 2: what the plugin is for */
        $text = sprintf(esc_html__('%1$s adds this plugin to new sites. It is not needed to run your site, only for %2$s. SEO Pro Stack recommends deactivating it if you do not use that, then you can delete it.', 'seoprostack'), esc_html($host), esc_html($use));
        if (current_user_can('deactivate_plugin', $file)) {
            $url   = wp_nonce_url(add_query_arg(array(
                'action'        => 'deactivate',
                'plugin'        => rawurlencode($file),
                'plugin_status' => 'all',
            ), self_admin_url('plugins.php')), 'deactivate-plugin_' . $file);
            $text .= sprintf(' <a href="%1$s">%2$s</a>', esc_url($url), esc_html__('Deactivate', 'seoprostack'));
        }
        $columns = ($wp_list_table instanceof WP_List_Table) ? $wp_list_table->get_column_count() : 4;
        printf(
            '<tr class="plugin-update-tr sps-host-row active"><td colspan="%1$d" class="plugin-update colspanchange"><div class="notice inline notice-info notice-alt"><p>%2$s</p></div></td></tr>',
            (int) $columns,
            $text // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
        );
    }
}
