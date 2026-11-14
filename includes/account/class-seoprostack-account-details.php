<?php
/**
 * My Account without WooCommerce: the Account details tab (WooCommerce's
 * myaccount/form-edit-account.php and
 * WC_Form_Handler::save_account_details()).
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

final class SEOProStack_Account_Details {

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('woocommerce_account_edit-account_endpoint', array(__CLASS__, 'output'));
        add_action('template_redirect', array(__CLASS__, 'save'), 10);
    }

    /**
     * The form.
     */
    public static function output() {
        $user = wp_get_current_user();
        do_action('woocommerce_before_edit_account_form');
        echo '<form class="woocommerce-EditAccountForm edit-account" action="" method="post" ';
        do_action('woocommerce_edit_account_form_tag');
        echo '>';
        do_action('woocommerce_edit_account_form_start');
        $required = '&nbsp;<span class="required" aria-hidden="true">*</span>';
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first"><label for="account_first_name">%1$s%5$s</label><input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_first_name" id="account_first_name" autocomplete="given-name" value="%2$s" aria-required="true" /></p><p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last"><label for="account_last_name">%3$s%5$s</label><input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_last_name" id="account_last_name" autocomplete="family-name" value="%4$s" aria-required="true" /></p><div class="clear"></div>',
            esc_html__('First name', 'seoprostack'),
            esc_attr($user->first_name),
            esc_html__('Last name', 'seoprostack'),
            esc_attr($user->last_name),
            $required // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
        );
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="account_display_name">%1$s%4$s</label><input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="account_display_name" id="account_display_name" aria-describedby="account_display_name_description" value="%2$s" aria-required="true" /><span id="account_display_name_description"><em>%3$s</em></span></p><div class="clear"></div>',
            esc_html__('Display name', 'seoprostack'),
            esc_attr($user->display_name),
            esc_html__('This will be how your name will be displayed in the account section and in reviews', 'seoprostack'),
            $required // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
        );
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="account_email">%1$s%3$s</label><input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="account_email" id="account_email" autocomplete="email" value="%2$s" aria-required="true" /></p>',
            esc_html__('Email address', 'seoprostack'),
            esc_attr($user->user_email),
            $required // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
        );
        do_action('woocommerce_edit_account_form_fields');
        printf(
            '<fieldset><legend>%1$s</legend><p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="password_current">%2$s</label><input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_current" id="password_current" autocomplete="current-password" /></p><p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="password_1">%3$s</label><input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_1" id="password_1" autocomplete="new-password" /></p><p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide"><label for="password_2">%4$s</label><input type="password" class="woocommerce-Input woocommerce-Input--password input-text" name="password_2" id="password_2" autocomplete="new-password" /></p></fieldset><div class="clear"></div>',
            esc_html__('Password change', 'seoprostack'),
            esc_html__('Current password (leave blank to leave unchanged)', 'seoprostack'),
            esc_html__('New password (leave blank to leave unchanged)', 'seoprostack'),
            esc_html__('Confirm new password', 'seoprostack')
        );
        do_action('woocommerce_edit_account_form');
        echo '<p>';
        wp_nonce_field('save_account_details', 'save-account-details-nonce');
        printf('<button type="submit" class="woocommerce-Button button" name="save_account_details" value="%1$s">%1$s</button><input type="hidden" name="action" value="save_account_details" /></p>', esc_attr__('Save changes', 'seoprostack'));
        do_action('woocommerce_edit_account_form_end');
        echo '</form>';
        do_action('woocommerce_after_edit_account_form');
    }

    /**
     * Save account details.
     */
    public static function save() {
        $user_id = self::posted_user();
        if ($user_id < 1) {
            return;
        }
        $current            = get_user_by('id', $user_id);
        $user               = new stdClass();
        $user->ID           = $user_id;
        $user->first_name   = self::field('account_first_name');
        $user->last_name    = self::field('account_last_name');
        $user->display_name = self::field('account_display_name');
        if (is_email($user->display_name)) {
            SEOProStack_Account_Notices::add(__('Display name cannot be changed to email address due to privacy concern.', 'seoprostack'), 'error');
        }
        self::check_required();
        self::check_email($user, $current);
        self::check_password($user, $current);
        $errors = new WP_Error();
        do_action_ref_array('woocommerce_save_account_details_errors', array(&$errors, &$user));
        foreach ($errors->get_error_messages() as $message) {
            SEOProStack_Account_Notices::add($message, 'error');
        }
        if (SEOProStack_Account_Notices::get_count('error') > 0) {
            return;
        }
        wp_update_user($user);
        SEOProStack_Account_Notices::add(__('Account details changed successfully.', 'seoprostack'));
        do_action('woocommerce_save_account_details', $user->ID);
        wp_safe_redirect(SEOProStack_Account_Endpoints::url('edit-account'));
        exit;
    }

    /**
     * The signed-in person, when the form was sent with its nonce; else 0.
     *
     * @return int
     */
    private static function posted_user() {
        if (!isset($_POST['save-account-details-nonce'], $_POST['action'])
            || !wp_verify_nonce(sanitize_key(wp_unslash((string) $_POST['save-account-details-nonce'])), 'save_account_details')
            || 'save_account_details' !== $_POST['action']) {
            return 0;
        }
        nocache_headers();
        return get_current_user_id();
    }

    /**
     * A sent text field.
     *
     * @param string $name Field.
     * @return string
     */
    private static function field($name) {
        return isset($_POST[$name]) ? sanitize_text_field(wp_unslash((string) $_POST[$name])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- posted_user() checked it.
    }

    /**
     * A sent password field, as WordPress and WooCommerce take it (slashed).
     *
     * @param string $name Field.
     * @return string
     */
    private static function password($name) {
        return isset($_POST[$name]) ? (string) $_POST[$name] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- posted_user() checked the nonce; a password, as WordPress and WooCommerce take it (slashed), so passwords they saved keep working.
    }

    /**
     * Notices for required fields left empty
     * (woocommerce_save_account_details_required_fields).
     */
    private static function check_required() {
        $required = apply_filters(
            'woocommerce_save_account_details_required_fields',
            array(
                'account_first_name'   => __('First name', 'seoprostack'),
                'account_last_name'    => __('Last name', 'seoprostack'),
                'account_display_name' => __('Display name', 'seoprostack'),
                'account_email'        => __('Email address', 'seoprostack'),
            )
        );
        foreach (is_array($required) ? $required : array() as $key => $name) {
            if ('' === self::field((string) $key)) {
                /* translators: %s: field name */
                SEOProStack_Account_Notices::add(sprintf(__('%s is a required field.', 'seoprostack'), '<strong>' . esc_html((string) $name) . '</strong>'), 'error', array('id' => (string) $key));
            }
        }
    }

    /**
     * The new email address, with a notice when it cannot be used.
     *
     * @param stdClass      $user    Changes.
     * @param WP_User|false $current Account.
     */
    private static function check_email($user, $current) {
        $email = self::field('account_email');
        if ('' === $email) {
            return;
        }
        $email = sanitize_email($email);
        if (!is_email($email)) {
            SEOProStack_Account_Notices::add(__('Please provide a valid email address.', 'seoprostack'), 'error');
        } elseif (email_exists($email) && $current && $email !== $current->user_email) {
            SEOProStack_Account_Notices::add(__('This email address is already registered.', 'seoprostack'), 'error');
        }
        $user->user_email = $email;
    }

    /**
     * The new password, when the three fields allow it; a notice when not.
     *
     * @param stdClass      $user    Changes.
     * @param WP_User|false $current Account.
     */
    private static function check_password($user, $current) {
        $now     = self::password('password_current');
        $pass1   = self::password('password_1');
        $problem = self::password_problem($now, $pass1, self::password('password_2'));
        if ('' === $problem && '' !== $pass1 && $current && !wp_check_password($now, $current->user_pass, $current->ID)) {
            $problem = __('Your current password is incorrect.', 'seoprostack');
        }
        if ('' !== $problem) {
            SEOProStack_Account_Notices::add($problem, 'error');
            return;
        }
        if ('' !== $pass1) {
            $user->user_pass = $pass1;
        }
    }

    /**
     * What is wrong with the three password fields, or ''.
     *
     * @param string $now   Current password.
     * @param string $pass1 New password.
     * @param string $pass2 Again.
     * @return string
     */
    private static function password_problem($now, $pass1, $pass2) {
        if ('' !== $now && '' === $pass1 && '' === $pass2) {
            return __('Please fill out all password fields.', 'seoprostack');
        }
        if ('' !== $pass1 && '' === $now) {
            return __('Please enter your current password.', 'seoprostack');
        }
        if ('' !== $pass1 && '' === $pass2) {
            return __('Please re-enter your password.', 'seoprostack');
        }
        // Both empty are equal, so this is WooCommerce's "either set and different".
        return $pass1 !== $pass2 ? __('New passwords do not match.', 'seoprostack') : '';
    }
}
