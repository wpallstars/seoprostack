/**
 * Fluent Forms' Website URL fields (SEOProStack_Form_Url_Fields): add
 * https:// to an address typed without it, such as www.example.com.
 *
 * - When the visitor leaves the field (change) and when the form is sent
 *   (capture, before Fluent Forms' own submit handler, so pressing Enter in
 *   the field works too), anything that looks like a web address gets
 *   https://; "https//", "https:/" and an upper-case scheme are corrected.
 * - Anything else that Fluent Forms' url rule would refuse shows the rule's
 *   own message at once, in Fluent Forms' error markup, which Fluent Forms
 *   removes when the field changes again.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function () {
	'use strict';

	var FIELD = 'input[data-seoprostack-url]';
	var SCHEME = /^[a-z][a-z0-9+.-]*:\/\//i;
	var LOOSE = /^(https?)(?::\/*|\/+)(.+)$/i;
	var LABEL = /^[a-z0-9\u00a1-\uffff-]{1,63}$/i;
	var TLD = /^(?:[a-z\u00a1-\uffff]{2,63}|xn--[a-z0-9-]{1,59})$/i;
	var OCTET = /^\d{1,3}$/;
	var PORT = /^\d{1,5}$/;
	// Fluent Forms' own url rule (assets/js/form-submission.js).
	var VALID = /^(ftp|http|https):\/\/[^ "]+$/;

	// A host name or IPv4 address, with an optional port, then a path,
	// query or fragment with no spaces: checked a part at a time, so no
	// pattern can backtrack.
	function isHost(text) {
		if (text.length > 2048 || /\s/.test(text)) {
			return false;
		}
		var host = text.split(/[/?#]/)[0];
		var colon = host.indexOf(':');
		if (colon !== -1) {
			if (!PORT.test(host.slice(colon + 1))) {
				return false;
			}
			host = host.slice(0, colon);
		}
		var labels = host.split('.');
		if (labels.length < 2) {
			return false;
		}
		if (labels.length === 4 && labels.every(function (label) {
			return OCTET.test(label) && Number(label) < 256;
		})) {
			return true;
		}
		return TLD.test(labels[labels.length - 1]) && labels.every(function (label) {
			return LABEL.test(label) && label.charAt(0) !== '-' && label.charAt(label.length - 1) !== '-';
		});
	}

	function normalise(value) {
		var text = String(value).trim();
		if (text === '') {
			return text;
		}
		if (SCHEME.test(text)) {
			return text.replace(SCHEME, function (scheme) {
				return scheme.toLowerCase();
			});
		}
		var loose = LOOSE.exec(text);
		if (loose && isHost(loose[2])) {
			return loose[1].toLowerCase() + '://' + loose[2];
		}
		var host = text.indexOf('//') === 0 ? text.slice(2) : text;
		return isHost(host) ? 'https://' + host : text;
	}

	function fix(field) {
		var value = normalise(field.value);
		if (value !== field.value) {
			field.value = value;
		}
	}

	function formVars(form) {
		var instance = form ? String(form.getAttribute('data-form_instance') || '').replace(/[^a-zA-Z0-9_-]/g, '') : '';
		var vars = instance ? window['fluent_form_' + instance] : null;
		return vars && typeof vars === 'object' ? vars : null;
	}

	function showError(field) {
		var vars = formVars(field.form);
		var rules = vars && vars.rules ? vars.rules[field.name] : null;
		var rule = rules ? rules.url : null;
		var layout = vars && vars.settings && vars.settings.layout ? vars.settings.layout : {};
		var group = field.closest('.ff-el-group');
		var content = field.closest('.ff-el-input--content');
		// Errors stacked at the end of the form are shown by Fluent Forms when it is sent.
		if (!rule || !rule.value || !rule.message || layout.errorMessagePlacement === 'stackToBottom' || !group || !content) {
			return;
		}
		var old = content.querySelector('div.error');
		if (old) {
			old.parentNode.removeChild(old);
		}
		var error = document.createElement('div');
		error.className = 'error text-danger';
		error.setAttribute('role', 'alert');
		error.textContent = String(rule.message);
		field.setAttribute('aria-invalid', 'true');
		group.classList.add('ff-el-is-error');
		content.appendChild(error);
	}

	document.addEventListener('change', function (event) {
		var field = event.target;
		if (!field || !field.matches || !field.matches(FIELD)) {
			return;
		}
		fix(field);
		if (field.value !== '' && !VALID.test(field.value)) {
			showError(field);
		}
	});

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || !form.querySelectorAll) {
			return;
		}
		Array.prototype.forEach.call(form.querySelectorAll(FIELD), fix);
	}, true);
})();
