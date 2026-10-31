/**
 * seoprostack/review-links on the page: each block waits for its Fluent form
 * (data-form, the form's ID) to be sent, then shows its thanks, the
 * visitor's comment with Copy review, and the review links. The rating
 * (data-rating field; data-high and up is high) only changes the wording:
 * the links show for every rating.
 *
 * The values are read when the form is submitted, before Fluent Forms may
 * reset or hide it; the block shows on Fluent Forms' jQuery event
 * fluentform_submission_success on that form. Plain ES5, no build step.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function () {
	'use strict';

	var NAME = 'wp-block-seoprostack-review-links';
	// Form ID => { rating, comment } read at submit.
	var sent = {};

	function blocksFor(formId) {
		return Array.prototype.filter.call(document.querySelectorAll('.' + NAME + '[data-form]'), function (block) {
			return block.getAttribute('data-form') === String(formId);
		});
	}

	function formIdOf(form) {
		return form && form.getAttribute ? form.getAttribute('data-form_id') || '' : '';
	}

	/** A field's value: the checked one of a group, or the field's own. */
	function valueOf(form, name) {
		var fields = form.querySelectorAll('[name="' + name + '"]');
		var value = '';
		Array.prototype.forEach.call(fields, function (field) {
			if (('radio' === field.type || 'checkbox' === field.type) && !field.checked) {
				return;
			}
			value = field.value;
		});
		return String(value || '').trim();
	}

	function capture(form) {
		var id = formIdOf(form);
		if (!id || !blocksFor(id).length) {
			return;
		}
		var first = blocksFor(id)[0];
		sent[id] = {
			rating: valueOf(form, first.getAttribute('data-rating') || 'rating'),
			comment: valueOf(form, first.getAttribute('data-comment') || 'comment')
		};
	}

	function show(block, values) {
		var rating = parseFloat(values.rating) || 0;
		var high = rating >= (parseFloat(block.getAttribute('data-high')) || 4);
		var when = high ? 'high' : 'low';
		block.querySelectorAll('[data-when]').forEach(function (part) {
			part.hidden = part.getAttribute('data-when') !== when;
		});
		var share = block.querySelector('.' + NAME + '__share');
		var review = block.querySelector('.' + NAME + '__review');
		var text = block.querySelector('.' + NAME + '__text');
		if (review && text) {
			text.value = values.comment;
			review.hidden = '' === values.comment;
		}
		if (share) {
			share.classList.toggle('is-high', high);
		}
		block.classList.toggle('is-high', high);
		block.classList.toggle('is-low', !high);
		block.hidden = false;
		block.focus({ preventScroll: true });
		if (block.scrollIntoView) {
			block.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}
	}

	function done(form) {
		var id = formIdOf(form);
		if (!id) {
			return;
		}
		var values = sent[id] || { rating: '', comment: '' };
		blocksFor(id).forEach(function (block) {
			show(block, values);
		});
	}

	function announce(block, which) {
		var status = block.querySelector('.' + NAME + '__copied');
		if (status) {
			status.textContent = status.getAttribute('data-' + which) || '';
		}
	}

	function selectText(text) {
		text.focus();
		text.select();
		if (text.setSelectionRange) {
			text.setSelectionRange(0, text.value.length);
		}
	}

	function copy(button) {
		var block = button.closest('.' + NAME);
		var text = block && block.querySelector('.' + NAME + '__text');
		if (!text) {
			return;
		}
		// Select-all is the fallback where the Clipboard API is missing or refused.
		function fallback() {
			selectText(text);
			var copied = false;
			try {
				copied = document.execCommand && document.execCommand('copy');
			} catch (e) {
				copied = false;
			}
			announce(block, copied ? 'copied' : 'select');
		}
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text.value).then(function () {
				announce(block, 'copied');
			}, fallback);
		} else {
			fallback();
		}
	}

	// Capture first: Fluent Forms handles submit itself and may reset the form.
	document.addEventListener('submit', function (event) {
		capture(event.target);
	}, true);
	// Fluent Forms submits on its button's click too.
	document.addEventListener('click', function (event) {
		var target = event.target;
		if (!target || !target.closest) {
			return;
		}
		var button = target.closest('.' + NAME + '__copy');
		if (button) {
			event.preventDefault();
			copy(button);
			return;
		}
		if (target.closest('button[type="submit"], .ff-btn-submit')) {
			capture(target.closest('form'));
		}
	}, true);

	function listen() {
		if (window.jQuery) {
			window.jQuery(document).on('fluentform_submission_success', 'form', function () {
				done(this);
			});
		}
	}
	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', listen);
	} else {
		listen();
	}
}());
