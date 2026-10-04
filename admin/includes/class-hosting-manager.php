<?php
/**
 * SEO Pro Stack Hosting tab.
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

class SEOProStack_Hosting_Manager {

    /**
     * Hosting provider data (admin/data/hosting-providers.php).
     *
     * @return array
     */
    public static function get_hosting_providers() {
        return seoprostack_get_hosting_providers();
    }

    /**
     * Render the tab.
     */
    public static function display_tab_content() {
        SEOProStack_Link_Cards::render(self::get_hosting_providers(), 'hosting', array(
            'intro'        => __('Hosting, DNS, domains and monitoring services we recommend.', 'seoprostack'),
            'search_label' => __('Filter providers…', 'seoprostack'),
        ));
    }
}
