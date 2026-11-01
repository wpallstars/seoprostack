<?php
/**
 * Kadence dark mode switch palette.
 *
 * Kadence Pro's Dark Mode switch colour controls (Customizer: Header, Mobile
 * Header and Footer → Dark Mode, and the floating switch under Colors →
 * Dark Mode) are the theme's colour control with the Global Palette turned
 * off, so they take only fixed colours. Kadence Pro already prints saved
 * palette names (palette8) as var(--global-palette8), so this turns the
 * palette on for those controls, after the theme adds them: a switch can
 * then follow the site's palette, and the dark palette in dark mode.
 *
 * Kadence Pro swaps the two colours in dark mode (in light mode the button
 * is the dark colour with the light colour's icon; in dark mode the other
 * way round). A switch set to share its row's background then turns the
 * opposite colour in dark mode. Keep switch colours in dark mode swaps the
 * two values back in dark mode, so each colour keeps its place and palette
 * colours alone follow the mode.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.134
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Switch_Palette extends SEOProStack_Feature {

    const KEY = 'kadence_switch_palette';

    const KEEP = 'kadence_switch_palette_keep';

    const HANDLE = 'seoprostack-kadence-switch-palette';

    /**
     * Kadence Pro's switch colour settings => the element its CSS sets the
     * switch colour variables on.
     */
    const SWITCHES = array(
        'header_dark_mode_colors' => '.kadence-color-palette-header-switcher',
        'mobile_dark_mode_colors' => '.kadence-color-palette-mobile-switcher',
        'footer_dark_mode_colors' => '.kadence-color-palette-footer-switcher',
        // The floating switch and the shortcode take the body's values.
        'dark_mode_colors'        => '',
    );

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                // Safe on any site: it adds swatches to four Customizer controls.
                'default'     => true,
                'tab'         => 'content',
                'label'       => __('Palette colours for Kadence’s dark mode switch', 'seoprostack'),
                'description' => __('Kadence Pro’s Dark Mode switch colours (Header, Mobile Header, Footer and the floating switch) take only fixed colours in the Customizer. This adds the Global Palette swatches, as other Kadence colours have, so a switch can follow the site’s palette and the dark palette. Saved colours stay as they are, and nothing changes without Kadence Pro’s Dark Mode.', 'seoprostack'),
            ),
            self::KEEP => array(
                'type'        => 'bool',
                // Off: it changes how existing switches look in dark mode.
                'default'     => false,
                'parent'      => self::KEY,
                'label'       => __('Keep switch colours in dark mode', 'seoprostack'),
                'description' => __('Kadence Pro swaps the switch’s two colours in dark mode: the button in the Dark Color in light mode is in the Light Color in dark mode. This keeps each colour in its place, so a switch set to its row’s background palette colour keeps sharing it in dark mode, and palette colours change with the dark palette. Fixed colours then stay the same in both modes. Changes made in the Customizer show in dark mode after saving.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks, on Kadence and its child themes.
     */
    public static function boot() {
        if (!self::enabled() || 'kadence' !== get_template()) {
            return;
        }
        // After the theme adds its controls (Theme_Customizer::add_controls, priority 10).
        add_action('customize_register', array(__CLASS__, 'palette_controls'), 20);
        if (!is_admin() && SEOProStack_Settings::get(self::KEEP)) {
            // After Kadence Pro prints its switch styles (wp_enqueue_scripts, 10).
            add_action('wp_enqueue_scripts', array(__CLASS__, 'keep_colours'), 20);
        }
    }

    /**
     * Turn the Global Palette on for the switch colour controls that exist:
     * only when Kadence Pro's Dark Mode module has added them.
     *
     * @param WP_Customize_Manager $wp_customize Customizer.
     */
    public static function palette_controls($wp_customize) {
        foreach (array_keys(self::SWITCHES) as $key) {
            $control = $wp_customize->get_control(self::control_id($key));
            if (!$control || !isset($control->input_attrs['colors']) || !is_array($control->input_attrs['colors'])) {
                continue;
            }
            $attrs = $control->input_attrs;
            foreach (array('light', 'dark') as $item) {
                if (isset($attrs['colors'][$item]) && is_array($attrs['colors'][$item])) {
                    $attrs['colors'][$item]['palette'] = true;
                }
            }
            $control->input_attrs = $attrs;
        }
    }

    /**
     * The control ID Kadence gives a setting: its key, or option[key] when a
     * filter stores Kadence's settings in an option.
     *
     * @param string $key Setting key.
     * @return string
     */
    private static function control_id($key) {
        $option = apply_filters('kadence_settings_key_custom_mapping', '', $key); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Kadence's hook.
        return is_string($option) && '' !== $option ? $option . '[' . $key . ']' : $key;
    }

    /**
     * In dark mode, give each switch its two colours swapped back, where
     * Kadence Pro's dark mode is on (its own condition for its styles).
     */
    public static function keep_colours() {
        if (!class_exists('Kadence_Pro\Dark_Mode') || !function_exists('Kadence\kadence')) {
            return;
        }
        $theme = \Kadence\kadence();
        if (!is_object($theme) || !is_callable(array($theme, 'option')) || !is_callable(array($theme, 'sub_option'))) {
            return;
        }
        if (!call_user_func(array($theme, 'option'), 'dark_mode_enable') || !apply_filters('kadence_dark_mode_enable', true)) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Kadence Pro's hook.
            return;
        }
        $css = '';
        foreach (self::SWITCHES as $key => $selector) {
            $light = self::colour(call_user_func(array($theme, 'sub_option'), $key, 'light'));
            $dark  = self::colour(call_user_func(array($theme, 'sub_option'), $key, 'dark'));
            if ('' === $light || '' === $dark) {
                continue;
            }
            $css .= 'body.color-switch-dark' . ('' === $selector ? '' : ' ' . $selector)
                . '{--global-light-toggle-switch:' . $dark . ';--global-dark-toggle-switch:' . $light . '}';
        }
        if ('' === $css) {
            return;
        }
        wp_register_style(self::HANDLE, false, array(), SEOPROSTACK_VERSION);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, $css);
    }

    /**
     * A saved switch colour as CSS, as Kadence renders it (paletteN as its
     * variable), or '' for anything that is not a plain colour.
     *
     * @param mixed $value Saved colour.
     * @return string
     */
    private static function colour($value) {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if (preg_match('/^palette([1-9]|1[0-5])$/D', $value, $match)) {
            return 'var(--global-palette' . $match[1] . ')';
        }
        if (preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([0-9a-z.,%\s\/]+\)|[a-z]+)$/Di', $value)) {
            return $value;
        }
        return '';
    }
}
