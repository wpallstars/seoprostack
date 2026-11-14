<?php
/**
 * My Account without WooCommerce.
 *
 * A site that kept WooCommerce only for My Account (log in, sign up, lost
 * password, account details and the tabs other plugins add) carries a shop's
 * code on every request for it. This serves the same page without
 * WooCommerce: the [woocommerce_my_account] shortcode on the page WooCommerce
 * made (or [seoprostack_account] on any page), with WooCommerce's markup,
 * classes, form fields and hooks, so:
 *
 * - tabs other plugins add (woocommerce_get_query_vars,
 *   woocommerce_account_menu_items, woocommerce_account_{endpoint}_endpoint,
 *   woocommerce_endpoint_{endpoint}_title) keep working;
 * - fields and checks they add to the forms (woocommerce_register_form,
 *   woocommerce_register_post, woocommerce_created_customer,
 *   woocommerce_edit_account_form, woocommerce_save_account_details and
 *   their errors, lostpassword_post, validate_password_reset) keep working,
 *   Simple Cloudflare Turnstile's WooCommerce checks included;
 * - the Kadence theme's account layout (navigation, avatar, its WooCommerce
 *   styles) looks the same.
 *
 * Orders, Downloads, Addresses and Payment methods are the shop's and stay
 * WooCommerce's. While WooCommerce is active it serves My Account and this
 * does nothing.
 *
 * Other plugins check SEOProStack_Account::available() and use page_url(),
 * endpoint_url(), account_endpoint_url(), is_account_page() and add_notice()
 * where they would use WooCommerce's functions. This class is that interface
 * and the feature's settings; the work is in includes/account/, loaded only
 * where the feature serves the page.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.218
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/account/class-seoprostack-account-page.php';

class SEOProStack_Account extends SEOProStack_Feature {

    const KEY = 'account_pages';

    /** Setting key: people may sign up on My Account. */
    const SIGNUP_KEY = 'account_signup';

    /** Shortcode of the page, any theme. */
    const SHORTCODE = 'seoprostack_account';

    /** WooCommerce's shortcode, on the page it made. */
    const WC_SHORTCODE = 'woocommerce_my_account';

    /** Files in includes/account/ (class-seoprostack-account-{part}.php), loaded when the feature serves the page. */
    const PARTS = array('endpoints', 'front-page', 'links', 'notices', 'view', 'kadence', 'turnstile', 'forms', 'login', 'signup', 'customer', 'password-form', 'password-reset', 'details');

    /**
     * Request cache of available().
     *
     * @var bool|null
     */
    private static $available = null;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY        => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'content',
                'label'       => __('My Account without WooCommerce', 'seoprostack'),
                'description' => __('Keeps My Account working when WooCommerce is not active: log in, sign up, lost password, account details and the tabs other plugins add, with WooCommerce’s layout, on the page WooCommerce made (or a page with the [seoprostack_account] shortcode). So a site that has no shop no longer needs WooCommerce for it. Orders, Downloads and Addresses are the shop’s and need WooCommerce. Does nothing while WooCommerce is active.', 'seoprostack'),
            ),
            self::SIGNUP_KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('People can sign up', 'seoprostack'),
                'description' => __('My Account shows a sign-up form beside the login form. Starts as WooCommerce’s “Allow customers to create an account on the My account page”.', 'seoprostack'),
            ),
        );
    }

    /**
     * Import WooCommerce's sign-up switch.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        if ($from_version < 36 && false !== get_option('woocommerce_enable_myaccount_registration', false)) {
            $options = self::import_setting($options, self::SIGNUP_KEY, 'yes' === get_option('woocommerce_enable_myaccount_registration'));
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        // Before available(): a page published with the shortcode later is found.
        add_action('save_post_page', array('SEOProStack_Account_Page', 'forget'));
        add_action('deleted_post', array('SEOProStack_Account_Page', 'forget'));
        if (!self::available()) {
            return;
        }
        SEOProStack_Account_Endpoints::boot();
        SEOProStack_Account_Links::boot();

        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        SEOProStack_Account_Forms::boot();
        SEOProStack_Account_View::boot();
        add_filter('wp_redirect', array('SEOProStack_Account_Notices', 'keep'), PHP_INT_MAX);
        if ('kadence' === get_template() && function_exists('Kadence\kadence')) {
            SEOProStack_Account_Kadence::boot();
        }
        SEOProStack_Account_Turnstile::boot();
    }

    /**
     * Load the classes in includes/account/.
     */
    private static function load() {
        foreach (self::PARTS as $part) {
            require_once dirname(__DIR__) . '/account/class-seoprostack-account-' . $part . '.php';
        }
    }

    /**
     * Whether WooCommerce is active here (stored as active, even on a
     * request where Load plugins only where needed skips it).
     *
     * @return bool
     */
    private static function woocommerce_active() {
        return class_exists('WooCommerce', false) || isset(self::active_plugins()['woocommerce']);
    }

    /**
     * Whether this serves My Account: switched on, WooCommerce not active,
     * and an account page.
     *
     * @return bool
     */
    public static function available() {
        if (null === self::$available) {
            self::$available = self::enabled() && !self::woocommerce_active() && self::get_page_id() > 0;
            if (self::$available) {
                self::load();
            }
        }
        return self::$available;
    }

    /**
     * The account page: WooCommerce's My account page while it is
     * published, else the first published page with the shortcode.
     *
     * Filter: `seoprostack_account_page` (a page ID, or 0 for none).
     *
     * @return int
     */
    public static function get_page_id() {
        return SEOProStack_Account_Page::get_id();
    }

    /**
     * The account page's address, or '' without one.
     *
     * @return string
     */
    public static function page_url() {
        $id = self::get_page_id();
        return $id ? (string) get_permalink($id) : '';
    }

    /**
     * Where the account page answers, for Load plugins only where needed,
     * which loads every plugin there (other plugins' tabs and form checks
     * use hooks its learning does not see): the page's path from the
     * site's address ('' when it is the front page) and the tabs' slugs.
     * Empty while this does not serve My Account.
     *
     * @return array{path?: string, slugs?: string[]}
     */
    public static function loader_paths() {
        if (!self::available()) {
            return array();
        }
        $id = self::get_page_id();
        return array(
            'path'  => ('page' === get_option('show_on_front') && (int) get_option('page_on_front') === $id) ? '' : (string) get_page_uri($id),
            'slugs' => array_values(SEOProStack_Account_Endpoints::get_all()),
        );
    }

    /**
     * Whether this is the account page (any tab).
     *
     * @return bool
     */
    public static function is_account_page() {
        return self::available() && is_page(self::get_page_id());
    }

    /**
     * Whether people may sign up on My Account.
     *
     * @return bool
     */
    public static function signups_open() {
        return (bool) SEOProStack_Settings::get(self::SIGNUP_KEY);
    }

    /**
     * Address of an endpoint, as wc_get_endpoint_url() makes it, through
     * woocommerce_get_endpoint_url. Only while available().
     *
     * @param string $endpoint  Endpoint key.
     * @param string $value     Value after the slug.
     * @param string $permalink Page address; the account page by default.
     * @return string
     */
    public static function endpoint_url($endpoint, $value = '', $permalink = '') {
        return SEOProStack_Account_Endpoints::url($endpoint, $value, $permalink);
    }

    /**
     * Address of a menu item, as wc_get_account_endpoint_url() makes it.
     * Only while available().
     *
     * @param string $endpoint Endpoint key.
     * @return string
     */
    public static function account_endpoint_url($endpoint) {
        return SEOProStack_Account_Links::menu_url($endpoint);
    }

    /**
     * Add a notice to My Account, as wc_add_notice() does. Only while
     * available().
     *
     * @param string $message HTML.
     * @param string $type    error, success or notice.
     * @param array  $data    Data (an id, for errors about a field).
     */
    public static function add_notice($message, $type = 'success', $data = array()) {
        SEOProStack_Account_Notices::add($message, $type, $data);
    }

    /**
     * Drop every notice. Only while available().
     */
    public static function clear_notices() {
        SEOProStack_Account_Notices::clear();
    }
}
