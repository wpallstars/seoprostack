<?php
/**
 * Plugins screen: plugins whose job WordPress now does itself.
 *
 * Some feature plugins were merged into WordPress, such as Performant
 * Translations in WordPress 6.5. On that version or later, a note under the
 * plugin's row says so and what, if anything, the plugin still adds:
 * - active: recommends deactivating it unless that is needed, with core's
 *   Deactivate link;
 * - inactive on a single site: says it can be deleted, with a Delete link.
 * The owner decides: nothing is deactivated or deleted here.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.105
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Core_Plugins {

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
     * Plugins merged into WordPress, keyed by plugin folder.
     *
     * @return array<string,array{wp:string,still?:string,languages?:bool}> folder => the WordPress version that does its job, what the plugin still adds (plain text) and whether that only matters on sites in another language.
     */
    public static function plugins() {
        $plugins = array(
            'performant-translations' => array(
                'wp'        => '6.5',
                'still'     => __('converting the translation files of plugins and themes that do not get them from WordPress.org, such as commercial ones', 'seoprostack'),
                'languages' => true,
            ),
        );
        /**
         * Plugins merged into WordPress, which the Plugins screen recommends
         * deactivating and deleting on that version or later.
         *
         * @param array<string,array{wp:string,still?:string,languages?:bool}> $plugins Plugin folder => `wp`: the WordPress version that does its job; `still`: what the plugin still adds (plain text, completing "It now only helps with …"), or empty; `languages`: true when that only matters on sites in another language.
         */
        $plugins = apply_filters('seoprostack_core_plugins', $plugins);
        return is_array($plugins) ? $plugins : array();
    }

    /**
     * Join a note to its plugin's row, as core does for update notes.
     */
    public static function row_note_style() {
        echo '<style>.plugins tr:has(+ tr.sps-core-row) th, .plugins tr:has(+ tr.sps-core-row) td { box-shadow: none; }</style>' . "\n";
    }

    /**
     * Whether the site and its people can only use English (United States):
     * no other language is installed.
     *
     * @return bool
     */
    private static function english_only() {
        return 'en_US' === get_locale() && !get_available_languages();
    }

    /**
     * A note under the row of a plugin WordPress now does the job of.
     *
     * @param string $file Plugin file.
     */
    public static function row_note($file) {
        global $wp_list_table;
        $plugins = self::plugins();
        $slug    = dirname((string) $file);
        if (!isset($plugins[$slug]) || !is_array($plugins[$slug]) || empty($plugins[$slug]['wp'])) {
            return;
        }
        $wp = (string) $plugins[$slug]['wp'];
        if (version_compare(get_bloginfo('version'), $wp, '<')) {
            return;
        }
        $still = isset($plugins[$slug]['still']) ? (string) $plugins[$slug]['still'] : '';
        if ('' !== $still && !empty($plugins[$slug]['languages']) && self::english_only()) {
            $still = '';
        }

        // Active where this screen can deactivate it: network-wide in the
        // network admin, on this site otherwise.
        $network = is_multisite() && is_network_admin();
        $active  = $network ? is_plugin_active_for_network($file) : (is_plugin_active($file) && !is_plugin_active_for_network($file));
        if ($active) {
            if ('' === $still) {
                /* translators: %s: WordPress version */
                $text = sprintf(esc_html__('WordPress %s and later does this plugin’s job, so it is no longer needed. SEO Pro Stack recommends deactivating it, then you can delete it.', 'seoprostack'), esc_html($wp));
            } else {
                /* translators: 1: WordPress version, 2: what the plugin still adds */
                $text = sprintf(esc_html__('WordPress %1$s and later does most of this plugin’s job. It now only helps with %2$s. SEO Pro Stack recommends deactivating it if you do not need that, then you can delete it.', 'seoprostack'), esc_html($wp), esc_html($still));
            }
            if (current_user_can('deactivate_plugin', $file)) {
                $url   = wp_nonce_url(add_query_arg(array(
                    'action'        => 'deactivate',
                    'plugin'        => rawurlencode($file),
                    'plugin_status' => 'all',
                ), self_admin_url('plugins.php')), 'deactivate-plugin_' . $file);
                $text .= sprintf(' <a href="%1$s">%2$s</a>', esc_url($url), esc_html__('Deactivate', 'seoprostack'));
            }
        } elseif (!is_multisite() && !is_plugin_active($file)) {
            // On a network another site may use it.
            /* translators: %s: WordPress version */
            $text = sprintf(esc_html__('WordPress %s and later does this plugin’s job. You can delete it.', 'seoprostack'), esc_html($wp));
            if (current_user_can('delete_plugins')) {
                $url   = wp_nonce_url(add_query_arg(array('action' => 'delete-selected', 'checked[]' => $file, 'plugin_status' => 'all'), self_admin_url('plugins.php')), 'bulk-plugins');
                $text .= sprintf(' <a href="%1$s">%2$s</a>', esc_url($url), esc_html__('Delete', 'seoprostack'));
            }
        } else {
            return;
        }
        $columns = ($wp_list_table instanceof WP_List_Table) ? $wp_list_table->get_column_count() : 4;
        printf(
            '<tr class="plugin-update-tr sps-core-row%1$s"><td colspan="%2$d" class="plugin-update colspanchange"><div class="notice inline notice-info notice-alt"><p>%3$s</p></div></td></tr>',
            $active ? ' active' : ' inactive',
            (int) $columns,
            $text // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
        );
    }
}
