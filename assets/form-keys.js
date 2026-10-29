/**
 * Form modals (SEOProStack_Form_Keys): three fixes to Kadence Blocks Pro's
 * modal (kt-modal-init, MicroModal) for forms.
 *
 * 1. Close from the overlay only when the press began there. MicroModal
 *    closes on any click whose target is the overlay, and a press that starts
 *    in the panel and ends on the overlay is dispatched there too: a drag out
 *    of a field, or a tap on the panel while a searchable list is open (the
 *    list closes, the panel shrinks and the release lands on the overlay).
 *    The close button, Escape and a press on the overlay itself still close.
 * 2. Show the whole of an opened searchable list (Choices.js): scroll the
 *    panel just enough when it opens below the visible area.
 * 3. Keep Tab on the visible fields. Kadence's trap wraps at the last
 *    focusable node in the dialog, which in a multi-step Fluent form is a
 *    hidden later step, so Tab from Next left the dialog.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function () {
	'use strict';

	var MODAL = '.seoprostack-form-modal';
	var pressInPanel = false;

	window.addEventListener('pointerdown', function (event) {
		var target = event.target;
		pressInPanel = !!(target && target.closest && target.closest(MODAL + ' .kt-modal-container'));
	}, true);

	window.addEventListener('click', function (event) {
		var target = event.target;
		var fromPanel = pressInPanel;
		pressInPanel = false;
		if (fromPanel && target && target.classList && target.classList.contains('kt-modal-overlay') && target.closest(MODAL)) {
			event.preventDefault();
			event.stopPropagation();
		}
	}, true);

	document.addEventListener('showDropdown', function (event) {
		var field = event.target && event.target.closest ? event.target.closest('.choices') : null;
		if (!field || !field.closest(MODAL + '.is-open')) {
			return;
		}
		var list = field.querySelector('.choices__list--dropdown.is-active, .choices__list[aria-expanded].is-active');
		if (!list || !list.scrollIntoView) {
			return;
		}
		window.requestAnimationFrame(function () {
			list.scrollIntoView({ block: 'nearest' });
		});
	});

	var focusable = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex^="-"])';

	window.addEventListener('keydown', function (event) {
		if (event.key !== 'Tab') {
			return;
		}
		var modal = document.querySelector(MODAL + '.is-open');
		if (!modal) {
			return;
		}
		var nodes = Array.prototype.filter.call(modal.querySelectorAll(focusable), function (node) {
			return node.getClientRects().length > 0 && window.getComputedStyle(node).visibility !== 'hidden';
		});
		if (!nodes.length) {
			return;
		}
		var first = nodes[0];
		var last = nodes[nodes.length - 1];
		var active = document.activeElement;
		var target = null;
		if (!modal.contains(active)) {
			target = event.shiftKey ? last : first;
		} else if (event.shiftKey && active === first) {
			target = last;
		} else if (!event.shiftKey && active === last) {
			target = first;
		}
		if (target) {
			event.preventDefault();
			event.stopPropagation();
			target.focus();
		}
	}, true);
}());
