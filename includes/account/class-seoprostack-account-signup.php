<?php
/**
 * My Account without WooCommerce: signing up
 * (WC_Form_Handler::process_registration()) and the welcome email.
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

final class SEOProStack_Account_Signup {

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('wp_loaded', array(__CLASS__, 'process'), 20);
        add_action('woocommerce_created_customer', array(__CLASS__, 'welcome_email'), 10, 3);
    }

    /**
     * Sign up.
     */
    public static function process() {
        if (!self::posted()) {
            return;
        }
        $fields = self::fields();
        $error  = apply_filters('woocommerce_process_registration_errors', new WP_Error(), $fields['username'], $fields['password'], $fields['email']);
        if (self::show_errors($error)) {
            return;
        }
        $customer = SEOProStack_Account_Customer::create($fields['email'], $fields['username'], $fields['password']);
        if (is_wp_error($customer)) {
            SEOProStack_Account_Notices::add('<strong>' . esc_html__('Error:', 'seoprostack') . '</strong> ' . $customer->get_error_message(), 'error');
            return;
        }
        self::signed_up($customer);
    }

    /**
     * Whether the sign-up form was sent, with its nonce, while sign-up is
     * open.
     *
     * @return bool
     */
    private static function posted() {
        if (!isset($_POST['register'], $_POST['email'], $_POST['woocommerce-register-nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash((string) $_POST['woocommerce-register-nonce'])), 'woocommerce-register')) {
            return false;
        }
        return SEOProStack_Account::signups_open();
    }

    /**
     * The sent username (when it is not made up), password (when it is not
     * made up) and email address.
     *
     * @return array{username:string,password:string,email:string}
     */
    private static function fields() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- posted() checked the nonce.
        $username = !SEOProStack_Account_Customer::generates_username() && isset($_POST['username']) ? sanitize_user(wp_unslash((string) $_POST['username'])) : '';
        $password = !SEOProStack_Account_Customer::generates_password() && isset($_POST['password']) ? (string) $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- a password, as WordPress and WooCommerce take it (slashed), so passwords they saved keep working.
        $email    = isset($_POST['email']) ? sanitize_email(wp_unslash((string) $_POST['email'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return array(
            'username' => $username,
            'password' => $password,
            'email'    => $email,
        );
    }

    /**
     * Show the errors plugins found, if any.
     *
     * @param mixed $error WP_Error.
     * @return bool Whether there were any.
     */
    private static function show_errors($error) {
        $messages = $error instanceof WP_Error ? $error->get_error_messages() : array();
        foreach ($messages as $message) {
            SEOProStack_Account_Notices::add('<strong>' . esc_html__('Error:', 'seoprostack') . '</strong> ' . $message, 'error');
        }
        return array() !== $messages;
    }

    /**
     * Account made: say so, then log in and go on, unless a plugin says
     * not to (woocommerce_registration_auth_new_customer).
     *
     * @param int $customer User ID.
     */
    private static function signed_up($customer) {
        SEOProStack_Account_Notices::add(SEOProStack_Account_Customer::generates_password()
            ? __('Your account was created successfully and a password has been sent to your email address.', 'seoprostack')
            : __('Your account was created successfully. Your login details have been sent to your email address.', 'seoprostack'));
        if (!apply_filters('woocommerce_registration_auth_new_customer', true, $customer)) {
            return;
        }
        wp_set_current_user($customer);
        wp_set_auth_cookie($customer, true);
        $redirect = (string) apply_filters('woocommerce_registration_redirect', wp_sanitize_redirect(SEOProStack_Account_Login::get_redirect()), $customer);
        wp_redirect(wp_validate_redirect($redirect, SEOProStack_Account::page_url())); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated with wp_validate_redirect().
        exit;
    }

    /**
     * The welcome email with a link to set a password, unless WooCommerce's
     * "New account" email was switched off or a plugin holds it (such as
     * one that sends a confirmation link instead).
     *
     * @param mixed $user_id   New account.
     * @param mixed $data      Its data.
     * @param mixed $generated Whether the password was made up.
     */
    public static function welcome_email($user_id, $data = array(), $generated = false) {
        unset($data, $generated);
        $user = get_userdata((int) $user_id);
        if (!$user instanceof WP_User) {
            return;
        }
        $settings = get_option('woocommerce_customer_new_account_settings');
        $enabled  = !is_array($settings) || !isset($settings['enabled']) || 'no' !== $settings['enabled'];
        if (!apply_filters('woocommerce_email_enabled_customer_new_account', $enabled, $user)) {
            return;
        }
        wp_new_user_notification($user->ID, null, 'user');
    }
}
