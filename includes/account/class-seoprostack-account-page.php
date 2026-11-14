<?php
/**
 * My Account without WooCommerce: which page is My Account
 * (SEOProStack_Account::get_page_id()).
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

final class SEOProStack_Account_Page {

    /** Transient: the account page found by its shortcode ('0': none). */
    const CACHE = 'seoprostack_account_page';

    /**
     * WooCommerce's My account page while it is published, else the first
     * published page with the shortcode; through seoprostack_account_page.
     *
     * @return int
     */
    public static function get_id() {
        $id = (int) get_option('woocommerce_myaccount_page_id');
        if ($id < 1 || 'publish' !== get_post_status($id) || 'page' !== get_post_type($id)) {
            $id = self::found();
        }
        return max(0, (int) apply_filters('seoprostack_account_page', $id));
    }

    /**
     * The page with the shortcode, kept for a day.
     *
     * @return int
     */
    private static function found() {
        $cached = get_transient(self::CACHE);
        if (false === $cached) {
            $cached = (string) self::search();
            set_transient(self::CACHE, $cached, DAY_IN_SECONDS);
        }
        return (int) $cached;
    }

    /**
     * The first published page with either shortcode, or 0.
     *
     * @return int
     */
    private static function search() {
        foreach (array(SEOProStack_Account::SHORTCODE, SEOProStack_Account::WC_SHORTCODE) as $code) {
            $ids = get_posts(
                array(
                    'post_type'   => 'page',
                    'post_status' => 'publish',
                    's'           => '[' . $code,
                    'fields'      => 'ids',
                    'numberposts' => 5,
                    'orderby'     => 'ID',
                    'order'       => 'ASC',
                )
            );
            foreach ($ids as $found) {
                if (has_shortcode((string) get_post_field('post_content', (int) $found), $code)) {
                    return (int) $found;
                }
            }
        }
        return 0;
    }

    /**
     * A page changed: look for the account page again.
     */
    public static function forget() {
        delete_transient(self::CACHE);
    }
}
