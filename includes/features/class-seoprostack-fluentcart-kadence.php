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
 * WooCommerce products and buttons. The single product page also takes
 * WooCommerce's layout in Kadence: unboxed (Kadence's product content style),
 * text sizes in the proportions of Kadence's WooCommerce styles, the
 * description at the narrow content width, and the reviews summary above
 * the list.
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

    /** FluentCart's product post type (\FluentCart\App\CPT\FluentProducts::CPT_NAME). */
    const POST_TYPE = 'fluent-products';

    /** FluentCart's action for the reviews under a single product. */
    const REVIEWS_ACTION = 'fluent_cart/product/after_product_content';

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
                'description' => __('Makes FluentCart’s product pages, shop cards, reviews, cart and checkout follow Kadence’s light and dark palette, as Kadence does for WooCommerce: the product title takes the heading and product title fonts, and Buy Now and Add To Cart take the site’s button style. Product pages are laid out as Kadence lays out WooCommerce products, with the description at the narrow content width and the reviews summary above the list. FluentCart’s Customize colours stay as they are. Nothing changes without FluentCart and Kadence.', 'seoprostack'),
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
        add_filter('kadence_post_layout', array(__CLASS__, 'post_layout'));
        add_filter('fluent_cart/review/renderer_options', array(__CLASS__, 'review_options'));
    }

    /**
     * Single products boxed or not as Kadence's WooCommerce products are
     * (Customizer → WooCommerce → Single Product Layout; unboxed by default),
     * not as Kadence's fallback for other post types (boxed). A choice made
     * for the product itself, or for FluentCart products in the Customizer,
     * stays.
     *
     * @param array $layout Kadence's layout for this request.
     * @return array
     */
    public static function post_layout($layout) {
        if (!is_array($layout) || !is_singular(self::POST_TYPE) || !function_exists('Kadence\kadence')) {
            return $layout;
        }
        $theme = \Kadence\kadence();
        $own   = get_post_meta((int) get_queried_object_id(), '_kad_post_content_style', true);
        if (in_array($own, array('boxed', 'unboxed'), true) || in_array($theme->option(self::POST_TYPE . '_content_style'), array('boxed', 'unboxed'), true)) {
            return $layout;
        }
        $layout['boxed'] = 'boxed' === $theme->option('product_content_style', 'unboxed') ? 'boxed' : 'unboxed';
        return $layout;
    }

    /**
     * The single product page's own reviews with their summary above the
     * list (FluentCart's "top"), which the narrow column reads better than a
     * summary squeezed beside it. Review blocks and shortcodes keep theirs.
     *
     * @param array $options FluentCart's review renderer options.
     * @return array
     */
    public static function review_options($options) {
        if (is_array($options) && doing_action(self::REVIEWS_ACTION) && 'side' === ($options['summaryMode'] ?? 'side')) {
            $options['summaryMode'] = 'top';
        }
        return $options;
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
/* The product row on the theme's container edge, as WooCommerce products are:
   Kadence's container already gives the side padding. */
html body.sps-fct-kadence .fct-single-product-page {
    max-width: none;
    padding-left: 0;
    padding-right: 0;
}
/* Sizes relative to body text, in the proportions Kadence's WooCommerce
   styles use (product meta 70% bold, tabs 90% bold), not FluentCart's fixed
   14px, which is tiny beside a large body size. */
html body.sps-fct-kadence .fct-product-meta {
    font-size: 70%;
    font-weight: 700;
    color: var(--global-palette6);
}
html body.sps-fct-kadence .fct-product-meta * {
    font-size: inherit;
}
html body.sps-fct-kadence .fct-product-tab-nav > * {
    font-size: 90%;
    font-weight: 700;
}
html body.sps-fct-kadence .fct-product-variant-title {
    font-size: 75%;
    line-height: 1.4;
}
html body.sps-fct-kadence .fct-product-item-price {
    font-size: 100%;
}
html body.sps-fct-kadence .fct-product-payment-type {
    font-size: 75%;
    color: var(--global-palette5);
}
/* The description, and the reviews under it, at Kadence's narrow content
   width, as on posts; wide and full width blocks keep their width. */
html body.sps-fct-kadence .single-content > .fct-single-product-page ~ :not(.alignwide):not(.alignfull) {
    max-width: calc(var(--global-content-narrow-width, 842px) - 2 * var(--global-content-edge-padding, 1.5rem));
    margin-left: auto;
    margin-right: auto;
}
html body.sps-fct-kadence .fct-product-reviews-section {
    margin-top: var(--global-lg-spacing, 2.5em);
    padding-top: var(--global-lg-spacing, 2.5em);
    border-top: 1px solid var(--global-gray-400, var(--global-palette7));
}
/* Reviews: the heading as Kadence's WooCommerce reviews heading (bold, body
   size), small text at three quarters of body size. */
html body.sps-fct-kadence .fct-reviews-section-title {
    font-size: 1em;
    line-height: 1.5;
    font-weight: 700;
    font-style: normal;
    color: var(--global-palette3);
}
html body.sps-fct-kadence .fct-reviews-average-number {
    font-size: 2.5em;
    line-height: 1;
    color: var(--global-palette3);
}
html body.sps-fct-kadence .fct-reviews-average-max,
html body.sps-fct-kadence .fct-reviews-summary-head-meta,
html body.sps-fct-kadence .fct-reviews-bar-label,
html body.sps-fct-kadence .fct-reviews-bar-count,
html body.sps-fct-kadence .fct-reviews-filter-chips button,
html body.sps-fct-kadence .fct-reviews-sort select,
html body.sps-fct-kadence .fct-review-cta-btn {
    font-size: max(0.75em, 14px);
}
html body.sps-fct-kadence .fct-reviews-summary-head-meta * {
    font-size: inherit;
}
html body.sps-fct-kadence .fct-reviews-summary-head-meta,
html body.sps-fct-kadence .fct-reviews-bar-label,
html body.sps-fct-kadence .fct-reviews-bar-count {
    color: var(--global-palette5);
}
/* Seen on both palettes: Kadence's palette7 is near the dark background. */
html body.sps-fct-kadence .fct-reviews-bar-track {
    background-color: color-mix(in srgb, var(--global-palette3) 12%, transparent);
}
html body.sps-fct-kadence .fct-reviews-empty {
    font-size: 90%;
    color: var(--global-palette5);
}
html body.sps-fct-kadence .fct-reviews-layout--summary-top .fct-reviews-layout-right {
    margin-top: var(--global-md-spacing, 2rem);
}
/* Summary above the list, in one row where there is room: the average over
   its stars, without the dividers FluentCart draws for a narrow column (its
   own wide rules come before them in its stylesheet, so they lose). */
@container fct-review-summary (min-width: 600px) {
    html body.sps-fct-kadence .fct-reviews-layout--summary-top .fct-reviews-summary-left {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25em;
        margin-bottom: 0;
        padding-bottom: 0;
        border: 0;
    }
    html body.sps-fct-kadence .fct-reviews-layout--summary-top .fct-reviews-summary-head-meta {
        margin-inline-start: 0;
        padding-inline-start: 0;
        border: 0;
    }
    html body.sps-fct-kadence .fct-reviews-layout--summary-top .fct-reviews-summary-right {
        margin-bottom: 0;
    }
}
CSS;
    }
}
