<?php
/**
 * Used CSS: one page rewritten. Each handled stylesheet link
 * (SEOProStack_Used_Css_Links) becomes a `<style>` with the rules this page
 * can use (SEOProStack_Used_Css_Match), in the link's place, so the cascade
 * is as before; the link loaded without blocking (media="print", switched
 * back once loaded); and a `<noscript>` copy for browsers without
 * JavaScript.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.145
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Used_Css_Page {

    /** Comments, scripts and the like are passed over: links there are not the page's. */
    const TAGS = '#<!--.*?-->|<(script|noscript|template|textarea)\b[^>]*>.*?</\1\s*>|<link\b[^>]*>#is';

    /** Which rules this page can use. */
    private $match;

    /** Stylesheets changed on this page. */
    private $sheets = 0;

    /** Bytes of rules put in the page. */
    private $used = 0;

    /** Bytes of the stylesheets changed. */
    private $full = 0;

    /**
     * Put each handled stylesheet's used rules in the page and load it
     * without blocking (an output buffer callback).
     *
     * @param string $html Page.
     * @return string
     */
    public static function rewrite($html) {
        if (false === stripos($html, '<link') || !self::is_html($html)) {
            return $html;
        }
        $page = new self($html);
        $out  = preg_replace_callback(self::TAGS, array($page, 'tag'), $html);
        if (!is_string($out) || $out === $html) {
            return $html; // Unchanged, or the regex hit a PCRE limit.
        }
        return $out . $page->summary();
    }

    /**
     * A page to rewrite.
     *
     * @param string $html Page.
     */
    private function __construct($html) {
        $this->match = new SEOProStack_Used_Css_Match($html);
    }

    /**
     * Whether the response is an HTML page.
     *
     * @param string $html Response.
     * @return bool
     */
    private static function is_html($html) {
        if (false === stripos($html, '</head>') || 0 !== strpos(ltrim(substr($html, 0, 500)), '<')) {
            return false;
        }
        foreach (headers_list() as $header) {
            if (0 === stripos($header, 'content-type:') && false === stripos($header, 'html')) {
                return false;
            }
        }
        return true;
    }

    /**
     * One tag the rewrite came to.
     *
     * @param array $m Match.
     * @return string
     */
    public function tag(array $m) {
        if (0 !== stripos($m[0], '<link')) {
            return $m[0];
        }
        try {
            return $this->link($m[0]);
        } catch (Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[SEO Pro Stack] Used CSS left a stylesheet as it was: ' . $e->getMessage());
            }
            return $m[0];
        }
    }

    /**
     * A stylesheet link: its used rules and the link loaded without blocking,
     * or the link as it was.
     *
     * @param string $tag `<link>` tag.
     * @return string
     */
    private function link($tag) {
        $attrs = SEOProStack_Used_Css_Links::attributes($tag);
        $media = isset($attrs['media']) ? trim($attrs['media']) : 'all';
        if (!SEOProStack_Used_Css_Links::candidate($attrs, $media)) {
            return $tag;
        }
        $file = SEOProStack_Used_Css_Links::file($attrs['href']);
        if ('' === $file || SEOProStack_Used_Css_Links::kept($attrs, $file)) {
            return $tag;
        }
        $rules = SEOProStack_Used_Css_Sheet::rules($file, $attrs['href']);
        if (null === $rules) {
            return $tag;
        }
        $css = $this->match->used($rules);
        $this->sheets++;
        $this->used += strlen($css);
        $this->full += (int) filesize($file);
        return self::replacement($tag, $attrs, $media, $css);
    }

    /**
     * What was done, as an HTML comment at the end of the page.
     *
     * @return string
     */
    private function summary() {
        return sprintf(
            "\n<!-- SEO Pro Stack Used CSS: %d stylesheets, %s of %s in the page -->",
            $this->sheets,
            size_format($this->used, 1),
            size_format($this->full, 1)
        );
    }

    /**
     * The used rules, the link loaded without blocking, and a copy for
     * browsers without JavaScript.
     *
     * @param string $tag   Original tag.
     * @param array  $attrs Its attributes.
     * @param string $media Its media.
     * @param string $css   Used rules.
     * @return string
     */
    private static function replacement($tag, array $attrs, $media, $css) {
        $style = '';
        if ('' !== $css) {
            $style = sprintf(
                '<style%s%s>%s</style>',
                isset($attrs['id']) && '' !== $attrs['id'] ? ' id="' . esc_attr($attrs['id'] . '-used') . '"' : '',
                'all' !== strtolower($media) ? ' media="' . esc_attr($media) . '"' : '',
                str_ireplace('</style', '<\/style', $css)
            );
        }
        $bare     = (string) preg_replace('/\smedia\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag);
        $async    = (string) preg_replace('#\s*/?>$#', sprintf(' media="print" onload="this.onload=null;this.media=\'%s\'" />', esc_attr($media)), $bare, 1);
        $noscript = (string) preg_replace('/\sid\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag);
        return $style . $async . '<noscript>' . $noscript . '</noscript>';
    }
}
