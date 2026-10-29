<?php
/**
 * Fluent Forms by key: links to the privacy policy, cookie policy or terms
 * inside a Fluent form open in a new tab, with rel="noopener", the
 * seoprostack-new-tab class and "(opens in a new tab)" for screen readers,
 * so reading them never loses what the visitor typed. Links that already
 * open a new tab are left alone.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.96
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Form_Policy_Links {

    /** The fields whose HTML can hold policy links. */
    const ELEMENTS = array('terms_and_condition', 'gdpr_agreement', 'input_checkbox', 'custom_html');

    /**
     * Filter the fields' HTML, after other filters of the same fields: a
     * link one of them has already opened in a new tab is left alone.
     */
    public static function boot() {
        foreach (self::ELEMENTS as $element) {
            add_filter('fluentform/rendering_field_html_' . $element, array(__CLASS__, 'filter'), 25);
        }
    }

    /**
     * Open a field's policy links in a new tab.
     *
     * @param string $html Field HTML.
     * @return string
     */
    public static function filter($html) {
        if (!is_string($html) || false === stripos($html, '<a')) {
            return $html;
        }
        $paths = self::paths();
        if (!$paths) {
            return $html;
        }
        $pattern = '~/(?:' . implode('|', array_map(static function ($path) {
            return preg_quote($path, '~');
        }, $paths)) . ')/?(?:[?#].*)?$~i';
        return (string) preg_replace_callback(
            '~<a\b([^>]*)>(.*?)</a>~is',
            static function ($match) use ($pattern) {
                return self::link($match, $pattern);
            },
            $html
        );
    }

    /**
     * One link, opened in a new tab when it goes to a policy page.
     *
     * @param array<int,string> $match   The link, its attributes and its text.
     * @param string            $pattern Policy page path pattern.
     * @return string
     */
    private static function link(array $match, $pattern) {
        $attrs = $match[1];
        if (!preg_match('~\bhref\s*=\s*(["\'])([^"\']*)\1~i', $attrs, $href) || !preg_match($pattern, $href[2]) || preg_match('~\btarget\s*=\s*(["\'])_blank\1~i', $attrs)) {
            return $match[0];
        }
        $attrs = (string) preg_replace('~\s(?:target|rel)\s*=\s*(["\'])[^"\']*\1~i', '', $attrs);
        if (preg_match('~\bclass\s*=\s*(["\'])([^"\']*)\1~i', $attrs)) {
            $attrs = (string) preg_replace('~\bclass\s*=\s*(["\'])([^"\']*)\1~i', 'class=$1$2 seoprostack-new-tab$1', $attrs, 1);
        } else {
            $attrs .= ' class="seoprostack-new-tab"';
        }
        return '<a' . $attrs . ' target="_blank" rel="noopener">' . $match[2] . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'seoprostack') . '</span></a>';
    }

    /**
     * Policy page paths matched in form links.
     *
     * @return string[]
     */
    private static function paths() {
        $paths   = array('privacy-policy', 'cookie-policy', 'terms', 'terms-and-conditions', 'terms-of-service', 'terms-of-use');
        $privacy = (int) get_option('wp_page_for_privacy_policy');
        if ($privacy) {
            $paths[] = trim((string) wp_parse_url((string) get_permalink($privacy), PHP_URL_PATH), '/');
        }
        /**
         * Filter the page paths whose links in forms open a new tab.
         *
         * @param string[] $paths Paths without slashes at either end, such as "privacy-policy".
         */
        $paths = (array) apply_filters('seoprostack_form_policy_paths', $paths);
        return array_values(array_unique(array_filter(array_map(static function ($path) {
            return trim((string) $path, '/');
        }, $paths))));
    }
}
