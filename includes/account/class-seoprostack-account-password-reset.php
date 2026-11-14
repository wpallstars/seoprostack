<?php
/**
 * My Account without WooCommerce: lost and reset password
 * (WC_Form_Handler::process_lost_password(), process_reset_password() and
 * WC_Shortcode_My_Account::retrieve_password()), and the emailed link.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.218
 */

// WooCommerce's and WordPress's hook names, so code written for them works.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Account_Password_Reset {

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('wp_loaded', array(__CLASS__, 'process_lost_password'), 20);
        add_action('wp_loaded', array(__CLASS__, 'process_reset_password'), 20);
        add_action('template_redirect', array(__CLASS__, 'link_redirect'), 10);
    }

    /**
     * Send the reset link.
     */
    public static function process_lost_password() {
        if (!isset($_POST['wc_reset_password'], $_POST['user_login'], $_POST['woocommerce-lost-password-nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash((string) $_POST['woocommerce-lost-password-nonce'])), 'lost_password')) {
            return;
        }
        if (self::send_link(trim(sanitize_text_field(wp_unslash((string) $_POST['user_login']))))) {
            wp_safe_redirect(add_query_arg('reset-link-sent', 'true', SEOProStack_Account_Endpoints::url('lost-password')));
            exit;
        }
    }

    /**
     * Email a reset link to My Account's reset form.
     *
     * @param string $login Username or email address.
     * @return bool
     */
    private static function send_link($login) {
        $user = self::user_for_reset($login);
        if (!$user) {
            return false;
        }
        $key = self::reset_key($user);
        return '' !== $key && self::email_link($user, $key);
    }

    /**
     * The account a reset was asked for, after WordPress's checks; a
     * notice and false when there is none.
     *
     * @param string $login Username or email address.
     * @return WP_User|false
     */
    private static function user_for_reset($login) {
        if ('' === $login) {
            SEOProStack_Account_Notices::add(__('Enter a username or email address.', 'seoprostack'), 'error');
            return false;
        }
        $user = get_user_by('login', $login);
        if (!$user && is_email($login)) {
            $user = get_user_by('email', $login);
        }
        $errors = new WP_Error();
        do_action('lostpassword_post', $errors, $user);
        if ($errors->get_error_code()) {
            SEOProStack_Account_Notices::add($errors->get_error_message(), 'error');
            return false;
        }
        if (!$user instanceof WP_User || (is_multisite() && !is_user_member_of_blog($user->ID, get_current_blog_id()))) {
            SEOProStack_Account_Notices::add(__('Invalid username or email.', 'seoprostack'), 'error');
            return false;
        }
        return $user;
    }

    /**
     * A reset key, if resetting is allowed; '' with a notice when not.
     *
     * @param WP_User $user Account.
     * @return string
     */
    private static function reset_key($user) {
        do_action('retrieve_password', $user->user_login);
        $allow = apply_filters('allow_password_reset', true, $user->ID);
        if (!$allow || is_wp_error($allow)) {
            SEOProStack_Account_Notices::add(is_wp_error($allow) ? $allow->get_error_message() : __('Password reset is not allowed for this user.', 'seoprostack'), 'error');
            return '';
        }
        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            SEOProStack_Account_Notices::add($key->get_error_message(), 'error');
            return '';
        }
        return (string) $key;
    }

    /**
     * Send the email, with WordPress's filters of its own reset email.
     *
     * @param WP_User $user Account.
     * @param string  $key  Reset key.
     * @return bool
     */
    private static function email_link($user, $key) {
        $site    = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        /* translators: %s: site name */
        $subject = sprintf(__('[%s] Password Reset', 'seoprostack'), $site);
        $lines   = array(
            __('Someone has requested a password reset for the following account:', 'seoprostack'),
            /* translators: %s: site name */
            sprintf(__('Site Name: %s', 'seoprostack'), $site),
            /* translators: %s: username */
            sprintf(__('Username: %s', 'seoprostack'), $user->user_login),
            __('If this was a mistake, ignore this email and nothing will happen.', 'seoprostack'),
            __('To reset your password, visit the following address:', 'seoprostack'),
            add_query_arg(array('key' => $key, 'id' => $user->ID), SEOProStack_Account_Endpoints::url('lost-password')),
        );
        $message = implode("\r\n\r\n", $lines) . "\r\n";
        $subject = (string) apply_filters('retrieve_password_title', $subject, $user->user_login, $user);
        $message = (string) apply_filters('retrieve_password_message', $message, $key, $user->user_login, $user);
        if ('' !== $message && !wp_mail($user->user_email, wp_specialchars_decode($subject), $message)) {
            SEOProStack_Account_Notices::add(__('The email could not be sent. Please try again later, or contact the site.', 'seoprostack'), 'error');
            return false;
        }
        return true;
    }

    /**
     * Save a new password.
     */
    public static function process_reset_password() {
        if (!isset($_POST['woocommerce-reset-password-nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash((string) $_POST['woocommerce-reset-password-nonce'])), 'reset_password')) {
            return;
        }
        if (!isset($_POST['wc_reset_password'], $_POST['password_1'], $_POST['password_2'], $_POST['reset_key'], $_POST['reset_login'])) {
            return;
        }
        $pass1 = (string) $_POST['password_1']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- a password, as WordPress and WooCommerce take it (slashed), so passwords they saved keep working.
        $pass2 = (string) $_POST['password_2']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- the same.
        $user  = SEOProStack_Account_Password_Form::check_key(sanitize_text_field(wp_unslash((string) $_POST['reset_key'])), sanitize_user(wp_unslash((string) $_POST['reset_login'])));
        if (!$user instanceof WP_User || !self::new_password_ok($pass1, $pass2, $user)) {
            return;
        }
        reset_password($user, $pass1);
        SEOProStack_Account_Password_Form::set_cookie();
        do_action('woocommerce_customer_reset_password', $user);
        wp_safe_redirect(add_query_arg('password-reset', 'true', SEOProStack_Account::page_url()));
        exit;
    }

    /**
     * Whether the new password can be saved; notices when not.
     *
     * @param string  $pass1 Password.
     * @param string  $pass2 Again.
     * @param WP_User $user  Account.
     * @return bool
     */
    private static function new_password_ok($pass1, $pass2, $user) {
        if ('' === $pass1) {
            SEOProStack_Account_Notices::add(__('Please enter your password.', 'seoprostack'), 'error');
        }
        if ($pass1 !== $pass2) {
            SEOProStack_Account_Notices::add(__('Passwords do not match.', 'seoprostack'), 'error');
        }
        $errors = new WP_Error();
        do_action('validate_password_reset', $errors, $user);
        foreach ($errors->get_error_messages() as $message) {
            SEOProStack_Account_Notices::add($message, 'error');
        }
        return 0 === SEOProStack_Account_Notices::get_count('error');
    }

    /**
     * The emailed reset link: to the reset form, its key in a cookie, so it
     * is not left in the address.
     */
    public static function link_redirect() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the reset key is the proof, checked on the form.
        if (!SEOProStack_Account::is_account_page() || !isset($_GET['key']) || (!isset($_GET['id']) && !isset($_GET['login']))) {
            return;
        }
        $user_id = self::link_user_id();
        $current = get_current_user_id();
        if ($current && $current !== $user_id) {
            SEOProStack_Account_Notices::add(__('This password reset key is for a different user account. Please log out and try again.', 'seoprostack'), 'error');
            return;
        }
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
        SEOProStack_Account_Password_Form::set_cookie(sprintf('%d:%s', $user_id, sanitize_text_field(wp_unslash((string) $_GET['key']))));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        wp_safe_redirect(add_query_arg(array_filter(array('show-reset-form' => 'true', 'action' => $action)), SEOProStack_Account_Endpoints::url('lost-password')));
        exit;
    }

    /**
     * The link's account: its login (WordPress's links) or ID (WooCommerce's).
     *
     * @return int
     */
    private static function link_user_id() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the same.
        if (isset($_GET['login'])) {
            $user = get_user_by('login', sanitize_user(wp_unslash((string) $_GET['login'])));
            return $user ? (int) $user->ID : 0;
        }
        return isset($_GET['id']) ? absint($_GET['id']) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }
}
