/**
 * Like, save and share: the buttons, Saved posts lists and Favorites'
 * shortcodes. The page may be cached, so state is loaded here: like totals
 * and, for logged-in people, what they liked and saved (with a fresh nonce).
 * Visitors' likes and saved posts stay in localStorage. No cookies.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 */
(function (cfg) {
	'use strict';

	if (!cfg || !window.fetch || !document.querySelector) {
		return;
	}

	var t = cfg.i18n;
	var KEY_LIKED = 'seoprostack-liked-' + cfg.site;
	var KEY_SAVED = 'seoprostack-saved-' + cfg.site;
	var state = { user: false, nonce: '', liked: [], saved: [] };

	function all(selector, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	function read(key) {
		try {
			var list = JSON.parse(window.localStorage.getItem(key) || '[]');
			return Array.isArray(list) ? list.map(Number).filter(Boolean) : [];
		} catch (e) {
			return [];
		}
	}

	function write(key, list) {
		try {
			window.localStorage.setItem(key, JSON.stringify(list));
		} catch (e) {
			// Private browsing or storage full: the change lasts for this page.
		}
	}

	function has(list, id) {
		return list.indexOf(id) !== -1;
	}

	function without(list, id) {
		return list.filter(function (x) {
			return x !== id;
		});
	}

	function send(what, data) {
		var body = new window.URLSearchParams();
		body.append('action', cfg.action);
		body.append('do', what);
		if (state.nonce) {
			body.append('nonce', state.nonce);
		}
		Object.keys(data || {}).forEach(function (k) {
			body.append(k, data[k]);
		});
		return window.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok || !json || !json.success) {
					throw new Error('failed');
				}
				return json.data;
			});
		});
	}

	function say(group, text) {
		var status = group && group.querySelector('.sps-reactions__status');
		if (!status) {
			return;
		}
		status.textContent = text;
		window.clearTimeout(status.spsTimer);
		status.spsTimer = window.setTimeout(function () {
			status.textContent = '';
		}, 2500);
	}

	function format(n) {
		try {
			return Number(n).toLocaleString(document.documentElement.lang || undefined);
		} catch (e) {
			return String(n);
		}
	}

	/* ----- Showing state ----- */

	function showCount(id, count) {
		all('[data-sps-reactions="' + id + '"] [data-sps-count], [data-sps-total="' + id + '"]').forEach(function (node) {
			node.textContent = format(count);
		});
	}

	function showGroup(group) {
		var id = Number(group.getAttribute('data-sps-reactions'));
		var like = group.querySelector('[data-sps-like]');
		var save = group.querySelector('[data-sps-save]');
		if (like) {
			var liked = has(state.liked, id);
			like.setAttribute('aria-pressed', liked ? 'true' : 'false');
			like.querySelector('.sps-reactions__label').textContent = liked ? t.liked : t.like;
		}
		if (save) {
			var saved = has(state.saved, id);
			save.setAttribute('aria-pressed', saved ? 'true' : 'false');
			save.querySelector('.sps-reactions__label').textContent = saved ? t.saved : t.save;
		}
	}

	function showAll() {
		all('[data-sps-reactions]').forEach(showGroup);
		all('[data-sps-saved-count]').forEach(function (node) {
			node.textContent = format(state.saved.length);
		});
	}

	function fillList(box) {
		var data = { types: box.getAttribute('data-types') || '' };
		if (!state.user) {
			data.posts = state.saved.join(',');
		}
		var request = state.user || state.saved.length ? send('list', data) : Promise.resolve({ items: [] });
		return request.then(function (res) {
			box.textContent = '';
			if (!res.items.length) {
				var empty = document.createElement('p');
				empty.className = 'sps-saved__empty';
				empty.textContent = box.getAttribute('data-empty') || t.none;
				box.appendChild(empty);
				return;
			}
			var list = document.createElement('ul');
			list.className = 'sps-saved__list';
			res.items.forEach(function (item) {
				var li = document.createElement('li');
				li.className = 'sps-saved__item';
				var a = document.createElement('a');
				a.className = 'sps-saved__link';
				a.href = item.url;
				a.textContent = item.title;
				li.appendChild(a);
				if (box.getAttribute('data-excerpts') === '1' && item.excerpt) {
					var p = document.createElement('p');
					p.className = 'sps-saved__excerpt';
					p.textContent = item.excerpt;
					li.appendChild(p);
				}
				if (box.getAttribute('data-buttons') === '1') {
					var b = document.createElement('button');
					b.type = 'button';
					b.className = 'sps-reactions__button sps-saved__remove';
					b.textContent = t.remove;
					b.setAttribute('data-sps-remove', item.id);
					li.appendChild(b);
				}
				list.appendChild(li);
			});
			box.appendChild(list);
		}, function () {
			box.textContent = t.failed;
		});
	}

	function fillLists() {
		all('[data-sps-saved]').forEach(fillList);
	}

	/* ----- Changes ----- */

	function like(group) {
		var id = Number(group.getAttribute('data-sps-reactions'));
		var on = !has(state.liked, id);
		state.liked = on ? state.liked.concat([id]) : without(state.liked, id);
		if (!state.user) {
			write(KEY_LIKED, state.liked);
		}
		showAll();
		send('like', { post: id, on: on ? '1' : '0' }).then(function (res) {
			showCount(id, res.count);
		}, function () {
			state.liked = on ? without(state.liked, id) : state.liked.concat([id]);
			if (!state.user) {
				write(KEY_LIKED, state.liked);
			}
			showAll();
			say(group, t.failed);
		});
	}

	function setSaved(id, on, group) {
		var before = state.saved;
		state.saved = on ? without(state.saved, id).concat([id]) : without(state.saved, id);
		showAll();
		if (!state.user) {
			write(KEY_SAVED, state.saved);
			fillLists();
			return;
		}
		send('save', { post: id, on: on ? '1' : '0' }).then(function (res) {
			state.saved = res.saved;
			showAll();
			fillLists();
		}, function () {
			state.saved = before;
			showAll();
			say(group, t.failed);
		});
	}

	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) {
			// Refused (no permission, page not focused): try the older way.
			return navigator.clipboard.writeText(text).catch(function () {
				return copyOld(text);
			});
		}
		return copyOld(text);
	}

	function copyOld(text) {
		return new Promise(function (resolve, reject) {
			var area = document.createElement('textarea');
			area.value = text;
			area.setAttribute('readonly', '');
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild(area);
			area.select();
			var ok = false;
			try {
				ok = document.execCommand('copy');
			} catch (e) {
				ok = false;
			}
			document.body.removeChild(area);
			if (ok) {
				resolve();
			} else {
				reject(new Error('copy'));
			}
		});
	}

	function share(button, group) {
		var data = { title: button.getAttribute('data-title') || document.title, url: button.getAttribute('data-url') || window.location.href };
		if (navigator.share && (!navigator.canShare || navigator.canShare(data))) {
			navigator.share(data).catch(function (e) {
				if (!e || e.name !== 'AbortError') {
					copy(data.url).then(function () {
						say(group, t.copied);
					});
				}
			});
			return;
		}
		copy(data.url).then(function () {
			say(group, t.copied);
		}, function () {
			window.prompt('', data.url); // eslint-disable-line no-alert
		});
	}

	function clear(button) {
		var before = state.saved;
		state.saved = [];
		showAll();
		if (!state.user) {
			write(KEY_SAVED, []);
			fillLists();
			return;
		}
		send('clear', {}).then(fillLists, function () {
			state.saved = before;
			showAll();
			button.textContent = t.failed;
		});
	}

	document.addEventListener('click', function (event) {
		var target = event.target.closest ? event.target.closest('button') : null;
		if (!target) {
			return;
		}
		var group = target.closest('[data-sps-reactions]');
		if (target.hasAttribute('data-sps-like') && group) {
			like(group);
		} else if (target.hasAttribute('data-sps-save') && group) {
			var id = Number(group.getAttribute('data-sps-reactions'));
			setSaved(id, !has(state.saved, id), group);
		} else if (target.hasAttribute('data-sps-share')) {
			share(target, group);
		} else if (target.hasAttribute('data-sps-remove')) {
			setSaved(Number(target.getAttribute('data-sps-remove')), false, null);
		} else if (target.hasAttribute('data-sps-clear')) {
			clear(target);
		}
	});

	/* ----- Start ----- */

	var ids = [];
	all('[data-sps-reactions], [data-sps-total]').forEach(function (node) {
		var id = Number(node.getAttribute('data-sps-reactions') || node.getAttribute('data-sps-total'));
		if (id && !has(ids, id)) {
			ids.push(id);
		}
	});

	state.liked = read(KEY_LIKED);
	state.saved = read(KEY_SAVED);
	showAll();

	send('state', { posts: ids.join(',') }).then(function (res) {
		Object.keys(res.counts || {}).forEach(function (id) {
			showCount(id, res.counts[id]);
		});
		if (!res.user) {
			fillLists();
			return;
		}
		state.user = true;
		state.nonce = res.nonce;
		state.liked = res.liked;
		state.saved = res.saved;
		var local = read(KEY_SAVED);
		if (!local.length) {
			showAll();
			fillLists();
			return;
		}
		// Posts saved in this browser before logging in join the account.
		send('merge', { posts: local.join(',') }).then(function (merged) {
			state.saved = merged.saved;
			write(KEY_SAVED, []);
		}).then(function () {
			showAll();
			fillLists();
		}, function () {
			showAll();
			fillLists();
		});
	}, function () {
		fillLists();
	});
}(window.seoprostackReactions));
