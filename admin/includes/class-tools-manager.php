<?php
/**
 * Allstars Tools tab.
 *
 * @package Allstars
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Allstars_Tools_Manager {

    /**
     * Tool data (admin/data/tools.php).
     *
     * @return array
     */
    public static function get_tools() {
        return allstars_get_tools();
    }

    /**
     * Render the tab.
     */
    public static function display_tab_content() {
        Allstars_Link_Cards::render(self::get_tools(), 'tools', array(
            'intro'        => __('Apps and services for SEO, content and site operations.', 'allstars'),
            'search_label' => __('Filter tools…', 'allstars'),
        ));
    }
}
