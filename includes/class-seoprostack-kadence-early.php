<?php
/**
 * The first picture of the Kadence Blocks row or Advanced Slider a page
 * opens with, fetched from the head (GH#1337), for Image loading
 * (SEOProStack_Image_Loading::kadence_preload()).
 *
 * The scripts that choose the copy each screen needs (SEOProStack_Kadence_Hero,
 * GH#1221; SEOProStack_Kadence_Slider, GH#1333) run after the row or slider,
 * where they can measure it. That is as far into the page as the parser
 * has to read first: on a phone, a Kadence home page's picture was asked
 * for about 400 ms after its logo, and then shared the line with every
 * stylesheet and picture asked for meanwhile.
 *
 * A script in the head, before any stylesheet, works out the fewest pixels
 * the picture can paint on this screen, from what the block itself says:
 * the screen's width (only for a full-width row or slider, as wide as the
 * screen) and a height the block sets for this screen size (Kadence's
 * desktop, tablet and phone sizes): the slider's ratio or fixed height, or
 * the row's minimum height, in px, vh or vw. Its content can only make it
 * taller, so a `cover` picture paints at least that many pixels, and a
 * `contain` one too. It preloads the narrowest copy at least that wide (with
 * the 10% to spare the other scripts use) and records which.
 *
 * The script after the row or slider then measures it, as before: if the
 * head's copy is at least the one it would choose, it uses that one, so the
 * picture is fetched once; if not, it chooses as before, never less sharp.
 * When the block sets no height for this screen size (a row as tall as its
 * content), the head preloads nothing and all stays as before.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 * @since 1.3.171
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-seoprostack-hero-picture.php';
require_once __DIR__ . '/class-seoprostack-kadence-hero.php';

class SEOProStack_Kadence_Early {

    /** Height units the head can work out (Kadence also offers em, rem and %). */
    const UNITS = array('px', 'vh', 'vw');

    /**
     * The head's script for a row the page opens with: '' when the row is
     * not full width, sets no height, or the filter says no.
     *
     * @param string $id      Row's unique ID (letters, digits, - and _).
     * @param array  $attrs   Row attributes.
     * @param array  $picture Its first picture, as SEOProStack_Hero_Picture::get() gives it.
     * @return string
     */
    public static function row($id, array $attrs, array $picture) {
        if ('full' !== ($attrs['align'] ?? '') || !self::wanted($attrs)) {
            return '';
        }
        $unit = ($attrs['minHeightUnit'] ?? '') ?: 'px';
        $desk = self::height($attrs['minHeight'] ?? '', $unit);
        $tab  = is_numeric($attrs['minHeightTablet'] ?? '') ? self::height($attrs['minHeightTablet'], $unit) : $desk;
        $mob  = is_numeric($attrs['minHeightMobile'] ?? '') ? self::height($attrs['minHeightMobile'], $unit) : $tab;
        $mode = 'contain' === ($attrs['bgImgSize'] ?? '') ? 'contain' : 'cover';
        return self::script('r' . $id, $picture, array($desk, $tab, $mob), $mode);
    }

    /**
     * The head's script for an Advanced Slider the page opens with: '' when
     * it is not a full-width slider of one slide at a time, or the filter
     * says no.
     *
     * @param string $id      Slider's unique ID (letters, digits, - and _).
     * @param array  $attrs   Slider attributes.
     * @param array  $picture Its first slide's picture, as SEOProStack_Hero_Picture::get() gives it.
     * @param string $size    That slide's background size.
     * @return string
     */
    public static function slider($id, array $attrs, array $picture, $size) {
        if ('full' !== ($attrs['align'] ?? '') || 'slider' !== (($attrs['sliderType'] ?? '') ?: 'slider') || self::side_padding($attrs) || !self::wanted($attrs)) {
            return '';
        }
        if ('fixed' === ($attrs['heightType'] ?? '')) {
            $list = isset($attrs['minHeight']) && is_array($attrs['minHeight']) ? $attrs['minHeight'] : array(500);
            $unit = ($attrs['heightUnit'] ?? '') ?: 'px';
            $desk = self::height($list[0] ?? '', $unit);
            $tab  = is_numeric($list[1] ?? '') ? self::height($list[1], $unit) : $desk;
            $mob  = is_numeric($list[2] ?? '') ? self::height($list[2], $unit) : $tab;
        } else {
            $list = isset($attrs['sliderRatio']) && is_array($attrs['sliderRatio']) ? $attrs['sliderRatio'] : array();
            $desk = self::ratio(($list[0] ?? '') ?: '12-5');
            $tab  = in_array($list[1] ?? '', array('', 'inherit'), true) ? $desk : self::ratio($list[1]);
            $mob  = in_array($list[2] ?? '', array('', 'inherit'), true) ? $tab : self::ratio($list[2]);
        }
        return self::script('s' . $id, $picture, array($desk, $tab, $mob), 'contain' === $size ? 'contain' : 'cover');
    }

    /**
     * Whether the head may fetch the picture; the filter.
     *
     * @param array $attrs Block attributes.
     * @return bool
     */
    private static function wanted(array $attrs) {
        if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            return false;
        }
        /**
         * Whether the first picture of the Kadence Blocks row or Advanced
         * Slider a page opens with is fetched from the head, at the fewest
         * pixels it can paint, when the block sets its height. Off: the
         * script after the block fetches it once it has measured the block.
         *
         * @param bool  $on    Default true.
         * @param array $attrs Block attributes.
         */
        return (bool) apply_filters('seoprostack_kadence_head_preload', true, $attrs);
    }

    /**
     * Whether a slider sets padding at its sides on any screen size, so its
     * slides are narrower than the screen.
     *
     * @param array $attrs Slider attributes.
     * @return bool
     */
    private static function side_padding(array $attrs) {
        foreach ((array) ($attrs['padding'][0] ?? array()) as $sides) {
            if (is_array($sides) && ('' !== (string) ($sides[1] ?? '') || '' !== (string) ($sides[3] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    /**
     * A height the head can work out: [unit, value], or 0 when none.
     *
     * @param mixed  $value Height.
     * @param string $unit  Its unit.
     * @return array{0:string,1:float}|int
     */
    private static function height($value, $unit) {
        return is_numeric($value) && (float) $value > 0 && in_array($unit, self::UNITS, true) ? array($unit, (float) $value) : 0;
    }

    /**
     * A slider ratio as Kadence names it (width-height, such as 16-9): its
     * height for each pixel of width, as ['r', value], or 0 when not read.
     *
     * @param mixed $ratio Ratio.
     * @return array{0:string,1:float}|int
     */
    private static function ratio($ratio) {
        if (!is_string($ratio) || !preg_match('/^([1-9]\d?)-([1-9]\d?)$/', $ratio, $m)) {
            return 0;
        }
        return array('r', round((int) $m[2] / (int) $m[1], 4));
    }

    /**
     * The head's script: '' when the block sets no height on any screen size.
     *
     * @param string $key     Block's key: r or s, then its unique ID.
     * @param array  $picture Picture, as SEOProStack_Hero_Picture::get() gives it.
     * @param array  $boxes   Height on desktop, tablet and phone: [unit, value] or 0.
     * @param string $mode    cover or contain.
     * @return string
     */
    private static function script($key, array $picture, array $boxes, $mode) {
        if (!array_filter($boxes) || count($picture['c']) < 2) {
            return '';
        }
        $data   = array(
            'k' => $key,
            'z' => $mode,
            'b' => $boxes,
            // Each file as url(…), so LiteSpeed Cache's WebP replacement
            // names its copy here too, as in the other scripts (GH#1333).
            'p' => SEOProStack_Hero_Picture::for_script($picture),
        );
        $script = <<<'JS'
(function (d, w, h) {
    var e = d.documentElement, m = w.matchMedia, b, v, t, n, j, f, l;
    // As the page is read, before any stylesheet; left alone when run late.
    if ('loading' !== d.readyState || !m || !d.head || !e.clientWidth) { return; }
    // Kadence's screen sizes: desktop, tablet (to 1024px), phone (to 767px).
    b = h.b[m('(max-width: 767px)').matches ? 2 : (m('(max-width: 1024px)').matches ? 1 : 0)];
    if (!b) { return; }
    v = e.clientWidth;
    // 100vh as CSS has it: on phones, taller than the window while the
    // address bar shows. Measured on an empty element, before the page has
    // any other to lay out.
    function vh() {
        var x = d.createElement('div'), y;
        x.style.cssText = 'position:absolute;top:0;left:0;width:0;height:100vh;visibility:hidden';
        e.appendChild(x);
        y = x.offsetHeight || e.clientHeight;
        e.removeChild(x);
        return y;
    }
    t = 'r' === b[0] ? v * b[1] : ('vh' === b[0] ? vh() * b[1] / 100 : ('vw' === b[0] ? v * b[1] / 100 : b[1]));
    // The fewest device pixels the picture paints across, with 10% to spare.
    n = ('contain' === h.z ? Math.min(v, t * h.p.w / h.p.h) : Math.max(v, t * h.p.w / h.p.h)) * (w.devicePixelRatio || 1) * 1.1;
    for (j = 0; j < h.p.c.length - 1 && h.p.c[j][0] < n; j++) {}
    f = h.p.c[j];
    l = d.createElement('link');
    l.rel = 'preload';
    l.as = 'image';
    if (f[4]) { l.type = f[4]; }
    l.setAttribute('fetchpriority', 'high');
    // f[1] is url(…): the address inside it.
    l.href = f[3] || f[1].slice(4, -1);
    d.head.appendChild(l);
    w.seoprostackEarly = { k: h.k, i: j };
})(document, window, SPS_EARLY_DATA);
JS;
        // Not sprintf(): the script has % signs.
        return wp_get_inline_script_tag(
            str_replace('SPS_EARLY_DATA', (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP), $script),
            array('id' => 'seoprostack-early') + SEOProStack_Kadence_Hero::SCRIPT_ATTRS
        );
    }
}
