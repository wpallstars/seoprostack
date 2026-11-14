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
 * endpoint_url(), is_account_page() and add_notice() where they would use
 * WooCommerce's functions. The forms and their handlers are
 * SEOProStack_Account_Forms.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.218
 */

// The page keeps WooCommerce's hook names, so code written for them works.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/class-seoprostack-account-forms.php';

class SEOProStack_Account extends SEOProStack_Feature {

    const KEY = 'account_pages';

    /** Setting key: people may sign up on My Account. */
    const SIGNUP_KEY = 'account_signup';

    /** Shortcode of the page, any theme. */
    const SHORTCODE = 'seoprostack_account';

    /** WooCommerce's shortcode, on the page it made. */
    const WC_SHORTCODE = 'woocommerce_my_account';

    /** Transient: the account page found by its shortcode ('0': none). */
    const PAGE_CACHE = 'seoprostack_account_page';

    /** Option: the endpoints in the rewrite rules (a hash). */
    const FLUSHED = 'seoprostack_account_endpoints';

    /** Transient prefix (+ user ID): notices kept over a redirect. */
    const NOTICES = 'seoprostack_account_notices_';

    /** Script handle of the password strength meter. */
    const METER = 'seoprostack-account-password';

    /**
     * Notices for this request, by type (error, success, notice).
     *
     * @var array<string,array<int,array{0:string,1:array}>>
     */
    private static $notices = array();

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
        if (!self::available()) {
            return;
        }
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('init', array(__CLASS__, 'add_endpoints'), 10);
        add_action('init', array(__CLASS__, 'maybe_flush'), 99);
        add_action('parse_request', array(__CLASS__, 'parse_request'), 0);
        add_action('save_post_page', array(__CLASS__, 'forget_page'));
        add_action('deleted_post', array(__CLASS__, 'forget_page'));
        add_filter('lostpassword_url', array(__CLASS__, 'lostpassword_url'), 10, 1);

        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        add_shortcode(self::SHORTCODE, array('SEOProStack_Account_Forms', 'shortcode'));
        add_shortcode(self::WC_SHORTCODE, array('SEOProStack_Account_Forms', 'shortcode'));
        SEOProStack_Account_Forms::boot();
        add_action('template_redirect', array(__CLASS__, 'no_cache'), 0);
        add_filter('wp_redirect', array(__CLASS__, 'keep_notices'), PHP_INT_MAX);
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_filter('the_title', array(__CLASS__, 'page_title'), 10, 2);
        add_filter('document_title_parts', array(__CLASS__, 'document_title'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
        if ('kadence' === get_template() && function_exists('kadence')) {
            self::boot_kadence();
        }
        self::boot_turnstile();
    }

    /* ------------------------------------------------------------------
     * Where My Account is
     * ------------------------------------------------------------------ */

    /**
     * Whether WooCommerce is active here (stored as active, even on a
     * request where Load plugins only where needed skips it).
     *
     * @return bool
     */
    public static function woocommerce_active() {
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
            self::$available = self::enabled() && !self::woocommerce_active() && self::page_id() > 0;
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
    public static function page_id() {
        $id = (int) get_option('woocommerce_myaccount_page_id');
        if ($id < 1 || 'publish' !== get_post_status($id) || 'page' !== get_post_type($id)) {
            $cached = get_transient(self::PAGE_CACHE);
            if (false === $cached) {
                $cached = '0';
                foreach (array(self::SHORTCODE, self::WC_SHORTCODE) as $code) {
                    $ids = get_posts(
                        array(
                            'post_type'   => 'page',
                            'post_status' => 'publish',
                            's'           => '[' . $code,
                            'fields'      => 'ids',
                            'numberposts' => 5,
                            'orderby'     => 'ID',
                            'order'       => 'ASC',
                        )
                    );
                    foreach ($ids as $found) {
                        if (has_shortcode((string) get_post_field('post_content', (int) $found), $code)) {
                            $cached = (string) (int) $found;
                            break 2;
                        }
                    }
                }
                set_transient(self::PAGE_CACHE, $cached, DAY_IN_SECONDS);
            }
            $id = (int) $cached;
        }
        return max(0, (int) apply_filters('seoprostack_account_page', $id));
    }

    /**
     * A page changed: look for the account page again.
     */
    public static function forget_page() {
        delete_transient(self::PAGE_CACHE);
    }

    /**
     * The account page's address, or '' without one.
     *
     * @return string
     */
    public static function page_url() {
        $id = self::page_id();
        return $id ? (string) get_permalink($id) : '';
    }

    /**
     * Whether this is the account page (any tab).
     *
     * @return bool
     */
    public static function is_account_page() {
        return self::available() && is_page(self::page_id());
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

    /* ------------------------------------------------------------------
     * Tabs (endpoints)
     * ------------------------------------------------------------------ */

    /**
     * Endpoints, key => address slug: WooCommerce's account details, log
     * out and lost password, with WooCommerce's slugs, plus those other
     * plugins add with woocommerce_get_query_vars.
     *
     * @return array<string,string>
     */
    public static function endpoints() {
        $vars = array(
            'edit-account'    => (string) get_option('woocommerce_myaccount_edit_account_endpoint', 'edit-account'),
            'customer-logout' => (string) get_option('woocommerce_logout_endpoint', 'customer-logout'),
            'lost-password'   => (string) get_option('woocommerce_myaccount_lost_password_endpoint', 'lost-password'),
        );
        $vars = apply_filters('woocommerce_get_query_vars', $vars);
        $out  = array();
        foreach (is_array($vars) ? $vars : array() as $key => $slug) {
            $key  = (string) $key;
            $slug = (string) $slug;
            if ('' !== $key && '' !== $slug && preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
                $out[$key] = $slug;
            }
        }
        return $out;
    }

    /**
     * The endpoints' address slugs as query variables.
     *
     * @param mixed $vars Public query variables.
     * @return mixed
     */
    public static function query_vars($vars) {
        if (is_array($vars)) {
            foreach (self::endpoints() as $key => $slug) {
                $vars[] = $key;
                $vars[] = $slug;
            }
            $vars = array_values(array_unique($vars));
        }
        return $vars;
    }

    /**
     * Add the endpoints' addresses (/my-account/edit-account/).
     */
    public static function add_endpoints() {
        foreach (self::endpoints() as $slug) {
            add_rewrite_endpoint($slug, EP_ROOT | EP_PAGES);
        }
    }

    /**
     * Put the endpoints in the rewrite rules when they change, on a request
     * that loads every plugin.
     */
    public static function maybe_flush() {
        if (self::plugins_skipped()) {
            return;
        }
        $hash = md5(SEOPROSTACK_VERSION . '|' . wp_json_encode(self::endpoints()));
        if (get_option(self::FLUSHED) === $hash) {
            return;
        }
        update_option(self::FLUSHED, $hash, false);
        flush_rewrite_rules(false);
    }

    /**
     * Copy each endpoint's value to its key, as WooCommerce does, so code
     * reading $wp->query_vars['edit-account'] works whatever the slug.
     *
     * @param WP $wp Request.
     */
    public static function parse_request($wp) {
        foreach (self::endpoints() as $key => $slug) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an address without pretty permalinks; read as text.
            if (isset($_GET[$slug])) {
                $wp->query_vars[$key] = sanitize_text_field(wp_unslash((string) $_GET[$slug])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the same.
            } elseif (isset($wp->query_vars[$slug])) {
                $wp->query_vars[$key] = $wp->query_vars[$slug];
            }
        }
    }

    /**
     * The tab being viewed (its key), or '' for the dashboard.
     *
     * @return string
     */
    public static function current_endpoint() {
        global $wp;
        if (!$wp instanceof WP) {
            return '';
        }
        foreach (array_keys(self::endpoints()) as $key) {
            if (isset($wp->query_vars[$key])) {
                return $key;
            }
        }
        return '';
    }

    /**
     * The value after the tab's slug in the address (/tab/value/), or ''.
     *
     * @return string
     */
    public static function current_value() {
        global $wp;
        $key = self::current_endpoint();
        return ('' !== $key && $wp instanceof WP) ? (string) $wp->query_vars[$key] : '';
    }

    /**
     * Address of an endpoint, as wc_get_endpoint_url() makes it, through
     * woocommerce_get_endpoint_url.
     *
     * @param string $endpoint  Endpoint key.
     * @param string $value     Value after the slug.
     * @param string $permalink Page address; the account page by default.
     * @return string
     */
    public static function endpoint_url($endpoint, $value = '', $permalink = '') {
        $endpoint  = (string) $endpoint;
        $value     = (string) $value;
        $permalink = '' !== (string) $permalink ? (string) $permalink : self::page_url();
        $vars      = self::endpoints();
        $slug      = isset($vars[$endpoint]) ? $vars[$endpoint] : $endpoint;
        if (get_option('permalink_structure')) {
            $query = '';
            if (false !== strpos($permalink, '?')) {
                $query     = '?' . (string) wp_parse_url($permalink, PHP_URL_QUERY);
                $permalink = current(explode('?', $permalink));
            }
            $url = trailingslashit($permalink) . ('' !== $value ? trailingslashit($slug) . user_trailingslashit($value) : user_trailingslashit($slug)) . $query;
        } else {
            $url = add_query_arg($slug, $value, $permalink);
        }
        return (string) apply_filters('woocommerce_get_endpoint_url', $url, $endpoint, $value, $permalink);
    }

    /**
     * Address of a menu item, as wc_get_account_endpoint_url() makes it.
     *
     * @param string $endpoint Endpoint key.
     * @return string
     */
    public static function account_endpoint_url($endpoint) {
        if ('dashboard' === $endpoint) {
            return self::page_url();
        }
        $url = self::endpoint_url($endpoint);
        return 'customer-logout' === $endpoint ? wp_nonce_url($url, 'customer-logout') : $url;
    }

    /**
     * Where logging out goes, then the account page (wc_logout_url()).
     *
     * @return string
     */
    public static function logout_url() {
        return wp_logout_url((string) apply_filters('woocommerce_logout_default_redirect_url', self::page_url()));
    }

    /**
     * WordPress's lost-password links go to My Account's form, as with
     * WooCommerce (not on wp-login.php's own form, nor for Network Admin).
     *
     * @param mixed $url Default address.
     * @return mixed
     */
    public static function lostpassword_url($url) {
        if (!did_action('init') || did_action('login_form_login')) {
            return $url;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared.
        $to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash((string) $_GET['redirect_to'])) : '';
        if (is_multisite() && '' !== $to && false !== strpos($to, network_admin_url())) {
            return $url;
        }
        return self::endpoint_url('lost-password');
    }

    /**
     * The menu: endpoint => label, through woocommerce_account_menu_items.
     *
     * @return array<string,string>
     */
    public static function menu_items() {
        $items = array(
            'dashboard'       => __('Dashboard', 'seoprostack'),
            'edit-account'    => __('Account details', 'seoprostack'),
            'customer-logout' => __('Log out', 'seoprostack'),
        );
        $vars  = self::endpoints();
        foreach (array_keys($items) as $key) {
            if ('dashboard' !== $key && !isset($vars[$key])) {
                unset($items[$key]);
            }
        }
        $items = apply_filters('woocommerce_account_menu_items', $items, $vars);
        $out   = array();
        foreach (is_array($items) ? $items : array() as $key => $label) {
            $out[(string) $key] = (string) $label;
        }
        return $out;
    }

    /**
     * A tab's title (woocommerce_endpoint_{endpoint}_title).
     *
     * @param string $endpoint Endpoint key.
     * @return string
     */
    public static function endpoint_title($endpoint) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- chooses a title only.
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
        $titles = array(
            'edit-account'    => __('Account details', 'seoprostack'),
            'customer-logout' => __('Log out', 'seoprostack'),
            'lost-password'   => in_array($action, array('rp', 'resetpass', 'newaccount'), true) ? __('Set password', 'seoprostack') : __('Lost password', 'seoprostack'),
        );
        $title = isset($titles[$endpoint]) ? $titles[$endpoint] : '';
        return (string) apply_filters('woocommerce_endpoint_' . $endpoint . '_title', $title, $endpoint, $action);
    }

    /**
     * The page's title on a tab: the tab's title.
     *
     * @param mixed $title Title.
     * @param mixed $id    Post ID.
     * @return mixed
     */
    public static function page_title($title, $id = 0) {
        if (is_admin() || !in_the_loop() || !is_main_query() || (int) $id !== self::page_id() || !self::is_account_page()) {
            return $title;
        }
        $endpoint = self::current_endpoint();
        $tab      = '' !== $endpoint ? self::endpoint_title($endpoint) : '';
        if ('' === $tab) {
            return $title;
        }
        remove_filter('the_title', array(__CLASS__, 'page_title'), 10);
        return $tab;
    }

    /**
     * The document title on a tab.
     *
     * @param mixed $parts Title parts.
     * @return mixed
     */
    public static function document_title($parts) {
        if (!is_array($parts) || !self::is_account_page()) {
            return $parts;
        }
        $endpoint = self::current_endpoint();
        $tab      = '' !== $endpoint ? self::endpoint_title($endpoint) : '';
        if ('' !== $tab) {
            $parts['title'] = $tab;
        }
        return $parts;
    }

    /* ------------------------------------------------------------------
     * Page
     * ------------------------------------------------------------------ */

    /**
     * Page caches never keep the account page (it holds nonces and the
     * person's details), as with WooCommerce's.
     */
    public static function no_cache() {
        if (!self::is_account_page()) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- page caches' shared constant: never keep this page.
        }
        do_action('litespeed_control_set_nocache', 'seoprostack: account page'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's hook.
        nocache_headers();
    }

    /**
     * WooCommerce's body classes on the account page.
     *
     * @param mixed $classes Classes.
     * @return mixed
     */
    public static function body_class($classes) {
        if (!is_array($classes) || !self::is_account_page()) {
            return $classes;
        }
        $classes[] = 'woocommerce-account';
        $classes[] = 'woocommerce-page';
        $endpoint  = self::current_endpoint();
        if ('' !== $endpoint) {
            $classes[] = 'woocommerce-' . sanitize_html_class($endpoint);
        } elseif (is_user_logged_in()) {
            $classes[] = 'woocommerce-dashboard';
        }
        return $classes;
    }

    /**
     * The password strength meter where a password is chosen.
     */
    public static function enqueue() {
        if (!self::is_account_page()) {
            return;
        }
        $endpoint = self::current_endpoint();
        $choosing = is_user_logged_in() ? in_array($endpoint, array('edit-account', 'lost-password'), true)
            : ('lost-password' === $endpoint || (self::signups_open() && !self::generates_password()));
        if (!$choosing) {
            return;
        }
        wp_enqueue_script(self::METER, SEOProStack::asset_url('assets/account-password.js'), array('password-strength-meter'), SEOPROSTACK_VERSION, array('strategy' => 'defer', 'in_footer' => true));
        wp_localize_script(
            self::METER,
            'seoprostackAccountPassword',
            array(
                /**
                 * Filter the strength (0 to 4, from WordPress's meter) a new
                 * password on My Account needs before its form can be sent.
                 *
                 * @param int $strength Default 3 (medium), as WooCommerce.
                 */
                'min'   => (int) apply_filters('seoprostack_account_min_password_strength', 3),
                'error' => __('Please enter a stronger password.', 'seoprostack'),
                'hint'  => wp_get_password_hint(),
            )
        );
    }

    /* ------------------------------------------------------------------
     * Notices
     * ------------------------------------------------------------------ */

    /**
     * Add a notice to My Account, as wc_add_notice() does.
     *
     * @param string $message HTML.
     * @param string $type    error, success or notice.
     * @param array  $data    Data (an id, for errors about a field).
     */
    public static function add_notice($message, $type = 'success', $data = array()) {
        $type = in_array($type, array('error', 'success', 'notice'), true) ? $type : 'success';
        self::load_notices();
        self::$notices[$type][] = array((string) $message, is_array($data) ? $data : array());
    }

    /**
     * Number of notices of a type (all types when '').
     *
     * @param string $type Type.
     * @return int
     */
    public static function notice_count($type = '') {
        self::load_notices();
        if ('' === $type) {
            return array_sum(array_map('count', self::$notices));
        }
        return isset(self::$notices[$type]) ? count(self::$notices[$type]) : 0;
    }

    /**
     * Drop every notice.
     */
    public static function clear_notices() {
        self::load_notices();
        self::$notices = array();
    }

    /**
     * Notices kept over the last redirect, once.
     */
    private static function load_notices() {
        static $loaded = false;
        if ($loaded || !did_action('init')) {
            return;
        }
        $loaded  = true;
        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return;
        }
        $kept = get_transient(self::NOTICES . $user_id);
        if (is_array($kept)) {
            delete_transient(self::NOTICES . $user_id);
            self::$notices = array_merge_recursive($kept, self::$notices);
        }
    }

    /**
     * Keep this request's notices for the page a redirect goes to.
     *
     * @param mixed $location Redirect address.
     * @return mixed
     */
    public static function keep_notices($location) {
        $user_id = get_current_user_id();
        if ($user_id > 0 && self::$notices && self::notice_count() > 0) {
            set_transient(self::NOTICES . $user_id, self::$notices, 5 * MINUTE_IN_SECONDS);
        }
        return $location;
    }

    /**
     * Print the notices and drop them, in WooCommerce's markup.
     */
    public static function print_notices() {
        self::load_notices();
        echo '<div class="woocommerce-notices-wrapper">';
        foreach (array('error', 'success', 'notice') as $type) {
            if (empty(self::$notices[$type])) {
                continue;
            }
            if ('error' === $type) {
                echo '<ul class="woocommerce-error" role="alert">';
                foreach (self::$notices[$type] as $notice) {
                    $id = isset($notice[1]['id']) ? ' data-id="' . esc_attr((string) $notice[1]['id']) . '"' : '';
                    echo '<li' . $id . '>' . wp_kses_post($notice[0]) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $id escaped above.
                }
                echo '</ul>';
                continue;
            }
            foreach (self::$notices[$type] as $notice) {
                $class = 'success' === $type ? 'woocommerce-message' : 'woocommerce-info';
                echo '<div class="' . esc_attr($class) . '" role="' . ('success' === $type ? 'alert' : 'status') . '">' . wp_kses_post($notice[0]) . '</div>';
            }
        }
        echo '</div>';
        self::$notices = array();
    }

    /* ------------------------------------------------------------------
     * Kadence
     * ------------------------------------------------------------------ */

    /**
     * What the Kadence theme adds to My Account while WooCommerce is
     * active: its navigation wrapper and avatar, menu classes, body class
     * and WooCommerce styles.
     */
    private static function boot_kadence() {
        add_action('woocommerce_before_account_navigation', array(__CLASS__, 'kadence_wrap_start'), 2);
        add_action('woocommerce_before_account_navigation', array(__CLASS__, 'kadence_avatar'), 20);
        add_action('woocommerce_after_account_navigation', array(__CLASS__, 'kadence_wrap_end'), 50);
        add_filter('woocommerce_account_menu_item_classes', array(__CLASS__, 'kadence_menu_class'));
        add_filter('body_class', array(__CLASS__, 'kadence_body_class'));
        add_filter('kadence_css_files', array(__CLASS__, 'kadence_css_files'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'kadence_inline_css'), 20);
    }

    /**
     * A Kadence theme option.
     *
     * @param string $name Option.
     * @return mixed
     */
    private static function kadence_option($name) {
        $theme = kadence();
        return is_object($theme) && method_exists($theme, 'option') ? $theme->option($name) : null;
    }

    /**
     * Open Kadence's navigation wrapper.
     */
    public static function kadence_wrap_start() {
        echo '<div class="account-navigation-wrap">';
    }

    /**
     * Close it.
     */
    public static function kadence_wrap_end() {
        echo '</div>';
    }

    /**
     * Kadence's avatar and name above the navigation.
     */
    public static function kadence_avatar() {
        $user = wp_get_current_user();
        if (!$user->exists() || !self::kadence_option('woo_account_navigation_avatar')) {
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
    public static function kadence_menu_class($classes) {
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
    public static function kadence_body_class($classes) {
        if (is_array($classes) && self::is_account_page()) {
            $layout    = (string) self::kadence_option('woo_account_navigation_layout');
            $classes[] = 'kadence-account-nav-' . sanitize_html_class('' !== $layout ? $layout : 'left');
        }
        return $classes;
    }

    /**
     * Kadence's WooCommerce and account styles on the account page.
     *
     * @param mixed $files Handle => file data.
     * @return mixed
     */
    public static function kadence_css_files($files) {
        if (!is_array($files) || !self::is_account_page()) {
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
    public static function kadence_inline_css() {
        if (self::is_account_page() && wp_style_is('kadence-woocommerce', 'enqueued')) {
            wp_add_inline_style('kadence-woocommerce', '.woocommerce form .form-row .required { visibility: visible; }');
        }
    }

    /* ------------------------------------------------------------------
     * Simple Cloudflare Turnstile
     * ------------------------------------------------------------------ */

    /**
     * Simple Cloudflare Turnstile loads its WooCommerce checks (log in, sign
     * up, lost password, account details) only while WooCommerce is active.
     * They hook only WooCommerce's form hooks, which this page fires, so
     * load the same file: its own switches (WooCommerce Login, Register,
     * Reset Password, My Account) and its own markup and checks.
     */
    private static function boot_turnstile() {
        if (!function_exists('cfturnstile_field_show') || function_exists('cfturnstile_field_woo_login')) {
            return;
        }
        $tested = get_option('cfturnstile_tested');
        if (!empty($tested) && 'yes' !== $tested) {
            return;
        }
        try {
            $file = (string) (new ReflectionFunction('cfturnstile_field_show'))->getFileName();
        } catch (ReflectionException $e) {
            return;
        }
        $file = dirname($file) . '/integrations/ecommerce/woocommerce.php';
        if (is_readable($file)) {
            include_once $file;
        }
    }
}
