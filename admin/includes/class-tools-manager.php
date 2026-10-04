<?php
/**
 * SEO Pro Stack Tools tab.
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
