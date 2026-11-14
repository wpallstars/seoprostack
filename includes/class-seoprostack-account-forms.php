<?php
/**
 * My Account without WooCommerce: the page's forms and what they send
 * (SEOProStack_Account).
 *
 * Markup, field names, nonces and hooks are WooCommerce's (its templates
 * myaccount/form-login.php, form-lost-password.php, form-reset-password.php,
 * lost-password-confirmation.php, form-edit-account.php, navigation.php,
 * dashboard.php, and WC_Form_Handler), so themes' styles and the fields and
 * checks other plugins add keep working.
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

final class SEOProStack_Account_Forms {

    /** Cookie that carries a password reset key from the email link to the form. */
    const RESET_COOKIE = 'wp-resetpass-';

    /**
     * Register hooks (front end and admin-ajax.php).
     */
    public static function boot() {
        add_action('wp_loaded', array(__CLASS__, 'process_login'), 20);
        add_action('wp_loaded', array(__CLASS__, 'process_registration'), 20);
        add_action('wp_loaded', array(__CLASS__, 'process_lost_password'), 20);
        add_action('wp_loaded', array(__CLASS__, 'process_reset_password'), 20);
        add_action('template_redirect', array(__CLASS__, 'template_redirect'), 10);
        add_action('template_redirect', array(__CLASS__, 'save_account_details'), 10);
        add_action('woocommerce_account_navigation', array(__CLASS__, 'navigation'));
        add_action('woocommerce_account_content', array(__CLASS__, 'content'));
        add_action('woocommerce_account_edit-account_endpoint', array(__CLASS__, 'edit_account'));
        // Before fields other plugins add at 20, where WooCommerce's text is.
        add_action('woocommerce_register_form', array(__CLASS__, 'privacy_text'), 19);
        add_action('woocommerce_created_customer', array(__CLASS__, 'welcome_email'), 10, 3);
    }

    /* ------------------------------------------------------------------
     * The page
     * ------------------------------------------------------------------ */

    /**
     * The shortcode: the forms, or the signed-in person's account.
     *
     * @return string
     */
    public static function shortcode() {
        // Not for a description or title made from the content in <head>.
        if (doing_action('wp_head') || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return '';
        }
        ob_start();
        echo '<div class="woocommerce">';
        self::output();
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * What the page shows.
     */
    private static function output() {
        $endpoint = SEOProStack_Account::current_endpoint();
        if (!is_user_logged_in() || 'lost-password' === $endpoint) {
            $message = (string) apply_filters('woocommerce_my_account_message', '');
            if ('' !== $message) {
                SEOProStack_Account::add_notice($message);
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chooses a notice only.
            if (!empty($_GET['password-reset'])) {
                SEOProStack_Account::add_notice(esc_html__('Your password has been reset successfully.', 'seoprostack'));
            }
            if ('lost-password' === $endpoint) {
                self::lost_password();
            } else {
                self::login_form();
            }
            return;
        }
        do_action('woocommerce_account_navigation');
        echo '<div class="woocommerce-MyAccount-content">';
        do_action('woocommerce_account_content');
        echo '</div>';
    }

    /**
     * The menu.
     */
    public static function navigation() {
        do_action('woocommerce_before_account_navigation');
        $current = SEOProStack_Account::current_endpoint();
        echo '<nav class="woocommerce-MyAccount-navigation" aria-label="' . esc_attr__('Account pages', 'seoprostack') . '"><ul>';
        foreach (SEOProStack_Account::menu_items() as $endpoint => $label) {
            $active  = 'dashboard' === $endpoint ? '' === $current : $endpoint === $current;
            $classes = array('woocommerce-MyAccount-navigation-link', 'woocommerce-MyAccount-navigation-link--' . $endpoint);
            if ($active) {
                $classes[] = 'is-active';
            }
            $classes = (array) apply_filters('woocommerce_account_menu_item_classes', $classes, $endpoint);
            printf(
                '<li class="%1$s"><a href="%2$s"%3$s>%4$s</a></li>',
                esc_attr(implode(' ', array_map('sanitize_html_class', array_map('strval', $classes)))),
                esc_url(SEOProStack_Account::account_endpoint_url($endpoint)),
                $active ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }
        echo '</ul></nav>';
        do_action('woocommerce_after_account_navigation');
    }

    /**
     * The tab's content: the notices, then the tab, or the dashboard.
     */
    public static function content() {
        global $wp;
        SEOProStack_Account::print_notices();
        if ($wp instanceof WP) {
            foreach ($wp->query_vars as $key => $value) {
                if ('pagename' === $key) {
                    continue;
                }
                if (has_action('woocommerce_account_' . $key . '_endpoint')) {
                    do_action('woocommerce_account_' . $key . '_endpoint', $value);
                    return;
                }
            }
        }
        self::dashboard();
    }

    /**
     * The dashboard.
     */
    private static function dashboard() {
        $user = wp_get_current_user();
        $html = array('a' => array('href' => array()), 'strong' => array());
        printf(
            '<p>%s</p>',
            sprintf(
                /* translators: 1: display name, 2: logout address */
                wp_kses(__('Hello %1$s (not %1$s? <a href="%2$s">Log out</a>)', 'seoprostack'), $html),
                '<strong>' . esc_html($user->display_name) . '</strong>',
                esc_url(SEOProStack_Account::logout_url())
            )
        );
        printf(
            '<p>%s</p>',
            sprintf(
                /* translators: %s: account details address */
                wp_kses(__('From your account dashboard you can <a href="%s">edit your password and account details</a>.', 'seoprostack'), $html),
                esc_url(SEOProStack_Account::endpoint_url('edit-account'))
            )
        );
        do_action('woocommerce_account_dashboard');
    }

    /**
     * A posted text field, for refilling a form.
     *
     * @param string $name Field.
     * @return string
     */
    private static function posted($name) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- refills the form after its own handler checked the nonce.
        return isset($_POST[$name]) && is_string($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
    }

    /**
     * A required field's label.
     *
     * @param string $for   Field ID.
     * @param string $label Label.
     * @return string HTML.
     */
    private static function label($for, $label) {
        return sprintf(
            '<label for="%1$s">%2$s&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text">%3$s</span></label>',
            esc_attr($for),
            esc_html($label),
            esc_html__('Required', 'seoprostack')
        );
    }

    /**
     * Log in, and sign up when open.
     */
    private static function login_form() {
        $signup = SEOProStack_Account::signups_open();
        SEOProStack_Account::print_notices();
        do_action('woocommerce_before_customer_login_form');
        if ($signup) {
            echo '<div class="u-columns col2-set" id="customer_login"><div class="u-column1 col-1">';
        }
        printf('<h2>%s</h2>', esc_html__('Login', 'seoprostack'));
        echo '<form class="woocommerce-form woocommerce-form-login login" method="post" novalidate>';
        do_action('woocommerce_login_form_start');
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">%1$s<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="username" autocomplete="username" value="%2$s" required aria-required="true" /></p>',
            self::label('username', __('Username or email address', 'seoprostack')), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
            esc_attr(self::posted('username'))
        );
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">%s<input class="woocommerce-Input woocommerce-Input--text input-text" type="password" name="password" id="password" autocomplete="current-password" required aria-required="true" /></p>',
            self::label('password', __('Password', 'seoprostack')) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
        );
        do_action('woocommerce_login_form');
        echo '<p class="form-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme"><input class="woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever" /> <span>' . esc_html__('Remember me', 'seoprostack') . '</span></label>';
        wp_nonce_field('woocommerce-login', 'woocommerce-login-nonce');
        printf('<button type="submit" class="woocommerce-button button woocommerce-form-login__submit" name="login" value="%1$s">%1$s</button></p>', esc_attr__('Log in', 'seoprostack'));
        printf('<p class="woocommerce-LostPassword lost_password"><a href="%1$s">%2$s</a></p>', esc_url(wp_lostpassword_url()), esc_html__('Lost your password?', 'seoprostack'));
        do_action('woocommerce_login_form_end');
        echo '</form>';
        if ($signup) {
            echo '</div><div class="u-column2 col-2">';
            self::register_form();
            echo '</div></div>';
        }
        do_action('woocommerce_after_customer_login_form');
    }

    /**
     * Sign up.
     */
    private static function register_form() {
        printf('<h2>%s</h2>', esc_html__('Register', 'seoprostack'));
        echo '<form method="post" class="woocommerce-form woocommerce-form-register register" ';
        do_action('woocommerce_register_form_tag');
        echo '>';
        do_action('woocommerce_register_form_start');
        if (!SEOProStack_Account::generates_username()) {
            printf(
                '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">%1$s<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="reg_username" autocomplete="username" value="%2$s" required aria-required="true" /></p>',
                self::label('reg_username', __('Username', 'seoprostack')), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
                esc_attr(self::posted('username'))
            );
        }
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">%1$s<input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="%2$s" required aria-required="true" /></p>',
            self::label('reg_email', __('Email address', 'seoprostack')), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
            esc_attr(self::posted('email'))
        );
        if (!SEOProStack_Account::generates_password()) {
            printf(
                '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">%s<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password" id="reg_password" autocomplete="new-password" required aria-required="true" /></p>',
                self::label('reg_password', __('Password', 'seoprostack')) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
            );
        } else {
            /**
             * Filter the line under the sign-up form's email field when the
             * password is made up (such as a plugin that emails a
             * confirmation link instead).
             *
             * @param string $line Text.
             */
            $line = (string) apply_filters('seoprostack_account_password_line', __('A link to set a new password will be sent to your email address.', 'seoprostack'));
            if ('' !== $line) {
                printf('<p>%s</p>', esc_html($line));
            }
        }
        do_action('woocommerce_register_form');
        echo '<p class="woocommerce-form-row form-row">';
        wp_nonce_field('woocommerce-register', 'woocommerce-register-nonce');
        printf('<button type="submit" class="woocommerce-Button woocommerce-button button woocommerce-form-register__submit" name="register" value="%1$s">%1$s</button></p>', esc_attr__('Register', 'seoprostack'));
        do_action('woocommerce_register_form_end');
        echo '</form>';
    }

    /**
     * WooCommerce's registration privacy text, with its policy links.
     */
    public static function privacy_text() {
        $text = get_option('woocommerce_registration_privacy_policy_text', __('Your personal data will be used to support your experience throughout this website, to manage access to your account, and for other purposes described in our [privacy_policy].', 'seoprostack'));
        $text = trim((string) $text);
        if ('' === $text) {
            return;
        }
        $links = array(
            '[privacy_policy]' => array((int) get_option('wp_page_for_privacy_policy'), 'woocommerce-privacy-policy-link', __('privacy policy', 'seoprostack')),
            '[terms]'          => array((int) get_option('woocommerce_terms_page_id'), 'woocommerce-terms-and-conditions-link', __('terms and conditions', 'seoprostack')),
        );
        foreach ($links as $tag => $link) {
            if (false === strpos($text, $tag)) {
                continue;
            }
            $html = esc_html($link[2]);
            // Any status, as WooCommerce (wc_replace_policy_page_link_placeholders()).
            if ($link[0] > 0 && false !== get_post_status($link[0])) {
                $html = sprintf('<a href="%1$s" class="%2$s" target="_blank">%3$s</a>', esc_url((string) get_permalink($link[0])), esc_attr($link[1]), $html);
            }
            $text = str_replace($tag, $html, $text);
        }
        echo '<div class="woocommerce-privacy-policy-text">' . wp_kses_post(wpautop($text)) . '</div>';
    }

    /**
     * Lost password: the form, the reset form from the emailed link, or
     * "sent".
     */
    private static function lost_password() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- choose which form shows; the reset key is checked.
        if (!empty($_GET['reset-link-sent'])) {
            SEOProStack_Account::print_notices();
            printf('<div class="woocommerce-message" role="alert">%s</div>', esc_html__('Password reset email has been sent.', 'seoprostack'));
            do_action('woocommerce_before_lost_password_confirmation_message');
            printf('<p>%s</p>', esc_html((string) apply_filters('woocommerce_lost_password_confirmation_message', __('A password reset email has been sent to the email address on file for your account, but may take several minutes to show up in your inbox. Please wait at least 10 minutes before attempting another reset.', 'seoprostack'))));
            do_action('woocommerce_after_lost_password_confirmation_message');
            return;
        }
        if (!empty($_GET['show-reset-form'])) {
            list($id, $key) = self::reset_cookie();
            $user  = $id ? get_userdata($id) : false;
            $login = $user ? $user->user_login : '';
            if ('' !== $key && '' !== $login && self::check_key($key, $login) instanceof WP_User) {
                self::reset_form($key, $login);
                return;
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        SEOProStack_Account::print_notices();
        do_action('woocommerce_before_lost_password_form');
        echo '<form method="post" class="woocommerce-ResetPassword lost_reset_password">';
        printf('<p>%s</p>', esc_html((string) apply_filters('woocommerce_lost_password_message', __('Lost your password? Please enter your username or email address. You will receive a link to create a new password via email.', 'seoprostack'))));
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">%s<input class="woocommerce-Input woocommerce-Input--text input-text" type="text" name="user_login" id="user_login" autocomplete="username" required aria-required="true" /></p><div class="clear"></div>',
            self::label('user_login', __('Username or email', 'seoprostack')) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
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
        SEOProStack_Account::print_notices();
        do_action('woocommerce_before_reset_password_form');
        echo '<form method="post" class="woocommerce-ResetPassword lost_reset_password">';
        printf('<p>%s</p>', esc_html((string) apply_filters('woocommerce_reset_password_message', __('Enter a new password below.', 'seoprostack'))));
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">%1$s<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_1" id="password_1" autocomplete="new-password" required aria-required="true" /></p><p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">%2$s<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_2" id="password_2" autocomplete="new-password" required aria-required="true" /></p>',
            self::label('password_1', __('New password', 'seoprostack')), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
            self::label('password_2', __('Re-enter new password', 'seoprostack')) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
        );
        printf('<input type="hidden" name="reset_key" value="%1$s" /><input type="hidden" name="reset_login" value="%2$s" /><div class="clear"></div>', esc_attr($key), esc_attr($login));
        do_action('woocommerce_resetpassword_form');
        printf('<p class="woocommerce-form-row form-row"><input type="hidden" name="wc_reset_password" value="true" /><button type="submit" class="woocommerce-Button button" value="%1$s">%1$s</button></p>', esc_attr__('Save', 'seoprostack'));
        wp_nonce_field('reset_password', 'woocommerce-reset-password-nonce');
        echo '</form>';
        do_action('woocommerce_after_reset_password_form');
    }

    /**
     * Account details.
     */
    public static function edit_account() {
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

    /* ------------------------------------------------------------------
     * Redirects
     * ------------------------------------------------------------------ */

    /**
     * The emailed reset link (to the reset form, its key in a cookie, so
     * it is not left in the address), and Log out.
     */
    public static function template_redirect() {
        if (!SEOProStack_Account::is_account_page()) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the reset key is the proof, checked on the form; Log out checks its nonce.
        if (isset($_GET['key']) && (isset($_GET['id']) || isset($_GET['login']))) {
            if (isset($_GET['login'])) {
                $user    = get_user_by('login', sanitize_user(wp_unslash((string) $_GET['login'])));
                $user_id = $user ? (int) $user->ID : 0;
            } else {
                $user_id = absint($_GET['id']);
            }
            $current = get_current_user_id();
            if ($current && $current !== $user_id) {
                SEOProStack_Account::add_notice(__('This password reset key is for a different user account. Please log out and try again.', 'seoprostack'), 'error');
                return;
            }
            $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
            self::set_reset_cookie(sprintf('%d:%s', $user_id, sanitize_text_field(wp_unslash((string) $_GET['key']))));
            wp_safe_redirect(add_query_arg(array_filter(array('show-reset-form' => 'true', 'action' => $action)), SEOProStack_Account::endpoint_url('lost-password')));
            exit;
        }
        $endpoint = SEOProStack_Account::current_endpoint();
        if ('customer-logout' !== $endpoint) {
            return;
        }
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_key(wp_unslash((string) $_REQUEST['_wpnonce'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ('' !== $nonce && wp_verify_nonce($nonce, 'customer-logout')) {
            $to = SEOProStack_Account::logout_redirect_url();
            wp_logout();
            wp_safe_redirect($to);
            exit;
        }
        // As wc_template_redirect(): ask, on My Account's dashboard.
        SEOProStack_Account::add_notice(sprintf(
            /* translators: %s: logout address */
            __('Are you sure you want to log out? <a href="%s">Confirm and log out</a>', 'seoprostack'),
            esc_url(SEOProStack_Account::logout_url())
        ));
        wp_safe_redirect(SEOProStack_Account::page_url());
        exit;
    }

    /**
     * The reset cookie's name, WooCommerce's (wp-resetpass- and the site's
     * cookie hash, which WordPress defines before plugins load).
     *
     * @return string
     */
    private static function reset_cookie_name() {
        return self::RESET_COOKIE . (defined('COOKIEHASH') ? COOKIEHASH : md5(site_url()));
    }

    /**
     * The reset cookie's user ID and key.
     *
     * @return array{0:int,1:string}
     */
    private static function reset_cookie() {
        $name  = self::reset_cookie_name();
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
    private static function set_reset_cookie($value = '') {
        $name = self::reset_cookie_name();
        $path = isset($_SERVER['REQUEST_URI']) ? (string) current(explode('?', sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])))) : '';
        if ('' !== $value) {
            setcookie($name, $value, 0, $path, COOKIE_DOMAIN, is_ssl(), true);
        } else {
            setcookie($name, ' ', time() - YEAR_IN_SECONDS, $path, COOKIE_DOMAIN, is_ssl(), true);
        }
    }

    /**
     * Check a reset key; a notice when it does not work.
     *
     * @param string $key   Key.
     * @param string $login Username.
     * @return WP_User|false
     */
    private static function check_key($key, $login) {
        $user = check_password_reset_key($key, $login);
        if ($user instanceof WP_User) {
            return $user;
        }
        SEOProStack_Account::add_notice(__('This key is invalid or has already been used. Please reset your password again if needed.', 'seoprostack'), 'error');
        return false;
    }

    /**
     * Where to go after logging in or signing up: the posted address, the
     * page the form was on, or My Account.
     *
     * @return string
     */
    private static function posted_redirect() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- called after the form's nonce was checked; validated by the caller.
        $posted = isset($_POST['redirect']) ? esc_url_raw(wp_unslash((string) $_POST['redirect'])) : '';
        if ('' !== $posted) {
            return $posted;
        }
        $referer = wp_get_raw_referer();
        return $referer ? (string) $referer : SEOProStack_Account::page_url();
    }

    /* ------------------------------------------------------------------
     * Logging in
     * ------------------------------------------------------------------ */

    /**
     * Log in (WC_Form_Handler::process_login()).
     */
    public static function process_login() {
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
            self::login_failed('<strong>' . esc_html__('Error:', 'seoprostack') . '</strong> ' . $error->get_error_message());
            return;
        }
        if ('' === $creds['user_login']) {
            self::login_failed('<strong>' . esc_html__('Error:', 'seoprostack') . '</strong> ' . esc_html__('Username is required.', 'seoprostack'));
            return;
        }
        // On a network, an account from another site joins this one.
        if (is_multisite()) {
            $found = get_user_by(is_email($creds['user_login']) ? 'email' : 'login', $creds['user_login']);
            if ($found && !is_user_member_of_blog($found->ID, get_current_blog_id())) {
                add_user_to_blog(get_current_blog_id(), $found->ID, self::role());
            }
        }
        $user = wp_signon(apply_filters('woocommerce_login_credentials', $creds), is_ssl());
        if (is_wp_error($user)) {
            self::login_failed($user->get_error_message());
            return;
        }
        $redirect = remove_query_arg('wc_error', self::posted_redirect());
        wp_redirect(wp_validate_redirect((string) apply_filters('woocommerce_login_redirect', $redirect, $user), SEOProStack_Account::page_url())); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated with wp_validate_redirect().
        exit;
    }

    /**
     * A login error.
     *
     * @param string $message HTML.
     */
    private static function login_failed($message) {
        SEOProStack_Account::add_notice((string) apply_filters('login_errors', $message), 'error'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's filter.
        do_action('woocommerce_login_failed');
    }

    /* ------------------------------------------------------------------
     * Signing up
     * ------------------------------------------------------------------ */

    /**
     * The role of new accounts: WooCommerce's Customer while it exists.
     *
     * @return string
     */
    private static function role() {
        $role = get_role('customer') ? 'customer' : (string) get_option('default_role', 'subscriber');
        /**
         * Filter the role of accounts made on My Account.
         *
         * @param string $role Default: customer, else the site's default role.
         */
        return (string) apply_filters('seoprostack_account_role', $role);
    }

    /**
     * Sign up (WC_Form_Handler::process_registration()).
     */
    public static function process_registration() {
        if (!isset($_POST['register'], $_POST['email'], $_POST['woocommerce-register-nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash((string) $_POST['woocommerce-register-nonce'])), 'woocommerce-register')) {
            return;
        }
        if (!SEOProStack_Account::signups_open()) {
            return;
        }
        $username = !SEOProStack_Account::generates_username() && isset($_POST['username']) ? sanitize_user(wp_unslash((string) $_POST['username'])) : '';
        $password = !SEOProStack_Account::generates_password() && isset($_POST['password']) ? (string) $_POST['password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- a password, as WordPress and WooCommerce take it (slashed), so passwords they saved keep working.
        $email    = sanitize_email(wp_unslash((string) $_POST['email']));

        $error    = apply_filters('woocommerce_process_registration_errors', new WP_Error(), $username, $password, $email);
        $messages = $error instanceof WP_Error ? $error->get_error_messages() : array();
        if ($messages) {
            foreach ($messages as $message) {
                SEOProStack_Account::add_notice('<strong>' . esc_html__('Error:', 'seoprostack') . '</strong> ' . $message, 'error');
            }
            return;
        }
        $customer = self::create_customer($email, $username, $password);
        if (is_wp_error($customer)) {
            SEOProStack_Account::add_notice('<strong>' . esc_html__('Error:', 'seoprostack') . '</strong> ' . $customer->get_error_message(), 'error');
            return;
        }
        SEOProStack_Account::add_notice(SEOProStack_Account::generates_password()
            ? __('Your account was created successfully and a password has been sent to your email address.', 'seoprostack')
            : __('Your account was created successfully. Your login details have been sent to your email address.', 'seoprostack'));
        if (!apply_filters('woocommerce_registration_auth_new_customer', true, $customer)) {
            return;
        }
        wp_set_current_user($customer);
        wp_set_auth_cookie($customer, true);
        $redirect = (string) apply_filters('woocommerce_registration_redirect', wp_sanitize_redirect(self::posted_redirect()), $customer);
        wp_redirect(wp_validate_redirect($redirect, SEOProStack_Account::page_url())); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated with wp_validate_redirect().
        exit;
    }

    /**
     * Make an account (wc_create_new_customer()), firing WooCommerce's
     * hooks, so plugins' checks and what they do with new accounts work.
     *
     * @param string $email    Email address.
     * @param string $username Username ('' to make one up when that is on).
     * @param string $password Password ('' to make one up when that is on).
     * @return int|WP_Error User ID.
     */
    public static function create_customer($email, $username = '', $password = '') {
        if ('' === $email || !is_email($email)) {
            return new WP_Error('registration-error-invalid-email', __('Please provide a valid email address.', 'seoprostack'));
        }
        if (email_exists($email)) {
            return new WP_Error('registration-error-email-exists', __('An account is already registered with your email address. Please log in.', 'seoprostack'));
        }
        if ('' === $username && SEOProStack_Account::generates_username()) {
            $username = self::new_username($email);
        }
        $username = sanitize_user($username);
        if ('' === $username || !validate_username($username)) {
            return new WP_Error('registration-error-invalid-username', __('Please enter a valid account username.', 'seoprostack'));
        }
        if (username_exists($username)) {
            return new WP_Error('registration-error-username-exists', __('An account is already registered with that username. Please choose another.', 'seoprostack'));
        }
        $generated = false;
        if ('' === $password && SEOProStack_Account::generates_password()) {
            $password  = wp_generate_password();
            $generated = true;
        }
        if ('' === $password) {
            return new WP_Error('registration-error-missing-password', __('Please enter an account password.', 'seoprostack'));
        }
        $errors = new WP_Error();
        do_action('woocommerce_register_post', $username, $email, $errors);
        $errors = apply_filters('woocommerce_registration_errors', $errors, $username, $email);
        if ($errors instanceof WP_Error && $errors->get_error_code()) {
            return $errors;
        }
        $data = apply_filters(
            'woocommerce_new_customer_data',
            array(
                'user_login' => $username,
                'user_pass'  => $password,
                'user_email' => $email,
                'role'       => self::role(),
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

    /* ------------------------------------------------------------------
     * Lost password
     * ------------------------------------------------------------------ */

    /**
     * Send the reset link (WC_Form_Handler::process_lost_password()).
     */
    public static function process_lost_password() {
        if (!isset($_POST['wc_reset_password'], $_POST['user_login'], $_POST['woocommerce-lost-password-nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash((string) $_POST['woocommerce-lost-password-nonce'])), 'lost_password')) {
            return;
        }
        if (self::send_reset_link(trim(sanitize_text_field(wp_unslash((string) $_POST['user_login']))))) {
            wp_safe_redirect(add_query_arg('reset-link-sent', 'true', SEOProStack_Account::endpoint_url('lost-password')));
            exit;
        }
    }

    /**
     * Email a reset link to My Account's reset form
     * (WC_Shortcode_My_Account::retrieve_password()).
     *
     * @param string $login Username or email address.
     * @return bool
     */
    private static function send_reset_link($login) {
        if ('' === $login) {
            SEOProStack_Account::add_notice(__('Enter a username or email address.', 'seoprostack'), 'error');
            return false;
        }
        $user = get_user_by('login', $login);
        if (!$user && is_email($login)) {
            $user = get_user_by('email', $login);
        }
        $errors = new WP_Error();
        do_action('lostpassword_post', $errors, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's action.
        if ($errors->get_error_code()) {
            SEOProStack_Account::add_notice($errors->get_error_message(), 'error');
            return false;
        }
        if (!$user instanceof WP_User || (is_multisite() && !is_user_member_of_blog($user->ID, get_current_blog_id()))) {
            SEOProStack_Account::add_notice(__('Invalid username or email.', 'seoprostack'), 'error');
            return false;
        }
        do_action('retrieve_password', $user->user_login); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's action.
        $allow = apply_filters('allow_password_reset', true, $user->ID); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's filter.
        if (!$allow || is_wp_error($allow)) {
            SEOProStack_Account::add_notice(is_wp_error($allow) ? $allow->get_error_message() : __('Password reset is not allowed for this user.', 'seoprostack'), 'error');
            return false;
        }
        $key = get_password_reset_key($user);
        if (is_wp_error($key)) {
            SEOProStack_Account::add_notice($key->get_error_message(), 'error');
            return false;
        }
        $link    = add_query_arg(array('key' => $key, 'id' => $user->ID), SEOProStack_Account::endpoint_url('lost-password'));
        $site    = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        /* translators: %s: site name */
        $subject = sprintf(__('[%s] Password Reset', 'seoprostack'), $site);
        $message = __('Someone has requested a password reset for the following account:', 'seoprostack') . "\r\n\r\n"
            /* translators: %s: site name */
            . sprintf(__('Site Name: %s', 'seoprostack'), $site) . "\r\n\r\n"
            /* translators: %s: username */
            . sprintf(__('Username: %s', 'seoprostack'), $user->user_login) . "\r\n\r\n"
            . __('If this was a mistake, ignore this email and nothing will happen.', 'seoprostack') . "\r\n\r\n"
            . __('To reset your password, visit the following address:', 'seoprostack') . "\r\n\r\n"
            . $link . "\r\n";
        // WordPress's filters of its own reset email.
        $subject = (string) apply_filters('retrieve_password_title', $subject, $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's filter.
        $message = (string) apply_filters('retrieve_password_message', $message, $key, $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's filter.
        if ('' !== $message && !wp_mail($user->user_email, wp_specialchars_decode($subject), $message)) {
            SEOProStack_Account::add_notice(__('The email could not be sent. Please try again later, or contact the site.', 'seoprostack'), 'error');
            return false;
        }
        return true;
    }

    /**
     * Save a new password (WC_Form_Handler::process_reset_password()).
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
        $user  = self::check_key(sanitize_text_field(wp_unslash((string) $_POST['reset_key'])), sanitize_user(wp_unslash((string) $_POST['reset_login'])));
        if (!$user instanceof WP_User) {
            return;
        }
        if ('' === $pass1) {
            SEOProStack_Account::add_notice(__('Please enter your password.', 'seoprostack'), 'error');
        }
        if ($pass1 !== $pass2) {
            SEOProStack_Account::add_notice(__('Passwords do not match.', 'seoprostack'), 'error');
        }
        $errors = new WP_Error();
        do_action('validate_password_reset', $errors, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's action.
        foreach ($errors->get_error_messages() as $message) {
            SEOProStack_Account::add_notice($message, 'error');
        }
        if (SEOProStack_Account::notice_count('error') > 0) {
            return;
        }
        reset_password($user, $pass1);
        self::set_reset_cookie();
        do_action('woocommerce_customer_reset_password', $user);
        wp_safe_redirect(add_query_arg('password-reset', 'true', SEOProStack_Account::page_url()));
        exit;
    }

    /* ------------------------------------------------------------------
     * Account details
     * ------------------------------------------------------------------ */

    /**
     * Save account details (WC_Form_Handler::save_account_details()).
     */
    public static function save_account_details() {
        if (!isset($_POST['save-account-details-nonce'], $_POST['action'])
            || !wp_verify_nonce(sanitize_key(wp_unslash((string) $_POST['save-account-details-nonce'])), 'save_account_details')
            || 'save_account_details' !== $_POST['action']) {
            return;
        }
        nocache_headers();
        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return;
        }
        $field = static function ($name) {
            return isset($_POST[$name]) ? sanitize_text_field(wp_unslash((string) $_POST[$name])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
        };
        $first    = $field('account_first_name');
        $last     = $field('account_last_name');
        $display  = $field('account_display_name');
        $email    = $field('account_email');
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- passwords, as WordPress and WooCommerce take them (slashed).
        $pass_cur = isset($_POST['password_current']) ? (string) $_POST['password_current'] : '';
        $pass1    = isset($_POST['password_1']) ? (string) $_POST['password_1'] : '';
        $pass2    = isset($_POST['password_2']) ? (string) $_POST['password_2'] : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $save_pass = true;
        $current   = get_user_by('id', $user_id);

        $user               = new stdClass();
        $user->ID           = $user_id;
        $user->first_name   = $first;
        $user->last_name    = $last;
        $user->display_name = $display;

        if (is_email($display)) {
            SEOProStack_Account::add_notice(__('Display name cannot be changed to email address due to privacy concern.', 'seoprostack'), 'error');
        }
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
            if ('' === $field((string) $key)) {
                /* translators: %s: field name */
                SEOProStack_Account::add_notice(sprintf(__('%s is a required field.', 'seoprostack'), '<strong>' . esc_html((string) $name) . '</strong>'), 'error', array('id' => (string) $key));
            }
        }
        if ('' !== $email) {
            $email = sanitize_email($email);
            if (!is_email($email)) {
                SEOProStack_Account::add_notice(__('Please provide a valid email address.', 'seoprostack'), 'error');
            } elseif (email_exists($email) && $current && $email !== $current->user_email) {
                SEOProStack_Account::add_notice(__('This email address is already registered.', 'seoprostack'), 'error');
            }
            $user->user_email = $email;
        }
        if ('' !== $pass_cur && '' === $pass1 && '' === $pass2) {
            SEOProStack_Account::add_notice(__('Please fill out all password fields.', 'seoprostack'), 'error');
            $save_pass = false;
        } elseif ('' !== $pass1 && '' === $pass_cur) {
            SEOProStack_Account::add_notice(__('Please enter your current password.', 'seoprostack'), 'error');
            $save_pass = false;
        } elseif ('' !== $pass1 && '' === $pass2) {
            SEOProStack_Account::add_notice(__('Please re-enter your password.', 'seoprostack'), 'error');
            $save_pass = false;
        } elseif (('' !== $pass1 || '' !== $pass2) && $pass1 !== $pass2) {
            SEOProStack_Account::add_notice(__('New passwords do not match.', 'seoprostack'), 'error');
            $save_pass = false;
        } elseif ('' !== $pass1 && $current && !wp_check_password($pass_cur, $current->user_pass, $current->ID)) {
            SEOProStack_Account::add_notice(__('Your current password is incorrect.', 'seoprostack'), 'error');
            $save_pass = false;
        }
        if ('' !== $pass1 && $save_pass) {
            $user->user_pass = $pass1;
        }
        $errors = new WP_Error();
        do_action_ref_array('woocommerce_save_account_details_errors', array(&$errors, &$user));
        foreach ($errors->get_error_messages() as $message) {
            SEOProStack_Account::add_notice($message, 'error');
        }
        if (SEOProStack_Account::notice_count('error') > 0) {
            return;
        }
        wp_update_user($user);
        SEOProStack_Account::add_notice(__('Account details changed successfully.', 'seoprostack'));
        do_action('woocommerce_save_account_details', $user->ID);
        wp_safe_redirect(SEOProStack_Account::endpoint_url('edit-account'));
        exit;
    }
}
