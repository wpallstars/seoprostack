<?php
/**
 * Used CSS: each page gets the CSS rules it uses, in the page, and the full
 * stylesheets load without holding up its first paint.
 *
 * Only for the Kadence theme (and a child theme of it), the plugins SEO Pro
 * Stack recommends, their Pro add-ons, Kadence's own plugins, the plugins
 * it works with (SEOProStack_Used_Css_Links::PLUGINS) and WordPress's block
 * library: the stylesheets SEO Pro Stack recommends and tests, so it never
 * has to support every other theme and plugin (owner's decisions, GitHub
 * issues #1194 and #1260). Everything works on the site's own server, with
 * no cloud service or licence, unlike FlyingPress 5's and LiteSpeed Cache's
 * unused-CSS services.
 *
 * For visitors who are not logged in, on normal GET pages, each such
 * `<link rel="stylesheet">` becomes (SEOProStack_Used_Css_Page):
 * - a `<style>` with the rules this page can use, in the link's place, so
 *   the cascade is as before (SEOProStack_Used_Css_Sheet reads each
 *   stylesheet once per version);
 * - the link itself, loaded without blocking (media="print", switched back
 *   once loaded), so every rule applies again soon after, as before; with
 *   LATER_KEY (on by default) only once the page has loaded or the visitor
 *   first interacts (SEOProStack_Used_Css_Page);
 * - a `<noscript>` copy for browsers without JavaScript.
 * A rule is used when the page has every class, ID and element one of its
 * selectors names; classes and elements the page's scripts may add after
 * it loads count as present (SEOProStack_Used_Css_Match,
 * SEOProStack_Used_Css_Scripts). Which links are handled:
 * SEOProStack_Used_Css_Links. The Kadence theme's local fonts stylesheet
 * (wp-content/fonts/, handle kadence-fonts-*) is handled too, and Kadence
 * Pro's dark mode script (kadence-dark-mode, up to 8 KB) is printed in the
 * page instead of as a request, still synchronous (GitHub issue #1218).
 * WP Grid Builder's Google Fonts come from this site, copied by the Kadence
 * theme's own loader, so they are handled the same way (GitHub issue #1434).
 *
 * Steps aside where LiteSpeed Cache combines CSS or makes its own unused or
 * critical CSS, and where a content security policy blocks its inline
 * `<style>` or onload (SEOProStack_Used_Css_Policy); other plugins that
 * rewrite stylesheet addresses (minify) leave nothing for it to match.
 * Turning it on or off, or changing its lists, empties LiteSpeed Cache's or
 * WP-Optimize's page cache (SEOProStack_Page_Cache), and Keep pages cached
 * fills it again.
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

require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-selector.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-parser.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-sheet.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-links.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-scripts.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-match.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-policy.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-page.php';

class SEOProStack_Used_Css extends SEOProStack_Feature {

    const KEY         = 'used_css';
    const EXCLUDE_KEY = 'used_css_exclude';
    const KEEP_KEY    = 'used_css_keep';
    const LATER_KEY   = 'used_css_later';

    /** Google Fonts stylesheet addresses: the css and css2 APIs. */
    const GOOGLE_FONTS = '#^https?://fonts\.googleapis\.com/css2?\?#i';

    /** The Kadence theme's and Kadence Blocks' Google Fonts loaders (WPTT's). */
    const FONT_LOADERS = array('Kadence\get_webfont_url', 'KadenceWP\KadenceBlocks\get_webfont_url');

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY         => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'speed',
                'label'       => __('Load the CSS each page uses first', 'seoprostack'),
                'description' => __('For the Kadence theme, the plugins SEO Pro Stack recommends (Kadence Blocks, Fluent Forms, Tutor LMS and others) and WordPress’s block styles: each page gets the CSS rules it uses, in the page, and their full stylesheets load without holding up its first paint, so pages show sooner and speed scores rise. Worked out on this server, once for each version of a stylesheet, with no cloud service. Turning it on or off empties LiteSpeed Cache’s or WP-Optimize’s page cache; empty any other page cache yourself. The Kadence theme’s local Google Fonts stylesheet loads without blocking, WP Grid Builder’s Google Fonts come from this site (copied by the Kadence theme), and Kadence Pro’s dark mode script goes into the page. Check pages with menus, tabs and forms after turning it on.', 'seoprostack'),
            ),
            self::LATER_KEY   => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'label'       => __('Load the full stylesheets once the page has loaded', 'seoprostack'),
                'description' => __('If part of a page looks wrong after the visitor first moves, taps or scrolls, switch this off: the full stylesheets then load straight away, as the page does. While on, they wait until the page has loaded or the visitor first moves, taps, scrolls or types, so on a slow phone the pictures and fonts the first screen needs arrive sooner. Scripts that Delay scripts holds wait for them.', 'seoprostack'),
            ),
            self::EXCLUDE_KEY => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'label'       => __('Leave these pages as they are', 'seoprostack'),
                'description' => __('One per line: part of the page address, such as /checkout.', 'seoprostack'),
            ),
            self::KEEP_KEY    => array(
                'type'        => 'lines',
                'default'     => '',
                'parent'      => self::KEY,
                'group'       => 'troubleshooting',
                'label'       => __('Load these stylesheets as they are', 'seoprostack'),
                'description' => __('If part of a page looks wrong for a moment while it loads, add its stylesheet: its handle, such as kadence-header, or part of its address, such as header.min.css or fluentformpro.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        add_action('seoprostack_settings_changed', array(__CLASS__, 'settings_changed'), 10, 2);
        if (!self::enabled() || is_admin()) {
            return;
        }
        add_action('template_redirect', array(__CLASS__, 'start'), PHP_INT_MAX);
        // After WP Grid Builder's own entries (priority 0 and 10).
        add_filter('wp_grid_builder/frontend/register_styles', array(__CLASS__, 'wpgb_fonts'), 20);
    }

    /**
     * WP Grid Builder's Google Fonts stylesheet (its cards' and facets'
     * fonts), copied to this site by the Kadence theme's own loader, as the
     * theme copies its own Google Fonts: a request to Google on the first
     * paint's path, which also sends each visitor's address there, becomes
     * a stylesheet in wp-content/fonts/ that SEOProStack_Used_Css_Links
     * handles (GitHub issue #1434). Google's address stays when no Kadence
     * loader is there or the copy fails.
     *
     * @param mixed $styles Stylesheets WP Grid Builder registers: handle, source and version each.
     * @return mixed
     */
    public static function wpgb_fonts($styles) {
        if (!is_array($styles)) {
            return $styles;
        }
        foreach ($styles as $i => $style) {
            if (!is_array($style) || !isset($style['handle'], $style['source']) || 'wpgb-fonts' !== $style['handle']
                || !is_string($style['source']) || !preg_match(self::GOOGLE_FONTS, $style['source'])) {
                continue;
            }
            $local = self::local_fonts($style['source']);
            if ('' !== $local) {
                $styles[$i]['source'] = $local;
            }
        }
        return $styles;
    }

    /**
     * The local copy of a Google Fonts stylesheet, from the first Kadence
     * loader there (it downloads the stylesheet and its font files once).
     *
     * @param string $url Google Fonts stylesheet address.
     * @return string Its address on this site, or empty.
     */
    private static function local_fonts($url) {
        foreach (self::FONT_LOADERS as $loader) {
            if (function_exists($loader)) {
                $local = call_user_func($loader, $url);
                return is_string($local) && $local !== $url && SEOProStack_Used_Css_Links::on_site($local) ? $local : '';
            }
        }
        return '';
    }

    /**
     * Plugin deactivated: remove this site's cache files.
     *
     * @param bool $network_wide Deactivated for the whole network.
     */
    public static function deactivate($network_wide = false) {
        unset($network_wide);
        SEOProStack_Used_Css_Sheet::remove();
    }

    /**
     * Settings changed: when this feature's did, cached pages were made the
     * other way, so empty the page cache; when it was turned off, remove
     * the cache files.
     *
     * @param array $old Settings before.
     * @param array $new Settings after.
     */
    public static function settings_changed($old, $new) {
        $changed = false;
        foreach (array(self::KEY, self::EXCLUDE_KEY, self::KEEP_KEY, self::LATER_KEY) as $key) {
            $before  = isset($old[$key]) ? $old[$key] : null;
            $after   = isset($new[$key]) ? $new[$key] : null;
            $changed = $changed || $before != $after; // phpcs:ignore Universal.Operators.StrictComparisons -- stored as text or true/false.
        }
        if (!$changed) {
            return;
        }
        SEOProStack_Page_Cache::purge_all();
        if (empty($new[self::KEY])) {
            SEOProStack_Used_Css_Sheet::remove();
        }
    }

    /**
     * Recommended speed settings' entry (SEOProStack_Speed_Plan): recommended
     * where the Kadence theme or one of the plugins whose stylesheets it
     * handles is active.
     *
     * @return array{state:string,why:string,cost:string}
     */
    public static function plan() {
        // Active plugins as stored: one skipped on this screen still counts.
        if (!self::kadence_theme() && !array_intersect_key(self::active_plugins(), array_flip(SEOProStack_Used_Css_Links::PLUGINS))) {
            return array('state' => 'skip', 'why' => __('For the Kadence theme and the plugins SEO Pro Stack recommends, none of which is active.', 'seoprostack'), 'cost' => '');
        }
        return array(
            'state' => 'recommended',
            'why'   => __('The Kadence theme or plugins SEO Pro Stack recommends are active: each page waits for all their stylesheets before it shows anything.', 'seoprostack'),
            'cost'  => __('Pages not in the page cache take a few milliseconds more to build. Check pages with menus, tabs and forms after turning it on.', 'seoprostack'),
        );
    }

    /**
     * Whether the parent theme is Kadence.
     *
     * @return bool
     */
    public static function kadence_theme() {
        return 'kadence' === get_template() || 'Kadence' === wp_get_theme(get_template())->get('Name');
    }

    /**
     * A list setting's lines.
     *
     * @param string $key Setting key.
     * @return string[]
     */
    public static function lines($key) {
        $lines = preg_split('/\R/', (string) SEOProStack_Settings::get($key), -1, PREG_SPLIT_NO_EMPTY);
        $lines = array_map('trim', is_array($lines) ? $lines : array());
        return array_values(array_filter($lines, function ($line) {
            return '' !== $line;
        }));
    }

    /**
     * Buffer the page for visitors on normal front-end views.
     */
    public static function start() {
        if (!self::normal_view() || self::special_request() || self::litespeed_css() || self::excluded_page()) {
            return;
        }
        ob_start(array('SEOProStack_Used_Css_Page', 'rewrite'));
    }

    /**
     * Whether this is a page a visitor who is not logged in sees as is.
     *
     * @return bool
     */
    private static function normal_view() {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        if ('GET' !== $method || is_user_logged_in() || is_preview() || is_customize_preview()) {
            return false;
        }
        return !is_feed() && !is_embed() && !is_robots() && !is_trackback();
    }

    /**
     * Whether this is an AJAX, REST, JSON or AMP request.
     *
     * @return bool
     */
    private static function special_request() {
        if (wp_doing_ajax() || wp_is_json_request() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return true;
        }
        return function_exists('amp_is_request') && amp_is_request();
    }

    /**
     * Whether LiteSpeed Cache combines CSS or makes unused or critical CSS:
     * it would then rework these stylesheets too.
     *
     * @return bool
     */
    private static function litespeed_css() {
        if (!defined('LSCWP_V')) {
            return false;
        }
        foreach (array('optm-css_comb', 'optm-ucss', 'optm-css_async') as $name) {
            $value = get_option('litespeed.conf.' . $name, null);
            if (null === $value && is_multisite()) {
                $value = get_site_option('litespeed.conf.' . $name);
            }
            if ($value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether this page is on the list to leave alone.
     *
     * @return bool
     */
    private static function excluded_page() {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
        foreach (self::lines(self::EXCLUDE_KEY) as $page) {
            if (false !== stripos((string) $uri, $page)) {
                return true;
            }
        }
        return false;
    }
}
