/**
 * seoprostack/brand-assets: copy colours, and make the logo with the site
 * title (as in the header) as SVG and PNG files and an email signature
 * banner. Files are made in the browser from the page's own fonts and logo,
 * so nothing is stored and no other site is asked.
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
	/** Email signature banner, in CSS pixels; drawn at twice the size. */
	var BANNER = { width: 600, height: 150, scale: 2 };

	function say(button, text) {
		var root = button.closest(ROOT);
		var live = root && root.querySelector(ROOT + '__live');
		if (live) {
			live.textContent = text;
		}
	}

	/* Copy a colour value, as shown beside the button. */
	function copy(button) {
		var value = button.getAttribute('data-sps-brand-copy');
		var done = function () {
			button.classList.add('is-copied');
			say(button, value + ' copied');
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

	/* The site title and tagline lines, measured and stacked, at scale 1. */
	function textBox(lockup, ctx) {
		var box = { width: 0, height: 0, lines: [] };
		[[lockup.title, lockup.titleFont], [lockup.tagline, lockup.tagFont]].forEach(function (line) {
			if (!line[0]) {
				return;
			}
			var text = transform(line[0], line[1].transform);
			ctx.font = fontString(line[1], 1);
			var width = ctx.measureText(text).width;
			var height = line[1].size * line[1].lineHeight;
			var gap = box.lines.length ? line[1].size * 0.4375 : 0;
			box.lines.push({ text: text, font: line[1], width: width, height: height, top: box.height + gap });
			box.width = Math.max(box.width, width);
			box.height += gap + height;
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
			line.x = at.text.x + (row ? 0 : (text.width - line.width) / 2);
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
			ctx.fillText(line.text, (offsetX + line.x) * scale, (offsetY + line.y) * scale);
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
				out += '<text x="' + (pad + line.x).toFixed(2) + '" y="' + (pad + line.y).toFixed(2) + '" dominant-baseline="central" font-family="' + escapeXml(family(line.font.family)) + '" font-weight="' + line.font.weight + '" font-style="' + line.font.style + '" font-size="' + line.font.size + '" fill="' + escapeXml(line.font.color) + '">' + escapeXml(line.text) + '</text>';
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

	/* The lockup on the light background, fitted inside a 600 by 150 banner. */
	function banner(lockup, parts) {
		var inner = { width: BANNER.width - 48, height: BANNER.height - 32 };
		var fit = Math.min(1, inner.width / parts.width, inner.height / parts.height);
		var canvas = document.createElement('canvas');
		canvas.width = BANNER.width * BANNER.scale;
		canvas.height = BANNER.height * BANNER.scale;
		var scale = fit * BANNER.scale;
		var offsetX = (BANNER.width / fit - parts.width) / 2;
		var offsetY = (BANNER.height / fit - parts.height) / 2;
		draw(lockup, parts, canvas, scale, offsetX, offsetY, lockup.background);
		return new Promise(function (resolve) {
			canvas.toBlob(resolve, 'image/png');
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
		var fonts = [lockup.titleFont, lockup.tagFont].map(function (font) {
			return document.fonts && document.fonts.load ? document.fonts.load(fontString(font, 1)).catch(function () {}) : Promise.resolve();
		});
		var image = lockup.logo ? loadImage(lockup.logo.url) : Promise.resolve(null);
		Promise.all([image].concat(fonts)).then(function (loaded) {
			lockup.img = loaded[0];
			var parts = layout(lockup, document.createElement('canvas').getContext('2d'));
			var base = lockup.file + '-logo-' + lockup.scheme;
			if ('svg' === kind) {
				return svg(lockup, parts).then(function (blob) {
					save(blob, base + '.svg');
				});
			}
			if ('email' === kind) {
				return banner(lockup, parts).then(function (blob) {
					save(blob, lockup.file + '-email-signature.png');
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

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', applyEffects);
	} else {
		applyEffects();
	}
})();
