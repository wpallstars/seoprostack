<?php
/**
 * My Account without WooCommerce: what the Kadence theme adds to My Account
 * while WooCommerce is active (its navigation wrapper and avatar, menu
 * classes, body class and WooCommerce styles).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.218
 */

// WooCommerce's and Kadence's hook names.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Account_Kadence {

    /**
     * Register hooks (front end, Kadence active).
     */
    public static function boot() {
        add_action('woocommerce_before_account_navigation', array(__CLASS__, 'wrap_start'), 2);
        add_action('woocommerce_before_account_navigation', array(__CLASS__, 'avatar'), 20);
        add_action('woocommerce_after_account_navigation', array(__CLASS__, 'wrap_end'), 50);
        add_filter('woocommerce_account_menu_item_classes', array(__CLASS__, 'menu_class'));
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('kadence_css_files', array(__CLASS__, 'css_files'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'inline_css'), 20);
    }

    /**
     * A Kadence theme option.
     *
     * @param string $name Option.
     * @return mixed
     */
    private static function option($name) {
        $theme = \Kadence\kadence();
        // option() is a template tag Kadence's theme object answers through
        // __call(), so method_exists() does not see it.
        return is_object($theme) && is_callable(array($theme, 'option')) ? $theme->option($name) : null;
    }

    /**
     * Open Kadence's navigation wrapper.
     */
    public static function wrap_start() {
        echo '<div class="account-navigation-wrap">';
    }

    /**
     * Close it.
     */
    public static function wrap_end() {
        echo '</div>';
    }

    /**
     * Kadence's avatar and name above the navigation.
     */
    public static function avatar() {
        $user = wp_get_current_user();
        if (!$user->exists() || !self::option('woo_account_navigation_avatar')) {
            return;
        }
        printf(
            '<div class="kadence-account-avatar"><div class="kadence-customer-image"><a class="kt-link-to-gravatar" href="https://gravatar.com/" target="_blank" rel="no" title="%1$s">%2$s</a></div><div class="kadence-customer-name">%3$s</div></div>',
            esc_attr__('Update Profile Photo', 'seoprostack'),
            get_avatar($user->ID, 60), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress's avatar markup.
            esc_html($user->display_name)
        );
    }

    /**
     * Kadence's menu-item class on each menu item.
     *
     * @param mixed $classes Classes.
     * @return mixed
     */
    public static function menu_class($classes) {
        if (is_array($classes)) {
            $classes[] = 'menu-item';
        }
        return $classes;
    }

    /**
     * Kadence's navigation layout class.
     *
     * @param mixed $classes Classes.
     * @return mixed
     */
    public static function body_class($classes) {
        if (is_array($classes) && SEOProStack_Account::is_account_page()) {
            $layout    = (string) self::option('woo_account_navigation_layout');
            $classes[] = 'kadence-account-nav-' . sanitize_html_class('' !== $layout ? $layout : 'right');
        }
        return $classes;
    }

    /**
     * Kadence's WooCommerce and account styles on the account page.
     *
     * @param mixed $files Handle => file data.
     * @return mixed
     */
    public static function css_files($files) {
        if (!is_array($files) || !SEOProStack_Account::is_account_page()) {
            return $files;
        }
        foreach (array('kadence-woocommerce', 'kadence-account-woocommerce') as $handle) {
            if (isset($files[$handle]) && is_array($files[$handle])) {
                $files[$handle]['global'] = true;
            }
        }
        return $files;
    }

    /**
     * WooCommerce's rule that shows required fields' asterisks.
     */
    public static function inline_css() {
        if (SEOProStack_Account::is_account_page() && wp_style_is('kadence-woocommerce', 'enqueued')) {
            wp_add_inline_style('kadence-woocommerce', '.woocommerce form .form-row .required { visibility: visible; }');
        }
    }
}
