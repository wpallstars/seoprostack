<?php
/**
 * Used CSS: which stylesheet links are handled, and their local files.
 *
 * A plain `<link rel="stylesheet">` that holds up the page (no onload, not
 * disabled, plain media other than print), served from this site, from the
 * Kadence parent theme, Kadence Blocks (Pro), Kadence Pro, Fluent Forms
 * (Pro) or WordPress's block library (filter seoprostack_used_css_sources),
 * and not on the list to load as it is.
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

final class SEOProStack_Used_Css_Links {

    /** Plugin folders whose stylesheets are handled, with the Kadence theme and the block library. */
    const PLUGINS = array('kadence-blocks', 'kadence-blocks-pro', 'kadence-pro', 'fluentform', 'fluentformpro');

    /** Folders whose stylesheets are handled, or null before they are worked out. */
    private static $sources = null;

    /**
     * A tag's attributes: lower-case name => value (entities decoded).
     *
     * @param string $tag Tag.
     * @return array<string,string>
     */
    public static function attributes($tag) {
        $attrs = array();
        // Name, then the value: quoted (2 is the quote, 3 the value) or not (4).
        if (preg_match_all('/([^\s=\/>]+)(?:\s*=\s*(?:(["\'])(.*?)\2|([^\s>]+)))?/s', substr($tag, 5), $found, PREG_SET_ORDER)) {
            foreach ($found as $m) {
                $value                    = (isset($m[3]) ? $m[3] : '') . (isset($m[4]) ? $m[4] : '');
                $attrs[strtolower($m[1])] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
            }
        }
        return $attrs;
    }

    /**
     * Whether a link is a plain stylesheet that holds up the page.
     *
     * @param array  $attrs Attributes.
     * @param string $media Its media.
     * @return bool
     */
    public static function candidate(array $attrs, $media) {
        if (!isset($attrs['rel'], $attrs['href']) || 'stylesheet' !== strtolower(trim($attrs['rel']))) {
            return false;
        }
        if (isset($attrs['onload']) || isset($attrs['disabled'])) {
            return false;
        }
        // Media goes into a script attribute: plain media queries only; print never held up the page.
        return (bool) preg_match('/^[\w\s(),:.\/-]+$/', $media) && 'print' !== strtolower($media);
    }

    /**
     * The local file of a handled stylesheet.
     *
     * @param string $href Address.
     * @return string Empty when it is not one.
     */
    public static function file($href) {
        $path = self::path($href);
        if ('' === $path) {
            return '';
        }
        foreach (self::roots() as $url_path => $dir) {
            if (0 === strpos($path, $url_path)) {
                $file = $dir . substr($path, strlen($url_path));
                return self::handled($file) && is_file($file) ? $file : '';
            }
        }
        return '';
    }

    /**
     * Whether a stylesheet is on the list to load as it is.
     *
     * @param array  $attrs Attributes.
     * @param string $file  File.
     * @return bool
     */
    public static function kept(array $attrs, $file) {
        $id     = isset($attrs['id']) ? $attrs['id'] : '';
        $handle = (string) preg_replace('/-css$/', '', $id);
        foreach (SEOProStack_Used_Css::lines(SEOProStack_Used_Css::KEEP_KEY) as $line) {
            if ($line === $handle || $line === $id || false !== strpos($file, $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The URL path of a stylesheet on this site.
     *
     * @param string $href Address.
     * @return string Empty when it is elsewhere, or not a .css file.
     */
    private static function path($href) {
        $parts = wp_parse_url($href);
        if (!is_array($parts) || empty($parts['path'])) {
            return '';
        }
        if (!empty($parts['host']) && !self::local_host($parts['host'])) {
            return '';
        }
        $path = rawurldecode($parts['path']);
        return false === strpos($path, '..') && '.css' === strtolower(substr($path, -4)) ? $path : '';
    }

    /**
     * Whether a host is this site's.
     *
     * @param string $host Host.
     * @return bool
     */
    private static function local_host($host) {
        $host = strtolower($host);
        foreach (array(home_url(), site_url(), content_url()) as $url) {
            if (strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === $host) {
                return true;
            }
        }
        return false;
    }

    /**
     * URL paths and the folders they serve, longest first.
     *
     * @return array<string,string>
     */
    private static function roots() {
        $roots = array();
        $pairs = array(
            array(plugins_url(), WP_PLUGIN_DIR),
            array(get_template_directory_uri(), get_template_directory()),
            array(content_url(), WP_CONTENT_DIR),
            array(includes_url(), ABSPATH . WPINC),
        );
        foreach ($pairs as $pair) {
            $roots[trailingslashit((string) wp_parse_url($pair[0], PHP_URL_PATH))] = trailingslashit(wp_normalize_path($pair[1]));
        }
        uksort($roots, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        return $roots;
    }

    /**
     * Whether a file is in a folder whose stylesheets are handled.
     *
     * @param string $file File.
     * @return bool
     */
    private static function handled($file) {
        foreach (self::sources() as $source) {
            if (0 === strpos($file, $source)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Folders whose stylesheets are handled.
     *
     * @return string[]
     */
    private static function sources() {
        if (null === self::$sources) {
            $sources = array(wp_normalize_path(ABSPATH . WPINC) . '/css/dist/block-library/');
            foreach (self::PLUGINS as $slug) {
                $sources[] = wp_normalize_path(WP_PLUGIN_DIR) . '/' . $slug . '/';
            }
            if (SEOProStack_Used_Css::kadence_theme()) {
                $sources[] = trailingslashit(wp_normalize_path(get_template_directory()));
            }
            /**
             * Folders whose stylesheets Used CSS handles. Only the default
             * ones are tested and supported.
             *
             * @param string[] $sources Folders, with a trailing slash.
             */
            $sources       = apply_filters('seoprostack_used_css_sources', $sources);
            self::$sources = array_values(array_filter(array_map('strval', (array) $sources)));
        }
        return self::$sources;
    }
}
