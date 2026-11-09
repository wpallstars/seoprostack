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
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
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
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
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
        if ('yes' === get_option('woocommerce_coming_soon')) {
            // In Coming soon mode WooCommerce adds its coming-soon page's fonts
            // to every page (#1447).
            add_action('template_redirect', array(__CLASS__, 'start_fonts'), PHP_INT_MAX);
        }
    }

    /**
     * Buffer the page, in Coming soon mode.
     */
    public static function start_fonts() {
        if (SEOProStack_Font_Fallbacks::html_view()) {
            ob_start(array(__CLASS__, 'drop_fonts'));
        }
    }

    /**
     * Leave out the @font-face rules WooCommerce adds in Coming soon mode
     * (ComingSoonRequestHandler::experimental_filter_theme_json_theme(), on
     * every request, printed in <style class="wp-fonts-local">) for a family
     * the page also declares another way, such as the Kadence theme's own
     * Inter: the browser takes WooCommerce's face for every weight and
     * downloads its 327 KB variable Inter, every character, before the first
     * paint. A family only WooCommerce gives (Cardo, or Inter where the page
     * has no other) keeps its rule, so no text changes font, and the
     * coming-soon screen keeps them all: it has the
     * wp-block-woocommerce-coming-soon block, or the woo-coming-soon-page
     * meta tag, which classic themes do not print since WooCommerce adds it
     * after their get_header().
     *
     * @param string $html Page.
     * @return string
     */
    public static function drop_fonts($html) {
        $folder = self::fonts_path();
        if (false === stripos($html, 'wp-fonts-local') || false === strpos($html, $folder) || false === stripos($html, '</head>')
            || preg_match('/\bclass=["\'][^"\']*\bwp-block-woocommerce-coming-soon\b|<meta\b[^>]*\bname=["\']woo-coming-soon-page["\']/i', $html)) {
            return $html;
        }
        $woo = self::woo_faces($html, $folder);
        if (!$woo) {
            return $html;
        }
        // The families the page declares without WooCommerce's rules.
        $families = array_flip(array_map(function ($name) {
            return strtolower(trim($name));
        }, SEOProStack_Font_Fallbacks::declared_families(str_replace($woo, '', $html))));
        $drop = array_values(array_filter($woo, function ($rule) use ($families) {
            return isset($families[self::face_family($rule)]);
        }));
        if (!$drop) {
            return $html;
        }
        $out = preg_replace_callback(self::FONTS_STYLE, function ($m) use ($drop) {
            return $m[1] . str_replace($drop, '', $m[2]) . $m[3];
        }, $html);
        return null === $out ? $html : $out;
    }

    /** WordPress's <style> with the theme.json fonts' @font-face rules. */
    const FONTS_STYLE = '#(<style\b[^>]*\bwp-fonts-local\b[^>]*>)(.*?)(</style>)#is';

    /**
     * The address path of WooCommerce's fonts folder.
     *
     * @return string
     */
    private static function fonts_path() {
        $path = function_exists('WC') ? (string) wp_parse_url(WC()->plugin_url(), PHP_URL_PATH) : '';
        return ('' === $path ? '/woocommerce' : untrailingslashit($path)) . '/assets/fonts/';
    }

    /**
     * The @font-face rules in the page's wp-fonts-local styles whose files
     * are in WooCommerce's fonts folder.
     *
     * @param string $html   Page.
     * @param string $folder Fonts folder path.
     * @return string[]
     */
    private static function woo_faces($html, $folder) {
        if (!preg_match_all(self::FONTS_STYLE, $html, $styles)) {
            return array();
        }
        $woo = array();
        foreach ($styles[2] as $css) {
            if (preg_match_all('/@font-face\s*\{[^}]*\}/i', $css, $faces)) {
                foreach ($faces[0] as $rule) {
                    if (false !== strpos($rule, $folder)) {
                        $woo[] = $rule;
                    }
                }
            }
        }
        return $woo;
    }

    /**
     * The family of a @font-face rule, lower case.
     *
     * @param string $rule Rule.
     * @return string
     */
    private static function face_family($rule) {
        return preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $rule, $m) ? strtolower(trim($m[1])) : '';
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
