<?php
/**
 * FluentCart styled by Kadence: palette, product title and buttons.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kadence styles WooCommerce's product pages, cart and checkout; FluentCart
 * brings its own grey look, and its "inherit from theme" colours are set on
 * :root, where Kadence Pro's dark palette (set on body.color-switch-dark)
 * never reaches them. This sets FluentCart's colour variables on body from
 * Kadence's palette, so they change with light and dark mode, and gives the
 * product title, price and buttons the rules Kadence writes for headings,
 * WooCommerce products and buttons.
 */
class SEOProStack_Fluentcart_Kadence extends SEOProStack_Feature {

    const KEY = 'fluentcart_kadence';

    /** Body class that scopes every rule, so nothing applies without it. */
    const BODY_CLASS = 'sps-fct-kadence';

    /** Two elements and the class: beats FluentCart's `body:not(…) .x` rules. */
    const SCOPE = 'html body.sps-fct-kadence';

    /** FluentCart's main buttons, given Kadence's button rules. */
    const BUTTONS = array('.fluent-cart-direct-checkout-button', '.fluent-cart-add-to-cart-button');

    /** The single product's title, given Kadence's heading 1 and product title. */
    const TITLE = '.fct-product-title h1';

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
                'tab'         => 'content',
                'label'       => __('FluentCart styled by Kadence', 'seoprostack'),
                'description' => __('Makes FluentCart’s product pages, shop cards, reviews, cart and checkout follow Kadence’s light and dark palette, as Kadence does for WooCommerce: the product title takes the heading and product title fonts, and Buy Now and Add To Cart take the site’s button style. FluentCart’s Customize colours stay as they are. Nothing changes without FluentCart and Kadence.', 'seoprostack'),
            ),
        );
    }

    /** Register only with FluentCart and Kadence (or a child), on the front end. */
    public static function boot() {
        if (!self::enabled() || is_admin() || !defined('FLUENTCART_VERSION') || 'kadence' !== get_template() || self::customised()) {
            return;
        }
        add_filter('body_class', array(__CLASS__, 'body_class'));
        // After Kadence's WooCommerce rules (priority 20), which hold the
        // product title's font.
        add_filter('kadence_dynamic_css', array(__CLASS__, 'dynamic_css'), 30);
    }

    /**
     * Whether the store owner chose their own colours in FluentCart
     * (Settings → Store → Appearance → Customize): those stay as they are.
     *
     * @return bool
     */
    private static function customised() {
        if (!class_exists('\FluentCart\App\Services\Theme\FrontendTheme')) {
            return false;
        }
        return 'customize' === \FluentCart\App\Services\Theme\FrontendTheme::getSource();
    }

    /**
     * @param string[] $classes Body classes.
     * @return string[]
     */
    public static function body_class($classes) {
        $classes[] = self::BODY_CLASS;
        return $classes;
    }

    /**
     * Add FluentCart's selectors to the rules Kadence writes from the
     * Customizer (heading 1, WooCommerce product title, buttons, at every
     * screen size), then the palette and the rest.
     *
     * @param string $css Kadence's dynamic CSS.
     * @return string
     */
    public static function dynamic_css($css) {
        $css   = (string) $css;
        $title = self::SCOPE . ' ' . self::TITLE;
        $css   = (string) preg_replace('/(^|[{}])h1\{/', '$1h1, ' . $title . '{', $css);
        $css   = str_replace('.woocommerce div.product .product_title{', '.woocommerce div.product .product_title, ' . $title . '{', $css);

        $buttons = array();
        $hover   = array();
        foreach (self::BUTTONS as $button) {
            $buttons[] = self::SCOPE . ' ' . $button;
            $hover[]   = self::SCOPE . ' ' . $button . ':hover';
            $hover[]   = self::SCOPE . ' ' . $button . ':focus';
        }
        $css = str_replace('.wc-block-cart__submit{', '.wc-block-cart__submit, ' . implode(', ', $buttons) . '{', $css);
        $css = str_replace('.wc-block-cart__submit:hover{', '.wc-block-cart__submit:hover, ' . implode(', ', $hover) . '{', $css);

        return $css . self::css();
    }

    /**
     * FluentCart's colours from Kadence's palette, set on body so they follow
     * Kadence Pro's dark palette; the variables FluentCart declares only on
     * :root are declared again here, or they would keep the light values.
     *
     * @return string
     */
    private static function css() {
        return <<<'CSS'
html body.sps-fct-kadence {
    --fct-primary-text-color: var(--global-palette3);
    --fct-secondary-text-color: var(--global-palette5);
    --fct-primary-active-text-color: var(--global-palette-highlight, var(--global-palette1));
    --fct-accent-color: var(--global-palette-highlight, var(--global-palette1));
    --fct-primary-bg-color: var(--global-palette8);
    --fct-secondary-bg-color: var(--global-palette8);
    --fct-border-color: var(--global-gray-400, var(--global-palette7));
    --fct-divider-color: var(--global-gray-400, var(--global-palette7));
    --fct-active-border-color: var(--global-palette-highlight, var(--global-palette1));
    --fct-secondary-active-border-color: var(--global-palette-highlight, var(--global-palette1));
    --fct-card-bg-color: var(--global-palette9);
    --fct-btn-bg-color: var(--global-palette-btn-bg);
    --fct-btn-text-color: var(--global-palette-btn);
    --fct-btn-hover-bg-color: var(--global-palette-btn-bg-hover);
    --fct-btn-hover-text-color: var(--global-palette-btn-hover);
    --fct-secondary-btn-bg-color: var(--global-palette9);
    --fct-secondary-btn-text-color: var(--global-palette3);
    --fct-secondary-btn-border-color: var(--global-gray-400, var(--global-palette7));
    --fct-secondary-btn-hover-bg-color: var(--global-palette8);
    --fct-input-bg-color: var(--global-palette9);
    --fct-input-text-color: var(--global-palette4);
    --fct-input-placeholder-text-color: var(--global-palette6);
    --fct-input-disabled-bg-color: var(--global-palette8);
    --fct-direct-btn-bg-color: var(--fct-btn-bg-color);
    --fct-direct-btn-text-color: var(--fct-btn-text-color);
    --fct-direct-btn-hover-bg-color: var(--fct-btn-hover-bg-color);
    --fct-direct-btn-hover-text-color: var(--fct-btn-hover-text-color);
    --fct-add-to-cart-btn-bg-color: var(--global-palette-btn-sec-bg, var(--fct-secondary-btn-bg-color));
    --fct-add-to-cart-btn-hover-bg-color: var(--global-palette-btn-sec-bg-hover, var(--fct-secondary-btn-hover-bg-color));
    --fct-add-to-cart-btn-text-color: var(--global-palette-btn-sec, var(--fct-secondary-btn-text-color));
    --fct-add-to-cart-btn-hover-text-color: var(--global-palette-btn-sec-hover, var(--fct-secondary-btn-text-color));
    --fct-add-to-cart-btn-border-color: transparent;
    --fct-review-star-empty-color: var(--global-gray-400, #d1d5db);
}
html body.sps-fct-kadence .fct-product-title h1 {
    color: var(--global-palette3);
}
html body.sps-fct-kadence .fct-product-prices {
    font-size: 1.25em;
    font-weight: 700;
    line-height: 1.4;
}
html body.sps-fct-kadence .fct-product-excerpt p {
    color: var(--global-palette4);
}
html body.sps-fct-kadence .fct-product-variant-item {
    background: var(--global-palette9);
}
html body.sps-fct-kadence .fct-product-variant-item.selected {
    box-shadow: 0 0 0 1px var(--fct-active-border-color);
}
/* FluentCart's grey box where a choice has no picture of its own. */
html body.sps-fct-kadence .fct_variation_view_type_both .fct-product-variant-image:has(img[src*="product-placeholder"]) {
    display: none;
}
/* Buttons as wide as their words, side by side, as on WooCommerce product
   pages: FluentCart's two equal columns break Kadence's larger button text. */
html body.sps-fct-kadence .fct-product-buttons-wrap {
    display: flex;
    flex-wrap: wrap;
}
html body.sps-fct-kadence .fct-product-buttons-wrap > * {
    white-space: nowrap;
}
html body.sps-fct-kadence .fluent-cart-direct-checkout-button,
html body.sps-fct-kadence .fct-product-card button,
html body.sps-fct-kadence .fct-product-view-button {
    background: var(--global-palette-btn-bg);
    color: var(--global-palette-btn);
}
html body.sps-fct-kadence .fluent-cart-direct-checkout-button:hover,
html body.sps-fct-kadence .fluent-cart-direct-checkout-button:focus,
html body.sps-fct-kadence .fct-product-card button:hover,
html body.sps-fct-kadence .fct-product-view-button:hover {
    background: var(--global-palette-btn-bg-hover);
    color: var(--global-palette-btn-hover);
}
html body.sps-fct-kadence .fluent-cart-add-to-cart-button {
    background: var(--fct-add-to-cart-btn-bg-color);
    border-color: var(--fct-add-to-cart-btn-border-color);
    color: var(--fct-add-to-cart-btn-text-color);
}
html body.sps-fct-kadence .fluent-cart-add-to-cart-button:hover,
html body.sps-fct-kadence .fluent-cart-add-to-cart-button:focus {
    background: var(--fct-add-to-cart-btn-hover-bg-color);
    color: var(--fct-add-to-cart-btn-hover-text-color);
}
CSS;
    }
}
