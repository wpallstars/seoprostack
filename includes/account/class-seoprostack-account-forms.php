<?php
/**
 * My Account without WooCommerce: the page (the shortcode), its menu, the
 * dashboard and the login and sign-up forms.
 *
 * Markup, field names, nonces and hooks are WooCommerce's (its templates
 * myaccount/form-login.php, navigation.php, my-account.php and
 * dashboard.php), so themes' styles and the fields and checks other plugins
 * add keep working. What the forms send is handled by
 * SEOProStack_Account_Login, _Signup, _Password_Reset and _Details.
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

    /**
     * Register hooks (front end and admin-ajax.php).
     */
    public static function boot() {
        add_shortcode(SEOProStack_Account::SHORTCODE, array(__CLASS__, 'shortcode'));
        add_shortcode(SEOProStack_Account::WC_SHORTCODE, array(__CLASS__, 'shortcode'));
        add_action('woocommerce_account_navigation', array(__CLASS__, 'navigation'));
        add_action('woocommerce_account_content', array(__CLASS__, 'content'));
        // Before fields other plugins add at 20, where WooCommerce's text is.
        add_action('woocommerce_register_form', array(__CLASS__, 'privacy_text'), 19);
        SEOProStack_Account_Login::boot();
        SEOProStack_Account_Signup::boot();
        SEOProStack_Account_Password_Reset::boot();
        SEOProStack_Account_Details::boot();
    }

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
        $endpoint = SEOProStack_Account_Endpoints::get_current();
        if (is_user_logged_in() && 'lost-password' !== $endpoint) {
            do_action('woocommerce_account_navigation');
            echo '<div class="woocommerce-MyAccount-content">';
            do_action('woocommerce_account_content');
            echo '</div>';
            return;
        }
        $message = (string) apply_filters('woocommerce_my_account_message', '');
        if ('' !== $message) {
            SEOProStack_Account_Notices::add($message);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chooses a notice only.
        if (!empty($_GET['password-reset'])) {
            SEOProStack_Account_Notices::add(esc_html__('Your password has been reset successfully.', 'seoprostack'));
        }
        if ('lost-password' === $endpoint) {
            SEOProStack_Account_Password_Form::output();
            return;
        }
        self::login_form();
    }

    /**
     * The menu.
     */
    public static function navigation() {
        do_action('woocommerce_before_account_navigation');
        $current = SEOProStack_Account_Endpoints::get_current();
        echo '<nav class="woocommerce-MyAccount-navigation" aria-label="' . esc_attr__('Account pages', 'seoprostack') . '"><ul>';
        foreach (SEOProStack_Account_Links::get_menu_items() as $endpoint => $label) {
            $active  = 'dashboard' === $endpoint ? '' === $current : $endpoint === $current;
            $classes = array('woocommerce-MyAccount-navigation-link', 'woocommerce-MyAccount-navigation-link--' . $endpoint);
            if ($active) {
                $classes[] = 'is-active';
            }
            $classes = (array) apply_filters('woocommerce_account_menu_item_classes', $classes, $endpoint);
            printf(
                '<li class="%1$s"><a href="%2$s"%3$s>%4$s</a></li>',
                esc_attr(implode(' ', array_map('sanitize_html_class', array_map('strval', $classes)))),
                esc_url(SEOProStack_Account_Links::menu_url($endpoint)),
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
        SEOProStack_Account_Notices::output();
        if ($wp instanceof WP) {
            foreach ($wp->query_vars as $key => $value) {
                if ('pagename' !== $key && has_action('woocommerce_account_' . $key . '_endpoint')) {
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
                esc_url(SEOProStack_Account_Links::logout_url())
            )
        );
        printf(
            '<p>%s</p>',
            sprintf(
                /* translators: %s: account details address */
                wp_kses(__('From your account dashboard you can <a href="%s">edit your password and account details</a>.', 'seoprostack'), $html),
                esc_url(SEOProStack_Account_Endpoints::url('edit-account'))
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
    public static function get_posted($name) {
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
    public static function label($for, $label) {
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
        SEOProStack_Account_Notices::output();
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
            esc_attr(self::get_posted('username'))
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
        if (!SEOProStack_Account_Customer::generates_username()) {
            printf(
                '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">%1$s<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="username" id="reg_username" autocomplete="username" value="%2$s" required aria-required="true" /></p>',
                self::label('reg_username', __('Username', 'seoprostack')), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
                esc_attr(self::get_posted('username'))
            );
        }
        printf(
            '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">%1$s<input type="email" class="woocommerce-Input woocommerce-Input--text input-text" name="email" id="reg_email" autocomplete="email" value="%2$s" required aria-required="true" /></p>',
            self::label('reg_email', __('Email address', 'seoprostack')), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
            esc_attr(self::get_posted('email'))
        );
        self::register_password();
        do_action('woocommerce_register_form');
        echo '<p class="woocommerce-form-row form-row">';
        wp_nonce_field('woocommerce-register', 'woocommerce-register-nonce');
        printf('<button type="submit" class="woocommerce-Button woocommerce-button button woocommerce-form-register__submit" name="register" value="%1$s">%1$s</button></p>', esc_attr__('Register', 'seoprostack'));
        do_action('woocommerce_register_form_end');
        echo '</form>';
    }

    /**
     * Sign up's password field, or the line saying a link will be sent.
     */
    private static function register_password() {
        if (!SEOProStack_Account_Customer::generates_password()) {
            printf(
                '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">%s<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password" id="reg_password" autocomplete="new-password" required aria-required="true" /></p>',
                self::label('reg_password', __('Password', 'seoprostack')) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in label().
            );
            return;
        }
        /**
         * Filter the line under the sign-up form's email field when the
         * password is made up (such as a plugin that emails a confirmation
         * link instead).
         *
         * @param string $line Text.
         */
        $line = (string) apply_filters('seoprostack_account_password_line', __('A link to set a new password will be sent to your email address.', 'seoprostack'));
        if ('' !== $line) {
            printf('<p>%s</p>', esc_html($line));
        }
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
            if (false !== strpos($text, $tag)) {
                $text = str_replace($tag, self::policy_link($link[0], $link[1], $link[2]), $text);
            }
        }
        echo '<div class="woocommerce-privacy-policy-text">' . wp_kses_post(wpautop($text)) . '</div>';
    }

    /**
     * A policy page's link, or its name without a page. Any status, as
     * WooCommerce (wc_replace_policy_page_link_placeholders()).
     *
     * @param int    $page  Page ID.
     * @param string $class Link class.
     * @param string $name  Link text.
     * @return string HTML.
     */
    private static function policy_link($page, $class, $name) {
        $html = esc_html($name);
        if ($page > 0 && false !== get_post_status($page)) {
            $html = sprintf('<a href="%1$s" class="%2$s" target="_blank">%3$s</a>', esc_url((string) get_permalink($page)), esc_attr($class), $html);
        }
        return $html;
    }
}
