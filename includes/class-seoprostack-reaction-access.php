<?php
/**
 * Where people reach their lists, for Like, save and share.
 *
 * - Your lists in a modal (setting): links to the page holding the Your
 *   lists block, such as a header's Shortlist button, open the lists in a
 *   modal over the page. The modal is printed once in the footer, empty
 *   (the script fills it), so pages stay cacheable; the page still opens
 *   on its own, and without the script.
 * - WooCommerce My Account (setting): a "Your lists" tab with the same box,
 *   for logged-in people.
 *
 * Log in to like and save (the third setting) is the script's: a visitor's
 * Like, Save or Add to shortlist opens the theme's login modal instead.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.68
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Reaction_Access {

    /** Transient: the published page with the Your lists block ('0': none). */
    const PAGE_CACHE = 'seoprostack_lists_page';

    /** WooCommerce My Account endpoint. */
    const ENDPOINT = 'lists';

    /** Option: plugin version whose My Account endpoint is in the rewrite rules. */
    const FLUSHED = 'seoprostack_lists_endpoint';

    /** The modal's ID. */
    const MODAL_ID = 'sps-lists-modal';

    /**
     * Register hooks for the settings that are on.
     *
     * @param bool $modal   Your lists in a modal.
     * @param bool $account WooCommerce My Account tab.
     */
    public static function boot($modal, $account) {
        add_action('save_post_page', array(__CLASS__, 'forget_page'));
        add_action('deleted_post', array(__CLASS__, 'forget_page'));
        if ($modal) {
            // Before plugins that print a dialog for the actions in it.
            add_action('wp_footer', array(__CLASS__, 'print_modal'), 1);
        }
        if ($account) {
            add_filter('woocommerce_get_query_vars', array(__CLASS__, 'query_vars'));
            add_filter('woocommerce_account_menu_items', array(__CLASS__, 'menu_items'));
            add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', array(__CLASS__, 'title'));
            add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array(__CLASS__, 'account_tab'));
            add_action('init', array(__CLASS__, 'flush_once'), 99);
        }
    }

    /**
     * The published page with the Your lists block (the first made), or 0.
     *
     * Filter: `seoprostack_lists_page` (a page ID, or 0 for none).
     *
     * @return int
     */
    public static function page_id() {
        $cached = get_transient(self::PAGE_CACHE);
        if (false === $cached) {
            $cached = '0';
            $ids = get_posts(
                array(
                    'post_type'        => 'page',
                    'post_status'      => 'publish',
                    's'                => 'wp:seoprostack/lists',
                    'fields'           => 'ids',
                    'numberposts'      => 5,
                    'orderby'          => 'ID',
                    'order'            => 'ASC',
                )
            );
            foreach ($ids as $id) {
                if (has_block('seoprostack/lists', (int) $id)) {
                    $cached = (string) (int) $id;
                    break;
                }
            }
            set_transient(self::PAGE_CACHE, $cached, DAY_IN_SECONDS);
        }
        return (int) apply_filters('seoprostack_lists_page', (int) $cached);
    }

    /**
     * The lists page's address, or ''.
     *
     * @return string
     */
    public static function page_url() {
        $id = self::page_id();
        return $id ? (string) get_permalink($id) : '';
    }

    /**
     * A page changed: look for the lists page again.
     */
    public static function forget_page() {
        delete_transient(self::PAGE_CACHE);
    }

    /**
     * The modal, with the lists page's title, in the footer of other pages.
     */
    public static function print_modal() {
        $id = self::page_id();
        if (!$id || is_page($id) || is_admin() || is_feed() || is_embed()) {
            return;
        }
        $title = wp_strip_all_tags(get_the_title($id));
        $html = '<dialog class="sps-panel sps-panel--modal" data-sps-panel data-sps-lists-modal id="' . esc_attr(self::MODAL_ID) . '" aria-labelledby="' . esc_attr(self::MODAL_ID . '-title') . '">'
            . '<div class="sps-panel__head"><h2 class="sps-panel__title" id="' . esc_attr(self::MODAL_ID . '-title') . '">' . esc_html('' !== $title ? $title : __('Your lists', 'seoprostack')) . '</h2>'
            . '<button type="button" class="sps-panel__close" data-sps-panel-close aria-label="' . esc_attr__('Close', 'seoprostack') . '">'
            . '<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></div>'
            . SEOProStack_Post_Reactions::lists_markup('modal')
            . '</dialog>';
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped above or by lists_markup().
    }

    /**
     * WooCommerce's My Account endpoints, with Your lists.
     *
     * @param mixed $vars Endpoint => slug.
     * @return mixed
     */
    public static function query_vars($vars) {
        if (is_array($vars)) {
            $vars[self::ENDPOINT] = self::ENDPOINT;
        }
        return $vars;
    }

    /**
     * My Account's menu, with Your lists before Log out.
     *
     * @param mixed $items Endpoint => label.
     * @return mixed
     */
    public static function menu_items($items) {
        if (!is_array($items)) {
            return $items;
        }
        $logout = array();
        if (isset($items['customer-logout'])) {
            $logout = array('customer-logout' => $items['customer-logout']);
            unset($items['customer-logout']);
        }
        $items[self::ENDPOINT] = self::title();
        return $items + $logout;
    }

    /**
     * The tab's title.
     *
     * @return string
     */
    public static function title() {
        $id = self::page_id();
        $title = $id ? wp_strip_all_tags(get_the_title($id)) : '';
        return '' !== $title ? $title : __('Your lists', 'seoprostack');
    }

    /**
     * The tab: every list.
     */
    public static function account_tab() {
        echo SEOProStack_Post_Reactions::lists_markup('account'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by lists_markup().
    }

    /**
     * Once a version, after WooCommerce adds its endpoints: put My Account's
     * Your lists address in the rewrite rules.
     */
    public static function flush_once() {
        if (!function_exists('WC') || get_option(self::FLUSHED) === SEOPROSTACK_VERSION) {
            return;
        }
        update_option(self::FLUSHED, SEOPROSTACK_VERSION, false);
        flush_rewrite_rules(false);
    }
}
