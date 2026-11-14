<?php
/**
 * My Account without WooCommerce: My Account as the static front page,
 * with its tabs at the site's root (WC_Query::pre_get_posts()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.218
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Account_Front_Page {

    /**
     * When My Account is the static front page, /edit-account/ and the
     * other tabs are at the site's root, where WordPress shows the posts
     * page: show My Account, as WC_Query::pre_get_posts() does.
     *
     * @param WP_Query $query Query.
     */
    public static function adjust($query) {
        if (!$query instanceof WP_Query || !$query->is_main_query() || !$query->is_home() || $query->is_posts_page) {
            return;
        }
        $front = self::account_page();
        if ($front < 1 || !self::asks_tab($query)) {
            return;
        }
        $query->is_page     = true;
        $query->is_home     = false;
        $query->is_singular = true;
        $query->set('page_id', $front);
        add_filter('redirect_canonical', '__return_false');
    }

    /**
     * The account page's ID when it is the static front page, else 0.
     *
     * @return int
     */
    private static function account_page() {
        $front = 'page' === get_option('show_on_front') ? (int) get_option('page_on_front') : 0;
        return ($front > 0 && SEOProStack_Account::get_page_id() === $front) ? $front : 0;
    }

    /**
     * Whether the query names a tab (by its key or its slug).
     *
     * @param WP_Query $query Query.
     * @return bool
     */
    private static function asks_tab(WP_Query $query) {
        $vars  = SEOProStack_Account_Endpoints::get_all();
        $asked = is_array($query->query) ? array_keys($query->query) : array();
        return array() !== array_intersect($asked, array_merge(array_keys($vars), array_values($vars)));
    }
}
