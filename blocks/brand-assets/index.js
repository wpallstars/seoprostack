/**
 * seoprostack/brand-assets editor script.
 *
 * Plain ES5 with wp.element.createElement so the plugin needs no build step.
 * The saved content is empty: render.php builds the page from the Kadence
 * theme's Customizer settings each time, and the editor shows the same.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (blocks, element, blockEditor, components, i18n, ServerSideRender) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var Disabled = components.Disabled;

	var SECTIONS = [
		['showLogos', __('Logos', 'seoprostack'), __('Logo files, and the logo with the site title where the header shows it.', 'seoprostack')],
		['showColours', __('Colours', 'seoprostack'), __('The palette, and the dark palette when dark mode is on.', 'seoprostack')],
		['showFonts', __('Fonts', 'seoprostack'), __('Heading and body fonts.', 'seoprostack')],
		['showFormatting', __('Formatting', 'seoprostack'), __('Heading, text, quote and button styles.', 'seoprostack')]
	];

	function Edit(props) {
		var a = props.attributes;
		var set = props.setAttributes;

		return el(Fragment, {},
			el(InspectorControls, {},
				el(PanelBody, { title: __('Show', 'seoprostack') },
					SECTIONS.map(function (section) {
						return el(ToggleControl, {
							key: section[0],
							label: section[1],
							help: section[2],
							checked: a[section[0]],
							onChange: function (value) {
								var change = {};
								change[section[0]] = value;
								set(change);
							},
							__nextHasNoMarginBottom: true
						});
					})
				),
				el(PanelBody, { title: __('Where it comes from', 'seoprostack'), initialOpen: false },
					el('p', {}, __('Change the logo, colours and fonts in Appearance → Customize (Header → Logo, Colors & Fonts). This block shows them as they are.', 'seoprostack'))
				)
			),
			el('div', useBlockProps(),
				el(Disabled, {},
					el(ServerSideRender, {
						block: 'seoprostack/brand-assets',
						attributes: a,
						skipBlockSupportAttributes: true
					})
				)
			)
		);
	}

	blocks.registerBlockType('seoprostack/brand-assets', {
		edit: Edit,
		save: function () {
			return null;
		}
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.serverSideRender);
