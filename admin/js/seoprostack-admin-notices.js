/**
 * Hide admin notices: move notices into a panel under a bell in the admin bar.
 *
 * Runs when the page is ready, after common.js has moved the notices under
 * the heading. Takes the notices marked while the page was drawn (see
 * SEOProStack_Admin_Notices::mark()), the ones common.js would move, and
 * notices that scripts add before the person first clicks or types.
 *
 * A notice drawn by React or Vue stays where it is, hidden, because moving it
 * would break the script that owns it. The panel shows a copy that passes
 * clicks back, and follows the original until it goes away.
 *
 * Empty boxes the notice hooks printed, for a script to fill, stay hidden
 * while empty (see wait()).
 *
 * The panel stays inside #wpbody-content, so plugin styles and handlers
 * scoped to it keep working, and is fixed under the bell. Like core's admin
 * bar menus, it opens while the mouse points at the bell and closes when the
 * mouse moves away; keys and taps open it until Escape or a press elsewhere.
 * The bell is on every screen, with a dot while the panel has notices.
 *
 * Notices kept from plugins this screen skipped carry data-sps-stored; using
 * one's dismiss control tells the server to stop showing it (dismissed()).
 *
 * @package SEOProStack
 */
(function ($, cfg) {
	'use strict';

	if (!$ || !cfg) {
		return;
	}

	/** Places a late notice is left alone: our panel, core's Help, snackbars, dialogs. */
	var AWAY = '#sps-notices-wrap, #screen-meta, .components-snackbar-list, .components-modal__frame, .media-modal, [role="dialog"], [role="alertdialog"]';

	/** Properties React and Vue set on the elements they draw. */
	var OWNED = /^(__react|__vue|__vnode|_vnode)/;

	var $content = $();
	var $panel = null;
	var $item = null;
	var $button = null;
	var proxies = [];
	var goneWatch = null;

	function shown() {
		return this.style.display !== 'none';
	}

	function inPage(el) {
		return document.documentElement.contains(el);
	}

	/**
	 * Whether a script framework draws this element.
	 *
	 * @param {Element} el Notice.
	 * @return {boolean}
	 */
	function owned(el) {
		for (var n = el; n && n !== $content[0]; n = n.parentNode) {
			var keys = Object.keys(n);
			for (var i = 0; i < keys.length; i++) {
				if (OWNED.test(keys[i])) {
					return true;
				}
			}
		}
		return false;
	}

	function isOpen() {
		return !!$panel && !$panel.hasClass('hidden');
	}

	/**
	 * Line the panel up under the bell, kept on screen.
	 */
	function place() {
		var r = $button[0].getBoundingClientRect();
		var width = document.documentElement.clientWidth;
		if ($(document.body).hasClass('rtl')) {
			$panel.css({ left: Math.max(0, Math.min(r.left, width - $panel.outerWidth())), right: 'auto' });
		} else {
			$panel.css({ right: Math.max(0, Math.min(width - r.right, width - $panel.outerWidth())), left: 'auto' });
		}
	}

	/** Opened by key or tap: stays open until Escape, the bell or a press elsewhere. */
	var pinned = false;

	/**
	 * @param {boolean} [pin] Opened by key or tap: move focus into the panel
	 *                        and keep it open when a mouse leaves. Pointing
	 *                        does not move focus, so typing elsewhere goes on.
	 */
	function open(pin) {
		if (!isOpen()) {
			$panel.removeClass('hidden');
			place();
			$item.addClass('hover sps-notices-open');
			$button.attr('aria-expanded', 'true');
		}
		if (pin) {
			pinned = true;
			$panel[0].focus({ preventScroll: true });
		}
	}

	/**
	 * @param {boolean} [refocus] Put focus back on the bell.
	 */
	function close(refocus) {
		if (!isOpen()) {
			return;
		}
		pinned = false;
		// A hidden panel (or bell) sends no pointerleave; the next pointerenter sets these again.
		overBell = false;
		overPanel = false;
		$panel.addClass('hidden');
		$item.removeClass('hover sps-notices-open');
		$button.attr('aria-expanded', 'false');
		if (refocus) {
			$button[0].focus();
		}
	}

	/** Delays matching core's admin bar menus (hoverIntent interval and timeout). */
	var OPEN_DELAY = 100;
	var CLOSE_DELAY = 180;

	var overBell = false;
	var overPanel = false;
	var hoverTimer = null;
	var byMouse = false;

	/**
	 * Whether someone is typing in a field inside the panel.
	 *
	 * @return {boolean}
	 */
	function typing() {
		var el = document.activeElement;
		return !!el && el !== $panel[0] && $panel[0].contains(el) && $(el).is('input, textarea, select, [contenteditable]');
	}

	/**
	 * Open the panel while a mouse points at the bell or the panel, and close
	 * it when the mouse moves away, as core's admin bar menus do. Touch and
	 * keys open it with a click (see ui()) and pin it.
	 */
	function hover() {
		var settle = function () {
			clearTimeout(hoverTimer);
			hoverTimer = null;
			if (overBell && !isOpen()) {
				open();
			} else if (!overBell && !overPanel && isOpen() && !pinned && !typing()) {
				close();
			}
		};
		var track = function (el, set) {
			el.addEventListener('pointerenter', function (e) {
				if ('mouse' === e.pointerType) {
					set(true);
					clearTimeout(hoverTimer);
					hoverTimer = setTimeout(settle, isOpen() ? 0 : OPEN_DELAY);
				}
			});
			el.addEventListener('pointerleave', function (e) {
				if ('mouse' === e.pointerType) {
					set(false);
					clearTimeout(hoverTimer);
					hoverTimer = setTimeout(settle, CLOSE_DELAY);
				}
			});
		};
		track($item[0], function (on) {
			overBell = on;
		});
		track($panel[0], function (on) {
			overPanel = on;
		});
		$button[0].addEventListener('pointerdown', function (e) {
			byMouse = 'mouse' === e.pointerType;
		});
	}

	/**
	 * The bell's panel, made on first use.
	 *
	 * @return {jQuery|null} The panel, or null when the admin bar has no bell.
	 */
	function ui() {
		if ($panel) {
			return $panel;
		}
		$item = $(document.getElementById(cfg.node));
		$button = $item.children('.ab-item').first();
		if (!$button.length) {
			return null;
		}
		$panel = $('<div id="sps-notices-wrap" class="hidden" tabindex="-1" role="region"></div>').attr('aria-label', cfg.panel).prependTo($content);
		// Shown by CSS only while it is the panel's one child.
		$('<p class="sps-notices-none"></p>').text(cfg.none).appendTo($panel);
		$button.attr({ role: 'button', 'aria-controls': 'sps-notices-wrap', 'aria-expanded': 'false' });

		hover();
		$button.on('click', function (e) {
			e.preventDefault();
			if (byMouse) {
				// Pointing opens it already; a click (also before the delay) only opens it.
				byMouse = false;
				open();
			} else if (isOpen()) {
				close();
			} else {
				open(true);
			}
		}).on('keydown', function (e) {
			byMouse = false;
			// A link acts on Enter; as a button it also answers Space.
			if (' ' === e.key) {
				e.preventDefault();
				$(this).trigger('click');
			}
		});
		// Pressed outside: checked on press, before a click handler can remove its target.
		document.addEventListener('pointerdown', function (e) {
			if (isOpen() && !$panel[0].contains(e.target) && !$item[0].contains(e.target)) {
				close();
			}
		}, true);
		$(document).on('keydown', function (e) {
			if ('Escape' === e.key && isOpen()) {
				close(true);
			}
		});
		$(window).on('resize', function () {
			if (isOpen()) {
				place();
			}
		});
		if (window.MutationObserver) {
			new MutationObserver(count).observe($panel[0], { childList: true });
		}
		$panel.on('click', '[' + cfg.stored + ']', dismissed);
		return $panel;
	}

	/**
	 * Show the dot while the panel has notices, and the count to screen
	 * readers. The bell stays, so the bar never moves.
	 */
	function count() {
		if (!$panel) {
			return;
		}
		var n = $panel.children().not('.sps-notices-none').length;
		$button.attr('aria-label', cfg.label.replace('%d', n));
		$item.toggleClass('sps-notices-empty', !n);
	}

	/** Controls that dismiss a notice, by class, link or words. */
	var DISMISS = /dismiss|(^|[\s_-])(hide|close|later|skip)([\s_-]|$)|no,? thanks|don.t show/i;

	/**
	 * A kept notice of a plugin this screen skipped was dismissed: stop
	 * showing it on screens that skip that plugin. Its plugin is not loaded,
	 * so a dismiss button that needs its script just takes the notice away;
	 * a link goes on to its address, which loads every plugin.
	 *
	 * @param {Event} e Click inside a kept notice.
	 */
	function dismissed(e) {
		var $control = $(e.target).closest('a, button, [role="button"], .notice-dismiss');
		if (!$control.length || !$.contains(this, $control[0]) && $control[0] !== this) {
			return;
		}
		var el = $control[0];
		var words = [el.className, el.getAttribute('href') || '', el.getAttribute('aria-label') || '', $control.text()].join(' ');
		if (!$control.is('.notice-dismiss') && !DISMISS.test(words)) {
			return;
		}
		var notice = this;
		$.post(cfg.ajax, { action: cfg.forget, nonce: cfg.nonce, hash: notice.getAttribute(cfg.stored) });
		var href = el.getAttribute('href');
		if (!$control.is('.notice-dismiss') && (!href || '#' === href.charAt(0) || /^javascript:/i.test(href))) {
			e.preventDefault();
			$(notice).fadeTo(100, 0, function () {
				$(notice).slideUp(100, function () {
					$(notice).remove();
				});
			});
		}
	}

	/**
	 * Pass a click on the copy to the same element in the original.
	 *
	 * @param {Object} item Proxy record.
	 * @return {Function} Click handler.
	 */
	function forward(item) {
		return function (e) {
			var path = [];
			var n = e.target;
			for (; n && n !== this; n = n.parentNode) {
				path.unshift($(n).index());
			}
			if (n !== this) {
				return;
			}
			var target = item.orig;
			for (var i = 0; target && i < path.length; i++) {
				target = target.children[path[i]];
			}
			if (!target) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			target.dispatchEvent(new MouseEvent('click', {
				bubbles: true,
				cancelable: true,
				view: window,
				button: e.button,
				ctrlKey: e.ctrlKey,
				metaKey: e.metaKey,
				shiftKey: e.shiftKey,
				altKey: e.altKey
			}));
		};
	}

	/**
	 * Draw, redraw or drop the copy of a script-owned notice.
	 *
	 * @param {Object} item Proxy record.
	 */
	function render(item) {
		var orig = item.orig;
		if (!inPage(orig) || orig.hidden || orig.style.display === 'none' || !filled(orig)) {
			if (item.clone) {
				$(item.clone).remove();
				item.clone = null;
			}
			if (!inPage(orig)) {
				item.watch.disconnect();
				proxies.splice($.inArray(item, proxies), 1);
			}
			return;
		}
		if (!orig.classList.contains('sps-notice-away')) {
			orig.classList.add('sps-notice-away');
		}
		var clone = orig.cloneNode(true);
		clone.classList.remove('sps-notice-away');
		clone.removeAttribute('id');
		$(clone).find('[id]').removeAttr('id');
		$(clone).attr('data-sps-proxy', '').on('click', forward(item));
		if (item.clone && item.clone.parentNode) {
			item.clone.parentNode.replaceChild(clone, item.clone);
		} else {
			$panel.append(clone);
		}
		item.clone = clone;
	}

	/**
	 * Show a copy of a script-owned notice and hide the original.
	 *
	 * @param {Element} orig Notice.
	 */
	function proxy(orig) {
		if (!window.MutationObserver) {
			return;
		}
		var item = { orig: orig, clone: null };
		item.watch = new MutationObserver(function () {
			render(item);
		});
		item.watch.observe(orig, { childList: true, subtree: true, characterData: true, attributes: true });
		proxies.push(item);
		render(item);
		if (!goneWatch) {
			// The original is removed by its parent, which its own watcher does not see.
			goneWatch = new MutationObserver(function () {
				$.each(proxies.slice(), function (i, it) {
					if (!inPage(it.orig)) {
						render(it);
					}
				});
			});
			goneWatch.observe($content[0], { childList: true, subtree: true });
		}
	}

	/** Class or id words that mean a notice or banner (as in the marking script). */
	var BANNER = /(^|[\s_-])(notices?|nag|notification|alert|banner|promo|announcement)([\s_-]|$)/i;

	/**
	 * Whether an element shows anything: text, images or embeds.
	 *
	 * @param {Element} el Element.
	 * @return {boolean}
	 */
	function filled(el) {
		return !!$.trim(el.textContent) || !!el.querySelector('img, svg, iframe, video, canvas, object, embed');
	}

	/**
	 * Whether a box is, or holds, a notice or banner.
	 *
	 * @param {Element} el Box.
	 * @return {boolean}
	 */
	function noticeLike(el) {
		if ($(el).is(cfg.notices) || $(el).find(cfg.notices).length) {
			return true;
		}
		var all = [el].concat($(el).find('[class], [id]').get());
		for (var i = 0; i < all.length; i++) {
			if (BANNER.test((all[i].getAttribute('class') || '') + ' ' + all[i].id)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * An empty box the notice hooks printed, for a script to fill: keep it
	 * hidden while it is empty, so it never pushes the page down. Filled with
	 * a notice or banner, it goes behind the bell; filled with anything else
	 * (a dialog, a toolbar), it shows where it is.
	 *
	 * @param {Element} el Box.
	 */
	function wait(el) {
		var watch = null;
		var check = function () {
			if (!inPage(el)) {
				if (watch) {
					watch.disconnect();
				}
				return;
			}
			// Empty, or what it holds was taken already (a copy is in the panel).
			if (!filled(el) || $(el).find('.sps-notice-away').length) {
				el.classList.add('sps-notice-away');
				return;
			}
			if (watch) {
				watch.disconnect();
			}
			el.classList.remove('sps-notice-away');
			if (noticeLike(el) && !$(el).find(AWAY).length && take(el)) {
				$(document).trigger('wp-notice-added');
			}
		};
		if (window.MutationObserver) {
			watch = new MutationObserver(check);
			watch.observe(el, { childList: true, subtree: true, characterData: true });
		}
		check();
	}

	/**
	 * Put a notice in the panel.
	 *
	 * @param {Element} el Notice.
	 * @return {boolean} Whether it was taken.
	 */
	function take(el) {
		if (!ui()) {
			return false;
		}
		if (owned(el)) {
			proxy(el);
		} else {
			$panel.append(el);
		}
		return true;
	}

	/**
	 * Take notices that scripts add until the person first clicks or types.
	 * Later ones answer what they did, so they stay where the script put them.
	 */
	function watchLate() {
		if (!window.MutationObserver) {
			return;
		}
		var observer = new MutationObserver(function (records) {
			var moved = false;
			$.each(records, function (i, record) {
				$.each(record.addedNodes, function (j, node) {
					if (1 !== node.nodeType) {
						return;
					}
					$(node).find(cfg.notices).addBack(cfg.notices).each(function () {
						var $el = $(this);
						if (!inPage(this) || this.style.display === 'none' || $el.is(cfg.skip) || $el.closest(AWAY).length || $el.parent().closest(cfg.notices).length) {
							return;
						}
						moved = take(this) || moved;
					});
				});
			});
			if (moved) {
				// Add dismiss buttons to moved notices, as common.js does for its own.
				$(document).trigger('wp-notice-added');
			}
		});
		var stop = function () {
			observer.disconnect();
			document.removeEventListener('pointerdown', stop, true);
			document.removeEventListener('keydown', stop, true);
		};
		observer.observe($content[0], { childList: true, subtree: true });
		document.addEventListener('pointerdown', stop, true);
		document.addEventListener('keydown', stop, true);
	}

	function init() {
		$content = $('#wpbody-content');
		if (!$content.length) {
			return;
		}
		var $notices = $content.find(cfg.notices).not(cfg.skip).filter(shown);

		// Everything the notice hooks printed (marked while the page was drawn).
		$notices = $notices.add($content.find('[' + cfg.mark + '="move"]'));
		// Kept kinds stay, unless the page's styles hide them where they are:
		// WooCommerce and Rank Math hide every notice on their own screens.
		// Ones a script hid (inline style) are left alone.
		$notices = $notices.add($content.find('[' + cfg.mark + '="keep"]').filter(shown).filter(function () {
			return !this.getClientRects().length;
		}));

		// Inline notices printed above the page, when the page was drawn without
		// the notice hooks' marks.
		var top = [];
		$content.children().each(function () {
			var $el = $(this);
			if ($el.is('.wrap') || $el.find('.wp-header-end, h1').length) {
				return false;
			}
			if ($el.is(cfg.notices) && !$el.is(cfg.kinds)) {
				top.push(this);
			}
		});
		$notices = $notices.add($(top).filter(shown));
		if (cfg.nag) {
			$notices = $notices.add($(cfg.nag));
		}
		$notices = $notices.not('#screen-meta, #screen-meta *, #sps-notices-wrap, #sps-notices-wrap *');
		// Outermost only: a wrapper takes the notices inside it along.
		var $all = $notices;
		$notices = $notices.filter(function () {
			return !$(this).parents().filter($all).length;
		});
		// The bell is on every screen; with no notices its panel says so.
		ui();
		$notices.each(function () {
			take(this);
		});
		$content.find('[' + cfg.mark + '="wait"]').each(function () {
			wait(this);
		});
		// A box a script put in place of a waiting one (marked by the marking script).
		document.addEventListener('sps-notice-wait', function (e) {
			if (e.detail && 1 === e.detail.nodeType) {
				wait(e.detail);
			}
		});
		count();
		watchLate();
	}

	$(function () {
		try {
			init();
		} finally {
			$(document.body).removeClass(cfg.loading);
		}
	});
})(window.jQuery, window.seoprostackNotices);
