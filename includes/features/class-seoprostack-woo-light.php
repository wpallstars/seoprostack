<?php
/**
 * Lighter WooCommerce pages.
 *
 * WooCommerce loads its scripts and styles on every page of the site, so
 * that add-to-cart buttons work wherever they appear. On a site where most
 * pages are posts and pages without products, they only cost time. This
 * keeps them to shop pages (shop, products, categories, cart, checkout and
 * account) and to pages whose content has WooCommerce blocks or shortcodes.
 *
 * Only dequeued: a script or style that another one needs still loads, and
 * blocks that enqueue scripts while they render (after wp_enqueue_scripts)
 * keep them.
 *
 * Replaces part of Disable Bloat; its matching switches are imported once.
 *
 * @package SEOProStack
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Woo_Light extends SEOProStack_Feature {

    const KEY = 'woo_light';

    /** What to keep to shop pages. */
    const ITEMS_KEY = 'woo_light_items';

    /** WooCommerce scripts loaded on every page. */
    const SCRIPTS = array('woocommerce', 'wc-add-to-cart', 'wc-add-to-cart-variation', 'wc-single-product', 'wc-cart', 'wc-checkout');

    /** WooCommerce styles loaded on every page. */
    const STYLES = array('woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-blocktheme', 'woocommerce-inline');

    /**
     * Request-level cache of is_shop_page().
     *
     * @var bool|null
     */
    private static $shop_page = null;

    /**
     * Whether a WooCommerce block has been drawn on this page.
     *
     * @var bool
     */
    private static $woo_block = false;

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
                'tab'         => 'speed',
                'label'       => __('Lighter WooCommerce pages', 'seoprostack'),
                'description' => __('WooCommerce loads its scripts on every page. Keep them to shop pages and pages with WooCommerce blocks or shortcodes. Applies to everyone. Does nothing without WooCommerce.', 'seoprostack'),
                'replaces'    => SEOProStack_Disable_Bloat::PLUGINS,
            ),
            self::ITEMS_KEY => array(
                'type'        => 'multi',
                'default'     => array('scripts'),
                'parent'      => self::KEY,
                'label'       => __('Leave out', 'seoprostack'),
                'description' => __('Shop pages are the shop, products, product categories and tags, cart, checkout and My account.', 'seoprostack'),
                'options'     => array(__CLASS__, 'item_options'),
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
            'scripts'   => __('WooCommerce scripts and styles outside shop pages (add-to-cart buttons there reload the page or open the product)', 'seoprostack'),
            'fragments' => __('Cart fragments outside shop pages: they ask the server for the cart on every page (a header cart there may show an old count on cached pages)', 'seoprostack'),
            'stripe'    => __('Stripe’s Apple Pay and Google Pay buttons on product pages (they stay in the cart and checkout)', 'seoprostack'),
            'blocks'    => __('WooCommerce block styles outside shop pages (a WooCommerce block shown there, such as a mini cart, still loads its own)', 'seoprostack'),
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
        if (!self::enabled() || is_admin() || !class_exists('WooCommerce', false)) {
            return;
        }
        $items = array_flip((array) SEOProStack_Settings::get(self::ITEMS_KEY));

        if (isset($items['scripts'])) {
            // After WooCommerce (10) and themes that add to its styles.
            add_action('wp_enqueue_scripts', array(__CLASS__, 'dequeue_scripts'), 999);
        }
        if (isset($items['fragments'])) {
            // Themes enqueue cart fragments while printing a header cart, after
            // wp_enqueue_scripts; footer scripts are printed at wp_footer 20.
            add_action('wp_enqueue_scripts', array(__CLASS__, 'dequeue_fragments'), 999);
            add_action('wp_footer', array(__CLASS__, 'dequeue_fragments'), 1);
        }
        if (isset($items['stripe'])) {
            add_filter('wc_stripe_hide_payment_request_on_product_page', '__return_true', 99);
        }
        if (isset($items['blocks'])) {
            add_filter('render_block', array(__CLASS__, 'note_block'), 10, 2);
            add_action('wp_enqueue_scripts', array(__CLASS__, 'dequeue_block_styles'), 999);
        }
    }

    /**
     * Note a WooCommerce block that has been drawn. Block themes draw the
     * template (such as a mini cart in the header) before the styles are
     * chosen, so its styles are kept.
     *
     * @param string $content Block output.
     * @param array  $block   Block.
     * @return string
     */
    public static function note_block($content, $block) {
        if (!self::$woo_block && isset($block['blockName']) && is_string($block['blockName']) && 0 === strpos($block['blockName'], 'woocommerce/')) {
            self::$woo_block = true;
        }
        return $content;
    }

    /**
     * Dequeue WooCommerce's block styles enqueued for every page, outside
     * shop pages and pages that have drawn a WooCommerce block. Styles a
     * block enqueues while it is drawn later are kept.
     */
    public static function dequeue_block_styles() {
        if (self::$woo_block || self::is_shop_page()) {
            return;
        }
        foreach (wp_styles()->queue as $handle) {
            if (0 === strpos($handle, 'wc-blocks-') || 0 === strpos($handle, 'wc-all-blocks') || 'wc-block-editor' === $handle) {
                wp_dequeue_style($handle);
            }
        }
    }

    /**
     * Whether this page needs WooCommerce's scripts.
     *
     * @return bool
     */
    public static function is_shop_page() {
        if (null !== self::$shop_page) {
            return self::$shop_page;
        }
        $shop = (function_exists('is_woocommerce') && is_woocommerce())
            || (function_exists('is_cart') && is_cart())
            || (function_exists('is_checkout') && is_checkout())
            || (function_exists('is_account_page') && is_account_page());

        if (!$shop && is_singular()) {
            $post    = get_post();
            $content = $post ? (string) $post->post_content : '';
            $shop    = false !== strpos($content, '<!-- wp:woocommerce/')
                || (bool) preg_match('/\[(woocommerce_[a-z_]+|products?|product_[a-z_]+|add_to_cart(_url)?|recent_products|featured_products|sale_products|best_selling_products|top_rated_products|shop_messages)[\s\]]/', $content);
        }

        /**
         * Whether a page keeps WooCommerce's scripts with Lighter WooCommerce
         * pages on, for content the check above does not see (such as
         * products in a theme's template parts).
         *
         * @param bool $shop Whether it is a shop page or has WooCommerce blocks or shortcodes.
         */
        self::$shop_page = (bool) apply_filters('seoprostack_woo_light_shop_page', $shop);
        return self::$shop_page;
    }

    /**
     * Dequeue WooCommerce's scripts and styles outside shop pages.
     */
    public static function dequeue_scripts() {
        if (self::is_shop_page()) {
            return;
        }
        foreach (self::SCRIPTS as $handle) {
            // The store notice's dismiss link needs woocommerce.js.
            if ('woocommerce' === $handle && function_exists('is_store_notice_showing') && is_store_notice_showing()) {
                continue;
            }
            wp_dequeue_script($handle);
        }
        foreach (self::STYLES as $handle) {
            wp_dequeue_style($handle);
        }
    }

    /**
     * Dequeue cart fragments outside shop pages.
     */
    public static function dequeue_fragments() {
        if (!self::is_shop_page()) {
            wp_dequeue_script('wc-cart-fragments');
        }
    }
}
