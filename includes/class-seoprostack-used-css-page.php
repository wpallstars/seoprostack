<?php
/**
 * Used CSS: one page rewritten. Each handled stylesheet link
 * (SEOProStack_Used_Css_Links) becomes a `<style>` with the rules this page
 * can use (SEOProStack_Used_Css_Match), in the link's place, so the cascade
 * is as before; the link loaded without blocking (media="print", switched
 * back once loaded); and a `<noscript>` copy for browsers without
 * JavaScript.
 *
 * With "Load the full stylesheets once the page has loaded" (on by default),
 * the link waits, without an address, until the page's load event or the
 * visitor's first move, tap, scroll or key, whichever comes first; a small
 * script at the end of the page then gives it its address. On a slow phone
 * the full stylesheets no longer share the connection with the pictures and
 * fonts the first screen needs: on four Kadence sites, local Lighthouse
 * mobile runs scored 4 to 8 points higher (GitHub issue #1426). Scripts
 * Delay scripts holds wait for the full stylesheets
 * (window.seoprostackCss), as their classes are not in the page's rules
 * (SEOProStack_Used_Css_Scripts). Pages with scripts another plugin holds
 * until the visitor interacts (LiteSpeed Cache's, WP Rocket's, Perfmatters'
 * or WP Meteor's) load them straight away, as before, as those scripts
 * cannot be made to wait.
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

    /**
     * Comments, scripts and the like are passed over: links there are not the
     * page's. A link ends at the first `>` outside quotes; a quoted value
     * holding `<` (or never closed) leaves the link as it is.
     */
    const TAGS = '#<!--.*?-->|<(script|noscript|template|textarea)\b[^>]*>.*?</\1\s*>|<link\b(?:[^>"\']++|"[^"<]*+"|\'[^\'<]*+\')*+>#is';

    /** Scripts another plugin holds until the visitor interacts: LiteSpeed Cache, WP Rocket, Perfmatters, WP Meteor. */
    const HELD_ELSEWHERE = '#<script\b[^>]*\btype\s*=\s*["\']?(?:litespeed/javascript|rocketlazyloadscript|pmdelayedscript|javascript/blocked)\b#i';

    /** Which rules this page can use. */
    private $match;

    /** Whether the page's policy allows an inline script (Kadence Pro's dark mode script goes in the page). */
    private $script = false;

    /** Whether the full stylesheets wait until the page has loaded. */
    private $later = false;

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
        if (false === stripos($html, '<link') || !self::is_html($html) || !SEOProStack_Used_Css_Policy::allows($html)) {
            return $html;
        }
        $page = new self($html, SEOProStack_Used_Css_Policy::allows($html, true));
        $out  = preg_replace_callback(self::TAGS, array($page, 'tag'), $html);
        if (!is_string($out) || $out === $html) {
            return $html; // Unchanged, or the regex hit a PCRE limit.
        }
        if ($page->later && $page->sheets) {
            $out = self::before_body_end($out, '<script id="seoprostack-used-css-later">' . self::later_js() . '</script>');
        }
        return $out . $page->summary();
    }

    /**
     * A page to rewrite.
     *
     * @param string $html   Page.
     * @param bool   $script Whether the policy allows an inline script.
     */
    private function __construct($html, $script) {
        $this->match  = new SEOProStack_Used_Css_Match($html);
        $this->script = $script;
        $this->later  = $script && SEOProStack_Settings::get(SEOProStack_Used_Css::LATER_KEY) && !preg_match(self::HELD_ELSEWHERE, $html);
    }

    /**
     * Some markup put just before the page's last `</body>`, or at its end.
     *
     * @param string $html   Page.
     * @param string $markup Markup.
     * @return string
     */
    private static function before_body_end($html, $markup) {
        $pos = strripos($html, '</body>');
        return false === $pos ? $html . $markup : substr($html, 0, $pos) . $markup . substr($html, $pos);
    }

    /**
     * The script that gives the waiting links their addresses on the page's
     * load event or the visitor's first move, tap, scroll or key (listened
     * for before anything else hears it). Each is loaded as print, then
     * switched to its media once loaded, so it never blocks the page.
     * window.seoprostackCss() starts this at once and returns a promise kept
     * when every one has loaded, failed or 3 seconds have passed: Delay
     * scripts waits for it.
     *
     * @return string
     */
    private static function later_js() {
        return '(function(){var w=window,d=document,p,o={capture:true,passive:true},'
            . 'e=["mousemove","mousedown","keydown","touchstart","wheel","scroll"];'
            . 'function go(){if(p)return p;e.forEach(function(n){w.removeEventListener(n,go,o);});'
            . 'var l=[].slice.call(d.querySelectorAll("link[data-seoprostack-href]"));'
            . 'p=Promise.race([Promise.all(l.map(function(k){return new Promise(function(ok){'
            . 'k.onload=k.onerror=function(){k.onload=k.onerror=null;k.media=k.getAttribute("data-seoprostack-media")||"all";ok();};'
            . 'k.href=k.getAttribute("data-seoprostack-href");k.removeAttribute("data-seoprostack-href");});})),'
            . 'new Promise(function(ok){setTimeout(ok,3000);})]);return p;}'
            . 'w.seoprostackCss=go;e.forEach(function(n){w.addEventListener(n,go,o);});'
            . 'if(d.readyState==="complete")go();else w.addEventListener("load",function(){setTimeout(go,0);});})();';
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
        $script = 0 === stripos($m[0], '<script');
        if (!$script && 0 !== stripos($m[0], '<link')) {
            return $m[0];
        }
        if ($script && !$this->script) {
            return $m[0];
        }
        try {
            return $script ? $this->script($m[0]) : $this->link($m[0]);
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
        $media = isset($attrs['media']) ? trim((string) preg_replace('/\s+/', ' ', $attrs['media'])) : 'all';
        if (!SEOProStack_Used_Css_Links::candidate($attrs, $media)) {
            return $tag;
        }
        $file = SEOProStack_Used_Css_Links::file($attrs['href'], $attrs);
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
        return self::replacement($tag, $attrs, $media, $css, $this->later);
    }

    /**
     * Kadence Pro's dark mode script with its code in the page, or the tag as
     * it was. It keeps its id and place, and stays synchronous.
     *
     * @param string $tag `<script>` element.
     * @return string
     */
    private function script($tag) {
        $code = SEOProStack_Used_Css_Links::dark_mode_script($tag);
        return '' === $code ? $tag : '<script id="kadence-dark-mode-js">' . $code . '</script>';
    }

    /**
     * What was done, as an HTML comment at the end of the page.
     *
     * @return string
     */
    private function summary() {
        return sprintf(
            "\n<!-- SEO Pro Stack Used CSS: %d stylesheets, %s of %s in the page%s -->",
            $this->sheets,
            size_format($this->used, 1),
            size_format($this->full, 1),
            $this->later ? ', the full stylesheets once it has loaded' : ''
        );
    }

    /**
     * The used rules, the link loaded without blocking (or, $later, waiting
     * without an address for later_js()), and a copy for browsers without
     * JavaScript.
     *
     * @param string $tag   Original tag.
     * @param array  $attrs Its attributes.
     * @param string $media Its media.
     * @param string $css   Used rules.
     * @param bool   $later Whether the link waits until the page has loaded.
     * @return string
     */
    private static function replacement($tag, array $attrs, $media, $css, $later = false) {
        $style = '';
        if ('' !== $css) {
            $style = sprintf(
                '<style%s%s>%s</style>',
                isset($attrs['id']) && '' !== $attrs['id'] ? ' id="' . esc_attr($attrs['id'] . '-used') . '"' : '',
                'all' !== strtolower($media) ? ' media="' . esc_attr($media) . '"' : '',
                str_ireplace('</style', '<\/style', $css)
            );
        }
        $bare = (string) preg_replace('/\smedia\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag);
        if ($later) {
            // The address moves to data-seoprostack-href, as it was written.
            $waiting = (string) preg_replace('/\shref\s*=/i', ' data-seoprostack-href=', $bare, 1);
            $async   = (string) preg_replace('#\s*/?>$#', sprintf(' media="print" data-seoprostack-media="%s" />', esc_attr($media)), $waiting, 1);
        } else {
            $async = (string) preg_replace('#\s*/?>$#', sprintf(' media="print" onload="this.onload=null;this.media=\'%s\'" />', esc_attr($media)), $bare, 1);
        }
        $noscript = (string) preg_replace('/\sid\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag);
        return $style . $async . '<noscript>' . $noscript . '</noscript>';
    }
}
