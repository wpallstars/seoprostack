/**
 * Organise the admin menu, options panel: choosing an entry's place in the
 * table writes its "Move menu entries" line, which then saves as usual.
 *
 * Each select (printed by SEOProStack_Admin_Menu::panel()) carries the
 * entry's address, the menu it comes from and its usual place. Back to the
 * usual place removes the line; any other place adds or replaces
 * "address = place".
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 *
 * @package SEOProStack
 */
(function () {
	'use strict';

	var field = document.querySelector('textarea[data-sps-setting="admin_menu_moves"]');
	if (!field) {
		return;
	}

	/** The address a line is about ("address = place"; addresses may hold "="). */
	function keyOf(line) {
		var at = line.lastIndexOf(' = ');
		if (at < 0) {
			at = line.lastIndexOf('=');
		}
		return at < 0 ? '' : line.slice(0, at).trim();
	}

	document.addEventListener('change', function (event) {
		var select = event.target;
		if (!select.matches || !select.matches('select[data-sps-menu-place]')) {
			return;
		}
		var slug = select.getAttribute('data-sps-menu-place');
		var from = select.getAttribute('data-sps-menu-from');
		var keys = [slug, from + '>' + slug];
		var lines = field.value.split(/\r?\n/).filter(function (line) {
			return '' !== line.trim() && keys.indexOf(keyOf(line.trim())) < 0;
		});
		if (select.value !== select.getAttribute('data-sps-menu-usual')) {
			lines.push(slug + ' = ' + select.value);
		}
		field.value = lines.join('\n');
		field.dispatchEvent(new Event('change', { bubbles: true }));
	});
})();
