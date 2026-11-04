<?php
/**
 * Used CSS: a stylesheet read into rules, with what each rule's selectors
 * need (SEOProStack_Used_Css_Selector).
 *
 * Each rule is `['k' => css]` (always kept), `['r' => css, 'n' => needs]`
 * (kept when one of the `needs` lists is all in the page: `.class`, `#id`
 * or an element) or `['g' => '@media ...', 'c' => rules]`. Rules in
 * `@media`, `@supports`, `@container`, `@layer` and `@scope` are read the
 * same way; `@font-face`, `@keyframes` and the like, and `@import` and
 * `@layer` statements, are always kept. Relative url()s and @import paths
 * become absolute from the site's root, as the rules move into the page.
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

final class SEOProStack_Used_Css_Parser {

    /** Blocks whose rules are read one by one. */
    const GROUPS = '/^@(?:media|supports|container|layer|scope)\b/i';

    /**
     * A quoted string, escapes and all; an escaped quote outside one (as in
     * Tailwind's `.\[class\*\=\'x\'\]`) opens none. Possessive, so long
     * strings such as data: URIs stay within PCRE's stack.
     */
    const QUOTED = '(?<!\\\\)(["\'])(?:\\\\.|(?!\1)[^\\\\])*+\1';

    /** A string (kept) or a comment. */
    const COMMENTS = '~' . self::QUOTED . '|/\*.*?\*/~s';

    /** A string (kept) or a run of white space. */
    const SPACES = '~' . self::QUOTED . '|\s+~';

    /** Folder (URL path) of the stylesheet, for its url()s. */
    private $base;

    /**
     * Read a stylesheet.
     *
     * @param string $css  Stylesheet.
     * @param string $base Its folder (URL path), for relative url()s.
     * @return array|null Null when it cannot be read, so it is left as it is.
     */
    public static function parse($css, $base = '') {
        // Comments out, strings kept (a string may hold "/*").
        $css = preg_replace_callback(self::COMMENTS, function ($m) {
            return '/' === $m[0][0] ? '' : $m[0];
        }, (string) $css);
        if (!is_string($css)) {
            return null; // A PCRE limit: reading on would drop every rule.
        }
        $parser = new self($base);
        return $parser->block($css);
    }

    /**
     * A reader for one stylesheet.
     *
     * @param string $base Its folder (URL path).
     */
    private function __construct($base) {
        $this->base = rtrim((string) $base, '/');
    }

    /**
     * The rules of a stylesheet or of a block (@media, @supports...).
     *
     * @param string $css Rules.
     * @return array
     */
    private function block($css) {
        $out = array();
        $pos = 0;
        $len = strlen($css);
        while ($pos < $len) {
            $at = self::next($css, $pos, '{};');
            if ($at >= $len) {
                break;
            }
            $prelude = trim(substr($css, $pos, $at - $pos));
            if ('{' !== $css[$at]) {
                $out = array_merge($out, $this->statement($prelude, $css[$at]));
                $pos = $at + 1;
                continue;
            }
            $close = self::close($css, $at);
            if ($close < 0) {
                break;
            }
            if ('' !== $prelude) {
                $out[] = $this->node($prelude, substr($css, $at + 1, $close - $at - 1));
            }
            $pos = $close + 1;
        }
        return $out;
    }

    /**
     * A statement: @import and @layer names are kept; @charset has no
     * place in a page.
     *
     * @param string $prelude The statement.
     * @param string $end     The character that ended it.
     * @return array No rules, or one.
     */
    private function statement($prelude, $end) {
        if (';' !== $end || !preg_match('/^@(?!charset\b)/i', $prelude)) {
            return array();
        }
        // @import "file.css": relative to the stylesheet, as url() is.
        $prelude = (string) preg_replace_callback('/^(@import\s+)(["\'])([^"\']*)\2/i', function ($m) {
            return $m[1] . '"' . str_replace('"', '%22', $this->absolute($m[3])) . '"';
        }, $prelude);
        return array(array('k' => $this->urls($prelude) . ';'));
    }

    /**
     * One rule or block.
     *
     * @param string $prelude Selectors or at-rule.
     * @param string $body    Declarations or rules.
     * @return array
     */
    private function node($prelude, $body) {
        // White space on one line, but as it is inside strings ([title="a  b"]).
        $spaced  = preg_replace_callback(self::SPACES, function ($m) {
            return '"' === $m[0][0] || '\'' === $m[0][0] ? $m[0] : ' ';
        }, $prelude);
        $prelude = is_string($spaced) ? $spaced : $prelude;
        if ('@' !== $prelude[0]) {
            return $this->rule($prelude, $body);
        }
        if (preg_match(self::GROUPS, $prelude)) {
            return array('g' => $prelude, 'c' => $this->block($body));
        }
        return array('k' => $prelude . '{' . $this->urls(self::tidy($body)) . '}');
    }

    /**
     * A style rule, with what its selectors need.
     *
     * @param string $selectors Selector list.
     * @param string $body      Declarations.
     * @return array
     */
    private function rule($selectors, $body) {
        $css   = $selectors . '{' . $this->urls(self::tidy($body)) . '}';
        $needs = array();
        foreach (SEOProStack_Used_Css_Selector::split($selectors) as $selector) {
            $one = SEOProStack_Used_Css_Selector::needs($selector);
            if (!$one) {
                return array('k' => $css); // A selector every page meets.
            }
            $needs[implode(' ', $one)] = $one;
        }
        return array('r' => $css, 'n' => array_values($needs));
    }

    /**
     * Relative url()s made absolute (from the site's root), as the rules
     * move into the page.
     *
     * @param string $css CSS.
     * @return string
     */
    private function urls($css) {
        if ('' === $this->base || false === stripos($css, 'url(')) {
            return $css;
        }
        return (string) preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', function ($m) {
            $url = $this->absolute($m[2]);
            return trim($m[2]) === $url ? $m[0] : 'url("' . str_replace('"', '%22', $url) . '")';
        }, $css);
    }

    /**
     * An address relative to the stylesheet, from the site's root; any other
     * (with a scheme, from the root, or #fragment) as it is.
     *
     * @param string $url Address.
     * @return string
     */
    private function absolute($url) {
        $url = trim($url);
        if ('' === $this->base || '' === $url || preg_match('#^(?:[a-z][a-z0-9+.-]*:|/|\#)#i', $url)) {
            return $url;
        }
        return self::resolve($this->base . '/' . $url);
    }

    /**
     * A path with its `./` and `../` worked out.
     *
     * @param string $path Path.
     * @return string
     */
    private static function resolve($path) {
        do {
            $path = (string) preg_replace('#/(?:\./)+#', '/', $path, -1, $dots);
            $path = (string) preg_replace('#/(?!\.\./)[^/]+/\.\./#', '/', $path, -1, $ups);
        } while ($dots + $ups > 0);
        return $path;
    }

    /**
     * Declarations on one line (CSS strings hold no line breaks).
     *
     * @param string $body Declarations.
     * @return string
     */
    private static function tidy($body) {
        return trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', $body));
    }

    /**
     * The next of these characters outside strings and not escaped (as the
     * quote in Tailwind's `.\[class\*\=\'x\'\]` is).
     *
     * @param string $css   Stylesheet.
     * @param int    $pos   Where to start.
     * @param string $chars Characters to find.
     * @return int strlen($css) when there is none.
     */
    private static function next($css, $pos, $chars) {
        $len = strlen($css);
        while ($pos < $len) {
            $pos += strcspn($css, $chars . '"\'\\', $pos);
            if ($pos >= $len) {
                return $len;
            }
            $ch = $css[$pos];
            if ('\\' === $ch) {
                $pos += 2; // An escape takes the next character with it.
            } elseif (false === strpos('"\'', $ch)) {
                return $pos;
            } else {
                $pos = self::after_string($css, $pos);
            }
        }
        return $len;
    }

    /**
     * Where the string opened at `$pos` ends, plus one.
     *
     * @param string $css Stylesheet.
     * @param int    $pos Position of the opening quote.
     * @return int
     */
    private static function after_string($css, $pos) {
        $quote = $css[$pos];
        $len   = strlen($css);
        $i     = $pos + 1;
        while ($i < $len) {
            $ch = $css[$i];
            if ($quote === $ch || "\n" === $ch) {
                return $i + 1;
            }
            $i += '\\' === $ch ? 2 : 1; // An escape takes the next character with it.
        }
        return $len;
    }

    /**
     * Where the block opened at `$open` closes.
     *
     * @param string $css  Stylesheet.
     * @param int    $open Position of `{`.
     * @return int -1 when it does not close.
     */
    private static function close($css, $open) {
        $depth = 0;
        $pos   = $open;
        $len   = strlen($css);
        while ($pos < $len) {
            $pos = self::next($css, $pos, '{}');
            if ($pos >= $len) {
                return -1;
            }
            $depth += '{' === $css[$pos] ? 1 : -1;
            if (0 === $depth) {
                return $pos;
            }
            $pos++;
        }
        return -1;
    }
}
