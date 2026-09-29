<?php
/**
 * WP Allstars Hosting tab.
 *
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Hosting_Manager {

    /**
     * Hosting provider data (admin/data/hosting-providers.php).
     *
     * @return array
     */
    public static function get_hosting_providers() {
        return wp_allstars_get_hosting_providers();
    }

    /**
     * Render the tab.
     */
    public static function display_tab_content() {
        WP_Allstars_Link_Cards::render(self::get_hosting_providers(), 'hosting', array(
            'intro'        => __('Hosting, DNS, domains and monitoring services we recommend.', 'wp-allstars'),
            'search_label' => __('Filter providers…', 'wp-allstars'),
        ));
    }
}
