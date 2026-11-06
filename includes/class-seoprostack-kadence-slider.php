<?php
/**
 * Kadence Blocks Pro Advanced Slider: only the slides shown fetch their
 * pictures, and the next ones once the first is in (GH#1312), for Image
 * loading (SEOProStack_Image_Loading).
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

    /**
     * Register hooks.
     */
    public static function boot() {
        add_filter('render_block_kadence/slider', array(__CLASS__, 'before_slider'), 30, 2);
    }

    /**
     * Before a slider on a front-end page: the style that keeps its other
     * slides' pictures waiting, and the script that turns it on.
     *
     * @param mixed $html  Block HTML.
     * @param array $block Parsed block.
     * @return mixed
     */
    public static function before_slider($html, $block) {
        if (!is_string($html) || '' === $html || !is_array($block) || !self::front_page()) {
            return $html;
        }
        $ids = self::ids($block);
        if (!$ids || false === strpos($html, 'kb-advanced-slider-' . $ids[0]) || false === strpos($html, 'kb-slide-' . $ids[1])) {
            return $html;
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
            return $html;
        }
        return self::style($ids[0], $ids[1]) . self::script() . $html;
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
     * The slider's unique ID and its first slide's, when both can go in a
     * CSS class name and another slide has a picture; none when not.
     *
     * @param array $block Parsed slider block.
     * @return string[]
     */
    private static function ids(array $block) {
        $slides = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? array_values($block['innerBlocks']) : array();
        $first  = $slides[0]['attrs']['uniqueID'] ?? '';
        $ids    = array($block['attrs']['uniqueID'] ?? '', $first);
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                return array();
            }
        }
        if (empty($slides[0]['blockName']) || 'kadence/slide' !== $slides[0]['blockName']) {
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
