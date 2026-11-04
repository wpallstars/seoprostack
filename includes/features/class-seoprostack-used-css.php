<?php
/**
 * Used CSS: each page gets the CSS rules it uses, in the page, and the full
 * stylesheets load without holding up its first paint.
 *
 * Only for the Kadence theme, Kadence Blocks (Pro), Kadence Pro, Fluent
 * Forms (Pro) and WordPress's block library: the stylesheets SEO Pro Stack
 * recommends and tests, so it never has to support every other theme and
 * plugin (owner's decision, GitHub issue #1194). Everything works on the
 * site's own server, with no cloud service or licence, unlike FlyingPress
 * 5's and LiteSpeed Cache's unused-CSS services.
 *
 * For visitors who are not logged in, on normal GET pages, each such
 * `<link rel="stylesheet">` becomes (SEOProStack_Used_Css_Page):
 * - a `<style>` with the rules this page can use, in the link's place, so
 *   the cascade is as before (SEOProStack_Used_Css_Sheet reads each
 *   stylesheet once per version);
 * - the link itself, loaded without blocking (media="print", switched back
 *   once loaded), so every rule applies again soon after, as before;
 * - a `<noscript>` copy for browsers without JavaScript.
 * A rule is used when the page has every class, ID and element one of its
 * selectors names; classes the supported scripts add after the page loads
 * count as present (SEOProStack_Used_Css_Match). Which links are handled:
 * SEOProStack_Used_Css_Links.
 *
 * Steps aside where LiteSpeed Cache combines CSS or makes its own unused or
 * critical CSS; other plugins that rewrite stylesheet addresses (minify)
 * leave nothing for it to match. Turning it on or off, or changing its
 * lists, empties the page cache (SEOProStack_Page_Cache), and Keep pages
 * cached fills it again.
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
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-match.php';
require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-page.php';

class SEOProStack_Used_Css extends SEOProStack_Feature {

    const KEY         = 'used_css';
    const EXCLUDE_KEY = 'used_css_exclude';
    const KEEP_KEY    = 'used_css_keep';

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
                'description' => __('For the Kadence theme, Kadence Blocks, Fluent Forms and WordPress’s block styles: each page gets the CSS rules it uses, in the page, and their full stylesheets load without holding up its first paint, so pages show sooner and speed scores rise. Worked out on this server, once for each version of a stylesheet, with no cloud service. Turning it on or off empties the page cache. Check pages with menus, tabs and forms after turning it on.', 'seoprostack'),
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
        foreach (array(self::KEY, self::EXCLUDE_KEY, self::KEEP_KEY) as $key) {
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
     * where the Kadence theme, Kadence Blocks or Fluent Forms is active.
     *
     * @return array{state:string,why:string,cost:string}
     */
    public static function plan() {
        if (!self::kadence_theme() && !defined('KADENCE_BLOCKS_VERSION') && !defined('FLUENTFORM')) {
            return array('state' => 'skip', 'why' => __('For the Kadence theme, Kadence Blocks or Fluent Forms, none of which is active.', 'seoprostack'), 'cost' => '');
        }
        return array(
            'state' => 'recommended',
            'why'   => __('The Kadence theme, Kadence Blocks or Fluent Forms is active: each page waits for all their stylesheets before it shows anything.', 'seoprostack'),
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
