<?php
/**
 * Readabler colours from the site's palette.
 *
 * Readabler stores fixed colours for its accessibility button and popup. It
 * prints the popup's on :root, where Kadence's palette keeps its light
 * values, and picks its light or dark popup colours with
 * prefers-color-scheme, which follows the visitor's system rather than the
 * site's switcher. So the colours cannot follow the palette from Readabler's
 * settings: this sets them on the body, from the palette, for light and dark
 * alike, so Kadence's light and dark mode decide.
 *
 * Readabler's saved colours stay as they are, and are the fallback wherever
 * the theme has no palette, so nothing changes there.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Readabler_Colours extends SEOProStack_Feature {

    const KEY = 'readabler_colours';

    /** Readabler's front-end style handle; its inline CSS sets the colours. */
    const HANDLE = 'mdp-readabler';

    /**
     * Readabler's colour settings: option => setting => its default
     * (Readabler 2.0.18).
     */
    const SAVED = array(
        'mdp_readabler_open_button_settings' => array(
            'button_color'         => '#ffffff',
            'button_color_hover'   => 'rgba(33, 111, 243, 1)',
            'button_bgcolor'       => 'rgba(33, 111, 243, 1)',
            'button_bgcolor_hover' => '#ffffff',
        ),
        'mdp_readabler_modal_popup_settings' => array(
            'popup_background_color'      => '#ffffff',
            'popup_background_color_dark' => '#16191b',
            'popup_text_color'            => '#333',
            'popup_text_color_dark'       => '#deeffd',
            'popup_key_color'             => 'rgba(33, 111, 243, 1)',
            'popup_key_color_dark'        => 'rgba(33, 111, 243, 1)',
        ),
    );

    /** Whether the styles were added on this request. */
    private static $added = false;

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
                'label'       => __('Readabler colours', 'seoprostack'),
                'description' => __('Readabler’s accessibility button and popup take the site’s palette colours and follow the Kadence light and dark mode switcher. Readabler’s own colours return when this is off, and stay where the theme has no palette. Nothing changes without Readabler.', 'seoprostack'),
            ),
        );
    }

    /** Front end only; the styles go with Readabler's, wherever it loads them. */
    public static function boot() {
        if (!self::enabled() || is_admin()) {
            return;
        }
        // Readabler adds its styles at 99, or in the footer with Late load on.
        add_action('wp_enqueue_scripts', array(__CLASS__, 'styles'), 100);
        add_action('wp_footer', array(__CLASS__, 'styles'), 100);
    }

    /** Add the palette colours after Readabler's own. */
    public static function styles() {
        if (self::$added || !wp_style_is(self::HANDLE, 'enqueued')) {
            return;
        }
        self::$added = true;
        $c = self::saved();

        // Specificity beats Readabler's .mdp-readabler-trigger-button-box and
        // #mdp-readabler-voice-navigation rules; the body is nearer the popup
        // than Readabler's :root. Light and dark get the same palette colours.
        $css = 'body .mdp-readabler-trigger-button-box{'
            . '--readabler-btn-color:var(--global-palette9,' . $c['button_color'] . ');'
            . '--readabler-btn-color-hover:var(--global-palette9,' . $c['button_color_hover'] . ');'
            . '--readabler-btn-bg:var(--global-palette5,' . $c['button_bgcolor'] . ');'
            . '--readabler-btn-bg-hover:var(--global-palette1,' . $c['button_bgcolor_hover'] . ');'
            . '}'
            . 'body,body #mdp-readabler-voice-navigation{'
            . '--readabler-bg:var(--global-palette9,' . $c['popup_background_color'] . ');'
            . '--readabler-bg-dark:var(--global-palette9,' . $c['popup_background_color_dark'] . ');'
            . '--readabler-text:var(--global-palette4,' . $c['popup_text_color'] . ');'
            . '--readabler-text-dark:var(--global-palette4,' . $c['popup_text_color_dark'] . ');'
            . '--readabler-color:var(--global-palette5,' . $c['popup_key_color'] . ');'
            . '--readabler-color-dark:var(--global-palette5,' . $c['popup_key_color_dark'] . ');';
        // Readabler works these out from its saved rgba() text.
        foreach (array('' => 20, '-15' => 15, '-25' => 25, '-50' => 50) as $suffix => $percent) {
            $css .= '--readabler-color-transparent' . $suffix . ':color-mix(in srgb,var(--readabler-color) ' . $percent . '%,transparent);'
                . '--readabler-color-transparent' . $suffix . '-dark:color-mix(in srgb,var(--readabler-color-dark) ' . $percent . '%,transparent);';
        }
        $css .= '}';

        wp_add_inline_style(self::HANDLE, $css);
        if (wp_style_is(self::HANDLE, 'done')) {
            // Already printed (Late load): print ours on its own.
            printf("<style id=\"seoprostack-readabler-colours\">%s</style>\n", $css); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from fixed text and colours checked in saved().
        }
    }

    /**
     * Readabler's saved colours, each a plain CSS colour, or its default.
     *
     * @return array<string,string>
     */
    private static function saved() {
        $colours = array();
        foreach (self::SAVED as $option => $defaults) {
            $stored = get_option($option, array());
            $stored = is_array($stored) ? $stored : array();
            foreach ($defaults as $key => $default) {
                $value           = isset($stored[$key]) && is_string($stored[$key]) ? trim($stored[$key]) : '';
                $colours[$key] = preg_match('/^(#[0-9a-f]{3,8}|rgba?\([0-9.,%\s]+\))$/i', $value) ? $value : $default;
            }
        }
        return $colours;
    }
}
