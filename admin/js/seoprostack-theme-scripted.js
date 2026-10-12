/**
 * Dark mode for colours a screen's own script sets (Emotion, CSS in JS, as
 * on Tutor LMS's React screens; SEOProStack_Admin_Theme::script_styles()).
 *
 * Its rules use class names made from a hash of the styles (`css-1cg1ee4`),
 * new with each version, so the dark stylesheet cannot name them. Instead,
 * each rule is read as it is added, before anything uses it, and each light
 * ground, dark grey text and light line takes the dark palette's colour,
 * with its own as the fallback: `var(--sps-ui-surface, rgb(255, 255, 255))`.
 * The palette is set only in Dark, so Light keeps the screen's own colours,
 * and a switch either way needs nothing redone. White grounds become the
 * surface, pale greys raised, pale tints a dark shade of their hue, light
 * grey lines the borders; brand and other strong colours stay (the contrast
 * guard in seoprostack-theme.js lifts text on them that does not read).
 *
 * It loads in the <head>, before the screen's script draws anything, so
 * nothing is drawn light first and then eased to dark.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 */
(function () {
	'use strict';
	if (!window.MutationObserver || !window.WeakSet || !document.head) {
		return;
	}
	// Emotion's style tags, but its global ones (resets).
	var TAGS = 'style[data-emotion]:not([data-emotion$="-global"])';
	var LINES = ['border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color'];
	var adopted = new WeakSet();
	var shades = {};
	var shadeSheet = null;

	function parse(value) {
		if (value === 'white') {
			return { r: 255, g: 255, b: 255, a: 1 };
		}
		var m = /^rgba?\(([^)]+)\)$/.exec(value || '');
		if (!m) {
			return null;
		}
		var parts = m[1].split(/[\s,/]+/).filter(Boolean);
		// Only plain channel numbers (as styles read back give them); any other form keeps its colour.
		if (parts.length < 3 || parts.some(function (part) { return part.indexOf('%') !== -1; })) {
			return null;
		}
		var p = parts.map(Number);
		if (!p.every(isFinite)) {
			return null;
		}
		return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
	}
	function chroma(c) {
		return Math.max(c.r, c.g, c.b) - Math.min(c.r, c.g, c.b);
	}
	function lightness(c) {
		return (Math.max(c.r, c.g, c.b) + Math.min(c.r, c.g, c.b)) / 510;
	}
	// A pale tint's dark shade, `level` light, in a custom property set in Dark only.
	function shade(c, level) {
		var name = '--sps-ui-shade-' + [c.r, c.g, c.b, level].join('-');
		if (!shades[name]) {
			var max = Math.max(c.r, c.g, c.b);
			var d = chroma(c);
			var h = max === c.r ? (c.g - c.b) / d : (max === c.g ? (c.b - c.r) / d + 2 : (c.r - c.g) / d + 4);
			var s = Math.min(0.45, d / 255 / (1 - Math.abs(2 * lightness(c) - 1)));
			if (!shadeSheet) {
				var tag = document.createElement('style');
				tag.id = 'seoprostack-theme-shades';
				document.head.appendChild(tag);
				shadeSheet = tag.sheet;
			}
			shadeSheet.insertRule('html.sps-dark{' + name + ':hsl(' + Math.round((h * 60 + 360) % 360) + ' ' + Math.round(s * 100) + '% ' + level + '%)}', shadeSheet.cssRules.length);
			shades[name] = true;
		}
		return name;
	}
	// The palette's colour for a light ground, dark grey text or a light line; '' keeps it.
	function ground(c) {
		var l = lightness(c);
		if (c.a < 0.99 || l < 0.75 || (l < 0.85 && chroma(c) >= 12)) {
			return '';
		}
		// WordPress's own page ground (#f0f0f1, or #f1f1f1), as behind a panel or in a cut-out.
		if (/^(240,240,241|241,241,241)$/.test([c.r, c.g, c.b].join())) {
			return '--sps-ui-canvas';
		}
		if (chroma(c) >= 12 && l < 0.985) {
			return shade(c, 16);
		}
		// Mid greys: a switch's track when off, a handle.
		if (l < 0.85) {
			return '--sps-ui-field-border';
		}
		return l >= 0.985 ? '--sps-ui-surface' : '--sps-ui-raised';
	}
	function ink(c) {
		var l = lightness(c);
		if (c.a < 0.99 || chroma(c) > 40 || l >= 0.56) {
			return '';
		}
		if (l < 0.3) {
			return '--sps-ui-text';
		}
		return l < 0.45 ? '--sps-ui-muted' : '--sps-ui-subtle';
	}
	function line(c) {
		var l = lightness(c);
		if (c.a < 0.99 || l < 0.65) {
			return '';
		}
		if (l >= 0.985) {
			return '--sps-ui-surface';
		}
		if (chroma(c) >= 12) {
			return shade(c, 30);
		}
		return l >= 0.8 ? '--sps-ui-border' : '--sps-ui-field-border';
	}
	// `value` with each colour `to` maps in the palette, or '' when none is.
	function swap(value, to) {
		var changed = false;
		var next = value.replace(/rgba?\([^)]*\)|\bwhite\b/g, function (part) {
			var c = parse(part);
			var name = c ? to(c) : '';
			changed = changed || !!name;
			return name ? 'var(' + name + ', ' + part + ')' : part;
		});
		return changed ? next : '';
	}
	function set(style, prop, to) {
		var value = style.getPropertyValue(prop);
		var next = value && value.indexOf('var(') === -1 ? swap(value, to) : '';
		if (next) {
			style.setProperty(prop, next, style.getPropertyPriority(prop));
		}
	}
	// A rule, or each rule in a group (@media, @supports).
	function recolour(rule) {
		if (!rule) {
			return;
		}
		if (rule.style && rule.selectorText) {
			set(rule.style, 'background-color', ground);
			set(rule.style, 'color', ink);
			LINES.forEach(function (prop) {
				set(rule.style, prop, line);
			});
			// Lines drawn as inset shadows; white ones (a focus ring's gap) stay.
			set(rule.style, 'box-shadow', function (c) {
				return lightness(c) < 0.985 ? line(c) : '';
			});
		} else if (rule.cssRules) {
			for (var i = 0; i < rule.cssRules.length; i++) {
				recolour(rule.cssRules[i]);
			}
		}
	}
	/*
	 * A style tag's rules, and each rule it is given later: Emotion adds them
	 * one by one with insertRule() as components first draw, so they are
	 * recoloured there, before the elements using them are on the screen.
	 */
	function adopt(tag) {
		var sheet = tag.sheet;
		var list = null;
		if (!sheet || adopted.has(sheet)) {
			return;
		}
		adopted.add(sheet);
		try {
			list = sheet.cssRules;
		} catch (e) {
			return;
		}
		for (var i = 0; i < list.length; i++) {
			recolour(list[i]);
		}
		var insert = sheet.insertRule;
		sheet.insertRule = function () {
			var at = insert.apply(this, arguments);
			try {
				recolour(this.cssRules[at]);
			} catch (e) {
				// The rule keeps its own colours.
			}
			return at;
		};
	}
	// Emotion's tags, as it adds them to the <head>.
	function scan() {
		var tags = document.head.querySelectorAll(TAGS);
		for (var i = 0; i < tags.length; i++) {
			adopt(tags[i]);
		}
	}
	scan();
	new MutationObserver(scan).observe(document.head, { childList: true });
}());
