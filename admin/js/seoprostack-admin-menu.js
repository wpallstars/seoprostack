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
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
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
	/**
	 * Padding of a submenu entry when its menu is open in place (core and
	 * the stylesheet): before the name (entries with icons and third-level
	 * entries are indented) and after it (entries with a chevron).
	 */
	var IN_PLACE_START = 12;
	var IN_PLACE_INDENT = 38;
	var IN_PLACE_END = 12;
	var IN_PLACE_CHEVRON = 24;

	/**
	 * Width an entry needs when its menu is open in place: the padding
	 * before the name, the name, and the padding after it. The name is
	 * measured on the text itself (scrollWidth leaves out the padding
	 * after it and never reports less than the current width), wherever
	 * the entry is now, so every menu's entries count, open or not.
	 * Submenu entries use their in-place padding, not the padding they
	 * have where they are (a menu that opens to the side pads its entries
	 * differently), so an entry needs the same room on every screen.
	 */
	function room(el) {
		var range = document.createRange();
		range.selectNodeContents(el);
		var text = range.getBoundingClientRect();
		if (!text.width) {
			return 0;
		}
		var box = el.getBoundingClientRect();
		var style = window.getComputedStyle(el);
		var rtl = 'rtl' === style.direction;
		var before = parseFloat(rtl ? style.paddingRight : style.paddingLeft) || 0;
		var after = parseFloat(rtl ? style.paddingLeft : style.paddingRight) || 0;
		// Text from the start of the content box; an icon sits in the padding.
		var name = rtl ? box.right - before - text.left : text.right - box.left - before;
		var item = el.closest('.wp-submenu li');
		if (item) {
			var third = !!el.closest('ul.sps-menu-flyout');
			before = third || item.classList.contains('sps-menu-entry') ? IN_PLACE_INDENT : IN_PLACE_START;
			after = item.classList.contains('sps-menu-has-sub') && !third ? IN_PLACE_CHEVRON : IN_PLACE_END;
		}
		return before + name + after;
	}

	/**
	 * Most a remembered width may be above what this screen needs. Screens
	 * differ by a few pixels (3 to 14 seen) through badges, entries and
	 * fonts some plugins add on their own screens only; a bigger gap is a
	 * one-off (a badge or notice since gone) that would otherwise keep the
	 * menu wide until the plugins change.
	 */
	var FIT_SLACK = 24;

	/**
	 * The widest width this person's menu has needed with the same plugins,
	 * language and SEO Pro Stack version (cfg.widthKey), kept in the
	 * wp-settings cookie. Plugins add entries, badges and fonts on some
	 * screens only, so remembering the widest keeps the menu still from
	 * screen to screen.
	 */
	function remembered() {
		if (!cfg.widthKey || 'function' !== typeof window.getUserSetting) {
			return 0;
		}
		var match = /^([a-z0-9]+)w(\d{3})$/.exec(String(window.getUserSetting('spsmw', '')));
		return match && match[1] === cfg.widthKey ? parseInt(match[2], 10) : 0;
	}

	/**
	 * Remember this screen's width when it is wider, or when the
	 * remembered one is more than FIT_SLACK above it (then this screen's
	 * replaces it). Returns the width to keep.
	 */
	function remember(width) {
		var kept = remembered();
		if (width > kept || kept > width + FIT_SLACK) {
			kept = width;
			if (cfg.widthKey && 'function' === typeof window.setUserSetting) {
				window.setUserSetting('spsmw', cfg.widthKey + 'w' + width);
			}
		}
		return kept;
	}

	/**
	 * The full menu shows: not collapsed, a wide window, and not hidden,
	 * as in the full screen block and site editors (is-fullscreen-mode on
	 * <body>, which the editors switch while the page is open).
	 */
	function shown() {
		var body = document.body;
		var menu = document.getElementById('adminmenu');
		return !!(body && menu) && !body.classList.contains('folded') &&
			!body.classList.contains('is-fullscreen-mode') &&
			window.matchMedia('(min-width: 961px)').matches && menu.getClientRects().length > 0;
	}

	/**
	 * Fit the menu when it shows again. The block editor opens in full
	 * screen (menu hidden) and leaves it once its own script has run,
	 * often after the page has loaded, so the menu is measured then.
	 */
	var shownWatcher = null;
	function watchShown(body) {
		if (shownWatcher || 'function' !== typeof window.MutationObserver) {
			return;
		}
		var was = shown();
		shownWatcher = new window.MutationObserver(function () {
			var now = shown();
			if (now && !was) {
				fit();
			}
			was = now;
		});
		shownWatcher.observe(body, { attributes: true, attributeFilter: ['class'] });
	}

	/**
	 * Widen the menu so entry names fit on one line ("Fit the menu to its
	 * names"). Every menu's entries count, open or not, with folded
	 * sections shown, so the width is the same on every screen and when a
	 * section opens. The width goes in --sps-menu-width on <body>; the
	 * stylesheet applies it only where the full menu shows (wide screens,
	 * menu not collapsed, editor not full screen). Runs straight after the
	 * menu is printed, before
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
		watchShown(body);
		// Collapsed, narrow or hidden (the full screen editor): names are hidden, so measure later.
		if (!shown()) {
			return;
		}
		body.classList.add('sps-menu-measure');
		var need = 0;
		menu.querySelectorAll('li.menu-top > a .wp-menu-name, .wp-submenu a').forEach(function (el) {
			need = Math.max(need, room(el));
		});
		body.classList.remove('sps-menu-measure');
		var measured = Math.max(FIT_MIN, Math.min(FIT_MAX, Math.ceil(need) + 2));
		var width = Math.max(measured, Math.min(FIT_MAX, remember(measured)));
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
	 * menu, a narrow window or the full screen editor puts the plugin's
	 * own layout back.
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
		return fitted > FIT_MIN && document.body.classList.contains('sps-menu-fit') && shown();
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
		// The menu itself, and the block editor's frame, which the stylesheet places (also in full screen).
		if (el.closest('#adminmenumain, #wpadminbar') || el.classList.contains('interface-interface-skeleton')) {
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
		/*
		 * Sized for the usual menu (as wide as the window less 160px, or
		 * moved over above): it now runs further off the far side than it
		 * did, so it narrows by the extra width and ends where it did.
		 */
		var sized = Math.abs(end + extra) <= 2 || Math.abs(box.width - (view - FIT_MIN)) <= 1;
		if (moved || (sized && end < -1)) {
			var now = el.getBoundingClientRect();
			var over = rtl ? -now.left : now.right - view;
			if (over > Math.max(0, -end) + 1 || (!moved && over > 1)) {
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
				// Menu titles, printed the same way by WordPress. Parsed apart
				// from the page: innerHTML here, while the page is still being
				// read, would cancel the wait for the footer that draws SEO Pro
				// Stack's screens whole (SEOProStack_Setup::print_whole_screen).
				var title = new window.DOMParser().parseFromString(entry.t, 'text/html').body;
				while (title.firstChild) {
					link.appendChild(title.firstChild);
				}
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
