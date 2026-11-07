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
 * Left alone: the row a post or page opens with, or the row after a short
 * row of text alone there (its picture is fetched first,
 * SEOProStack_Image_Loading::kadence_preload(), GH#1402), parallax rows,
 * rows with a background slider, video or gradient, Kadence's old row
 * markup, and rows Kadence Blocks' own optimizer lazy-loads.
 *
 * At the size the screen needs (GH#1341): Kadence Blocks names the full
 * upload, so phones fetched the whole file for a small box. When the
 * picture has smaller copies (SEOProStack_Hero_Picture), the script sets
 * the narrowest copy at least the painted pixels × DPR × 1.1 as the row
 * comes near, and a larger one if it grows, never a smaller one. Columns
 * with a background picture wait and are sized the same way; their script
 * sits inside the column, as Kadence places columns with
 * :nth-child(… of *:not(style)). Left as before: sizes other than cover
 * and contain, fixed and parallax pictures, rows with tablet, phone or
 * dynamic backgrounds, columns with a hover change, and overlays.
 *
 * Rows with a background slider (GH#1339): the slider's list loses its
 * margin and padding from the start, not only once Kadence's script adds
 * .kb-splide, so the first slide does not paint 40 pixels narrower and
 * widen later, which Chrome counted as a later, larger LCP.
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

    /** @var bool Whether the background sliders' style is on the page. */
    private static $slider = false;

    /**
     * Register hooks.
     */
    public static function boot() {
        add_filter('render_block_kadence/rowlayout', array(__CLASS__, 'around_row'), 30, 2);
        add_filter('render_block_kadence/column', array(__CLASS__, 'around_column'), 30, 2);
    }

    /**
     * Around a row with a background picture on a front-end page: the style
     * that keeps its pictures waiting before it, and the script that has it
     * watched after it. Before the page's first row with a background
     * slider, the style for its first slide (slider_style()).
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
        $html  = self::slider_style($html, $attrs, $block);
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
        $picture = self::row_sizable($attrs) ? self::picture($attrs['bgImgID'] ?? 0, $attrs['bgImg'] ?? '', $block) : null;
        return $before . $html . self::call($id, $picture, false);
    }

    /**
     * Around a Kadence Blocks column with a background picture on a
     * front-end page (GH#1341): a style before it that keeps the picture
     * waiting, and inside it, after its inner column, the script that has it
     * watched and sized.
     *
     * @param mixed $html  Block HTML.
     * @param array $block Parsed block.
     * @return mixed
     */
    public static function around_column($html, $block) {
        if (!is_string($html) || '' === $html || !is_array($block) || !self::front_page()) {
            return $html;
        }
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        $id    = $attrs['uniqueID'] ?? '';
        $image = isset($attrs['backgroundImg'][0]) && is_array($attrs['backgroundImg'][0]) ? $attrs['backgroundImg'][0] : array();
        $end   = strrpos($html, '</div>');
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) || !self::column_waits($attrs, $image)
            || false === strpos($html, 'kadence-column' . $id) || false === strpos($html, 'kt-inside-inner-col')
            || false !== strpos($html, 'data-kadence-lazy-') || false === $end || '' !== trim(substr($html, $end + 6))) {
            return $html;
        }
        /**
         * Whether a Kadence Blocks column's background picture waits until
         * the visitor scrolls near the column (and then comes at the size
         * the screen needs, see seoprostack_kadence_bg_sizes). Off: it loads
         * with the page, as Kadence Blocks has it.
         *
         * @param bool  $on    Default true.
         * @param array $block Parsed column block.
         */
        if (!apply_filters('seoprostack_kadence_column_wait', true, $block)) {
            return $html;
        }
        $before = self::style($id, true);
        if (!self::$script) {
            self::$script = true;
            $before      .= self::script();
        }
        $picture = self::column_sizable($attrs, $image) ? self::picture($image['bgImgID'] ?? 0, $image['bgImg'] ?? '', $block) : null;
        // The script goes last in the column (its last </div> closes it).
        return $before . substr($html, 0, $end) . self::call($id, $picture, true) . substr($html, $end);
    }

    /**
     * The one-line script after a row, or inside a column: has it watched,
     * with its picture's copies when it has them.
     *
     * @param string     $id      Unique ID (letters, digits, - and _).
     * @param array|null $picture As SEOProStack_Hero_Picture::get() gives it, or null.
     * @param bool       $column  Whether it is a column's.
     * @return string
     */
    private static function call($id, $picture, $column) {
        $args = "document.currentScript,'" . $id . "'";
        if ($picture || $column) {
            // No < in the content (script()); JSON_HEX_TAG writes it, and
            // >, as \u003C and \u003E. Each file as url(…), so LiteSpeed
            // Cache's WebP replacement names its copy here too (GH#1333).
            $args .= ',' . ($picture ? (string) wp_json_encode(SEOProStack_Hero_Picture::for_script($picture), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) : '0');
        }
        return wp_get_inline_script_tag('window.seoprostackRows&&seoprostackRows(' . $args . ($column ? ',1' : '') . ')', SEOProStack_Kadence_Hero::SCRIPT_ATTRS);
    }

    /**
     * A row's or column's picture when it has smaller copies and the filter
     * says yes; null when not.
     *
     * @param mixed $attachment Attachment ID.
     * @param mixed $url        Address the block names.
     * @param array $block      Parsed block.
     * @return array|null
     */
    private static function picture($attachment, $url, array $block) {
        if (!is_string($url) || '' === $url) {
            return null;
        }
        /**
         * Whether a Kadence Blocks row's or column's background picture
         * comes at the size the screen needs, when it waits until near. Off:
         * the picture the block names, as Kadence Blocks has it.
         *
         * @param bool  $on    Default true.
         * @param array $block Parsed row or column block.
         */
        if (!apply_filters('seoprostack_kadence_bg_sizes', true, $block)) {
            return null;
        }
        require_once __DIR__ . '/class-seoprostack-hero-picture.php';
        $picture = SEOProStack_Hero_Picture::get($attachment, $url);
        return $picture && count($picture['c']) > 1 ? $picture : null;
    }

    /**
     * Whether a waiting row's own picture can be sized: one picture on
     * every device (no tablet, phone or dynamic background), sized cover,
     * contain or unset, and not fixed (it would paint across the window).
     *
     * @param array $attrs Row attributes.
     * @return bool
     */
    private static function row_sizable(array $attrs) {
        return in_array($attrs['bgImgSize'] ?? '', array('', 'cover', 'contain'), true)
            && !in_array($attrs['bgImgAttachment'] ?? '', array('fixed', 'parallax'), true)
            && empty($attrs['tabletBackground'][0]['enable']) && empty($attrs['mobileBackground'][0]['enable'])
            && !self::dynamic_background($attrs);
    }

    /**
     * Whether a column's background picture waits until near: Kadence's
     * normal background type with a picture. A column away from the window
     * cannot be hovered, so its hover settings stay as they are.
     *
     * @param array $attrs Column attributes.
     * @param array $image Its backgroundImg[0].
     * @return bool
     */
    private static function column_waits(array $attrs, array $image) {
        return in_array($attrs['backgroundType'] ?? '', array('', 'normal'), true)
            && is_string($image['bgImg'] ?? null) && '' !== $image['bgImg'];
    }

    /**
     * Whether a waiting column's picture can be sized: sized cover, contain
     * or unset, not fixed or parallax, no dynamic background, and nothing
     * on hover that changes the picture (Kadence's hover colour sets
     * background-image none, a hover picture or gradient replaces it; the
     * copy is set on the inner column with !important, which would win).
     *
     * @param array $attrs Column attributes.
     * @param array $image Its backgroundImg[0].
     * @return bool
     */
    private static function column_sizable(array $attrs, array $image) {
        foreach (array($attrs['backgroundHover'] ?? '', $attrs['backgroundImgHover'][0]['bgImg'] ?? '', $attrs['gradientHover'] ?? '') as $hover) {
            if (!is_string($hover) || '' !== $hover) {
                return false;
            }
        }
        return in_array($image['bgImgSize'] ?? '', array('', 'cover', 'contain'), true)
            && !in_array($image['bgImgAttachment'] ?? '', array('fixed', 'parallax'), true)
            && !self::dynamic_background($attrs);
    }

    /**
     * Whether a block has a dynamic background picture turned on (Kadence
     * Blocks Pro's kadenceDynamic, keyed by attribute path: bgImg for a row,
     * backgroundImg:0:bgImg for a column). Its picture is put in at render,
     * so the attribute's picture is not the one shown.
     *
     * @param array $attrs Block attributes.
     * @return bool
     */
    private static function dynamic_background(array $attrs) {
        $dynamic = isset($attrs['kadenceDynamic']) && is_array($attrs['kadenceDynamic']) ? $attrs['kadenceDynamic'] : array();
        foreach ($dynamic as $key => $field) {
            if ((false !== stripos((string) $key, 'background') || false !== stripos((string) $key, 'bgimg')) && is_array($field) && !empty($field['enable'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Before the page's first row with a background slider: a style that
     * takes the margin and padding off background sliders' lists, as
     * Kadence Blocks does once a slider starts (.kb-splide .splide__list,
     * with !important), so the first slide shows at its full width from the
     * first paint. Once a page.
     *
     * It is printed in the post's content, so it has no "<" or "&"
     * (script()).
     *
     * @param string $html  Row HTML.
     * @param array  $attrs Row attributes.
     * @param array  $block Parsed row block.
     * @return string
     */
    private static function slider_style($html, array $attrs, array $block) {
        if (self::$slider || 'slider' !== ($attrs['backgroundSettingTab'] ?? '') || false === strpos($html, 'kb-blocks-bg-slider')) {
            return $html;
        }
        /**
         * Whether a Kadence Blocks row background slider's first slide shows
         * at its full width before the slider's script runs. Off: it shows
         * narrower until then, as Kadence Blocks has it.
         *
         * @param bool  $on    Default true.
         * @param array $block Parsed row block.
         */
        if (!apply_filters('seoprostack_kadence_bg_slider_width', true, $block)) {
            return $html;
        }
        self::$slider = true;
        return '<style class="seoprostack-bg-slider" data-no-optimize="1">.kb-blocks-bg-slider .splide__list{margin:0!important;padding:0!important}</style>' . $html;
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
     * or ''. After a short row of text alone, the row after it
     * (SEOProStack_Image_Loading::opening_block(), GH#1402).
     *
     * @return string
     */
    private static function first_row() {
        if (null !== self::$first) {
            return self::$first;
        }
        self::$first = '';
        $post        = is_singular() ? get_queried_object() : null;
        if (!$post instanceof WP_Post || 0 !== strpos(ltrim($post->post_content), '<!-- wp:kadence/rowlayout ')) {
            return '';
        }
        $block = SEOProStack_Image_Loading::opening_block($post);
        $id    = $block['attrs']['uniqueID'] ?? '';
        if (is_array($block) && 'kadence/rowlayout' === ($block['blockName'] ?? '') && is_string($id)) {
            self::$first = $id;
        }
        return self::$first;
    }

    /**
     * The row's style: its pictures and its overlay's wait under HIDE until
     * the row is near (NEAR). A column's: its inner column's picture.
     *
     * @param string $id     Unique ID (letters, digits, - and _).
     * @param bool   $column Whether it is a column's.
     * @return string
     */
    private static function style($id, $column = false) {
        if ($column) {
            return '<style class="seoprostack-rows" data-column="' . $id . '" data-no-optimize="1">html.' . self::HIDE . ' .kadence-column' . $id . ':not(.' . self::NEAR . ')>.kt-inside-inner-col{background-image:none!important}</style>';
        }
        $row = 'html.' . self::HIDE . ' .kb-row-layout-id' . $id . ':not(.' . self::NEAR . ')';
        return '<style class="seoprostack-rows" data-row="' . $id . '" data-no-optimize="1">' . $row . ',' . $row . '>.kt-row-layout-overlay{background-image:none!important}</style>';
    }

    /**
     * The script that turns the wait on as the page is read, and watches
     * each row or column its own script names; as one comes near, it sets
     * the copy of its picture the screen needs, when its script gave the
     * copies (GH#1341).
     *
     * It is printed in the post's content, so it has no "<": wptexturize()
     * reads one there as the start of a tag, turns each & after it into
     * &#038; and leaves the rest of the content without curly quotes.
     *
     * @return string
     */
    private static function script() {
        $script = <<<'JS'
(function (d, w) {
    var e = d.documentElement, o, r, all = [], each = Array.prototype.forEach;
    // As the page is read; left alone when run late by an optimiser.
    if (w.seoprostackRows || 'loading' !== d.readyState || !e.classList || !d.querySelectorAll || !w.IntersectionObserver || !each || !w.getComputedStyle) { return; }
    o = new w.IntersectionObserver(function (list) {
        each.call(list, function (n) {
            if (n.isIntersecting) { o.unobserve(n.target); near(n.target); }
        });
    }, { rootMargin: '1250px 0px' });
    // A larger copy when the box grows; never a smaller one.
    r = w.ResizeObserver ? new w.ResizeObserver(function (list) {
        each.call(list, function (n) { if (n.target.spsOwner) { size(n.target.spsOwner); } });
    }) : null;
    // A row paints its own background; a column, its inner column.
    function box(el) {
        var c = el.firstElementChild;
        if (!el.classList.contains('wp-block-kadence-column')) { return el; }
        while (c && !c.classList.contains('kt-inside-inner-col')) { c = c.nextElementSibling; }
        return c;
    }
    // The narrowest file at least as wide as the pixels the picture paints,
    // with 10% to spare, else the block's own (the last); set only when
    // larger than the one set before.
    function size(el) {
        var p = el.spsPicture, b = el.spsBox, z = w.getComputedStyle(b).backgroundSize, q, a, n, j = 0, f;
        if ('cover' !== z && 'contain' !== z) { return; }
        q = b.getBoundingClientRect();
        if (!q.width || !q.height) { return; }
        a = p.w / p.h;
        n = ('cover' === z ? Math.max(q.width, q.height * a) : Math.min(q.width, q.height * a)) * (w.devicePixelRatio || 1) * 1.1;
        while (p.c.length - 1 > j && n > p.c[j][0]) { j++; }
        if (el.spsIndex >= j) { return; }
        el.spsIndex = j;
        f = p.c[j];
        // f[1] is url(…); f[2], when there, image-set() of its AVIF and WebP copies.
        b.style.setProperty('background-image', f[1], 'important');
        if (f[2]) { b.style.setProperty('background-image', f[2], 'important'); }
    }
    // Before its pictures may load: the copy first, so only that is fetched.
    function near(el) {
        var b = el.spsPicture ? box(el) : null;
        if (b) {
            el.spsBox = b;
            el.spsIndex = -1;
            b.spsOwner = el;
            try { size(el); } catch (x) {}
            if (r) { r.observe(b); }
            all.push(el);
        }
        el.classList.add('sps-near');
    }
    function watch(k, p) {
        each.call(d.querySelectorAll('.' + k), function (el) {
            if (el.classList.contains('sps-near')) { return; }
            if (p) { el.spsPicture = p; }
            o.observe(el);
        });
    }
    // Already near as it is read (a column in the first row): now, not a
    // frame later from the observer.
    function check(el) {
        var q = el.getBoundingClientRect(), h = w.innerHeight || e.clientHeight;
        if (q.bottom + 1250 > 0 && h + 1250 > q.top) { near(el); } else { o.observe(el); }
    }
    e.classList.add('sps-rows');
    // s: the script; a row's comes after it, a column's is its last child.
    w.seoprostackRows = function (s, id, p, c) {
        var k = (c ? 'kadence-column' : 'kb-row-layout-id') + id, el = s ? (c ? s.parentNode : s.previousElementSibling) : null;
        if (el && el.classList && el.classList.contains(k)) {
            if (p) { el.spsPicture = p; }
            check(el);
        } else {
            watch(k, p);
        }
    };
    // Denser pixels (the window moved to another display, or zoomed).
    (function dense() {
        var m = w.matchMedia && w.matchMedia('(resolution: ' + (w.devicePixelRatio || 1) + 'dppx)');
        if (m && m.addEventListener) { m.addEventListener('change', function () { each.call(all, function (el) { size(el); }); dense(); }, { once: true }); }
    })();
    // Rows and columns whose own script did not run (removed or moved by an optimiser).
    w.addEventListener('load', function () {
        each.call(d.querySelectorAll('style.seoprostack-rows'), function (s) {
            var row = s.getAttribute('data-row');
            watch(row ? 'kb-row-layout-id' + row : 'kadence-column' + s.getAttribute('data-column'));
        });
    });
})(document, window);
JS;
        return wp_get_inline_script_tag($script, SEOProStack_Kadence_Hero::SCRIPT_ATTRS);
    }
}
