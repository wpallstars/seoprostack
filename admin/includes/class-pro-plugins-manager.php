<?php
/**
 * SEO Pro Stack Pro Plugins tab.
 *
 * @package SEOProStack
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Pro_Plugins_Manager {

    /**
     * Pro plugin data (admin/data/pro-plugins.php).
     *
     * @return array
     */
    public static function get_pro_plugins() {
        return seoprostack_get_pro_plugins();
    }

    /**
     * Render the tab.
     */
    public static function display_tab_content() {
        SEOProStack_Link_Cards::render(self::get_pro_plugins(), 'pro', array(
            'intro'        => __('Premium upgrades we rely on. Badges show when the free version is already on this site.', 'seoprostack'),
            'search_label' => __('Filter pro plugins…', 'seoprostack'),
        ));
    }
}
