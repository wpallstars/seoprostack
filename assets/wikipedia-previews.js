/**
 * Wikipedia previews: a short preview of the article behind a Wikipedia link
 * (or a word marked with data-wikipedia-preview) on hover, focus or tap.
 *
 * Elements are marked on the server with data-wp-lang and data-wp-title.
 * The summary comes from Wikipedia's REST API only when a preview opens, and
 * is kept for the rest of the visit. Plain ES5; no jQuery.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 */
(function () {
	'use strict';

	var text = window.seoprostackWikipedia || {};
	var cache = {};
	var popup = null;
	var current = null;
	var showTimer = 0;
	var hideTimer = 0;
	var lastPointer = 'mouse';
	var pointerAt = 0;
	var SELECTOR = '[data-wikipedia-preview][data-wp-title]';

	function target(node) {
		return node && node.closest ? node.closest(SELECTOR) : null;
	}

	function summary(lang, title) {
		var key = lang + ':' + title;
		if (!cache[key]) {
			var address = 'https://' + lang + '.wikipedia.org/api/rest_v1/page/summary/' + encodeURIComponent(title.replace(/ /g, '_'));
			cache[key] = fetch(address, { headers: { Accept: 'application/json' } }).then(function (res) {
				if (!res.ok) {
					throw new Error(String(res.status));
				}
				return res.json();
			});
			cache[key].catch(function () {
				delete cache[key];
			});
		}
		return cache[key];
	}

	function make(tag, className, content) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (content) {
			node.textContent = content;
		}
		return node;
	}

	function place() {
		if (!popup || !current) {
			return;
		}
		var rect = current.getBoundingClientRect();
		var width = popup.offsetWidth;
		var height = popup.offsetHeight;
		var viewW = document.documentElement.clientWidth;
		var viewH = window.innerHeight;
		var left = Math.max(8, Math.min(rect.left, viewW - width - 8));
		var below = rect.bottom + 8;
		var top = below + height > viewH && rect.top - height - 8 > 0 ? rect.top - height - 8 : below;
		popup.style.left = (left + window.pageXOffset) + 'px';
		popup.style.top = (top + window.pageYOffset) + 'px';
	}

	function fill(data, lang, title) {
		popup.textContent = '';
		var close = make('button', 'sps-wikipedia-preview__close', '\u00d7');
		close.type = 'button';
		close.setAttribute('aria-label', text.close || 'Close');
		close.addEventListener('click', function () {
			hide(true);
		});
		popup.appendChild(close);

		if (!data) {
			popup.appendChild(make('p', 'sps-wikipedia-preview__extract', text.loading || 'Loading…'));
			return;
		}
		var page = data.content_urls && data.content_urls.desktop ? data.content_urls.desktop.page : 'https://' + lang + '.wikipedia.org/wiki/' + encodeURIComponent(title.replace(/ /g, '_'));
		if (data.error) {
			popup.appendChild(make('p', 'sps-wikipedia-preview__extract', text.unavailable || 'No preview is available.'));
		} else {
			if (data.thumbnail && data.thumbnail.source) {
				var img = make('img', 'sps-wikipedia-preview__image');
				img.src = data.thumbnail.source;
				img.alt = '';
				img.loading = 'lazy';
				img.addEventListener('load', place);
				popup.appendChild(img);
			}
			var heading = make('p', 'sps-wikipedia-preview__title', data.title ? String(data.title).replace(/<[^>]*>/g, '') : title);
			heading.id = 'sps-wikipedia-preview-title';
			popup.appendChild(heading);
			if (data.dir) {
				popup.setAttribute('dir', data.dir);
			}
			popup.appendChild(make('p', 'sps-wikipedia-preview__extract', data.extract || ''));
		}
		var footer = make('p', 'sps-wikipedia-preview__footer');
		var more = make('a', '', text.readMore || 'Read more on Wikipedia');
		more.href = page;
		footer.appendChild(more);
		footer.appendChild(make('span', 'sps-wikipedia-preview__source', text.source || 'Wikipedia'));
		popup.appendChild(footer);
	}

	function show(el) {
		clearTimeout(hideTimer);
		if (current === el && popup) {
			return;
		}
		hide(false);
		var lang = el.getAttribute('data-wp-lang') || 'en';
		var title = el.getAttribute('data-wp-title') || '';
		if (!/^[a-z][a-z0-9-]{1,15}$/.test(lang) || !title) {
			return;
		}
		current = el;
		popup = make('div', 'sps-wikipedia-preview');
		popup.setAttribute('role', 'dialog');
		popup.setAttribute('aria-labelledby', 'sps-wikipedia-preview-title');
		popup.addEventListener('mouseenter', function () {
			clearTimeout(hideTimer);
		});
		popup.addEventListener('mouseleave', scheduleHide);
		fill(null, lang, title);
		document.body.appendChild(popup);
		el.setAttribute('aria-expanded', 'true');
		place();
		var mine = popup;
		summary(lang, title).then(function (data) {
			if (popup === mine) {
				fill(data, lang, title);
				place();
			}
		}, function () {
			if (popup === mine) {
				fill({ error: true }, lang, title);
				place();
			}
		});
	}

	function hide(refocus) {
		clearTimeout(showTimer);
		clearTimeout(hideTimer);
		if (popup && popup.parentNode) {
			popup.parentNode.removeChild(popup);
		}
		if (current) {
			current.removeAttribute('aria-expanded');
			if (refocus && current.focus) {
				current.focus();
			}
		}
		popup = null;
		current = null;
	}

	function scheduleHide() {
		clearTimeout(showTimer);
		clearTimeout(hideTimer);
		hideTimer = setTimeout(function () {
			hide(false);
		}, 300);
	}

	document.addEventListener('pointerdown', function (e) {
		lastPointer = e.pointerType || 'mouse';
		pointerAt = Date.now();
		if (popup && !popup.contains(e.target) && !target(e.target)) {
			hide(false);
		}
	});

	document.addEventListener('mouseover', function (e) {
		var el = target(e.target);
		if (!el || lastPointer !== 'mouse') {
			return;
		}
		clearTimeout(hideTimer);
		clearTimeout(showTimer);
		showTimer = setTimeout(function () {
			show(el);
		}, 250);
	});

	document.addEventListener('mouseout', function (e) {
		var el = target(e.target);
		if (el && !el.contains(e.relatedTarget)) {
			scheduleHide();
		}
	});

	document.addEventListener('focusin', function (e) {
		var el = target(e.target);
		// Keyboard focus only: a tap focuses links before its click.
		if (el && Date.now() - pointerAt > 800) {
			show(el);
		} else if (!el && popup && !popup.contains(e.target)) {
			hide(false);
		}
	});

	/* Taps open the preview first; the preview links to the article. */
	document.addEventListener('click', function (e) {
		var el = target(e.target);
		if (!el) {
			return;
		}
		var isLink = el.tagName === 'A';
		if (!isLink || (lastPointer !== 'mouse' && current !== el)) {
			e.preventDefault();
			show(el);
		}
	});

	document.addEventListener('keydown', function (e) {
		if (!popup) {
			return;
		}
		if (e.key === 'Escape') {
			hide(true);
		} else if ((e.key === 'Enter' || e.key === ' ') && target(e.target) && e.target.tagName !== 'A') {
			e.preventDefault();
			show(target(e.target));
		}
	});

	window.addEventListener('resize', place);
}());
