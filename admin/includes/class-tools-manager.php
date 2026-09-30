<?php
/**
 * SEO Pro Stack Tools tab.
 *
 * @package SEOProStack
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Tools_Manager {

    /**
     * Tool data (admin/data/tools.php).
     *
     * @return array
     */
    public static function get_tools() {
        return seoprostack_get_tools();
    }

    /**
     * Render the tab.
     */
    public static function display_tab_content() {
        SEOProStack_Link_Cards::render(self::get_tools(), 'tools', array(
            'intro'        => __('Apps and services for SEO, content and site operations.', 'seoprostack'),
            'search_label' => __('Filter tools…', 'seoprostack'),
        ));
    }
}
