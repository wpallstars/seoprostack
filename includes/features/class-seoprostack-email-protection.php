<?php
/**
 * Hide rendered email addresses from HTML-only scrapers, not browser users.
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

class SEOProStack_Email_Protection extends SEOProStack_Feature {

    const KEY = 'email_protection';

    /** @var int|null One obfuscation key per response; not encryption. */
    private static $key = null;

    /** @return array Settings schema. */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'security',
                'label'       => __('Email protection', 'seoprostack'),
                'description' => __('Hide email addresses in content, widgets, blocks and menus from scrapers that read HTML. A small script restores the text and email links for visitors. Without JavaScript, a message asks visitors to enable it. Does not change stored content, form values, feeds or REST responses. Works without Cloudflare; scrapers that run JavaScript can still read addresses.', 'seoprostack'),
            ),
        );
    }

    /** Register output filters only while enabled. */
    public static function boot() {
        if (!self::enabled() || is_admin()) {
            return;
        }
        foreach (array('the_content', 'widget_text', 'widget_text_content', 'widget_block_content', 'render_block', 'wp_nav_menu_items') as $hook) {
            add_filter($hook, array(__CLASS__, 'protect'), 999);
        }
        add_filter('script_loader_tag', array(__CLASS__, 'defer_script'), 10, 2);
    }

    /**
     * Transform text and mailto hrefs, preserving every other attribute.
     * Raw-text elements and comments are kept as whole tokens. Repeated
     * content/block/widget passes cannot encode an existing placeholder again.
     *
     * @param string $html Rendered HTML.
     * @return string
     */
    public static function protect($html) {
        if (self::plain_context()) {
            return $html;
        }
        /**
         * Leave a context's HTML plain (for example, a print or export view).
         *
         * @param bool   $skip Whether to skip protection.
         * @param string $html Rendered HTML.
         */
        if (apply_filters('seoprostack_email_protection_skip', false, $html)) {
            return $html;
        }
        // Include entity-encoded addresses; avoid all parsing on ordinary text.
        if (false === strpos($html, '@') && false === strpos($html, '&') && false === stripos($html, 'mailto:')) {
            return $html;
        }
        $matched = preg_match_all('~<!--[\s\S]*?(?:-->|$)|<(script|style|textarea|title|noscript)(?=[\x09\x0A\x0C\x0D />])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?(?:</\1(?=[\x09\x0A\x0C\x0D />])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>|$)|<(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i', $html, $parts, PREG_OFFSET_CAPTURE);
        if (false === $matched) {
            return $html;
        }
        $output = '';
        $offset = 0;
        foreach ($parts[0] as $part) {
            $output .= self::protect_text(substr($html, $offset, $part[1] - $offset));
            $output .= self::protect_tag($part[0]);
            $offset = $part[1] + strlen($part[0]);
        }
        return $output . self::protect_text(substr($html, $offset));
    }

    /**
     * These responses cannot safely rely on a browser's footer decoder.
     *
     * @return bool
     */
    private static function plain_context() {
        return is_admin() || is_feed() || wp_doing_ajax() || wp_is_json_request()
            || (defined('REST_REQUEST') && REST_REQUEST) || doing_filter('wp_mail');
    }

    /**
     * Encode only mailto hrefs; keep link classes, labels and nested markup.
     *
     * @param string $tag HTML token, possibly a complete raw-text element.
     * @return string
     */
    private static function protect_tag($tag) {
        if (!preg_match('/^<a\s/i', $tag)) {
            return $tag;
        }
        $processor = new WP_HTML_Tag_Processor($tag);
        if (!$processor->next_tag()) {
            return $tag;
        }
        $href = $processor->get_attribute('href');
        if (!is_string($href) || 0 !== stripos(trim($href), 'mailto:')) {
            return $tag;
        }
        $processor->set_attribute('data-sps-mailto', self::encode(trim($href)));
        $processor->set_attribute('href', '#sps-email');
        return $processor->get_updated_html();
    }

    /**
     * Replace addresses in text nodes, never interpolate decoded HTML.
     *
     * @param string $text Text node's source.
     * @return string
     */
    private static function protect_text($text) {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pattern = '/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}/i';
        if (!preg_match_all($pattern, $decoded, $matches, PREG_OFFSET_CAPTURE)) {
            return $text;
        }
        // Re-escape only this text token, so entities cannot become markup.
        $output = '';
        $offset = 0;
        foreach ($matches[0] as $match) {
            $output .= self::escape_text(substr($decoded, $offset, $match[1] - $offset));
            $output .= '<span class="sps-email" data-sps-e="' . esc_attr(self::encode($match[0])) . '"></span><noscript>'
                . esc_html__('Email address hidden, enable JavaScript.', 'seoprostack') . '</noscript>';
            $offset = $match[1] + strlen($match[0]);
        }
        return $output . self::escape_text(substr($decoded, $offset));
    }

    /**
     * Escape already-decoded text, including literal entity-like strings.
     * esc_html() alone does not double-encode an ampersand in "&copy;".
     *
     * @param string $text Decoded text segment.
     * @return string
     */
    private static function escape_text($text) {
        return esc_html(str_replace('&', '&amp;', $text));
    }

    /**
     * Hex-encoded XOR bytes; first byte carries the response's key.
     *
     * @param string $value Address or complete mailto URI (including queries).
     * @return string
     */
    private static function encode($value) {
        if (null === self::$key) {
            self::$key = wp_rand(1, 255);
        }
        $encoded = sprintf('%02x', self::$key);
        for ($index = 0, $length = strlen($value); $index < $length; ++$index) {
            $encoded .= sprintf('%02x', ord($value[$index]) ^ self::$key);
        }
        // Footer loading works when a placeholder first appears after wp_head.
        wp_enqueue_script('seoprostack-email-protection', SEOPROSTACK_URL . 'assets/js/email-protection.js', array(), SEOPROSTACK_VERSION, true);
        return $encoded;
    }

    /**
     * Defer on WordPress 6.2 too (native strategies arrived in 6.3).
     *
     * @param string $tag    Script tag.
     * @param string $handle Script handle.
     * @return string
     */
    public static function defer_script($tag, $handle) {
        if ('seoprostack-email-protection' !== $handle) {
            return $tag;
        }
        $processor = new WP_HTML_Tag_Processor($tag);
        if ($processor->next_tag()) {
            $processor->set_attribute('defer', true);
        }
        return $processor->get_updated_html();
    }
}
