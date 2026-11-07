<?php
/**
 * Kadence Blocks Pro Advanced Slider: only the slides shown fetch their
 * pictures, and the next ones once the first is in (GH#1312), each at the
 * size the screen needs (GH#1333), for Image loading
 * (SEOProStack_Image_Loading).
 *
 * Kadence Blocks Pro writes each slide's picture into the block's CSS, and
 * Splide keeps every slide rendered (stacked at opacity 0 when slides fade,
 * off-screen in a carousel), so browsers fetched every slide's picture with
 * the page, at once, competing with the first slide's. On a 17-slide home
 * page that was 4.9 MB of the page's 5.4 MB, and the first slide, the
 * page's largest paint, came 15 seconds in on a phone.
 *
 * Before each slider, a style and a small script: the script adds a class
 * to <html>, and under it every slide's picture except the first slide's
 * (by its class, so Splide's loop copies of it keep it too) waits until
 * Splide shows the slide (is-active, is-visible). Once the first slider's
 * first picture is in (an Image() of the address its slide shows, so the
 * browser's copy), or the page has loaded, the slides next to those shown
 * (is-next, is-prev, and the one after each shown) fetch theirs too, so the
 * next picture is in before it shows, without competing with the first. A
 * slider with no slide shown a second after the page has loaded (Splide
 * not started, in a closed tab, or its classes renamed) shows every
 * slide's picture, as before. Without JavaScript nothing waits, and
 * browsers without :not() selector lists (Selectors Level 4) drop the
 * rules, so nothing waits either.
 *
 * Sizes (GH#1333). A slide names one picture, often the full upload, so
 * phones got it too. When a slide's picture has smaller copies of the same
 * proportions (SEOProStack_Hero_Picture, as for the row a page opens with,
 * GH#1221), a script right after the slider measures its slides and takes,
 * for each, the smallest copy at least as wide as the pixels it paints,
 * with 10% to spare, else the slide's own picture: never less sharp. The
 * choice goes in a style rule per slide, by its kb-slide-<id> class, so
 * Splide's loop copies get it too; the rule has !important but is less
 * specific than the wait's, so slides still wait. If the slides grow or
 * the screen's pixels get denser, a larger copy replaces it, never a
 * smaller one. When a post or page opens with the slider, the first
 * slide's choice is preloaded at high priority, in place of the head's
 * preload of its own picture. Each file is written as CSS's url(…), so
 * LiteSpeed Cache's WebP replacement, which rewrites every url() in the
 * page whose picture has a .webp file beside it, names its copy there
 * too, on the pages where it names it in the slide's CSS (GH#1326).
 *
 * Until the script after the slider has set its rules, a class on <html>
 * hides that slider's pictures, so the full ones are not fetched as well.
 * A script adds the class, so without JavaScript nothing is hidden, and it
 * goes when the page is read even if the script after the slider did not
 * run. Only `background-size: cover` and `contain` and a normal background
 * (a gradient replaces the picture); classic themes only, as for the row.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.166
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-seoprostack-kadence-hero.php';

class SEOProStack_Kadence_Slider {

    /** Class on <html> under which slides' pictures wait. */
    const HIDE = 'sps-slides';

    /** Class on <html> once the page has loaded: the next slides fetch theirs. */
    const NEAR = 'sps-slides-near';

    /** Class on a slider whose slides all show their pictures (the fail-safe). */
    const ALL = 'sps-slides-all';

    /** Class on <html> under which sliders' pictures wait for their size (GH#1333). */
    const SIZING = 'sps-sizing';

    /** Class on a slider once its slides' sizes are set. */
    const SIZED = 'sps-sized';

    /** @var string Unique ID of the slider the page opens with, when its first slide's chosen copy is preloaded from it. */
    private static $opens = '';

    /** @var string Preload of that slide's own picture, printed before the slider if it is not sized after all. */
    private static $opens_tag = '';

    /**
     * Register hooks.
     */
    public static function boot() {
        add_filter('render_block_kadence/slider', array(__CLASS__, 'before_slider'), 30, 2);
    }

    /**
     * When a post or page opens with the slider
     * (SEOProStack_Image_Loading::kadence_preload()): whether its first
     * slide's picture comes at the size the screen needs, so the script
     * after the slider preloads the copy it takes, and the head's preload
     * of the slide's own picture is only for browsers without JavaScript.
     *
     * @param array  $block Parsed slider block.
     * @param string $tag   That preload, printed before the slider if it is not sized after all.
     * @return bool
     */
    public static function opens(array $block, $tag) {
        $slider  = self::slider_id($block);
        $first   = $block['innerBlocks'][0] ?? null;
        $picture = '' !== $slider && is_array($first) && self::sizing($block) ? self::picture($first) : null;
        if (!$picture) {
            return false;
        }
        self::$opens     = $slider;
        self::$opens_tag = (string) $tag;
        return true;
    }

    /**
     * For the slider a post or page opens with, once opens() has taken it:
     * the head's script that fetches its first slide's picture, when the
     * slider sets its height (SEOProStack_Kadence_Early, GH#1337); '' when not.
     *
     * @param array $block Parsed slider block.
     * @return string
     */
    public static function early(array $block) {
        $first   = $block['innerBlocks'][0] ?? null;
        $picture = '' !== self::$opens && self::$opens === self::slider_id($block) && is_array($first) ? self::picture($first) : null;
        if (!$picture) {
            return '';
        }
        require_once __DIR__ . '/class-seoprostack-kadence-early.php';
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        return SEOProStack_Kadence_Early::slider(self::$opens, $attrs, $picture, (string) ($first['attrs']['backgroundImg'][0]['size'] ?? ''));
    }

    /**
     * Around a slider on a front-end page: before it, the styles and
     * scripts that size its slides' pictures and keep the other slides'
     * waiting; after it, the script that sizes them.
     *
     * @param mixed $html  Block HTML.
     * @param array $block Parsed block.
     * @return mixed
     */
    public static function before_slider($html, $block) {
        if (!is_string($html) || '' === $html || !is_array($block) || !self::front_page()) {
            return $html;
        }
        $slider = self::slider_id($block);
        // Not cleared once used: the content can be rendered more than once.
        $opens = '' !== self::$opens && $slider === self::$opens;
        if ('' === $slider || false === strpos($html, 'kb-advanced-slider-' . $slider)) {
            return ($opens ? self::own_preload() : '') . $html;
        }
        $pictures = self::sizing($block) ? self::pictures($block) : array();
        $first    = $block['innerBlocks'][0]['attrs']['uniqueID'] ?? '';
        // The first slide's copy is preloaded only when it is sized.
        $preload = $opens && $pictures && $first === $pictures[0]['n'];
        $before  = $opens && !$preload ? self::own_preload() : '';
        $after   = '';
        if ($pictures) {
            $before .= self::size_style($slider) . self::size_script();
            $after   = self::size_call($slider, $pictures, $preload);
        }
        return $before . self::wait($html, $block) . $html . $after;
    }

    /**
     * Before a slider: the style that keeps its other slides' pictures
     * waiting, and the script that turns it on; '' when it does not apply.
     *
     * @param string $html  Block HTML.
     * @param array  $block Parsed block.
     * @return string
     */
    private static function wait($html, array $block) {
        $ids = self::ids($block);
        if (!$ids || false === strpos($html, 'kb-slide-' . $ids[1])) {
            return '';
        }
        /**
         * Whether a Kadence Blocks Pro Advanced Slider's slides fetch their
         * pictures only as they come up. Off: every slide's picture loads
         * with the page, as Kadence Blocks Pro has it.
         *
         * @param bool  $on    Default true.
         * @param array $block Parsed slider block.
         */
        if (!apply_filters('seoprostack_kadence_slider_wait', true, $block)) {
            return '';
        }
        return self::style($ids[0], $ids[1]) . self::script();
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
     * A block's unique ID, when it can go in a CSS class name; '' when not.
     *
     * @param mixed $id Unique ID.
     * @return string
     */
    private static function usable_id($id) {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : '';
    }

    /**
     * The slider's unique ID, when it can go in a CSS class name; '' when not.
     *
     * @param array $block Parsed slider block.
     * @return string
     */
    private static function slider_id(array $block) {
        return self::usable_id($block['attrs']['uniqueID'] ?? '');
    }

    /**
     * Whether a slider's slides may come at the size each screen needs:
     * classic themes (block themes render the content before the head), and
     * the filter.
     *
     * @param array $block Parsed slider block.
     * @return bool
     */
    private static function sizing(array $block) {
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            return false;
        }
        /**
         * Whether a Kadence Blocks Pro Advanced Slider's slides get their
         * pictures at the size each screen needs. Off: every screen gets the
         * picture each slide names.
         *
         * @param bool  $on    Default true.
         * @param array $block Parsed slider block.
         */
        return (bool) apply_filters('seoprostack_kadence_slider_sizes', true, $block);
    }

    /**
     * The slides' pictures that have smaller copies, in slide order.
     *
     * @param array $block Parsed slider block.
     * @return array<int,array>
     */
    private static function pictures(array $block) {
        $slides = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : array();
        $ids    = array();
        foreach ($slides as $slide) {
            $id = $slide['attrs']['backgroundImg'][0]['id'] ?? 0;
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        // One query for the attachments and their metadata, not two a slide.
        if ($ids && function_exists('_prime_post_caches')) {
            _prime_post_caches(array_unique($ids), false, true);
        }
        $list = array();
        foreach ($slides as $slide) {
            $picture = is_array($slide) ? self::picture($slide) : null;
            if ($picture) {
                $list[] = $picture;
            }
        }
        return $list;
    }

    /**
     * A slide's picture with its smaller copies (SEOProStack_Hero_Picture),
     * and the slide's unique ID as n: for a normal background without a
     * gradient, sized cover (Kadence Blocks Pro's default) or contain. Null
     * when not, or when the picture has no smaller copies.
     *
     * @param array $slide Parsed slide block.
     * @return array|null
     */
    private static function picture(array $slide) {
        $attrs = isset($slide['attrs']) && is_array($slide['attrs']) ? $slide['attrs'] : array();
        $image = $attrs['backgroundImg'][0] ?? null;
        $id    = self::usable_id($attrs['uniqueID'] ?? '');
        if (empty($slide['blockName']) || 'kadence/slide' !== $slide['blockName'] || '' === $id || !is_array($image)
            || !in_array($attrs['backgroundType'] ?? 'normal', array('', 'normal'), true) || !empty($attrs['backgroundGradient'])
            || !in_array($image['size'] ?? '', array('', 'cover', 'contain'), true)) {
            return null;
        }
        require_once __DIR__ . '/class-seoprostack-hero-picture.php';
        $picture = SEOProStack_Hero_Picture::get($image['id'] ?? 0, $image['img'] ?? '');
        if (!$picture || count($picture['c']) < 2) {
            return null;
        }
        $picture['n'] = $id;
        return $picture;
    }

    /**
     * The preload of the first slide's own picture, before a slider the
     * head left it to that was not sized after all.
     *
     * @return string
     */
    private static function own_preload() {
        return self::$opens_tag;
    }

    /**
     * The style that hides a slider's pictures until its sizes are set.
     *
     * @param string $slider Slider's unique ID (letters, digits, - and _).
     * @return string
     */
    private static function size_style($slider) {
        return '<style class="seoprostack-slide-sizes" data-no-optimize="1">html.' . self::SIZING . ' .kb-advanced-slider-' . $slider . ':not(.' . self::SIZED
            . ') .wp-block-kadence-slide>.kb-advanced-slide>.kb-advanced-slide-inner-wrap{background-image:none!important}</style>';
    }

    /**
     * After a slider: the script that sizes its slides' pictures, with
     * each picture's proportions and files, narrowest first, as width and
     * url(…) (then, when there are, image-set() of its copies, the address
     * to preload and its type).
     *
     * @param string $slider   Slider's unique ID (letters, digits, - and _).
     * @param array  $pictures Pictures, as picture() gives them.
     * @param bool   $preload  Whether the first picture's copy is preloaded.
     * @return string
     */
    private static function size_call($slider, array $pictures, $preload) {
        $list = array();
        foreach ($pictures as $picture) {
            $list[] = SEOProStack_Hero_Picture::for_script($picture) + array('n' => $picture['n']);
        }
        $data = array(
            's' => $slider,
            'f' => $preload ? 1 : 0,
            'p' => $list,
        );
        // No < in the content (wptexturize(), see script()); JSON_HEX_TAG
        // writes it, and >, as \u003C and \u003E.
        $json = (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        return wp_get_inline_script_tag('window.seoprostackSlideSizes&&seoprostackSlideSizes(document.currentScript,' . $json . ')', SEOProStack_Kadence_Hero::SCRIPT_ATTRS);
    }

    /**
     * The script that sizes the slides' pictures, as the page is read;
     * once a page, whichever slider's copy runs first. No "<" (script()).
     *
     * @return string
     */
    private static function size_script() {
        $script = <<<'JS'
(function (d, w) {
    var e = d.documentElement;
    // Once, as the page is read; left alone when run late by an optimiser.
    if (w.seoprostackSlideSizes || 'loading' !== d.readyState || !e.classList || !w.getComputedStyle || !d.head) { return; }
    e.classList.add('sps-sizing');
    // Whatever happens below, every slider shows its pictures once the page is read.
    d.addEventListener('DOMContentLoaded', function () { e.classList.remove('sps-sizing'); });
    w.seoprostackSlideSizes = function (s, h) {
        var k = 'kb-advanced-slider-' + h.s, root = s && s.previousElementSibling, g = w.seoprostackEarly;
        if (!root || !root.classList.contains(k)) { root = d.querySelector('.' + k); }
        if (!root) { return; }
        // The copy the head fetched for the first slide (GH#1337), or -1.
        g = h.f && g && 's' + h.s === g.k ? g.i : -1;
        try { size(root, k, h.p, h.f, g); } finally { root.classList.add('sps-sized'); }
    };
    function size(root, k, p, preload, g) {
        var wraps = root.getElementsByClassName('kb-advanced-slide-inner-wrap'), st = d.createElement('style'), b, f, l, o;
        // The slides' box: the largest laid out (before Splide starts, the first slide).
        function box() {
            var r = { w: 0, h: 0 };
            Array.prototype.forEach.call(wraps, function (x) {
                var c = x.getBoundingClientRect();
                r.w = Math.max(r.w, c.width);
                r.h = Math.max(r.h, c.height);
            });
            return r;
        }
        // Device pixels a picture paints across, with 10% to spare.
        function need(q, r) {
            var a = q.w / q.h;
            if (!r.w || !r.h) { return 0; }
            return ('cover' === q.z ? Math.max(r.w, r.h * a) : Math.min(r.w, r.h * a)) * (w.devicePixelRatio || 1) * 1.1;
        }
        // The narrowest file that is enough, else the slide's own (the last).
        function pick(q, r) {
            var n = need(q, r), j = 0;
            if (!n) { return q.c.length - 1; }
            while (q.c.length - 1 > j && n > q.c[j][0]) { j++; }
            return j;
        }
        function rules() {
            var css = '';
            p.forEach(function (q) {
                var f, sel;
                if (!q.z) { return; }
                f = q.c[q.i];
                sel = '.' + k + ' .kb-slide-' + q.n + '>.kb-advanced-slide>.kb-advanced-slide-inner-wrap{background-image:';
                css += sel + f[1] + '!important}';
                // Browsers without image-set() types drop this one and keep the url().
                if (f[2]) { css += sel + f[2] + '!important}'; }
            });
            st.textContent = css;
        }
        // How the slide's picture is sized, cover or contain; '' for other ways, left alone.
        function mode(q) {
            var x = root.querySelector('.kb-slide-' + q.n + '>.kb-advanced-slide>.kb-advanced-slide-inner-wrap'), z = x ? w.getComputedStyle(x).backgroundSize : '';
            return 'cover' === z || 'contain' === z ? z : '';
        }
        b = box();
        p.forEach(function (q) {
            q.z = mode(q);
            if (q.z) { q.i = pick(q, b); }
        });
        // The head's copy of the first slide's picture when it is enough
        // (never fewer pixels than this script's), so it is fetched once.
        if (p[0] && p[0].z && g >= p[0].i) {
            p[0].i = g;
        } else if (preload && p[0]) {
            // The first slide's file, at high priority, as the head would have.
            f = p[0].c[p[0].z ? p[0].i : p[0].c.length - 1];
            l = d.createElement('link');
            l.rel = 'preload';
            l.as = 'image';
            if (f[4]) { l.type = f[4]; }
            l.setAttribute('fetchpriority', 'high');
            l.href = f[3] || f[1].slice(4, -1);
            d.head.appendChild(l);
        }
        rules();
        d.head.appendChild(st);
        // Larger when the slides grow, or when the screen's pixels get denser
        // (the window moved to another display, or zoomed); never smaller.
        function grow() {
            var r = box(), more = 0;
            p.forEach(function (q) {
                var z, j;
                if (!q.z) { return; }
                // The theme's CSS may switch contain to cover at another width.
                z = mode(q);
                if (z) { q.z = z; }
                j = pick(q, r);
                if (j > q.i) { q.i = j; more = 1; }
            });
            if (more) { rules(); }
        }
        if (w.ResizeObserver) {
            o = new w.ResizeObserver(grow);
            o.observe(root);
            Array.prototype.forEach.call(wraps, function (x) { o.observe(x); });
        }
        (function watch() {
            var m = w.matchMedia && w.matchMedia('(resolution: ' + (w.devicePixelRatio || 1) + 'dppx)');
            if (m && m.addEventListener) { m.addEventListener('change', function () { grow(); watch(); }, { once: true }); }
        })();
    }
})(document, window);
JS;
        return wp_get_inline_script_tag($script, SEOProStack_Kadence_Hero::SCRIPT_ATTRS);
    }

    /**
     * The slider's unique ID and its first slide's, when both can go in a
     * CSS class name and another slide has a picture; none when not.
     *
     * @param array $block Parsed slider block.
     * @return string[]
     */
    private static function ids(array $block) {
        $slides = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? array_values($block['innerBlocks']) : array();
        $ids    = array(self::slider_id($block), self::usable_id($slides[0]['attrs']['uniqueID'] ?? ''));
        if ('' === $ids[0] || '' === $ids[1] || empty($slides[0]['blockName']) || 'kadence/slide' !== $slides[0]['blockName']) {
            return array();
        }
        foreach (array_slice($slides, 1) as $slide) {
            if (isset($slide['blockName']) && 'kadence/slide' === $slide['blockName'] && !empty($slide['attrs']['backgroundImg'][0]['img'])) {
                return $ids;
            }
        }
        return array();
    }

    /**
     * The slider's style: every slide's picture but the first slide's waits
     * until Splide shows the slide; once the first is in (NEAR), the slides
     * next to those shown fetch theirs too. Two rules, so a browser that
     * cannot read the second drops only that one.
     *
     * @param string $slider Slider's unique ID (letters, digits, - and _).
     * @param string $first  First slide's unique ID (letters, digits, - and _).
     * @return string
     */
    private static function style($slider, $first) {
        $slides = '.kb-advanced-slider-' . $slider . ':not(.' . self::ALL . ') .wp-block-kadence-slide:not(.kb-slide-' . $first . ',.is-active,.is-visible';
        $wrap   = ')>.kb-advanced-slide>.kb-advanced-slide-inner-wrap{background-image:none!important}';
        $css    = 'html.' . self::HIDE . ':not(.' . self::NEAR . ') ' . $slides . $wrap
            . 'html.' . self::HIDE . '.' . self::NEAR . ' ' . $slides . ',.is-next,.is-prev,.is-active+*,.is-visible+*' . $wrap;
        // The IDs are letters, digits, - and _ (ids()).
        return '<style class="seoprostack-slides" data-no-optimize="1">' . $css . '</style>';
    }

    /**
     * The script that turns the wait on, as the page is read; once a page,
     * whichever slider's copy runs first.
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
    var e = d.documentElement;
    // Once, as the page is read; left alone when run late by an optimiser.
    if (w.seoprostackSlides || 'loading' !== d.readyState || !e.classList || !d.querySelectorAll || !w.getComputedStyle) { return; }
    w.seoprostackSlides = 1;
    e.classList.add('sps-slides');
    function near() { e.classList.add('sps-slides-near'); }
    // The next slides fetch theirs once the first slide's picture is in
    // (the same address, so the browser's copy), or the page has loaded.
    d.addEventListener('DOMContentLoaded', function () {
        var f = d.querySelector('.wp-block-kadence-slider .wp-block-kadence-slide>.kb-advanced-slide>.kb-advanced-slide-inner-wrap'),
            m = f && /^url\(["']?([^"')]+)["']?\)$/.exec(w.getComputedStyle(f).backgroundImage), i;
        if (m) { i = new Image(); i.onload = i.onerror = near; i.src = m[1]; }
    });
    w.addEventListener('load', function () {
        near();
        // Splide marks the slide it shows: a slider with none by now shows
        // every slide's picture, as before.
        setTimeout(function () {
            Array.prototype.forEach.call(d.querySelectorAll('.wp-block-kadence-slider'), function (s) {
                if (!s.querySelector('.wp-block-kadence-slide.is-active')) { s.classList.add('sps-slides-all'); }
            });
        }, 1000);
    });
})(document, window);
JS;
        return wp_get_inline_script_tag($script, SEOProStack_Kadence_Hero::SCRIPT_ATTRS);
    }
}
