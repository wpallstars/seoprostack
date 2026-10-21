/**
 * Like, save and share: the buttons, the "Save to list" chooser, the Your
 * lists block and Saved panel, Saved posts lists and Favorites' shortcodes.
 *
 * Liked is the default list; the others are named by the person
 * ({id, name, posts}). Saving a post adds it to Liked and opens the chooser.
 * The page may be cached, so state is loaded here: like totals and, for
 * logged-in people, their lists (with a fresh nonce). Visitors' lists stay
 * in localStorage and join their account when they log in. No cookies.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (cfg) {
	'use strict';

	if (!cfg || !window.fetch || !document.querySelector || !window.crypto || !window.crypto.getRandomValues) {
		return;
	}

	var t = cfg.i18n;
	var LIKED = 'liked';
	var MAX_POSTS = 500;
	var MAX_LISTS = Number(cfg.maxLists) || 20;
	var NAME_MAX = Number(cfg.nameMax) || 60;
	var KEY_LIKED = 'seoprostack-liked-' + cfg.site;
	var KEY_LISTS = 'seoprostack-lists-' + cfg.site;
	var KEY_SYNC = 'seoprostack-lists-sync-' + cfg.site;
	// Before named lists, visitors' Shortlist and Saved lists were kept apart.
	var LEGACY = {
		shortlist: { key: 'seoprostack-shortlist-' + cfg.site, name: t.shortlistList },
		saved: { key: 'seoprostack-saved-' + cfg.site, name: t.savedList }
	};
	var canDialog = typeof window.HTMLDialogElement === 'function';
	var state = { user: false, nonce: '', liked: [], lists: [] };
	var ready = false;
	var current = null;
	var chooser = null;
	var cache = {};
	var serial = 0;
	var queue = Promise.resolve();

	function noop() {}

	/* ----- Helpers ----- */

	function all(selector, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	function button(className, text, attrs) {
		var node = el('button', 'sps-reactions__button' + (className ? ' ' + className : ''), text);
		node.type = 'button';
		Object.keys(attrs || {}).forEach(function (key) {
			node.setAttribute(key, attrs[key]);
		});
		return node;
	}

	function has(list, id) {
		return list.indexOf(id) !== -1;
	}

	function minus(list, ids) {
		return list.filter(function (x) {
			return !has(ids, x);
		});
	}

	function postIds(value) {
		var out = [];
		(Array.isArray(value) ? value : []).forEach(function (x) {
			var id = Number(x);
			if (Number.isInteger(id) && id > 0 && !has(out, id)) {
				out.push(id);
			}
		});
		return out;
	}

	function format(n) {
		try {
			return Number(n).toLocaleString(document.documentElement.lang || undefined);
		} catch (e) {
			return String(n);
		}
	}

	function safeUrl(value) {
		try {
			var url = new window.URL(value, window.location.href);
			return /^https?:$/.test(url.protocol) ? url.href : '';
		} catch (e) {
			return '';
		}
	}

	// A translated sentence with links in place of %s, %1$s and %2$s.
	function compose(template, nodes) {
		var out = document.createDocumentFragment();
		var next = 0;
		String(template).split(/(%\d\$s|%s)/).forEach(function (part) {
			var numbered = /^%(\d)\$s$/.exec(part);
			var node = null;
			if (numbered) {
				node = nodes[Number(numbered[1]) - 1];
			} else if (part === '%s') {
				node = nodes[next];
				next += 1;
			}
			if (node) {
				out.appendChild(node);
			} else if (part && !numbered && part !== '%s') {
				out.appendChild(document.createTextNode(part));
			}
		});
		return out;
	}

	function say(where, text) {
		var status = null;
		if (where && where.children) {
			status = Array.prototype.filter.call(where.children, function (node) {
				return node.classList.contains('sps-reactions__status');
			})[0] || where.querySelector('.sps-reactions__status');
		}
		if (!status) {
			return;
		}
		status.textContent = text;
		window.clearTimeout(status.spsTimer);
		status.spsTimer = window.setTimeout(function () {
			status.textContent = '';
		}, 4000);
	}

	// Rebuild a container, keeping focus on the same control when it was there.
	function rebuild(container, build) {
		var active = document.activeElement;
		var key = active && container.contains(active) ? active.getAttribute('data-sps-key') : null;
		container.textContent = '';
		build(container);
		if (key) {
			var again = container.querySelector('[data-sps-key="' + key + '"]');
			if (again) {
				again.focus();
			}
		}
	}

	/* ----- Lists ----- */

	function maxPosts(id) {
		return id === 'shortlist' ? cfg.shortlistMax : MAX_POSTS;
	}

	function cleanId(id) {
		var value = typeof id === 'string' ? id.toLowerCase() : '';
		return /^[a-z0-9][a-z0-9-]{0,39}$/.test(value) && value !== LIKED ? value : '';
	}

	function cleanName(name) {
		var value = typeof name === 'string' ? name.replace(/[<>]/g, '').replace(/\s+/g, ' ').trim() : '';
		return Array.from(value).slice(0, NAME_MAX).join('').trim();
	}

	function find(id, lists) {
		return (lists || state.lists).filter(function (list) {
			return list.id === id;
		})[0] || null;
	}

	function byName(name) {
		var lower = name.toLowerCase();
		return state.lists.filter(function (list) {
			return list.name.toLowerCase() === lower;
		})[0] || null;
	}

	function cleanLists(value) {
		var out = [];
		(Array.isArray(value) ? value : []).forEach(function (list) {
			if (!list || typeof list !== 'object' || out.length >= MAX_LISTS) {
				return;
			}
			var id = cleanId(list.id);
			var name = cleanName(list.name);
			if (id && name && !find(id, out)) {
				out.push({ id: id, name: name, posts: postIds(list.posts).slice(-maxPosts(id)) });
			}
		});
		return out;
	}

	function allLists() {
		return [{ id: LIKED, name: t.likedList, posts: state.liked }].concat(state.lists);
	}

	function listInfo(id) {
		return id === LIKED ? allLists()[0] : find(id);
	}

	function postsOf(id) {
		var list = listInfo(id);
		return list ? list.posts : [];
	}

	function inAny(id) {
		return allLists().some(function (list) {
			return has(list.posts, id);
		});
	}

	function savedCount() {
		var seen = [];
		allLists().forEach(function (list) {
			list.posts.forEach(function (id) {
				if (!has(seen, id)) {
					seen.push(id);
				}
			});
		});
		return seen.length;
	}

	// Random letters and digits, for list IDs and the other tabs' sync mark.
	function token(length) {
		var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var bytes = new window.Uint8Array(length);
		window.crypto.getRandomValues(bytes);
		var out = '';
		for (var i = 0; i < length; i++) {
			out += chars.charAt(bytes[i] % chars.length);
		}
		return out;
	}

	function newId() {
		var id;
		do {
			id = 'l-' + token(8);
		} while (find(id));
		return id;
	}

	function snapshot() {
		return {
			liked: state.liked.slice(),
			lists: state.lists.map(function (list) {
				return { id: list.id, name: list.name, posts: list.posts.slice() };
			})
		};
	}

	function restore(before) {
		state.liked = before.liked;
		state.lists = before.lists;
	}

	// The server's answer for a logged-in person.
	function apply(res) {
		if (res && Array.isArray(res.liked)) {
			state.liked = postIds(res.liked);
		}
		if (res && Array.isArray(res.lists)) {
			state.lists = cleanLists(res.lists);
		}
	}

	// Add posts to a list, or take them off, here only. False: no such list.
	function localChange(id, posts, on) {
		if (id === LIKED) {
			state.liked = (on ? state.liked.concat(minus(posts, state.liked)) : minus(state.liked, posts)).slice(-MAX_POSTS);
			return true;
		}
		var list = find(id);
		if (!list) {
			if (!on) {
				return true;
			}
			if (!LEGACY[id]) {
				return false;
			}
			list = { id: id, name: LEGACY[id].name, posts: [] };
			state.lists.push(list);
		}
		var rest = minus(list.posts, posts);
		list.posts = (on ? rest.concat(posts) : rest).slice(-maxPosts(id));
		return true;
	}

	/* ----- Storage ----- */

	function readJson(key) {
		try {
			return JSON.parse(window.localStorage.getItem(key) || 'null');
		} catch (e) {
			return null;
		}
	}

	function write(key, value) {
		try {
			window.localStorage.setItem(key, JSON.stringify(value));
		} catch (e) {
			// Private browsing or storage full: the change lasts for this page.
		}
	}

	function forget(key) {
		try {
			window.localStorage.removeItem(key);
		} catch (e) {
			// Nothing to do.
		}
	}

	function readLocal() {
		var lists = readJson(KEY_LISTS);
		if (!Array.isArray(lists)) {
			lists = Object.keys(LEGACY).map(function (id) {
				return { id: id, name: LEGACY[id].name, posts: postIds(readJson(LEGACY[id].key)) };
			}).filter(function (list) {
				return list.posts.length;
			});
		}
		return { liked: postIds(readJson(KEY_LIKED)).slice(-MAX_POSTS), lists: cleanLists(lists) };
	}

	function writeLocal() {
		write(KEY_LIKED, state.liked);
		write(KEY_LISTS, state.lists);
	}

	function clearLocal() {
		[KEY_LIKED, KEY_LISTS, LEGACY.saved.key, LEGACY.shortlist.key].forEach(forget);
	}

	/* ----- Server ----- */

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
			return res.json().catch(function () {
				return null;
			}).then(function (json) {
				if (!res.ok || !json || !json.success) {
					var error = new Error('failed');
					error.status = res.status;
					error.code = json && json.data && json.data.message ? String(json.data.message) : '';
					throw error;
				}
				return json.data;
			});
		});
	}

	// Logged-in people's changes go to the server; visitors' stay here.
	function remote(what, data) {
		return state.user ? send(what, data) : Promise.resolve(null);
	}

	// One change at a time, in order.
	function run(work) {
		var task = queue.then(work);
		queue = task.catch(noop);
		return task;
	}

	function tooManyText() {
		return t.tooManyLists.replace('%d', format(MAX_LISTS));
	}

	// Keep a change: the server's lists, or this browser's.
	function saved(res) {
		if (state.user) {
			apply(res);
			write(KEY_SYNC, String(Date.now()) + ':' + token(8));
		} else {
			writeLocal();
		}
		changed();
		return res;
	}

	function failed(before, where) {
		return function (error) {
			restore(before);
			changed();
			say(where, error && error.code === 'too_many' ? tooManyText() : (error && error.code === 'exists' ? t.nameTaken : t.failed));
			throw error;
		};
	}

	// Visitors' likes change only the public totals on the server.
	function visitorLikes(posts, on) {
		return posts.reduce(function (done, id) {
			return done.then(function () {
				return send('like', { post: id, on: on ? '1' : '0' }).then(function (res) {
					showCount(id, res.count);
				}, noop);
			});
		}, Promise.resolve());
	}

	/* ----- Changes ----- */

	// Put a post on a list or take it off (toggle when on is not a boolean).
	function setOn(listId, postId, on, where) {
		postId = Number(postId);
		if (!Number.isInteger(postId) || postId < 1 || (listId !== LIKED && !cleanId(listId))) {
			return Promise.reject(new Error('invalid list or post'));
		}
		return run(function () {
			on = typeof on === 'boolean' ? on : !has(postsOf(listId), postId);
			if (has(postsOf(listId), postId) === on) {
				return Promise.resolve();
			}
			var before = snapshot();
			if (!localChange(listId, [postId], on)) {
				return Promise.reject(new Error('no such list'));
			}
			changed();
			var flag = on ? '1' : '0';
			var request = listId === LIKED ? send('like', { post: postId, on: flag }) : remote('save', { list: listId, post: postId, on: flag });
			return request.then(function (res) {
				if (res && res.count !== undefined && listId === LIKED) {
					showCount(postId, res.count);
				}
				saved(res);
			}, failed(before, where));
		});
	}

	// Move posts to another list, or remove them from this one.
	function bulk(what, from, posts, to, where) {
		return run(function () {
			posts = posts.filter(function (id) {
				return has(postsOf(from), id);
			});
			if (!posts.length || (what === 'move' && (to === from || !listInfo(to)))) {
				return Promise.resolve(0);
			}
			var before = snapshot();
			var liking = what === 'move' && to === LIKED ? minus(posts, state.liked) : [];
			localChange(from, posts, false);
			if (what === 'move') {
				localChange(to, posts, true);
			}
			changed();
			var request;
			if (state.user) {
				request = send(what, what === 'move' ? { list: from, to: to, posts: posts.join(',') } : { list: from, posts: posts.join(',') });
			} else {
				request = visitorLikes(liking, true).then(function () {
					return visitorLikes(from === LIKED ? posts : [], false);
				});
			}
			return request.then(function (res) {
				saved(res);
				if (state.user && (from === LIKED || to === LIKED)) {
					refreshCounts();
				}
				return posts.length;
			}, failed(before, where));
		});
	}

	function create(name, postId, where) {
		return run(function () {
			var existing = byName(name);
			if (!existing && state.lists.length >= MAX_LISTS) {
				say(where, tooManyText());
				return Promise.reject(new Error('too_many'));
			}
			if (state.user) {
				return send('create', { name: name, post: postId || '' }).then(function (res) {
					saved(res);
					return String(res.id || '');
				}, failed(snapshot(), where));
			}
			var id = existing ? existing.id : newId();
			if (!existing) {
				state.lists.push({ id: id, name: name, posts: [] });
			}
			if (postId) {
				localChange(id, [postId], true);
			}
			saved(null);
			return Promise.resolve(id);
		});
	}

	function rename(id, name, where) {
		return run(function () {
			var list = find(id);
			var other = byName(name);
			if (!list) {
				return Promise.reject(new Error('no such list'));
			}
			if (other && other.id !== id) {
				say(where, t.nameTaken);
				return Promise.reject(new Error('exists'));
			}
			var before = snapshot();
			list.name = name;
			changed();
			return remote('rename', { list: id, name: name }).then(function (res) {
				saved(res);
				return id;
			}, failed(before, where));
		});
	}

	function deleteList(id, where) {
		return run(function () {
			var list = find(id);
			if (!list) {
				return Promise.resolve();
			}
			var before = snapshot();
			state.lists = state.lists.filter(function (each) {
				return each !== list;
			});
			changed();
			return remote('delete', { list: id }).then(saved, failed(before, where));
		});
	}

	// [clear_favorites_button]: empties Saved.
	function clear(where) {
		return run(function () {
			var list = find('saved');
			if (!list) {
				return Promise.resolve();
			}
			var before = snapshot();
			list.posts = [];
			changed();
			return remote('clear', {}).then(saved, failed(before, where));
		});
	}

	/* ----- Showing state ----- */

	function showCount(id, count) {
		if (count === undefined) {
			return;
		}
		all('[data-sps-reactions="' + id + '"] [data-sps-count], [data-sps-total="' + id + '"]').forEach(function (node) {
			node.textContent = format(count);
		});
	}

	function label(node, text) {
		var span = node.querySelector('.sps-reactions__label');
		if (span) {
			span.textContent = text;
		}
	}

	function showAll() {
		all('[data-sps-reactions]').forEach(function (group) {
			var id = Number(group.getAttribute('data-sps-reactions'));
			var like = group.querySelector('[data-sps-like]');
			var save = group.querySelector('[data-sps-save]');
			if (like) {
				var liked = has(state.liked, id);
				like.setAttribute('aria-pressed', String(liked));
				label(like, liked ? t.liked : t.like);
			}
			if (save) {
				var kept = inAny(id);
				save.setAttribute('aria-pressed', String(kept));
				label(save, kept ? t.saved : t.save);
			}
		});
		all('[data-sps-shortlist]').forEach(function (node) {
			var on = inAny(Number(node.getAttribute('data-sps-shortlist')));
			node.setAttribute('aria-pressed', String(on));
			label(node, on ? t.shortlisted : t.shortlist);
		});
		var count = savedCount();
		all('[data-sps-shortlist-count]').forEach(function (node) {
			node.textContent = count ? format(count) : '';
		});
		all('[data-sps-saved-count]').forEach(function (node) {
			node.textContent = format(count);
		});
	}

	function changed() {
		all('[data-sps-lists]').forEach(trimSelection);
		showAll();
		if (ready) {
			all('[data-sps-lists]').forEach(renderBox);
			all('[data-sps-saved]').forEach(function (box) {
				if (!box.closest('[data-sps-lists]')) {
					fillList(box);
				}
			});
			renderChooser();
		}
		document.dispatchEvent(new window.CustomEvent('seoprostack:lists', { detail: {
			user: state.user,
			liked: state.liked.slice(),
			saved: postsOf('saved').slice(),
			shortlist: postsOf('shortlist').slice(),
			lists: snapshot().lists
		} }));
	}

	function refreshCounts() {
		if (!pageIds.length) {
			return;
		}
		send('state', { posts: pageIds.join(',') }).then(function (res) {
			Object.keys(res.counts || {}).forEach(function (id) {
				showCount(id, res.counts[id]);
			});
		}, noop);
	}

	/* ----- A list's posts ----- */

	function draw(box, which, items) {
		var holder = box.closest('[data-sps-lists]');
		var picked = holder && holder.spsSelected ? holder.spsSelected : [];
		box.textContent = '';
		if (!items.length) {
			box.appendChild(el('p', 'sps-saved__empty', box.getAttribute('data-empty') || (which === 'saved' ? t.none : t.emptyList)));
			if (holder) {
				syncSelectAll(holder);
			}
			return;
		}
		var list = el('ul', 'sps-saved__list');
		items.forEach(function (item) {
			var li = el('li', 'sps-saved__item');
			if (box.getAttribute('data-select') === '1') {
				var check = el('input', 'sps-saved__select');
				check.type = 'checkbox';
				check.setAttribute('data-sps-select', item.id);
				check.setAttribute('data-sps-key', 'select-' + item.id);
				check.setAttribute('aria-label', item.title);
				check.checked = has(picked, Number(item.id));
				li.appendChild(check);
			}
			if (item.image && safeUrl(item.image)) {
				var image = el('img');
				image.src = safeUrl(item.image);
				image.alt = '';
				image.loading = 'lazy';
				image.width = 64;
				image.height = 64;
				li.appendChild(image);
			}
			var a = el('a', 'sps-saved__link', item.title);
			a.href = safeUrl(item.url);
			li.appendChild(a);
			(item.meta || []).slice(0, 3).forEach(function (line) {
				li.appendChild(el('p', 'sps-saved__excerpt', line));
			});
			if (box.getAttribute('data-excerpts') === '1' && item.excerpt) {
				li.appendChild(el('p', 'sps-saved__excerpt', item.excerpt));
			}
			if (box.getAttribute('data-buttons') === '1') {
				li.appendChild(button('sps-saved__remove', t.remove, { 'data-sps-remove': item.id }));
			}
			list.appendChild(li);
		});
		box.appendChild(list);
		if (holder) {
			syncSelectAll(holder);
		}
	}

	// Titles and links come from the server once per post, then from memory.
	function fillList(box) {
		var which = box.getAttribute('data-list') || 'saved';
		var types = box.getAttribute('data-types') || '';
		var posts = postsOf(which).slice().reverse();
		var missing = posts.filter(function (id) {
			return !Object.prototype.hasOwnProperty.call(cache, types + ':' + id);
		});
		var revision = (box.spsRevision || 0) + 1;
		box.spsRevision = revision;
		// Posts the server does not return (deleted, private) are remembered as gone.
		function remember(res) {
			missing.forEach(function (id) {
				cache[types + ':' + id] = null;
			});
			(res.items || []).forEach(function (item) {
				cache[types + ':' + Number(item.id)] = item;
			});
		}
		var request = Promise.resolve();
		if (missing.length) {
			request = send('list', { which: which, types: types, posts: missing.join(',') }).then(remember);
		}
		return request.then(function () {
			if (box.spsRevision !== revision) {
				return;
			}
			draw(box, which, posts.map(function (id) {
				return cache[types + ':' + id];
			}).filter(Boolean));
		}, function () {
			if (box.spsRevision === revision) {
				box.textContent = t.failed;
			}
		});
	}

	/* ----- Your lists ----- */

	function initBox(box) {
		box.spsId = 'sps-lists-' + (++serial);
		box.spsActive = LIKED;
		box.spsSelected = [];
		box.spsNaming = '';
		box.spsNameValue = '';
		box.spsMoveTo = '';
		box.spsMenu = false;
		// Actions other plugins add (seoprostack_lists_actions, such as
		// "Message selected"): one node, kept while the box redraws, shown
		// in the toolbar before More actions while the open list has posts.
		box.spsPrimary = box.querySelector('[data-sps-lists-actions]');
		// Taken out of the server markup at once: toolbar() puts it back only
		// while the open list has posts, so an empty first list shows none.
		if (box.spsPrimary && box.spsPrimary.parentNode) {
			box.spsPrimary.parentNode.removeChild(box.spsPrimary);
		}
	}

	function trimSelection(box) {
		if (!box.spsId) {
			return;
		}
		if (!listInfo(box.spsActive)) {
			box.spsActive = LIKED;
			box.spsSelected = [];
			box.spsNaming = '';
		}
		var posts = postsOf(box.spsActive);
		box.spsSelected = box.spsSelected.filter(function (id) {
			return has(posts, id);
		});
	}

	function visitorNote() {
		var note = el('p', 'sps-lists__note');
		var login = cfg.loginUrl ? el('a', 'sps-lists__link', t.logIn) : null;
		var register = cfg.registerUrl ? el('a', 'sps-lists__link', t.register) : null;
		if (login) {
			try {
				var url = new window.URL(cfg.loginUrl, window.location.href);
				if (!url.searchParams.has('redirect_to')) {
					url.searchParams.set('redirect_to', window.location.href);
				}
				login.href = safeUrl(url.href);
			} catch (e) {
				login.href = safeUrl(cfg.loginUrl);
			}
		}
		if (register) {
			register.href = safeUrl(cfg.registerUrl);
		}
		if (login && register) {
			note.appendChild(compose(t.visitorNote, [login, register]));
		} else if (login) {
			note.appendChild(compose(t.visitorNoteLogin, [login]));
		} else {
			note.textContent = t.visitorOnly;
		}
		return note;
	}

	function nameForm(box) {
		var renaming = box.spsNaming === 'rename';
		var form = el('form', 'sps-lists__name');
		form.setAttribute('data-sps-name-form', box.spsNaming);
		var id = box.spsId + '-name';
		var caption = el('label', '', renaming ? t.renameList : t.listName);
		caption.htmlFor = id;
		var input = el('input');
		input.type = 'text';
		input.id = id;
		input.name = 'name';
		input.maxLength = NAME_MAX;
		input.required = true;
		input.autocomplete = 'off';
		input.value = box.spsNameValue;
		input.setAttribute('data-sps-key', 'name-input');
		var submit = button('', renaming ? t.saveName : t.create, { 'data-sps-key': 'name-submit' });
		submit.type = 'submit';
		var row = el('span', 'sps-lists__row');
		row.append(input, submit, button('', t.cancel, { 'data-sps-name-cancel': '', 'data-sps-key': 'name-cancel' }));
		form.append(caption, row);
		return form;
	}

	// The toolbar: Select all, then the actions other plugins add, then More
	// actions, a disclosure holding Move, Remove, Rename and Delete, so one
	// main action stays in view.
	function toolbar(box, list) {
		var bar = el('div', 'sps-lists__toolbar');
		var menuItems = [];
		if (list.posts.length) {
			var allLabel = el('label', 'sps-lists__all');
			var allBox = el('input');
			allBox.type = 'checkbox';
			allBox.setAttribute('data-sps-select-all', '');
			allBox.setAttribute('data-sps-key', 'all');
			allLabel.append(allBox, document.createTextNode(' ' + t.selectAll));
			bar.appendChild(allLabel);
			var others = allLists().filter(function (other) {
				return other.id !== list.id;
			});
			if (others.length) {
				var move = el('span', 'sps-lists__row');
				var moveId = box.spsId + '-to';
				var caption = el('label', '', t.moveTo);
				caption.htmlFor = moveId;
				var select = el('select', 'sps-lists__to');
				select.id = moveId;
				select.setAttribute('data-sps-move-to', '');
				select.setAttribute('data-sps-key', 'to');
				others.forEach(function (other) {
					var option = el('option', '', other.name);
					option.value = other.id;
					select.appendChild(option);
				});
				if (find(box.spsMoveTo) || box.spsMoveTo === LIKED) {
					select.value = box.spsMoveTo;
				}
				var pick = el('span', 'sps-lists__pick');
				pick.append(select, button('', t.move, { 'data-sps-move': '', 'data-sps-key': 'move' }));
				move.append(caption, pick);
				menuItems.push(move);
			}
			menuItems.push(button('sps-lists__menu-item', t.removeSelected, { 'data-sps-remove-selected': '', 'data-sps-key': 'remove' }));
		}
		if (list.id !== LIKED) {
			menuItems.push(
				button('sps-lists__menu-item', t.renameList, { 'data-sps-rename': '', 'data-sps-key': 'rename', 'aria-expanded': String(box.spsNaming === 'rename') }),
				button('sps-lists__menu-item', t.deleteList, { 'data-sps-delete': '', 'data-sps-key': 'delete' })
			);
		}
		var end = el('span', 'sps-lists__end');
		if (box.spsPrimary && list.posts.length) {
			end.appendChild(box.spsPrimary);
		}
		if (menuItems.length) {
			var menuId = box.spsId + '-menu';
			var more = button('sps-lists__more', '', {
				'aria-expanded': String(box.spsMenu),
				'aria-controls': menuId,
				'aria-label': t.moreActions,
				title: t.moreActions,
				'data-sps-more': '',
				'data-sps-key': 'more'
			});
			var dots = el('span', '', '\u22EF');
			dots.setAttribute('aria-hidden', 'true');
			more.appendChild(dots);
			var menu = el('div', 'sps-lists__menu');
			menu.id = menuId;
			menu.hidden = !box.spsMenu;
			menu.setAttribute('data-sps-menu', '');
			menuItems.forEach(function (item) {
				menu.appendChild(item);
			});
			end.append(more, menu);
		}
		if (end.childNodes.length) {
			bar.appendChild(end);
		}
		return bar;
	}

	// Open or close a box's More actions in place (no redraw, so a click
	// that closes it still reaches its target); focus moves into it, or
	// back to its button.
	function setMenu(box, open, focus) {
		box.spsMenu = open;
		var menu = box.querySelector('[data-sps-menu]');
		var more = box.querySelector('[data-sps-more]');
		if (menu) {
			menu.hidden = !open;
		}
		if (more) {
			more.setAttribute('aria-expanded', String(open));
		}
		if (focus) {
			focusIn(box, open ? '[data-sps-menu] select, [data-sps-menu] button' : '[data-sps-more]');
		}
	}

	function renderBox(box) {
		var ui = box.querySelector('[data-sps-lists-ui]');
		if (!ui || !box.spsId) {
			return;
		}
		var active = box.spsActive;
		var list = listInfo(active);
		var base = box.spsId;
		var old = ui.querySelector('[data-sps-saved]');
		var items = old && old.getAttribute('data-list') === active ? old : null;
		rebuild(ui, function () {
			var bar = el('div', 'sps-lists__bar');
			var tabs = el('div', 'sps-lists__tabs');
			tabs.setAttribute('role', 'tablist');
			tabs.setAttribute('aria-label', t.yourLists);
			allLists().forEach(function (each) {
				var on = each.id === active;
				var tab = button('sps-lists__tab', each.name, {
					role: 'tab',
					id: base + '-tab-' + each.id,
					'aria-controls': base + '-panel',
					'aria-selected': String(on),
					tabindex: on ? '0' : '-1',
					'data-sps-tab': each.id,
					'data-sps-key': 'tab-' + each.id
				});
				tab.appendChild(el('span', 'sps-lists__count', format(each.posts.length)));
				tabs.appendChild(tab);
			});
			bar.appendChild(tabs);
			if (state.lists.length < MAX_LISTS) {
				bar.appendChild(button('sps-lists__new', '+ ' + t.newList, { 'data-sps-new-list': '', 'data-sps-key': 'new', 'aria-expanded': String(box.spsNaming === 'new') }));
			}
			ui.appendChild(bar);
			if (box.spsNaming) {
				ui.appendChild(nameForm(box));
			}
			var panel = el('div', 'sps-lists__panel');
			panel.setAttribute('role', 'tabpanel');
			panel.id = base + '-panel';
			panel.setAttribute('aria-labelledby', base + '-tab-' + active);
			panel.tabIndex = 0;
			var tools = toolbar(box, list);
			if (tools.childNodes.length) {
				panel.appendChild(tools);
			}
			if (!items) {
				items = el('div', 'sps-saved');
				items.setAttribute('data-sps-saved', '');
				items.setAttribute('data-list', active);
				items.setAttribute('data-select', '1');
				items.setAttribute('data-buttons', '0');
			}
			panel.appendChild(items);
			ui.appendChild(panel);
			if (!state.user) {
				ui.appendChild(visitorNote());
			}
		});
		if (box.spsPrimary) {
			box.spsPrimary.setAttribute('data-list', active);
		}
		fillList(items);
	}

	function syncSelectAll(box) {
		var allBox = box.querySelector('[data-sps-select-all]');
		if (!allBox) {
			return;
		}
		var checks = all('[data-sps-select]', box);
		var ticked = checks.filter(function (check) {
			return check.checked;
		}).length;
		allBox.checked = ticked > 0 && ticked === checks.length;
		allBox.indeterminate = ticked > 0 && ticked < checks.length;
	}

	function focusIn(box, selector) {
		var node = box.querySelector(selector);
		if (node) {
			node.focus();
		}
	}

	function selectTab(box, id) {
		box.spsActive = id;
		box.spsSelected = [];
		box.spsNaming = '';
		renderBox(box);
		focusIn(box, '[data-sps-tab="' + id + '"]');
	}

	function naming(box, mode) {
		var list = listInfo(box.spsActive);
		box.spsNaming = mode;
		box.spsNameValue = mode === 'rename' && list ? list.name : '';
		renderBox(box);
		if (mode) {
			focusIn(box, '[data-sps-name-form] input');
		}
	}

	function nameSubmitted(form) {
		var box = form.closest('[data-sps-lists]');
		var input = form.querySelector('input');
		var name = cleanName(input.value);
		if (!box || !name) {
			input.focus();
			return;
		}
		var mode = box.spsNaming;
		(mode === 'rename' ? rename(box.spsActive, name, box) : create(name, 0, box)).then(function (id) {
			box.spsNaming = '';
			box.spsNameValue = '';
			if (mode !== 'rename' && id) {
				box.spsActive = id;
				box.spsSelected = [];
			}
			renderBox(box);
			focusIn(box, '[data-sps-tab="' + box.spsActive + '"]');
		}).catch(noop);
	}

	// Move selected to, or Remove selected.
	function bulkClicked(box, moving) {
		var select = box.querySelector('[data-sps-move-to]');
		var to = moving && select ? select.value : '';
		setMenu(box, false, true);
		if (!box.spsSelected.length) {
			say(box, t.selectFirst);
			return;
		}
		bulk(moving ? 'move' : 'remove', box.spsActive, box.spsSelected.slice(), to, box).then(function (n) {
			say(box, (moving ? t.moved : t.removed).replace('%d', format(n)));
		}).catch(noop);
	}

	// Delete list: never Liked, and only once confirmed.
	function deleteClicked(box) {
		setMenu(box, false, true);
		var list = listInfo(box.spsActive);
		if (!list || list.id === LIKED || !window.confirm(t.confirmDelete.replace('%s', list.name))) { // eslint-disable-line no-alert
			return;
		}
		deleteList(list.id, box).then(function () {
			selectTab(box, LIKED);
		}).catch(noop);
	}

	function boxClick(box, target) {
		if (target.hasAttribute('data-sps-more')) {
			setMenu(box, !box.spsMenu, true);
		} else if (target.hasAttribute('data-sps-tab')) {
			box.spsMenu = false;
			selectTab(box, target.getAttribute('data-sps-tab'));
		} else if (target.hasAttribute('data-sps-new-list') || target.hasAttribute('data-sps-rename')) {
			var mode = target.hasAttribute('data-sps-rename') ? 'rename' : 'new';
			box.spsMenu = false;
			naming(box, box.spsNaming === mode ? '' : mode);
		} else if (target.hasAttribute('data-sps-name-cancel')) {
			var was = box.spsNaming;
			naming(box, '');
			// Rename is in More actions, which closed when it opened the form.
			focusIn(box, was === 'rename' ? '[data-sps-more]' : '[data-sps-new-list]');
		} else if (target.hasAttribute('data-sps-move') || target.hasAttribute('data-sps-remove-selected')) {
			bulkClicked(box, target.hasAttribute('data-sps-move'));
		} else if (target.hasAttribute('data-sps-delete')) {
			deleteClicked(box);
		}
	}

	/* ----- Save to list ----- */

	function chooserDialog() {
		if (chooser) {
			return chooser;
		}
		var dialog = el('dialog', 'sps-chooser');
		dialog.setAttribute('aria-labelledby', 'sps-chooser-title');
		var title = el('h2', 'sps-chooser__title', t.saveTo);
		title.id = 'sps-chooser-title';
		var post = el('p', 'sps-chooser__post');
		post.setAttribute('data-sps-chooser-post', '');
		var lists = el('div', 'sps-chooser__body');
		lists.setAttribute('data-sps-chooser-lists', '');
		var form = el('form', 'sps-chooser__new');
		form.setAttribute('data-sps-chooser-new', '');
		var caption = el('label', '', t.newList);
		caption.htmlFor = 'sps-chooser-name';
		var input = el('input');
		input.type = 'text';
		input.id = 'sps-chooser-name';
		input.name = 'name';
		input.maxLength = NAME_MAX;
		input.required = true;
		input.autocomplete = 'off';
		var submit = button('', t.create);
		submit.type = 'submit';
		var row = el('span', 'sps-lists__row');
		row.append(input, submit);
		form.append(caption, row);
		var note = el('div', 'sps-chooser__note');
		note.setAttribute('data-sps-chooser-note', '');
		var status = el('p', 'sps-reactions__status');
		status.setAttribute('role', 'status');
		status.setAttribute('aria-live', 'polite');
		dialog.append(title, post, lists, form, note, status, button('sps-chooser__done', t.done, { 'data-sps-chooser-close': '' }));
		dialog.addEventListener('close', function () {
			if (dialog.spsTrigger && document.contains(dialog.spsTrigger)) {
				dialog.spsTrigger.focus();
			}
			dialog.spsPost = 0;
		});
		document.body.appendChild(dialog);
		chooser = dialog;
		return dialog;
	}

	function renderChooser() {
		if (!chooser || !chooser.open) {
			return;
		}
		var postId = chooser.spsPost;
		rebuild(chooser.querySelector('[data-sps-chooser-lists]'), function (body) {
			var group = el('ul', 'sps-chooser__lists');
			allLists().forEach(function (list) {
				var item = el('li');
				var caption = el('label', 'sps-chooser__list');
				var input = el('input');
				input.type = 'checkbox';
				input.checked = has(list.posts, postId);
				input.setAttribute('data-sps-choose', list.id);
				input.setAttribute('data-sps-key', 'choose-' + list.id);
				caption.append(input, el('span', 'sps-chooser__name', list.name), el('span', 'sps-lists__count', format(list.posts.length)));
				item.appendChild(caption);
				group.appendChild(item);
			});
			body.appendChild(group);
		});
		var full = state.lists.length >= MAX_LISTS;
		chooser.querySelector('[data-sps-chooser-new]').hidden = full;
		var note = chooser.querySelector('[data-sps-chooser-note]');
		note.textContent = '';
		if (full) {
			note.appendChild(el('p', 'sps-lists__note', tooManyText()));
		}
		if (!state.user) {
			note.appendChild(visitorNote());
		}
	}

	function openChooser(postId, trigger) {
		var dialog = chooserDialog();
		var title = trigger ? trigger.getAttribute('data-sps-title') : '';
		var post = dialog.querySelector('[data-sps-chooser-post]');
		dialog.spsPost = postId;
		dialog.spsTrigger = trigger;
		post.textContent = title || '';
		post.hidden = !title;
		dialog.querySelector('[data-sps-chooser-new] input').value = '';
		if (!dialog.open) {
			dialog.showModal();
		}
		renderChooser();
		focusIn(dialog, '[data-sps-choose]');
	}

	// Save and "Add to shortlist": into Liked when not kept yet, then choose lists.
	function saveClicked(postId, trigger, where) {
		if (!inAny(postId)) {
			setOn(LIKED, postId, true, where).catch(noop);
		} else if (!canDialog) {
			setOn(LIKED, postId, undefined, where).catch(noop);
		}
		if (canDialog) {
			openChooser(postId, trigger);
		}
	}

	function chooserCreate(form) {
		var input = form.querySelector('input');
		var name = cleanName(input.value);
		var postId = chooser ? chooser.spsPost : 0;
		if (!name || !postId) {
			input.focus();
			return;
		}
		create(name, postId, chooser).then(function (id) {
			input.value = '';
			say(chooser, t.savedTo.replace('%s', name));
			focusIn(chooser, '[data-sps-choose="' + id + '"]');
		}).catch(noop);
	}

	/* ----- Sharing ----- */

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

	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) {
			// Refused (no permission, page not focused): try the older way.
			return navigator.clipboard.writeText(text).catch(function () {
				return copyOld(text);
			});
		}
		return copyOld(text);
	}

	function share(node, group) {
		var data = { title: node.getAttribute('data-title') || document.title, url: node.getAttribute('data-url') || window.location.href };
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

	/* ----- Events ----- */

	function closeOnBackdrop(dialog, event) {
		var box = dialog.getBoundingClientRect();
		if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
			dialog.close();
		}
	}

	// The lists box used last: its selection is the one other plugins act on.
	['click', 'focusin'].forEach(function (type) {
		document.addEventListener(type, function (event) {
			var box = event.target && event.target.closest ? event.target.closest('[data-sps-lists]') : null;
			if (box) {
				current = box;
			}
		}, true);
	});

	// Like, Save, Add to shortlist and Share. True when the button was one.
	function reactionClick(target) {
		var group = target.closest('[data-sps-reactions]');
		if (target.hasAttribute('data-sps-like') && group) {
			setOn(LIKED, Number(group.getAttribute('data-sps-reactions')), undefined, group).catch(noop);
		} else if (target.hasAttribute('data-sps-save') && group) {
			saveClicked(Number(group.getAttribute('data-sps-reactions')), target, group);
		} else if (target.hasAttribute('data-sps-shortlist')) {
			saveClicked(Number(target.getAttribute('data-sps-shortlist')), target, target.parentElement);
		} else if (target.hasAttribute('data-sps-share')) {
			share(target, group);
		} else {
			return false;
		}
		return true;
	}

	// A saved list's Remove and Clear, and the panel's open and close.
	function listClick(target) {
		if (target.hasAttribute('data-sps-remove')) {
			var holder = target.closest('[data-sps-saved]');
			setOn(holder.getAttribute('data-list') || 'saved', Number(target.getAttribute('data-sps-remove')), false, holder.parentElement).catch(noop);
		} else if (target.hasAttribute('data-sps-clear')) {
			clear(target.parentElement).catch(function () {
				target.textContent = t.failed;
			});
		} else if (target.hasAttribute('data-sps-panel-open')) {
			var dialog = target.parentElement.querySelector('[data-sps-panel]');
			if (dialog && dialog.showModal) {
				dialog.spsTrigger = target;
				dialog.showModal();
			}
		} else if (target.hasAttribute('data-sps-panel-close') || target.hasAttribute('data-sps-chooser-close')) {
			target.closest('dialog').close();
		} else {
			return false;
		}
		return true;
	}

	// A click anywhere outside an open More actions (and its button) closes it.
	function closeMenus(node) {
		all('[data-sps-lists]').forEach(function (box) {
			if (!box.spsMenu || !box.spsId) {
				return;
			}
			var keep = node && node.closest ? node.closest('[data-sps-menu], [data-sps-more]') : null;
			if (!keep || !box.contains(keep)) {
				setMenu(box, false, false);
			}
		});
	}

	document.addEventListener('click', function (event) {
		closeMenus(event.target);
		if (event.target && event.target.tagName === 'DIALOG' && (event.target.hasAttribute('data-sps-panel') || event.target === chooser)) {
			closeOnBackdrop(event.target, event);
			return;
		}
		var target = event.target && event.target.closest ? event.target.closest('button') : null;
		if (!target || reactionClick(target) || listClick(target)) {
			return;
		}
		var box = target.closest('[data-sps-lists]');
		if (box) {
			boxClick(box, target);
		}
	});

	document.addEventListener('keydown', function (event) {
		var tab = event.target;
		// Escape closes More actions, back on its button; inside a dialog
		// it does not also close the dialog.
		var menuBox = event.key === 'Escape' && tab && tab.closest ? tab.closest('[data-sps-lists]') : null;
		if (menuBox && menuBox.spsMenu) {
			event.preventDefault();
			setMenu(menuBox, false, true);
			return;
		}
		if (!tab.getAttribute || tab.getAttribute('role') !== 'tab' || !tab.hasAttribute('data-sps-tab')) {
			return;
		}
		var box = tab.closest('[data-sps-lists]');
		var tabs = all('[data-sps-tab]', tab.parentElement);
		var index = tabs.indexOf(tab);
		if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
			index = (index + 1) % tabs.length;
		} else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
			index = (index + tabs.length - 1) % tabs.length;
		} else if (event.key === 'Home') {
			index = 0;
		} else if (event.key === 'End') {
			index = tabs.length - 1;
		} else {
			return;
		}
		event.preventDefault();
		selectTab(box, tabs[index].getAttribute('data-sps-tab'));
	});

	document.addEventListener('change', function (event) {
		var input = event.target;
		if (!input.hasAttribute) {
			return;
		}
		var box = input.closest('[data-sps-lists]');
		if (input.hasAttribute('data-sps-choose') && chooser && chooser.spsPost) {
			var listId = input.getAttribute('data-sps-choose');
			var list = listInfo(listId);
			var on = input.checked;
			setOn(listId, chooser.spsPost, on, chooser).then(function () {
				say(chooser, (on ? t.savedTo : t.removedFrom).replace('%s', list ? list.name : ''));
			}).catch(noop);
		} else if (box && input.hasAttribute('data-sps-select-all')) {
			var checks = all('[data-sps-select]', box);
			checks.forEach(function (check) {
				check.checked = input.checked;
			});
			box.spsSelected = input.checked ? checks.map(function (check) {
				return Number(check.getAttribute('data-sps-select'));
			}) : [];
		} else if (box && input.hasAttribute('data-sps-select')) {
			var id = Number(input.getAttribute('data-sps-select'));
			box.spsSelected = minus(box.spsSelected, [id]);
			if (input.checked && has(postsOf(box.spsActive), id)) {
				box.spsSelected.push(id);
			}
			syncSelectAll(box);
		} else if (box && input.hasAttribute('data-sps-move-to')) {
			box.spsMoveTo = input.value;
		}
	});

	document.addEventListener('input', function (event) {
		var form = event.target && event.target.closest ? event.target.closest('[data-sps-name-form]') : null;
		var box = form ? form.closest('[data-sps-lists]') : null;
		if (box) {
			box.spsNameValue = event.target.value;
		}
	});

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (form.hasAttribute('data-sps-name-form')) {
			event.preventDefault();
			nameSubmitted(form);
		} else if (form.hasAttribute('data-sps-chooser-new')) {
			event.preventDefault();
			chooserCreate(form);
		}
	});

	all('[data-sps-panel]').forEach(function (dialog) {
		dialog.addEventListener('close', function () {
			if (dialog.spsTrigger) {
				dialog.spsTrigger.focus();
			}
		});
	});

	cfg.api = {
		// A list's post IDs: liked, saved, shortlist or a named list's ID.
		get: function (list) {
			return postsOf(String(list)).slice();
		},
		// Every list: [{id, name, posts}], Liked first.
		lists: function () {
			return [{ id: LIKED, name: t.likedList, posts: state.liked.slice() }].concat(snapshot().lists);
		},
		toggle: function (list, id, on) {
			return setOn(String(list), id, on);
		},
		// The ticked posts in a lists box (this one, or the one used last).
		selected: function (root) {
			var box = root && root.closest ? (root.closest('[data-sps-lists]') || root.querySelector('[data-sps-lists]')) : null;
			box = box || current || document.querySelector('[data-sps-lists]');
			return box && box.spsSelected ? box.spsSelected.slice() : [];
		},
		// The list a lists box shows.
		active: function (root) {
			var box = root && root.closest ? (root.closest('[data-sps-lists]') || root.querySelector('[data-sps-lists]')) : null;
			box = box || current || document.querySelector('[data-sps-lists]');
			return box && box.spsActive ? box.spsActive : LIKED;
		}
	};

	/* ----- Start ----- */

	var pageIds = [];
	all('[data-sps-reactions], [data-sps-total]').forEach(function (node) {
		var id = Number(node.getAttribute('data-sps-reactions') || node.getAttribute('data-sps-total'));
		if (id && !has(pageIds, id)) {
			pageIds.push(id);
		}
	});
	if (canDialog) {
		all('[data-sps-save], [data-sps-shortlist]').forEach(function (node) {
			node.setAttribute('aria-haspopup', 'dialog');
		});
	}
	all('[data-sps-lists]').forEach(initBox);

	var local = readLocal();
	state.liked = local.liked;
	state.lists = local.lists;
	showAll();

	function loadState() {
		return send('state', { posts: pageIds.join(',') }).then(function (res) {
			Object.keys(res.counts || {}).forEach(function (id) {
				showCount(id, res.counts[id]);
			});
			var stored = readLocal();
			if (!res.user) {
				state.user = false;
				state.nonce = '';
				state.liked = stored.liked;
				state.lists = stored.lists;
				return null;
			}
			state.user = true;
			state.nonce = res.nonce;
			apply(res);
			if (!stored.liked.length && !stored.lists.length) {
				return null;
			}
			// Lists kept in this browser before logging in join the account;
			// Liked joins without counting the likes again.
			return send('merge', { lists: JSON.stringify(stored) }).then(function (merged) {
				apply(merged);
				clearLocal();
				write(KEY_SYNC, String(Date.now()));
			}, function () {
				say(document.querySelector('[data-sps-lists]'), t.failed);
			});
		}, noop).then(function () {
			ready = true;
			changed();
		});
	}

	queue = loadState();
	window.addEventListener('storage', function (event) {
		if (event.key !== null && [KEY_LIKED, KEY_LISTS, KEY_SYNC, LEGACY.saved.key, LEGACY.shortlist.key].indexOf(event.key) === -1) {
			return;
		}
		queue = queue.then(loadState);
	});
}(window.seoprostackReactions));
