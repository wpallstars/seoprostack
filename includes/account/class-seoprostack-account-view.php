<?php
/**
 * My Account without WooCommerce: the page around the forms (never page
 * cached, WooCommerce's body classes, the tab's title, the password script).
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

final class SEOProStack_Account_View {

    /** Script handle of the show-password buttons and strength meter. */
    const SCRIPT = 'seoprostack-account-password';

    /**
     * Register hooks (front end).
     */
    public static function boot() {
        add_action('template_redirect', array(__CLASS__, 'no_cache'), 0);
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('the_title', array(__CLASS__, 'page_title'), 10, 2);
        add_filter('document_title_parts', array(__CLASS__, 'document_title'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    /**
     * Page caches never keep the account page (it holds nonces and the
     * person's details), as with WooCommerce's.
     */
    public static function no_cache() {
        if (!SEOProStack_Account::is_account_page()) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- page caches' shared constant: never keep this page.
        }
        do_action('litespeed_control_set_nocache', 'seoprostack: account page'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's hook.
        nocache_headers();
    }

    /**
     * WooCommerce's body classes on the account page.
     *
     * @param mixed $classes Classes.
     * @return mixed
     */
    public static function body_class($classes) {
        if (!is_array($classes) || !SEOProStack_Account::is_account_page()) {
            return $classes;
        }
        $classes[] = 'woocommerce-account';
        $classes[] = 'woocommerce-page';
        $endpoint  = SEOProStack_Account_Endpoints::get_current();
        if ('' !== $endpoint) {
            $classes[] = 'woocommerce-' . sanitize_html_class($endpoint);
        } elseif (is_user_logged_in()) {
            $classes[] = 'woocommerce-dashboard';
        }
        return $classes;
    }

    /**
     * The current tab's title, or ''.
     *
     * @return string
     */
    private static function tab_title() {
        $endpoint = SEOProStack_Account_Endpoints::get_current();
        return '' !== $endpoint ? SEOProStack_Account_Endpoints::get_title($endpoint) : '';
    }

    /**
     * The page's title on a tab: the tab's title.
     *
     * @param mixed $title Title.
     * @param mixed $id    Post ID.
     * @return mixed
     */
    public static function page_title($title, $id = 0) {
        if (is_admin() || !in_the_loop() || !is_main_query() || (int) $id !== SEOProStack_Account::get_page_id() || !SEOProStack_Account::is_account_page()) {
            return $title;
        }
        $tab = self::tab_title();
        if ('' === $tab) {
            return $title;
        }
        remove_filter('the_title', array(__CLASS__, 'page_title'), 10);
        return $tab;
    }

    /**
     * The document title on a tab.
     *
     * @param mixed $parts Title parts.
     * @return mixed
     */
    public static function document_title($parts) {
        if (!is_array($parts) || !SEOProStack_Account::is_account_page()) {
            return $parts;
        }
        $tab = self::tab_title();
        if ('' !== $tab) {
            $parts['title'] = $tab;
        }
        return $parts;
    }

    /**
     * Whether this view has a field where a new password is chosen.
     *
     * @return bool
     */
    private static function choosing_password() {
        $endpoint = SEOProStack_Account_Endpoints::get_current();
        if (is_user_logged_in()) {
            return in_array($endpoint, array('edit-account', 'lost-password'), true);
        }
        return 'lost-password' === $endpoint || (SEOProStack_Account::signups_open() && !SEOProStack_Account_Customer::generates_password());
    }

    /**
     * WooCommerce's show-password buttons on the account page, and its
     * password strength meter where a password is chosen.
     */
    public static function enqueue() {
        if (!SEOProStack_Account::is_account_page()) {
            return;
        }
        $choosing = self::choosing_password();
        wp_enqueue_script(self::SCRIPT, SEOProStack::asset_url('assets/account-password.js'), $choosing ? array('password-strength-meter') : array(), SEOPROSTACK_VERSION, array('strategy' => 'defer', 'in_footer' => true));
        wp_localize_script(
            self::SCRIPT,
            'seoprostackAccountPassword',
            array(
                'meter' => $choosing,
                'show'  => __('Show password', 'seoprostack'),
                'hide'  => __('Hide password', 'seoprostack'),
                /**
                 * Filter the strength (0 to 4, from WordPress's meter) a new
                 * password on My Account needs before its form can be sent.
                 *
                 * @param int $strength Default 3 (medium), as WooCommerce.
                 */
                'min'   => (int) apply_filters('seoprostack_account_min_password_strength', 3),
                'error' => __('Please enter a stronger password.', 'seoprostack'),
                'hint'  => wp_get_password_hint(),
            )
        );
    }
}
