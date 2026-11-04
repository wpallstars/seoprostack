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
 * `<link rel="stylesheet">` becomes:
 * - a `<style>` with the rules this page can use, in the link's place, so
 *   the cascade is as before (SEOProStack_Used_Css_Sheet reads each
 *   stylesheet once per version);
 * - the link itself, loaded without blocking (media="print", switched back
 *   once loaded), so every rule applies again soon after, as before;
 * - a `<noscript>` copy for browsers without JavaScript.
 * A rule is used when the page has every class, ID and element one of its
 * selectors names. Classes that Kadence's, Kadence Blocks', Fluent Forms'
 * and their libraries' scripts add after the page loads (menus, drawers,
 * the sticky header, tabs, accordions, dark mode, form errors, the phone
 * field's flags, dropdowns and date pickers) count as present, so those
 * parts look right before the full stylesheets arrive. The
 * `seoprostack_used_css_classes` filter adds more ("name" or "prefix*"),
 * and `seoprostack_used_css_sources` other folders.
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

require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-sheet.php';

class SEOProStack_Used_Css extends SEOProStack_Feature {

    const KEY         = 'used_css';
    const EXCLUDE_KEY = 'used_css_exclude';
    const KEEP_KEY    = 'used_css_keep';

    /** Plugin folders whose stylesheets are handled, with the Kadence theme and the block library. */
    const PLUGINS = array('kadence-blocks', 'kadence-blocks-pro', 'kadence-pro', 'fluentform', 'fluentformpro');

    /**
     * Classes the supported scripts add after the page loads, so rules for
     * them are kept; a trailing `*` is a prefix. Found in Kadence's
     * navigation.js, Kadence Blocks' tabs, accordion, navigation, header and
     * off-canvas scripts, Kadence Pro's dark mode, Fluent Forms'
     * form-submission.js and its intl-tel-input, Choices and flatpickr.
     */
    const CLASSES = array(
        // States many scripts use.
        'active', 'open', 'opened', 'show', 'triggered', 'initialized', 'is-*',
        // Kadence: menus, drawers, sticky header and sidebar, dark mode.
        'current-menu-item', 'menu-item--toggled-on', 'menu-item--has-toggle', 'toggle-show', 'sub-menu-*',
        'dropdown-nav-special-toggle', 'kadence-menu-mega-enabled', 'show-drawer', 'pop-animated',
        'showing-popup-drawer-*', 'kadence-scrollbar-fixer', 'kadence-drawer-opened', 'kadence-drawer-closed',
        'hide-focus-outline', 'scroll-visible', 'item-hidden-above', 'item-is-fixed', 'item-at-start',
        'item-is-stuck', 'child-is-fixed', 'header-is-fixed', 'has-sticky-sidebar', 'has-sticky-sidebar-widget',
        'color-switch-dark', 'color-switch-light',
        // Kadence Blocks: tabs, accordions, navigation, header, off-canvas, show more, sliders, lightbox.
        'kt-active-tab-*', 'kt-tab-title-active', 'kt-tab-title-inactive', 'kt-tabs-accordion-title*', 'kt-create-accordion',
        'kt-accordion-initialized', 'kt-accordion-panel-active', 'kt-accordion-panel-hidden',
        'kt-panel-is-collapsing', 'kt-panel-is-expanding', 'kb-nav-esc-close', 'kb-modal-open', 'kb-scrollbar-fixer',
        'kb-header-sticky-wrapper', 'kb-header-placeholder-wrapper', 'show-off-canvas', 'kb-smc-open',
        'wp-block-kadence-show-more', 'splide*', 'glightbox*', 'gslide*', 'tippy*',
        // Fluent Forms: errors, messages, submitting, steps, and its phone, select and date libraries.
        'error', 'text-danger', 'error-text', 'error-clear', 'ff-el-is-error', 'ff-message-success', 'ff-hidden',
        'ff_force_hide', 'ff_submitting', 'ff-working', 'ff-form-loading', 'ff-form-loaded', 'ff_item_selected',
        'ff-el-pop-content', 'ff_excluded', 'ff_tokenizing', 'ff_tokenized', 'ff-form-inline', 'ff_numeric',
        'ff_el_with_extended_validation', 'ff_other_error_*', 'iti*', 'choices*', 'flatpickr*',
        // WordPress: the navigation block's menu and the image lightbox.
        'has-modal-open', 'wp-lightbox-overlay', 'zoom', 'show-closing-animation',
    );

    /** The page's classes (`.name`), IDs (`#name`) and elements: token => present. */
    private static $present = array();

    /** The page being rewritten, read for its tokens when a stylesheet needs them. */
    private static $html = '';

    /** Prefixes of classes counted as present (regex), or ''. */
    private static $prefixes = '';

    /** Folders whose stylesheets are handled, or null before they are worked out. */
    private static $sources = null;

    /** Stylesheets changed on this page, and their sizes. */
    private static $stats = array('sheets' => 0, 'used' => 0, 'full' => 0);

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
     * Whether the Kadence theme, Kadence Blocks or Fluent Forms is active
     * (for the speed plan).
     *
     * @return bool
     */
    public static function applies() {
        return self::kadence_theme() || defined('KADENCE_BLOCKS_VERSION') || defined('FLUENTFORM');
    }

    /**
     * Buffer the page for visitors on normal front-end views.
     */
    public static function start() {
        if (!self::normal_view() || self::special_request() || self::litespeed_css() || self::excluded_page()) {
            return;
        }
        ob_start(array(__CLASS__, 'rewrite'));
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

    /**
     * A list setting's lines.
     *
     * @param string $key Setting key.
     * @return string[]
     */
    private static function lines($key) {
        $lines = preg_split('/\R/', (string) SEOProStack_Settings::get($key), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter(array_map('trim', is_array($lines) ? $lines : array()), 'strlen'));
    }

    /**
     * Put each handled stylesheet's used rules in the page and load it
     * without blocking.
     *
     * @param string $html Page.
     * @return string
     */
    public static function rewrite($html) {
        if (!is_string($html) || false === stripos($html, '<link') || !self::is_html($html)) {
            return $html;
        }
        self::$html    = $html;
        self::$present = array();
        self::$stats   = array('sheets' => 0, 'used' => 0, 'full' => 0);
        // Comments, scripts and the like are passed over: links there are not the page's.
        $out        = preg_replace_callback('#<!--.*?-->|<(script|noscript|template|textarea)\b[^>]*>.*?</\1\s*>|<link\b[^>]*>#is', array(__CLASS__, 'tag'), $html);
        self::$html = '';
        if (!is_string($out) || !self::$stats['sheets']) {
            return $html; // Unchanged, or the regex hit a PCRE limit.
        }
        return $out . sprintf(
            "\n<!-- SEO Pro Stack Used CSS: %d stylesheets, %s of %s in the page -->",
            self::$stats['sheets'],
            size_format(self::$stats['used'], 1),
            size_format(self::$stats['full'], 1)
        );
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
     * One tag the page rewrite came to.
     *
     * @param array $m Match.
     * @return string
     */
    public static function tag(array $m) {
        if (0 !== stripos($m[0], '<link')) {
            return $m[0];
        }
        try {
            return self::link($m[0]);
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
    private static function link($tag) {
        $attrs = self::attributes($tag);
        $media = isset($attrs['media']) ? trim($attrs['media']) : 'all';
        if (!self::candidate($attrs, $media)) {
            return $tag;
        }
        $file = self::file($attrs['href']);
        if ('' === $file || self::kept($attrs, $file)) {
            return $tag;
        }
        $rules = SEOProStack_Used_Css_Sheet::rules($file, $attrs['href']);
        if (null === $rules) {
            return $tag;
        }
        if (!self::$present) {
            self::$present  = self::tokens(self::$html);
            self::$prefixes = self::prefixes();
        }
        $css = self::used($rules);
        self::$stats['sheets']++;
        self::$stats['used'] += strlen($css);
        self::$stats['full'] += (int) filesize($file);
        return self::replacement($tag, $attrs, $media, $css);
    }

    /**
     * Whether a link is a plain stylesheet that holds up the page.
     *
     * @param array  $attrs Attributes.
     * @param string $media Its media.
     * @return bool
     */
    private static function candidate(array $attrs, $media) {
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
     * A tag's attributes: lower-case name => value (entities decoded).
     *
     * @param string $tag Tag.
     * @return array<string,string>
     */
    private static function attributes($tag) {
        $attrs = array();
        if (preg_match_all('/([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', substr($tag, 5), $found, PREG_SET_ORDER)) {
            foreach ($found as $m) {
                $value                       = (isset($m[2]) ? $m[2] : '') . (isset($m[3]) ? $m[3] : '') . (isset($m[4]) ? $m[4] : '');
                $attrs[strtolower($m[1])] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
            }
        }
        return $attrs;
    }

    /**
     * The local file of a handled stylesheet.
     *
     * @param string $href Address.
     * @return string Empty when it is not one.
     */
    private static function file($href) {
        $parts = wp_parse_url($href);
        if (!is_array($parts) || empty($parts['path']) || (!empty($parts['host']) && !self::local_host($parts['host']))) {
            return '';
        }
        $path = rawurldecode($parts['path']);
        if (false !== strpos($path, '..') || '.css' !== strtolower(substr($path, -4))) {
            return '';
        }
        foreach (self::roots() as $url_path => $dir) {
            if (0 !== strpos($path, $url_path)) {
                continue;
            }
            $file = $dir . substr($path, strlen($url_path));
            foreach (self::sources() as $source) {
                if (0 === strpos($file, $source)) {
                    return is_file($file) ? $file : '';
                }
            }
            return '';
        }
        return '';
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
            if (self::kadence_theme()) {
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

    /**
     * Whether the parent theme is Kadence.
     *
     * @return bool
     */
    private static function kadence_theme() {
        return 'kadence' === get_template() || 'Kadence' === wp_get_theme(get_template())->get('Name');
    }

    /**
     * Whether a stylesheet is on the list to load as it is.
     *
     * @param array  $attrs Attributes.
     * @param string $file  File.
     * @return bool
     */
    private static function kept(array $attrs, $file) {
        $id     = isset($attrs['id']) ? $attrs['id'] : '';
        $handle = (string) preg_replace('/-css$/', '', $id);
        foreach (self::lines(self::KEEP_KEY) as $line) {
            if ($line === $handle || $line === $id || false !== strpos($file, $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The page's classes, IDs and elements, with the classes scripts add.
     *
     * @param string $html Page.
     * @return array<string,bool>
     */
    private static function tokens($html) {
        $present = SEOProStack_Used_Css_Sheet::ALWAYS;
        if (preg_match_all('/<([a-zA-Z][a-zA-Z0-9-]*)/', $html, $tags)) {
            foreach (array_unique($tags[1]) as $tag) {
                $present[strtolower($tag)] = true;
            }
        }
        if (preg_match_all('/\s(class|id)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $found, PREG_SET_ORDER)) {
            foreach ($found as $m) {
                $value = $m[2] . (isset($m[3]) ? $m[3] : '') . (isset($m[4]) ? $m[4] : '');
                $value = false !== strpos($value, '&') ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
                $mark  = 'id' === strtolower($m[1]) ? '#' : '.';
                foreach (preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: array() as $name) {
                    $present[$mark . $name] = true;
                }
            }
        }
        foreach (self::classes() as $class) {
            if ('*' !== substr($class, -1)) {
                $present['.' . $class] = true;
            }
        }
        return $present;
    }

    /**
     * Classes counted as present: "name", or "prefix*".
     *
     * @return string[]
     */
    private static function classes() {
        /**
         * Classes that scripts add after the page loads, so Used CSS keeps
         * their rules: "name", or "prefix*" for every class starting so.
         *
         * @param string[] $classes Classes.
         */
        return array_values(array_filter(array_map('strval', (array) apply_filters('seoprostack_used_css_classes', self::CLASSES))));
    }

    /**
     * A regex matching the class prefixes, or ''.
     *
     * @return string
     */
    private static function prefixes() {
        $prefixes = array();
        foreach (self::classes() as $class) {
            if ('*' === substr($class, -1) && '*' !== $class) {
                $prefixes[] = preg_quote(substr($class, 0, -1), '/');
            }
        }
        return $prefixes ? '/^(?:' . implode('|', $prefixes) . ')/' : '';
    }

    /**
     * The rules this page can use.
     *
     * @param array $rules Rules (SEOProStack_Used_Css_Sheet::rules()).
     * @return string
     */
    private static function used(array $rules) {
        $out = '';
        foreach ($rules as $node) {
            if (isset($node['k'])) {
                $out .= $node['k'];
            } elseif (isset($node['g'])) {
                $inner = self::used((array) $node['c']);
                // An empty @layer block still sets the order of layers.
                if ('' !== $inner || 0 === stripos($node['g'], '@layer')) {
                    $out .= $node['g'] . '{' . $inner . '}';
                }
            } elseif (isset($node['r']) && self::matches((array) $node['n'])) {
                $out .= $node['r'];
            }
        }
        return $out;
    }

    /**
     * Whether the page has all that one of a rule's selectors needs.
     *
     * @param array $selectors What each selector needs.
     * @return bool
     */
    private static function matches(array $selectors) {
        foreach ($selectors as $needs) {
            foreach ((array) $needs as $token) {
                if (!self::has((string) $token)) {
                    continue 2;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Whether the page has a class, ID or element.
     *
     * @param string $token `.class`, `#id` or element.
     * @return bool
     */
    private static function has($token) {
        if (!isset(self::$present[$token])) {
            self::$present[$token] = '' !== $token && '.' === $token[0] && '' !== self::$prefixes && preg_match(self::$prefixes, substr($token, 1));
        }
        return self::$present[$token];
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
