/**
 * SEO Pro Stack admin screen.
 *
 * One delegated controller per concern:
 * - Settings: instant save for [data-sps-setting] controls (network values
 *   on the network screen, multisite).
 * - Network: Apply to every site and Make every site the same (network
 *   screen only).
 * - Panels: expandable setting options.
 * - Tokens: insert pattern tokens into text fields.
 * - Media fields: choose a Media Library picture with the media dialog.
 * - Tabs: switch between the tabs drawn on the page without a reload.
 *
 * The plugin's own tabs bring their own script (seoprostack_admin_enqueue),
 * which can use window.seoprostackAdmin.api.
 *
 * Localized data: window.seoprostackAdmin (see SEOProStack_Admin_Manager::enqueue_assets()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-e4502af0f07e8a92. Copies, even of part, keep these lines.
 */
(function ($, wp, cfg) {
	'use strict';

	if (!cfg) {
		return;
	}

	var i18n = cfg.i18n || {};

	function speak(message, politeness) {
		if (wp && wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(message, politeness || 'polite');
		}
	}

	// Some plugins redirect the first admin request after activation to a
	// welcome screen, and admin-ajax.php runs admin_init too. The redirect
	// happens once, so a reply that is a page instead of JSON is retried
	// once. Every action here is safe to repeat.
	function post(action, data) {
		var deferred = $.Deferred();
		var current;
		var send = function (retry) {
			current = $.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: $.extend({ action: action, nonce: cfg.nonce }, data)
			})
				.done(deferred.resolve)
				.fail(function (xhr, status, error) {
					if (status === 'parsererror' && retry) {
						send(false);
					} else {
						deferred.reject(xhr, status, error);
					}
				});
		};
		send(true);

		var promise = deferred.promise();
		promise.abort = function () {
			current.abort();
		};
		return promise;
	}

	function errorMessage(xhr, fallback) {
		var json = xhr && xhr.responseJSON;
		if (json && json.data && json.data.message) {
			return json.data.message;
		}
		return fallback;
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	var Settings = {
		pending: {},
		sequence: 0,
		// Saves run one at a time so each reads the settings the last one wrote.
		queue: $.Deferred().resolve().promise(),

		init: function () {
			$(document).on('change', '[data-sps-setting]', function () {
				Settings.save($(this));
			});
			// "Select all" and "Clear" for long checkbox lists: one save for the lot.
			$(document).on('click', '[data-sps-check-all], [data-sps-check-none]', function () {
				var all = this.hasAttribute('data-sps-check-all');
				// By id, so the attribute is never read as a selector.
				var group = document.getElementById(this.getAttribute(all ? 'data-sps-check-all' : 'data-sps-check-none'));
				if (!group) {
					return;
				}
				// Locked choices (disabled) stay as they are.
				$(group).find(':checkbox').not(':disabled').prop('checked', all);
				Settings.save($(group));
			});
			this.ordered();
			// Forms that change data elsewhere ask first.
			$(document).on('submit', 'form[data-sps-confirm]', function (event) {
				if (!window.confirm($(this).attr('data-sps-confirm'))) {
					event.preventDefault();
				}
			});
		},

		// Ordered checkbox lists (the `ordered` setting key): drag a row by
		// its handle, or focus the handle and press the up or down arrow
		// key. The checked boxes, top to bottom, are saved as the order.
		ordered: function () {
			if ($.fn.sortable) {
				$('[data-sps-ordered]').sortable({
					items: '> .sps-checkbox-row',
					handle: '.sps-checkbox__handle',
					// The handle is a button (for the keyboard); jQuery UI skips buttons by default.
					cancel: 'input, textarea, select, option, a',
					axis: 'y',
					cursor: 'grabbing',
					update: function () {
						Settings.save($(this));
					}
				});
			}
			$(document).on('keydown', '[data-sps-ordered] .sps-checkbox__handle', function (event) {
				var up = event.key === 'ArrowUp';
				if (!up && event.key !== 'ArrowDown') {
					return;
				}
				event.preventDefault();
				var $row = $(this).closest('.sps-checkbox-row');
				// The nearest movable row, past any locked choice between.
				var $to = (up ? $row.prevAll('.sps-checkbox-row') : $row.nextAll('.sps-checkbox-row')).first();
				if (!$to.length) {
					return;
				}
				if (up) {
					$row.insertBefore($to);
				} else {
					$row.insertAfter($to);
				}
				$(this).trigger('focus');
				Settings.save($row.closest('[data-sps-ordered]'));
			});
		},

		valueOf: function ($input) {
			if ($input.is('[data-sps-multi]')) {
				// Locked choices are always chosen and never stored.
				return $input.find(':checkbox:checked').not('[data-sps-locked]').map(function () {
					return this.value;
				}).get();
			}
			if ($input.is(':checkbox')) {
				return $input.is(':checked') ? '1' : '0';
			}
			return $input.val();
		},

		status: function (key, state, text, keep) {
			// Compare attribute values, never interpret a setting key as a selector.
			var $status = $('[data-sps-status]').filter(function () {
				return this.getAttribute('data-sps-status') === String(key);
			});
			$status.removeClass('is-saving is-saved is-error').addClass(state ? 'is-' + state : '').text(text || '');
			clearTimeout($status.data('timer'));
			// "Reload the page" stays until the next change.
			if (state === 'saved' && !keep) {
				$status.data('timer', setTimeout(function () {
					$status.removeClass('is-saved').text('');
				}, 2000));
			}
		},

		save: function ($input) {
			var key = $input.data('sps-setting');
			var value = this.valueOf($input);
			var previous = $input.data('sps-saved');
			var seq = ++this.sequence;

			if (previous === undefined) {
				previous = $input.is(':checkbox') ? ($input.is(':checked') ? '0' : '1') : value;
			}

			this.pending[key] = seq;
			this.status(key, 'saving', i18n.saving);

			var done = function (response) {
				if (Settings.pending[key] !== seq) {
					return; // A newer save for this key is queued.
				}
				if (!response || !response.success) {
					Settings.fail($input, key, previous, i18n.saveFailed);
					return;
				}
				var saved = response.data.value;
				if ($input.is('[data-sps-multi]')) {
					var chosen = $.map(saved || [], String);
					$input.find(':checkbox').each(function () {
						this.checked = this.hasAttribute('data-sps-locked') || $.inArray(this.value, chosen) !== -1;
					});
				} else if ($input.is(':checkbox')) {
					$input.data('sps-saved', saved ? '1' : '0');
				} else if (saved !== undefined && String(saved) !== String($input.val())) {
					$input.val(saved); // Show the sanitized value.
				}
				if (!$input.is(':checkbox')) {
					$input.data('sps-saved', $input.val());
				}
				var reload = !!response.data.reload;
				var message = reload && response.data.message ? response.data.message : i18n.saved;
				Settings.status(key, 'saved', message, reload);
				speak(message);
				// Network screen: the sites that now differ.
				if (response.data.card) {
					Network.sites(response.data.card, response.data.sites);
				}
				$(document).trigger('seoprostack:setting-saved', [key, saved]);
			};
			var failed = function (xhr) {
				if (Settings.pending[key] === seq) {
					Settings.fail($input, key, previous, errorMessage(xhr, i18n.saveFailed));
				}
			};
			var run = function () {
				return post(cfg.network ? 'seoprostack_network_save' : 'seoprostack_save_setting', { key: key, value: value })
					.done(done)
					.fail(failed);
			};

			// A failed save must not block the ones after it.
			this.queue = this.queue.then(run, run);

			if ($input.is(':checkbox')) {
				$input.closest('[data-setting-card]').filter(function () {
					return $(this).data('setting-card') === key;
				}).toggleClass('is-on', $input.is(':checked'));
			}
		},

		fail: function ($input, key, previous, message) {
			if ($input.is(':checkbox')) {
				var on = previous === '1' || previous === true;
				$input.prop('checked', on);
				$input.closest('[data-setting-card]').toggleClass('is-on', on);
			}
			this.status(key, 'error', message);
			speak(message, 'assertive');
		}
	};

	/* ------------------------------------------------------------------ */
	/* Network screen (multisite)                                          */
	/* ------------------------------------------------------------------ */

	// Each card's network part (SEOProStack_Network_Manager::render_card_network()):
	// Apply to every site, and the sites that differ with Make every site
	// the same. Its changes join the saves' queue, so they run one at a time.
	var Network = {
		init: function () {
			if (!cfg.network) {
				return;
			}
			$(document).on('change', '[data-sps-network-apply]', function () {
				Network.apply($(this));
			});
			$(document).on('click', '[data-sps-network-copy]', function () {
				if (window.confirm(i18n.copyConfirm)) {
					Network.copy($(this));
				}
			});
		},

		// A card's sites part, drawn again from a reply's lines of text and
		// links and its Make every site the same label
		// (SEOProStack_Network_Manager::sites()): built from text, never from
		// markup, and found by id, so a key is never read as a selector.
		sites: function (key, sites) {
			var box = document.getElementById('sps-' + key + '-network-sites');
			if (!box || !sites || !Array.isArray(sites.lines)) {
				return;
			}
			box.textContent = '';
			sites.lines.forEach(function (line) {
				var p = document.createElement('p');
				(Array.isArray(line) ? line : []).forEach(function (part) {
					p.appendChild(Network.part(part));
				});
				box.appendChild(p);
			});
			if (sites.copy) {
				var button = document.createElement('button');
				var row = document.createElement('p');
				button.type = 'button';
				button.className = 'button';
				button.setAttribute('data-sps-network-copy', key);
				button.textContent = String(sites.copy);
				row.appendChild(button);
				box.appendChild(row);
			}
		},

		// One part of a line: text, or a link to a site's settings screen.
		part: function (part) {
			var text = document.createTextNode(String(part && part.text !== undefined ? part.text : ''));
			if (!part || typeof part.url !== 'string' || !/^https?:\/\//i.test(part.url)) {
				return text;
			}
			var link = document.createElement('a');
			link.href = part.url;
			link.appendChild(text);
			return link;
		},

		run: function (key, action, data, done, failed) {
			var status = key + ':network';
			var fail = function (message) {
				failed();
				Settings.status(status, 'error', message);
				speak(message, 'assertive');
			};
			var send = function () {
				return post(action, $.extend({ key: key }, data))
					.done(function (response) {
						if (!response || !response.success) {
							fail(i18n.saveFailed);
							return;
						}
						done();
						Network.sites(key, response.data.sites);
						Settings.status(status, 'saved', response.data.message);
						speak(response.data.message);
					})
					.fail(function (xhr) {
						fail(errorMessage(xhr, i18n.saveFailed));
					});
			};
			Settings.status(status, 'saving', i18n.saving);
			Settings.queue = Settings.queue.then(send, send);
		},

		apply: function ($input) {
			var key = $input.attr('data-sps-network-apply');
			var on = $input.is(':checked');
			Network.run(key, 'seoprostack_network_apply', { apply: on ? '1' : '0' }, function () {
				$input.closest('[data-setting-card]').toggleClass('is-applied', on);
			}, function () {
				$input.prop('checked', !on);
			});
		},

		copy: function ($button) {
			var key = $button.attr('data-sps-network-copy');
			$button.prop('disabled', true);
			Network.run(key, 'seoprostack_network_copy', {}, $.noop, function () {
				$button.prop('disabled', false);
			});
		}
	};

	/* ------------------------------------------------------------------ */
	/* Expandable panels and tokens                                        */
	/* ------------------------------------------------------------------ */

	var Panels = {
		toggle: function ($button) {
			if (!$button.length) {
				return;
			}
			var expanded = $button.attr('aria-expanded') === 'true';
			var panel = document.getElementById($button.attr('aria-controls'));
			$button.attr('aria-expanded', expanded ? 'false' : 'true');
			if (panel) {
				panel.hidden = expanded;
			}
			$button.closest('.sps-setting').toggleClass('is-expanded', !expanded);
		},

		init: function () {
			$(document).on('click', '.sps-setting__expand', function (event) {
				event.stopPropagation();
				Panels.toggle($(this));
			});

			// Mouse convenience: clicking anywhere on the header opens/closes the
			// options. The switch (and any other control) keeps its own behaviour;
			// keyboard users use the Options button.
			$(document).on('click', '[data-sps-panel-toggle]', function (event) {
				// event.target is a DOM node, not HTML or a user-provided selector.
				if ($(event.target).closest('input, button, a, label, select, textarea, .sps-switch').length) {
					return;
				}
				if (window.getSelection && String(window.getSelection()).length) {
					return; // Let people select text.
				}
				Panels.toggle($(this).find('.sps-setting__expand').first());
			});

			$(document).on('click', '.sps-token', function () {
				var input = document.getElementById($(this).data('target'));
				var token = String($(this).data('token'));
				if (!input) {
					return;
				}
				var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
				var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : input.value.length;
				input.value = input.value.slice(0, start) + token + input.value.slice(end);
				input.focus();
				input.setSelectionRange(start + token.length, start + token.length);
				// getElementById returned a DOM element; jQuery does not parse a string here.
				$(input).trigger('change');
			});
		}
	};

	/* ------------------------------------------------------------------ */
	/* Media fields: choose a Media Library picture                        */
	/* ------------------------------------------------------------------ */

	var MediaField = {
		init: function () {
			$(document).on('click', '.sps-media__choose', function () {
				MediaField.open($(this).closest('[data-sps-media]'));
			});
			$(document).on('click', '.sps-media__remove', function () {
				MediaField.set($(this).closest('[data-sps-media]'), 0, '');
				$(this).siblings('.sps-media__choose').trigger('focus');
			});
			// A saved 0 means the choice was not a picture.
			$(document).on('seoprostack:setting-saved', function (event, key, value) {
				var $field = $('[data-sps-media]').has($('[data-sps-setting]').filter(function () {
					return this.getAttribute('data-sps-setting') === String(key);
				}));
				if ($field.length && !parseInt(value, 10)) {
					MediaField.preview($field, '');
				}
			});
		},

		open: function ($field) {
			if (!wp || !wp.media) {
				return;
			}
			var frame = $field.data('sps-frame');
			if (!frame) {
				frame = wp.media({
					title: i18n.chooseImage,
					library: { type: 'image' },
					multiple: false,
					button: { text: i18n.useImage }
				});
				frame.on('select', function () {
					var item = frame.state().get('selection').first();
					if (!item) {
						return;
					}
					var data = item.toJSON();
					var url = data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url;
					MediaField.set($field, data.id, url);
				});
				$field.data('sps-frame', frame);
			}
			frame.open();
		},

		set: function ($field, id, url) {
			this.preview($field, url);
			$field.find('[data-sps-setting]').val(String(id || 0)).trigger('change');
		},

		preview: function ($field, url) {
			var $img = $field.find('.sps-media__preview');
			if (url) {
				$img.attr('src', url).prop('hidden', false);
			} else {
				$img.removeAttr('src').prop('hidden', true);
			}
			$field.find('.sps-media__remove').prop('hidden', !url);
		}
	};

	/* ------------------------------------------------------------------ */
	/* Tabs: switch between the tabs drawn on the page                     */
	/* ------------------------------------------------------------------ */

	// The screen draws the active tab with the other tabs of its group
	// (SEOProStack_Admin_Manager::page_tabs()), each in a [data-sps-panel].
	// Their links carry data-sps-tab: choosing one shows its panel and puts
	// the tab's address in the location bar, so Back, reload and bookmarks
	// work as with page loads. Other links load their page.
	var Tabs = {
		init: function () {
			if ($('[data-sps-panel]').length === 0 || !window.history || !window.history.pushState) {
				return;
			}
			// Remember the tab of the first page, for Back.
			window.history.replaceState($.extend({}, window.history.state, { spsTab: cfg.tab }), '');

			$(document).on('click', 'a[data-sps-tab]', function (event) {
				var slug = this.getAttribute('data-sps-tab');
				// A new tab or window (modifier keys, middle click) loads the page.
				if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || Tabs.panel(slug).length === 0) {
					return;
				}
				event.preventDefault();
				if (slug !== cfg.tab) {
					// Address first, so seoprostack:tab-shown listeners read the new one.
					window.history.pushState({ spsTab: slug }, '', this.href);
					Tabs.show(slug);
				}
			});

			window.addEventListener('popstate', function (event) {
				var slug = event.state && event.state.spsTab;
				if (slug && slug !== cfg.tab && Tabs.panel(slug).length > 0) {
					Tabs.show(slug);
				}
			});
		},

		inMenu: function (el, slug) {
			return (el.getAttribute('data-sps-tabs') || '').split(' ').indexOf(slug) !== -1;
		},

		panel: function (slug) {
			return $('[data-sps-panel]').filter(function () {
				return this.getAttribute('data-sps-panel') === slug;
			});
		},

		show: function (slug) {
			$('[data-sps-panel]').each(function () {
				this.hidden = this.getAttribute('data-sps-panel') !== slug;
			});
			$('.sps-nav__tab, .sps-subnav__tab').each(function () {
				var current = this.getAttribute('data-sps-tab') === slug;
				// A menu's tab (data-sps-tabs) is current with any of its tabs.
				var menu = this.hasAttribute('data-sps-tabs');
				if (menu) {
					current = Tabs.inMenu(this, slug);
				}
				$(this).toggleClass('is-active', current);
				if (current) {
					this.setAttribute('aria-current', menu ? 'true' : 'page');
				} else {
					this.removeAttribute('aria-current');
				}
			});
			// The second row of tabs shows with its menu's tabs only.
			$('.sps-subnav').each(function () {
				this.hidden = !Tabs.inMenu(this, slug);
			});
			Tabs.title(cfg.tab, slug);
			cfg.tab = slug;
			// For the plugin's own scripts: the tab now shown.
			$(document).trigger('seoprostack:tab-shown', [slug]);
		},

		// The browser title names the tab shown, as a page load would
		// ("{Name}: General ‹ Site — WordPress"): swap one tab's name for
		// the other's and keep the rest.
		title: function (from, to) {
			var titles = cfg.titles || {};
			var current = document.title;
			if (!titles[from] || !titles[to] || current.indexOf(titles[from]) === -1) {
				return;
			}
			document.title = current.replace(titles[from], function () {
				return titles[to];
			});
		}
	};

	// For the plugin's own admin scripts, which load after this one
	// (seoprostack_admin_enqueue): the same AJAX, screen reader and error helpers.
	cfg.api = { post: post, speak: speak, errorMessage: errorMessage };

	$(function () {
		Settings.init();
		Network.init();
		Panels.init();
		MediaField.init();
		Tabs.init();
	});
})(jQuery, window.wp, window.seoprostackAdmin);
