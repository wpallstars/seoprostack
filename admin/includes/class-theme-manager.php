<?php
/**
 * SEO Pro Stack Theme tab (Kadence).
 *
 * Theme data is fetched from wordpress.org and cached. Installation uses
 * core's wp.updates.installTheme(); activation uses core's nonce'd URL.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2025 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Theme_Manager {

    /** Recommended theme slug. */
    const SLUG = 'kadence';

    /** Cache key. */
    const CACHE_KEY = 'seoprostack_theme_kadence';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('wp_ajax_seoprostack_get_themes', array(__CLASS__, 'ajax_get_themes'));
        add_action('switch_theme', array(__CLASS__, 'clear_theme_cache'));
    }

    /**
     * Render the tab shell; the card loads via AJAX.
     */
    public static function display_tab_content() {
        ?>
        <div class="sps-section">
            <div class="sps-section__intro">
                <h2 class="sps-section__title"><?php esc_html_e('Recommended theme', 'seoprostack'); ?></h2>
                <p class="sps-section__desc"><?php esc_html_e('Kadence is a fast, flexible block theme with a large starter template library.', 'seoprostack'); ?></p>
            </div>
            <div id="sps-theme" data-sps-theme aria-live="polite" aria-busy="true">
                <div class="sps-loading"><span class="spinner is-active"></span> <?php esc_html_e('Loading theme…', 'seoprostack'); ?></div>
            </div>
        </div>
        <?php
    }

    /**
     * Clear cached theme data.
     */
    public static function clear_theme_cache() {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * AJAX: render the theme card.
     */
    public static function ajax_get_themes() {
        check_ajax_referer(SEOProStack_Settings::NONCE, 'nonce');

        if (!current_user_can('install_themes') && !current_user_can('switch_themes')) {
            wp_send_json_error(array('message' => __('You are not allowed to manage themes on this site.', 'seoprostack')), 403);
        }

        $theme_data = get_transient(self::CACHE_KEY);
        if (empty($theme_data)) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
            $theme_data = themes_api('theme_information', array(
                'slug'   => self::SLUG,
                'fields' => array(
                    'sections'       => false,
                    'tags'           => false,
                    'screenshot_url' => true,
                    'preview_url'    => true,
                    'rating'         => true,
                    'active_installs' => true,
                ),
            ));

            if (is_wp_error($theme_data)) {
                wp_send_json_error(array('message' => $theme_data->get_error_message()), 502);
            }
            set_transient(self::CACHE_KEY, $theme_data, 12 * HOUR_IN_SECONDS);
        }

        $author = '';
        if (is_string($theme_data->author)) {
            $author = $theme_data->author;
        } elseif (is_array($theme_data->author) && isset($theme_data->author['display_name'])) {
            $author = $theme_data->author['display_name'];
        }

        ob_start();
        include SEOPROSTACK_DIR . 'admin/partials/theme-panel.php';
        wp_send_json_success(array('html' => ob_get_clean()));
    }
}
