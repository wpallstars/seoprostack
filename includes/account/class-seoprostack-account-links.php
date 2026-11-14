<?php
/**
 * My Account without WooCommerce: the menu, its addresses, log out and the
 * lost-password address WordPress links to.
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

final class SEOProStack_Account_Links {

    /**
     * Register hooks (front end and admin).
     */
    public static function boot() {
        add_filter('lostpassword_url', array(__CLASS__, 'lostpassword_url'), 10, 1);
        add_action('template_redirect', array(__CLASS__, 'maybe_logout'), 10);
    }

    /**
     * Address of a menu item, as wc_get_account_endpoint_url() makes it.
     *
     * @param string $endpoint Endpoint key.
     * @return string
     */
    public static function menu_url($endpoint) {
        if ('dashboard' === $endpoint) {
            return SEOProStack_Account::page_url();
        }
        $url = SEOProStack_Account_Endpoints::url($endpoint);
        return 'customer-logout' === $endpoint ? wp_nonce_url($url, 'customer-logout') : $url;
    }

    /**
     * The menu: endpoint => label, through woocommerce_account_menu_items.
     *
     * @return array<string,string>
     */
    public static function get_menu_items() {
        $items = array(
            'dashboard'       => __('Dashboard', 'seoprostack'),
            'edit-account'    => __('Account details', 'seoprostack'),
            'customer-logout' => __('Log out', 'seoprostack'),
        );
        $vars  = SEOProStack_Account_Endpoints::get_all();
        foreach (array_keys($items) as $key) {
            if ('dashboard' !== $key && !isset($vars[$key])) {
                unset($items[$key]);
            }
        }
        $items = apply_filters('woocommerce_account_menu_items', $items, $vars);
        $out   = array();
        foreach (is_array($items) ? $items : array() as $key => $label) {
            $out[(string) $key] = (string) $label;
        }
        return $out;
    }

    /**
     * The Confirm and log out address, as wc_logout_url() makes it.
     *
     * @return string
     */
    public static function logout_url() {
        return wp_logout_url(self::logout_redirect_url());
    }

    /**
     * Where logging out goes (wc_get_logout_redirect_url()).
     *
     * @return string
     */
    public static function logout_redirect_url() {
        return (string) apply_filters('woocommerce_logout_default_redirect_url', SEOProStack_Account::page_url());
    }

    /**
     * WordPress's lost-password links go to My Account's form, as with
     * WooCommerce (not on wp-login.php's own form, nor for Network Admin).
     *
     * @param mixed $url Default address.
     * @return mixed
     */
    public static function lostpassword_url($url) {
        if (!did_action('init') || did_action('login_form_login')) {
            return $url;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared.
        $to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash((string) $_GET['redirect_to'])) : '';
        if (is_multisite() && '' !== $to && false !== strpos($to, network_admin_url())) {
            return $url;
        }
        return SEOProStack_Account_Endpoints::url('lost-password');
    }

    /**
     * Log out: with its nonce, log out and go on; without (an old or
     * typed link), ask on My Account's dashboard, as wc_template_redirect().
     */
    public static function maybe_logout() {
        if (!SEOProStack_Account::is_account_page() || 'customer-logout' !== SEOProStack_Account_Endpoints::get_current()) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read to be checked.
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_key(wp_unslash((string) $_REQUEST['_wpnonce'])) : '';
        if ('' !== $nonce && wp_verify_nonce($nonce, 'customer-logout')) {
            $to = self::logout_redirect_url();
            wp_logout();
            wp_safe_redirect($to);
            exit;
        }
        SEOProStack_Account_Notices::add(sprintf(
            /* translators: %s: logout address */
            __('Are you sure you want to log out? <a href="%s">Confirm and log out</a>', 'seoprostack'),
            esc_url(self::logout_url())
        ));
        wp_safe_redirect(SEOProStack_Account::page_url());
        exit;
    }
}
