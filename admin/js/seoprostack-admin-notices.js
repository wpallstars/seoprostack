/**
 * Hide admin notices: move notices into a "Notices (n)" panel.
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
 * @package SEOProStack
 */
(function ($, cfg) {
	'use strict';

	if (!$ || !cfg) {
		return;
	}

	/** Places a late notice is left alone: our panel, snackbars, dialogs. */
	var AWAY = '#screen-meta, .components-snackbar-list, .components-modal__frame, .media-modal, [role="dialog"], [role="alertdialog"]';

	/** Properties React and Vue set on the elements they draw. */
	var OWNED = /^(__react|__vue|__vnode|_vnode)/;

	var $content = $();
	var $meta = $();
	var $links = $();
	var $panel = null;
	var $button = null;
	var $wrap = null;
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

	/**
	 * The button and panel, made on first use.
	 *
	 * @return {jQuery|null} The panel, or null when the page has no Screen Options area.
	 */
	function ui() {
		if ($panel) {
			return $panel;
		}
		$meta = $('#screen-meta');
		if (!$meta.length) {
			return null;
		}
		$links = $('#screen-meta-links');
		if (!$links.length) {
			$links = $('<div id="screen-meta-links"></div>').insertAfter($meta);
		}
		// Without Screen Options or Help the button gets a row of its own.
		$links.toggleClass('sps-notices-only', !$links.children('.screen-meta-toggle').length).addClass('sps-has-notices');

		$panel = $('<div id="sps-notices-wrap" class="hidden" tabindex="-1"></div>').attr('aria-label', cfg.panel).appendTo($meta);
		var buttonClass = $links.find('.show-settings').first().attr('class') || 'button button-compact show-settings';
		$button = $('<button type="button" id="sps-notices-link" aria-controls="sps-notices-wrap" aria-expanded="false"></button>')
			.attr('class', buttonClass.replace(/\bscreen-meta-active\b/, ''));
		$wrap = $('<div id="sps-notices-link-wrap" class="hide-if-no-js screen-meta-toggle"></div>').append($button).prependTo($links);

		$button.on('click', function () {
			if (window.screenMeta) {
				window.screenMeta.toggleEvent.call(this);
			} else {
				$meta.toggle();
				$panel.toggleClass('hidden');
			}
		});
		if (window.MutationObserver) {
			new MutationObserver(count).observe($panel[0], { childList: true });
		}
		return $panel;
	}

	/**
	 * Show the count, and hide the button while the panel is empty.
	 */
	function count() {
		if (!$panel) {
			return;
		}
		var n = $panel.children().length;
		$button.text(cfg.label.replace('%d', n));
		if (n) {
			if (!$wrap.parent().length) {
				$wrap.prependTo($links);
				$links.addClass('sps-has-notices');
			}
			return;
		}
		if ($panel.is(':visible') && window.screenMeta) {
			window.screenMeta.close($panel, $button);
		}
		$wrap.detach();
		$links.removeClass('sps-has-notices');
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
		if (!inPage(orig) || orig.hidden || orig.style.display === 'none') {
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
		$notices = $notices.not('#screen-meta, #screen-meta *');
		// Outermost only: a wrapper takes the notices inside it along.
		var $all = $notices;
		$notices = $notices.filter(function () {
			return !$(this).parents().filter($all).length;
		});
		$notices.each(function () {
			take(this);
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
