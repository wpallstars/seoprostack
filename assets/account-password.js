/**
 * My Account without WooCommerce (SEOProStack_Account): WooCommerce's
 * show-password button on every password field (woocommerce.js: the field
 * wrapped in span.password-input, with a button.show-password-input that
 * the theme's WooCommerce styles draw as an eye), and, where a password is
 * chosen, the password strength meter of the sign-up, reset and account
 * details forms, as WooCommerce's: the strength under the new password, a
 * hint while it is weak, and the form's button off until it is strong
 * enough (a password manager's weak password still shows its strength).
 * WordPress's meter (wp.passwordStrength, zxcvbn) does the measuring.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function () {
	'use strict';

	var config = window.seoprostackAccountPassword || {};
	var labels = window.pwsL10n || {};
	// wp_localize_script() passes values as strings.
	var min = parseInt(config.min, 10);
	min = isNaN(min) ? 3 : min;
	var FORMS = 'form.register, form.edit-account, form.lost_reset_password';
	var FIELDS = '#reg_password, #account_password, #password_1';

	function strengthOf(value) {
		if (!window.wp || !wp.passwordStrength || typeof wp.passwordStrength.meter !== 'function') {
			return -1;
		}
		var disallowed = typeof wp.passwordStrength.userInputDisallowedList === 'function' ? wp.passwordStrength.userInputDisallowedList() : [];
		return wp.passwordStrength.meter(value, disallowed);
	}

	function check(field) {
		var form = field.form;
		if (!form) {
			return;
		}
		var meter = form.querySelector('.woocommerce-password-strength');
		var hint = form.querySelector('.woocommerce-password-hint');
		var buttons = form.querySelectorAll('button[type="submit"]');
		if (hint) {
			hint.remove();
		}
		if (field.value === '') {
			if (meter) {
				meter.hidden = true;
			}
			field.removeAttribute('aria-describedby');
			buttons.forEach(function (button) {
				button.disabled = false;
				button.classList.remove('disabled');
			});
			return;
		}
		if (!meter) {
			meter = document.createElement('div');
			meter.className = 'woocommerce-password-strength';
			meter.id = 'password_strength';
			meter.setAttribute('aria-live', 'polite');
			field.insertAdjacentElement('afterend', meter);
		}
		meter.hidden = false;
		field.setAttribute('aria-describedby', 'password_strength');

		var strength = strengthOf(field.value);
		var error = strength >= 0 && strength < min ? ' - ' + (config.error || '') : '';
		var classes = ['short', 'bad', 'bad', 'good', 'strong', 'short'];
		var words = [labels['short'], labels.bad, labels.bad, labels.good, labels.strong, labels.mismatch];
		meter.classList.remove('short', 'bad', 'good', 'strong');
		if (strength >= 0 && strength < classes.length) {
			meter.classList.add(classes[strength]);
			meter.textContent = (words[strength] || '') + (strength === 5 ? '' : error);
		}
		if (strength >= 0 && strength < 3 && config.hint) {
			var small = document.createElement('small');
			small.className = 'woocommerce-password-hint';
			small.textContent = config.hint;
			meter.insertAdjacentElement('afterend', small);
		}
		var weak = strength >= 0 && strength < min;
		buttons.forEach(function (button) {
			button.disabled = weak;
			button.classList.toggle('disabled', weak);
		});
	}

	function onInput(event) {
		var field = event.target;
		if (field && field.matches && field.matches(FIELDS) && field.closest(FORMS)) {
			check(field);
		}
	}

	function showPasswordButtons() {
		var show = config.show || 'Show password';
		var hide = config.hide || 'Hide password';
		document.querySelectorAll('.woocommerce form .woocommerce-Input[type="password"]').forEach(function (input) {
			var wrap = input.parentElement;
			if (!wrap || !wrap.classList.contains('password-input')) {
				wrap = document.createElement('span');
				wrap.className = 'password-input';
				input.parentNode.insertBefore(wrap, input);
				wrap.appendChild(input);
			}
			if (wrap.querySelector('.show-password-input')) {
				return;
			}
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'show-password-input';
			button.setAttribute('aria-label', show);
			if (input.id) {
				button.setAttribute('aria-describedby', input.id);
			}
			button.addEventListener('click', function (event) {
				event.preventDefault();
				var shown = button.classList.toggle('display-password');
				button.setAttribute('aria-label', shown ? hide : show);
				input.type = shown ? 'text' : 'password';
				input.focus();
			});
			wrap.appendChild(button);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', showPasswordButtons);
	} else {
		showPasswordButtons();
	}
	if (config.meter) {
		document.addEventListener('input', onInput);
		document.addEventListener('change', onInput);
	}
}());
