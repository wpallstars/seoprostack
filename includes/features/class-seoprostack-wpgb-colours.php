<?php
/**
 * WP Grid Builder colours from Kadence's body-scoped dark palette.
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

class SEOProStack_Wpgb_Colours extends SEOProStack_Feature {

    const KEY = 'wpgb_colours';

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
                'label'       => __('WP Grid Builder colours', 'seoprostack'),
                'description' => __('Makes WP Grid Builder cards and facet inputs follow Kadence’s dark palette. Light mode and WP Grid Builder’s saved colours stay unchanged. Nothing changes without WP Grid Builder and Kadence.', 'seoprostack'),
            ),
        );
    }

    /** Register only for the active plugin on Kadence, including child themes. */
    public static function boot() {
        if (!self::enabled() || is_admin() || !function_exists('wp_grid_builder') || 'kadence' !== get_template()) {
            return;
        }
        // WPGB enqueues in the footer, including facets without a grid.
        add_filter('wp_grid_builder/frontend/add_inline_style', array(__CLASS__, 'styles'));
    }

    /**
     * Append only where WPGB prints styles, without changing its saved CSS.
     *
     * @param string $css WPGB's existing inline styles.
     * @return string
     */
    public static function styles($css) {
        // Do not key off prefers-color-scheme: the site's switcher owns the mode.
        // The extra body class beats generated card IDs and scheme selectors.
        return $css . <<<'CSS'
body.color-switch-dark .wp-grid-builder .wpgb-card,
body.color-switch-dark .wp-grid-builder .wpgb-card .wpgb-card-inner,
body.color-switch-dark .wp-grid-builder .wpgb-card .wpgb-card-media,
body.color-switch-dark .wp-grid-builder .wpgb-card .wpgb-card-body {
    background-color: var(--global-palette9, #16191b);
    border-color: var(--global-palette7, #444);
    color: var(--global-palette4, #eee);
}
/* Card builder CSS colours blocks with WPGB's variables, not the classes
   below: dark-scheme text now sits on the dark card, so light it. Only
   dark-scheme areas, so media overlays and facet controls keep the accent. */
body.color-switch-dark .wp-grid-builder .wpgb-card .wpgb-scheme-dark {
    --wpgb-dark-scheme-1: var(--global-palette3, #fff);
    --wpgb-dark-scheme-2: var(--global-palette5, #ccc);
    --wpgb-dark-scheme-3: var(--global-palette5, #ccc);
    --wpgb-color-scheme-1: var(--global-palette3, #fff);
    --wpgb-color-scheme-2: var(--global-palette5, #ccc);
    --wpgb-color-scheme-3: var(--global-palette5, #ccc);
    --wpgb-accent-scheme-1: var(--global-palette1, #8ab4f8);
}
body.color-switch-dark .wp-grid-builder [class*="wpgb-scheme-"] .wpgb-idle-scheme-1,
body.color-switch-dark .wp-grid-builder [class*="wpgb-scheme-"] [class^="wpgb-block-"].wpgb-hover-scheme-1:hover {
    color: var(--global-palette3, #fff);
}
body.color-switch-dark .wp-grid-builder [class*="wpgb-scheme-"] .wpgb-idle-scheme-2,
body.color-switch-dark .wp-grid-builder [class*="wpgb-scheme-"] .wpgb-idle-scheme-3,
body.color-switch-dark .wp-grid-builder [class*="wpgb-scheme-"] [class^="wpgb-block-"].wpgb-hover-scheme-2:hover,
body.color-switch-dark .wp-grid-builder [class*="wpgb-scheme-"] [class^="wpgb-block-"].wpgb-hover-scheme-3:hover {
    /* Palette 6 is a decorative tint, not a safe small-text colour. */
    color: var(--global-palette5, #ccc);
}
body.color-switch-dark .wp-grid-builder [class*="wpgb-scheme-"] .wpgb-idle-accent-1,
body.color-switch-dark .wp-grid-builder [class*="wpgb-scheme-"] [class^="wpgb-block-"].wpgb-hover-accent-1:hover {
    color: var(--global-palette1, #8ab4f8);
}
body.color-switch-dark .wpgb-facet,
body.color-switch-dark .wpgb-facet .wpgb-facet-title {
    color: var(--global-palette4, #eee);
}
body.color-switch-dark .wpgb-facet .wpgb-select,
body.color-switch-dark .wpgb-facet .wpgb-combobox,
body.color-switch-dark .wpgb-facet .wpgb-combobox input,
body.color-switch-dark .wpgb-facet .wpgb-combobox .wpgb-combobox-input,
body.color-switch-dark .wpgb-facet .wpgb-combobox .wpgb-combobox-options,
body.color-switch-dark .wpgb-facet input.wpgb-input {
    background-color: var(--global-palette9, #16191b);
    border-color: var(--global-palette7, #444);
    color: var(--global-palette4, #eee);
}
body.color-switch-dark .wpgb-facet input::placeholder {
    color: var(--global-palette5, #ccc);
    opacity: 1;
}
body.color-switch-dark .wpgb-facet .wpgb-combobox .wpgb-combobox-option[aria-selected="true"],
body.color-switch-dark .wpgb-facet .wpgb-combobox .wpgb-combobox-option:hover {
    background-color: var(--global-palette7, #444);
    color: var(--global-palette3, #fff);
}
CSS;
    }
}
