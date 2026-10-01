/**
 * Organise the admin menu: the Administrators and Developers menus' third level,
 * and folding sections open and closed.
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

	window.seoprostackMenuFlyouts = function (data) {
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
