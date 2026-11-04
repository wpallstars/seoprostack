<?php
/**
 * Used CSS: a stylesheet's rules, with what each rule's selectors need.
 *
 * Each stylesheet is read once per version (its file, size, modified time
 * and folder) into a cache file in uploads/seoprostack-used-css, so a page
 * only matches its classes, IDs and elements against the rules
 * (SEOProStack_Used_Css). A rule needs every class, ID and element named in
 * one of its selectors, outside attribute selectors and pseudo-classes
 * (their contents, as in :not() or :is(), are not needed). Rules in
 * `@media`, `@supports`, `@container`, `@layer` and `@scope` are read the
 * same way; `@font-face`, `@keyframes` and the like, and `@import` and
 * `@layer` statements, are always kept. Relative url()s become absolute
 * from the site's root, as the rules move into the page.
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

final class SEOProStack_Used_Css_Sheet {

    /** The reader's version: a change reads every stylesheet again. */
    const VERSION = '1';

    /** Folder in uploads for the cache files. */
    const DIR = 'seoprostack-used-css';

    /** Larger stylesheets are left as they are (bytes). */
    const MAX_BYTES = 1048576;

    /** Blocks whose rules are read one by one. */
    const GROUPS = '/^@(?:media|supports|container|layer|scope)\b/i';

    /** Elements every page has. */
    const ALWAYS = array('html' => true, 'body' => true, 'head' => true);

    /** Folder (URL path) of the stylesheet being read, for its url()s. */
    private static $base = '';

    /** Stylesheets read on this request: file => rules. */
    private static $read = array();

    /**
     * A stylesheet's rules, read once per version of the file. Each is
     * `['k' => css]` (always kept), `['r' => css, 'n' => needs]` (kept when
     * one of the `needs` lists is all in the page: `.class`, `#id` or an
     * element) or `['g' => '@media ...', 'c' => rules]`.
     *
     * @param string $file Stylesheet file.
     * @param string $href Its address.
     * @return array|null Null when it cannot be read.
     */
    public static function rules($file, $href) {
        if (isset(self::$read[$file])) {
            return self::$read[$file];
        }
        $size = is_readable($file) ? (int) filesize($file) : 0;
        if ($size < 1 || $size > self::MAX_BYTES) {
            return null;
        }
        $base   = untrailingslashit(dirname((string) wp_parse_url($href, PHP_URL_PATH)));
        $folder = self::folder();
        $key    = substr(hash('sha256', $file), 0, 12);
        $name   = $key . '-' . substr(hash('sha256', filemtime($file) . '|' . $size . '|' . $base . '|' . self::VERSION), 0, 8) . '.json';
        $rules  = $folder ? self::cached($folder . '/' . $name) : null;
        if (null === $rules) {
            $css = file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local stylesheet.
            if (!is_string($css)) {
                return null;
            }
            $rules = self::parse($css, $base);
            if ($folder) {
                self::write($folder, $name, $key, $rules);
            }
        }
        self::$read[$file] = $rules;
        return $rules;
    }

    /**
     * Rules from the cache file, if it is there and whole.
     *
     * @param string $path Cache file.
     * @return array|null
     */
    private static function cached($path) {
        if (!is_file($path)) {
            return null;
        }
        $json  = file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own cache file.
        $rules = is_string($json) ? json_decode($json, true) : null;
        return is_array($rules) ? $rules : null;
    }

    /**
     * Read a stylesheet.
     *
     * @param string $css  Stylesheet.
     * @param string $base Its folder (URL path), for relative url()s.
     * @return array
     */
    public static function parse($css, $base = '') {
        self::$base = rtrim((string) $base, '/');
        // Comments out, strings kept (a string may hold "/*").
        $css = (string) preg_replace('~("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|/\*.*?\*/~s', '$1', $css);
        return self::block($css);
    }

    /**
     * The rules of a stylesheet or of a block (@media, @supports...).
     *
     * @param string $css Rules.
     * @return array
     */
    private static function block($css) {
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
                // A statement: @import and @layer names are kept; @charset has no place in a page.
                if (';' === $css[$at] && preg_match('/^@(?!charset\b)/i', $prelude)) {
                    $out[] = array('k' => self::urls($prelude) . ';');
                }
                $pos = $at + 1;
                continue;
            }
            $close = self::close($css, $at);
            if ($close < 0) {
                break;
            }
            $body = substr($css, $at + 1, $close - $at - 1);
            $pos  = $close + 1;
            if ('' !== $prelude) {
                $out[] = self::node($prelude, $body);
            }
        }
        return $out;
    }

    /**
     * One rule or block.
     *
     * @param string $prelude Selectors or at-rule.
     * @param string $body    Declarations or rules.
     * @return array
     */
    private static function node($prelude, $body) {
        $prelude = (string) preg_replace('/\s+/', ' ', $prelude);
        if ('@' === $prelude[0]) {
            return preg_match(self::GROUPS, $prelude)
                ? array('g' => $prelude, 'c' => self::block($body))
                : array('k' => $prelude . '{' . self::urls(self::tidy($body)) . '}');
        }
        $css   = $prelude . '{' . self::urls(self::tidy($body)) . '}';
        $needs = array();
        foreach (self::split($prelude) as $selector) {
            $one = self::needs($selector);
            if (!$one) {
                return array('k' => $css); // A selector every page meets.
            }
            $needs[implode(' ', $one)] = $one;
        }
        return array('r' => $css, 'n' => array_values($needs));
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
     * What a selector needs in the page: `.class`, `#id` and elements.
     * Attribute selectors and pseudo-classes, with what they hold, are not
     * needed, so a rule is kept whenever the page might use it.
     *
     * @param string $selector One selector.
     * @return string[]
     */
    public static function needs($selector) {
        // Escaped characters, such as "\:" in ".md\:flex", stand aside as markers.
        $escaped  = array();
        $selector = (string) preg_replace_callback('/\\\\(?:([0-9a-fA-F]{1,6})\s?|(.))/s', function ($m) use (&$escaped) {
            $escaped[] = isset($m[2]) && '' !== $m[2] ? $m[2] : self::character((int) hexdec($m[1]));
            return "\x01" . (count($escaped) - 1) . "\x02";
        }, $selector);
        $selector = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '', $selector);
        $selector = (string) preg_replace('/\[[^\]]*\]/', '', $selector);
        do {
            $before   = $selector;
            $selector = (string) preg_replace('/\([^()]*\)/', '', $selector);
        } while ($selector !== $before);
        $selector = (string) preg_replace('/::?[a-zA-Z_-][\w-]*/', '', $selector);

        $needs = array();
        if (preg_match_all('/([.#]?)((?:-?[_a-zA-Z\x80-\xff\x01]|--)[\w\x80-\xff\x01\x02-]*)/', $selector, $found, PREG_SET_ORDER)) {
            foreach ($found as $m) {
                $name = (string) preg_replace_callback('/\x01(\d+)\x02/', function ($e) use ($escaped) {
                    return isset($escaped[(int) $e[1]]) ? $escaped[(int) $e[1]] : '';
                }, $m[2]);
                $token = '' === $m[1] ? strtolower($name) : $m[1] . $name;
                if ('' !== $name && !isset(self::ALWAYS[$token])) {
                    $needs[$token] = true;
                }
            }
        }
        return array_keys($needs);
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

    /**
     * A selector list's selectors (commas inside brackets kept).
     *
     * @param string $list Selector list.
     * @return string[]
     */
    private static function split($list) {
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
     * The next of these characters outside strings.
     *
     * @param string $css   Stylesheet.
     * @param int    $pos   Where to start.
     * @param string $chars Characters to find.
     * @return int strlen($css) when there is none.
     */
    private static function next($css, $pos, $chars) {
        $len = strlen($css);
        while ($pos < $len) {
            $pos += strcspn($css, $chars . '"\'', $pos);
            if ($pos >= $len || false === strpos('"\'', $css[$pos])) {
                return $pos;
            }
            $pos = self::after_string($css, $pos);
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
        for ($i = $pos + 1; $i < $len; $i++) {
            if ('\\' === $css[$i]) {
                $i++;
            } elseif ($quote === $css[$i] || "\n" === $css[$i]) {
                return $i + 1;
            }
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

    /**
     * Relative url()s made absolute (from the site's root), as the rules
     * move into the page.
     *
     * @param string $css CSS.
     * @return string
     */
    private static function urls($css) {
        if (false === stripos($css, 'url(') || '' === self::$base) {
            return $css;
        }
        return (string) preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', function ($m) {
            $url = trim($m[2]);
            if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|/|\#)#i', $url)) {
                return $m[0];
            }
            $path = self::$base . '/' . $url;
            do {
                $path = (string) preg_replace('#/(?:\./)+#', '/', $path, -1, $n);
                $path = (string) preg_replace('#/(?!\.\./)[^/]+/\.\./#', '/', $path, -1, $k);
            } while ($n + $k > 0);
            return 'url("' . str_replace('"', '%22', $path) . '")';
        }, $css);
    }

    /**
     * Folder for this site's cache files.
     *
     * @return string Empty when uploads cannot be used.
     */
    public static function folder() {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return '';
        }
        return untrailingslashit(wp_normalize_path($uploads['basedir'])) . '/' . self::DIR;
    }

    /**
     * Write a cache file whole (a temporary file, then renamed, as requests
     * run at once), and remove the stylesheet's older ones.
     *
     * @param string $folder Folder.
     * @param string $name   File name.
     * @param string $key    The stylesheet's part of the name.
     * @param array  $rules  Rules.
     */
    private static function write($folder, $name, $key, array $rules) {
        $json = wp_json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || !wp_mkdir_p($folder)) {
            return;
        }
        $target = $folder . '/' . $name;
        $temp   = $target . '.' . wp_generate_password(8, false) . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary file renamed into place.
        if (false === file_put_contents($temp, $json)) {
            return;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- same directory.
        if (!@rename($temp, $target)) {
            wp_delete_file($temp);
            return;
        }
        foreach ((array) glob($folder . '/' . $key . '-*.json') as $old) {
            if (is_string($old) && $old !== $target) {
                wp_delete_file($old);
            }
        }
    }

    /** Remove this site's cache files. */
    public static function remove() {
        $folder = self::folder();
        if ('' === $folder || !is_dir($folder)) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $filesystem = new WP_Filesystem_Direct(null);
        $filesystem->delete($folder, true, 'd');
    }
}
