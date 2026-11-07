<?php
/**
 * Used CSS: class names (and IDs) the page's scripts may add after it loads.
 *
 * Scripts open menus, start sliders, show form errors and animate blocks by
 * adding classes the page's HTML does not have yet, and build panels with
 * IDs of their own. Rules for those must be in the page, or those parts
 * look wrong until the full stylesheets arrive (GitHub issue #1260: Kadence
 * Blocks' slider adds `kb-splide`, its Pro animations `aos-init` and
 * `aos-animate`; #1355: Readabler builds `#mdp-readabler-voice-navigation`,
 * hidden only by an ID rule). A list kept by hand
 * (SEOProStack_Used_Css_Match::CLASSES) goes stale with each plugin update,
 * so every word in the page's scripts that could be a class name or ID counts as
 * present, as PurgeCSS does; and so does every prefix a script builds class
 * names from ("kt-active-tab-" + n, `${prefix}`). Which scripts: those that
 * run without the visitor doing anything, so before the full stylesheets
 * may have arrived: each `<script>` on the page with no type, a JavaScript
 * type (Cloudflare Rocket Loader's `…-text/javascript` too) or `module`,
 * by its file on this site (`src`, read once per version into a cache file
 * next to the stylesheets', SEOProStack_Used_Css_Sheet) or its inline code.
 * Scripts that wait for a click, a key or consent (`seoprostack/delayed`,
 * `litespeed/javascript`, `text/plain`) run after the full stylesheets, so
 * their words would only make the page heavier: on one test page, the
 * accessibility toolbar's delayed script alone added 41 KB. Interactivity
 * API directives (`data-wp-class--name`) count too.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.161
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProStack_Used_Css_Scripts {

    /** The reader's version: a change reads every script again. */
    const VERSION = '1';

    /** Larger script files are not read (bytes). */
    const MAX_BYTES = 5242880;

    /** Inline script code read on one page, at most (bytes). */
    const MAX_INLINE = 1048576;

    /** Code is read in parts of about this size, to keep memory low (bytes). */
    const CHUNK = 262144;

    /** A part ends after one of these: no word or prefix holds them, other than a prefix's closing `{`. */
    const BREAKS = ';,(){}[]:';

    /** A script element: 1 is the rest of its opening tag, 2 its code. */
    const SCRIPTS = '#<script\b([^>]*)>(.*?)</script\s*>#is';

    /** Words that could be class names or IDs. */
    const WORDS = '/-?[A-Za-z_][A-Za-z0-9_-]+/';

    /** A string ending in - or _ that code adds to: "name-" + x, `name-${x}`. */
    const PREFIX = '/([A-Za-z_][A-Za-z0-9_-]*[-_])(?:["\'`]\s*\+|\$\{)/';

    /** Shorter prefixes are left out ("-", "a-"): they would keep almost every rule. */
    const MIN_PREFIX = 3;

    /** An Interactivity API class directive. */
    const DIRECTIVE = '/\sdata-wp-class--([A-Za-z0-9_-]+)/';

    /** Script types that run as the page loads; no type does too. */
    const RUNS = '#^(?:module|(?:.*-)?(?:text|application)/(?:x-)?(?:java|ecma)script)$#i';

    /**
     * Script files read on this request: file => names and prefixes.
     *
     * @var array<string,array{names: array<string,bool>, prefixes: array<string,bool>}>
     */
    private static $read = array();

    /**
     * Words in the page's scripts that could be class names or IDs, and
     * prefixes the scripts build class names from.
     *
     * @param string $html Page.
     * @return array{names: array<string,bool>, prefixes: array<string,bool>}
     */
    public static function classes($html) {
        $html  = (string) $html;
        $found = array('names' => array(), 'prefixes' => array());
        if (preg_match_all(self::DIRECTIVE, $html, $directives)) {
            $found['names'] = array_fill_keys($directives[1], true);
        }
        if (!preg_match_all(self::SCRIPTS, $html, $scripts, PREG_SET_ORDER)) {
            return $found;
        }
        list($files, $inline) = self::running($scripts);
        foreach ($files as $file) {
            $found = self::merge($found, self::file($file));
        }
        return self::merge($found, self::read($inline));
    }

    /**
     * The local files and inline code of the scripts that run as the page
     * loads.
     *
     * @param array<int,array<int,string>> $scripts Script elements (SCRIPTS matches).
     * @return array{0: string[], 1: string} Files, and inline code (MAX_INLINE at most).
     */
    private static function running(array $scripts) {
        $inline = '';
        $files  = array();
        foreach ($scripts as $m) {
            $attrs = SEOProStack_Used_Css_Links::attributes('<script' . $m[1] . '>');
            if (isset($attrs['type']) && '' !== trim($attrs['type']) && !preg_match(self::RUNS, trim($attrs['type']))) {
                continue; // Waits for the visitor, or is data.
            }
            $file = isset($attrs['src']) ? SEOProStack_Used_Css_Links::script_file($attrs['src']) : '';
            if ('' !== $file) {
                $files[$file] = true;
            } elseif (strlen($inline) < self::MAX_INLINE) {
                $inline .= "\n" . $m[2];
            }
        }
        return array(array_keys($files), substr($inline, 0, self::MAX_INLINE));
    }

    /**
     * A script file's names and prefixes, read once per version of it.
     *
     * @param string $file File.
     * @return array{names: array<string,bool>, prefixes: array<string,bool>}
     */
    private static function file($file) {
        if (isset(self::$read[$file])) {
            return self::$read[$file];
        }
        $size = is_readable($file) ? (int) filesize($file) : 0;
        $data = array('names' => array(), 'prefixes' => array());
        if ($size > 0 && $size <= self::MAX_BYTES) {
            $data = self::cached_or_read($file, $size);
        }
        self::$read[$file] = $data;
        return $data;
    }

    /**
     * A script file's names and prefixes from its cache file, or read and
     * cached.
     *
     * @param string $file File.
     * @param int    $size Its size.
     * @return array{names: array<string,bool>, prefixes: array<string,bool>}
     */
    private static function cached_or_read($file, $size) {
        $folder = SEOProStack_Used_Css_Sheet::folder();
        $key    = 'js-' . substr(hash('sha256', $file), 0, 12);
        $name   = $key . '-' . substr(hash('sha256', filemtime($file) . '|' . $size . '|' . self::VERSION), 0, 8) . '.json';
        $lists  = '' !== $folder ? SEOProStack_Used_Css_Sheet::cached($folder . '/' . $name) : null;
        if (is_array($lists) && isset($lists['names'], $lists['prefixes']) && is_array($lists['names']) && is_array($lists['prefixes'])) {
            return array(
                'names'    => array_fill_keys(array_map('strval', $lists['names']), true),
                'prefixes' => array_fill_keys(array_map('strval', $lists['prefixes']), true),
            );
        }
        $code = file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local script.
        $data = self::read(is_string($code) ? $code : '');
        if ('' !== $folder) {
            SEOProStack_Used_Css_Sheet::write($folder, $name, $key, array(
                'names'    => array_map('strval', array_keys($data['names'])),
                'prefixes' => array_map('strval', array_keys($data['prefixes'])),
            ));
        }
        return $data;
    }

    /**
     * The words and prefixes in some code, read in parts.
     *
     * @param string $code Code.
     * @return array{names: array<string,bool>, prefixes: array<string,bool>}
     */
    private static function read($code) {
        $data = array('names' => array(), 'prefixes' => array());
        $len  = strlen($code);
        for ($at = 0; $at < $len; $at = $end) {
            $end = $at + self::CHUNK;
            $end = $end < $len ? min($len, $end + strcspn($code, self::BREAKS, $end) + 1) : $len;
            $part = substr($code, $at, $end - $at);
            if (preg_match_all(self::WORDS, $part, $words)) {
                $data['names'] += array_fill_keys($words[0], true);
            }
            if (preg_match_all(self::PREFIX, $part, $prefixes)) {
                foreach ($prefixes[1] as $prefix) {
                    if (strlen($prefix) >= self::MIN_PREFIX) {
                        $data['prefixes'][$prefix] = true;
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Two sets of names and prefixes together.
     *
     * @param array{names: array<string,bool>, prefixes: array<string,bool>} $a One.
     * @param array{names: array<string,bool>, prefixes: array<string,bool>} $b The other.
     * @return array{names: array<string,bool>, prefixes: array<string,bool>}
     */
    private static function merge(array $a, array $b) {
        return array('names' => $a['names'] + $b['names'], 'prefixes' => $a['prefixes'] + $b['prefixes']);
    }
}
