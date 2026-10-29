/**
 * FluentCRM's email designer: a preview of how mail apps show the email in
 * their own dark mode (SEOProStack_Admin_Whole_Theme::fluent_editor_head()
 * and fluent_mail_dark()).
 *
 * Apple Mail, Outlook and Gmail darken an email that sets no dark colours
 * of its own, as FluentCRM's emails do not: light backgrounds turn dark and
 * dark text light, keeping their hue, while pictures and colours that are
 * already dark stay. A button in the designer's header, and in its Email
 * Preview, shows the email that way. It is a preview only: the blocks and
 * the email that is saved and sent are unchanged. Nothing in the email's
 * page is touched but one stylesheet in its <head>, of selectors by
 * position (rich text would save any attribute added to its elements),
 * made again as the email changes. The choice is kept in the browser, and
 * the designer and the screen around it follow each other.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (data) {
	'use strict';
	if (!data || !window.MutationObserver) {
		return;
	}
	var KEY = 'seoprostack-mail-dark';
	var STYLE = 'seoprostack-mail-dark';
	var STILL = 'seoprostack-mail-dark-still';
	var BUTTON = 'sps-mail-dark-toggle';
	// The moon as the admin bar's, beside an envelope.
	var ICON = '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
		+ '<path fill="none" stroke="currentColor" stroke-width="1.5" d="M14 17.25H4.25a1.5 1.5 0 0 1-1.5-1.5v-9.5a1.5 1.5 0 0 1 1.5-1.5h13.5a1.5 1.5 0 0 1 1.5 1.5V9M3.25 6.5 11 12l7.75-5.5"/>'
		+ '<path fill="currentColor" d="M22.25 18.9a4.25 4.25 0 0 1-5.6-5.6 4.25 4.25 0 1 0 5.6 5.6z"/></svg>';
	// The button, as the header's own (36 px, FluentCRM's secondary style; FluentCRM hides
	// other buttons there), pressed as WordPress's.
	var HOST_CSS = '.' + BUTTON + '.components-button{display:inline-flex!important;width:36px;height:36px;padding:7px;justify-content:center}'
		+ '.' + BUTTON + '[aria-pressed="true"]{background:#1e1e1e!important;border-color:#1e1e1e!important;color:#fff!important}'
		+ 'html.sps-dark .' + BUTTON + '[aria-pressed="true"]{background:#f0f0f1!important;border-color:#f0f0f1!important;color:#101517!important}'
		+ '.sps-mail-dark-group{margin-left:8px}';
	var designer = false;
	var on = stored();
	var queued = false;
	// Each frame's email page <body> being watched; a page written again has a new one.
	var watched = new WeakMap();

	function stored() {
		try {
			return window.localStorage.getItem(KEY) === '1';
		} catch (e) {
			return false;
		}
	}
	function store(value) {
		try {
			if (value) {
				window.localStorage.setItem(KEY, '1');
			} else {
				window.localStorage.removeItem(KEY);
			}
		} catch (e) {
			// Private browsing: this screen still follows the button.
		}
	}

	// Colours: computed values come as rgb()/rgba().
	function parse(value) {
		var m = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+)(%?))?\s*\)$/.exec(value || '');
		if (!m) {
			return null;
		}
		var alpha = m[4] === undefined ? 1 : parseFloat(m[4]) / (m[5] ? 100 : 1);
		return { r: +m[1], g: +m[2], b: +m[3], a: alpha };
	}
	function luminance(c) {
		function lin(v) {
			v /= 255;
			return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
		}
		return 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
	}
	function toHsl(c) {
		var r = c.r / 255, g = c.g / 255, b = c.b / 255;
		var max = Math.max(r, g, b), min = Math.min(r, g, b);
		var l = (max + min) / 2, h = 0, s = 0, d = max - min;
		if (d) {
			s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
			h = max === r ? (g - b) / d + (g < b ? 6 : 0) : (max === g ? (b - r) / d + 2 : (r - g) / d + 4);
			h /= 6;
		}
		return { h: h, s: s, l: l };
	}
	function css(hsl, alpha) {
		var h = Math.round(hsl.h * 360), s = Math.round(hsl.s * 100), l = Math.round(hsl.l * 100);
		return alpha < 1 ? 'hsla(' + h + ',' + s + '%,' + l + '%,' + alpha + ')' : 'hsl(' + h + ',' + s + '%,' + l + '%)';
	}
	// A light ground turns dark: white as mail apps' dark grey (#1f1f1f), lighter tints darker.
	function ground(value, edge) {
		var c = parse(value);
		if (!c || c.a < 0.05 || luminance(c) < 0.35) {
			return '';
		}
		var hsl = toHsl(c);
		hsl.l = (edge ? 0.22 : 0.12) + (1 - hsl.l) * 0.5;
		return css(hsl, c.a);
	}
	// Dark text turns light: black as #ebebeb, greys and colours lighter by as much as they were dark.
	function ink(value) {
		var c = parse(value);
		if (!c || c.a < 0.05 || luminance(c) > 0.2) {
			return '';
		}
		var hsl = toHsl(c);
		hsl.l = Math.min(0.92, 0.95 - hsl.l * 0.6);
		return css(hsl, c.a);
	}

	// The element's place, from <body>: the email's page has no ids or classes to rely on.
	// Two ids' weight, so the email's and the editor's own !important rules give way.
	var WEIGHT = ':not(#sps-mail-dark):not(#sps-mail-dark)';
	function path(el, body, cache) {
		if (el === body) {
			return 'body' + WEIGHT;
		}
		if (cache.has(el)) {
			return cache.get(el);
		}
		var parent = el.parentElement;
		var n = 1;
		for (var sib = el.previousElementSibling; sib; sib = sib.previousElementSibling) {
			n++;
		}
		var selector = path(parent, body, cache) + '>' + el.tagName.toLowerCase() + ':nth-child(' + n + ')';
		cache.set(el, selector);
		return selector;
	}

	var SKIP = { IMG: 1, PICTURE: 1, SOURCE: 1, VIDEO: 1, AUDIO: 1, CANVAS: 1, IFRAME: 1, SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEMPLATE: 1, BR: 1, WBR: 1 };
	var SIDES = ['top', 'right', 'bottom', 'left'];

	// The rules for one email page, read from its colours as they are without them.
	function rules(doc) {
		var body = doc.body;
		var view = doc.defaultView;
		var cache = new Map();
		var out = ['html' + WEIGHT + '{color-scheme:dark;background:#1f1f1f!important}'];
		var all = [body].concat(Array.prototype.slice.call(body.getElementsByTagName('*')));
		all.forEach(function (el) {
			if (SKIP[el.tagName] || el.namespaceURI !== 'http://www.w3.org/1999/xhtml') {
				return;
			}
			var cs = view.getComputedStyle(el);
			if (cs.display === 'none') {
				return;
			}
			var decl = [];
			var bg = ground(cs.backgroundColor, false);
			var fg = ink(cs.color);
			if (bg) {
				decl.push('background-color:' + bg + '!important');
			}
			if (fg) {
				decl.push('color:' + fg + '!important');
			}
			SIDES.forEach(function (side) {
				if (parseFloat(cs.getPropertyValue('border-' + side + '-width')) > 0) {
					var line = ground(cs.getPropertyValue('border-' + side + '-color'), true);
					if (line) {
						decl.push('border-' + side + '-color:' + line + '!important');
					}
				}
			});
			if (decl.length) {
				out.push(path(el, body, cache) + '{' + decl.join(';') + '}');
			}
		});
		return out.join('\n');
	}

	function paint(doc) {
		if (!doc || !doc.head || !doc.body) {
			return;
		}
		var style = doc.getElementById(STYLE);
		var still = doc.getElementById(STILL);
		if (!on) {
			if (style) {
				style.remove();
			}
			if (still) {
				still.remove();
			}
			return;
		}
		if (!still) {
			// No colour transitions while the rules are read and put back.
			still = doc.createElement('style');
			still.id = STILL;
			still.textContent = '*,*::before,*::after{transition:none!important}';
			doc.head.appendChild(still);
		}
		if (!style) {
			style = doc.createElement('style');
			style.id = STYLE;
			style.media = 'not all';
			doc.head.appendChild(style);
		}
		// Read the email's own colours, then put the dark ones back, in one go: nothing is drawn between.
		style.media = 'not all';
		style.textContent = rules(doc);
		style.media = 'all';
	}

	// Watch an email page while the preview is on: its content, its <body>'s
	// colour settings and its stylesheets, made again after a pause.
	function watch(frame) {
		var doc;
		try {
			doc = frame.contentDocument;
		} catch (e) {
			return;
		}
		if (!doc || !doc.body || !doc.head) {
			return;
		}
		if (watched.get(frame) === doc.body) {
			if (on && !doc.getElementById(STYLE)) {
				paint(doc);
			}
			return;
		}
		watched.set(frame, doc.body);
		var timer = 0;
		var later = function () {
			if (!on) {
				return;
			}
			window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				paint(doc);
			}, 250);
		};
		var observer = new MutationObserver(later);
		observer.observe(doc.body, { subtree: true, childList: true, attributes: true, characterData: true });
		observer.observe(doc.head, { childList: true });
		paint(doc);
		// Stylesheets that are still loading.
		[400, 1500].forEach(function (wait) {
			window.setTimeout(later, wait);
		});
	}

	function frames() {
		var list = document.querySelectorAll(designer ? 'iframe[name="editor-canvas"]' : '.fcrm_email_preview_shell iframe');
		return Array.prototype.slice.call(list);
	}

	function label() {
		// Not "Preview": FluentCRM hides the header's buttons with it in their name.
		return data.label || 'Show in mail apps’ dark mode';
	}
	function button(className) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = className + ' ' + BUTTON;
		b.setAttribute('aria-label', label());
		b.title = data.hint ? label() + '. ' + data.hint : label();
		b.innerHTML = ICON;
		b.addEventListener('click', function () {
			set(!on);
		});
		return b;
	}
	function mark() {
		Array.prototype.forEach.call(document.querySelectorAll('.' + BUTTON), function (b) {
			b.setAttribute('aria-pressed', on ? 'true' : 'false');
			b.classList.toggle('is-pressed', on);
			b.classList.toggle('active', on && !designer);
		});
	}

	// The buttons: in the designer's header, after Email Preview; in the
	// Email Preview, after the desktop, tablet and mobile buttons.
	function buttons() {
		if (!document.getElementById(STYLE + '-ui')) {
			var style = document.createElement('style');
			style.id = STYLE + '-ui';
			style.textContent = HOST_CSS;
			(document.head || document.documentElement).appendChild(style);
		}
		if (designer) {
			var bar = document.querySelector('.editor-header__settings');
			if (bar && !bar.querySelector('.' + BUTTON)) {
				var eye = bar.querySelector('.fcrm-email-preview-btn');
				bar.insertBefore(button('components-button fcrm_secondary_btn'), eye ? eye.nextSibling : bar.firstChild);
			}
		} else {
			Array.prototype.forEach.call(document.querySelectorAll('.fcrm_preview_device_toggle'), function (box) {
				if (box.querySelector('.' + BUTTON)) {
					return;
				}
				var group = document.createElement('div');
				group.className = 'fcrm_device_btn_group sps-mail-dark-group';
				group.appendChild(button('fcrm_device_btn'));
				box.appendChild(group);
			});
		}
		mark();
	}

	function sync() {
		queued = false;
		buttons();
		frames().forEach(watch);
	}
	function soon() {
		if (!queued) {
			queued = true;
			window.requestAnimationFrame(sync);
		}
	}

	function set(value) {
		on = !!value;
		store(on);
		mark();
		frames().forEach(function (frame) {
			try {
				paint(frame.contentDocument);
			} catch (e) {
				// Another site's page: not FluentCRM's.
			}
			watch(frame);
		});
	}

	function start() {
		designer = !!(document.body && document.body.classList.contains('fcrm_custom_editor'));
		sync();
		new MutationObserver(soon).observe(document.body, { subtree: true, childList: true });
		// A frame's load event does not bubble, so it is caught on the way down.
		document.addEventListener('load', function (event) {
			if (event.target && event.target.tagName === 'IFRAME') {
				soon();
			}
		}, true);
		// The designer and the screen around it share the choice.
		window.addEventListener('storage', function (event) {
			if (event.key === KEY && stored() !== on) {
				set(stored());
			}
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})(window.seoprostackMailDark);
