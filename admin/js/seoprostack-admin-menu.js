/**
 * Organise the admin menu: the Administrators and Developers menus' third level,
 * keeping the printed order when other plugins' scripts move entries, and
 * folding sections open and closed.
 *
 * Loaded in the head. SEOProStack_Admin_Menu::print_flyouts() prints a
 * call to seoprostackMenuFlyouts() straight after the menu, so the third
 * level is in place before the page is drawn. Each entry with a submenu
 * gets a list that opens to the side on hover or keyboard focus; the
 * current page's list shows in place.
 *
 * Folding (when "Fold sections" is on): each section heading
 * (li.sps-menu-heading) hides or shows the entries after it, up to the
 * next heading. Folded sections are remembered per person in the
 * wp-settings cookie (setUserSetting), as one letter per section, so the
 * page is drawn folded the next time without a flash.
 *
 * @package SEOProStack
 */
(function (cfg) {
	'use strict';

	/** Background of the submenu a flyout opens from, so every colour scheme matches. */
	function paint(item, list) {
		var parent = item.closest('.wp-submenu');
		if (parent) {
			list.style.backgroundColor = window.getComputedStyle(parent).backgroundColor;
		}
		// Keep it on screen: lift it when it would run off the bottom.
		list.style.marginTop = '';
		var box = list.getBoundingClientRect();
		var over = box.bottom - window.innerHeight + 8;
		if (over > 0) {
			list.style.marginTop = -Math.min(over, box.top - 8) + 'px';
		}
	}

	/**
	 * Keep the menu in the order it was printed. Some plugins move menu
	 * entries with their own scripts, matching them by name (for example
	 * any entry called "Analytics" moved above Posts), which would pull
	 * entries out of their sections. Entries they add or remove are left
	 * alone; only the order of the printed ones is put back, a few times
	 * at most, before the page is drawn.
	 */
	function keepOrder(menu) {
		if (!menu || 'function' !== typeof window.MutationObserver) {
			return;
		}
		var printed = Array.prototype.filter.call(menu.children, function (el) {
			return 'LI' === el.tagName;
		});
		var fixes = 0;
		var observer;

		function inOrder() {
			var last = -1;
			for (var i = 0; i < menu.children.length; i++) {
				var at = printed.indexOf(menu.children[i]);
				if (-1 === at) {
					continue;
				}
				if (at < last) {
					return false;
				}
				last = at;
			}
			return true;
		}

		function restore() {
			if (fixes >= 5 || inOrder()) {
				return;
			}
			fixes++;
			observer.disconnect();
			// From the end: each printed entry goes before the next one still in the menu.
			var next = null;
			for (var i = printed.length - 1; i >= 0; i--) {
				if (printed[i].parentNode !== menu) {
					continue;
				}
				if (next && printed[i].nextElementSibling !== next) {
					menu.insertBefore(printed[i], next);
				}
				next = printed[i];
			}
			observer.observe(menu, { childList: true });
		}

		observer = new window.MutationObserver(restore);
		observer.observe(menu, { childList: true });
	}

	/** Widest the menu grows, and its usual width. */
	var FIT_MAX = 280;
	var FIT_MIN = 160;
	var fitted = 0;
	/** Indent of a third-level entry shown in place (stylesheet). */
	var IN_PLACE_INDENT = 38;

	/**
	 * Width an entry needs when its menu is open in place: the padding
	 * before the name, the name, and the padding after it. Measured on
	 * the text itself (scrollWidth leaves out the padding after it and
	 * never reports less than the current width), and wherever the entry
	 * is now, so every menu's entries count, open or not.
	 */
	function room(el, indent) {
		var range = document.createRange();
		range.selectNodeContents(el);
		var text = range.getBoundingClientRect();
		if (!text.width) {
			return 0;
		}
		var box = el.getBoundingClientRect();
		var style = window.getComputedStyle(el);
		var before = parseFloat(style.paddingLeft) || 0;
		var after = parseFloat(style.paddingRight) || 0;
		// Text from the start of the content box; an icon sits in the padding.
		var name = text.right - box.left - before;
		if ('rtl' === style.direction) {
			before = after;
			after = parseFloat(style.paddingLeft) || 0;
			name = box.right - before - text.left;
		}
		return (indent || before) + name + after;
	}

	/**
	 * Widen the menu so entry names fit on one line ("Fit the menu to its
	 * names"). Every menu's entries count, open or not, with folded
	 * sections shown, so the width is the same on every screen and when a
	 * section opens. The width goes in --sps-menu-width on <body>; the
	 * stylesheet applies it only where the full menu shows (wide screens,
	 * menu not collapsed). Runs straight after the menu is printed, before
	 * the content is drawn, and once more when the page has loaded, in
	 * case styles printed later made names longer; it only ever widens,
	 * so nothing moves back and forth.
	 */
	function fit() {
		var body = document.body;
		var menu = document.getElementById('adminmenu');
		if (!cfg || !cfg.fit || !body || !menu) {
			return;
		}
		// Submenus that open to the side fit their names (stylesheet).
		body.classList.add('sps-menu-fit');
		// Collapsed or narrow: names are hidden, so measure later.
		if (body.classList.contains('folded') || !window.matchMedia('(min-width: 961px)').matches) {
			return;
		}
		body.classList.add('sps-menu-measure');
		var need = 0;
		menu.querySelectorAll('li.menu-top > a .wp-menu-name, .wp-submenu a').forEach(function (el) {
			var third = !!el.closest('ul.sps-menu-flyout');
			need = Math.max(need, room(el, third ? IN_PLACE_INDENT : 0));
		});
		body.classList.remove('sps-menu-measure');
		var width = Math.max(FIT_MIN, Math.min(FIT_MAX, Math.ceil(need) + 2));
		if (width > fitted) {
			fitted = width;
			if (width > FIT_MIN) {
				body.style.setProperty('--sps-menu-width', width + 'px');
			}
		}
		if (fitted > FIT_MIN) {
			if ('loading' === document.readyState) {
				document.addEventListener('DOMContentLoaded', watch);
			} else {
				watch();
			}
		}
	}

	/*
	 * Other plugins' bars beside the menu. Many plugins place their own
	 * fixed headers, footers, notices and panels for WordPress's usual
	 * 160px menu (left: 160px, margin-left: 160px, padding-left: 160px or
	 * width: calc(100% - 160px)), so the wider menu covers their start or
	 * pushes their end off the screen. Rather than a list of plugins that
	 * is never complete, elements fixed to the window are checked as they
	 * appear: one that starts under the wider menu (at 160px or more, and
	 * not centred like a dialog) moves over by the extra width, and one
	 * sized for the usual menu narrows by it. Elements a script places
	 * itself (inline left, right or transform, such as dropdowns) are left
	 * alone. Classes and custom properties set here take effect through
	 * the stylesheet only where the full menu shows, so collapsing the
	 * menu or a narrow window puts the plugin's own layout back.
	 */
	var FOLLOW = ['sps-fit-start', 'sps-fit-margin', 'sps-fit-pad', 'sps-fit-width'];
	var FOLLOW_PROPS = ['--sps-fit-start', '--sps-fit-margin', '--sps-fit-pad', '--sps-fit-gap'];
	/** Biggest changed part of the page to check again on a class or style change. */
	var FOLLOW_LIMIT = 300;
	var watcher = null;
	var pending = [];
	var whole = false;
	var timer = 0;
	var resizing = 0;

	/** The full, fitted menu shows. */
	function wide() {
		var body = document.body;
		return fitted > FIT_MIN && body && body.classList.contains('sps-menu-fit') &&
			!body.classList.contains('folded') && window.matchMedia('(min-width: 961px)').matches;
	}

	function px(value) {
		return parseFloat(value) || 0;
	}

	/** Covered by the wider menu but clear of the usual one. */
	function under(value) {
		return value >= FIT_MIN - 0.5 && value < fitted - 0.5;
	}

	function followed(el) {
		for (var i = 0; i < FOLLOW.length; i++) {
			if (el.classList.contains(FOLLOW[i])) {
				return true;
			}
		}
		return false;
	}

	function unfollow(el) {
		FOLLOW.forEach(function (name) {
			el.classList.remove(name);
		});
		FOLLOW_PROPS.forEach(function (name) {
			el.style.removeProperty(name);
		});
	}

	/**
	 * Move or narrow one element if it is laid out for the usual menu.
	 * Transitions are paused while it moves, so it does not slide and the
	 * measurements are where it ends up.
	 *
	 * @param {Element} el    Element to check.
	 * @param {boolean} again Check it again even if it already follows (its class or style changed).
	 */
	function follow(el, again) {
		if (!el.classList || !el.style) {
			return;
		}
		var was = followed(el);
		if (was && !again) {
			return;
		}
		var transition = el.style.transition;
		var paused = false;
		function pause() {
			if (!paused) {
				paused = true;
				el.style.transition = 'none';
			}
		}
		if (was) {
			pause();
			unfollow(el);
		}
		place(el, pause);
		if (paused) {
			el.getBoundingClientRect();
			el.style.transition = transition;
		}
	}

	function place(el, pause) {
		var style = window.getComputedStyle(el);
		var position = style.position;
		if ('fixed' !== position && ('absolute' !== position || el.offsetParent !== document.body)) {
			return;
		}
		if (el.closest('#adminmenumain, #wpadminbar')) {
			return;
		}
		var inline = el.style;
		if (inline.left || inline.right || inline.inset || inline.insetInlineStart || inline.insetInlineEnd || inline.transform || inline.translate) {
			return;
		}
		var box = el.getBoundingClientRect();
		if (!box.width || !box.height) {
			return;
		}
		var rtl = document.body.classList.contains('rtl');
		var side = rtl ? 'Right' : 'Left';
		var view = document.documentElement.clientWidth;
		var start = rtl ? view - box.right : box.left;
		var end = rtl ? box.left : view - box.right;
		var extra = fitted - FIT_MIN;
		var moved = false;

		function set(kind, prop, value) {
			pause();
			el.style.setProperty(prop, Math.round(value * 100) / 100 + 'px');
			el.classList.add('sps-fit-' + kind);
		}

		if (under(start)) {
			// Centred, such as a dialog over the whole window.
			if (Math.abs(start - end) <= 1) {
				return;
			}
			var inset = px(style[rtl ? 'right' : 'left']);
			var margin = px(style['margin' + side]);
			if (under(inset)) {
				set('start', '--sps-fit-start', inset - FIT_MIN);
			} else if (under(margin)) {
				set('margin', '--sps-fit-margin', margin - FIT_MIN);
			} else {
				return;
			}
			moved = true;
		} else if (Math.abs(start) <= 0.5 && Math.abs(end) <= 0.5) {
			// Across the whole window, its content kept clear of the menu.
			var padding = px(style['padding' + side]);
			if (under(padding)) {
				set('pad', '--sps-fit-pad', padding - FIT_MIN);
			}
			return;
		}
		// Sized for the usual menu: it now runs off the far side by the extra width.
		if (moved ? end > -1 : Math.abs(end + extra) <= 2) {
			var now = el.getBoundingClientRect();
			var over = rtl ? -now.left : now.right - view;
			if (over > 1) {
				set('width', '--sps-fit-gap', view - FIT_MIN - box.width);
			}
		}
	}

	function flush() {
		timer = 0;
		// Changed elements first (checked again), then the whole page if asked.
		var roots = pending.filter(function (item) {
			return !whole || item[1];
		});
		if (whole) {
			roots.push([document.body, false, true]);
		}
		pending = [];
		whole = false;
		if (!wide()) {
			return;
		}
		roots.forEach(function (item) {
			var root = item[0];
			if (!root.isConnected) {
				return;
			}
			if (root !== document.body) {
				follow(root, item[1]);
			}
			var kids = root.getElementsByTagName('*');
			if (!item[2] && kids.length > FOLLOW_LIMIT) {
				return;
			}
			for (var i = 0; i < kids.length; i++) {
				follow(kids[i], false);
			}
		});
		// Changes made here are not news.
		if (watcher) {
			watcher.takeRecords();
		}
	}

	function later() {
		if (!timer) {
			timer = window.setTimeout(flush, 50);
		}
	}

	/**
	 * Check the whole page now, then what changes: added elements and
	 * their contents, and elements whose class or style changes (shown,
	 * moved, opened). The menu itself is left out.
	 */
	function watch() {
		if (watcher || 'function' !== typeof window.MutationObserver || !document.body) {
			return;
		}
		var menu = document.getElementById('adminmenumain');
		watcher = new window.MutationObserver(function (records) {
			for (var i = 0; i < records.length; i++) {
				var record = records[i];
				var target = record.target;
				if ('attributes' === record.type && target === document.body) {
					// Menu collapsed or widened, or the page changed state.
					whole = true;
					continue;
				}
				if (whole && 'childList' === record.type) {
					continue;
				}
				if (menu && menu.contains(target)) {
					continue;
				}
				if ('childList' === record.type) {
					for (var j = 0; j < record.addedNodes.length; j++) {
						if (1 === record.addedNodes[j].nodeType) {
							pending.push([record.addedNodes[j], false, true]);
						}
					}
				} else {
					pending.push([target, true, false]);
				}
			}
			if (whole || pending.length) {
				later();
			}
		});
		watcher.observe(document.body, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: ['class', 'style'],
		});
		window.addEventListener('resize', function () {
			window.clearTimeout(resizing);
			resizing = window.setTimeout(function () {
				whole = true;
				later();
			}, 200);
		});
		whole = true;
		later();
	}

	if (cfg && cfg.fit) {
		// Menu expanded again, or the window made wider.
		if (window.jQuery) {
			window.jQuery(document).on('wp-collapse-menu', function () {
				window.setTimeout(fit, 0);
			});
		}
		window.addEventListener('resize', function () {
			if (!fitted) {
				fit();
			}
		});
		window.addEventListener('load', fit);
	}

	window.seoprostackMenuFlyouts = function (data) {
		keepOrder(document.getElementById('adminmenu'));
		// Headings that do not fold are labels, not links.
		document.querySelectorAll('#adminmenu li.sps-menu-static > a').forEach(function (link) {
			link.removeAttribute('href');
		});
		Object.keys(data || {}).forEach(function (id) {
			var item = document.querySelector('#adminmenu li.' + id);
			if (!item) {
				return;
			}
			var list = document.createElement('ul');
			list.className = 'sps-menu-flyout';
			data[id].forEach(function (entry) {
				var li = document.createElement('li');
				var link = document.createElement('a');
				link.setAttribute('href', entry.u);
				link.innerHTML = entry.t; // Menu titles, printed the same way by WordPress.
				if (entry.c) {
					li.className = 'current';
					link.className = 'current';
					link.setAttribute('aria-current', 'page');
				}
				if (entry.d) {
					li.classList.add('sps-menu-divider');
				}
				li.appendChild(link);
				list.appendChild(li);
			});
			item.appendChild(list);
			item.addEventListener('mouseenter', function () {
				paint(item, list);
			});
			item.addEventListener('focusin', function () {
				paint(item, list);
			});
		});
		fit();
	};

	if (!cfg || !cfg.fold) {
		return;
	}

	function init() {
		var menu = document.getElementById('adminmenu');
		if (!menu || 'function' !== typeof window.setUserSetting) {
			return;
		}

		/** Section code from a heading's class (sps-menu-code-x). */
		function code(heading) {
			var match = /(?:^|\s)sps-menu-code-([a-z])(?:\s|$)/.exec(heading.className);
			return match ? match[1] : '';
		}

		/** Entries that belong to a heading. */
		function entries(heading) {
			var list = [];
			var el = heading.nextElementSibling;
			while (el && !el.classList.contains('sps-menu-heading') && !el.classList.contains('wp-menu-separator') && 'collapse-menu' !== el.id) {
				list.push(el);
				el = el.nextElementSibling;
			}
			return list;
		}

		function save() {
			var codes = '';
			menu.querySelectorAll('li.sps-menu-heading.is-folded').forEach(function (heading) {
				codes += code(heading);
			});
			window.setUserSetting(cfg.setting, codes);
		}

		function set(heading, folded) {
			heading.classList.toggle('is-folded', folded);
			var link = heading.querySelector('a');
			if (link) {
				link.setAttribute('aria-expanded', folded ? 'false' : 'true');
			}
			entries(heading).forEach(function (el) {
				el.classList.toggle('sps-menu-folded', folded);
			});
		}

		menu.querySelectorAll('li.sps-menu-heading').forEach(function (heading) {
			var link = heading.querySelector('a');
			if (!link) {
				return;
			}
			link.setAttribute('role', 'button');
			link.setAttribute('aria-expanded', heading.classList.contains('is-folded') ? 'false' : 'true');
			link.addEventListener('click', function (event) {
				event.preventDefault();
				set(heading, !heading.classList.contains('is-folded'));
				save();
			});
			// Buttons also respond to the space bar.
			link.addEventListener('keydown', function (event) {
				if (' ' === event.key) {
					event.preventDefault();
					link.click();
				}
			});
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window.seoprostackAdminMenu);
