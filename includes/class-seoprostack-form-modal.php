<?php
/**
 * Fluent Forms by key: the Form modal block and [seoprostack_form_modal]. A
 * keyed form is printed at the foot of the page in Kadence Blocks Pro's
 * Modal block markup, so no row's stacking context traps it and Kadence's
 * modal script and stylesheet drive it; any link to its #ID opens it. It
 * prints nothing in place, and each ID once. Without Kadence Blocks Pro the
 * form stays in place, in a panel with the same ID.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.97
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Form_Modal {

    /** Kadence Blocks Pro's modal script and style handles. */
    const HANDLE = 'kadence-blocks-pro-modal';

    /** Modal IDs printed on this request. @var array<string,bool> */
    private static $printed = array();

    /**
     * Whether Kadence Blocks Pro's modal is available.
     *
     * @return bool
     */
    public static function available() {
        return defined('KBP_URL') && defined('KBP_VERSION');
    }

    /**
     * A keyed form in a modal.
     *
     * @param array $atts key, id (the modal's ID, default the key), title, intro.
     * @return string
     */
    public static function render(array $atts) {
        $key = (string) ($atts['key'] ?? '');
        $id  = sanitize_html_class('' !== (string) ($atts['id'] ?? '') ? (string) $atts['id'] : $key);
        if ('' === $id || (self::available() && isset(self::$printed[$id]))) {
            return '';
        }
        $form = SEOProStack_Form_Keys::form_html($key);
        if ('' === $form) {
            // The editors' notice for a key with no form.
            return SEOProStack_Form_Keys::render($key);
        }
        $content = self::head($id, (string) ($atts['title'] ?? ''), (string) ($atts['intro'] ?? '')) . $form;
        if (!self::available()) {
            SEOProStack_Form_Keys::enqueue_style();
            return '<div id="' . esc_attr($id) . '" class="seoprostack-form seoprostack-form-panel">' . $content . '</div>';
        }
        self::$printed[$id] = true;
        self::enqueue();
        $html = self::markup($id, '' !== (string) ($atts['title'] ?? ''), $content);
        add_action(
            'wp_footer',
            static function () use ($html) {
                echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in head() and markup(); the form is Fluent Forms' output.
            },
            9
        );
        return '';
    }

    /**
     * [seoprostack_form_modal key="request" id="request" title="..." intro="..."].
     *
     * @param array|string $atts Attributes.
     * @return string
     */
    public static function shortcode($atts) {
        return self::render(shortcode_atts(array('key' => '', 'id' => '', 'title' => '', 'intro' => ''), is_array($atts) ? $atts : array(), 'seoprostack_form_modal'));
    }

    /**
     * The Form modal block.
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_block($attributes) {
        $attributes = is_array($attributes) ? $attributes : array();
        return self::render(array(
            'key'   => (string) ($attributes['formKey'] ?? ''),
            'id'    => (string) ($attributes['modalId'] ?? ''),
            'title' => (string) ($attributes['title'] ?? ''),
            'intro' => (string) ($attributes['intro'] ?? ''),
        ));
    }

    /**
     * Keep the assets a Form modal block enqueues: it prints nothing in
     * place, and since WordPress 6.9 an empty block's assets are dequeued,
     * which left the modal's form unstyled and stuck loading on pages with
     * no other form.
     *
     * @param bool   $enqueue    Whether to keep them.
     * @param string $block_name Block name.
     * @return bool
     */
    public static function keep_assets($enqueue, $block_name) {
        return SEOProStack_Form_Keys::MODAL_BLOCK === $block_name ? true : (bool) $enqueue;
    }

    /**
     * Kadence Blocks Pro's modal script and stylesheet under the Modal
     * block's own handles, so a page that also has a Modal block loads them
     * once, then this feature's script and style.
     */
    public static function enqueue() {
        if (!self::available()) {
            return;
        }
        $url     = (string) constant('KBP_URL');
        $version = (string) constant('KBP_VERSION');
        if (!wp_script_is(self::HANDLE, 'registered')) {
            wp_register_script(self::HANDLE, $url . 'includes/assets/js/kt-modal-init.min.js', array(), $version, true);
        }
        if (!wp_style_is(self::HANDLE, 'registered')) {
            wp_register_style(self::HANDLE, $url . 'dist/style-blocks-modal.css', array(), $version);
        }
        wp_enqueue_script(self::HANDLE);
        wp_enqueue_style(self::HANDLE);
        $js = 'assets/form-keys.js';
        wp_enqueue_script(SEOProStack_Form_Keys::HANDLE, SEOPROSTACK_URL . $js, array(self::HANDLE), (string) filemtime(SEOPROSTACK_DIR . $js), true);
        SEOProStack_Form_Keys::enqueue_style();
    }

    /**
     * The modal's title and introduction.
     *
     * @param string $id    Modal ID.
     * @param string $title Title.
     * @param string $intro Introduction.
     * @return string
     */
    private static function head($id, $title, $intro) {
        $head = '' !== $title ? '<h2 id="' . esc_attr($id) . '-title" class="seoprostack-form-modal-title">' . esc_html($title) . '</h2>' : '';
        if ('' !== $intro) {
            $head .= '<p class="seoprostack-form-modal-intro">' . esc_html($intro) . '</p>';
        }
        return $head;
    }

    /**
     * Kadence Blocks Pro's Modal block markup (its save()) round the content.
     *
     * @param string $id      Modal ID.
     * @param bool   $titled  Whether the content starts with the title.
     * @param string $content Title, introduction and form.
     * @return string
     */
    private static function markup($id, $titled, $content) {
        $label = $titled ? ' aria-labelledby="' . esc_attr($id) . '-title"' : ' aria-label="' . esc_attr__('Form', 'seoprostack') . '"';
        $close = '<button type="button" class="kt-modal-close" aria-label="' . esc_attr__('Close', 'seoprostack') . '" data-modal-close="true">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>';
        return '<div id="' . esc_attr($id) . '" class="kadence-block-pro-modal seoprostack-form-modal kt-m-animate-in-fadeup kt-m-animate-out-fadeout" aria-hidden="true">'
            . '<div class="kt-modal-overlay" tabindex="-1" data-modal-close="true">'
            . '<div class="kt-modal-container kt-modal-height-auto kt-close-position-inside" role="dialog" aria-modal="true"' . $label . '>'
            . $close . '<div class="kt-modal-content">' . $content . '</div>'
            . '</div></div></div>';
    }
}
