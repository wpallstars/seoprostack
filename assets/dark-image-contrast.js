/**
 * Dark mode image contrast.
 *
 * While the body has color-switch-dark (the Kadence Pro dark mode switcher),
 * finds transparent logos, icons and line art that are hard to see on the
 * background they sit on, and marks them with data-seoprostack-dark, which a
 * CSS filter scoped to dark mode recolours. Switching back to light restores
 * them at once, with no script.
 *
 * - Only images with transparency are measured: JPEGs, images that already
 *   have a CSS filter, cross-origin images that cannot be read, images with
 *   under 5% transparent pixels (photos, logos on a white box) and images
 *   with many colours (cut-out photos, gradients) are left alone.
 * - Only visible pixels count, so transparent margins in the file change
 *   nothing.
 * - The background is the one really behind the image: its own background
 *   (which also fills its padding), then each ancestor's, with translucent
 *   colours, even gradients and absolutely positioned overlays (Kadence row
 *   overlays, Cover block backgrounds) composited in. A background picture or
 *   video, or a colour it cannot read, leaves the image alone.
 * - Only images with most (60%) of their visible pixels under 3:1 contrast
 *   (WCAG 1.4.11) against it change; the theme's own dark mode image filter
 *   (Kadence Pro's brightness and contrast) is allowed for. Each filter is
 *   tried on the sampled pixels; one is used only when it makes most of the
 *   image reach 3:1 and clearly improves on the original. Inverting with the
 *   hue kept comes first, so brand colours stay recognisable, then white,
 *   then black, which are skipped for images with detail inside their shape
 *   (text in a box). It replaces the theme's filter on that image. Mostly
 *   filled images (banners, badges) with a clear part are left alone.
 * - Watermarks (unless window.seoprostackDarkImageContrast.watermarks is
 *   false): transparent images drawn under 75% opacity, as an <img> or a
 *   background of an element or its ::before or ::after (Kadence row and
 *   column overlays), are kept at watermark-level contrast (1.2:1 at most)
 *   by lowering their opacity, inverted first (hue kept) when they have all
 *   but vanished. Marked with data-seoprostack-watermark and a
 *   --seoprostack-wm-* opacity, applied in dark mode only.
 *
 * Opt out: the class seoprostack-keep-colours on the image or any parent.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 */
(function () {
    'use strict';

    var body = document.body;
    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext?.('2d', { willReadFrequently: true });
    if (!body || !ctx || !window.getComputedStyle || !window.WeakMap) {
        return;
    }

    var ATTR = 'data-seoprostack-dark';
    var KEEP = '.seoprostack-keep-colours';
    var AREA = 16384; // Pixels sampled at most (128 x 128).
    var MIN_RATIO = 3; // WCAG 1.4.11 non-text contrast.
    var FILTERS = ['invert', 'white', 'black'];
    var state = new WeakMap(); // img -> { src, px }.
    var seen = new WeakMap(); // img -> true once near the window.
    var probe = document.createElement('img'); // For the base filter.
    var timer = 0;

    var WM_ATTR = 'data-seoprostack-watermark';
    var WM_OPACITY = 0.75; // Drawn under this opacity on purpose: a watermark.
    var WM_MAX = 1.2; // Watermark-level contrast, at most.
    var WM_MIN = 1.08; // Under this, a watermark has all but vanished.
    var NOT_WATERMARKS = /^(img|script|style|link|meta|noscript|template|br|svg|path|g|use|source|option)$/i;
    var config = window.seoprostackDarkImageContrast || {};
    var watermarks = false !== config.watermarks;
    var originals = new WeakMap(); // el -> { part: opacity before changes }.
    var tints = new Map(); // Background image address -> Promise of its tint.
    var pending = []; // Elements waiting for the watermark look.
    var scanning = false;
    var idle = window.requestIdleCallback || function (fn) {
        return setTimeout(fn, 50);
    };

    // Relative luminance of each sRGB channel value.
    var LIN = [];
    for (var v = 0; v < 256; v++) {
        var c = v / 255;
        LIN.push(c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
    }

    function clamp(x) {
        return Math.min(255, Math.max(0, Math.round(x)));
    }

    function lum(r, g, b) {
        return 0.2126 * LIN[clamp(r)] + 0.7152 * LIN[clamp(g)] + 0.0722 * LIN[clamp(b)];
    }

    function ratio(a, b) {
        return a > b ? (a + 0.05) / (b + 0.05) : (b + 0.05) / (a + 0.05);
    }

    function isDark() {
        return body.classList.contains('color-switch-dark');
    }

    /** An alpha value such as "0.5" or "50%"; none is opaque. */
    function alpha(str) {
        if (undefined === str) {
            return 1;
        }
        var n = Number.parseFloat(str);
        return str.endsWith('%') ? n / 100 : n;
    }

    /**
     * Parse a computed colour, rgb(), rgba() or color(srgb …), into
     * [r, g, b, a], or null when unreadable.
     */
    function parse(str) {
        var m = /^(rgba?|color)\(([^()]*)\)$/.exec(str);
        if (!m) {
            return null;
        }
        var parts = m[2].replace('/', ' ').split(/[\s,]+/).filter(Boolean);
        var scale = 1;
        if ('color' === m[1]) {
            if ('srgb' !== parts.shift()) {
                return null;
            }
            scale = 255;
        }
        if (parts.length < 3 || parts.length > 4) {
            return null;
        }
        var out = [Number(parts[0]) * scale, Number(parts[1]) * scale, Number(parts[2]) * scale, alpha(parts[3])];
        return out.some(Number.isNaN) ? null : out;
    }

    /**
     * Average colour of a gradient whose colours are close in lightness,
     * or null when it cannot stand for one colour.
     */
    function gradient(str) {
        if (/url\(|(?:oklch|oklab|lab|lch|hsl|hwb|color)\(/i.test(str)) {
            return null;
        }
        var found = str.match(/rgba?\([^)]*\)/g);
        if (!found) {
            return null;
        }
        var sum = [0, 0, 0, 0];
        var lo = 1;
        var hi = 0;
        for (const item of found) {
            var col = parse(item);
            if (!col) {
                return null;
            }
            var l = lum(col[0], col[1], col[2]);
            lo = Math.min(lo, l);
            hi = Math.max(hi, l);
            for (var j = 0; j < 4; j++) {
                sum[j] += col[j];
            }
        }
        if (ratio(lo, hi) > 1.6) {
            return null;
        }
        return sum.map(function (x) {
            return x / found.length;
        });
    }

    /**
     * Add an element's background to the layers (nearest first).
     * Returns -1 when unknown, 1 when it is opaque, 0 otherwise.
     */
    function addLayers(layers, cs, opacity) {
        if ('text' === cs.backgroundClip || 'text' === cs.webkitBackgroundClip) {
            return 0;
        }
        var colour = parse(cs.backgroundColor);
        if (!colour) {
            return -1;
        }
        var opaque = 0;
        if (cs.backgroundImage && 'none' !== cs.backgroundImage) {
            var grad = gradient(cs.backgroundImage);
            if (!grad) {
                return -1;
            }
            grad[3] *= opacity;
            if (grad[3] > 0) {
                layers.push(grad);
                opaque = grad[3] >= 0.999 ? 1 : 0;
            }
        }
        colour[3] *= opacity;
        if (!opaque && colour[3] > 0) {
            layers.push(colour);
            opaque = colour[3] >= 0.999 ? 1 : 0;
        }
        return opaque;
    }

    /**
     * Absolutely positioned overlays among an element's children (other
     * than the one the image is in) that cover the image's centre.
     */
    function addOverlays(layers, parent, skip, x, y) {
        for (var n = parent.firstElementChild; n; n = n.nextElementSibling) {
            if (n === skip) {
                continue;
            }
            var cs = getComputedStyle(n);
            var opacity = Number.parseFloat(cs.opacity);
            var shown = opacity > 0; // False for NaN too.
            if ('absolute' !== cs.position || 'none' === cs.display || 'hidden' === cs.visibility || !shown) {
                continue;
            }
            var r = n.getBoundingClientRect();
            if (x < r.left || x > r.right || y < r.top || y > r.bottom) {
                continue;
            }
            if (/^(img|video|picture|iframe|canvas|svg)$/i.test(n.tagName) || n.querySelector('img,video,picture,iframe,canvas')) {
                return -1;
            }
            var found = addLayers(layers, cs, opacity);
            if (0 !== found) {
                return found;
            }
        }
        return 0;
    }

    /**
     * The colour behind the image, or null when unknown. From: the element
     * whose background is the first one under it (default: the image's own).
     */
    function backdrop(img, from) {
        var rect = img.getBoundingClientRect();
        var x = rect.left + rect.width / 2;
        var y = rect.top + rect.height / 2;
        var layers = [];
        var child = null;
        var found = 0;
        for (var el = from || img; 1 === el?.nodeType && !found; el = el.parentElement) {
            if (child) {
                found = addOverlays(layers, el, child, x, y);
            }
            if (!found) {
                found = addLayers(layers, getComputedStyle(el), 1);
            }
            child = el;
        }
        if (found < 0) {
            return null;
        }
        // Nothing opaque: the browser's canvas, white for a light <html>.
        var out = [255, 255, 255];
        for (var i = layers.length - 1; i >= 0; i--) {
            var a = layers[i][3];
            for (var j = 0; j < 3; j++) {
                out[j] = out[j] * (1 - a) + layers[i][j] * a;
            }
        }
        return out;
    }

    /**
     * The image's pixels, at most AREA of them, as { data, cw, ch }, or null
     * when they cannot be read.
     */
    function sample(img) {
        var w = img.naturalWidth || img.clientWidth;
        var h = img.naturalHeight || img.clientHeight;
        if (!w || !h) {
            return null;
        }
        var s = Math.min(1, Math.sqrt(AREA / (w * h)));
        var cw = Math.max(1, Math.round(w * s));
        var ch = Math.max(1, Math.round(h * s));
        canvas.width = cw;
        canvas.height = ch;
        ctx.clearRect(0, 0, cw, ch);
        try {
            ctx.drawImage(img, 0, 0, cw, ch);
            return { data: ctx.getImageData(0, 0, cw, ch).data, cw: cw, ch: ch };
        } catch (e) {
            // Expected for images from other sites without CORS, or not
            // decodable: their pixels cannot be read, so leave them alone.
            return null;
        }
    }

    /**
     * Visible pixels [r, g, b, a, ...] of a transparent, flat-coloured
     * image, with its transparent share as .clear, or null to leave it alone.
     */
    function pixels(img) {
        var got = sample(img);
        if (!got) {
            return null;
        }
        var data = got.data;
        var cw = got.cw;
        var ch = got.ch;
        var clear = 0;
        var px = [];
        var buckets = {};
        for (var i = 0; i < data.length; i += 4) {
            var a = data[i + 3];
            if (a < 16) {
                clear++;
            } else if (a >= 128) {
                px.push(data[i], data[i + 1], data[i + 2], a);
                var k = ((data[i] >> 5) << 6) | ((data[i + 1] >> 5) << 3) | (data[i + 2] >> 5);
                buckets[k] = (buckets[k] || 0) + 1;
            }
        }
        var n = px.length / 4;
        if (clear < cw * ch * 0.05 || n < 16) {
            return null;
        }
        // Flat colours: a few colours cover most of it.
        var counts = Object.values(buckets);
        counts.sort(function (p, q) {
            return q - p;
        });
        var top = 0;
        for (var t = 0; t < counts.length && t < 12; t++) {
            top += counts[t];
        }
        if (top < n * 0.8) {
            return null;
        }
        px.clear = clear / (cw * ch);
        px.detail = detail(data, cw, ch);
        return px;
    }

    /**
     * Whether the image has detail inside its shape (text in a box, an
     * outline round a fill): strong edges between visible pixels, at least a
     * quarter as many as edges against transparency. A white or black
     * silhouette would wipe that detail out.
     */
    function detail(data, cw, ch) {
        var inner = 0;
        var outer = 0;
        function edge(i, j) {
            var a = data[i + 3];
            var b = data[j + 3];
            if (a >= 128 && b >= 128) {
                if (ratio(lum(data[i], data[i + 1], data[i + 2]), lum(data[j], data[j + 1], data[j + 2])) >= MIN_RATIO) {
                    inner++;
                }
            } else if ((a >= 128 && b < 16) || (a < 16 && b >= 128)) {
                outer++;
            }
        }
        for (var y = 0; y < ch; y++) {
            for (var x = 0; x < cw; x++) {
                var i = (y * cw + x) * 4;
                if (x + 1 < cw) {
                    edge(i, i + 4);
                }
                if (y + 1 < ch) {
                    edge(i, i + cw * 4);
                }
            }
        }
        return inner >= outer * 0.25;
    }

    /**
     * The filter every image gets in this mode, such as Kadence Pro's
     * brightness(0.9) contrast(1.2) in dark mode, read from a hidden image
     * with no classes. An image with another filter has one of its own.
     */
    function baseFilter() {
        return getComputedStyle(probe).filter;
    }

    /** Brightness and contrast steps of a filter; others are ignored. */
    function steps(filter) {
        var out = [];
        var re = /(brightness|contrast)\(([\d.]+)(%?)\)/g;
        var m;
        while ((m = re.exec(filter))) {
            out.push([m[1], alpha(m[2] + m[3])]);
        }
        return out;
    }

    /**
     * A pixel after a filter, as CSS filter functions do it (sRGB). With no
     * name, the base filter's steps apply.
     */
    function filtered(name, r, g, b, base) {
        if ('' === name) {
            var p = [r, g, b];
            for (const step of base) {
                for (var j = 0; j < 3; j++) {
                    p[j] = clamp('brightness' === step[0] ? p[j] * step[1] : (p[j] - 127.5) * step[1] + 127.5);
                }
            }
            return p;
        }
        if ('white' === name) {
            return [255, 255, 255];
        }
        if ('black' === name) {
            return [0, 0, 0];
        }
        if ('invert' === name) {
            // invert(1) then hue-rotate(180deg).
            r = 255 - r;
            g = 255 - g;
            b = 255 - b;
            return [
                -0.574 * r + 1.43 * g + 0.144 * b,
                0.426 * r + 0.43 * g + 0.144 * b,
                0.426 * r + 1.43 * g - 0.856 * b
            ];
        }
        return [r, g, b];
    }

    /** Share of visible pixels below 3:1 against the background. */
    function lowShare(px, bg, name, base) {
        var bgLum = lum(bg[0], bg[1], bg[2]);
        var low = 0;
        for (var i = 0; i < px.length; i += 4) {
            var a = px[i + 3] / 255;
            var f = filtered(name, px[i], px[i + 1], px[i + 2], base);
            var l = lum(
                clamp(f[0]) * a + bg[0] * (1 - a),
                clamp(f[1]) * a + bg[1] * (1 - a),
                clamp(f[2]) * a + bg[2] * (1 - a)
            );
            if (ratio(l, bgLum) < MIN_RATIO) {
                low++;
            }
        }
        return low / (px.length / 4);
    }

    /** The filter to use, or '' for none. */
    function choose(px, bg) {
        var before = lowShare(px, bg, '', steps(baseFilter()));
        // A clear part already shows (a white book in a montage): recolouring
        // would spoil it. Only mostly hidden images.
        if (before < 0.6) {
            return '';
        }
        // Mostly filled, such as a banner or badge, with some of it clear
        // (white text on coloured bands): it carries its own contrast.
        if (px.clear < 0.4 && before <= 0.9) {
            return '';
        }
        var best = '';
        var bestShare = before;
        for (const name of FILTERS) {
            if (px.detail && 'invert' !== name) {
                continue; // A silhouette would hide the detail.
            }
            var share = lowShare(px, bg, name, null);
            if (share <= 0.2) {
                best = name;
                bestShare = share;
                break;
            }
            if (share < bestShare) {
                best = name;
                bestShare = share;
            }
        }
        return best && bestShare <= before - 0.25 ? best : '';
    }

    function eligible(img) {
        if (img.closest(KEEP) || img.classList.contains('kadence-dark-mode-logo')) {
            return false;
        }
        var pic = img.parentElement;
        if ('PICTURE' === pic?.tagName && pic.querySelector('source[media*="prefers-color-scheme"]')) {
            return false;
        }
        var src = img.currentSrc || img.src || '';
        return !/(?:^data:image\/jpe?g)|(?:\.jpe?g(?:$|[?#]))/i.test(src);
    }

    function check(img) {
        if (!isDark() || !img.complete) {
            return; // The load listener checks it again.
        }
        var src = img.currentSrc || img.src || '';
        var saved = state.get(img);
        if (saved && saved.src === src) {
            if (saved.done) {
                mark(img, saved);
            }
            return;
        }
        saved = { src: src, px: null, done: false };
        state.set(img, saved);
        // Unmarked first: a filter of its own means hands off.
        img.removeAttribute(ATTR);
        var own = getComputedStyle(img).filter;
        if (!src || !eligible(img) || ('none' !== own && baseFilter() !== own)) {
            saved.done = true;
            return;
        }
        // Fading in (lazy loaders): judge it at the opacity it ends at.
        var fadeIn = watermarks ? fading(img, function () {
            check(img);
        }) : 0;
        if (1 === fadeIn) {
            state.delete(img);
            return;
        }
        // Drawn faintly on purpose: a watermark, kept faint (below).
        saved.opacity = original(img, 'self', getComputedStyle(img));
        saved.faint = watermarks && 0 === fadeIn && faint(saved.opacity);
        // A loaded image may not be decoded yet; drawn then, it is blank.
        var run = function () {
            if (state.get(img) === saved) {
                if (saved.faint) {
                    saved.tint = tint(img);
                } else {
                    saved.px = pixels(img);
                }
                saved.done = true;
                mark(img, saved);
            }
        };
        if (img.decode) {
            img.decode().then(run, run);
        } else {
            run();
        }
    }

    /** Mark the image with the filter it needs, if any, for this mode. */
    function mark(img, saved) {
        if (!isDark()) {
            return;
        }
        if (saved.faint) {
            fade(img, 'self', saved.tint, saved.opacity, true);
            return;
        }
        var bg = saved.px ? backdrop(img) : null;
        var name = bg ? choose(saved.px, bg) : '';
        if (name) {
            if (img.getAttribute(ATTR) !== name) {
                img.setAttribute(ATTR, name);
            }
        } else if (img.hasAttribute(ATTR)) {
            img.removeAttribute(ATTR);
        }
    }

    /*
     * Watermarks: images drawn faintly on purpose, behind content. Light on
     * a light page, they stand out on a dark one (or vanish, if dark). In
     * dark mode they are kept at watermark-level contrast: their opacity is
     * lowered, after inverting them (hue kept) when they have all but
     * vanished. A watermark is a single url() background of an element or
     * its ::before or ::after (Kadence row and column overlays), or an <img>,
     * drawn under WM_OPACITY. Its colour is the average of its visible
     * pixels; images from other sites that cannot be read are left alone.
     */

    /** Drawn faintly on purpose: visible, but under WM_OPACITY. */
    function faint(opacity) {
        return opacity > 0 && opacity < WM_OPACITY;
    }

    /**
     * Opacity of an element (part 'self') or its ::before or ::after before
     * any change here. Only a watermark's is remembered (it is the one
     * changed); others are read again next time, as they may still change.
     */
    function original(el, part, cs) {
        var saved = originals.get(el);
        if (saved && part in saved) {
            return saved[part];
        }
        var o = Number.parseFloat(cs.opacity);
        o = Number.isNaN(o) ? 1 : o;
        if (faint(o)) {
            if (!saved) {
                saved = {};
                originals.set(el, saved);
            }
            saved[part] = o;
        }
        return o;
    }

    /**
     * Opacity transitions or animations running on an element or its
     * ::before or ::after (fading in): 0 for none; 1 for ones that end, with
     * done called then; 2 for endless ones, never a watermark.
     */
    function fading(el, done) {
        if (!el.getAnimations) {
            return 0;
        }
        var running = el.getAnimations({ subtree: true }).filter(function (a) {
            var fx = a.effect;
            return fx && fx.target === el && 'running' === a.playState && fx.getKeyframes().some(function (k) {
                return 'opacity' in k;
            });
        });
        if (!running.length) {
            return 0;
        }
        if (running.some(function (a) {
            return Infinity === a.effect.getComputedTiming().endTime;
        })) {
            return 2;
        }
        Promise.all(running.map(function (a) {
            return a.finished;
        })).then(done, done);
        return 1;
    }

    /**
     * Average colour and opacity [r, g, b, a] of a transparent image's
     * visible pixels, or null for an image without transparency or
     * unreadable.
     */
    function tint(img) {
        var got = sample(img);
        if (!got) {
            return null;
        }
        var data = got.data;
        var sum = [0, 0, 0, 0];
        var n = 0;
        for (var i = 0; i < data.length; i += 4) {
            var a = data[i + 3];
            if (a >= 16) {
                n++;
                sum[0] += data[i] * a;
                sum[1] += data[i + 1] * a;
                sum[2] += data[i + 2] * a;
                sum[3] += a;
            }
        }
        var all = got.cw * got.ch;
        if (n < 16 || all - n < all * 0.05) {
            return null;
        }
        return [sum[0] / sum[3], sum[1] / sum[3], sum[2] / sum[3], sum[3] / n / 255];
    }

    /** The tint of a background image, loaded once per address. */
    function urlTint(url) {
        if (!tints.has(url)) {
            tints.set(url, new Promise(function (resolve) {
                var im = new Image();
                im.onload = function () {
                    var done = function () {
                        resolve(tint(im));
                    };
                    if (im.decode) {
                        im.decode().then(done, done);
                    } else {
                        done();
                    }
                };
                im.onerror = function () {
                    resolve(null);
                };
                im.src = url;
            }));
        }
        return tints.get(url);
    }

    /**
     * How to show a watermark of colour c at strength k (its opacity times
     * its pixels' own) on bg: { name, scale }, a filter ('' or 'invert') and
     * a factor for its opacity.
     */
    function plan(c, k, bg, base, canInvert) {
        var bgLum = lum(bg[0], bg[1], bg[2]);
        function shown(name, scale) {
            var f = filtered(name, c[0], c[1], c[2], base);
            var a = k * scale;
            return ratio(lum(
                clamp(f[0]) * a + bg[0] * (1 - a),
                clamp(f[1]) * a + bg[1] * (1 - a),
                clamp(f[2]) * a + bg[2] * (1 - a)
            ), bgLum);
        }
        var name = '';
        if (canInvert && shown('', 1) < WM_MIN && shown('invert', 1) >= WM_MIN) {
            name = 'invert';
        }
        if (shown(name, 1) <= WM_MAX) {
            return { name: name, scale: 1 };
        }
        var lo = 0;
        var hi = 1;
        for (var i = 0; i < 14; i++) {
            var mid = (lo + hi) / 2;
            if (shown(name, mid) > WM_MAX) {
                hi = mid;
            } else {
                lo = mid;
            }
        }
        return { name: name, scale: lo };
    }

    /** Mark a part of an element with its watermark change, or none. */
    function setPart(el, part, opacity, how) {
        var keep = function (t) {
            return t && t !== part && t !== part + '-invert';
        };
        var tokens = (el.getAttribute(WM_ATTR) || '').split(' ').filter(keep);
        var prop = '--seoprostack-wm-' + part;
        if (how && (how.name || how.scale < 1)) {
            tokens.push(part);
            if (how.name) {
                tokens.push(part + '-invert');
            }
            el.style.setProperty(prop, String(Math.round(opacity * how.scale * 1000) / 1000));
        } else {
            el.style.removeProperty(prop);
        }
        if (tokens.length) {
            el.setAttribute(WM_ATTR, tokens.join(' '));
        } else {
            el.removeAttribute(WM_ATTR);
        }
    }

    /**
     * Keep a watermark faint. Part: 'self' (the element or <img>), 'before'
     * or 'after'. Its background is the element's own for ::before and
     * ::after, the parent's for the element.
     */
    function fade(el, part, c, opacity, isImg) {
        var from = 'self' !== part || isImg ? el : el.parentElement;
        var bg = c && from ? backdrop(el, from) : null;
        // Inverting an element would invert its content too.
        var how = bg ? plan(c, opacity * c[3], bg, isImg ? steps(baseFilter()) : [], isImg || 'self' !== part) : null;
        setPart(el, part, opacity, how);
    }

    /** Look for background-image watermarks on an element. */
    function inspect(el) {
        if (!isDark() || !el.isConnected || NOT_WATERMARKS.test(el.tagName) || el.closest(KEEP)) {
            return;
        }
        // Fading in (animate on scroll): look again at the opacity it ends at.
        if (fading(el, function () {
            inspect(el);
        })) {
            return;
        }
        ['self', 'before', 'after'].forEach(function (part) {
            var cs = getComputedStyle(el, 'self' === part ? null : '::' + part);
            if ('none' === cs.display || ('self' !== part && /^(none|normal)$/.test(cs.content))) {
                return;
            }
            var m = /^url\("?([^")]+)"?\)$/.exec(cs.backgroundImage);
            if (!m) {
                return;
            }
            var opacity = original(el, part, cs);
            if (faint(opacity)) {
                urlTint(m[1]).then(function (c) {
                    if (isDark()) {
                        fade(el, part, c, opacity, false);
                    }
                });
            }
        });
    }

    /** Look through elements a few milliseconds at a time, when idle. */
    function scanChunk() {
        var end = Date.now() + 8;
        while (pending.length && Date.now() < end) {
            inspect(pending.pop());
        }
        if (pending.length) {
            idle(scanChunk);
        } else {
            scanning = false;
        }
    }

    /** Queue a subtree for the watermark look. */
    function scan(root) {
        if (!watermarks || !isDark()) {
            return;
        }
        if (1 === root.nodeType && root !== body) {
            pending.push(root);
        }
        var all = root.querySelectorAll('*');
        for (const el of all) {
            pending.push(el);
        }
        if (!scanning && pending.length) {
            scanning = true;
            idle(scanChunk);
        }
    }

    var io = window.IntersectionObserver ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                io.unobserve(entry.target);
                seen.set(entry.target, true);
                check(entry.target);
            }
        });
    }, { rootMargin: '200px' }) : null;

    function track(img) {
        if (seen.has(img) || state.has(img)) {
            return;
        }
        if (io) {
            io.observe(img);
        } else {
            seen.set(img, true);
            check(img);
        }
    }

    function trackAll(root) {
        if ('IMG' === root.tagName) {
            track(root);
        } else if (root.querySelectorAll) {
            Array.prototype.forEach.call(root.querySelectorAll('img'), track);
        }
    }

    function recheck() {
        timer = 0;
        if (!isDark()) {
            return;
        }
        Array.prototype.forEach.call(document.images, function (img) {
            if (seen.has(img)) {
                check(img);
            }
        });
        scan(body);
    }

    function start() {
        var style = document.createElement('style');
        style.id = 'seoprostack-dark-image-contrast';
        style.textContent = 'body.color-switch-dark img[' + ATTR + '="invert"]{filter:invert(1) hue-rotate(180deg)}'
            + 'body.color-switch-dark img[' + ATTR + '="white"]{filter:brightness(0) invert(1)}'
            + 'body.color-switch-dark img[' + ATTR + '="black"]{filter:brightness(0)}';
        var dark = 'body.color-switch-dark [' + WM_ATTR + '~="';
        ['self', 'before', 'after'].forEach(function (part) {
            var pseudo = 'self' === part ? '' : '::' + part;
            style.textContent += dark + part + '"]' + pseudo + '{opacity:var(--seoprostack-wm-' + part + ')!important}'
                + dark + part + '-invert"]' + pseudo + '{filter:invert(1) hue-rotate(180deg)!important}';
        });
        document.head.appendChild(style);

        trackAll(document);
        scan(body);
        probe.hidden = true;
        probe.alt = '';
        body.appendChild(probe);

        // Lazy loaders and srcset swap the picture: measure it again.
        document.addEventListener('load', function (e) {
            var img = e.target;
            if ('IMG' === img?.tagName && seen.has(img)) {
                check(img);
            }
        }, true);

        if (window.MutationObserver) {
            // After a switch, wait for colour transitions to finish. Other
            // body class changes (some themes add classes as the page
            // scrolls) are ignored: measuring again costs layout and pixels.
            var wasDark = isDark();
            new MutationObserver(function () {
                var dark = isDark();
                if (dark === wasDark) {
                    return;
                }
                wasDark = dark;
                if (timer) {
                    clearTimeout(timer);
                }
                timer = setTimeout(recheck, 400);
            }).observe(body, { attributes: true, attributeFilter: ['class'] });
            new MutationObserver(function (records) {
                records.forEach(function (record) {
                    Array.prototype.forEach.call(record.addedNodes, function (node) {
                        if (1 === node.nodeType) {
                            trackAll(node);
                            scan(node);
                        }
                    });
                });
            }).observe(body, { childList: true, subtree: true });
        }
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
