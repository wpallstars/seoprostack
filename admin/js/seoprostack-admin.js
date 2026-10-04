/**
 * SEO Pro Stack admin screen.
 *
 * One delegated controller per concern:
 * - Settings: instant save for [data-sps-setting] controls.
 * - Panels: expandable setting options.
 * - Tokens: insert pattern tokens into text fields.
 * - Media fields: choose a Media Library picture with the media dialog.
 *
 * The plugin's own tabs bring their own script (seoprostack_admin_enqueue),
 * which can use window.seoprostackAdmin.api.
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
				// By id, so the attribute is never read as a selector.
				var group = document.getElementById(this.getAttribute(all ? 'data-sps-check-all' : 'data-sps-check-none'));
				if (!group) {
					return;
				}
				$(group).find(':checkbox').prop('checked', all);
				Settings.save($(group));
			});
			// Forms that change data elsewhere ask first.
			$(document).on('submit', 'form[data-sps-confirm]', function (event) {
				if (!window.confirm($(this).attr('data-sps-confirm'))) {
					event.preventDefault();
				}
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

	// For the plugin's own admin scripts, which load after this one
	// (seoprostack_admin_enqueue): the same AJAX, screen reader and error helpers.
	cfg.api = { post: post, speak: speak, errorMessage: errorMessage };

	$(function () {
		Settings.init();
		Panels.init();
		MediaField.init();
	});
})(jQuery, window.wp, window.seoprostackAdmin);
