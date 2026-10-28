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

    /** The block editor canvas's copy of the colours. */
    const EDITOR_HANDLE = 'seoprostack-fluentforms-colours';

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
                'description' => __('Makes Fluent Forms labels, help text, inputs and file-upload areas follow Kadence’s light and dark palette, on the page and in the block editor’s preview. Fluent Forms’ saved styles stay unchanged. Nothing changes without Fluent Forms and Kadence.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register only with Fluent Forms and Kadence (or a child): on the front
     * end, and in the block editor's canvas so a form's preview has the
     * page's colours.
     */
    public static function boot() {
        if (!self::enabled() || !class_exists('FluentForm\App\Models\Form') || 'kadence' !== get_template()) {
            return;
        }
        if (is_admin()) {
            // After a theme's canvas styles (priority 10), as on the page,
            // where these print with Fluent's late stylesheet: a theme's rules
            // of the same specificity must not win in the preview only.
            add_action('enqueue_block_assets', array(__CLASS__, 'editor_styles'), 100);
            return;
        }
        add_action('wp_enqueue_scripts', array(__CLASS__, 'styles'), 100);
        // Shortcodes can enqueue their styles after the head has been printed.
        add_action('wp_footer', array(__CLASS__, 'styles'), 100);
    }

    /**
     * The block editor's canvas. WordPress collects the canvas's styles by
     * running enqueue_block_assets again with the registered styles, so the
     * inline CSS is added once, at registration.
     */
    public static function editor_styles() {
        if (!is_admin()) {
            return;
        }
        if (!wp_style_is(self::EDITOR_HANDLE, 'registered')) {
            wp_register_style(self::EDITOR_HANDLE, false, array(), SEOPROSTACK_VERSION);
            wp_add_inline_style(self::EDITOR_HANDLE, self::css());
        }
        wp_enqueue_style(self::EDITOR_HANDLE);
    }

    /** Add only alongside Fluent Forms' own front-end stylesheet. */
    public static function styles() {
        if (self::$added || !wp_style_is(self::HANDLE, 'enqueued')) {
            return;
        }
        self::$added = true;
        $css = self::css();

        if (wp_style_is(self::HANDLE, 'done')) {
            // Late-rendered form: the handle has already printed its inline CSS.
            printf("<style id=\"seoprostack-fluentforms-colours\">%s</style>\n", $css); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed CSS only, with no interpolated values.
        } else {
            wp_add_inline_style(self::HANDLE, $css);
        }
    }

    /**
     * The colours. Resolved on the form, below body: Kadence's switcher, not
     * the system, owns the palette. Submit buttons and validation states keep
     * their colours.
     *
     * @return string
     */
    private static function css() {
        return <<<'CSS'
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
body :is(.fluentform, .frm-fluent-form) .ff-el-form-control::placeholder,
body :is(.fluentform, .frm-fluent-form) .choices__input::placeholder {
    /* Without color-mix(): Kadence's muted text, 7.5:1 on its light palette. */
    color: var(--global-palette5, #606266);
    /* The input's text faded towards the field, so a placeholder reads but is
       not taken for a value: about 5.5:1 on Kadence's light palette. */
    color: color-mix(in srgb, var(--global-palette4, #2d3748) 75%, var(--global-palette9, #fff));
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
    }
}
