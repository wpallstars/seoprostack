/**
 * seoprostack/client-dashboard editor script.
 *
 * Plain ES5 with wp.element.createElement so the plugin needs no build step.
 * The saved content is empty: render.php builds the dashboard for whoever is
 * logged in, and the editor shows your own.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
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
	var TextControl = components.TextControl;
	var Disabled = components.Disabled;

	function Edit(props) {
		var a = props.attributes;
		var set = props.setAttributes;

		return el(Fragment, {},
			el(InspectorControls, {},
				el(PanelBody, { title: __('Show', 'seoprostack') },
					el(ToggleControl, {
						label: __('Orders', 'seoprostack'),
						checked: a.showOrders,
						onChange: function (value) { set({ showOrders: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(ToggleControl, {
						label: __('Report', 'seoprostack'),
						help: __('A link to the address in the client’s FluentCRM report field.', 'seoprostack'),
						checked: a.showReport,
						onChange: function (value) { set({ showReport: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(ToggleControl, {
						label: __('Calls', 'seoprostack'),
						help: __('Upcoming FluentBooking calls.', 'seoprostack'),
						checked: a.showCalls,
						onChange: function (value) { set({ showCalls: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(ToggleControl, {
						label: __('Payments', 'seoprostack'),
						help: __('Fluent Forms payments.', 'seoprostack'),
						checked: a.showPayments,
						onChange: function (value) { set({ showPayments: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(ToggleControl, {
						label: __('Courses', 'seoprostack'),
						help: __('Tutor LMS courses the client is enrolled in.', 'seoprostack'),
						checked: a.showCourses,
						onChange: function (value) { set({ showCourses: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(ToggleControl, {
						label: __('Community spaces', 'seoprostack'),
						help: __('FluentCommunity spaces the client belongs to.', 'seoprostack'),
						checked: a.showSpaces,
						onChange: function (value) { set({ showSpaces: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(TextControl, {
						label: __('Order page', 'seoprostack'),
						help: __('A page ID or address for “Order something new”. Empty uses the one chosen in SEO Pro Stack.', 'seoprostack'),
						value: a.orderPage,
						onChange: function (value) { set({ orderPage: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(TextControl, {
						label: __('Call booking page', 'seoprostack'),
						help: __('A page ID or address for “Book a call”. Empty uses the one chosen in SEO Pro Stack.', 'seoprostack'),
						value: a.callPage,
						onChange: function (value) { set({ callPage: value }); },
						__nextHasNoMarginBottom: true
					})
				)
			),
			el('div', useBlockProps(),
				el(Disabled, {},
					el(ServerSideRender, {
						block: 'seoprostack/client-dashboard',
						attributes: a,
						skipBlockSupportAttributes: true
					})
				)
			)
		);
	}

	blocks.registerBlockType('seoprostack/client-dashboard', {
		edit: Edit,
		save: function () {
			return null;
		}
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.serverSideRender);
