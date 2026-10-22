<?php
/**
 * The business plugins' settings, for the roles Editors use the business
 * plugins covers: read-only, or for administrators only.
 *
 * Those roles use the chosen plugins as administrators do (see
 * SEOProStack_Editor_Access), but their settings are for administrators to
 * change. Each plugin was checked: what its settings screens show, every
 * way they save (REST routes, AJAX and admin-post actions, forms posted to
 * the screen, links that act when opened). Screens without secrets stay
 * open to read, with a note to ask an administrator; every save is refused
 * on the server. Screens that show passwords, keys or licence keys, and
 * tools that export settings or change the plugin's set-up, are refused
 * and left out of the menu.
 *
 * WooCommerce's settings and status (payment gateway keys, REST API keys,
 * logs) are for administrators only. Fluent Forms' settings already are
 * (SEOProStack_Editor_Access gives no settings permission), and FluentSMTP
 * has its own rules there.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.72
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Editor_Settings {

    /**
     * Rules for each plugin, keyed as SEOProStack_Editor_Access::PLUGINS.
     *
     * - admin:   admin screens for administrators only, matched against the
     *            page slug, "slug/tab" and "slug/tab/section" (WooCommerce's
     *            settings tabs), and "slug#path" (WooCommerce's admin app);
     * - secrets: settings screens that become administrators' only when
     *            the option holds any of these keys (screen => option => keys);
     * - read:    settings screens open to read: forms posted to them are refused;
     * - hash:    page slug => address part (location.hash) of the plugin's
     *            app that shows its settings, where the note is shown;
     * - write:   REST routes whose saves (any method but GET) are refused;
     * - all:     REST routes refused for every method (they return secrets,
     *            or are tools for administrators);
     * - ajax:    admin-ajax.php actions refused;
     * - post:    admin-post.php actions refused;
     * - args:    query arguments that make an admin screen act when opened
     *            (an OAuth reply, a reconnect link): refused.
     */
    const RULES = array(
        // Payments (and payment plugins' own pages: PayPal by Payment
        // Plugins), Integration (MaxMind licence key), REST API keys and
        // webhooks, Status (logs, tools) and Extensions are administrators'.
        'woocommerce'    => array(
            'admin' => '~^(wc-status|wc-addons|wc-ppcp-main)$|^wc-settings/(checkout|integration|advanced/(keys|webhooks))(/|$)|^wc-admin#/(payments|extensions|marketplace)~',
            'read'  => '~^wc-settings$~',
            'all'   => '~^/wc/v[0-9]+/(payment_gateways|system_status|webhooks|settings/(checkout|integration|advanced))|^/wc-admin/settings/payments~',
            'write' => '~^/wc/v[0-9]+/(settings|taxes|shipping|shipping_methods)(/|$)|^/wc-admin/settings~',
            'ajax'  => '~^woocommerce_(toggle_gateway_enabled|update_api_key|tax_rates_save_changes|shipping_zones_save_changes|shipping_zone_add_method|shipping_zone_remove_method|shipping_zone_methods_save_changes|shipping_zone_methods_save_settings|shipping_classes_save_changes|shipping_providers_save_changes)$~',
            'hash'  => array('wc-settings' => ''),
        ),
        'rank-math'      => array(
            'admin'   => '~^rank-math-(status|wizard|registration)$~',
            'secrets' => array(
                'rank-math-options-titles'  => array('rank-math-options-titles' => array('facebook_secret', 'maps_api_key')),
                'rank-math-options-sitemap' => array('rank-math-options-sitemap' => array('youtube_api_key')),
            ),
            'read'    => '~^rank-math-options-~',
            'hash'    => array('rank-math-options-' => ''),
            'write'   => '~^/rank-math/v1/(updateSettings|saveModule|updateMode|in/resetKey)$~',
            'all'     => '~^/rank-math/v1/(toolsAction|importCSV|cancelCsvImport|status/|setupWizard/)|^/wp-abilities/v1/abilities/rank-math/(set-[a-z-]+|fix-site-seo)/run~',
            'ajax'    => '~^rank_math_(add_site_console|disconnect_google|verify_site_console|save_analytic_profile|save_analytic_options|analytics_delete_cache|analytic_start_fetching|analytic_cancel_fetching|save_adsense_account|import_plugin|clean_plugin|deactivate_plugins|import_settings|enable_auto_update|remove_nginx_notice)$~',
            'post'    => '~^rank_math_(save_wizard|save_registration|skip_wizard)$~',
            'args'    => array('rankmath_security', 'reconnect'),
        ),
        'tutor'          => array(
            'admin' => '~^(tutor_settings|tutor-tools|tutor-addons|tutor-setup|tutor_setup)$~',
            'ajax'  => '~^(tutor_(option_save|option_default_save|option_search|export_settings|export_single_settings|delete_single_settings|import_settings|apply_settings|onboard_setup|add_manual_payment_method|delete_manual_payment_method|payment_settings|payment_gateways|get_tax_settings|install_payment_gateway|remove_payment_gateway|install_plugin|template_required_plugin_install|pro_chatgpt_save_settings|pro_exportable_contents|pro_export|pro_import|pro_export_import_history|pro_delete_export_import_history)|reset_settings_data|addon_enable_disable|install_tutor_plugin|save_email_settings)$~',
        ),
        'fluent-crm'     => array(
            'hash'  => array('fluentcrm-admin' => '^#/settings'),
            'write' => '~^/fluent-crm/v2/(setting|custom-fields|campaign-pro-settings|migrators|ai/(settings|models|test))(/|$)~',
            'all'   => '~^/fluent-crm/v2/(setting/(bounce_configs|rest-keys|integrations|system-logs|reset_db)|webhooks|mcp|campaign-pro-settings/(license|sms|whatsapp-settings)|migrators/verify-cred)(/|$)~',
        ),
        'fluent-support' => array(
            'hash'  => array('fluent-support' => '^#/settings'),
            'write' => '~^/fluent-support/v2/(settings|mailboxes|products|agent-groups|ticket_importer|pro|email-box|(ai-)?activity-logger/settings|ticket-custom-fields/?$|agents/?$|agents/[0-9]+)(/|$)~',
            'all'   => '~^/fluent-support/v2/(settings/(integration|integration-settings|slack-integration|discord-integration|twilio-integration|incoming-webhook|recaptcha-settings|remote-upload-settings|upload_integration|ai-integration|fluent-bot-integration|remote-portal|mcp/config-snippets)|mailboxes/[0-9]+/(email_settings|email_configs)|pro/license)(/|$)~',
        ),
        'fluent-booking' => array(
            'hash'  => array('fluent-booking' => '^#/settings'),
            'write' => '~^/fluent-booking/v2/(settings|integrations/?$|integrations/(settings|zoom|[0-9]+))(/|$)~',
            'all'   => '~^/fluent-booking/v2/(settings/license|integrations/?$|integrations/(settings/payment-methods|zoom|[0-9]+/settings))(/|$)~',
        ),
        'fluent-boards'  => array(
            'hash'  => array('fluent-boards' => '^#/settings'),
            'write' => '~^/fluent-boards/v2/(admin/(feature-modules|general-settings|mcp|storage-settings)|ai/(settings|models|test)|managers|fluent-boards-import)(/|$)~',
            'all'   => '~^/fluent-boards/v2/(webhooks|outgoing-webhooks|license|admin/storage-settings)(/|$)~',
        ),
    );

    /**
     * Rules of the chosen plugins.
     *
     * @var array<string,array>
     */
    private static $rules = array();

    /**
     * Register hooks. Called by SEOProStack_Editor_Access::boot() when the
     * feature is on.
     *
     * @param array<string,array> $chosen Chosen plugins, keyed as RULES.
     */
    public static function boot(array $chosen) {
        self::$rules = array_intersect_key(self::RULES, $chosen);
        if (!self::$rules) {
            return;
        }
        // Before WooCommerce saves its settings (wp_loaded), admin-ajax.php
        // and admin-post.php run their handlers, and the plugins' admin_init.
        add_action('wp_loaded', array(__CLASS__, 'refuse_admin'), 1);
        add_filter('rest_pre_dispatch', array(__CLASS__, 'refuse_rest'), 10, 3);
        add_action('admin_menu', array(__CLASS__, 'menu'), PHP_INT_MAX);
        // After SEOProStack_Editor_Access::menu_capability().
        add_filter('seoprostack_restored_menu_capability', array(__CLASS__, 'restored_capability'), 20, 2);
        add_action('admin_footer', array(__CLASS__, 'notice'));
    }

    /**
     * Whether the current person is one these rules are for.
     *
     * @return bool
     */
    private static function applies() {
        return array() !== self::$rules && SEOProStack_Editor_Access::covers_current();
    }

    /* --------------------------------------------------------------------- */
    /* Screens, AJAX and admin-post                                           */
    /* --------------------------------------------------------------------- */

    /**
     * Refuse the admin-only screens, forms posted to the read-only ones,
     * links that act, and the refused AJAX and admin-post actions.
     */
    public static function refuse_admin() {
        if (!is_admin() || !self::applies()) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- only refuses; the plugins check their own nonces on what is let through.
        $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['action'])) : '';
        if (wp_doing_ajax()) {
            if ('' !== $action && self::matches('ajax', $action)) {
                wp_send_json_error(array('message' => self::change_message()), 403);
            }
            return;
        }
        if ('admin-post.php' === $GLOBALS['pagenow']) {
            if ('' !== $action && self::matches('post', $action)) {
                self::stop(self::change_message());
            }
            return;
        }
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash((string) $_GET['page'])) : '';
        if ('' === $page) {
            return;
        }
        if (self::admin_only($page, self::screen_args($_GET))) {
            self::stop(self::admin_message());
        }
        $posted = isset($_SERVER['REQUEST_METHOD']) && 'POST' === strtoupper(sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD'])));
        if ($posted && self::matches('read', $page)) {
            self::stop(self::change_message());
        }
        foreach (self::$rules as $rule) {
            foreach (isset($rule['args']) ? $rule['args'] : array() as $arg) {
                if (isset($_GET[$arg])) {
                    self::stop(self::change_message());
                }
            }
        }
        // phpcs:enable
    }

    /**
     * The tab, section and admin app address (path) of a screen.
     *
     * @param array $args Query arguments (slashed, as in $_GET).
     * @return array<string,string>
     */
    private static function screen_args(array $args) {
        $screen = array();
        foreach (array('tab', 'section', 'path') as $key) {
            $screen[$key] = isset($args[$key]) && is_string($args[$key]) ? sanitize_text_field(wp_unslash($args[$key])) : '';
        }
        return $screen;
    }

    /**
     * Whether a screen is for administrators only.
     *
     * @param string               $page   Page slug.
     * @param array<string,string> $screen Tab, section and path (screen_args()).
     * @return bool
     */
    private static function admin_only($page, array $screen = array()) {
        $subjects = array($page);
        if (!empty($screen['path'])) {
            $subjects[] = $page . '#' . $screen['path'];
        }
        if (!empty($screen['tab'])) {
            $subjects[] = $page . '/' . $screen['tab'] . (!empty($screen['section']) ? '/' . $screen['section'] : '');
        }
        foreach ($subjects as $subject) {
            if (self::matches('admin', $subject)) {
                return true;
            }
        }
        foreach (self::$rules as $rule) {
            if (isset($rule['secrets'][$page]) && self::has_secrets($rule['secrets'][$page])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any of the given options holds a secret.
     *
     * @param array<string,string[]> $options Option => keys.
     * @return bool
     */
    private static function has_secrets(array $options) {
        foreach ($options as $option => $keys) {
            $value = get_option($option, array());
            foreach ($keys as $key) {
                if (is_array($value) && !empty($value[$key])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether a chosen plugin's rule of this kind matches.
     *
     * @param string $kind    Rule kind (admin, read, ajax, post, write, all).
     * @param string $subject Slug, action or REST route.
     * @return bool
     */
    private static function matches($kind, $subject) {
        foreach (self::$rules as $rule) {
            if (isset($rule[$kind]) && preg_match($rule[$kind], $subject)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stop the request with a message.
     *
     * @param string $message Message.
     */
    private static function stop($message) {
        wp_die(esc_html($message), esc_html__('Ask an administrator', 'seoprostack'), array('response' => 403, 'back_link' => true));
    }

    /* --------------------------------------------------------------------- */
    /* REST API                                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Refuse settings saves, and routes for administrators only, before the
     * plugin's own permission check (which says yes to these people).
     *
     * @param mixed           $result  Response so far.
     * @param WP_REST_Server  $server  Server.
     * @param WP_REST_Request $request Request.
     * @return mixed
     */
    public static function refuse_rest($result, $server, $request) {
        unset($server);
        if (null !== $result || !$request instanceof WP_REST_Request || !self::applies()) {
            return $result;
        }
        $route = (string) $request->get_route();
        if (self::matches('all', $route)) {
            return new WP_Error('seoprostack_administrators_only', self::admin_message(), array('status' => 403));
        }
        if (!in_array(strtoupper($request->get_method()), array('GET', 'HEAD', 'OPTIONS'), true) && self::matches('write', $route)) {
            return new WP_Error('seoprostack_ask_administrator', self::change_message(), array('status' => 403));
        }
        return $result;
    }

    /* --------------------------------------------------------------------- */
    /* Menu and note                                                          */
    /* --------------------------------------------------------------------- */

    /**
     * Leave the administrators' screens out of the menu.
     */
    public static function menu() {
        global $menu, $submenu;
        if (!self::applies()) {
            return;
        }
        foreach (is_array($menu) ? $menu : array() as $position => $item) {
            if (is_array($item) && isset($item[2]) && self::admin_entry((string) $item[2])) {
                unset($menu[$position]); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- leaving an entry out.
            }
        }
        foreach (is_array($submenu) ? $submenu : array() as $parent => $items) {
            foreach ((array) $items as $position => $item) {
                if (is_array($item) && isset($item[2]) && self::admin_entry((string) $item[2])) {
                    unset($submenu[$parent][$position]); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.
                }
            }
        }
    }

    /**
     * Leave the administrators' screens out of the entries Load plugins
     * only where needed puts back on screens that skip their plugin.
     *
     * @param mixed $cap  Capability the entry asks for.
     * @param mixed $slug Entry address.
     * @return mixed
     */
    public static function restored_capability($cap, $slug) {
        return is_string($slug) && self::applies() && self::admin_entry($slug) ? 'do_not_allow' : $cap;
    }

    /**
     * Whether a menu entry opens a screen for administrators only. Entries
     * are page slugs, or addresses such as
     * admin.php?page=wc-settings&tab=checkout or wc-admin&path=/payments.
     *
     * @param string $slug Entry address.
     * @return bool
     */
    private static function admin_entry($slug) {
        $query = (string) wp_parse_url(html_entity_decode($slug), PHP_URL_QUERY);
        $args  = array();
        if (false !== strpos($slug, 'page=')) {
            wp_parse_str($query, $args);
        } elseif (false !== strpos($slug, '&')) {
            // A page slug followed by arguments: wc-admin&path=/payments.
            list($page, $rest) = explode('&', $slug, 2);
            wp_parse_str($rest, $args);
            $args['page'] = $page;
        } else {
            $args['page'] = $slug;
        }
        $page = isset($args['page']) && is_string($args['page']) ? $args['page'] : '';
        return '' !== $page && false === strpos($page, '.php') && self::admin_only($page, self::screen_args(wp_slash($args)));
    }

    /**
     * On the read-only settings screens, say that changes are for an
     * administrator. Printed by script, at the top of the screen: the
     * Fluent plugins' apps remove admin notices when they start, and show
     * their settings at an address part (#/settings) the server does not
     * see. The note is put back if removed, and shown over styles that hide
     * notices.
     */
    public static function notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen this is.
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash((string) $_GET['page'])) : '';
        if ('' === $page || !self::applies()) {
            return;
        }
        $hash = null;
        foreach (self::$rules as $rule) {
            foreach (isset($rule['hash']) ? $rule['hash'] : array() as $prefix => $pattern) {
                if ($page === $prefix || (0 === strpos($page, $prefix) && '-' === substr($prefix, -1))) {
                    $hash = $pattern;
                }
            }
        }
        if (null === $hash) {
            return;
        }
        $script = '(function(){var re=new RegExp(%1$s),text=%2$s,at=document.getElementById("wpbody-content"),box=null;'
            . 'if(!at){return;}'
            . 'function show(){var on=re.test(location.hash);'
            . 'if(on&&(!box||!box.isConnected)){box=document.createElement("div");box.className="notice notice-info sps-keep sps-ask-admin";'
            . 'box.style.margin="12px 20px 12px 0";var p=document.createElement("p");p.textContent=text;box.appendChild(p);'
            . 'at.insertBefore(box,at.firstChild);}'
            . 'if(box){box.hidden=!on;box.style.setProperty("display",on?"block":"none","important");}}'
            . 'show();window.addEventListener("hashchange",show);'
            . 'new MutationObserver(function(){if(box&&!box.isConnected&&re.test(location.hash)){show();}}).observe(at,{childList:true});})();';
        wp_print_inline_script_tag(sprintf(
            $script,
            wp_json_encode($hash),
            wp_json_encode(self::notice_message())
        ));
    }

    /* --------------------------------------------------------------------- */
    /* Messages                                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Note on a read-only settings screen.
     *
     * @return string
     */
    private static function notice_message() {
        return __('You can see these settings, but only an administrator can change them. If you think something needs updating, please ask one: changes made here are not saved.', 'seoprostack');
    }

    /**
     * Message for a refused change.
     *
     * @return string
     */
    private static function change_message() {
        return __('Only an administrator can change these settings. If you think something needs updating, please ask one.', 'seoprostack');
    }

    /**
     * Message for a screen or route for administrators only.
     *
     * @return string
     */
    private static function admin_message() {
        return __('Only an administrator can see these settings, as they include passwords, keys or tools for administrators. If you think something needs changing, please ask one.', 'seoprostack');
    }
}
