<?php
/**
 * My Account without WooCommerce: the Lost password tab (WooCommerce's
 * myaccount/form-lost-password.php, form-reset-password.php and
 * lost-password-confirmation.php) and the cookie that carries the reset key
 * from the emailed link to the reset form.
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

final class SEOProStack_Account_Password_Form {

    /** Cookie that carries a password reset key from the email link to the form. */
    const COOKIE = 'wp-resetpass-';

    /**
     * The tab: "sent", the reset form from the emailed link, or the form
     * that asks for a link.
     */
    public static function output() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- choose which form shows; the reset key is checked.
        if (!empty($_GET['reset-link-sent'])) {
            self::sent();
            return;
        }
        if (!empty($_GET['show-reset-form']) && self::show_reset_form()) {
            return;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        self::request_form();
    }

    /**
     * Reset link sent.
     */
    private static function sent() {
        SEOProStack_Account_Notices::output();
        printf('<div class="woocommerce-message" role="alert">%s</div>', esc_html__('Password reset email has been sent.', 'seoprostack'));
        do_action('woocommerce_before_lost_password_confirmation_message');
        printf('<p>%s</p>', esc_html((string) apply_filters('woocommerce_lost_password_confirmation_message', __('A password reset email has been sent to the email address on file for your account, but may take several minutes to show up in your inbox. Please wait at least 10 minutes before attempting another reset.', 'seoprostack'))));
        do_action('woocommerce_after_lost_password_confirmation_message');
    }

    /**
     * The reset form, when the cookie holds a key that works.
     *
     * @return bool Whether it was shown.
     */
    private static function show_reset_form() {
        list($id, $key) = self::get_cookie();
        $user  = $id ? get_userdata($id) : false;
        $login = $user ? $user->user_login : '';
        if ('' === $key || '' === $login || !self::check_key($key, $login) instanceof WP_User) {
            return false;
        }
        self::reset_form($key, $login);
        return true;
    }

    /**
     * Ask for a reset link.
     */
    private static function request_form() {
        SEOProStack_Account_Notices::output();
        do_action('woocommerce_before_lost_password_form');
        echo '<form method="post" class="woocommerce-ResetPassword lost_reset_password">';
        printf('<p>%s</p>', esc_html((string) apply_filters('woocommerce_lost_password_message', __('Lost your password? Please enter your username or email address. You will receive a link to create a new password via email.', 'seoprostack'))));
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">%s<input class="woocommerce-Input woocommerce-Input--text input-text" type="text" name="user_login" id="user_login" autocomplete="username" required aria-required="true" /></p><div class="clear"></div>',
            SEOProStack_Account_Forms::label('user_login', __('Username or email', 'seoprostack')) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
        );
        do_action('woocommerce_lostpassword_form');
        printf('<p class="woocommerce-form-row form-row"><input type="hidden" name="wc_reset_password" value="true" /><button type="submit" class="woocommerce-Button button" value="%1$s">%1$s</button></p>', esc_attr__('Reset password', 'seoprostack'));
        wp_nonce_field('lost_password', 'woocommerce-lost-password-nonce');
        echo '</form>';
        do_action('woocommerce_after_lost_password_form');
    }

    /**
     * Choose a new password.
     *
     * @param string $key   Reset key.
     * @param string $login Username.
     */
    private static function reset_form($key, $login) {
        SEOProStack_Account_Notices::output();
        do_action('woocommerce_before_reset_password_form');
        echo '<form method="post" class="woocommerce-ResetPassword lost_reset_password">';
        printf('<p>%s</p>', esc_html((string) apply_filters('woocommerce_reset_password_message', __('Enter a new password below.', 'seoprostack'))));
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">%1$s<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_1" id="password_1" autocomplete="new-password" required aria-required="true" /></p><p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">%2$s<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_2" id="password_2" autocomplete="new-password" required aria-required="true" /></p>',
            SEOProStack_Account_Forms::label('password_1', __('New password', 'seoprostack')), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
            SEOProStack_Account_Forms::label('password_2', __('Re-enter new password', 'seoprostack')) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
        );
        printf('<input type="hidden" name="reset_key" value="%1$s" /><input type="hidden" name="reset_login" value="%2$s" /><div class="clear"></div>', esc_attr($key), esc_attr($login));
        do_action('woocommerce_resetpassword_form');
        printf('<p class="woocommerce-form-row form-row"><input type="hidden" name="wc_reset_password" value="true" /><button type="submit" class="woocommerce-Button button" value="%1$s">%1$s</button></p>', esc_attr__('Save', 'seoprostack'));
        wp_nonce_field('reset_password', 'woocommerce-reset-password-nonce');
        echo '</form>';
        do_action('woocommerce_after_reset_password_form');
    }

    /**
     * Check a reset key; a notice when it does not work.
     *
     * @param string $key   Key.
     * @param string $login Username.
     * @return WP_User|false
     */
    public static function check_key($key, $login) {
        $user = check_password_reset_key($key, $login);
        if ($user instanceof WP_User) {
            return $user;
        }
        SEOProStack_Account_Notices::add(__('This key is invalid or has already been used. Please reset your password again if needed.', 'seoprostack'), 'error');
        return false;
    }

    /**
     * The reset cookie's name, WooCommerce's (wp-resetpass- and the site's
     * cookie hash, which WordPress defines before plugins load).
     *
     * @return string
     */
    private static function cookie_name() {
        return self::COOKIE . (defined('COOKIEHASH') ? COOKIEHASH : hash('sha256', site_url()));
    }

    /**
     * The reset cookie's user ID and key.
     *
     * @return array{0:int,1:string}
     */
    public static function get_cookie() {
        $name  = self::cookie_name();
        $value = isset($_COOKIE[$name]) ? sanitize_text_field(wp_unslash((string) $_COOKIE[$name])) : '';
        if (0 < (int) strpos($value, ':')) {
            list($id, $key) = explode(':', $value, 2);
            return array(absint($id), (string) $key);
        }
        return array(0, '');
    }

    /**
     * Set or clear the reset cookie, for this address only.
     *
     * @param string $value user ID:key, or '' to clear.
     */
    public static function set_cookie($value = '') {
        $name = self::cookie_name();
        $path = isset($_SERVER['REQUEST_URI']) ? (string) current(explode('?', sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])))) : '';
        if ('' !== $value) {
            setcookie($name, $value, 0, $path, COOKIE_DOMAIN, is_ssl(), true);
            return;
        }
        setcookie($name, ' ', time() - YEAR_IN_SECONDS, $path, COOKIE_DOMAIN, is_ssl(), true);
    }
}
