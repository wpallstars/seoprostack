<?php
/**
 * My Account without WooCommerce: making an account
 * (wc_create_new_customer()), with WooCommerce's checks, settings and hooks,
 * so plugins' checks and what they do with new accounts work.
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

final class SEOProStack_Account_Customer {

    /**
     * Whether sign-up makes the username up from the email address
     * (WooCommerce's setting, kept when it is deactivated; default yes).
     *
     * @return bool
     */
    public static function generates_username() {
        return 'no' !== get_option('woocommerce_registration_generate_username', 'yes');
    }

    /**
     * Whether sign-up makes the password up and emails a link to set one
     * (WooCommerce's setting; default yes).
     *
     * @return bool
     */
    public static function generates_password() {
        return 'no' !== get_option('woocommerce_registration_generate_password', 'yes');
    }

    /**
     * The role of new accounts: WooCommerce's Customer while it exists.
     *
     * @return string
     */
    public static function get_role() {
        $role = get_role('customer') ? 'customer' : (string) get_option('default_role', 'subscriber');
        /**
         * Filter the role of accounts made on My Account.
         *
         * @param string $role Default: customer, else the site's default role.
         */
        return (string) apply_filters('seoprostack_account_role', $role);
    }

    /**
     * Make an account.
     *
     * @param string $email    Email address.
     * @param string $username Username ('' to make one up when that is on).
     * @param string $password Password ('' to make one up when that is on).
     * @return int|WP_Error User ID.
     */
    public static function create($email, $username = '', $password = '') {
        $error = self::email_error($email);
        if ($error) {
            return $error;
        }
        $username = self::username($email, $username);
        if (is_wp_error($username)) {
            return $username;
        }
        $generated = '' === $password && self::generates_password();
        $password  = $generated ? wp_generate_password() : $password;
        if ('' === $password) {
            return new WP_Error('registration-error-missing-password', __('Please enter an account password.', 'seoprostack'));
        }
        $errors = new WP_Error();
        do_action('woocommerce_register_post', $username, $email, $errors);
        $errors = apply_filters('woocommerce_registration_errors', $errors, $username, $email);
        if ($errors instanceof WP_Error && $errors->get_error_code()) {
            return $errors;
        }
        return self::insert($email, $username, $password, $generated);
    }

    /**
     * Why an email address cannot sign up, or null.
     *
     * @param string $email Email address.
     * @return WP_Error|null
     */
    private static function email_error($email) {
        if ('' === $email || !is_email($email)) {
            return new WP_Error('registration-error-invalid-email', __('Please provide a valid email address.', 'seoprostack'));
        }
        if (email_exists($email)) {
            return new WP_Error('registration-error-email-exists', __('An account is already registered with your email address. Please log in.', 'seoprostack'));
        }
        return null;
    }

    /**
     * The username to use: the one given, or one made up from the email
     * address when that is on; an error when it cannot be used.
     *
     * @param string $email    Email address.
     * @param string $username Username, or ''.
     * @return string|WP_Error
     */
    private static function username($email, $username) {
        if ('' === $username && self::generates_username()) {
            $username = self::new_username($email);
        }
        $username = sanitize_user($username);
        if ('' === $username || !validate_username($username)) {
            return new WP_Error('registration-error-invalid-username', __('Please enter a valid account username.', 'seoprostack'));
        }
        if (username_exists($username)) {
            return new WP_Error('registration-error-username-exists', __('An account is already registered with that username. Please choose another.', 'seoprostack'));
        }
        return $username;
    }

    /**
     * Insert the account and fire woocommerce_created_customer.
     *
     * @param string $email     Email address.
     * @param string $username  Username.
     * @param string $password  Password.
     * @param bool   $generated Whether the password was made up.
     * @return int|WP_Error User ID.
     */
    private static function insert($email, $username, $password, $generated) {
        $data    = apply_filters(
            'woocommerce_new_customer_data',
            array(
                'user_login' => $username,
                'user_pass'  => $password,
                'user_email' => $email,
                'role'       => self::get_role(),
            )
        );
        $user_id = wp_insert_user($data);
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        do_action('woocommerce_created_customer', $user_id, $data, $generated);
        return (int) $user_id;
    }

    /**
     * A free username from an email address's first part.
     *
     * @param string $email Email address.
     * @return string
     */
    private static function new_username($email) {
        $base = sanitize_user(strtolower(current(explode('@', $email))), true);
        $base = '' !== $base ? $base : 'user';
        if (in_array($base, array_map('strtolower', (array) apply_filters('illegal_user_logins', array())), true)) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's filter.
            $base .= 'user';
        }
        $name = $base;
        for ($i = 1; username_exists($name) && $i < 100; $i++) {
            $name = $base . $i;
        }
        return username_exists($name) ? $base . '-' . wp_generate_password(6, false, false) : $name;
    }
}
