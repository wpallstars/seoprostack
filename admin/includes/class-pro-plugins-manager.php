<?php
/**
 * WP Allstars Pro Plugins tab.
 *
 * @package WP_ALLSTARS
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_Allstars_Pro_Plugins_Manager {

    /**
     * Pro plugin data (admin/data/pro-plugins.php).
     *
     * @return array
     */
    public static function get_pro_plugins() {
        return wp_allstars_get_pro_plugins();
    }

    /**
     * Render the tab.
     */
    public static function display_tab_content() {
        WP_Allstars_Link_Cards::render(self::get_pro_plugins(), 'pro', array(
            'intro'        => __('Premium upgrades we rely on. Badges show when the free version is already on this site.', 'wp-allstars'),
            'search_label' => __('Filter pro plugins…', 'wp-allstars'),
        ));
    }
}
