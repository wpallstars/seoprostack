/**
 * SEO Pro Stack admin screen.
 *
 * One delegated controller per concern:
 * - Settings: instant save for [data-sps-setting] controls.
 * - Panels: expandable setting options.
 * - Tokens: insert pattern tokens into text fields.
 * - Directories: client-side filter for Pro/Hosting/Tools cards.
 * - Plugins: AJAX category loading into core's #plugin-filter list.
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

	function post(action, data) {
		return $.ajax({
			url: cfg.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: $.extend({ action: action, nonce: cfg.nonce }, data)
		});
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

		init: function () {
			$(document).on('change', '[data-sps-setting]', function () {
				Settings.save($(this));
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

		status: function (key, state, text) {
			var $status = $('[data-sps-status="' + key + '"]');
			$status.removeClass('is-saving is-saved is-error').addClass(state ? 'is-' + state : '').text(text || '');
			clearTimeout($status.data('timer'));
			if (state === 'saved') {
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

			post('seoprostack_save_setting', { key: key, value: value })
				.done(function (response) {
					if (Settings.pending[key] !== seq) {
						return; // A newer save for this key is in flight.
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
					Settings.status(key, 'saved', i18n.saved);
					speak(i18n.saved);
					$(document).trigger('seoprostack:setting-saved', [key, saved]);
				})
				.fail(function (xhr) {
					if (Settings.pending[key] === seq) {
						Settings.fail($input, key, previous, errorMessage(xhr, i18n.saveFailed));
					}
				});

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

	var Plugins = {
		request: null,

		init: function () {
			var $list = $('[data-sps-plugin-list]');
			if (!$list.length) {
				return;
			}

			$('.sps-filter .filter-links').on('click', 'a[data-category]', function (event) {
				event.preventDefault();
				Plugins.select($(this).data('category'), this.href, true);
			});

			window.addEventListener('popstate', function () {
				var match = /[?&]category=([^&#]+)/.exec(window.location.search);
				Plugins.select(match ? decodeURIComponent(match[1]) : 'minimal', null, false);
			});

			this.load(String($list.data('category')));
		},

		select: function (category, href, push) {
			var $links = $('.sps-filter .filter-links a');
			$links.removeClass('current').removeAttr('aria-current');
			$links.filter('[data-category="' + category + '"]').addClass('current').attr('aria-current', 'page');
			if (push && href && window.history && window.history.pushState) {
				window.history.pushState({ category: category }, '', href);
			}
			this.load(category);
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

		notice: function (message) {
			return $('<div class="notice notice-error inline"><p></p></div>').find('p').text(message).end();
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
		Directory.init();
		Plugins.init();
		Theme.init();
	});
})(jQuery, window.wp, window.seoprostackAdmin);
