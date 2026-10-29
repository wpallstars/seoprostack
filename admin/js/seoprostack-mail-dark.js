/**
 * FluentCRM's email designer and its Email Preview: how the email looks in
 * mail apps' own dark mode, and the email's setting for it
 * (SEOProStack_Admin_Whole_Theme::fluent_editor_head() and
 * fluent_mail_dark(); the sent email: SEOProStack_FluentCRM_Email_Dark).
 *
 * Mail apps treat an email two ways in dark mode:
 * - Apple Mail and the apps like it follow the email's own setting: its
 *   dark colours, or light kept; with no setting, they darken it themselves.
 * - Gmail and Outlook for Windows ignore it and darken it themselves.
 * Darkening turns light backgrounds dark and dark text light, keeping their
 * hue, while pictures and colours that are already dark stay.
 *
 * A menu button in the designer's header, and in its Email Preview, shows
 * the email either way. It is a preview only: nothing in the email's page
 * is touched but stylesheets in its <head>, of selectors by position (rich
 * text would save any attribute added to its elements), made again as the
 * email changes. The choice is kept in the browser, and the designer and
 * the screen around it follow each other.
 *
 * The setting is a panel in the designer's Style settings, saved with the
 * email's design settings through FluentCRM's own window._fcrmApplyDesignPreset().
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
	var MODES = ['', 'apple', 'forced'];
	// The preview's stylesheets in the email's page: darkening, no transitions, and the scheme.
	var STYLE = 'seoprostack-mail-dark';
	var STILL = 'seoprostack-mail-dark-still';
	var SCHEME = 'seoprostack-mail-dark-scheme';
	// The sent email's own dark rules (SEOProStack_FluentCRM_Email_Dark::STYLE_ID).
	var OWN = 'seoprostack-email-dark';
	var SETTING = 'sps_dark_mode';
	var BUTTON = 'sps-mail-dark-toggle';
	var MENU = 'sps-mail-dark-menu';
	var text = data.text || {};
	// The dark colours: setting key, FluentCRM's colour property in the designer, default.
	var COLOURS = data.colours || [];
	// The moon as the admin bar's, beside an envelope.
	var ICON = '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
		+ '<path fill="none" stroke="currentColor" stroke-width="1.5" d="M14 17.25H4.25a1.5 1.5 0 0 1-1.5-1.5v-9.5a1.5 1.5 0 0 1 1.5-1.5h13.5a1.5 1.5 0 0 1 1.5 1.5V9M3.25 6.5 11 12l7.75-5.5"/>'
		+ '<path fill="currentColor" d="M22.25 18.9a4.25 4.25 0 0 1-5.6-5.6 4.25 4.25 0 1 0 5.6 5.6z"/></svg>';
	// The button, as the header's own (36 px, FluentCRM's secondary style; FluentCRM hides
	// other buttons there), pressed as WordPress's; the menu, as WordPress's dropdown menus.
	var HOST_CSS = '.' + BUTTON + '.components-button{display:inline-flex!important;width:36px;height:36px;padding:7px;justify-content:center}'
		+ '.' + BUTTON + '.is-on{background:#1e1e1e!important;border-color:#1e1e1e!important;color:#fff!important}'
		+ 'html.sps-dark .' + BUTTON + '.is-on{background:#f0f0f1!important;border-color:#f0f0f1!important;color:#101517!important}'
		+ '.sps-mail-dark-group{margin-left:8px}'
		+ '.' + MENU + '{position:fixed;z-index:1000001;width:280px;box-sizing:border-box;padding:8px;background:#fff;color:#1e1e1e;border:1px solid #e0e0e0;border-radius:4px;box-shadow:0 4px 16px rgba(0,0,0,.15);font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;text-align:left}'
		+ '.' + MENU + ' p{margin:0;padding:4px 8px 8px;font-weight:600}'
		+ '.' + MENU + ' button{position:relative;display:block;width:100%;margin:0;padding:8px 8px 8px 32px;border:0;border-radius:2px;background:none;color:inherit;font:inherit;text-align:left;cursor:pointer}'
		+ '.' + MENU + ' button:hover,.' + MENU + ' button:focus{background:#f0f0f0;outline:none}'
		+ '.' + MENU + ' button:focus-visible{box-shadow:inset 0 0 0 1.5px var(--wp-admin-theme-color,#3858e9)}'
		+ '.' + MENU + ' button[aria-checked="true"]::before{content:"";position:absolute;left:13px;top:10px;width:5px;height:10px;border:solid currentColor;border-width:0 2px 2px 0;transform:rotate(45deg)}'
		+ '.' + MENU + ' strong{display:block;font-weight:500}'
		+ '.' + MENU + ' small{display:block;margin-top:2px;color:#757575;font-size:12px}'
		+ 'html.sps-dark .' + MENU + '{background:#1e1e1e;color:#f0f0f1;border-color:#3c434a}'
		+ 'html.sps-dark .' + MENU + ' button:hover,html.sps-dark .' + MENU + ' button:focus{background:#2c3338}'
		+ 'html.sps-dark .' + MENU + ' small{color:#a7aaad}'
		+ '.sps-email-dark-panel .components-radio-control__option{margin-bottom:4px}';
	var designer = false;
	var mode = stored();
	var queued = false;
	var menu = null;
	var panelBox = null;
	var panelRoot = null;
	// Each frame's email page <body> being watched; a page written again has a new one.
	var watched = new WeakMap();

	function stored() {
		try {
			var value = window.localStorage.getItem(KEY);
			// 1.3.102 kept one choice, on or off.
			if (value === '1') {
				return 'apple';
			}
			return MODES.indexOf(value) > 0 ? value : '';
		} catch (e) {
			return '';
		}
	}
	function store(value) {
		try {
			if (value) {
				window.localStorage.setItem(KEY, value);
			} else {
				window.localStorage.removeItem(KEY);
			}
		} catch (e) {
			// Private browsing: this screen still follows the button.
		}
	}

	// The email's design settings in the designer.
	function config() {
		return window._fcrmStyleConfig || {};
	}
	// The email's setting: from its design settings in the designer, from
	// what the sent email has in the Email Preview.
	function setting(doc) {
		if (designer) {
			var value = config()[SETTING];
			return value === 'light' || value === 'custom' ? value : 'auto';
		}
		if (doc.getElementById(OWN)) {
			return 'custom';
		}
		return doc.querySelector('meta[name="color-scheme"][content*="only"]') ? 'light' : 'auto';
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

	// The darkening rules for one email page, read from its colours as they are without them.
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

	// A stylesheet of the preview's in the email's page: made, changed or removed.
	function sheet(doc, id, content) {
		var style = doc.getElementById(id);
		if (!content) {
			if (style) {
				style.remove();
			}
			return;
		}
		if (!style) {
			style = doc.createElement('style');
			style.id = id;
			doc.head.appendChild(style);
		}
		if (style.textContent !== content) {
			style.textContent = content;
		}
	}

	// The email's dark colours as FluentCRM's colour properties in the designer.
	// Inside a block with a background of its own, which stays as it is, text
	// keeps its light mode colours, as in the sent email.
	function darkColours() {
		var cfg = config();
		var keep = [];
		var dark = COLOURS.map(function (c) {
			var value = /^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(cfg[c.key] || '') ? cfg[c.key] : c.fallback;
			if (!/_bg_/.test(c.global) && /^#[0-9a-f]{3,8}$/i.test(cfg[c.global] || '')) {
				keep.push(c.property + ':' + cfg[c.global]);
				// The canvas works out its text colour at :root, so it is inherited as a colour.
				if (c.global === 'text_color') {
					keep.push('color:' + cfg[c.global]);
				}
			}
			return c.property + ':' + value + '!important';
		});
		return 'body{' + dark.join(';') + '}' + (keep.length ? '.has-background{' + keep.join(';') + '}' : '');
	}

	function paint(doc) {
		// A page its frame has since replaced or left has no window.
		if (!doc || !doc.defaultView || !doc.head || !doc.body) {
			return;
		}
		var own = setting(doc);
		// The app shows the email's own dark colours.
		var ownDark = mode === 'apple' && own === 'custom';
		// The app darkens the email itself.
		var darken = mode === 'forced' || (mode === 'apple' && own === 'auto');
		// The sent email's own dark rules (Email Preview): on as the app reads
		// them, whatever the computer's mode, else off.
		var rulesOwn = doc.getElementById(OWN);
		if (rulesOwn) {
			if (!rulesOwn.hasAttribute('data-sps-rules')) {
				rulesOwn.setAttribute('data-sps-rules', rulesOwn.textContent);
			}
			var wanted = rulesOwn.getAttribute('data-sps-rules').replace('(prefers-color-scheme: dark)', ownDark ? 'all' : 'not all');
			if (rulesOwn.textContent !== wanted) {
				rulesOwn.textContent = wanted;
			}
		}
		var scheme = '';
		if (ownDark) {
			scheme = 'html{color-scheme:dark!important}' + (designer ? darkColours() : '');
		} else if (!darken && !designer) {
			// An email with "light dark" would follow the computer's mode in the Email Preview.
			scheme = 'html{color-scheme:light only!important}';
		}
		sheet(doc, SCHEME, scheme);
		if (!darken) {
			sheet(doc, STYLE, '');
			sheet(doc, STILL, '');
			return;
		}
		// No colour transitions while the rules are read and put back.
		sheet(doc, STILL, '*,*::before,*::after{transition:none!important}');
		var style = doc.getElementById(STYLE);
		if (!style) {
			style = doc.createElement('style');
			style.id = STYLE;
			doc.head.appendChild(style);
		}
		// Read the email's own colours, then put the dark ones back, in one go: nothing is drawn between.
		style.media = 'not all';
		style.textContent = rules(doc);
		style.media = 'all';
	}

	// Watch an email page: its content, its <body>'s colour settings and
	// its stylesheets, made again after a pause.
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
			return;
		}
		watched.set(frame, doc.body);
		var timer = 0;
		var observer;
		var later = function () {
			if (watched.get(frame) !== doc.body || !frame.isConnected) {
				// The frame shows another page now, or is gone.
				observer.disconnect();
				return;
			}
			window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				paint(doc);
			}, 250);
		};
		observer = new MutationObserver(later);
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
	function refresh() {
		frames().forEach(function (frame) {
			try {
				paint(frame.contentDocument);
			} catch (e) {
				// Another site's page: not FluentCRM's.
			}
			watch(frame);
		});
	}

	function label() {
		// Not "Preview": FluentCRM hides the header's buttons with it in their name.
		return text.label || 'Mail apps’ dark mode';
	}

	// The menu: off, or one kind of mail app.
	function closeMenu(focus) {
		if (!menu) {
			return;
		}
		var opener = menu.opener;
		menu.remove();
		menu = null;
		document.removeEventListener('mousedown', outside, true);
		if (opener) {
			opener.setAttribute('aria-expanded', 'false');
			if (focus) {
				opener.focus();
			}
		}
	}
	function outside(event) {
		if (menu && !menu.contains(event.target) && !menu.opener.contains(event.target)) {
			closeMenu(false);
		}
	}
	function openMenu(opener) {
		closeMenu(false);
		menu = document.createElement('div');
		menu.className = MENU;
		menu.setAttribute('role', 'menu');
		menu.setAttribute('aria-label', label());
		menu.opener = opener;
		var title = document.createElement('p');
		title.textContent = label();
		menu.appendChild(title);
		(text.modes || []).forEach(function (item) {
			var b = document.createElement('button');
			b.type = 'button';
			b.setAttribute('role', 'menuitemradio');
			b.setAttribute('aria-checked', item.value === mode ? 'true' : 'false');
			var name = document.createElement('strong');
			name.textContent = item.label;
			b.appendChild(name);
			if (item.hint) {
				var hint = document.createElement('small');
				hint.textContent = item.hint;
				b.appendChild(hint);
			}
			b.addEventListener('click', function () {
				set(item.value);
				closeMenu(true);
			});
			menu.appendChild(b);
		});
		menu.addEventListener('keydown', function (event) {
			var items = Array.prototype.slice.call(menu.querySelectorAll('button'));
			var at = items.indexOf(document.activeElement);
			if (event.key === 'Escape') {
				event.preventDefault();
				closeMenu(true);
			} else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault();
				items[(at + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus();
			} else if (event.key === 'Tab') {
				closeMenu(false);
			}
		});
		document.body.appendChild(menu);
		var box = opener.getBoundingClientRect();
		var left = Math.max(8, Math.min(box.right - menu.offsetWidth, window.innerWidth - menu.offsetWidth - 8));
		menu.style.left = left + 'px';
		menu.style.top = (box.bottom + 6) + 'px';
		opener.setAttribute('aria-expanded', 'true');
		document.addEventListener('mousedown', outside, true);
		var checked = menu.querySelector('[aria-checked="true"]');
		(checked || menu.querySelector('button')).focus();
	}

	function button(className) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = className + ' ' + BUTTON;
		b.setAttribute('aria-label', label());
		b.setAttribute('aria-haspopup', 'menu');
		b.setAttribute('aria-expanded', 'false');
		b.title = text.hint ? label() + '. ' + text.hint : label();
		b.innerHTML = ICON;
		b.addEventListener('click', function () {
			if (menu && menu.opener === b) {
				closeMenu(false);
			} else {
				openMenu(b);
			}
		});
		return b;
	}
	function mark() {
		Array.prototype.forEach.call(document.querySelectorAll('.' + BUTTON), function (b) {
			b.classList.toggle('is-on', !!mode);
			b.classList.toggle('active', !!mode && !designer);
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

	// The email's setting: a panel at the end of the designer's Style settings.
	function Panel() {
		var wp = window.wp;
		var el = wp.element.createElement;
		var C = wp.components;
		var state = wp.element.useState(config);
		var cfg = state[0];
		var setCfg = state[1];
		wp.element.useEffect(function () {
			// Changes made elsewhere: a template or design imported. FluentCRM
			// replaces its settings object on every change.
			var seen = config();
			var follow = function () {
				window.setTimeout(function () {
					if (config() !== seen) {
						seen = config();
						setCfg(seen);
						refresh();
					}
				}, 0);
			};
			window.addEventListener('fcrm:style-config-sync', follow);
			window.addEventListener('message', follow);
			return function () {
				window.removeEventListener('fcrm:style-config-sync', follow);
				window.removeEventListener('message', follow);
			};
		}, []);
		function save(changes) {
			if (typeof window._fcrmApplyDesignPreset !== 'function') {
				return;
			}
			window._fcrmApplyDesignPreset(changes);
			setCfg(config());
			refresh();
		}
		var value = cfg[SETTING] === 'light' || cfg[SETTING] === 'custom' ? cfg[SETTING] : 'auto';
		var children = [
			el(C.RadioControl, {
				key: 'mode',
				label: text.setting,
				hideLabelFromVision: true,
				selected: value,
				options: (text.settings || []).map(function (o) {
					return { value: o.value, label: o.label };
				}),
				onChange: function (next) {
					var changes = {};
					changes[SETTING] = next;
					save(changes);
				}
			})
		];
		(text.settings || []).forEach(function (o) {
			if (o.value === value && o.help) {
				children.push(el('p', { key: 'help', className: 'components-base-control__help' }, o.help));
			}
		});
		if (value === 'custom' && wp.blockEditor && wp.blockEditor.PanelColorSettings) {
			children.push(el(wp.blockEditor.PanelColorSettings, {
				key: 'colours',
				__experimentalIsRenderedInSidebar: true,
				title: text.colours,
				initialOpen: true,
				className: 'fcrm-sub-panel',
				colorSettings: COLOURS.map(function (c) {
					return {
						value: cfg[c.key] || c.fallback,
						label: c.label,
						onChange: function (next) {
							var changes = {};
							changes[c.key] = next || '';
							save(changes);
						}
					};
				})
			}));
		}
		return el(C.PanelBody, { title: text.panel, initialOpen: value !== 'auto', className: 'sps-email-dark-panel' }, children);
	}
	function panel() {
		var wp = window.wp;
		if (!designer || !wp || !wp.element || !wp.components || !text.panel) {
			return;
		}
		var host = document.querySelector('.fcrm-email-style-settings');
		if (panelBox && host && panelBox.parentNode === host) {
			return;
		}
		if (panelRoot) {
			// The settings were closed or drawn again.
			if (panelRoot.unmount) {
				panelRoot.unmount();
			} else if (wp.element.unmountComponentAtNode) {
				wp.element.unmountComponentAtNode(panelBox);
			}
			panelRoot = null;
			panelBox = null;
		}
		if (!host || typeof window._fcrmApplyDesignPreset !== 'function') {
			return;
		}
		panelBox = document.createElement('div');
		panelBox.className = 'sps-email-dark-panel-box';
		host.appendChild(panelBox);
		if (wp.element.createRoot) {
			panelRoot = wp.element.createRoot(panelBox);
			panelRoot.render(wp.element.createElement(Panel));
		} else {
			wp.element.render(wp.element.createElement(Panel), panelBox);
			panelRoot = {};
		}
	}

	function sync() {
		queued = false;
		buttons();
		panel();
		frames().forEach(watch);
	}
	function soon() {
		if (!queued) {
			queued = true;
			window.requestAnimationFrame(sync);
		}
	}

	function set(value) {
		mode = MODES.indexOf(value) > 0 ? value : '';
		store(mode);
		mark();
		refresh();
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
			if (event.key === KEY && stored() !== mode) {
				set(stored());
			}
		});
		window.addEventListener('resize', function () {
			closeMenu(false);
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})(window.seoprostackMailDark);
