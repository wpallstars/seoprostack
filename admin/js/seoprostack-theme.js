/**
 * Accessible, per-person Light / Dark / System menu (SEO Pro Stats #219).
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
	var root = document.documentElement;
	var media = window.matchMedia('(prefers-color-scheme: dark)');
	var order = ['light', 'dark', 'system'];
	var labels = { light: __('Light', 'seoprostack'), dark: __('Dark', 'seoprostack'), system: __('System', 'seoprostack') };
	var paths = {
		light: 'M12 7.75a4.25 4.25 0 1 0 0 8.5 4.25 4.25 0 0 0 0-8.5zM11.25 2h1.5v3h-1.5zm0 17h1.5v3h-1.5zM2 11.25h3v1.5H2zm17 0h3v1.5h-3zM4.4 5.46 5.46 4.4l2.12 2.12-1.06 1.06zm12.02 12.02 1.06-1.06 2.12 2.12-1.06 1.06zM4.4 18.54l2.12-2.12 1.06 1.06-2.12 2.12zM16.42 6.52l2.12-2.12 1.06 1.06-2.12 2.12z',
		dark: 'M20.5 14.6A8.5 8.5 0 0 1 9.4 3.5a8.5 8.5 0 1 0 11.1 11.1z',
		system: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm0 1.5v15a7.5 7.5 0 0 1 0-15z'
	};
	var mode = order.indexOf(data.mode) === -1 ? 'light' : data.mode;
	var dark = root.classList.contains('sps-dark');
	var saving = false;
	var toggle;
	var menu;
	var items = [];

	function name(which) {
		/* translators: %s: Light, Dark or System. */
		return wp.i18n.sprintf(__('Colour mode: %s', 'seoprostack'), labels[which]);
	}
	function icon(which) {
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		path.setAttribute('d', paths[which]);
		path.setAttribute('fill-rule', 'evenodd');
		svg.appendChild(path);
		return svg;
	}
	function apply(which) {
		var previous = mode;
		var nowDark = which === 'dark' || (which === 'system' && media.matches);
		mode = which;
		order.forEach(function (value) { root.classList.toggle('sps-theme-' + value, value === which); });
		root.classList.toggle('sps-dark', nowDark);
		if (toggle) {
			toggle.replaceChild(icon(which), toggle.firstChild);
			toggle.lastChild.textContent = name(which);
			toggle.title = name(which);
		}
		items.forEach(function (item) { item.setAttribute('aria-checked', item.dataset.spsMode === which ? 'true' : 'false'); });
		if (previous !== which || nowDark !== dark) {
			dark = nowDark;
			document.dispatchEvent(new CustomEvent('sps-themechange', { detail: { mode: which, dark: nowDark } }));
		}
	}
	function close(focus) {
		menu.hidden = true;
		toggle.setAttribute('aria-expanded', 'false');
		if (focus) { toggle.focus(); }
	}
	function open() {
		menu.hidden = false;
		toggle.setAttribute('aria-expanded', 'true');
		items[order.indexOf(mode)].focus();
	}
	function choose(which) {
		close(true);
		if (saving || which === mode) { return; }
		var previous = mode;
		saving = true;
		items.forEach(function (item) { item.setAttribute('aria-disabled', 'true'); });
		apply(which);
		wp.a11y.speak(name(which));
		var body = new URLSearchParams({ action: data.action, nonce: data.nonce, mode: which });
		window.fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) {
				if (!response.ok) { throw new Error('not saved'); }
				return response.json();
			})
			.then(function (answer) {
				if (!answer || !answer.success || answer.data.mode !== which) { throw new Error('not saved'); }
			})
			.catch(function () {
				apply(previous);
				wp.a11y.speak(__('The colour mode could not be saved. Please try again.', 'seoprostack'), 'assertive');
			})
			.then(function () {
				saving = false;
				items.forEach(function (item) { item.removeAttribute('aria-disabled'); });
			});
	}
	function start() {
		var actions = document.querySelector('.sps-header__actions');
		if (!actions) { return; }
		var wrap = document.createElement('div');
		wrap.className = 'sps-theme';
		toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'button sps-header__support sps-theme__toggle';
		toggle.setAttribute('aria-haspopup', 'menu');
		toggle.setAttribute('aria-expanded', 'false');
		toggle.setAttribute('aria-controls', 'sps-theme-menu');
		toggle.appendChild(icon(mode));
		var text = document.createElement('span');
		text.className = 'screen-reader-text';
		toggle.appendChild(text);
		menu = document.createElement('div');
		menu.id = 'sps-theme-menu';
		menu.className = 'sps-theme__menu';
		menu.setAttribute('role', 'menu');
		menu.setAttribute('aria-label', __('Colour mode', 'seoprostack'));
		menu.hidden = true;
		order.forEach(function (which) {
			var item = document.createElement('button');
			item.type = 'button';
			item.className = 'sps-theme__item';
			item.tabIndex = -1;
			item.setAttribute('role', 'menuitemradio');
			item.dataset.spsMode = which;
			item.appendChild(icon(which));
			item.appendChild(document.createTextNode(labels[which]));
			var check = document.createElement('span');
			check.className = 'dashicons dashicons-yes sps-theme__check';
			check.setAttribute('aria-hidden', 'true');
			item.appendChild(check);
			item.addEventListener('click', function () { choose(which); });
			items.push(item);
			menu.appendChild(item);
		});
		toggle.addEventListener('click', function () { if (menu.hidden) { open(); } else { close(true); } });
		toggle.addEventListener('keydown', function (event) {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); open(); }
		});
		menu.addEventListener('keydown', function (event) {
			var at = items.indexOf(document.activeElement);
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
		document.addEventListener('click', function (event) { if (!wrap.contains(event.target)) { close(false); } });
		wrap.addEventListener('focusout', function (event) { if (!wrap.contains(event.relatedTarget)) { close(false); } });
		wrap.appendChild(toggle);
		wrap.appendChild(menu);
		var donate = actions.querySelector('.sps-header__donate');
		actions.insertBefore(wrap, donate ? donate.nextSibling : null);
		apply(mode);
		window.requestAnimationFrame(function () { window.requestAnimationFrame(function () { root.classList.add('sps-theme-ready'); }); });
	}
	var follow = function () { if (mode === 'system') { apply('system'); } };
	if (media.addEventListener) { media.addEventListener('change', follow); } else { media.addListener(follow); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
}(window.wp, window.seoprostackTheme));
