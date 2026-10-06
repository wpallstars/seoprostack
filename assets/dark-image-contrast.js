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
 * - Each filter is tried on the sampled pixels; one is used only when it
 *   makes most of the image reach 3:1 contrast (WCAG 1.4.11) and clearly
 *   improves on the original. Inverting with the hue kept comes first, so
 *   brand colours stay recognisable, then white, then black.
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
    var ctx = canvas.getContext && canvas.getContext('2d', { willReadFrequently: true });
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
    var timer = 0;

    // Relative luminance of each sRGB channel value.
    var LIN = [];
    for (var v = 0; v < 256; v++) {
        var c = v / 255;
        LIN.push(c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
    }

    function clamp(x) {
        return x < 0 ? 0 : (x > 255 ? 255 : Math.round(x));
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

    /**
     * Parse a computed colour into [r, g, b, a], or null when unreadable.
     */
    function parse(str) {
        var m = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+)(%?))?\s*\)$/.exec(str);
        if (m) {
            var a = undefined === m[4] ? 1 : parseFloat(m[4]) / (m[5] ? 100 : 1);
            return [+m[1], +m[2], +m[3], a];
        }
        m = /^color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+)(%?))?\s*\)$/.exec(str);
        if (m) {
            return [m[1] * 255, m[2] * 255, m[3] * 255, undefined === m[4] ? 1 : parseFloat(m[4]) / (m[5] ? 100 : 1)];
        }
        return null;
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
        for (var i = 0; i < found.length; i++) {
            var col = parse(found[i]);
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
            var opacity = parseFloat(cs.opacity);
            if ('absolute' !== cs.position || 'none' === cs.display || 'hidden' === cs.visibility || !(opacity > 0)) {
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
     * The colour behind the image, or null when unknown.
     */
    function backdrop(img) {
        var rect = img.getBoundingClientRect();
        var x = rect.left + rect.width / 2;
        var y = rect.top + rect.height / 2;
        var layers = [];
        var child = null;
        var found = 0;
        for (var el = img; el && 1 === el.nodeType && !found; el = el.parentElement) {
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
     * Visible pixels [r, g, b, a, ...] of a transparent, flat-coloured
     * image, or null to leave it alone.
     */
    function pixels(img) {
        var w = img.naturalWidth || img.clientWidth;
        var h = img.naturalHeight || img.clientHeight;
        if (!w || !h) {
            return null;
        }
        var s = Math.min(1, Math.sqrt(AREA / (w * h)));
        var cw = Math.max(1, Math.round(w * s));
        var ch = Math.max(1, Math.round(h * s));
        var data;
        canvas.width = cw;
        canvas.height = ch;
        ctx.clearRect(0, 0, cw, ch);
        try {
            ctx.drawImage(img, 0, 0, cw, ch);
            data = ctx.getImageData(0, 0, cw, ch).data;
        } catch (e) {
            return null; // Cross-origin, or not decodable.
        }
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
        var counts = [];
        for (var key in buckets) {
            if (Object.prototype.hasOwnProperty.call(buckets, key)) {
                counts.push(buckets[key]);
            }
        }
        counts.sort(function (p, q) {
            return q - p;
        });
        var top = 0;
        for (var t = 0; t < counts.length && t < 12; t++) {
            top += counts[t];
        }
        return top >= n * 0.8 ? px : null;
    }

    /**
     * A pixel after a filter, as CSS filter functions do it (sRGB).
     */
    function filtered(name, r, g, b) {
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
    function lowShare(px, bg, name) {
        var bgLum = lum(bg[0], bg[1], bg[2]);
        var low = 0;
        for (var i = 0; i < px.length; i += 4) {
            var a = px[i + 3] / 255;
            var f = filtered(name, px[i], px[i + 1], px[i + 2]);
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
        var before = lowShare(px, bg, '');
        if (before < 0.4) {
            return '';
        }
        var best = '';
        var bestShare = before;
        for (var i = 0; i < FILTERS.length; i++) {
            var share = lowShare(px, bg, FILTERS[i]);
            if (share <= 0.2) {
                best = FILTERS[i];
                bestShare = share;
                break;
            }
            if (share < bestShare) {
                best = FILTERS[i];
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
        if (pic && 'PICTURE' === pic.tagName && pic.querySelector('source[media*="prefers-color-scheme"]')) {
            return false;
        }
        var src = img.currentSrc || img.src || '';
        return !/^data:image\/jpe?g|\.jpe?g(?:$|[?#])/i.test(src);
    }

    function check(img) {
        if (!isDark() || !img.complete) {
            return; // The load listener checks it again.
        }
        var src = img.currentSrc || img.src || '';
        var saved = state.get(img);
        if (!saved || saved.src !== src) {
            var px = null;
            // Unmarked first: a filter of its own means hands off.
            img.removeAttribute(ATTR);
            if (src && eligible(img) && 'none' === getComputedStyle(img).filter) {
                px = pixels(img);
            }
            saved = { src: src, px: px };
            state.set(img, saved);
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
    }

    function start() {
        var style = document.createElement('style');
        style.id = 'seoprostack-dark-image-contrast';
        style.textContent = 'body.color-switch-dark img[' + ATTR + '="invert"]{filter:invert(1) hue-rotate(180deg)}'
            + 'body.color-switch-dark img[' + ATTR + '="white"]{filter:brightness(0) invert(1)}'
            + 'body.color-switch-dark img[' + ATTR + '="black"]{filter:brightness(0)}';
        document.head.appendChild(style);

        trackAll(document);

        // Lazy loaders and srcset swap the picture: measure it again.
        document.addEventListener('load', function (e) {
            var img = e.target;
            if (img && 'IMG' === img.tagName && seen.has(img)) {
                check(img);
            }
        }, true);

        if (window.MutationObserver) {
            // After a switch, wait for colour transitions to finish.
            new MutationObserver(function () {
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
