<?php
/**
 * Minimum password length.
 *
 * Long passwords resist guessing better than complex short ones, so this
 * asks only for a length (12 characters unless changed), with no rules
 * about symbols or capitals. It applies when anyone sets a new password:
 * on their profile or another person's (Users), when adding someone, on
 * the password reset form, on WooCommerce's account details, password
 * reset and registration forms, and through the REST API. Passwords
 * already set keep working until they are changed; WP-CLI and code that
 * sets a password itself are not checked. WordPress's own password
 * strength meter still shows, and its "Confirm use of weak password" box
 * cannot get round the length.
 *
 * Two-step login and login limits are FluentAuth's (README.md → Security).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 * @since 1.3.44
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Min_Pass extends SEOProStack_Feature {

    const KEY = 'password_length';

    /** Shortest password allowed. */
    const MIN_KEY = 'password_length_min';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'security',
                'label'       => __('Minimum password length', 'seoprostack'),
                'description' => __('Ask everyone for a password of at least 12 characters (or the length set below) whenever they set a new one, on their profile, the password reset form, WooCommerce’s account forms and the REST API. No rules about symbols or capitals. Passwords already set keep working until they are changed.', 'seoprostack'),
            ),
            self::MIN_KEY => array(
                'type'        => 'int',
                'default'     => 12,
                'min'         => 8,
                'max'         => 64,
                'unit'        => __('characters', 'seoprostack'),
                'parent'      => self::KEY,
                'label'       => __('Shortest password', 'seoprostack'),
                'description' => __('12 or more is recommended; a long phrase of ordinary words is easy to remember and hard to guess.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled()) {
            return;
        }
        add_action('user_profile_update_errors', array(__CLASS__, 'check_profile'), 10, 3);
        add_action('validate_password_reset', array(__CLASS__, 'check_reset'), 10, 2);
        add_action('woocommerce_save_account_details_errors', array(__CLASS__, 'check_profile'), 10, 2);
        add_filter('woocommerce_registration_errors', array(__CLASS__, 'check_registration'));
        add_filter('rest_request_before_callbacks', array(__CLASS__, 'check_rest'), 10, 3);
        add_filter('password_hint', array(__CLASS__, 'hint'));
    }

    /**
     * Shortest password allowed.
     *
     * @return int
     */
    public static function min_length() {
        return max(8, min(64, (int) SEOProStack_Settings::get(self::MIN_KEY)));
    }

    /**
     * Whether a password is too short.
     *
     * @param mixed $password Password, as sent (slashed).
     * @return bool
     */
    private static function too_short($password) {
        if (!is_string($password) || '' === $password) {
            return false;
        }
        $password = wp_unslash($password);
        $length   = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        return $length < self::min_length();
    }

    /**
     * The error message.
     *
     * @return string
     */
    private static function message() {
        $min = self::min_length();
        /* translators: %d: number of characters */
        return sprintf(_n('Please use a password of at least %d character.', 'Please use a password of at least %d characters.', $min, 'seoprostack'), $min);
    }

    /**
     * A new password on a profile (Users, the user's own profile, Add New
     * User) or WooCommerce's account details: the user object holds it
     * only when it changes.
     *
     * @param WP_Error $errors Errors.
     * @param mixed    $update Whether an existing user (core), or the user (WooCommerce).
     * @param mixed    $user   User data (core).
     */
    public static function check_profile($errors, $update = false, $user = null) {
        if (!is_object($user) && is_object($update)) {
            $user = $update;
        }
        if ($errors instanceof WP_Error && is_object($user) && isset($user->user_pass) && self::too_short($user->user_pass)) {
            $errors->add('pass', self::message(), array('form-field' => 'pass1'));
        }
    }

    /**
     * The password reset form: core's (pass1) and WooCommerce's (password_1).
     * The action also fires when the form is shown, with no password sent.
     *
     * @param WP_Error $errors Errors.
     * @param mixed    $user   User.
     */
    public static function check_reset($errors, $user = null) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- core and WooCommerce verify the reset key; a password is not sanitised, too_short() unslashes it.
        $password = '';
        foreach (array('pass1', 'password_1') as $field) {
            if (isset($_POST[$field])) {
                $password = $_POST[$field];
                break;
            }
        }
        // phpcs:enable
        if ($errors instanceof WP_Error && self::too_short($password)) {
            $errors->add('password_too_short', self::message());
        }
    }

    /**
     * WooCommerce registration with a password field (when it does not make
     * one up).
     *
     * @param WP_Error $errors Errors.
     * @return WP_Error
     */
    public static function check_registration($errors) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- WooCommerce verifies its nonce; too_short() unslashes it.
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        if ($errors instanceof WP_Error && self::too_short($password)) {
            $errors->add('registration-error-password-too-short', self::message());
        }
        return $errors;
    }

    /**
     * A password set through the REST API's users endpoints, before core
     * saves it. Not on rest_pre_insert_user: core's users controller does
     * not check that filter for an error, and saves the user anyway.
     *
     * @param mixed           $response Null to go on, or an earlier error.
     * @param mixed           $handler  Route handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed
     */
    public static function check_rest($response, $handler, $request) {
        unset($handler);
        if (null !== $response || !($request instanceof WP_REST_Request)
            || !in_array($request->get_method(), array('POST', 'PUT', 'PATCH'), true)
            || !preg_match('#^/wp/v2/users(?:/(?:\d+|me))?$#', $request->get_route())) {
            return $response;
        }
        $password = $request->get_param('password');
        // The REST API sends it unslashed: slash it for too_short().
        if (is_string($password) && self::too_short(wp_slash($password))) {
            return new WP_Error('rest_user_invalid_password', self::message(), array('status' => 400));
        }
        return $response;
    }

    /**
     * The hint under password fields.
     *
     * @return string
     */
    public static function hint() {
        $min = self::min_length();
        /* translators: %d: number of characters */
        return sprintf(_n('Hint: use at least %d character. A long phrase of ordinary words is easy to remember and hard to guess.', 'Hint: use at least %d characters. A long phrase of ordinary words is easy to remember and hard to guess.', $min, 'seoprostack'), $min);
    }
}
