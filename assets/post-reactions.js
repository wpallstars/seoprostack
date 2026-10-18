/**
 * Like, save and share: the buttons, Saved posts lists and Favorites'
 * shortcodes. The page may be cached, so state is loaded here: like totals
 * and, for logged-in people, what they liked and saved (with a fresh nonce).
 * Visitors' likes and saved posts stay in localStorage. No cookies.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (cfg) {
	'use strict';

	if (!cfg || !window.fetch || !document.querySelector) {
		return;
	}

	var t = cfg.i18n;
	var KEY_LIKED = 'seoprostack-liked-' + cfg.site;
	var KEY_SAVED = 'seoprostack-saved-' + cfg.site;
	var KEY_SHORTLIST = 'seoprostack-shortlist-' + cfg.site;
	var KEY_SYNC = 'seoprostack-lists-sync-' + cfg.site;
	var keys = { liked: KEY_LIKED, saved: KEY_SAVED, shortlist: KEY_SHORTLIST };
	var state = { user: false, nonce: '', liked: [], saved: [], shortlist: [] };
	var selected = [];
	var queue = Promise.resolve();

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
		all('[data-sps-shortlist]').forEach(function (button) {
			var on = has(state.shortlist, Number(button.getAttribute('data-sps-shortlist')));
			button.setAttribute('aria-pressed', String(on));
			button.querySelector('.sps-reactions__label').textContent = on ? t.shortlisted : t.shortlist;
		});
		all('[data-sps-shortlist-count]').forEach(function (node) {
			node.textContent = format(state.shortlist.length);
		});
		all('[data-sps-saved-count]').forEach(function (node) {
			node.textContent = format(state.saved.length);
		});
	}

	function changed() {
		selected = selected.filter(function (id) { return has(state.shortlist, id); });
		showAll();
		document.dispatchEvent(new CustomEvent('seoprostack:lists', { detail: {
			user: state.user, liked: state.liked.slice(), saved: state.saved.slice(), shortlist: state.shortlist.slice()
		} }));
	}

	function safeUrl(value) {
		try {
			var url = new window.URL(value, window.location.href);
			return /^https?:$/.test(url.protocol) ? url.href : '';
		} catch (e) {
			return '';
		}
	}

	function fillList(box) {
		var which = box.getAttribute('data-list') || 'saved';
		var data = { which: which, types: box.getAttribute('data-types') || '' };
		var revision = (box.spsRevision || 0) + 1;
		box.spsRevision = revision;
		if (!state.user) {
			data.posts = state[which].join(',');
		}
		var request = state[which].length ? send('list', data) : Promise.resolve({ items: [] });
		return request.then(function (res) {
			if (box.spsRevision !== revision) { return; }
			box.textContent = '';
			if (!res.items.length) {
				var empty = document.createElement('p');
				empty.className = 'sps-saved__empty';
				empty.textContent = box.getAttribute('data-empty') || (which === 'saved' ? t.none : t.emptyList);
				box.appendChild(empty);
				return;
			}
			var list = document.createElement('ul');
			list.className = 'sps-saved__list';
			res.items.forEach(function (item) {
				var li = document.createElement('li');
				li.className = 'sps-saved__item';
				if (which === 'shortlist' && box.closest('[data-sps-panel]')) {
					var check = document.createElement('input');
					check.type = 'checkbox';
					check.setAttribute('data-sps-select', item.id);
					check.setAttribute('aria-label', item.title);
					check.checked = has(selected, Number(item.id));
					li.appendChild(check);
				}
				if (item.image && safeUrl(item.image)) {
					var image = document.createElement('img');
					image.src = safeUrl(item.image);
					image.alt = '';
					image.loading = 'lazy';
					image.width = 64;
					image.height = 64;
					li.appendChild(image);
				}
				var a = document.createElement('a');
				a.className = 'sps-saved__link';
				a.href = safeUrl(item.url);
				a.textContent = item.title;
				li.appendChild(a);
				(item.meta || []).slice(0, 3).forEach(function (line) {
					var meta = document.createElement('p');
					meta.className = 'sps-saved__excerpt';
					meta.textContent = line;
					li.appendChild(meta);
				});
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
			if (box.spsRevision === revision) { box.textContent = t.failed; }
		});
	}

	function fillLists() {
		all('[data-sps-saved]').forEach(fillList);
	}

	/* ----- Changes ----- */

	function toggle(which, id, on, group) {
		if (!Object.prototype.hasOwnProperty.call(keys, which) || !Number.isInteger(Number(id)) || Number(id) < 1) {
			return Promise.reject(new Error('invalid list or post'));
		}
		id = Number(id);
		var task = queue.then(function () {
			on = typeof on === 'boolean' ? on : !has(state[which], id);
			if (has(state[which], id) === on) { return; }
			var before = state[which].slice();
			state[which] = (on ? without(before, id).concat([id]) : without(before, id)).slice(-(which === 'shortlist' ? cfg.shortlistMax : 500));
			changed();
			var action = { liked: 'like', saved: 'save', shortlist: 'shortlist' }[which];
			var request = state.user || which === 'liked' ? send(action, { post: id, on: on ? '1' : '0' }) : Promise.resolve({});
			return request.then(function (res) {
				if (res[which]) { state[which] = res[which]; }
				if (which === 'liked') { showCount(id, res.count); }
				if (!state.user) { write(keys[which], state[which]); }
				else { write(KEY_SYNC, Date.now() + ':' + Math.random()); }
				changed();
				fillLists();
			}, function (error) {
				state[which] = before;
				changed();
				say(group || document.querySelector('[data-sps-panel]') || document, t.failed);
				throw error;
			});
		});
		queue = task.catch(function () {});
		return task;
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
		queue = queue.then(function () {
		var before = state.saved;
		state.saved = [];
		changed();
		if (!state.user) {
			write(KEY_SAVED, []);
			fillLists();
			return;
		}
		return send('clear', {}).then(function () { write(KEY_SYNC, Date.now()); fillLists(); }, function () {
			state.saved = before;
			changed();
			button.textContent = t.failed;
		});
		});
	}

	document.addEventListener('click', function (event) {
		var target = event.target.closest ? event.target.closest('button') : null;
		if (!target) {
			return;
		}
		var group = target.closest('[data-sps-reactions]');
		if (target.hasAttribute('data-sps-like') && group) {
			toggle('liked', Number(group.getAttribute('data-sps-reactions')), undefined, group).catch(function () {});
		} else if (target.hasAttribute('data-sps-save') && group) {
			var id = Number(group.getAttribute('data-sps-reactions'));
			toggle('saved', id, undefined, group).catch(function () {});
		} else if (target.hasAttribute('data-sps-shortlist')) {
			toggle('shortlist', Number(target.getAttribute('data-sps-shortlist')), undefined, target.parentElement).catch(function () {});
		} else if (target.hasAttribute('data-sps-share')) {
			share(target, group);
		} else if (target.hasAttribute('data-sps-remove')) {
			toggle(target.closest('[data-sps-saved]').getAttribute('data-list') || 'saved', Number(target.getAttribute('data-sps-remove')), false).catch(function () {});
		} else if (target.hasAttribute('data-sps-clear')) {
			clear(target);
		} else if (target.hasAttribute('data-sps-panel-open')) {
			var dialog = target.parentElement.querySelector('[data-sps-panel]');
			if (dialog && dialog.showModal) { dialog.spsTrigger = target; dialog.showModal(); }
		} else if (target.hasAttribute('data-sps-panel-close')) {
			target.closest('dialog').close();
		} else if (target.getAttribute('role') === 'tab' && target.closest('[data-sps-panel]')) {
			activateTab(target);
		}
	});

	function activateTab(tab) {
		var dialog = tab.closest('[data-sps-panel]');
		all('[role="tab"]', dialog).forEach(function (node) {
			var active = node === tab;
			node.setAttribute('aria-selected', String(active));
			node.tabIndex = active ? 0 : -1;
			document.getElementById(node.getAttribute('aria-controls')).hidden = !active;
		});
		tab.focus();
	}

	document.addEventListener('keydown', function (event) {
		var tab = event.target;
		if (tab.getAttribute('role') !== 'tab' || !tab.closest('[data-sps-panel]')) { return; }
		var tabs = all('[role="tab"]', tab.parentElement);
		var index = tabs.indexOf(tab);
		if (event.key === 'ArrowRight') { index = (index + 1) % tabs.length; }
		else if (event.key === 'ArrowLeft') { index = (index + tabs.length - 1) % tabs.length; }
		else if (event.key === 'Home') { index = 0; }
		else if (event.key === 'End') { index = tabs.length - 1; }
		else { return; }
		event.preventDefault();
		activateTab(tabs[index]);
	});

	all('[data-sps-panel]').forEach(function (dialog) {
		dialog.addEventListener('close', function () {
			if (dialog.spsTrigger) { dialog.spsTrigger.focus(); }
		});
	});
	document.addEventListener('change', function (event) {
		var input = event.target;
		if (!input.hasAttribute('data-sps-select')) { return; }
		var id = Number(input.getAttribute('data-sps-select'));
		selected = without(selected, id);
		if (input.checked && has(state.shortlist, id)) { selected.push(id); }
		all('[data-sps-select="' + id + '"]').forEach(function (node) { node.checked = input.checked; });
	});

	cfg.api = {
		get: function (list) { return Object.prototype.hasOwnProperty.call(keys, list) ? state[list].slice() : []; },
		toggle: toggle,
		selected: function () { return selected.slice(); }
	};

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
	state.shortlist = read(KEY_SHORTLIST).slice(-cfg.shortlistMax);
	showAll();

	function loadState() {
	return send('state', { posts: ids.join(',') }).then(function (res) {
		Object.keys(res.counts || {}).forEach(function (id) {
			showCount(id, res.counts[id]);
		});
		if (!res.user) {
			state.user = false;
			state.nonce = '';
			Object.keys(keys).forEach(function (list) { state[list] = read(keys[list]).slice(-(list === 'shortlist' ? cfg.shortlistMax : 500)); });
			changed();
			fillLists();
			return;
		}
		state.user = true;
		state.nonce = res.nonce;
		return Promise.all(Object.keys(keys).map(function (list) {
			state[list] = res[list] || [];
			var local = read(keys[list]);
			if (!local.length) { return Promise.resolve(); }
			// Merging Liked must not increment already counted public totals.
			return send('merge', { list: list, posts: local.join(',') }).then(function (merged) {
				state[list] = merged[list];
				write(keys[list], []);
			}).catch(function () { say(document, t.failed); });
		})).then(function () {
			changed();
			fillLists();
		});
	}, function () {
		changed();
		fillLists();
	});
	}
	queue = loadState();
	window.addEventListener('storage', function (event) {
		if (event.key !== null && event.key !== KEY_SYNC && Object.keys(keys).every(function (list) { return keys[list] !== event.key; })) { return; }
		queue = queue.then(loadState);
	});
}(window.seoprostackReactions));
