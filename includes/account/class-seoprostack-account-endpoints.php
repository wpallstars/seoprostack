<?php
/**
 * My Account without WooCommerce: the tabs (endpoints), with WooCommerce's
 * slugs and filters, their addresses and titles.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.218
 */

// WooCommerce's hook names, so code written for them works.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Account_Endpoints {

    /** Option: the endpoints in the rewrite rules (a hash). */
    const FLUSHED = 'seoprostack_account_endpoints';

    /**
     * Register hooks (front end and admin).
     */
    public static function boot() {
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('init', array(__CLASS__, 'add'), 10);
        add_action('init', array(__CLASS__, 'maybe_flush'), 99);
        add_action('parse_request', array(__CLASS__, 'parse_request'), 0);
        add_action('pre_get_posts', array('SEOProStack_Account_Front_Page', 'adjust'));
    }

    /**
     * Endpoints, key => address slug: WooCommerce's account details, log
     * out and lost password, with WooCommerce's slugs, plus those other
     * plugins add with woocommerce_get_query_vars.
     *
     * @return array<string,string>
     */
    public static function get_all() {
        $vars = array(
            'edit-account'    => (string) get_option('woocommerce_myaccount_edit_account_endpoint', 'edit-account'),
            'customer-logout' => (string) get_option('woocommerce_logout_endpoint', 'customer-logout'),
            'lost-password'   => (string) get_option('woocommerce_myaccount_lost_password_endpoint', 'lost-password'),
        );
        $vars = apply_filters('woocommerce_get_query_vars', $vars);
        $out  = array();
        foreach (is_array($vars) ? $vars : array() as $key => $slug) {
            $key  = (string) $key;
            $slug = (string) $slug;
            if ('' !== $key && '' !== $slug && preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
                $out[$key] = $slug;
            }
        }
        return $out;
    }

    /**
     * The endpoints' address slugs as query variables.
     *
     * @param mixed $vars Public query variables.
     * @return mixed
     */
    public static function query_vars($vars) {
        if (is_array($vars)) {
            foreach (self::get_all() as $key => $slug) {
                $vars[] = $key;
                $vars[] = $slug;
            }
            $vars = array_values(array_unique($vars));
        }
        return $vars;
    }

    /**
     * Add the endpoints' addresses (/my-account/edit-account/).
     */
    public static function add() {
        foreach (self::get_all() as $slug) {
            add_rewrite_endpoint($slug, EP_ROOT | EP_PAGES);
        }
    }

    /**
     * Put the endpoints in the rewrite rules when they change, on a request
     * that loads every plugin.
     */
    public static function maybe_flush() {
        if (SEOProStack_Account::plugins_skipped()) {
            return;
        }
        $hash = hash('sha256', SEOPROSTACK_VERSION . '|' . wp_json_encode(self::get_all()));
        if (get_option(self::FLUSHED) === $hash) {
            return;
        }
        update_option(self::FLUSHED, $hash, false);
        flush_rewrite_rules(false);
    }

    /**
     * Copy each endpoint's value to its key, as WooCommerce does, so code
     * reading $wp->query_vars['edit-account'] works whatever the slug.
     *
     * @param WP $wp Request.
     */
    public static function parse_request($wp) {
        foreach (self::get_all() as $key => $slug) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an address without pretty permalinks; read as text.
            if (isset($_GET[$slug])) {
                $wp->query_vars[$key] = sanitize_text_field(wp_unslash((string) $_GET[$slug])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the same.
            } elseif (isset($wp->query_vars[$slug])) {
                $wp->query_vars[$key] = $wp->query_vars[$slug];
            }
        }
    }

    /**
     * The tab being viewed (its key), or '' for the dashboard.
     *
     * @return string
     */
    public static function get_current() {
        global $wp;
        if (!$wp instanceof WP) {
            return '';
        }
        foreach (array_keys(self::get_all()) as $key) {
            if (isset($wp->query_vars[$key])) {
                return $key;
            }
        }
        return '';
    }

    /**
     * The value after the tab's slug in the address (/tab/value/), or ''.
     *
     * @return string
     */
    public static function get_value() {
        global $wp;
        $key = self::get_current();
        return ('' !== $key && $wp instanceof WP) ? (string) $wp->query_vars[$key] : '';
    }

    /**
     * Address of an endpoint, as wc_get_endpoint_url() makes it, through
     * woocommerce_get_endpoint_url.
     *
     * @param string $endpoint  Endpoint key.
     * @param string $value     Value after the slug.
     * @param string $permalink Page address; the account page by default.
     * @return string
     */
    public static function url($endpoint, $value = '', $permalink = '') {
        $endpoint  = (string) $endpoint;
        $value     = (string) $value;
        $permalink = '' !== (string) $permalink ? (string) $permalink : SEOProStack_Account::page_url();
        $vars      = self::get_all();
        $slug      = isset($vars[$endpoint]) ? $vars[$endpoint] : $endpoint;
        $url       = get_option('permalink_structure') ? self::pretty_url($permalink, $slug, $value) : add_query_arg($slug, $value, $permalink);
        return (string) apply_filters('woocommerce_get_endpoint_url', $url, $endpoint, $value, $permalink);
    }

    /**
     * An endpoint's address with pretty permalinks (page/slug/value/), the
     * page address's query string kept at the end.
     *
     * @param string $permalink Page address.
     * @param string $slug      Endpoint slug.
     * @param string $value     Value after the slug.
     * @return string
     */
    private static function pretty_url($permalink, $slug, $value) {
        $query = '';
        if (false !== strpos($permalink, '?')) {
            $query     = '?' . (string) wp_parse_url($permalink, PHP_URL_QUERY);
            $permalink = current(explode('?', $permalink));
        }
        $path = '' !== $value ? trailingslashit($slug) . user_trailingslashit($value) : user_trailingslashit($slug);
        return trailingslashit($permalink) . $path . $query;
    }

    /**
     * A tab's title (woocommerce_endpoint_{endpoint}_title).
     *
     * @param string $endpoint Endpoint key.
     * @return string
     */
    public static function get_title($endpoint) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chooses a title only.
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
        $titles = array(
            'edit-account'    => __('Account details', 'seoprostack'),
            'customer-logout' => __('Log out', 'seoprostack'),
            'lost-password'   => in_array($action, array('rp', 'resetpass', 'newaccount'), true) ? __('Set password', 'seoprostack') : __('Lost password', 'seoprostack'),
        );
        $title = isset($titles[$endpoint]) ? $titles[$endpoint] : '';
        return (string) apply_filters('woocommerce_endpoint_' . $endpoint . '_title', $title, $endpoint, $action);
    }
}
