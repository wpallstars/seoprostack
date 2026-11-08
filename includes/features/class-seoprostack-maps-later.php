<?php
/**
 * Load Google Maps embeds when they come near.
 *
 * Browsers start loading an iframe with loading="lazy" well before it
 * reaches the screen: on desktop Chrome a Google Maps embed in a lower
 * section of a home page loads with the page. On one Kadence home page, the
 * Kadence Blocks Google Maps block (Embed API) cost 16 requests, about
 * 490 KB, about 360 ms of main-thread time and its own Google Fonts in
 * PageSpeed's desktop run (GH#1297).
 *
 * Each Google Maps embed iframe in a block's output (the Kadence Blocks
 * Google Maps block, an embed pasted into a Custom HTML block or classic
 * content, a block widget) is printed without its src, kept in
 * data-seoprostack-src, with a <noscript> copy for browsers without
 * JavaScript. Width, height, loading and the block's container stay, so
 * nothing moves. A small script before </body> gives each map its address
 * once it comes within DISTANCE pixels of the screen; a map in a tab, an
 * accordion or a modal loads when it is shown. Without IntersectionObserver,
 * and before printing, every map loads at once.
 *
 * Left alone: maps in the block a post or page opens with (the first
 * screen); maps with loading="eager", or text that keeps a picture from
 * lazy loading (Image loading's list, the skip-lazy and no-lazy classes, a
 * data-skip-lazy or data-no-lazy attribute); the Kadence Blocks map's
 * JavaScript API type, which is not an iframe; maps outside blocks, such as
 * a shortcode's; the editor, REST and AJAX responses, feeds and embeds;
 * pages whose content security policy, as PHP sends it, blocks inline
 * scripts or styles.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.189
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Maps_Later extends SEOProStack_Feature {

    const KEY = 'maps_later';

    /** Where a waiting map keeps its address. */
    const SRC = 'data-seoprostack-src';

    /** How near the screen a map is, in pixels, when it loads. */
    const DISTANCE = 600;

    /** @var int Blocks being rendered, one inside another. */
    private static $depth = 0;

    /** @var array<int,int> Top-level blocks begun in each post's main content. */
    private static $tops = array();

    /** @var bool Whether the top-level block being rendered is the one the main post opens with. */
    private static $opening = false;

    /** @var int Maps held this request. */
    private static $held = 0;

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => true,
                'tab'         => 'speed',
                'label'       => __('Load Google Maps embeds when they come near', 'seoprostack'),
                'description' => __('Browsers load an embedded Google map well before it reaches the screen, so on a computer a map far down a page loads with it: about 500 KB and 16 requests, with its own fonts. This loads each Google Maps embed, such as the Kadence Blocks Google Maps block, when it comes near the screen or is shown in a tab or modal, keeping its size so nothing moves. A map in the block a page opens with loads straight away, as before.', 'seoprostack'),
            ),
        );
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || is_admin()) {
            return;
        }
        // Last, so a block another filter renders itself is not counted.
        add_filter('pre_render_block', array(__CLASS__, 'begin'), PHP_INT_MAX, 2);
        add_filter('render_block', array(__CLASS__, 'render'), 20, 2);
        add_action('wp_footer', array(__CLASS__, 'loader'), 20);
    }

    /**
     * Count a block as it starts, and note whether it is the block the main
     * post opens with.
     *
     * @param string|null $pre   Output another filter rendered, or null.
     * @param array       $block Parsed block.
     * @return string|null
     */
    public static function begin($pre, $block) {
        if (null !== $pre) {
            return $pre;
        }
        ++self::$depth;
        if (1 === self::$depth) {
            self::$opening = false;
            // Leave out the white space between blocks.
            if ((!empty($block['blockName']) || '' !== trim((string) ($block['innerHTML'] ?? ''))) && self::main_content()) {
                $id              = (int) get_the_ID();
                self::$tops[$id] = (self::$tops[$id] ?? 0) + 1;
                self::$opening   = 1 === self::$tops[$id];
            }
        }
        return $pre;
    }

    /**
     * Hold the Google Maps embeds in a rendered block.
     *
     * @param string $content Rendered block.
     * @param array  $block   Parsed block.
     * @return string
     */
    public static function render($content, $block) {
        $opening     = self::$opening;
        self::$depth = max(0, self::$depth - 1);
        if (0 === self::$depth) {
            self::$opening = false;
        }
        if ($opening || !is_string($content) || false === stripos($content, '<iframe') || false === stripos($content, 'google.')
            || !self::page_view()) {
            return $content;
        }
        $held = preg_replace_callback('#<iframe\b[^>]*>.*?</iframe>#is', array(__CLASS__, 'hold'), $content);
        return is_string($held) ? $held : $content;
    }

    /**
     * One iframe: when it is a Google Maps embed that may wait, the same
     * iframe without its address, then a <noscript> copy.
     *
     * @param string[] $match The iframe, from its tag to its end tag.
     * @return string
     */
    public static function hold(array $match) {
        $iframe = $match[0];
        $tags   = new WP_HTML_Tag_Processor($iframe);
        if (!$tags->next_tag(array('tag_name' => 'IFRAME'))) {
            return $iframe;
        }
        $src = $tags->get_attribute('src');
        // A map held by an inner block has no src when an outer block's
        // render sees it, and it and its <noscript> copy have data-no-lazy.
        if (!is_string($src) || !self::is_map($src) || 'eager' === strtolower((string) $tags->get_attribute('loading'))
            || self::excluded($iframe)) {
            return $iframe;
        }
        $tags->set_attribute(self::SRC, $src);
        $tags->remove_attribute('src');
        // Page-rewriting lazy loaders (LiteSpeed Cache, WP Rocket) skip it.
        $tags->set_attribute('data-no-lazy', '1');
        $waiting = $tags->get_updated_html();

        // Without JavaScript: the map as it was. Marked so an outer block,
        // and other lazy loaders, leave it alone.
        $copy = new WP_HTML_Tag_Processor($iframe);
        $copy->next_tag(array('tag_name' => 'IFRAME'));
        $copy->set_attribute('data-no-lazy', '1');

        ++self::$held;
        return $waiting . '<noscript>' . $copy->get_updated_html() . '</noscript>';
    }

    /**
     * Whether an address is a Google Maps embed: google.com/maps/embed
     * (the Embed API Kadence Blocks uses, and Google's Share → Embed a map),
     * or the older maps.google.com/maps?…&output=embed, on any Google domain.
     *
     * @param string $src Address.
     * @return bool
     */
    public static function is_map($src) {
        $parts = wp_parse_url(trim($src));
        // No scheme: a protocol-relative address.
        if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? 'https'), array('http', 'https'), true)) {
            return false;
        }
        if (!preg_match('/^(?:www\.|maps\.)?google\.(?:[a-z]{2,3}|com?\.[a-z]{2})$/', strtolower($parts['host']))) {
            return false;
        }
        $path = (string) ($parts['path'] ?? '');
        if (0 === strpos($path, '/maps/embed')) {
            return true;
        }
        return '/maps' === rtrim($path, '/') && (bool) preg_match('/(?:^|&)output=embed(?:&|$)/', (string) ($parts['query'] ?? ''));
    }

    /**
     * Whether the iframe's tag has text that keeps it from lazy loading:
     * Image loading's list (skip-lazy, no-lazy, data-skip-lazy,
     * data-no-lazy, custom-logo and the site's own lines), whether or not
     * Image loading is on.
     *
     * @param string $iframe The iframe.
     * @return bool
     */
    private static function excluded($iframe) {
        return SEOProStack_Image_Loading::excluded((string) strstr($iframe, '>', true));
    }

    /**
     * Whether a block is rendered in the main post's content, on its own
     * page.
     *
     * @return bool
     */
    private static function main_content() {
        global $wp_current_filter;

        if (!is_singular() || !is_main_query() || !in_the_loop()
            || count(array_keys((array) $wp_current_filter, 'the_content', true)) !== 1) {
            return false;
        }
        // get_the_ID() is false without a post, and a singular page's ID is not 0.
        return get_queried_object_id() === (int) get_the_ID();
    }

    /**
     * Whether this request is a page view where maps can wait.
     *
     * @return bool
     */
    private static function page_view() {
        static $view = null;
        if (null !== $view) {
            return $view;
        }
        // boot() has already left out the admin.
        if (is_feed() || is_embed() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || wp_is_json_request()) {
            // Not stored: the request type does not change, but these are cheap.
            return false;
        }
        require_once SEOPROSTACK_DIR . 'includes/class-seoprostack-used-css-policy.php';
        // A policy that blocks the loader would leave the maps empty. Meta
        // policies in the head cannot be read from here.
        if (!SEOProStack_Used_Css_Policy::allows('', true)) {
            $view = false;
            return $view;
        }
        /**
         * Whether Google Maps embeds load when they come near on this page.
         *
         * @param bool $later True to load them when they come near.
         */
        $view = (bool) apply_filters('seoprostack_maps_later', true);
        return $view;
    }

    /**
     * The script that gives each waiting map its address as it comes near.
     */
    public static function loader() {
        if (0 === self::$held) {
            return;
        }
        $js = sprintf(
            '(function(){var a=%1$s,f=document.querySelectorAll("iframe["+a+"]"),io=null,i;if(!f.length)return;'
            . 'function go(e){var s=e.getAttribute(a);if(!s)return;if(io)io.unobserve(e);e.removeAttribute(a);e.setAttribute("src",s);}'
            . 'function all(){for(var j=0;j<f.length;j++)go(f[j]);}'
            . 'window.addEventListener("beforeprint",all);'
            . 'if(!("IntersectionObserver" in window)){all();return;}'
            . 'io=new IntersectionObserver(function(es){for(var j=0;j<es.length;j++){if(es[j].isIntersecting)go(es[j].target);}},{rootMargin:"%2$dpx 0px"});'
            . 'for(i=0;i<f.length;i++)io.observe(f[i]);})();',
            wp_json_encode(self::SRC),
            self::DISTANCE
        );
        wp_print_inline_script_tag($js, array(
            'id'                       => 'seoprostack-maps-later',
            'data-cfasync'             => 'false',
            'data-no-optimize'         => '1',
            'data-seoprostack-nodelay' => '1',
        ));
    }
}
