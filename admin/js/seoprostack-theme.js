/**
 * Colour mode for the whole of wp-admin, from the admin bar
 * (SEOProStack_Admin_Theme): a click turns Dark on or off; the menu offers
 * Light, Dark and System.
 *
 * The screen's <head> has already set the mode's classes on <html>; this
 * switches them at once, loads the dark stylesheet on the first switch to
 * dark, saves the choice, and puts it back if saving fails. Every
 * wpallstars plugin with a dark mode shares the choice: after a change is
 * saved, it is sent on `document` as `wpallstars-admin-theme`
 * ({ mode, source }), and changes from the others are applied here
 * without saving or sending again. `sps-themechange` ({ mode, dark }) tells
 * SEO Pro Stack's own screens.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 */
(function (wp, data) {
	'use strict';
	if (!wp || !wp.i18n || !data) {
		return;
	}
	var __ = wp.i18n.__;
	var SOURCE = 'seoprostack';
	var EVENT = 'wpallstars-admin-theme';
	var root = document.documentElement;
	var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
	var order = ['light', 'dark', 'system'];
	var labels = { light: __('Light', 'seoprostack'), dark: __('Dark', 'seoprostack'), system: __('System', 'seoprostack') };
	var mode = order.indexOf(data.mode) === -1 ? 'light' : data.mode;
	var dark = root.classList.contains('sps-dark');
	var shown = mode;
	var saving = false;
	var guardTimer = 0;
	var node;
	var top;
	var items = [];

	function speak(message, politeness) {
		if (wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(message, politeness || 'polite');
		}
	}
	function name(which) {
		/* translators: %s: Light, Dark or System. */
		return wp.i18n.sprintf(__('Colour mode: %s', 'seoprostack'), labels[which]);
	}
	function isDark(which) {
		return which === 'dark' || (which === 'system' && !!media && media.matches);
	}
	function icon(which) {
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		path.setAttribute('d', data.icons[which]);
		path.setAttribute('fill-rule', 'evenodd');
		svg.appendChild(path);
		return svg;
	}

	// The dark stylesheet, loaded on the first switch to dark when the screen opened light.
	function withStyles(then) {
		if (!data.css || document.getElementById('seoprostack-theme-css')) {
			then();
			return;
		}
		var link = document.createElement('link');
		link.id = 'seoprostack-theme-css';
		link.rel = 'stylesheet';
		link.href = data.css;
		link.onload = then;
		link.onerror = then;
		document.head.appendChild(link);
	}

	/*
	 * Contrast guard, in Dark only: text another plugin's styles leave hard
	 * to read (below WCAG AA, 4.5:1, or 3:1 for large text) against what is
	 * behind it gets the nearest colour that reads, keeping its hue: lighter
	 * on a dark background, darker on a light one. It checks the screen once
	 * it is read, new content and changed classes before the next frame is
	 * drawn, and whatever is pointed at or focused, once its hover colours
	 * have settled. Only text colours change, inline, and every change is
	 * undone when the screen leaves Dark. Backgrounds are left to the
	 * stylesheet, so nothing flashes.
	 */
	var guard = (function () {
		// Not checked: WordPress's own chrome, the editor's canvas (the theme's colours), graphics and hidden text.
		var SKIP = '#wpadminbar, #adminmenumain, script, style, noscript, template, svg, iframe, canvas, video, img, option, optgroup, .screen-reader-text, .editor-styles-wrapper, .block-editor-block-preview__content, .sps-contrast-skip';
		var TEXT = /^(text|search|email|url|tel|number|password|date|datetime-local|month|time|week)?$/;
		var fixed = new Map();
		var queue = [];
		var frame = 0;
		var watcher = null;
		var on = false;
		var bgs = null;

		function rgb(value) {
			var m = /rgba?\(([^)]+)\)/.exec(value || '');
			if (!m) {
				return null;
			}
			var p = m[1].split(/[\s,/]+/).filter(Boolean).map(parseFloat);
			return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
		}
		function over(top, under) {
			return { r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 };
		}
		function luminance(c) {
			function lin(v) {
				v /= 255;
				return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
			}
			return 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
		}
		function ratio(a, b) {
			var x = luminance(a);
			var y = luminance(b);
			return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
		}
		// What is painted behind an element: its own and its ancestors' colours, or null over an image or gradient.
		function behind(el) {
			if (bgs.has(el)) {
				return bgs.get(el);
			}
			var style = getComputedStyle(el);
			var result;
			var own = rgb(style.backgroundColor);
			if (style.backgroundImage && style.backgroundImage !== 'none' && el !== document.body && el !== root) {
				result = null;
			} else if (own && own.a >= 0.99) {
				result = own;
			} else {
				var under = el.parentElement ? behind(el.parentElement) : { r: 16, g: 21, b: 23, a: 1 };
				result = under && own && own.a > 0 ? over(own, under) : under;
			}
			bgs.set(el, result);
			return result;
		}
		// The colour nearest to `fg` that reads on `bg`: mixed with white on dark, with black on light.
		function readable(fg, bg, need) {
			var to = luminance(bg) < 0.18 ? 255 : 0;
			for (var step = 1; step <= 20; step++) {
				var k = step / 20;
				var c = { r: fg.r + (to - fg.r) * k, g: fg.g + (to - fg.g) * k, b: fg.b + (to - fg.b) * k, a: 1 };
				if (ratio(c, bg) >= need + 0.1) {
					return 'rgb(' + Math.round(c.r) + ' ' + Math.round(c.g) + ' ' + Math.round(c.b) + ')';
				}
			}
			return to ? '#fff' : '#000';
		}
		function hasText(el) {
			var tag = el.tagName;
			if (tag === 'INPUT') {
				return TEXT.test(el.type) && el.value !== '';
			}
			if (tag === 'SELECT' || tag === 'TEXTAREA') {
				return true;
			}
			for (var n = el.firstChild; n; n = n.nextSibling) {
				if (n.nodeType === 3 && /\S/.test(n.nodeValue)) {
					return true;
				}
			}
			return false;
		}
		// Undo a change, measuring the screen's own colour without a transition.
		function release(el) {
			var was = fixed.get(el);
			if (!was) {
				return null;
			}
			fixed.delete(el);
			el.style.setProperty('transition', 'none', 'important');
			if (was.color) {
				el.style.setProperty('color', was.color, was.priority);
			} else {
				el.style.removeProperty('color');
			}
			return was;
		}
		// Put the transition back once the new colour is in place (reading it applies it), so it does not animate.
		function settle(el, was) {
			if (was) {
				if (getComputedStyle(el).color && was.transition) {
					el.style.setProperty('transition', was.transition, was.transitionPriority);
				} else {
					el.style.removeProperty('transition');
				}
			}
		}
		function check(el) {
			var was = release(el);
			var style = getComputedStyle(el);
			var fg = rgb(style.color);
			var bg = fg && fg.a > 0 ? behind(el) : null;
			if (bg && !el.disabled && el.getAttribute('aria-disabled') !== 'true') {
				var size = parseFloat(style.fontSize);
				var need = size >= 24 || (size >= 18.6 && parseInt(style.fontWeight, 10) >= 700) ? 3 : 4.5;
				var shown = fg.a < 1 ? over(fg, bg) : fg;
				if (ratio(shown, bg) < need) {
					var entry = was || {
						color: el.style.getPropertyValue('color'),
						priority: el.style.getPropertyPriority('color'),
						transition: el.style.getPropertyValue('transition'),
						transitionPriority: el.style.getPropertyPriority('transition')
					};
					fixed.set(el, entry);
					el.style.setProperty('color', readable(shown, bg, need), 'important');
				}
			}
			settle(el, was);
		}
		function walk(start, until) {
			if (!start.isConnected || (start.closest && start.closest(SKIP))) {
				return;
			}
			var walker = document.createTreeWalker(start, NodeFilter.SHOW_ELEMENT, {
				acceptNode: function (el) {
					return el.matches(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
				}
			});
			for (var el = start; el; el = walker.nextNode()) {
				if (hasText(el) || fixed.has(el)) {
					check(el);
				}
				if (until && Date.now() > until) {
					var rest = walker.nextNode();
					if (rest) {
						queue.push({ from: rest, root: start });
					}
					return;
				}
			}
		}
		// Elements after `from` in `scope`, continued in a later frame.
		function resume(job) {
			if (!job.root.isConnected || !job.root.contains(job.from)) {
				return true;
			}
			var walker = document.createTreeWalker(job.root, NodeFilter.SHOW_ELEMENT, {
				acceptNode: function (el) {
					return el.matches(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
				}
			});
			walker.currentNode = job.from;
			var until = Date.now() + 12;
			for (var el = job.from; el; el = walker.nextNode()) {
				if (el.isConnected && (hasText(el) || fixed.has(el))) {
					check(el);
				}
				if (Date.now() > until) {
					var rest = walker.nextNode();
					if (rest) {
						queue.unshift({ from: rest, root: job.root });
					}
					return false;
				}
			}
			return true;
		}
		function run() {
			frame = 0;
			if (!on) {
				queue = [];
				return;
			}
			bgs = new Map();
			var until = Date.now() + 12;
			while (queue.length && Date.now() < until) {
				var job = queue.shift();
				if (job.from) {
					if (!resume(job)) {
						break;
					}
				} else {
					walk(job, until);
				}
			}
			bgs = null;
			if (queue.length) {
				frame = window.requestAnimationFrame(run);
			}
		}
		function later(el) {
			if (on && el && el.nodeType === 1 && queue.indexOf(el) === -1) {
				queue.push(el);
				if (!frame) {
					frame = window.requestAnimationFrame(run);
				}
			}
		}
		// Hover and focus colours, once their transitions have run: checked, and undone when left.
		var hovered = null;
		var hoverTimer = 0;
		function hover(event) {
			var el = event.target && event.target.closest ? event.target.closest('a, button, [role="button"], [role="option"], [role="menuitem"], [role="tab"], li, tr, label, summary') : null;
			if (!el || el === hovered || el.closest(SKIP)) {
				return;
			}
			hovered = el;
			window.clearTimeout(hoverTimer);
			hoverTimer = window.setTimeout(function () {
				if (on && hovered === el) {
					later(el);
				}
			}, 250);
		}
		function leave(event) {
			if (!hovered || (event.relatedTarget && hovered.contains(event.relatedTarget))) {
				return;
			}
			var el = hovered;
			hovered = null;
			window.setTimeout(function () {
				later(el);
			}, 250);
		}

		return {
			start: function () {
				if (on || !document.body || !window.MutationObserver || !window.Map) {
					return;
				}
				on = true;
				queue.push(document.body);
				run();
				watcher = new MutationObserver(function (records) {
					records.forEach(function (record) {
						if (record.type === 'childList') {
							Array.prototype.forEach.call(record.addedNodes, later);
							if (record.target.nodeType === 1 && record.addedNodes.length && Array.prototype.some.call(record.addedNodes, function (n) { return n.nodeType === 3; })) {
								later(record.target);
							}
						} else {
							later(record.target);
						}
					});
				});
				watcher.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'aria-selected', 'aria-expanded', 'aria-checked'], characterData: false });
				document.addEventListener('pointerover', hover, true);
				document.addEventListener('focusin', hover, true);
				document.addEventListener('pointerout', leave, true);
				document.addEventListener('focusout', leave, true);
			},
			stop: function () {
				if (!on) {
					return;
				}
				on = false;
				queue = [];
				if (frame) {
					window.cancelAnimationFrame(frame);
					frame = 0;
				}
				if (watcher) {
					watcher.disconnect();
					watcher = null;
				}
				document.removeEventListener('pointerover', hover, true);
				document.removeEventListener('focusin', hover, true);
				document.removeEventListener('pointerout', leave, true);
				document.removeEventListener('focusout', leave, true);
				Array.from(fixed.keys()).forEach(function (el) {
					settle(el, release(el));
				});
			}
		};
	}());

	// Classes on <html>, the admin bar icon, its name and the menu's tick.
	function paint(which) {
		var nowDark = !!data.applies && isDark(which);
		if (data.applies) {
			order.forEach(function (value) { root.classList.toggle('sps-theme-' + value, value === which); });
			root.classList.toggle('sps-dark', nowDark);
		}
		if (top) {
			var holder = top.querySelector('.ab-icon');
			var text = top.querySelector('.screen-reader-text');
			if (holder) {
				holder.textContent = '';
				holder.appendChild(icon(which));
			}
			if (text) {
				text.textContent = name(which);
			}
			top.title = name(which);
		}
		items.forEach(function (item) {
			var current = item.getAttribute('data-sps-mode') === which;
			item.setAttribute('aria-checked', current ? 'true' : 'false');
			item.parentNode.classList.toggle('sps-current', current);
		});
		if (nowDark && data.guard) {
			// After a switch, once the colours have eased (0.2 s), so it reads the final ones.
			window.clearTimeout(guardTimer);
			if (root.classList.contains('sps-theme-ready')) {
				guardTimer = window.setTimeout(guard.start, 300);
			} else {
				guard.start();
			}
		} else {
			window.clearTimeout(guardTimer);
			guard.stop();
		}
		if (nowDark !== dark || which !== shown) {
			dark = nowDark;
			shown = which;
			document.dispatchEvent(new CustomEvent('sps-themechange', { detail: { mode: which, dark: nowDark } }));
		}
	}
	function apply(which) {
		mode = which;
		if (data.applies && isDark(which)) {
			withStyles(function () { paint(mode); });
		} else {
			paint(which);
		}
	}

	function close(focus) {
		if (!node) {
			return;
		}
		node.classList.remove('hover');
		top.setAttribute('aria-expanded', 'false');
		if (focus) {
			top.focus();
		}
	}
	function open() {
		node.classList.add('hover');
		top.setAttribute('aria-expanded', 'true');
		items[order.indexOf(mode)].focus();
	}

	/*
	 * Fluent plugins (FluentCRM, Fluent Booking, FluentSMTP, Fluent Boards,
	 * Fluent Support...) and LiteSpeed Cache have their own light and dark
	 * modes, kept in this browser. A choice made here is passed on, so their
	 * screens open in the same mode from the next one on (the screen's
	 * <head> does the same while Dark or System is chosen).
	 */
	function shareMode(which) {
		try {
			window.localStorage.setItem('fluent_theme_mode', which);
			window.localStorage.setItem('fs-theme', which === 'system' ? 'system:' + (media && media.matches ? 'dark' : 'light') : which);
			// LiteSpeed Cache follows the system when nothing is saved.
			if (which === 'system') {
				window.localStorage.removeItem('litespeed-dark-preference');
			} else {
				window.localStorage.setItem('litespeed-dark-preference', which);
			}
		} catch (e) {
			// Storage is off: their own choice stays.
		}
	}

	function choose(which) {
		if (saving || which === mode) {
			return;
		}
		var previous = mode;
		saving = true;
		apply(which);
		speak(name(which));
		var body = new URLSearchParams({ action: data.action, nonce: data.nonce, mode: which });
		window.fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) {
				if (!response.ok) {
					throw new Error('not saved');
				}
				return response.json();
			})
			.then(function (answer) {
				if (!answer || !answer.success || !answer.data || answer.data.mode !== which) {
					throw new Error('not saved');
				}
				document.dispatchEvent(new CustomEvent(EVENT, { detail: { mode: which, source: SOURCE } }));
				shareMode(which);
			})
			.catch(function () {
				apply(previous);
				speak(__('The colour mode could not be saved. Please try again.', 'seoprostack'), 'assertive');
			})
			.then(function () {
				saving = false;
			});
	}

	function start() {
		node = data.node ? document.getElementById(data.node) : null;
		top = node ? node.querySelector(':scope > .ab-item') : null;
		if (!top) {
			node = null;
		} else {
			order.forEach(function (which) {
				var item = document.querySelector('#' + data.node + '-' + which + ' > .ab-item');
				if (!item) {
					return;
				}
				item.setAttribute('role', 'menuitemradio');
				item.setAttribute('data-sps-mode', which);
				item.addEventListener('click', function (event) {
					event.preventDefault();
					close(event.detail === 0);
					choose(which);
				});
				items.push(item);
			});
			// A click turns Dark on or off; from System, the opposite of what shows now.
			top.addEventListener('click', function (event) {
				event.preventDefault();
				choose(isDark(mode) ? 'light' : 'dark');
			});
			top.addEventListener('keydown', function (event) {
				if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && items.length === order.length) {
					event.preventDefault();
					open();
				}
			});
			node.addEventListener('keydown', function (event) {
				// Only keys pressed on a menu item (the opening key bubbles here from the icon).
				var at = items.indexOf(event.target);
				if (at === -1) {
					return;
				}
				switch (event.key) {
					case 'ArrowDown': items[(at + 1) % items.length].focus(); break;
					case 'ArrowUp': items[(at + items.length - 1) % items.length].focus(); break;
					case 'Home': items[0].focus(); break;
					case 'End': items[items.length - 1].focus(); break;
					case 'Escape': close(true); break;
					case 'Tab': close(false); return;
					default: return;
				}
				event.preventDefault();
			});
		}
		paint(mode);
		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () { root.classList.add('sps-theme-ready'); });
		});
	}

	// Another plugin's control changed the mode: follow it, without saving or sending again.
	document.addEventListener(EVENT, function (event) {
		var detail = event.detail || {};
		if (detail.source === SOURCE || order.indexOf(detail.mode) === -1) {
			return;
		}
		apply(detail.mode);
	});
	if (media) {
		var follow = function () {
			if (mode === 'system') {
				apply('system');
			}
		};
		if (media.addEventListener) {
			media.addEventListener('change', follow);
		} else {
			media.addListener(follow);
		}
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
}(window.wp, window.seoprostackTheme));
