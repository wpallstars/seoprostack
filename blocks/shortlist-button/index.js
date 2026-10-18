/**
 * Shortlist button editor preview; rendered dynamically on the site.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (blocks, element, editor, i18n) {
	'use strict';
	var el = element.createElement;
	blocks.registerBlockType('seoprostack/shortlist-button', {
		edit: function () {
			return el('div', editor.useBlockProps(), el('button', {
				type: 'button', className: 'sps-reactions__button sps-shortlist', disabled: true
			}, i18n.__('Add to shortlist', 'seoprostack')));
		},
		save: function () { return null; }
	});
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.i18n));
