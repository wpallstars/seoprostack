<?php
/**
 * The background picture of the Kadence Blocks row a page opens with, at
 * the size each screen needs (GH#1221), for Image loading
 * (SEOProStack_Image_Loading::kadence_preload()).
 *
 * A row names one picture, often the full upload, so phones got it too.
 * How many pixels it paints depends on the row's box, which its content,
 * fonts and the screen set, so only the browser knows. When the picture
 * has smaller copies of the same proportions, a script after the row
 * measures it and takes the smallest copy at least as wide as the pixels
 * it paints, with 10% to spare for fonts and late styles, else the row's
 * own picture: never less sharp than before. The copy is preloaded at high
 * priority (its AVIF or WebP copy when pages name copies) and set on the
 * row, or on each slide of a background slider with its own picture's
 * copy. If the row grows later (fonts, a turned phone, a wider window), a
 * larger copy replaces it, never a smaller one.
 *
 * Only `background-size: cover` and `contain`; anything else keeps the
 * row's picture. Until the script after the row runs, a class on <html>
 * hides the row's background, so the full picture is not fetched as well.
 * A script adds the class, so without JavaScript nothing is hidden, and it
 * goes when the page is read (DOMContentLoaded) even if the script after
 * the row did not run. A script run late (delayed by an optimiser) does
 * nothing. Classic themes only: block themes render the content before the
 * head.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.150
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Kadence_Hero {

    /** Class on <html> that hides the row's background until it is chosen. */
    const HIDE = 'sps-hero';

    /**
     * Attributes that keep the scripts as written: not delayed, deferred or
     * combined by LiteSpeed Cache, Flying Scripts or Cloudflare Rocket
     * Loader.
     */
    const SCRIPT_ATTRS = array(
        'data-no-optimize' => '1',
        'data-no-defer'    => '1',
        'data-cfasync'     => 'false',
    );

    /** @var string Unique ID of the row whose script is still due. */
    private static $row = '';

    /**
     * Print the head's style and script for a row, when its first picture
     * has smaller copies. False when not, so the caller preloads the
     * picture as before.
     *
     * @param array   $attrs Attributes of the row the content opens with.
     * @param WP_Post $post  Post shown.
     * @return bool
     */
    public static function start(array $attrs, WP_Post $post) {
        $id = isset($attrs['uniqueID']) && is_string($attrs['uniqueID']) ? $attrs['uniqueID'] : '';
        if ('' !== self::$row || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) || (function_exists('wp_is_block_theme') && wp_is_block_theme())) {
            return false;
        }
        /**
         * Whether the background picture of the Kadence Blocks row a page
         * opens with comes at the size each screen needs. Off: every screen
         * gets the picture the row names.
         *
         * @param bool    $on   Default true.
         * @param WP_Post $post Post shown.
         */
        if (!apply_filters('seoprostack_kadence_hero_sizes', true, $post)) {
            return false;
        }
        // Kadence's default is cover; any other size keeps the early preload.
        $size = isset($attrs['bgImgSize']) && '' !== $attrs['bgImgSize'] ? $attrs['bgImgSize'] : 'cover';
        if ('cover' !== $size && 'contain' !== $size) {
            return false;
        }
        $slider   = isset($attrs['backgroundSettingTab']) && 'slider' === $attrs['backgroundSettingTab'];
        $pictures = $slider ? self::slides($attrs) : self::normal($attrs);
        if (!$pictures) {
            return false;
        }
        self::$row = $id;
        add_filter('render_block_kadence/rowlayout', array(__CLASS__, 'after_row'), 30, 2);
        self::head($id, $slider, $pictures);
        return true;
    }

    /**
     * The normal tab's picture, when it has smaller copies.
     *
     * @param array $attrs Row attributes.
     * @return array<int,array>
     */
    private static function normal(array $attrs) {
        $picture = self::picture(isset($attrs['bgImgID']) ? $attrs['bgImgID'] : 0, isset($attrs['bgImg']) ? $attrs['bgImg'] : '');
        return $picture && count($picture['c']) > 1 ? array($picture) : array();
    }

    /**
     * Each slide's picture, when the first slide's has smaller copies:
     * that one shows first. Slides whose picture cannot be read keep it.
     *
     * @param array $attrs Row attributes.
     * @return array<int,array>
     */
    private static function slides(array $attrs) {
        $slides = isset($attrs['backgroundSlider']) && is_array($attrs['backgroundSlider']) ? $attrs['backgroundSlider'] : array();
        // Kadence's default is one slide, and it shows no more than the count.
        $count = isset($attrs['backgroundSliderCount']) ? (int) $attrs['backgroundSliderCount'] : 1;
        $list  = array();
        $item  = 0;
        foreach ($slides as $key => $slide) {
            if (++$item > $count) {
                break;
            }
            if (!is_int($key) || !is_array($slide) || empty($slide['bgImg'])) {
                continue;
            }
            $picture = self::picture(isset($slide['bgImgID']) ? $slide['bgImgID'] : 0, $slide['bgImg']);
            if (!$picture && !$list) {
                return array();
            }
            if ($picture) {
                $picture['n'] = $key;
                $list[]       = $picture;
            }
        }
        return $list && 0 === $list[0]['n'] && count($list[0]['c']) > 1 ? $list : array();
    }

    /**
     * A picture's proportions and the files on offer, narrowest first: its
     * smaller copies of the same proportions, then the picture the row
     * names. Each is width, address, image-set() of its AVIF and WebP
     * copies (or ''), the address to preload when not the same, and that
     * one's MIME type. Addresses are the ones pages get, through Media in
     * cloud storage's mapping when it serves the picture. Null when the
     * address is not one of the attachment's files.
     *
     * @param mixed $id  Attachment ID.
     * @param mixed $url Address the row names.
     * @return array{w:int,h:int,c:array<int,array>}|null
     */
    private static function picture($id, $url) {
        $id = is_numeric($id) ? (int) $id : 0;
        if ($id < 1 || !self::css_url($url)) {
            return null;
        }
        $meta = wp_get_attachment_metadata($id);
        if (!is_array($meta) || empty($meta['file']) || empty($meta['width']) || empty($meta['height'])) {
            return null;
        }
        $sizes = isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : array();
        list($width, $height) = self::named($meta, $sizes, wp_basename(rawurldecode((string) wp_parse_url($url, PHP_URL_PATH))));
        if ($width < 1 || $height < 1) {
            return null;
        }
        $dir    = dirname((string) get_attached_file($id));
        $copies = array();
        foreach ($sizes as $size => $info) {
            $w = isset($info['width']) ? (int) $info['width'] : 0;
            $h = isset($info['height']) ? (int) $info['height'] : 0;
            // Narrower, and its height within a pixel of the same proportions.
            if ($w < 1 || $w >= $width || isset($copies[$w]) || empty($info['file']) || abs($h * $width - $w * $height) > $width) {
                continue;
            }
            $src = wp_get_attachment_image_src($id, (string) $size);
            if (is_array($src) && isset($src[0], $src[1]) && $w === (int) $src[1] && self::css_url($src[0]) && self::there($id, (string) $size, $dir . '/' . $info['file'], $src[0])) {
                $copies[$w] = self::file($w, $src[0]);
            }
        }
        ksort($copies);
        // The row's own picture from cloud storage too, when it is served
        // from there (Kadence prints the address saved in the content).
        if (class_exists('SEOProStack_Media_Storage')) {
            $mapped = SEOProStack_Media_Storage::attachment_url($url, $id);
            $url    = self::css_url($mapped) ? $mapped : $url;
        }
        $copies[] = self::file($width, $url);
        return array(
            'w' => $width,
            'h' => $height,
            'c' => array_values($copies),
        );
    }

    /**
     * Width and height of the file an address names: the attachment's own
     * file or one of its sizes. Zero when neither.
     *
     * @param array  $meta  Attachment metadata.
     * @param array  $sizes Its sizes.
     * @param string $name  File name in the address.
     * @return array{0:int,1:int}
     */
    private static function named(array $meta, array $sizes, $name) {
        if (wp_basename($meta['file']) === $name) {
            return array((int) $meta['width'], (int) $meta['height']);
        }
        foreach ($sizes as $info) {
            if (is_array($info) && isset($info['file'], $info['width'], $info['height']) && $info['file'] === $name) {
                return array((int) $info['width'], (int) $info['height']);
            }
        }
        return array(0, 0);
    }

    /**
     * Whether a size's file can be served: here, or in cloud storage when
     * Media in cloud storage serves it from there.
     *
     * @param int    $id   Attachment ID.
     * @param string $size Size name.
     * @param string $path Local file.
     * @param string $url  Address pages get for it.
     * @return bool
     */
    private static function there($id, $size, $path, $url) {
        if (is_file($path)) {
            return true;
        }
        // Mapped to the bucket only when the object is confirmed there.
        return class_exists('SEOProStack_Media_Storage') && SEOProStack_Media_Storage::local_url($id, $size) !== $url;
    }

    /**
     * One file on offer: width, address, image-set() of its copies, the
     * address to preload (when not the same) and its MIME type.
     *
     * @param int    $width Width.
     * @param string $url   Address.
     * @return array{0:int,1:string,2:string,3:string,4:string}
     */
    private static function file($width, $url) {
        $set   = '';
        $first = $url;
        $type  = '';
        if (class_exists('SEOProStack_Nextgen_Images')) {
            $set = SEOProStack_Nextgen_Images::background_set($url);
            list($first, $type) = SEOProStack_Nextgen_Images::first_copy($url);
        }
        return array($width, $url, $set, $first === $url ? '' : $first, $type);
    }

    /**
     * Whether an address can go in CSS's url('…') as it is.
     *
     * @param mixed $url Address.
     * @return bool
     */
    private static function css_url($url) {
        return is_string($url) && preg_match('#^(?:https?:)?//#i', $url) && !preg_match('/[\s\'"()<>\\\\]/', $url);
    }

    /**
     * The head's style, hiding the row's background while the class is on
     * <html>, and its script.
     *
     * @param string $id       Row's unique ID (letters, digits, - and _).
     * @param bool   $slider   Whether it is a background slider.
     * @param array  $pictures Pictures, as picture() gives them.
     */
    private static function head($id, $slider, array $pictures) {
        $selector = 'html.' . self::HIDE . ' .kb-row-layout-id' . $id . ($slider ? '>.kb-blocks-bg-slider .kb-bg-slide' : '');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the ID is letters, digits, - and _ (start()).
        echo '<style id="seoprostack-hero-css">' . $selector . '{background-image:none!important}</style>' . "\n";
        $data = array(
            'id' => $id,
            's'  => $slider,
            'p'  => $pictures,
        );
        $script = <<<'JS'
(function (d, w, h) {
    var e = d.documentElement;
    // Run as the page is read, or leave it alone (run late by an optimiser).
    if ('loading' !== d.readyState || !e.classList || !w.getComputedStyle) { return; }
    function show() { e.classList.remove('sps-hero'); }
    e.classList.add('sps-hero');
    // Whatever happens below, the row's own picture shows by then.
    d.addEventListener('DOMContentLoaded', show);
    w.seoprostackHero = function (s) {
        w.seoprostackHero = null;
        try { run(s); } finally { show(); }
    };
    function run(s) {
        var k = 'kb-row-layout-id' + h.id, row = s && s.previousElementSibling, box, first, size, i, c, l;
        if (!row || !row.classList.contains(k)) { row = d.querySelector('.' + k); }
        box = row;
        if (row && h.s) {
            for (box = row.firstElementChild; box && !box.classList.contains('kb-blocks-bg-slider'); box = box.nextElementSibling) {}
        }
        first = box && els(h.p[0])[0];
        size = first && w.getComputedStyle(first).backgroundSize;
        if ('cover' !== size && 'contain' !== size) { return; }
        function els(p) { return h.s ? box.querySelectorAll('.kb-bg-slide-' + p.n) : [row]; }
        // Device pixels the picture paints across, with 10% to spare.
        function need(p) {
            var b = box.getBoundingClientRect(), a = p.w / p.h;
            if (!b.width || !b.height) { return 0; }
            return ('cover' === size ? Math.max(b.width, b.height * a) : Math.min(b.width, b.height * a)) * (w.devicePixelRatio || 1) * 1.1;
        }
        // The narrowest file that is enough, else the row's own (the last).
        function pick(p) {
            var n = need(p), j = 0;
            if (!n) { return p.c.length - 1; }
            while (j < p.c.length - 1 && p.c[j][0] < n) { j++; }
            return j;
        }
        function set(p, j) {
            var f = p.c[j], list = els(p), m;
            p.i = j;
            for (m = 0; m < list.length; m++) {
                list[m].style.setProperty('background-image', "url('" + f[1] + "')", 'important');
                // Browsers without image-set() types keep the url().
                if (f[2]) { list[m].style.setProperty('background-image', f[2], 'important'); }
            }
        }
        for (i = 0; i < h.p.length; i++) { set(h.p[i], pick(h.p[i])); }
        c = h.p[0].c[h.p[0].i];
        l = d.createElement('link');
        l.rel = 'preload';
        l.as = 'image';
        if (c[4]) { l.type = c[4]; }
        l.setAttribute('fetchpriority', 'high');
        l.href = c[3] || c[1];
        d.head.appendChild(l);
        // Larger when the row grows; never smaller.
        if (w.ResizeObserver) {
            new w.ResizeObserver(function () {
                for (var q = 0, j; q < h.p.length; q++) {
                    j = pick(h.p[q]);
                    if (j > h.p[q].i) { set(h.p[q], j); }
                }
            }).observe(box);
        }
    }
})(document, window, SPS_HERO_DATA);
JS;
        // Not sprintf(): the script has % signs.
        wp_print_inline_script_tag(
            str_replace('SPS_HERO_DATA', (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG), $script),
            array('id' => 'seoprostack-hero') + self::SCRIPT_ATTRS
        );
    }

    /**
     * After the row, in the post's content: the script that measures it and
     * sets its picture. Once.
     *
     * @param mixed $html  Block HTML.
     * @param array $block Parsed block.
     * @return mixed
     */
    public static function after_row($html, $block) {
        if ('' === self::$row || !is_string($html) || !isset($block['attrs']['uniqueID']) || self::$row !== $block['attrs']['uniqueID'] || !doing_filter('the_content')) {
            return $html;
        }
        self::$row = '';
        return $html . wp_get_inline_script_tag('window.seoprostackHero&&seoprostackHero(document.currentScript)', self::SCRIPT_ATTRS);
    }
}
