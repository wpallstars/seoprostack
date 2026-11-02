/*
 * Language switcher (includes/features/class-seoprostack-language-switcher.php):
 * a disclosure button and its list of links. Escape closes and returns focus,
 * arrow keys move through the list, a click outside or Tab away closes it.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 * From the Property Services Directory child theme (Provenance: wpas-2bec2bd6b77c93d7).
 */
(function () {
	'use strict';

	// Space kept between the list and the screen edge (px), plus the list's offset from the button.
	var EDGE = 14;
	// A list never gets shorter than this; three languages and a scroll shade.
	var MIN_HEIGHT = 120;

	function items(menu) {
		return Array.prototype.slice.call(menu.querySelectorAll('a'));
	}

	/*
	 * Open towards the side with room. The header list opens downwards, the
	 * footer and mobile-drawer lists upwards (their buttons sit at the bottom
	 * of their area). When the list does not fit there and the other side has
	 * more room it opens that way instead, and its height is capped to the
	 * space it has, so every language stays on screen (the list scrolls).
	 */
	function place(root, button, menu) {
		root.removeAttribute('data-placement');
		menu.style.maxHeight = '';
		var cap = parseFloat(window.getComputedStyle(menu).maxHeight) || Infinity;
		var rect = button.getBoundingClientRect();
		var view = window.innerHeight || document.documentElement.clientHeight;
		var above = rect.top - EDGE;
		var below = view - rect.bottom - EDGE;
		var need = Math.min(menu.scrollHeight, cap);
		var up = root.classList.contains('seoprostack-languages--footer') || root.classList.contains('seoprostack-languages--mobile');
		var room = up ? above : below;
		var other = up ? below : above;
		if (room < need && other > room) {
			up = !up;
			room = other;
		}
		root.setAttribute('data-placement', up ? 'up' : 'down');
		menu.style.maxHeight = Math.max(MIN_HEIGHT, Math.min(cap, room)) + 'px';
	}

	function setup(root) {
		var button = root.querySelector('.seoprostack-languages__button');
		var menu = root.querySelector('.seoprostack-languages__menu');
		if (!button || !menu) {
			return;
		}

		function open(focus) {
			button.setAttribute('aria-expanded', 'true');
			menu.hidden = false;
			place(root, button, menu);
			if (focus) {
				var links = items(menu);
				var current = menu.querySelector('[aria-current="true"]');
				var target = 'last' === focus ? links[links.length - 1] : current || links[0];
				if (target) {
					target.focus();
				}
			}
		}

		function close(returnFocus) {
			if (menu.hidden) {
				return;
			}
			button.setAttribute('aria-expanded', 'false');
			menu.hidden = true;
			if (returnFocus) {
				button.focus();
			}
		}

		button.addEventListener('click', function () {
			if (menu.hidden) {
				open(false);
			} else {
				close(false);
			}
		});

		button.addEventListener('keydown', function (event) {
			if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
				event.preventDefault();
				open('ArrowUp' === event.key ? 'last' : 'first');
			} else if ('Escape' === event.key) {
				close(true);
			}
		});

		menu.addEventListener('keydown', function (event) {
			var links = items(menu);
			var index = links.indexOf(document.activeElement);
			if ('Escape' === event.key) {
				event.preventDefault();
				close(true);
			} else if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
				event.preventDefault();
				var step = 'ArrowDown' === event.key ? 1 : -1;
				links[(index + step + links.length) % links.length].focus();
			} else if ('Home' === event.key || 'End' === event.key) {
				event.preventDefault();
				links['Home' === event.key ? 0 : links.length - 1].focus();
			}
		});

		root.addEventListener('focusout', function (event) {
			if (event.relatedTarget && !root.contains(event.relatedTarget)) {
				close(false);
			}
		});

		document.addEventListener('click', function (event) {
			if (!root.contains(event.target)) {
				close(false);
			}
		});
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('.seoprostack-languages'), setup);
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
