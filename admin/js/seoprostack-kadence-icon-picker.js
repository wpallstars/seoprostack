/**
 * Kadence Blocks' icon picker draws icons as they are needed.
 *
 * The picker draws every icon of every set when it opens (over 5,000 with
 * the Brand set, over 20,000 elements). It reads its sets through the
 * kadence.icon_options_names filter and goes through each set with .map(),
 * keeping the icons that match the search. This gives each large set a
 * .map() that stops after the first FIRST icons that match, so the picker
 * draws those; scrolling near the end of a set draws MORE of it. Searching
 * goes through every icon, so search finds them all.
 *
 * To redraw, the picker's set list is changed to the set being added to and
 * straight back, each committed at once (flushSync), so the set's icons stay
 * in place; the scroll position and folded sets are put back.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function () {
	'use strict';
	if (!window.wp || !wp.hooks || !window.WeakMap) {
		return;
	}
	var FIRST = 150;
	var MORE = 300;
	var NEAR = 800;
	var POPOVER = '.kadence-icon-picker-pop-selection';
	var cache = new WeakMap();
	var limits = {};
	var more = {};
	var popover = null;
	var queued = false;

	/** The open picker's popover; added icons count for it only. */
	function session() {
		var open = document.querySelector(POPOVER);
		if (open !== popover) {
			popover = open;
			limits = {};
		}
		return open;
	}

	function lazy(names, label) {
		var list = names.slice();
		Object.defineProperty(list, 'map', {
			configurable: true,
			writable: true,
			value: function (fn, self) {
				var max = session() && limits[label] ? limits[label] : FIRST;
				var out = [];
				var shown = 0;
				for (var i = 0; i < this.length; i++) {
					var kept = fn.call(self, this[i], i, this);
					out.push(kept);
					// The picker returns the name of each icon it keeps.
					if (kept !== undefined) {
						shown++;
						if (shown >= max) {
							more[label] = i + 1 < this.length;
							return out;
						}
					}
				}
				more[label] = false;
				return out;
			}
		});
		return list;
	}

	// Last, so it sees every other plugin's sets (Kadence Pro, Brand icons).
	wp.hooks.addFilter('kadence.icon_options_names', 'seoprostack/kadence-icon-picker', function (sets) {
		if (!sets || typeof sets !== 'object') {
			return sets;
		}
		var out = {};
		Object.keys(sets).forEach(function (label) {
			var names = sets[label];
			if (!Array.isArray(names) || names.length <= FIRST) {
				out[label] = names;
				return;
			}
			var kept = cache.get(names);
			if (!kept || kept.label !== label) {
				kept = { label: label, list: lazy(names, label) };
				cache.set(names, kept);
			}
			out[label] = kept.list;
		});
		return out;
	}, 1000);

	function text(el) {
		return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : '';
	}

	function panels(pop) {
		return pop.querySelectorAll('.kadence-icon-picker-content > .components-panel__body');
	}

	/** Set the picker's set list, committed at once where React allows. */
	function choose(select, value) {
		var run = function () {
			select.value = value;
			select.dispatchEvent(new Event('change', { bubbles: true }));
		};
		if (wp.element && typeof wp.element.flushSync === 'function') {
			wp.element.flushSync(run);
		} else {
			run();
		}
	}

	/** Redraw the picker with more of one set. */
	function redraw(pop, label) {
		var select = pop.querySelector('.kadence-icon-picker-search select');
		var scroller = pop.querySelector('.components-popover__content');
		if (!select || !scroller) {
			return;
		}
		var via = '';
		Array.prototype.forEach.call(select.options, function (option) {
			if (text(option) === label) {
				via = option.value;
			}
		});
		var current = select.value;
		if (current !== 'all') {
			via = 'all';
		}
		if (!via || via === current) {
			return;
		}
		var top = scroller.scrollTop;
		var folded = {};
		Array.prototype.forEach.call(panels(pop), function (panel) {
			if (!panel.classList.contains('is-opened')) {
				folded[text(panel.querySelector('.components-panel__body-title'))] = true;
			}
		});
		var fold = function () {
			Array.prototype.forEach.call(panels(pop), function (panel) {
				var toggle = panel.querySelector('.components-panel__body-toggle');
				if (toggle && panel.classList.contains('is-opened') && folded[text(panel.querySelector('.components-panel__body-title'))]) {
					toggle.click();
				}
			});
		};
		var place = function () {
			if (scroller.scrollTop !== top) {
				scroller.scrollTop = top;
			}
		};
		var settle = function () {
			place();
			window.requestAnimationFrame(function () {
				place();
				scroller.style.removeProperty('overflow-anchor');
			});
		};
		var frame = function () {
			return new Promise(window.requestAnimationFrame);
		};
		// The browser would move the view to follow sets that are briefly redrawn.
		scroller.style.setProperty('overflow-anchor', 'none');
		choose(select, via);
		if (wp.element && typeof wp.element.flushSync === 'function') {
			choose(select, current);
			wp.element.flushSync(fold);
			settle();
		} else {
			// React draws the first change in a microtask queued before this one.
			Promise.resolve().then(function () {
				choose(select, current);
				return frame();
			}).then(function () {
				fold();
				return frame();
			}).then(settle);
		}
	}

	/** Near the end of a set that has more icons, draw more of it; not of a set scrolled past. */
	function check() {
		queued = false;
		var pop = session();
		var scroller = pop && pop.querySelector('.components-popover__content');
		if (!scroller) {
			return;
		}
		var view = scroller.getBoundingClientRect();
		var list = panels(pop);
		for (var i = 0; i < list.length; i++) {
			var label = text(list[i].querySelector('.components-panel__body-title'));
			var end = list[i].getBoundingClientRect().bottom;
			if (more[label] && list[i].classList.contains('is-opened') && end > view.top && end < view.bottom + NEAR) {
				limits[label] = (limits[label] || FIRST) + MORE;
				redraw(pop, label);
				return;
			}
		}
	}

	document.addEventListener('scroll', function (event) {
		var target = event.target;
		if (!queued && target && target.closest && target.closest(POPOVER)) {
			queued = true;
			window.requestAnimationFrame(check);
		}
	}, true);
})();
