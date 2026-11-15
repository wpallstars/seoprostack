<?php
/**
 * My Account without WooCommerce: Fluent Support's Support tab.
 *
 * Fluent Support Pro shows its customer portal as a Support tab in
 * WooCommerce's My Account when its setting Customer Portal is WooCommerce
 * Account Navigation, but only while WooCommerce is loaded
 * (Helper::isPortalActive('woocommerce') needs WC_PLUGIN_FILE). Without
 * WooCommerce the tab goes, and its ticket emails link to its portal page,
 * which a site using the tab often does not have. This keeps the tab as
 * Fluent Support made it: the same endpoint (support-tickets) and title,
 * its [fluent_support_portal] in it, and its portal address pointing at it.
 * Replies, attachments, notifications and who may see which ticket stay
 * Fluent Support's.
 *
 * Stands aside when Ultimate Multisite Plus's Support in My Account already
 * serves the tab, so there is never a second tab or portal.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.220
 */

// WooCommerce's and Fluent Support's hook names, so the tab is theirs.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Account_Fluent_Support {

    /** Fluent Support's My Account endpoint (IntegrationInit::SUPPORT_ENDPOINT). */
    const ENDPOINT = 'support-tickets';

    /**
     * Request cache of active().
     *
     * @var bool|null
     */
    private static $active = null;

    /**
     * Register hooks (front end and admin: ticket emails use the portal address).
     */
    public static function boot() {
        add_filter('woocommerce_get_query_vars', array(__CLASS__, 'query_vars'));
        add_filter('woocommerce_account_menu_items', array(__CLASS__, 'menu_items'), 20);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array(__CLASS__, 'render'));
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', array(__CLASS__, 'title'));
        add_filter('fluent_support/portal_base_url', array(__CLASS__, 'portal_base_url'), 20);
    }

    /**
     * Whether this serves the tab: Fluent Support Pro is active, its portal
     * setting is WooCommerce, and Ultimate Multisite Plus is not serving it.
     *
     * @return bool
     */
    public static function active() {
        if (null !== self::$active) {
            return self::$active;
        }
        // Too early to know (before plugins load): not cached.
        if (!did_action('plugins_loaded')) {
            return false;
        }
        self::$active = defined('FLUENTSUPPORTPRO_PLUGIN_VERSION')
            && class_exists('\FluentSupport\App\Services\Helper')
            && 'woocommerce' === (string) \FluentSupport\App\Services\Helper::getBusinessSettings('ticket_link_portal', 'default')
            && !(class_exists('UltimateMultisitePlus_Account_Support') && UltimateMultisitePlus_Account_Support::active());
        return self::$active;
    }

    /**
     * The tab's endpoint.
     *
     * @param mixed $vars Endpoint key => slug.
     * @return mixed
     */
    public static function query_vars($vars) {
        if (is_array($vars) && !isset($vars[self::ENDPOINT]) && self::active()) {
            $vars[self::ENDPOINT] = self::ENDPOINT;
        }
        return $vars;
    }

    /**
     * Support in the menu, before Log out.
     *
     * @param mixed $items Endpoint key => label.
     * @return mixed
     */
    public static function menu_items($items) {
        if (!is_array($items) || isset($items[self::ENDPOINT]) || !self::active()) {
            return $items;
        }
        $support  = array(self::ENDPOINT => self::label());
        $position = array_search('customer-logout', array_keys($items), true);
        if (false === $position) {
            return $items + $support;
        }
        return array_slice($items, 0, (int) $position, true) + $support + array_slice($items, (int) $position, null, true);
    }

    /**
     * The tab: Fluent Support's portal (its own sign-in, permission checks
     * and scripts), as Fluent Support Pro renders it with WooCommerce.
     */
    public static function render() {
        if (!self::active() || !shortcode_exists('fluent_support_portal')) {
            return;
        }
        echo do_shortcode('[fluent_support_portal]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fluent Support's portal markup, escaped by Fluent Support.
    }

    /**
     * The tab's page title.
     *
     * @param mixed $title Title.
     * @return mixed
     */
    public static function title($title) {
        return self::active() ? self::label() : $title;
    }

    /**
     * Fluent Support's portal address: the tab, so ticket links in its
     * emails and its sign-in redirects open My Account.
     *
     * @param mixed $url Fluent Support's portal address.
     * @return mixed
     */
    public static function portal_base_url($url) {
        if (!self::active() || '' === SEOProStack_Account::page_url()) {
            return $url;
        }
        // Fluent Support appends "/#/ticket/…", so no trailing slash.
        return rtrim(SEOProStack_Account_Endpoints::url(self::ENDPOINT), '/\\');
    }

    /**
     * The tab's name.
     *
     * @return string
     */
    private static function label() {
        return __('Support', 'seoprostack');
    }
}
