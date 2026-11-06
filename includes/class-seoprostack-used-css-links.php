<?php
/**
 * Used CSS: which stylesheet links are handled, and their local files.
 *
 * A plain `<link rel="stylesheet">` that holds up the page (no onload, not
 * disabled, plain media other than print), served from this site, from the
 * Kadence theme (and a child theme of it), one of the plugins in PLUGINS
 * or WordPress's block library (filter seoprostack_used_css_sources), and
 * not on the list to load as it is. Also maps the page's scripts to their
 * files (SEOProStack_Used_Css_Scripts).
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

    /**
     * Plugin folders whose stylesheets are handled, with the Kadence theme
     * and the block library: the plugins SEO Pro Stack recommends that put
     * stylesheets on the front end (admin/data/free-plugins.php and
     * pro-plugins.php), their Pro add-ons, Kadence's own plugins, and the
     * plugins SEO Pro Stack works with (Readabler, IndieWeb, WP Grid
     * Builder). Owner's decision, GitHub issue #1260, replacing the shorter
     * list of issue #1194. Not for other themes and plugins: support would
     * have no end (filter seoprostack_used_css_sources, unsupported).
     */
    const PLUGINS = array(
        // Kadence.
        'kadence-blocks', 'kadence-blocks-pro', 'kadence-pro', 'kadence-conversions', 'kadence-woo-extras',
        'kadence-shop-kit', 'kadence-galleries', 'kadence-reading-time', 'kadence-related-content', 'kadence-creative-kit',
        // Fluent and WPManageNinja.
        'fluentform', 'fluentformpro', 'fluentform-signature', 'fluentforms-pdf', 'fluent-booking', 'fluent-booking-pro',
        'fluent-cart', 'fluent-cart-pro', 'fluent-community', 'fluent-community-pro', 'fluent-support', 'fluent-support-pro',
        'fluent-crm', 'fluentcampaign-pro', 'fluent-boards', 'fluent-boards-pro', 'fluent-affiliate', 'fluent-player',
        'wp-payment-form', 'ninja-tables', 'ninja-tables-pro', 'ninja-charts', 'ninja-job-board',
        'wp-social-reviews', 'wp-social-ninja-pro',
        // Courses, AI, consent, languages, shops.
        'tutor', 'tutor-pro', 'ai-engine', 'ai-engine-pro', 'complianz-gdpr', 'complianz-gdpr-premium',
        'complianz-terms-conditions', 'translatepress-multilingual', 'translatepress-business', 'woocommerce',
        'woo-stripe-payment', 'pymntpl-paypal-woocommerce', 'slicewp',
        // Content, events, reviews and the IndieWeb.
        'seo-by-rank-math', 'seo-by-rank-math-pro', 'code-block-pro', 'ics-calendar', 'eventon-lite',
        'easy-video-reviews', 'custom-feed-for-tiktok', 'simple-cloudflare-turnstile', 'syndication-links', 'webmention',
        'indieweb', 'readabler', 'wp-grid-builder',
    );

    /** Folders whose stylesheets are handled, or null before they are worked out. */
    private static $sources = null;

    /**
     * URL paths and the folders they serve, or null before they are worked out.
     *
     * @var array<string,string>|null
     */
    private static $roots = null;

    /**
     * A tag's attributes: lower-case name => value (entities decoded).
     *
     * @param string $tag Tag.
     * @return array<string,string>
     */
    public static function attributes($tag) {
        $attrs = array();
        // Name, then the value: quoted (2 is the quote, 3 the value) or not (4).
        if (preg_match_all('/([^\s=\/>]+)(?:\s*=\s*(?:(["\'])(.*?)\2|([^\s>]+)))?/s', (string) preg_replace('/^<[^\s>\/]*/', '', $tag), $found, PREG_SET_ORDER)) {
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
        // Media goes into a script string: plain media queries with plain
        // spaces only (a line break would end the string); print never held up the page.
        return (bool) preg_match('/^[\w (),:.\/-]+$/', $media) && 'print' !== strtolower($media);
    }

    /**
     * The local file of a handled stylesheet.
     *
     * @param string $href  Address.
     * @param array  $attrs The link's attributes, to tell the Kadence theme's local fonts stylesheet.
     * @return string Empty when it is not one.
     */
    public static function file($href, array $attrs = array()) {
        $path = self::path($href);
        if ('' === $path) {
            return '';
        }
        foreach (self::roots() as $url_path => $dir) {
            if (0 === strpos($path, $url_path)) {
                $file = $dir . substr($path, strlen($url_path));
                return (self::handled($file) || self::kadence_fonts($attrs, $file)) && is_file($file) ? $file : '';
            }
        }
        return '';
    }

    /**
     * The local file of a script on this site, in any folder.
     *
     * @param string $src Address.
     * @return string Empty when it is elsewhere or not a .js file.
     */
    public static function script_file($src) {
        $path = self::path($src, '.js');
        if ('' === $path) {
            return '';
        }
        foreach (self::roots() as $url_path => $dir) {
            if (0 === strpos($path, $url_path)) {
                $file = $dir . substr($path, strlen($url_path));
                return is_file($file) ? $file : '';
            }
        }
        return '';
    }

    /**
     * The contents of Kadence Pro's dark mode script, for the page: it sets
     * the colour scheme before the first paint, so it stays in the head and
     * synchronous, but can be in the page instead of a request.
     *
     * @param string $tag A whole `<script>` element.
     * @return string Empty when it is not that script, or is not small and plain.
     */
    public static function dark_mode_script($tag) {
        if (!preg_match('/^<script\b[^>]*>/i', $tag, $open) || '' !== trim(substr($tag, strlen($open[0]), -strlen('</script>')))) {
            return '';
        }
        $attrs = self::attributes($open[0]);
        if (!isset($attrs['src'], $attrs['id']) || 'kadence-dark-mode-js' !== $attrs['id'] || array_diff(array_keys($attrs), array('src', 'id'))) {
            return '';
        }
        $path = self::path($attrs['src'], '.js');
        $base = trailingslashit((string) wp_parse_url(plugins_url('kadence-pro'), PHP_URL_PATH));
        if ('' === $path || 0 !== strpos($path, $base)) {
            return '';
        }
        $dir  = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR)) . 'kadence-pro/';
        $file = $dir . substr($path, strlen($base));
        if (!is_file($file) || !is_readable($file) || filesize($file) > 8192) {
            return '';
        }
        $code = file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
        return is_string($code) && false === stripos($code, '</script') ? $code : '';
    }

    /**
     * Whether a file is the Kadence theme's local Google Fonts stylesheet: a
     * `kadence-fonts-*` handle, a .css file straight in wp-content/fonts/,
     * while Kadence is the parent theme. Other loaders share that folder, so
     * the folder alone is not a source.
     *
     * @param array  $attrs Attributes.
     * @param string $file  File.
     * @return bool
     */
    private static function kadence_fonts(array $attrs, $file) {
        $id = isset($attrs['id']) ? $attrs['id'] : '';
        if (!SEOProStack_Used_Css::kadence_theme() || 0 !== strpos($id, 'kadence-fonts-') || '-css' !== substr($id, -4)) {
            return false;
        }
        $dir = trailingslashit(wp_normalize_path(WP_CONTENT_DIR)) . 'fonts/';
        return 0 === strpos($file, $dir) && false === strpos(substr($file, strlen($dir)), '/');
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
     * @param string $ext  File extension, with the dot.
     * @return string Empty when it is elsewhere, or not such a file.
     */
    private static function path($href, $ext = '.css') {
        $parts = wp_parse_url($href);
        if (!is_array($parts) || empty($parts['path'])) {
            return '';
        }
        if (!empty($parts['host']) && !self::local_host($parts)) {
            return '';
        }
        $path = rawurldecode($parts['path']);
        return false === strpos($path, '..') && strtolower($ext) === strtolower(substr($path, -strlen($ext))) ? $path : '';
    }

    /**
     * Whether an address's host and port are this site's.
     *
     * @param array $parts Address parts (wp_parse_url()), with a host.
     * @return bool
     */
    private static function local_host(array $parts) {
        $host = strtolower($parts['host']);
        $port = self::port($parts);
        foreach (array(home_url(), site_url(), content_url()) as $url) {
            $site = wp_parse_url($url);
            if (!is_array($site) || empty($site['host']) || strtolower($site['host']) !== $host) {
                continue;
            }
            // An address without a scheme (//host/...) uses the page's, so its port is the site's.
            if (null === $port || self::port($site) === $port) {
                return true;
            }
        }
        return false;
    }

    /**
     * An address's port: its own, or its scheme's.
     *
     * @param array $parts Address parts (wp_parse_url()).
     * @return int|null Null when it has neither.
     */
    private static function port(array $parts) {
        if (!empty($parts['port'])) {
            return (int) $parts['port'];
        }
        $ports  = array('http' => 80, 'https' => 443);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        return isset($ports[$scheme]) ? $ports[$scheme] : null;
    }

    /**
     * URL paths and the folders they serve, longest first.
     *
     * @return array<string,string>
     */
    private static function roots() {
        if (null !== self::$roots) {
            return self::$roots;
        }
        $roots = array();
        $pairs = array(
            array(plugins_url(), WP_PLUGIN_DIR),
            array(get_template_directory_uri(), get_template_directory()),
            array(get_stylesheet_directory_uri(), get_stylesheet_directory()),
            array(content_url(), WP_CONTENT_DIR),
            array(includes_url(), ABSPATH . WPINC),
        );
        foreach ($pairs as $pair) {
            $roots[trailingslashit((string) wp_parse_url($pair[0], PHP_URL_PATH))] = trailingslashit(wp_normalize_path($pair[1]));
        }
        uksort($roots, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        self::$roots = $roots;
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
                // The Kadence theme, and a child theme of it.
                $sources[] = trailingslashit(wp_normalize_path(get_template_directory()));
                $sources[] = trailingslashit(wp_normalize_path(get_stylesheet_directory()));
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
