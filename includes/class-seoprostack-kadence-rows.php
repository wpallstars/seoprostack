<?php
/**
 * Kadence Blocks rows further down a page: their background pictures wait
 * until the visitor scrolls near them (GH#1329), for Image loading
 * (SEOProStack_Image_Loading).
 *
 * Kadence Blocks writes a row's background picture into the page's CSS, so
 * browsers fetched every row's picture as soon as the row was laid out,
 * wherever it was on the page. On one Kadence home page a row further down
 * fetched a 566 KiB picture with the page, competing with the first
 * screen's. Pictures in <img> tags have the browser's own lazy loading;
 * backgrounds have none.
 *
 * Before each such row, a style; after it, a one-line script. The first
 * waiting row's script adds a class to <html>, under which the row's
 * background pictures (its own, its tablet and phone ones, and its
 * overlay's) wait, and each row's script has its row watched: it gets its
 * pictures once it comes within 1,250 pixels of the window (sps-near), the
 * distance Chrome's own lazy loading uses for pictures on a fast
 * connection. A row on the first screen gets them at the first check, as
 * the page is first drawn. Without JavaScript, in a browser without
 * IntersectionObserver or :not(), or when an optimiser runs the scripts
 * late, nothing waits; once the page has loaded, rows whose script did not
 * run are watched too.
 *
 * Left alone: the row a post or page opens with (its picture is fetched
 * first, SEOProStack_Image_Loading::kadence_preload()), parallax rows,
 * rows with a background slider, video or gradient, Kadence's old row
 * markup, and rows Kadence Blocks' own optimizer lazy-loads.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.168
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-seoprostack-kadence-hero.php';

class SEOProStack_Kadence_Rows {

    /** Class on <html> under which rows' pictures wait. */
    const HIDE = 'sps-rows';

    /** Class on a row near the window: its pictures load. */
    const NEAR = 'sps-near';

    /** @var string|null Unique ID of the row the post or page opens with, '' for none, null until known. */
    private static $first = null;

    /** @var bool Whether the script that turns the wait on is on the page. */
    private static $script = false;

    /**
     * Register hooks.
     */
    public static function boot() {
        add_filter('render_block_kadence/rowlayout', array(__CLASS__, 'around_row'), 30, 2);
    }

    /**
     * Around a row with a background picture on a front-end page: the style
     * that keeps its pictures waiting before it, and the script that has it
     * watched after it.
     *
     * @param mixed $html  Block HTML.
     * @param array $block Parsed block.
     * @return mixed
     */
    public static function around_row($html, $block) {
        if (!is_string($html) || '' === $html || !is_array($block) || !self::front_page()) {
            return $html;
        }
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        $id    = $attrs['uniqueID'] ?? '';
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) || !self::waits($attrs) || self::first_row() === $id
            || false === strpos($html, 'kb-row-layout-id' . $id) || false !== strpos($html, 'data-kadence-lazy-')) {
            return $html;
        }
        /**
         * Whether a Kadence Blocks row's background pictures wait until the
         * visitor scrolls near the row. Off: they load with the page, as
         * Kadence Blocks has it.
         *
         * @param bool  $on    Default true.
         * @param array $block Parsed row block.
         */
        if (!apply_filters('seoprostack_kadence_row_wait', true, $block)) {
            return $html;
        }
        $before = self::style($id);
        if (!self::$script) {
            self::$script = true;
            $before      .= self::script();
        }
        // The ID is letters, digits, - and _.
        return $before . $html . wp_get_inline_script_tag("window.seoprostackRows&&seoprostackRows(document.currentScript,'" . $id . "')", SEOProStack_Kadence_Hero::SCRIPT_ATTRS);
    }

    /**
     * Whether this is a front-end page being built, not a feed, embed,
     * REST response (the editors) or admin-ajax request.
     *
     * @return bool
     */
    private static function front_page() {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || !did_action('wp')) {
            return false;
        }
        return !is_feed() && !is_embed();
    }

    /**
     * Whether a row's background pictures can wait: Kadence's current row
     * markup, the normal background tab, and a picture (its own, a tablet
     * or phone one, its overlay's, or a dynamic one), none of them parallax.
     *
     * @param array $attrs Row attributes.
     * @return bool
     */
    private static function waits(array $attrs) {
        if (empty($attrs['kbVersion']) || (int) $attrs['kbVersion'] < 2 || !in_array($attrs['backgroundSettingTab'] ?? 'normal', array('', 'normal'), true)) {
            return false;
        }
        $pictures = array(
            array($attrs['bgImg'] ?? '', $attrs['bgImgAttachment'] ?? ''),
            array($attrs['overlayBgImg'] ?? '', $attrs['overlayBgImgAttachment'] ?? ''),
        );
        foreach (array('tabletBackground', 'mobileBackground') as $device) {
            if (!empty($attrs[$device][0]['enable'])) {
                $pictures[] = array($attrs[$device][0]['bgImg'] ?? '', $attrs[$device][0]['bgImgAttachment'] ?? '');
            }
        }
        $any = !empty($attrs['kadenceDynamic']['bgImg']['enable']);
        foreach ($pictures as $picture) {
            if (!is_string($picture[0]) || '' === $picture[0]) {
                continue;
            }
            if ('parallax' === $picture[1]) {
                return false;
            }
            $any = true;
        }
        return $any;
    }

    /**
     * The unique ID of the Kadence row the post or page shown opens with,
     * from its first block's comment, or ''.
     *
     * @return string
     */
    private static function first_row() {
        if (null !== self::$first) {
            return self::$first;
        }
        self::$first = '';
        $post        = is_singular() ? get_queried_object() : null;
        if (!$post instanceof WP_Post) {
            return '';
        }
        // A block comment's attributes never hold "-->": the editor escapes it.
        if (preg_match('#^<!-- wp:kadence/rowlayout (\{.*?\}) /?-->#s', ltrim($post->post_content), $match)) {
            $attrs        = json_decode($match[1], true);
            self::$first  = is_array($attrs) && isset($attrs['uniqueID']) && is_string($attrs['uniqueID']) ? $attrs['uniqueID'] : '';
        }
        return self::$first;
    }

    /**
     * The row's style: its pictures and its overlay's wait under HIDE until
     * the row is near (NEAR).
     *
     * @param string $id Row's unique ID (letters, digits, - and _).
     * @return string
     */
    private static function style($id) {
        $row = 'html.' . self::HIDE . ' .kb-row-layout-id' . $id . ':not(.' . self::NEAR . ')';
        return '<style class="seoprostack-rows" data-row="' . $id . '" data-no-optimize="1">' . $row . ',' . $row . '>.kt-row-layout-overlay{background-image:none!important}</style>';
    }

    /**
     * The script that turns the wait on as the page is read, and watches
     * each row its row script names.
     *
     * @return string
     */
    private static function script() {
        $script = <<<'JS'
(function (d, w) {
    var e = d.documentElement, o;
    // As the page is read; left alone when run late by an optimiser.
    if (w.seoprostackRows || 'loading' !== d.readyState || !e.classList || !d.querySelectorAll || !w.IntersectionObserver) { return; }
    o = new w.IntersectionObserver(function (list) {
        for (var i = 0; i < list.length; i++) {
            if (list[i].isIntersecting) { list[i].target.classList.add('sps-near'); o.unobserve(list[i].target); }
        }
    }, { rootMargin: '1250px 0px' });
    function watch(id) {
        var r = d.querySelectorAll('.kb-row-layout-id' + id), i;
        for (i = 0; i < r.length; i++) { o.observe(r[i]); }
    }
    e.classList.add('sps-rows');
    w.seoprostackRows = function (s, id) {
        var r = s && s.previousElementSibling;
        if (r && r.classList.contains('kb-row-layout-id' + id)) { o.observe(r); } else { watch(id); }
    };
    // Rows whose own script did not run (removed or moved by an optimiser).
    w.addEventListener('load', function () {
        var s = d.querySelectorAll('style.seoprostack-rows[data-row]'), i;
        for (i = 0; i < s.length; i++) { watch(s[i].getAttribute('data-row')); }
    });
})(document, window);
JS;
        return wp_get_inline_script_tag($script, SEOProStack_Kadence_Hero::SCRIPT_ATTRS);
    }
}
