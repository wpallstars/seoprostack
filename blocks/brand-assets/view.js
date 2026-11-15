/**
 * seoprostack/brand-assets: copy colours, and make the logo with the site
 * title (as in the header) as SVG and PNG files and an email signature
 * banner. Files are made in the browser from the page's own fonts and logo,
 * and no other site is asked. For someone who can edit the theme, it also
 * draws each picture as PNG files and saves them to the site, so visitors
 * can copy links to them (makePngs()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function () {
	'use strict';

	var ROOT = '.wp-block-seoprostack-brand-assets';
	/** PNG files are drawn at this many times the header's size. */
	var SCALE = 3;
	/**
	 * Email signature banner's largest size, in CSS pixels; drawn at twice
	 * the size. space: the share of the even space around the logo kept.
	 */
	var BANNER = { width: 600, height: 150, scale: 2, space: 0.5 };

	function say(button, text) {
		var root = button.closest(ROOT);
		var live = root && root.querySelector(ROOT + '__live');
		if (live) {
			live.textContent = text;
		}
	}

	/* Copy a colour value, as shown beside the button, or a PNG file's link. */
	function copy(button) {
		var value = button.getAttribute('data-sps-brand-copy');
		if (!value) {
			return;
		}
		var copied = button.getAttribute('data-sps-brand-copied') || value + ' copied';
		var done = function () {
			button.classList.add('is-copied');
			say(button, copied);
			window.setTimeout(function () {
				button.classList.remove('is-copied');
			}, 1500);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(value).then(done, function () {
				say(button, value);
			});
			return;
		}
		var field = document.createElement('textarea');
		field.value = value;
		field.setAttribute('readonly', '');
		field.style.position = 'fixed';
		field.style.opacity = '0';
		document.body.appendChild(field);
		field.select();
		try {
			if (document.execCommand('copy')) {
				done();
			} else {
				say(button, value);
			}
		} catch (e) {
			say(button, value);
		}
		document.body.removeChild(field);
	}

	/*
	 * The effect the theme gives the header's logo (Kadence's markup:
	 * .site-branding .brand img.custom-logo), such as an edge that keeps a
	 * white flag's shape on a white header, worked out inside a stage so it
	 * takes that stage's colours. '' when there is none.
	 */
	function headerEffect(stage) {
		var probe = document.createElement('span');
		var brand = document.createElement('span');
		var img = document.createElement('img');
		probe.className = 'site-branding';
		probe.setAttribute('aria-hidden', 'true');
		probe.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:1px;overflow:hidden;visibility:hidden';
		brand.className = 'brand';
		img.className = 'custom-logo';
		img.alt = '';
		brand.appendChild(img);
		probe.appendChild(brand);
		stage.appendChild(probe);
		var filter = window.getComputedStyle(img).filter;
		stage.removeChild(probe);
		return filter && 'none' !== filter ? filter : '';
	}

	/* Give the stages' logos the header logo's effect, and keep it for the files. */
	function applyEffects() {
		Array.prototype.forEach.call(document.querySelectorAll(ROOT + ' [data-sps-brand-stage]'), function (stage) {
			var logos = stage.querySelectorAll('[data-sps-brand-edge]');
			var filter = logos.length ? headerEffect(stage) : '';
			if (!filter) {
				return;
			}
			stage.setAttribute('data-sps-brand-filter', filter);
			Array.prototype.forEach.call(logos, function (img) {
				img.style.filter = filter;
			});
		});
	}

	/*
	 * A CSS colour as rgb() and an opacity. Computed colours (color(srgb …)
	 * or rgb()) are read exactly; others from a pixel, which rounds a
	 * see-through colour.
	 */
	function rgba(colour) {
		var num = '(-?\\d*\\.?\\d+(?:e-?\\d+)?)';
		var srgb = new RegExp('^color\\(srgb ' + num + ' ' + num + ' ' + num + '(?: / ' + num + ')?\\)$').exec(colour);
		var rgb = new RegExp('^rgba?\\(' + num + ',\\s*' + num + ',\\s*' + num + '(?:,\\s*' + num + ')?\\)$').exec(colour);
		var found = srgb || rgb;
		if (found) {
			var unit = srgb ? 255 : 1;
			var channels = [1, 2, 3].map(function (i) {
				return Math.max(0, Math.min(255, Math.round(parseFloat(found[i]) * unit)));
			});
			return { colour: 'rgb(' + channels.join(',') + ')', opacity: undefined === found[4] ? 1 : Math.round(parseFloat(found[4]) * 1000) / 1000 };
		}
		var ctx = document.createElement('canvas').getContext('2d');
		ctx.fillStyle = colour;
		ctx.fillRect(0, 0, 1, 1);
		var d = ctx.getImageData(0, 0, 1, 1).data;
		return { colour: 'rgb(' + d[0] + ',' + d[1] + ',' + d[2] + ')', opacity: Math.round(d[3] / 255 * 1000) / 1000 };
	}

	/* One drop-shadow()'s inside: a colour and two or three lengths, in any order. */
	function shadow(inner) {
		var lengths = [];
		var colour = inner.replace(/(^|\s)(-?\d*\.?\d+)px(?=\s|$)/g, function (all, space, n) {
			lengths.push(parseFloat(n));
			return space;
		}).trim();
		if (lengths.length < 2) {
			return null;
		}
		var c = rgba(colour || 'black');
		return { x: lengths[0], y: lengths[1], blur: lengths[2] || 0, colour: c.colour, opacity: c.opacity };
	}

	/* The drop-shadow()s of a CSS filter, or null if it has anything else. */
	function shadows(filter) {
		var out = [];
		var at = 0;
		var name = 'drop-shadow(';
		while (at < filter.length) {
			if (filter.indexOf(name, at) !== at) {
				return null;
			}
			var start = at + name.length;
			var depth = 1;
			var i = start;
			for (; i < filter.length && depth; i++) {
				depth += '(' === filter[i] ? 1 : (')' === filter[i] ? -1 : 0);
			}
			var one = shadow(filter.slice(start, i - 1));
			if (!one) {
				return null;
			}
			out.push(one);
			at = i;
			while (' ' === filter[at]) {
				at++;
			}
		}
		return out.length ? out : null;
	}

	/* The effect as an SVG filter (id sps-edge), or '' if SVG cannot say it. */
	function svgFilter(filter) {
		var list = filter ? shadows(filter) : null;
		if (!list) {
			return '';
		}
		var out = '<filter id="sps-edge" x="-20%" y="-20%" width="140%" height="140%" color-interpolation-filters="sRGB">';
		list.forEach(function (s, i) {
			out += '<feDropShadow' + (i ? ' in="s' + (i - 1) + '"' : '') + ' result="s' + i + '" dx="' + s.x + '" dy="' + s.y + '" stdDeviation="' + (s.blur / 2) + '" flood-color="' + s.colour + '" flood-opacity="' + s.opacity + '"/>';
		});
		return out + '</filter>';
	}

	/* A CSS filter with its lengths multiplied, for a canvas drawn larger. */
	function scaleFilter(filter, scale) {
		return filter.replace(/(-?\d*\.?\d+)px/g, function (all, n) {
			return (parseFloat(n) * scale) + 'px';
		});
	}

	/* A CSS font family with any var() read from the page. */
	function family(css) {
		var m = /^var\((--[\w-]+)/.exec(css || '');
		if (m) {
			var value = window.getComputedStyle(document.body).getPropertyValue(m[1]).trim();
			return value || 'sans-serif';
		}
		return css || 'sans-serif';
	}

	function firstFamily(css) {
		return family(css).split(',')[0].trim().replace(/^["']|["']$/g, '');
	}

	function fontString(font, scale) {
		return font.style + ' ' + font.weight + ' ' + (font.size * scale) + 'px ' + family(font.family);
	}

	function transform(text, how) {
		if ('uppercase' === how) {
			return text.toUpperCase();
		}
		if ('lowercase' === how) {
			return text.toLowerCase();
		}
		if ('capitalize' === how) {
			return text.replace(/(^|\s)(\S)/g, function (all, space, letter) {
				return space + letter.toUpperCase();
			});
		}
		return text;
	}

	function loadImage(url) {
		return new Promise(function (resolve, reject) {
			var img = new Image();
			// A logo on another host (a CDN or media bucket) must come with CORS, or the canvas cannot be saved.
			if (new URL(url, window.location.href).origin !== window.location.origin) {
				img.crossOrigin = 'anonymous';
			}
			img.onload = function () {
				resolve(img);
			};
			img.onerror = reject;
			img.src = url;
		});
	}

	/* A file of this page (the logo, a font) as a data: URL; web addresses only. */
	function dataUrl(url) {
		var target = new URL(url, window.location.href);
		if ('https:' !== target.protocol && 'http:' !== target.protocol) {
			return Promise.reject(new Error('Not a web address'));
		}
		// nosemgrep -- runs in the visitor's browser (not a server request), reading the page's own logo and @font-face files.
		return fetch(target.href, { credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) {
				throw new Error(r.status);
			}
			return r.blob();
		}).then(function (blob) {
			return new Promise(function (resolve) {
				var reader = new FileReader();
				reader.onload = function () {
					resolve(reader.result);
				};
				reader.readAsDataURL(blob);
			});
		});
	}

	/* The logo's size at scale 1, or 0 by 0 when there is none. */
	function logoBox(lockup) {
		if (!lockup.logo || !lockup.img) {
			return { width: 0, height: 0 };
		}
		var natural = lockup.img.naturalWidth ? lockup.img.naturalHeight / lockup.img.naturalWidth : 1;
		var ratio = lockup.logo.ratio || natural;
		return { width: lockup.logo.width, height: lockup.logo.width * ratio };
	}

	/* A font's letter spacing in pixels at scale 1 (lockups before 1.3.221 have none). */
	function spacing(font) {
		return (Number(font.letterSpacing) || 0) * font.size;
	}

	/*
	 * A line at scale 1: its width (without the letter spacing after its
	 * last letter) and where its ink starts and ends from where it is
	 * drawn. A letter's side bearing grows with its size, so text lined up
	 * by its boxes is not lined up by its letters.
	 */
	function measure(ctx, text, font) {
		ctx.font = fontString(font, 1);
		if ('letterSpacing' in ctx) {
			ctx.letterSpacing = '0px';
		}
		var m = ctx.measureText(text);
		var extra = spacing(font) * Math.max(0, Array.from(text).length - 1);
		var ink = 'number' === typeof m.actualBoundingBoxLeft;
		return {
			width: m.width + extra,
			inkLeft: ink ? -m.actualBoundingBoxLeft : 0,
			inkRight: (ink ? m.actualBoundingBoxRight : m.width) + extra
		};
	}

	/* A copy of a lockup font at another size. */
	function resized(font, size) {
		return {
			family: font.family,
			weight: font.weight,
			style: font.style,
			size: size,
			lineHeight: font.lineHeight,
			transform: font.transform,
			color: font.color,
			letterSpacing: font.letterSpacing
		};
	}

	/*
	 * With tagFit, the title's and tagline's fonts sized so the tagline's
	 * letters span the title's exactly, as a theme's header does it. Below
	 * its smallest fitted size (min, minPx) the title grows instead, by at
	 * most tagFit.grow; where that is not enough, the tagline fits the
	 * grown title down to minPx, its hard floor (wider than the title only
	 * below it), as the PSD theme's brand-fit.js does.
	 */
	function fitted(lockup, ctx) {
		var fonts = { title: lockup.titleFont, tag: lockup.tagFont };
		var fit = lockup.tagFit;
		if (!fit || !lockup.title || !lockup.tagline) {
			return fonts;
		}
		var title = measure(ctx, transform(lockup.title, fonts.title.transform), fonts.title);
		var tag = measure(ctx, transform(lockup.tagline, fonts.tag.transform), resized(fonts.tag, 100));
		var titleInk = title.inkRight - title.inkLeft;
		var perPx = (tag.inkRight - tag.inkLeft) / 100;
		if (!(titleInk > 0) || !(perPx > 0)) {
			return fonts;
		}
		var target = titleInk / perPx;
		var least = Math.max(fit.minPx || 0, fonts.title.size * (fit.min || 0));
		if (target < least) {
			var grow = Math.max(1, Math.min(fit.grow || 1, least * perPx / titleInk));
			fonts.title = resized(fonts.title, fonts.title.size * grow);
			target = Math.max(fit.minPx || 0, Math.min(least, titleInk * grow / perPx));
		}
		fonts.tag = resized(fonts.tag, target);
		return fonts;
	}

	/* The site title and tagline lines, measured and stacked, at scale 1. */
	function textBox(lockup, ctx) {
		var box = { width: 0, height: 0, lines: [], aligned: !!(lockup.tagFit && lockup.title && lockup.tagline) };
		var fonts = fitted(lockup, ctx);
		var gapEm = 'number' === typeof lockup.tagGap ? lockup.tagGap : 0.4375;
		[{ text: lockup.title, font: fonts.title }, { text: lockup.tagline, font: fonts.tag }].forEach(function (part) {
			if (!part.text) {
				return;
			}
			var font = part.font;
			var text = transform(part.text, font.transform);
			var m = measure(ctx, text, font);
			var height = font.size * font.lineHeight;
			var gap = box.lines.length ? font.size * gapEm : 0;
			box.lines.push({ text: text, font: font, width: m.width, inkLeft: m.inkLeft, shift: 0, height: height, top: box.height + gap });
			box.height += gap + height;
		});
		// Fitted lines start at the same ink, not the same box.
		if (box.aligned) {
			var start = Math.max.apply(null, box.lines.map(function (line) {
				return line.inkLeft;
			}));
			box.lines.forEach(function (line) {
				line.shift = start - line.inkLeft;
			});
		}
		box.lines.forEach(function (line) {
			box.width = Math.max(box.width, line.shift + line.width);
		});
		return box;
	}

	/* Where the logo and the text start: side by side, centred on the height. */
	function placeRow(direction, logo, text, gap, size) {
		var logoFirst = 'row' === direction;
		return {
			logo: { x: logoFirst ? 0 : text.width + gap, y: (size.height - logo.height) / 2 },
			text: { x: logoFirst ? logo.width + gap : 0, y: (size.height - text.height) / 2 }
		};
	}

	/* Where the logo and the text start: stacked, centred on the width. */
	function placeColumn(direction, logo, text, gap, size) {
		var logoFirst = 'column' === direction;
		return {
			logo: { x: (size.width - logo.width) / 2, y: logoFirst ? 0 : text.height + gap },
			text: { x: (size.width - text.width) / 2, y: logoFirst ? logo.height + gap : 0 }
		};
	}

	/*
	 * Work out where everything goes, at scale 1, like Kadence's header:
	 * logo and name side by side (or stacked), a 1em gap, centred.
	 */
	function layout(lockup, ctx) {
		var logo = logoBox(lockup);
		var text = textBox(lockup, ctx);
		var gap = logo.width && text.width ? lockup.gap : 0;
		var row = 'row' === lockup.direction || 'row-reverse' === lockup.direction;
		var size = row
			? { width: logo.width + gap + text.width, height: Math.max(logo.height, text.height) }
			: { width: Math.max(logo.width, text.width), height: logo.height + gap + text.height };
		var at = (row ? placeRow : placeColumn)(lockup.direction, logo, text, gap, size);
		text.lines.forEach(function (line) {
			// Lines lined up with each other keep that when stacked and centred.
			line.x = at.text.x + (row || text.aligned ? line.shift : (text.width - line.width) / 2);
			line.y = at.text.y + line.top + line.height / 2;
		});
		return {
			logo: logo.width ? { x: at.logo.x, y: at.logo.y, width: logo.width, height: logo.height } : null,
			lines: text.lines,
			width: size.width,
			height: size.height,
			pad: Math.round(lockup.titleFont.size * 0.5)
		};
	}

	function draw(lockup, parts, canvas, scale, offsetX, offsetY, background) {
		var ctx = canvas.getContext('2d');
		if (background) {
			ctx.fillStyle = background;
			ctx.fillRect(0, 0, canvas.width, canvas.height);
		}
		if (parts.logo) {
			// The header logo's effect; browsers without canvas filters draw the logo plain.
			ctx.filter = lockup.filter ? scaleFilter(lockup.filter, scale) : 'none';
			ctx.drawImage(lockup.img, (offsetX + parts.logo.x) * scale, (offsetY + parts.logo.y) * scale, parts.logo.width * scale, parts.logo.height * scale);
			ctx.filter = 'none';
		}
		ctx.textBaseline = 'middle';
		parts.lines.forEach(function (line) {
			ctx.font = fontString(line.font, scale);
			ctx.fillStyle = line.font.color;
			fillSpaced(ctx, line.text, (offsetX + line.x) * scale, (offsetY + line.y) * scale, spacing(line.font) * scale);
		});
	}

	/* Text with letter spacing; letter by letter where canvas has no letterSpacing. */
	function fillSpaced(ctx, text, x, y, space) {
		if (!space) {
			ctx.fillText(text, x, y);
			return;
		}
		if ('letterSpacing' in ctx) {
			ctx.letterSpacing = space + 'px';
			ctx.fillText(text, x, y);
			ctx.letterSpacing = '0px';
			return;
		}
		var before = '';
		Array.from(text).forEach(function (letter, i) {
			ctx.fillText(letter, x + ctx.measureText(before).width + space * i, y);
			before += letter;
		});
	}

	/* Whether a CSS unicode-range covers any character of the text (no range covers all). */
	function coversText(range, text) {
		if (!range) {
			return true;
		}
		var spans = range.split(',').map(function (part) {
			var m = /U\+([0-9a-f?]+)(?:-([0-9a-f]+))?/i.exec(part);
			if (!m) {
				return null;
			}
			if (m[1].indexOf('?') > -1) {
				return [parseInt(m[1].replace(/\?/g, '0'), 16), parseInt(m[1].replace(/\?/g, 'F'), 16)];
			}
			return [parseInt(m[1], 16), parseInt(m[2] || m[1], 16)];
		});
		return Array.from(text).some(function (ch) {
			var code = ch.codePointAt(0);
			return spans.some(function (span) {
				return span && code >= span[0] && code <= span[1];
			});
		});
	}

	/*
	 * The @font-face files of a family, weight and style in the page's own
	 * stylesheets: every subset whose unicode-range covers the text.
	 */
	function fontFiles(name, weight, style, text) {
		var found = [];
		Array.prototype.forEach.call(document.styleSheets, function (sheet) {
			var rules;
			try {
				rules = sheet.cssRules;
			} catch (e) {
				return;
			}
			Array.prototype.forEach.call(rules || [], function (rule) {
				if (!rule.style || 'undefined' === typeof CSSFontFaceRule || !(rule instanceof CSSFontFaceRule)) {
					return;
				}
				var fam = rule.style.getPropertyValue('font-family').trim().replace(/^["']|["']$/g, '');
				var faceStyle = rule.style.getPropertyValue('font-style').trim() || 'normal';
				var italic = /^(italic|oblique)/.test(style);
				if (fam.toLowerCase() !== name.toLowerCase() || /^(italic|oblique)/.test(faceStyle) !== italic) {
					return;
				}
				var src = /url\((["']?)([^"')]+)\1\)/.exec(rule.style.getPropertyValue('src'));
				if (!src) {
					return;
				}
				var weights = rule.style.getPropertyValue('font-weight').split(/\s+/).map(Number);
				var range = rule.style.getPropertyValue('unicode-range');
				var fits = weights.length > 1 ? (weight >= weights[0] && weight <= weights[1]) : weights[0] === Number(weight);
				if (fits && coversText(range, text)) {
					found.push({ url: new URL(src[2], sheet.href || window.location.href).href, range: range });
				}
			});
		});
		return found;
	}

	function escapeXml(text) {
		return String(text).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' }[c];
		});
	}

	function svg(lockup, parts) {
		var pad = parts.pad;
		var w = Math.ceil(parts.width + pad * 2);
		var h = Math.ceil(parts.height + pad * 2);
		var fonts = {};
		parts.lines.forEach(function (line) {
			var name = firstFamily(line.font.family);
			var key = name + ' ' + line.font.weight + ' ' + line.font.style;
			if (!fonts[key]) {
				fonts[key] = { name: name, weight: line.font.weight, style: line.font.style, text: '' };
			}
			fonts[key].text += line.text;
		});
		var faces = [];
		Object.keys(fonts).forEach(function (key) {
			var font = fonts[key];
			fontFiles(font.name, font.weight, font.style, font.text).forEach(function (face) {
				faces.push({ font: font, range: face.range, url: face.url });
			});
		});
		/* A font file that cannot be read leaves the font named only; the logo must be read. */
		var jobs = faces.map(function (face) {
			return dataUrl(face.url).catch(function () {
				return '';
			});
		});
		jobs.push(parts.logo ? dataUrl(lockup.logo.url) : Promise.resolve(''));
		return Promise.all(jobs).then(function (files) {
			var logoData = files.pop();
			var style = faces.map(function (face, i) {
				var font = face.font;
				return files[i] ? '@font-face{font-family:"' + font.name + '";font-weight:' + font.weight + ';font-style:' + font.style + ';' + (face.range ? 'unicode-range:' + face.range + ';' : '') + 'src:url(' + files[i] + ')}' : '';
			}).join('');
			var out = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '">';
			out += '<title>' + escapeXml(lockup.title || lockup.logo.alt) + '</title>';
			if (style) {
				out += '<style>' + style + '</style>';
			}
			var effect = parts.logo ? svgFilter(lockup.filter) : '';
			if (effect) {
				out += '<defs>' + effect + '</defs>';
			}
			if (parts.logo) {
				out += '<image' + (effect ? ' filter="url(#sps-edge)"' : '') + ' href="' + logoData + '" x="' + (pad + parts.logo.x).toFixed(2) + '" y="' + (pad + parts.logo.y).toFixed(2) + '" width="' + parts.logo.width.toFixed(2) + '" height="' + parts.logo.height.toFixed(2) + '"/>';
			}
			parts.lines.forEach(function (line) {
				var space = spacing(line.font);
				out += '<text x="' + (pad + line.x).toFixed(2) + '" y="' + (pad + line.y).toFixed(2) + '" dominant-baseline="central" font-family="' + escapeXml(family(line.font.family)) + '" font-weight="' + line.font.weight + '" font-style="' + line.font.style + '" font-size="' + line.font.size + '"' + (space ? ' letter-spacing="' + space.toFixed(3) + '"' : '') + ' fill="' + escapeXml(line.font.color) + '">' + escapeXml(line.text) + '</text>';
			});
			return new Blob([out + '</svg>'], { type: 'image/svg+xml' });
		});
	}

	function png(lockup, parts) {
		var canvas = document.createElement('canvas');
		canvas.width = Math.ceil((parts.width + parts.pad * 2) * SCALE);
		canvas.height = Math.ceil((parts.height + parts.pad * 2) * SCALE);
		draw(lockup, parts, canvas, SCALE, parts.pad, parts.pad, null);
		return new Promise(function (resolve) {
			canvas.toBlob(resolve, 'image/png');
		});
	}

	/*
	 * The lockup on the light background, fitted inside a 600 by 150 banner
	 * (never enlarged), then cropped so the space around it is the same on
	 * every side: half the smaller of the space above it and beside it.
	 */
	function bannerCanvas(lockup, parts) {
		var inner = { width: BANNER.width - 48, height: BANNER.height - 32 };
		var fit = Math.min(1, inner.width / parts.width, inner.height / parts.height);
		var drawn = { width: parts.width * fit, height: parts.height * fit };
		var pad = Math.min((BANNER.width - drawn.width) / 2, (BANNER.height - drawn.height) / 2) * BANNER.space;
		var width = Math.min(BANNER.width, Math.round(drawn.width + pad * 2));
		var height = Math.min(BANNER.height, Math.round(drawn.height + pad * 2));
		var canvas = document.createElement('canvas');
		canvas.width = width * BANNER.scale;
		canvas.height = height * BANNER.scale;
		var scale = fit * BANNER.scale;
		var offsetX = (width / fit - parts.width) / 2;
		var offsetY = (height / fit - parts.height) / 2;
		draw(lockup, parts, canvas, scale, offsetX, offsetY, lockup.background);
		return canvas;
	}

	function banner(lockup, parts) {
		var canvas = bannerCanvas(lockup, parts);
		return new Promise(function (resolve) {
			canvas.toBlob(resolve, 'image/png');
		});
	}

	/*
	 * Load the faces the lockup's title and tagline use. Given the text, the
	 * browser picks the faces with its letters (a font split into Unicode
	 * subsets loads only the Latin one for the default space).
	 */
	function loadFonts(lockup) {
		return Promise.all([
			{ text: lockup.title, font: lockup.titleFont },
			{ text: lockup.tagline, font: lockup.tagFont }
		].map(function (part) {
			if (!part.text || !document.fonts || !document.fonts.load) {
				return Promise.resolve();
			}
			return document.fonts.load(fontString(part.font, 1), transform(part.text, part.font.transform)).catch(function () {});
		}));
	}

	/* Load the lockup's fonts and logo, then lay it out. */
	function prepare(lockup) {
		var image = lockup.logo ? loadImage(lockup.logo.url) : Promise.resolve(null);
		return Promise.all([image, loadFonts(lockup)]).then(function (loaded) {
			lockup.img = loaded[0];
			return layout(lockup, document.createElement('canvas').getContext('2d'));
		});
	}

	function save(blob, name) {
		var url = URL.createObjectURL(blob);
		var link = document.createElement('a');
		link.href = url;
		link.download = name;
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		window.setTimeout(function () {
			URL.revokeObjectURL(url);
		}, 10000);
	}

	function make(button) {
		var kind = button.getAttribute('data-sps-brand-make');
		var lockup;
		try {
			lockup = JSON.parse(button.getAttribute('data-sps-brand-lockup'));
		} catch (e) {
			return;
		}
		var card = button.closest(ROOT + '__card');
		var stage = card && card.querySelector('[data-sps-brand-stage]');
		lockup.filter = stage ? stage.getAttribute('data-sps-brand-filter') || '' : '';
		button.disabled = true;
		say(button, 'Making the file…');
		prepare(lockup).then(function (parts) {
			var base = lockup.file + '-logo-' + lockup.scheme;
			if ('svg' === kind) {
				return svg(lockup, parts).then(function (blob) {
					save(blob, base + '.svg');
				});
			}
			if ('email' === kind) {
				return banner(lockup, parts).then(function (blob) {
					save(blob, lockup.file + '-email-signature' + ('dark' === lockup.scheme ? '-dark' : '') + '.png');
				});
			}
			return png(lockup, parts).then(function (blob) {
				save(blob, base + '.png');
			});
		}).then(function () {
			say(button, 'Downloaded.');
		}, function () {
			say(button, 'The file could not be made. Please try another browser.');
		}).then(function () {
			button.disabled = false;
		});
	}

	/*
	 * PNG files for links. For someone who can edit the theme, the block
	 * lists the files that are missing or out of date; they are drawn here,
	 * one at a time, and saved to the site, whose answer gives the link.
	 */
	var PNG = {
		/** Space around a padded picture, the same on every side, as a share of its longest side. */
		pad: 0.0625,
		/** Longest side of a bitmap logo's file (never enlarged), and of an SVG logo's. */
		maxSide: 2048,
		svgSide: 1200
	};

	/* The buttons of one file, such as logo-light/padded. */
	function pngButtons(id) {
		return document.querySelectorAll(ROOT + ' [data-sps-brand-png="' + id + '"]');
	}

	/* The header logo's effect on the picture's card, or ''. */
	function cardFilter(id) {
		var button = pngButtons(id)[0];
		var card = button && button.closest(ROOT + '__card');
		var stage = card && card.querySelector('[data-sps-brand-stage]');
		return stage ? stage.getAttribute('data-sps-brand-filter') || '' : '';
	}

	/* How far the effect's shadows reach beyond the picture, in CSS pixels. */
	function filterReach(filter) {
		var list = filter ? shadows(filter) : null;
		return (list || []).reduce(function (most, s) {
			return Math.max(most, Math.max(Math.abs(s.x), Math.abs(s.y)) + s.blur);
		}, 0);
	}

	/* A logo or icon from the Media Library, padded on its background or on transparent. */
	function imageCanvas(target, variant, img, filter) {
		var w = img.naturalWidth || target.image.width || 512;
		var h = img.naturalHeight || target.image.height || 512;
		var svgFile = '.svg' === String(target.image.url).split('?')[0].slice(-4).toLowerCase();
		var fit = svgFile ? PNG.svgSide / Math.max(w, h) : Math.min(1, PNG.maxSide / Math.max(w, h));
		w = Math.round(w * fit);
		h = Math.round(h * fit);
		// The effect's lengths are for the picture at the size the page shows it.
		var unit = target.display ? w / target.display : h / 96;
		var effect = target.edge && filter ? scaleFilter(filter, unit) : '';
		var reach = effect ? Math.ceil(filterReach(filter) * unit) : 0;
		var padded = 'padded' === variant;
		var pad = padded ? Math.round(Math.max(w, h) * PNG.pad) : reach;
		var canvas = document.createElement('canvas');
		canvas.width = w + pad * 2;
		canvas.height = h + pad * 2;
		if (padded && target.square) {
			canvas.width = canvas.height = Math.max(canvas.width, canvas.height);
		}
		var ctx = canvas.getContext('2d');
		if (padded) {
			ctx.fillStyle = target.background;
			ctx.fillRect(0, 0, canvas.width, canvas.height);
		}
		ctx.filter = effect || 'none';
		ctx.drawImage(img, (canvas.width - w) / 2, (canvas.height - h) / 2, w, h);
		ctx.filter = 'none';
		return canvas;
	}

	/* The logo with the name, padded on its background or on transparent. */
	function lockupCanvas(variant, lockup, parts) {
		var padded = 'padded' === variant;
		var pad = padded ? Math.max(parts.width, parts.height) * PNG.pad : Math.max(2, filterReach(lockup.filter));
		var canvas = document.createElement('canvas');
		canvas.width = Math.ceil((parts.width + pad * 2) * SCALE);
		canvas.height = Math.ceil((parts.height + pad * 2) * SCALE);
		draw(lockup, parts, canvas, SCALE, pad, pad, padded ? lockup.background : null);
		return canvas;
	}

	/* One job's file as a data: URL. */
	function drawJob(job) {
		var target = job.target;
		var filter = cardFilter(job.id);
		if ('image' === target.kind) {
			return loadImage(target.image.url).then(function (img) {
				return imageCanvas(target, job.variant, img, filter).toDataURL('image/png');
			});
		}
		var lockup = JSON.parse(JSON.stringify(target.lockup));
		lockup.filter = filter;
		return prepare(lockup).then(function (parts) {
			var canvas = 'banner' === target.kind ? bannerCanvas(lockup, parts) : lockupCanvas(job.variant, lockup, parts);
			return canvas.toDataURL('image/png');
		});
	}

	function pngReady(id, saved) {
		Array.prototype.forEach.call(pngButtons(id), function (button) {
			button.setAttribute('data-sps-brand-copy', saved.url);
			button.title = saved.url + ' (' + saved.width + ' × ' + saved.height + ')';
			button.classList.remove('is-pending');
			button.disabled = false;
		});
	}

	function pngFailed(id) {
		Array.prototype.forEach.call(pngButtons(id), function (button) {
			// An older file still has a link; a missing one says why it has none.
			if (!button.getAttribute('data-sps-brand-copy')) {
				button.title = 'The file could not be made. Reload the page to try again.';
			}
		});
	}

	function makePngs() {
		var holder = document.querySelector(ROOT + '__png-jobs');
		var data;
		if (!holder || !window.fetch) {
			return;
		}
		try {
			data = JSON.parse(holder.textContent);
		} catch (e) {
			return;
		}
		var jobs = Array.isArray(data.jobs) ? data.jobs : [];
		var chain = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
		jobs.forEach(function (job, i) {
			chain = chain.then(function () {
				return drawJob(job).then(function (png) {
					// nosemgrep -- the site's own REST route, with the editor's nonce.
					return fetch(data.endpoint, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce },
						body: JSON.stringify({ key: job.key, variant: job.variant, hash: job.hash, png: png, last: i === jobs.length - 1 })
					});
				}).then(function (response) {
					if (!response.ok) {
						throw new Error(response.status);
					}
					return response.json();
				}).then(function (saved) {
					pngReady(job.id, saved);
				}).catch(function () {
					pngFailed(job.id);
				});
			});
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest && event.target.closest(ROOT + ' [data-sps-brand-copy], ' + ROOT + ' [data-sps-brand-make]');
		if (!button) {
			return;
		}
		event.preventDefault();
		if (button.hasAttribute('data-sps-brand-copy')) {
			copy(button);
		} else {
			make(button);
		}
	});

	/*
	 * The cards' lockups with tagFit (data-sps-brand-line), sized and lined
	 * up as the files draw them, once their fonts have loaded.
	 */
	function fitStages() {
		Array.prototype.forEach.call(document.querySelectorAll(ROOT + ' [data-sps-brand-line="title"]'), function (titleEl) {
			var name = titleEl.parentElement;
			var tagEl = name && name.querySelector('[data-sps-brand-line="tag"]');
			var card = titleEl.closest(ROOT + '__card');
			var button = card && card.querySelector('[data-sps-brand-lockup]');
			var lockup;
			if (!tagEl || !button) {
				return;
			}
			try {
				lockup = JSON.parse(button.getAttribute('data-sps-brand-lockup'));
			} catch (e) {
				return;
			}
			loadFonts(lockup).then(function () {
				var box = textBox(lockup, document.createElement('canvas').getContext('2d'));
				[titleEl, tagEl].forEach(function (el, i) {
					var line = box.lines[i];
					el.style.fontSize = line.font.size + 'px';
					el.style.marginLeft = line.shift ? line.shift + 'px' : '';
					// Lined up by their ink from the left, as the files draw
					// them, also in a centred (column) lockup.
					el.style.textAlign = 'left';
				});
			});
		});
	}

	function start() {
		applyEffects();
		fitStages();
		makePngs();
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
