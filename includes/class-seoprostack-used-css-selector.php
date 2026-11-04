<?php
/**
 * Used CSS: what a CSS selector needs in a page.
 *
 * A selector needs every class, ID and element it names, outside attribute
 * selectors and pseudo-classes: their contents, as in :not() or :is(), are
 * not needed, so a rule is kept whenever the page might use it.
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

final class SEOProStack_Used_Css_Selector {

    /** Elements every page has. */
    const ALWAYS = array('html' => true, 'body' => true, 'head' => true);

    /** A class (`.name`), an ID (`#name`) or an element; \x01 and \x02 mark escaped characters. */
    const TOKEN = '/([.#]?)((?:-?[_a-zA-Z\x80-\xff\x01]|--)[\w\x80-\xff\x01\x02-]*)/';

    /**
     * What a selector needs in the page: `.class`, `#id` and elements.
     *
     * @param string $selector One selector.
     * @return string[]
     */
    public static function needs($selector) {
        $escaped  = array();
        $selector = self::bare(self::hide_escapes((string) $selector, $escaped));
        $needs    = array();
        if (preg_match_all(self::TOKEN, $selector, $found, PREG_SET_ORDER)) {
            foreach ($found as $m) {
                $name = self::show_escapes($m[2], $escaped);
                if ('' === $name) {
                    continue;
                }
                $token = '' === $m[1] ? strtolower($name) : $m[1] . $name;
                if (!isset(self::ALWAYS[$token])) {
                    $needs[$token] = true;
                }
            }
        }
        return array_keys($needs);
    }

    /**
     * A selector list's selectors (commas inside brackets kept).
     *
     * @param string $list Selector list.
     * @return string[]
     */
    public static function split($list) {
        $parts = array();
        $depth = 0;
        $start = 0;
        $len   = strlen($list);
        for ($i = 0; $i < $len; $i++) {
            $ch = $list[$i];
            if ('(' === $ch || '[' === $ch) {
                $depth++;
            } elseif (')' === $ch || ']' === $ch) {
                $depth--;
            } elseif (',' === $ch && 0 === $depth) {
                $parts[] = substr($list, $start, $i - $start);
                $start   = $i + 1;
            }
        }
        $parts[] = substr($list, $start);
        return $parts;
    }

    /**
     * Escaped characters, such as "\:" in ".md\:flex", set aside as markers
     * so they cannot end a name.
     *
     * @param string   $selector Selector.
     * @param string[] $escaped  The characters set aside.
     * @return string
     */
    private static function hide_escapes($selector, array &$escaped) {
        return (string) preg_replace_callback('/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/s', function ($m) use (&$escaped) {
            $escaped[] = isset($m[2]) ? $m[2] : self::character((int) hexdec($m[1]));
            return "\x01" . (count($escaped) - 1) . "\x02";
        }, $selector);
    }

    /**
     * A name with its escaped characters back.
     *
     * @param string   $name    Name.
     * @param string[] $escaped The characters set aside.
     * @return string
     */
    private static function show_escapes($name, array $escaped) {
        if (false === strpos($name, "\x01")) {
            return $name;
        }
        return (string) preg_replace_callback('/\x01(\d+)\x02/', function ($e) use ($escaped) {
            return isset($escaped[(int) $e[1]]) ? $escaped[(int) $e[1]] : '';
        }, $name);
    }

    /**
     * The selector without strings, attribute selectors, pseudo-classes
     * and pseudo-elements, and what brackets hold.
     *
     * @param string $selector Selector.
     * @return string
     */
    private static function bare($selector) {
        $selector = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '', $selector);
        $selector = (string) preg_replace('/\[[^\]]*\]/', '', $selector);
        do {
            $before   = $selector;
            $selector = (string) preg_replace('/\([^()]*\)/', '', $selector);
        } while ($selector !== $before);
        return (string) preg_replace('/::?[a-zA-Z_-][\w-]*/', '', $selector);
    }

    /**
     * A character from its code point (CSS hex escapes).
     *
     * @param int $code Code point.
     * @return string
     */
    private static function character($code) {
        if ($code < 1 || $code > 0x10FFFF) {
            $code = 0xFFFD;
        }
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }
        if ($code < 0x10000) {
            return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
        }
        return chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 0x3F)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }
}
