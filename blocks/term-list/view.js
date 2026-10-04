/**
 * seoprostack/term-list:
 * - open the chosen term when a drop-down changes (option values are term
 *   links built on the server);
 * - turn groups shown "in tabs" into tabs. Without this script they stay
 *   under headings, so every term is still reachable.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 */
(function () {
	'use strict';

	document.addEventListener('change', function (event) {
		var select = event.target;
		if (!select || !select.matches || !select.matches('select[data-sps-term-select]')) {
			return;
		}
		var url = select.value;
		if (url && /^https?:\/\//i.test(url)) {
			window.location.href = url;
		}
	});

	function tabs(holder) {
		if (holder.getAttribute('data-sps-tabs-ready')) {
			return;
		}
		var panels = Array.prototype.filter.call(holder.children, function (child) {
			return child.classList.contains('wp-block-seoprostack-term-list__group');
		});
		if (panels.length < 2) {
			return;
		}
		holder.setAttribute('data-sps-tabs-ready', '1');
		var list = document.createElement('div');
		list.className = 'wp-block-seoprostack-term-list__tablist';
		list.setAttribute('role', 'tablist');
		var buttons = panels.map(function (panel, i) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'wp-block-seoprostack-term-list__tab';
			button.id = panel.id + '-tab';
			button.setAttribute('role', 'tab');
			button.setAttribute('aria-controls', panel.id);
			button.textContent = panel.getAttribute('data-sps-tab-label') || String(i + 1);
			panel.setAttribute('role', 'tabpanel');
			panel.setAttribute('aria-labelledby', button.id);
			panel.setAttribute('tabindex', '0');
			list.appendChild(button);
			return button;
		});
		function select(index, focus) {
			buttons.forEach(function (button, i) {
				var on = i === index;
				button.setAttribute('aria-selected', on ? 'true' : 'false');
				button.tabIndex = on ? 0 : -1;
				panels[i].hidden = !on;
			});
			if (focus) {
				buttons[index].focus();
			}
		}
		list.addEventListener('click', function (event) {
			var i = buttons.indexOf(event.target.closest ? event.target.closest('[role="tab"]') : event.target);
			if (i >= 0) {
				select(i, false);
			}
		});
		list.addEventListener('keydown', function (event) {
			var i = buttons.indexOf(document.activeElement);
			if (i < 0) {
				return;
			}
			var next = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: buttons.length - 1 }[event.key];
			if (next === undefined) {
				return;
			}
			event.preventDefault();
			select((next + buttons.length) % buttons.length, true);
		});
		holder.insertBefore(list, holder.firstChild);
		holder.classList.add('is-sps-tabbed');
		select(0, false);
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-sps-tabs]'), tabs);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
