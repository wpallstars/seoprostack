/**
 * SEO Pro Stack admin screen.
 *
 * One delegated controller per concern:
 * - Settings: instant save for [data-sps-setting] controls.
 * - Panels: expandable setting options.
 * - Tokens: insert pattern tokens into text fields.
 * - Media fields: choose a Media Library picture with the media dialog.
 * - Directories: client-side filter for Pro/Hosting/Tools cards.
 * - Plugins: AJAX category cards, the All list, in-place install, activate,
 *   deactivate and uninstall, and bulk actions.
 * - Theme: AJAX theme card and in-place install via wp.updates.
 *
 * Localized data: window.seoprostackAdmin (see SEOProStack_Admin_Manager::enqueue_assets()).
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
				var $group = $('#' + $(this).attr(all ? 'data-sps-check-all' : 'data-sps-check-none'));
				$group.find(':checkbox').prop('checked', all);
				Settings.save($group);
			});
		},

		valueOf: function ($input) {
			if ($input.is('[data-sps-multi]')) {
				return $input.find(':checkbox:checked').map(function () {
					return this.value;
				}).get();
			}
			if ($input.is(':checkbox')) {
				return $input.is(':checked') ? '1' : '0';
			}
			return $input.val();
		},

		status: function (key, state, text, keep) {
			var $status = $('[data-sps-status="' + key + '"]');
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

			var run = function () {
				return post('seoprostack_save_setting', { key: key, value: value })
					.done(done)
					.fail(failed);
			};
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
						this.checked = $.inArray(this.value, chosen) !== -1;
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
				$(document).trigger('seoprostack:setting-saved', [key, saved]);
			};
			var failed = function (xhr) {
				if (Settings.pending[key] === seq) {
					Settings.fail($input, key, previous, errorMessage(xhr, i18n.saveFailed));
				}
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
	/* Modern admin colours (live switch, no reload)                       */
	/* ------------------------------------------------------------------ */

	var Colors = {
		init: function () {
			$(document).on('seoprostack:setting-saved', function (event, key, value) {
				if (key === 'modern_admin_colors' && cfg.colorSchemes) {
					Colors.apply(value ? cfg.colorSchemes.enabled : cfg.colorSchemes.disabled);
				}
			});
		},

		apply: function (scheme) {
			if (!scheme) {
				return;
			}
			var $link = $('#colors-css');
			if (!scheme.url) {
				$link.remove();
			} else if ($link.length) {
				$link.attr('href', scheme.url);
			} else {
				$('<link rel="stylesheet" id="colors-css" media="all">').attr('href', scheme.url).appendTo('head');
			}
			document.body.className = document.body.className.replace(/\badmin-color-\S+/g, '').trim() + ' admin-color-' + scheme.name;
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
				var $field = $('[data-sps-media]').has('[data-sps-setting="' + key + '"]');
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
	/* Directory filter (Pro / Hosting / Tools)                            */
	/* ------------------------------------------------------------------ */

	var Directory = {
		init: function () {
			$(document).on('input', '[data-sps-filter]', function () {
				var query = String($(this).val() || '').trim().toLowerCase();
				var $dir = $(this).closest('[data-sps-directory]');
				var visible = 0;
				$dir.find('[data-sps-search]').each(function () {
					var match = !query || String($(this).data('sps-search')).indexOf(query) !== -1;
					this.hidden = !match;
					visible += match ? 1 : 0;
				});
				$dir.find('.sps-directory__empty').prop('hidden', visible > 0);
				$dir.find('.sps-directory__count').text(
					wp && wp.i18n ? wp.i18n.sprintf(wp.i18n._n('%d item', '%d items', visible, 'seoprostack'), visible) : visible
				);
			});
		}
	};

	/* ------------------------------------------------------------------ */
	/* Free plugins                                                        */
	/* ------------------------------------------------------------------ */

	var __ = wp && wp.i18n ? wp.i18n.__ : function (text) { return text; };
	var _n = wp && wp.i18n ? wp.i18n._n : function (single, plural, n) { return n === 1 ? single : plural; };
	var sprintf = wp && wp.i18n ? wp.i18n.sprintf : function (format) { return format; };

	var Plugins = {
		request: null,
		generation: 0,

		init: function () {
			var $list = $('[data-sps-plugin-list]');
			if (!$list.length) {
				return;
			}

			$('.sps-filter .filter-links').on('click', 'a[data-category]', function (event) {
				event.preventDefault();
				if (Bulk.running) {
					Bulk.progress(__('Wait for the bulk action to finish, or stop it.', 'seoprostack'));
					return;
				}
				Plugins.select($(this).data('category'), this.href, true);
			});

			window.addEventListener('popstate', function () {
				var match = /[?&]category=([^&#]+)/.exec(window.location.search);
				Plugins.select(match ? decodeURIComponent(match[1]) : 'minimal', null, false);
			});

			PluginActions.init();
			Bulk.init();
			this.show(String($list.data('category')));
		},

		select: function (category, href, push) {
			var $links = $('.sps-filter .filter-links a');
			$links.removeClass('current').removeAttr('aria-current');
			$links.filter('[data-category="' + category + '"]').addClass('current').attr('aria-current', 'page');
			if (push && href && window.history && window.history.pushState) {
				window.history.pushState({ category: category }, '', href);
			}
			this.show(category);
		},

		show: function (category) {
			var all = category === 'all';
			$('[data-sps-plugin-cards]').prop('hidden', all);
			$('[data-sps-plugin-table]').prop('hidden', !all);
			if (all) {
				if (this.request) {
					this.request.abort();
				}
				this.loadRows();
			} else {
				this.load(category);
			}
		},

		load: function (category) {
			var $list = $('[data-sps-plugin-list]');
			if (this.request) {
				this.request.abort();
			}
			$list.attr('aria-busy', 'true').html(
				'<div class="sps-loading"><span class="spinner is-active"></span></div>'
			);

			this.request = post('seoprostack_get_plugins', { category: category })
				.done(function (response) {
					if (response && response.success) {
						$list.html(response.data.html);
					} else {
						$list.html(Plugins.notice(i18n.loadFailed));
					}
				})
				.fail(function (xhr, status) {
					if (status !== 'abort') {
						$list.html(Plugins.notice(errorMessage(xhr, i18n.loadFailed)));
					}
				})
				.always(function () {
					$list.attr('aria-busy', 'false');
				});
		},

		// The All list: one request per category (each uses its card cache),
		// a few at a time, so groups fill in as they arrive.
		loadRows: function () {
			var generation = ++this.generation;
			var $groups = $('[data-sps-plugin-group]');
			var queue = $groups.get();
			var running = 0;

			$groups.find('tr.sps-plugin-row, tr.sps-plugin-error').remove();
			$groups.find('[data-sps-group-state]').html('<span class="spinner is-active"></span>');
			$groups.find('[data-sps-group-check]').prop({ checked: false, indeterminate: false, disabled: true });

			var next = function () {
				while (running < 4 && queue.length) {
					running++;
					Plugins.loadGroup($(queue.shift()), generation).always(function () {
						running--;
						next();
					});
				}
			};
			next();
		},

		loadGroup: function ($group, generation) {
			var $state = $group.find('[data-sps-group-state]');
			var failed = function (message) {
				$state.empty();
				$group.append($('<tr class="sps-plugin-error"><td colspan="4"></td></tr>').find('td').append(Plugins.notice(message)).end());
			};

			return post('seoprostack_get_plugins', { category: $group.data('sps-plugin-group'), view: 'rows' })
				.done(function (response) {
					if (generation !== Plugins.generation) {
						return;
					}
					if (!response || !response.success) {
						failed(i18n.loadFailed);
						return;
					}
					$group.append(response.data.html);
					var count = $group.find('tr.sps-plugin-row').length;
					$state.text(sprintf(_n('%d plugin', '%d plugins', count, 'seoprostack'), count));
					Bulk.refresh();
				})
				.fail(function (xhr) {
					if (generation === Plugins.generation) {
						failed(errorMessage(xhr, i18n.loadFailed));
					}
				});
		},

		notice: function (message) {
			return $('<div class="notice notice-error inline"><p></p></div>').find('p').text(message).end();
		}
	};

	/* ------------------------------------------------------------------ */
	/* Install, activate, deactivate and uninstall in place                */
	/* ------------------------------------------------------------------ */

	// Cards and list rows carry data-sps-plugin="slug" and the same state
	// buttons. Install and Uninstall use core's AJAX (wp.updates), which also
	// asks for FTP details where needed; Activate and Deactivate use ours.
	// Every change ends by applying the server's state for the plugin.
	var PluginActions = {
		busy: {},
		cancel: {},

		init: function () {
			$('.sps-plugins').on('click', '[data-sps-plugin-action]', function (event) {
				var $button = $(this);
				var slug = String($button.data('slug'));
				var action = String($button.data('sps-plugin-action'));
				event.preventDefault();
				if (Bulk.running || PluginActions.busy[slug]) {
					return;
				}
				if (action === 'uninstall' && !window.confirm(sprintf(
					/* translators: %s: plugin name */
					__('Uninstall %s? This deletes its files, and the plugin may delete its settings and data.', 'seoprostack'),
					PluginActions.name(slug)
				))) {
					return;
				}
				PluginActions.credentials(event, action);
				PluginActions.run(slug, action);
			});

			// Core's Update Now: show the updated state after its "Updated!".
			$(document).on('wp-plugin-update-success', function (event, response) {
				if (response && response.slug) {
					setTimeout(function () {
						PluginActions.refresh([response.slug]);
					}, 1500);
				}
			});

			// The details dialog can install or activate on its own.
			$(document.body).on('thickbox:removed', function () {
				PluginActions.refresh(PluginActions.visibleSlugs());
			});

			// FTP details dialog closed without details: nothing will run.
			$(document).on('credential-modal-cancel', function () {
				if (Bulk.running) {
					Bulk.stopped = true;
				}
				$.each($.extend({}, PluginActions.cancel), function (slug, fail) {
					fail(__('Cancelled.', 'seoprostack'));
				});
			});
		},

		items: function (slug) {
			return $('[data-sps-plugin="' + slug + '"]');
		},

		name: function (slug) {
			return String(this.items(slug).first().data('name') || slug);
		},

		status: function (slug) {
			return String(this.items(slug).first().attr('data-status') || '');
		},

		visibleSlugs: function () {
			var slugs = {};
			$('[data-sps-plugin]').each(function () {
				slugs[$(this).attr('data-sps-plugin')] = true;
			});
			return Object.keys(slugs);
		},

		// Ask for FTP or SSH details first where WordPress needs them.
		credentials: function (event, action) {
			if ((action === 'install' || action === 'uninstall' || action === 'install-activate') &&
				wp && wp.updates && wp.updates.shouldRequestFilesystemCredentials && !wp.updates.ajaxLocked) {
				wp.updates.requestFilesystemCredentials(event);
			}
		},

		message: function (slug, text) {
			this.items(slug).find('[data-sps-plugin-message]').text(text || '').prop('hidden', !text);
		},

		setBusy: function (slug, action) {
			var texts = {
				install: __('Installing…', 'seoprostack'),
				activate: __('Activating…', 'seoprostack'),
				deactivate: __('Deactivating…', 'seoprostack'),
				uninstall: __('Uninstalling…', 'seoprostack')
			};
			var $items = this.items(slug);
			this.busy[slug] = true;
			this.message(slug, '');
			$items.addClass('is-busy');
			$items.find('[data-sps-plugin-action]').prop('disabled', true);
			$items.find('[data-sps-plugin-action="' + action + '"]').addClass('updating-message').text(texts[action]);
			$items.find('[data-sps-plugin-status]').text(texts[action]);
		},

		apply: function (state) {
			if (!state || !state.slug) {
				return;
			}
			var $items = this.items(state.slug);
			$items.attr({ 'data-status': state.status, 'data-file': state.file }).removeClass('is-busy');
			$items.find('.plugin-action-buttons > li.sps-state').remove();
			$items.find('.plugin-action-buttons').prepend(state.html);
			$items.find('[data-sps-plugin-status]').text(state.label);
			$items.find('[data-sps-plugin-check]').prop('disabled', !state.usable);
		},

		refresh: function (slugs) {
			slugs = $.grep(slugs, function (slug) {
				return !PluginActions.busy[slug];
			});
			if (!slugs.length) {
				return $.Deferred().resolve().promise();
			}
			return post('seoprostack_plugin_action', { do: 'state', slugs: slugs }).done(function (response) {
				if (response && response.success) {
					$.each(response.data.states, function (index, state) {
						PluginActions.apply(state);
					});
					Bulk.refresh();
				}
			});
		},

		/**
		 * Run one action on one plugin.
		 *
		 * @return {jQuery.Promise} Resolves with the new state, rejects with a message.
		 */
		run: function (slug, action) {
			var deferred = $.Deferred();
			var name = this.name(slug);
			var done = {
				install: __('%s installed.', 'seoprostack'),
				activate: __('%s activated.', 'seoprostack'),
				deactivate: __('%s deactivated.', 'seoprostack'),
				uninstall: __('%s uninstalled.', 'seoprostack')
			};

			var settle = function () {
				delete PluginActions.busy[slug];
				delete PluginActions.cancel[slug];
			};
			var succeed = function (state) {
				settle();
				PluginActions.apply(state);
				Bulk.refresh();
				speak(sprintf(done[action], name));
				deferred.resolve(state);
			};
			// Show what is actually true now, then the reason.
			var fail = function (message, state) {
				var shown;
				settle();
				if (state) {
					PluginActions.apply(state);
					shown = $.Deferred().resolve().promise();
				} else {
					shown = PluginActions.refresh([slug]);
				}
				shown.always(function () {
					if (PluginActions.items(slug).hasClass('is-busy')) {
						PluginActions.items(slug).removeClass('is-busy').find('[data-sps-plugin-action]').prop('disabled', false).removeClass('updating-message');
					}
					PluginActions.message(slug, message);
					speak(message, 'assertive');
					deferred.reject(message);
				});
			};
			// After core's AJAX, fetch the state ours reports.
			var refreshed = function () {
				post('seoprostack_plugin_action', { do: 'state', slugs: [slug] })
					.done(function (response) {
						var state = response && response.success && response.data.states[0];
						if (state) {
							succeed(state);
						} else {
							fail(i18n.saveFailed);
						}
					})
					.fail(function (xhr) {
						fail(errorMessage(xhr, i18n.saveFailed));
					});
			};
			// Core would retry a credentials failure with its own callbacks,
			// which would leave this one waiting; ask again on the next try.
			var coreError = function (response) {
				if (response && response.errorCode === 'unable_to_connect_to_filesystem' && wp.updates.filesystemCredentials) {
					wp.updates.filesystemCredentials.available = false;
				}
				fail((response && response.errorMessage) || i18n.saveFailed);
			};
			// Core's install and delete AJAX, retried once when a page comes
			// back instead of JSON (see post()). The redirect happens in
			// admin_init, before anything is installed or deleted.
			var core = function (retry) {
				var args = {
					slug: slug,
					success: refreshed,
					error: function (response) {
						if (retry && typeof response === 'string') {
							core(false);
						} else {
							coreError(response);
						}
					}
				};
				if (action === 'install') {
					wp.updates.installPlugin(args);
				} else {
					args.plugin = String(PluginActions.items(slug).first().attr('data-file') || '');
					wp.updates.deletePlugin(args);
				}
			};

			this.setBusy(slug, action);

			if (action === 'install' || action === 'uninstall') {
				if (!wp || !wp.updates) {
					fail(i18n.saveFailed);
					return deferred.promise();
				}
				this.cancel[slug] = fail;
				core(true);
				return deferred.promise();
			}

			post('seoprostack_plugin_action', { do: action, slug: slug })
				.done(function (response) {
					if (response && response.success) {
						succeed(response.data.state);
					} else {
						fail((response && response.data && response.data.message) || i18n.saveFailed, response && response.data && response.data.state);
					}
				})
				.fail(function (xhr) {
					var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
					// A plugin that redirects or prints on activation still
					// changes state; fail() shows the real state.
					fail(errorMessage(xhr, i18n.saveFailed), data && data.state);
				});

			return deferred.promise();
		}
	};

	/* ------------------------------------------------------------------ */
	/* All list: select and bulk actions                                   */
	/* ------------------------------------------------------------------ */

	var Bulk = {
		running: false,
		stopped: false,
		selected: {},

		init: function () {
			var $root = $('[data-sps-plugin-table]');
			if (!$root.length) {
				return;
			}
			this.$root = $root;

			$root.on('change', '[data-sps-plugin-check]', function () {
				Bulk.selected[this.value] = this.checked;
				Bulk.refresh();
			});
			$root.on('change', '[data-sps-group-check]', function () {
				Bulk.select($(this).closest('[data-sps-plugin-group]').find('[data-sps-plugin-check]'), this.checked);
			});
			$root.on('change', '[data-sps-check-all-plugins]', function () {
				Bulk.select($root.find('[data-sps-plugin-check]'), this.checked);
			});
			$root.on('click', '[data-sps-bulk-apply]', function (event) {
				Bulk.apply(event);
			});
			$root.on('click', '[data-sps-bulk-stop]', function () {
				Bulk.stopped = true;
				$(this).prop('disabled', true);
				Bulk.progress(__('Stopping after this plugin…', 'seoprostack'));
			});
			window.addEventListener('beforeunload', function (event) {
				if (Bulk.running) {
					event.preventDefault();
					event.returnValue = '';
				}
			});
			this.refresh();
		},

		select: function ($boxes, checked) {
			$boxes.filter(':enabled').each(function () {
				Bulk.selected[this.value] = checked;
			});
			this.refresh();
		},

		// Selected slugs, once each, in screen order.
		slugs: function () {
			var seen = {};
			var slugs = [];
			this.$root.find('[data-sps-plugin-check]:enabled').each(function () {
				if (Bulk.selected[this.value] && !seen[this.value]) {
					seen[this.value] = true;
					slugs.push(this.value);
				}
			});
			return slugs;
		},

		// Sync checkboxes (a plugin can sit in two groups), group boxes and the count.
		refresh: function () {
			if (!this.$root) {
				return;
			}
			var tally = function ($boxes, $check) {
				var $usable = $boxes.filter(':enabled');
				var checked = $usable.filter(':checked').length;
				$check.prop({
					disabled: !$usable.length,
					checked: $usable.length > 0 && checked === $usable.length,
					indeterminate: checked > 0 && checked < $usable.length
				});
			};

			var $boxes = this.$root.find('[data-sps-plugin-check]');
			$boxes.each(function () {
				this.checked = !this.disabled && !!Bulk.selected[this.value];
			});
			this.$root.find('[data-sps-plugin-group]').each(function () {
				tally($(this).find('[data-sps-plugin-check]'), $(this).find('[data-sps-group-check]'));
			});
			tally($boxes, this.$root.find('[data-sps-check-all-plugins]'));

			var count = this.slugs().length;
			this.$root.find('[data-sps-bulk-count]').text(
				count ? sprintf(_n('%d selected', '%d selected', count, 'seoprostack'), count) : ''
			);
		},

		progress: function (text) {
			this.$root.find('[data-sps-bulk-progress]').text(text);
		},

		// What one plugin needs for a bulk action, from its current state.
		steps: function (action, status) {
			var plan = {
				'install-activate': { 'not-installed': ['install', 'activate'], inactive: ['activate'] },
				install: { 'not-installed': ['install'] },
				activate: { inactive: ['activate'] },
				deactivate: { active: ['deactivate'] },
				uninstall: { active: ['deactivate', 'uninstall'], inactive: ['uninstall'] }
			};
			return (plan[action] && plan[action][status]) || [];
		},

		apply: function (event) {
			if (this.running) {
				return;
			}
			var action = String(this.$root.find('[data-sps-bulk-action]').val() || '');
			var slugs = this.slugs();
			if (!action) {
				this.progress(__('Choose a bulk action.', 'seoprostack'));
				return;
			}
			if (!slugs.length) {
				this.progress(__('Select at least one plugin.', 'seoprostack'));
				return;
			}
			if (action === 'uninstall' && !window.confirm(sprintf(
				/* translators: %d: number of plugins */
				_n(
					'Uninstall %d plugin? If it is active, it is deactivated first. This deletes its files, and the plugin may delete its settings and data.',
					'Uninstall %d plugins? Active ones are deactivated first. This deletes their files, and the plugins may delete their settings and data.',
					slugs.length,
					'seoprostack'
				),
				slugs.length
			))) {
				return;
			}

			PluginActions.credentials(event, action);
			this.start();

			var total = slugs.length;
			var index = 0;
			var counts = { changed: 0, skipped: 0, failed: 0 };

			var next = function () {
				if (Bulk.stopped || index >= total) {
					Bulk.finish(counts);
					return;
				}
				var slug = slugs[index++];
				var steps = Bulk.steps(action, PluginActions.status(slug));
				if (!steps.length) {
					counts.skipped++;
					next();
					return;
				}
				Bulk.progress(sprintf(
					/* translators: 1: position, 2: total, 3: plugin name */
					__('%1$d of %2$d: %3$s', 'seoprostack'),
					index,
					total,
					PluginActions.name(slug)
				));

				var step = 0;
				var runStep = function () {
					if (step >= steps.length) {
						counts.changed++;
						next();
						return;
					}
					PluginActions.run(slug, steps[step++])
						.done(runStep)
						.fail(function () {
							counts.failed++;
							next();
						});
				};
				runStep();
			};
			next();
		},

		start: function () {
			this.running = true;
			this.stopped = false;
			this.$root.addClass('is-running');
			this.$root.find('[data-sps-bulk-apply], [data-sps-bulk-action]').prop('disabled', true);
			this.$root.find('[data-sps-bulk-stop]').prop({ hidden: false, disabled: false });
			this.refresh();
		},

		finish: function (counts) {
			var stopped = this.stopped;
			this.running = false;
			this.stopped = false;
			this.$root.removeClass('is-running');
			this.$root.find('[data-sps-bulk-apply], [data-sps-bulk-action]').prop('disabled', false);
			this.$root.find('[data-sps-bulk-stop]').prop('hidden', true);
			this.refresh();

			var summary = sprintf(
				/* translators: 1: plugins changed, 2: plugins skipped, 3: plugins that failed */
				__('Changed %1$d, skipped %2$d, failed %3$d.', 'seoprostack'),
				counts.changed,
				counts.skipped,
				counts.failed
			);
			summary = (stopped ? __('Stopped.', 'seoprostack') : __('Done.', 'seoprostack')) + ' ' + summary;
			this.progress(summary);
			speak(summary);
		}
	};

	/* ------------------------------------------------------------------ */
	/* Theme                                                               */
	/* ------------------------------------------------------------------ */

	var Theme = {
		init: function () {
			var $theme = $('[data-sps-theme]');
			if (!$theme.length) {
				return;
			}

			post('seoprostack_get_themes', {})
				.done(function (response) {
					$theme.html(response && response.success ? response.data.html : Plugins.notice(i18n.loadFailed));
				})
				.fail(function (xhr) {
					$theme.html(Plugins.notice(errorMessage(xhr, i18n.loadFailed)));
				})
				.always(function () {
					$theme.attr('aria-busy', 'false');
				});

			$theme.on('click', '.sps-theme-install', function (event) {
				var $button = $(this);
				if (!wp || !wp.updates || !wp.updates.installTheme) {
					return; // Fall back to the core install screen link.
				}
				event.preventDefault();
				if ($button.hasClass('updating-message')) {
					return;
				}
				$button.addClass('updating-message');

				wp.updates.installTheme({
					slug: $button.data('slug'),
					success: function (response) {
						$button.removeClass('updating-message sps-theme-install').addClass('updated-message');
						if (response.activateUrl) {
							$button.attr('href', response.activateUrl).text(wp.i18n ? wp.i18n.__('Activate', 'seoprostack') : 'Activate');
							$button.removeClass('updated-message');
						}
					},
					error: function (response) {
						$button.removeClass('updating-message');
						var message = (response && response.errorMessage) || i18n.saveFailed;
						$button.after($('<p class="sps-inline-error"></p>').text(message));
						speak(message, 'assertive');
					}
				});
			});
		}
	};

	$(function () {
		Settings.init();
		Colors.init();
		Panels.init();
		MediaField.init();
		Directory.init();
		Plugins.init();
		Theme.init();
	});
})(jQuery, window.wp, window.seoprostackAdmin);
