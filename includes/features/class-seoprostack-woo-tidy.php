<?php
/**
 * Tidy WooCommerce admin.
 *
 * Removes WooCommerce's promotions and prompts from wp-admin with the
 * filters WooCommerce provides, and its Extensions menu entry. Orders,
 * products, settings, reports and WooCommerce's own stored settings are not
 * changed, and pages keep working at their addresses.
 *
 * Replaces part of Disable Bloat; its matching switches are imported once.
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Woo_Tidy extends SEOProStack_Feature {

    const KEY = 'woo_tidy';

    /** What to remove. */
    const ITEMS_KEY = 'woo_tidy_items';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'admin',
                'label'       => __('Tidy WooCommerce admin', 'seoprostack'),
                'description' => __('Remove WooCommerce’s promotions and prompts from wp-admin. Orders, products, reports and WooCommerce’s own settings are not changed. Does nothing without WooCommerce.', 'seoprostack'),
                'replaces'    => SEOProStack_Disable_Bloat::PLUGINS,
                'reload'      => true,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array('suggestions', 'payment_suggestions', 'extensions', 'connect', 'app_email'),
                'parent'      => self::KEY,
                'label'       => __('Remove', 'seoprostack'),
                'options'     => array(__CLASS__, 'item_options'),
                'reload'      => true,
            ),
        );
    }

    /**
     * Choices.
     *
     * @return array<string,string>
     */
    public static function item_options() {
        return array(
            'suggestions' => __('Marketplace suggestions: extensions WooCommerce promotes on its screens', 'seoprostack'),
            'payment_suggestions' => __('Payments settings: payment plugins WooCommerce suggests under “More payment options” (a link to its marketplace stays)', 'seoprostack'),
            'extensions'  => __('Extensions menu entry (the page still opens from Plugins)', 'seoprostack'),
            'connect'     => __('“Connect your store to WooCommerce.com” notices', 'seoprostack'),
            'app_email'   => __('“Get the WooCommerce app” in new order emails', 'seoprostack'),
            'marketing'   => __('Marketing → Overview: recommended marketing extensions and courses (Marketing opens Coupons instead; other plugins’ marketing pages stay)', 'seoprostack'),
        );
    }

    /**
     * Import Disable Bloat's matching switches.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Previous settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $choices = SEOProStack_Disable_Bloat::choices(self::ITEMS_KEY);
        if ($choices) {
            $options = self::import_setting($options, self::KEY, true);
            $options = self::import_setting($options, self::ITEMS_KEY, $choices);
        }
        return $options;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !class_exists('WooCommerce', false)) {
            return;
        }
        $items = array_flip((array) SEOProStack_Settings::get(self::ITEMS_KEY));

        if (isset($items['suggestions'])) {
            // WooCommerce's own switch for its in-dashboard suggestions; also
            // stops the WooPayments welcome page that it adds as a menu entry.
            add_filter('woocommerce_allow_marketplace_suggestions', '__return_false', 99);
        }
        if (isset($items['payment_suggestions'])) {
            // The Payments screen (React, WooCommerce 9.7+) reads them from
            // its providers route and shows only a link when there are none.
            add_filter('rest_request_after_callbacks', array(__CLASS__, 'remove_payment_suggestions'), 10, 3);
        }
        if (isset($items['extensions']) && is_admin()) {
            // WooCommerce registers the entry at admin_menu priority 70.
            add_action('admin_menu', array(__CLASS__, 'remove_extensions_menu'), 999);
        }
        if (isset($items['connect'])) {
            add_filter('woocommerce_helper_suppress_admin_notices', '__return_true', 99);
        }
        if (isset($items['app_email'])) {
            add_action('woocommerce_email', array(__CLASS__, 'remove_app_email'), 99);
        }
        if (isset($items['marketing']) && is_admin()) {
            // WooCommerce adds it at admin_menu priority 5; since 11.1 the
            // Marketing menu always loads, so its entry is removed instead.
            add_action('admin_menu', array(__CLASS__, 'remove_marketing_overview'), 999);
        }
    }

    /**
     * Remove Marketing → Overview, so the Marketing entry opens its next
     * page (Coupons). The page itself still opens at its address.
     */
    public static function remove_marketing_overview() {
        global $submenu;
        if (empty($submenu['woocommerce-marketing']) || !is_array($submenu['woocommerce-marketing'])) {
            return;
        }
        foreach ($submenu['woocommerce-marketing'] as $item) {
            if (isset($item[2]) && is_string($item[2]) && false !== strpos($item[2], 'path=/marketing') && false === strpos($item[2], 'path=/marketing/')) {
                remove_submenu_page('woocommerce-marketing', $item[2]);
            }
        }
    }

    /**
     * Leave out the payment plugins WooCommerce suggests under "More
     * payment options" on WooCommerce → Settings → Payments. Payment
     * methods already set up, and the suggested ones WooCommerce lists
     * among them, are unchanged.
     *
     * @param mixed           $response Response.
     * @param array           $handler  Route handler.
     * @param WP_REST_Request $request  Request.
     * @return mixed
     */
    public static function remove_payment_suggestions($response, $handler, $request) {
        if (!$request instanceof WP_REST_Request || '/wc-admin/settings/payments/providers' !== $request->get_route()) {
            return $response;
        }
        $data = $response instanceof WP_REST_Response ? $response->get_data() : null;
        if (is_array($data) && isset($data['suggestions'])) {
            $data['suggestions'] = array();
            $response->set_data($data);
        }
        return $response;
    }

    /**
     * Remove the Extensions entry (and the older Extensions address) from
     * the WooCommerce menu.
     */
    public static function remove_extensions_menu() {
        remove_submenu_page('woocommerce', 'wc-admin&path=/extensions');
        remove_submenu_page('woocommerce', 'wc-addons');
    }

    /**
     * Remove the "Get the WooCommerce app" block from new order emails.
     *
     * @param WC_Emails $mailer WooCommerce emails.
     */
    public static function remove_app_email($mailer) {
        if (!is_object($mailer) || empty($mailer->emails['WC_Email_New_Order'])) {
            return;
        }
        remove_action('woocommerce_email_footer', array($mailer->emails['WC_Email_New_Order'], 'mobile_messaging'), 9);
    }
}
