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
