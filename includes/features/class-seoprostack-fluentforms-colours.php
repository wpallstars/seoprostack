<?php
/**
 * Fluent Forms front-end colours from Kadence's body-scoped palette.
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

class SEOProStack_Fluentforms_Colours extends SEOProStack_Feature {

    const KEY = 'fluentforms_colours';
    const HANDLE = 'fluent-form-styles';

    /** Whether the palette CSS has already been added on this request. */
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
                'default'     => true,
                'tab'         => 'content',
                'label'       => __('Fluent Forms colours', 'seoprostack'),
                'description' => __('Makes Fluent Forms labels, help text, inputs and file-upload areas follow Kadence’s light and dark palette. Fluent Forms’ saved styles stay unchanged. Nothing changes without Fluent Forms and Kadence.', 'seoprostack'),
            ),
        );
    }

    /** Register only on the front end with Fluent Forms and Kadence (or a child). */
    public static function boot() {
        if (!self::enabled() || is_admin() || !class_exists('FluentForm\App\Models\Form') || 'kadence' !== get_template()) {
            return;
        }
        add_action('wp_enqueue_scripts', array(__CLASS__, 'styles'), 100);
        // Shortcodes can enqueue their styles after the head has been printed.
        add_action('wp_footer', array(__CLASS__, 'styles'), 100);
    }

    /** Add only alongside Fluent Forms' own front-end stylesheet. */
    public static function styles() {
        if (self::$added || !wp_style_is(self::HANDLE, 'enqueued')) {
            return;
        }
        self::$added = true;

        // Resolve on the form, below body: Kadence's switcher, not the system,
        // owns the palette. Do not recolour submit buttons or validation states.
        $css = <<<'CSS'
body :is(.fluentform, .frm-fluent-form) .ff-el-input--label label,
body :is(.fluentform, .frm-fluent-form) .ff-el-form-check-label {
    color: var(--global-palette4, #2d3748);
}
body :is(.fluentform, .frm-fluent-form) .ff-el-help-message {
    color: var(--global-palette5, #606266);
}
body :is(.fluentform, .frm-fluent-form) .ff-el-form-control,
body :is(.fluentform, .frm-fluent-form) .ff-el-form-control:focus {
    background-color: var(--global-palette9, #fff);
    border-color: var(--global-palette7, #dadbdd);
    color: var(--global-palette4, #606266);
}
body :is(.fluentform, .frm-fluent-form) .ff-el-form-control::placeholder {
    color: var(--global-palette4, #606266);
    opacity: 1;
}
body :is(.fluentform, .frm-fluent-form) .ff-el-form-control:focus {
    border-color: var(--global-palette1, #1a7efb);
}
body :is(.fluentform, .frm-fluent-form) .ff-dropzone .ff_upload_btn.ff-btn {
    background-color: var(--global-palette8, #f7fafc);
    border-color: var(--global-palette7, #dadbdd);
    color: var(--global-palette5, #606266);
}
body :is(.fluentform, .frm-fluent-form) .ff-dropzone .ff_upload_btn.ff-btn:focus-visible {
    border-color: var(--global-palette1, #1a7efb);
}
CSS;

        if (wp_style_is(self::HANDLE, 'done')) {
            // Late-rendered form: the handle has already printed its inline CSS.
            printf("<style id=\"seoprostack-fluentforms-colours\">%s</style>\n", $css); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed CSS only, with no interpolated values.
        } else {
            wp_add_inline_style(self::HANDLE, $css);
        }
    }
}
