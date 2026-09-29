<?php
/**
 * Allstars Theme tab (Kadence).
 *
 * Theme data is fetched from wordpress.org and cached. Installation uses
 * core's wp.updates.installTheme(); activation uses core's nonce'd URL.
 *
 * @package Allstars
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Allstars_Theme_Manager {

    /** Recommended theme slug. */
    const SLUG = 'kadence';

    /** Cache key. */
    const CACHE_KEY = 'allstars_theme_kadence';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('wp_ajax_allstars_get_themes', array(__CLASS__, 'ajax_get_themes'));
        add_action('switch_theme', array(__CLASS__, 'clear_theme_cache'));
    }

    /**
     * Render the tab shell; the card loads via AJAX.
     */
    public static function display_tab_content() {
        ?>
        <div class="wpa-section">
            <div class="wpa-section__intro">
                <h2 class="wpa-section__title"><?php esc_html_e('Recommended theme', 'allstars'); ?></h2>
                <p class="wpa-section__desc"><?php esc_html_e('Kadence is a fast, flexible block theme with a large starter template library.', 'allstars'); ?></p>
            </div>
            <div id="wpa-theme" data-wpa-theme aria-live="polite" aria-busy="true">
                <div class="wpa-loading"><span class="spinner is-active"></span> <?php esc_html_e('Loading theme…', 'allstars'); ?></div>
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
        check_ajax_referer(Allstars_Settings::NONCE, 'nonce');

        if (!current_user_can('install_themes') && !current_user_can('switch_themes')) {
            wp_send_json_error(array('message' => __('You are not allowed to manage themes on this site.', 'allstars')), 403);
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
        include ALLSTARS_DIR . 'admin/partials/theme-panel.php';
        wp_send_json_success(array('html' => ob_get_clean()));
    }
}
