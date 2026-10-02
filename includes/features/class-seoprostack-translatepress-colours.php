<?php
/**
 * TranslatePress switcher colours from the site's palette.
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Translatepress_Colours extends SEOProStack_Feature {

    const KEY = 'translatepress_colours';

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
                'tab'         => 'content',
                'label'       => __('TranslatePress switcher colours', 'seoprostack'),
                'description' => __('Makes TranslatePress language switchers follow the site’s light and dark colours, including the Kadence dark mode switcher. TranslatePress’s own colour choices return when this is off. Nothing changes without TranslatePress.', 'seoprostack'),
            ),
        );
    }

    /** Register front-end styles only when both features are running. */
    public static function boot() {
        if (!self::enabled() || !class_exists('TRP_Translate_Press', false) || is_admin()) {
            return;
        }
        add_action('wp_enqueue_scripts', array(__CLASS__, 'styles'), 100);
    }

    /** Use body-scoped palette values, not the visitor's system preference. */
    public static function styles() {
        // TranslatePress v2 puts these variables in style attributes. Important
        // overrides are limited to colours; its sizes and interactions stay.
        // Canvas/CanvasText also work without Kadence, following color-scheme.
        $css = <<<'CSS'
body .trp-language-switcher.trp-floating-switcher,
body .trp-language-switcher.trp-shortcode-switcher {
    --bg: var(--global-palette9, Canvas) !important;
    --bg-hover: rgba(127, 127, 127, 0.12) !important;
    --text: var(--global-palette3, CanvasText) !important;
    --text-hover: var(--global-palette3, CanvasText) !important;
    --border-color: var(--global-palette6, currentColor) !important;
    color: var(--global-palette3, CanvasText);
    border-color: var(--global-palette6, currentColor) !important;
}
body .trp-language-switcher .trp-language-item {
    color: var(--global-palette3, CanvasText);
}
/* Legacy switcher: keep its layout, replacing only fixed colours. */
body #trp-floater-ls,
body .trp_language_switcher_shortcode .trp-language-switcher > div {
    color: var(--global-palette3, CanvasText) !important;
    background-color: var(--global-palette9, Canvas) !important;
    border-color: var(--global-palette6, currentColor) !important;
}
body #trp-floater-ls {
    background-image: none !important;
}
body #trp-floater-ls a,
body .trp_language_switcher_shortcode .trp-language-switcher > div > a {
    color: inherit !important;
}
body #trp-floater-ls a:hover,
body #trp-floater-ls a:focus-visible,
body .trp_language_switcher_shortcode .trp-language-switcher > div > a:hover,
body .trp_language_switcher_shortcode .trp-language-switcher > div > a:focus-visible {
    background-color: rgba(127, 127, 127, 0.12) !important;
}
/* The legacy arrow is a fixed-colour SVG; draw it in the text colour. */
body .trp_language_switcher_shortcode .trp-language-switcher > div:not(:hover) {
    background-image: linear-gradient(45deg, transparent 50%, currentColor 50%), linear-gradient(135deg, currentColor 50%, transparent 50%);
    background-position: calc(100% - 24px) 50%, calc(100% - 20px) 50%;
    background-size: 4px 4px, 4px 4px;
}
/* Menu links already follow the theme; labels must follow their links too. */
body .trp-language-switcher-container .trp-ls-language-name,
body .trp-menu-ls-label {
    color: inherit;
}
CSS;
        wp_register_style('seoprostack-translatepress-colours', false, array(), SEOPROSTACK_VERSION);
        wp_enqueue_style('seoprostack-translatepress-colours');
        wp_add_inline_style('seoprostack-translatepress-colours', $css);
    }
}
