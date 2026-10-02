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
                'default'     => array('suggestions', 'extensions', 'connect', 'app_email'),
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
            'extensions'  => __('Extensions menu entry (the page still opens from Plugins)', 'seoprostack'),
            'connect'     => __('“Connect your store to WooCommerce.com” notices', 'seoprostack'),
            'app_email'   => __('“Get the WooCommerce app” in new order emails', 'seoprostack'),
            'marketing'   => __('Marketing menu and its pages: recommended marketing extensions and courses (coupons stay under Marketing → Coupons’ own address and in the WooCommerce menu)', 'seoprostack'),
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
        if (isset($items['marketing'])) {
            add_filter('woocommerce_marketing_menu_items', '__return_empty_array', 99);
            add_filter('woocommerce_admin_features', array(__CLASS__, 'no_marketing'), 99);
        }
    }

    /**
     * WooCommerce Admin features without the Marketing hub.
     *
     * @param mixed $features Feature names.
     * @return mixed
     */
    public static function no_marketing($features) {
        return is_array($features) ? array_values(array_diff($features, array('marketing'))) : $features;
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
