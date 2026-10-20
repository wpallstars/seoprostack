/**
 * Your lists editor preview; no personal data in editor or page HTML.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (blocks, element, editor, i18n) {
	'use strict';
	var el = element.createElement;
	blocks.registerBlockType('seoprostack/lists', {
		edit: function () {
			return el('div', editor.useBlockProps({ className: 'sps-lists' }),
				el('div', { className: 'sps-lists__tabs' },
					el('button', { type: 'button', className: 'sps-reactions__button', disabled: true, 'aria-pressed': 'true' }, i18n.__('Liked', 'seoprostack')),
					el('button', { type: 'button', className: 'sps-reactions__button', disabled: true }, i18n.__('New list', 'seoprostack'))),
				el('p', { className: 'sps-saved__empty' }, i18n.__('Each visitor sees their own lists here: Liked, and the lists they name, with Move to, Remove and the actions other plugins add.', 'seoprostack')));
		},
		save: function () { return null; }
	});
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.i18n));
