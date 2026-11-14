<?php
/**
 * My Account without WooCommerce: logging in (WC_Form_Handler::process_login()).
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

final class SEOProStack_Account_Login {

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('wp_loaded', array(__CLASS__, 'process'), 20);
    }

    /**
     * Log in.
     */
    public static function process() {
        if (!isset($_POST['login'], $_POST['username'], $_POST['password'], $_POST['woocommerce-login-nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash((string) $_POST['woocommerce-login-nonce'])), 'woocommerce-login')) {
            return;
        }
        $creds = array(
            // Not sanitize_user(): it drops the + of an email address.
            'user_login'    => trim(sanitize_text_field(wp_unslash((string) $_POST['username']))),
            'user_password' => (string) $_POST['password'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- a password, as WordPress and WooCommerce take it (slashed), so passwords they saved keep working.
            'remember'      => isset($_POST['rememberme']),
        );
        $error = apply_filters('woocommerce_process_login_errors', new WP_Error(), $creds['user_login'], $creds['user_password']);
        if ($error instanceof WP_Error && $error->get_error_code()) {
            self::failed('<strong>' . esc_html__('Error:', 'seoprostack') . '</strong> ' . $error->get_error_message());
            return;
        }
        if ('' === $creds['user_login']) {
            self::failed('<strong>' . esc_html__('Error:', 'seoprostack') . '</strong> ' . esc_html__('Username is required.', 'seoprostack'));
            return;
        }
        self::join_site($creds['user_login']);
        $user = wp_signon(apply_filters('woocommerce_login_credentials', $creds), is_ssl());
        if (is_wp_error($user)) {
            self::failed($user->get_error_message());
            return;
        }
        $redirect = remove_query_arg('wc_error', self::get_redirect());
        wp_redirect(wp_validate_redirect((string) apply_filters('woocommerce_login_redirect', $redirect, $user), SEOProStack_Account::page_url())); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated with wp_validate_redirect().
        exit;
    }

    /**
     * On a network, an account from another site joins this one, as with
     * WooCommerce.
     *
     * @param string $login Username or email address.
     */
    private static function join_site($login) {
        if (!is_multisite()) {
            return;
        }
        $found = get_user_by(is_email($login) ? 'email' : 'login', $login);
        if ($found && !is_user_member_of_blog($found->ID, get_current_blog_id())) {
            add_user_to_blog(get_current_blog_id(), $found->ID, SEOProStack_Account_Customer::get_role());
        }
    }

    /**
     * A login error.
     *
     * @param string $message HTML.
     */
    private static function failed($message) {
        SEOProStack_Account_Notices::add((string) apply_filters('login_errors', $message), 'error'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's filter.
        do_action('woocommerce_login_failed');
    }

    /**
     * Where to go after logging in or signing up: the posted address, the
     * page the form was on, or My Account.
     *
     * @return string
     */
    public static function get_redirect() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- called after the form's nonce was checked; validated by the caller.
        $posted = isset($_POST['redirect']) ? esc_url_raw(wp_unslash((string) $_POST['redirect'])) : '';
        if ('' !== $posted) {
            return $posted;
        }
        $referer = wp_get_raw_referer();
        return $referer ? (string) $referer : SEOProStack_Account::page_url();
    }
}
