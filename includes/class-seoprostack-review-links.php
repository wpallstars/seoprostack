<?php
/**
 * Fluent Forms by key: the Review links block (seoprostack/review-links).
 * Placed after a keyed feedback form, it stays hidden until that form is
 * sent, then thanks the visitor, shows their comment with a Copy review
 * button and offers the site's review short links (/googlereview/,
 * /facebookreview/, /trustpilotreview/ from Short links' starter review
 * links) that have a real target.
 *
 * The links are offered for every rating: asking only happy customers for
 * public reviews (review gating) breaks Google's and Trustpilot's rules. The
 * rating changes the wording and order only: a high rating leads with the
 * copy box and the links; a low one first says the team will be in touch.
 * Every submission is the form's own, so it goes wherever the form sends it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.123
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Review_Links {

    /** Block name. */
    const BLOCK = 'seoprostack/review-links';

    /** The block's editor script. */
    const EDITOR_HANDLE = 'seoprostack-review-links-editor';

    /** A field name: letters, digits, hyphens and underscores. */
    const FIELD = '/^[A-Za-z0-9_-]+$/';

    /**
     * Register the block and its editor script. Called by Fluent Forms by
     * key while it is on.
     */
    public static function register() {
        $js = 'blocks/review-links/index.js';
        wp_register_script(
            self::EDITOR_HANDLE,
            SEOPROSTACK_URL . $js,
            array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', SEOProStack_Form_Keys::EDITOR_HANDLE),
            (string) filemtime(SEOPROSTACK_DIR . $js),
            true
        );
        register_block_type(SEOPROSTACK_DIR . 'blocks/review-links', array('render_callback' => array(__CLASS__, 'render_block')));
    }

    /**
     * The review links with a real target, in the starter links' order:
     * name => short address. A link counts when it is live and no longer
     * points at the starter placeholder.
     *
     * @return array<string,string>
     */
    public static function links() {
        if (!class_exists('SEOProStack_Short_Links') || !SEOProStack_Short_Links::enabled()) {
            return array();
        }
        $names = array(
            'google'     => __('Google', 'seoprostack'),
            'facebook'   => __('Facebook', 'seoprostack'),
            'trustpilot' => __('Trustpilot', 'seoprostack'),
        );
        $map   = SEOProStack_Short_Links::map();
        $links = array();
        foreach (SEOProStack_Short_Links::presets() as $id => $preset) {
            $key = SEOProStack_Short_Links::key($preset['slug']);
            if (!isset($map[$key], $names[$id]) || self::same_place((string) $map[$key]['url'], $preset['url'])) {
                continue;
            }
            $links[$names[$id]] = SEOProStack_Short_Links::url($preset['slug']);
        }
        return $links;
    }

    /**
     * Whether two addresses are the same place, ignoring the scheme, www.,
     * case and a trailing slash.
     *
     * @param string $a Address.
     * @param string $b Address.
     * @return bool
     */
    private static function same_place($a, $b) {
        $clean = static function ($url) {
            $url = strtolower(trim((string) $url));
            $url = (string) preg_replace('~^https?://(www\.)?~', '', $url);
            return rtrim($url, '/');
        };
        return $clean($a) === $clean($b);
    }

    /**
     * A text attribute, or its default.
     *
     * @param array  $attributes Attributes.
     * @param string $name       Attribute.
     * @param string $default    Default.
     * @return string
     */
    private static function text(array $attributes, $name, $default) {
        $value = isset($attributes[$name]) ? trim(wp_strip_all_tags((string) $attributes[$name])) : '';
        return '' !== $value ? $value : $default;
    }

    /**
     * A field name attribute, or its default.
     *
     * @param array  $attributes Attributes.
     * @param string $name       Attribute.
     * @param string $default    Default.
     * @return string
     */
    private static function field(array $attributes, $name, $default) {
        $value = isset($attributes[$name]) ? trim((string) $attributes[$name]) : '';
        return '' !== $value && preg_match(self::FIELD, $value) ? $value : $default;
    }

    /**
     * The block: hidden until the form is sent. Nothing when no published
     * form holds the key (editors see Form by key's notice).
     *
     * @param array $attributes Block attributes.
     * @return string
     */
    public static function render_block($attributes) {
        $attributes = is_array($attributes) ? $attributes : array();
        $key        = self::field($attributes, 'formKey', 'feedback-review');
        $form_id    = SEOProStack_Form_Key_Store::form_id($key);
        if (!$form_id) {
            return SEOProStack_Form_Keys::render($key, false);
        }
        static $count = 0;
        $count++;
        $id    = 'seoprostack-review-links-' . $count;
        $high  = max(1, min(5, (int) ($attributes['highRating'] ?? 4)));
        $links = '';
        foreach (self::links() as $name => $url) {
            /* translators: %s: review site, such as Google */
            $label  = sprintf(__('Review us on %s', 'seoprostack'), $name);
            $links .= '<li><a class="wp-block-seoprostack-review-links__link button wp-element-button" href="' . esc_url($url) . '" target="_blank" rel="noopener">'
                . esc_html($label) . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'seoprostack') . '</span></a></li>';
        }

        $html  = '<p class="wp-block-seoprostack-review-links__thanks" data-when="high" hidden>' . esc_html(self::text($attributes, 'thanksHigh', __('Thank you for your feedback!', 'seoprostack'))) . '</p>';
        $html .= '<p class="wp-block-seoprostack-review-links__thanks" data-when="low" hidden>' . esc_html(self::text($attributes, 'thanksLow', __('Thank you for telling us. We’ll be in touch to put things right.', 'seoprostack'))) . '</p>';
        if ('' !== $links) {
            $html .= '<div class="wp-block-seoprostack-review-links__share">';
            $html .= '<p class="wp-block-seoprostack-review-links__ask" data-when="high" hidden>' . esc_html(self::text($attributes, 'askHigh', __('Would you share this where others can find it? Copy your review, then paste it on one of these.', 'seoprostack'))) . '</p>';
            $html .= '<p class="wp-block-seoprostack-review-links__ask" data-when="low" hidden>' . esc_html(self::text($attributes, 'askLow', __('You can also leave a public review:', 'seoprostack'))) . '</p>';
            $html .= '<div class="wp-block-seoprostack-review-links__review" hidden>'
                . '<label class="wp-block-seoprostack-review-links__label" for="' . esc_attr($id . '-text') . '">' . esc_html__('Your review', 'seoprostack') . '</label>'
                . '<textarea class="wp-block-seoprostack-review-links__text" id="' . esc_attr($id . '-text') . '" rows="4" readonly></textarea>'
                . '<p class="wp-block-seoprostack-review-links__copy-row"><button type="button" class="wp-block-seoprostack-review-links__copy button wp-element-button">' . esc_html(self::text($attributes, 'copyLabel', __('Copy review', 'seoprostack'))) . '</button>'
                . ' <span class="wp-block-seoprostack-review-links__copied" role="status" aria-live="polite" data-copied="' . esc_attr__('Copied', 'seoprostack') . '" data-select="' . esc_attr__('Selected: press Ctrl+C or ⌘C to copy', 'seoprostack') . '"></span></p>'
                . '</div>';
            $html .= '<ul class="wp-block-seoprostack-review-links__links">' . $links . '</ul>';
            $html .= '</div>';
        }

        $wrapper = get_block_wrapper_attributes(array(
            'class' => 'wp-block-seoprostack-review-links',
        ));
        return '<div ' . $wrapper . ' id="' . esc_attr($id) . '" data-form="' . esc_attr((string) $form_id) . '" data-rating="' . esc_attr(self::field($attributes, 'ratingField', 'rating')) . '" data-comment="' . esc_attr(self::field($attributes, 'commentField', 'comment')) . '" data-high="' . esc_attr((string) $high) . '" tabindex="-1" hidden>'
            . $html . '</div>';
    }

    /** The block's settings data for the editor: whether links show. */
    public static function editor_data() {
        $links = array_keys(self::links());
        wp_add_inline_script(self::EDITOR_HANDLE, 'window.seoprostackReviewLinks = ' . wp_json_encode(array('links' => $links)) . ';', 'before');
    }
}
