/**
 * seoprostack/form and seoprostack/form-modal editor script: the form is
 * chosen from the site's keyed Fluent forms in the block sidebar. Form by
 * key previews the form as the page shows it (ServerSideRender); Form modal
 * prints at the foot of the page, so it shows a summary of what opens it.
 * Plain ES5, no build step; the server renders both blocks.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (blocks, element, blockEditor, components, ServerSideRender, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;

	function cfg() {
		return window.seoprostackFormKeys || { forms: [], modal: true };
	}

	function titleOf(key) {
		var match = cfg().forms.filter(function (form) { return form.key === key; })[0];
		return match ? match.title : '';
	}

	function formControl(props) {
		var key = props.attributes.formKey;
		var options = [{ value: '', label: __('Choose a form', 'seoprostack') }].concat(cfg().forms.map(function (form) {
			return { value: form.key, label: form.title + ' (' + form.key + ')' };
		}));
		if (key && !titleOf(key)) {
			/* translators: %s: form key */
			options.push({ value: key, label: sprintf(__('%s (no form on this site)', 'seoprostack'), key) });
		}
		return el(components.SelectControl, {
			label: __('Form', 'seoprostack'),
			help: cfg().forms.length ? __('Published Fluent forms that carry a key.', 'seoprostack') : __('No form has a key yet: wp seoprostack form-keys set <form-id> <key>.', 'seoprostack'),
			value: key,
			options: options,
			__nextHasNoMarginBottom: true,
			onChange: function (value) { props.setAttributes({ formKey: value }); }
		});
	}

	function text(props, name, label, help) {
		return el(components.TextControl, {
			label: label,
			help: help,
			value: props.attributes[name],
			__nextHasNoMarginBottom: true,
			onChange: function (value) {
				var change = {};
				change[name] = value;
				props.setAttributes(change);
			}
		});
	}

	function sidebar(children) {
		return el(blockEditor.InspectorControls, null,
			el(components.PanelBody, { title: __('Settings', 'seoprostack') }, children));
	}

	blocks.registerBlockType('seoprostack/form', {
		edit: function (props) {
			return el(element.Fragment, null,
				sidebar([
					formControl(props),
					el(components.ToggleControl, {
						label: __('In a panel', 'seoprostack'),
						help: __('A bordered box around the form, as the theme draws panels.', 'seoprostack'),
						checked: !!props.attributes.panel,
						__nextHasNoMarginBottom: true,
						onChange: function (value) { props.setAttributes({ panel: value }); }
					})
				]),
				el('div', blockEditor.useBlockProps(),
					el(ServerSideRender, { block: 'seoprostack/form', attributes: props.attributes })));
		},
		save: function () {
			return null;
		}
	});

	blocks.registerBlockType('seoprostack/form-modal', {
		edit: function (props) {
			var a = props.attributes;
			var id = a.modalId || a.formKey;
			var lines = [];
			if (a.title) {
				lines.push(a.title);
			}
			lines.push(a.formKey ? (titleOf(a.formKey) || a.formKey) : __('Choose a form in the block settings.', 'seoprostack'));
			if (id) {
				/* translators: %s: link address, such as #request */
				lines.push(sprintf(__('Opens from links to %s.', 'seoprostack'), '#' + id));
			}
			if (!cfg().modal) {
				lines.push(__('Without Kadence Blocks Pro the form shows here, in a panel.', 'seoprostack'));
			}
			return el(element.Fragment, null,
				sidebar([
					formControl(props),
					text(props, 'modalId', __('Modal ID', 'seoprostack'), __('Links to #ID open the modal. Empty: the form key.', 'seoprostack')),
					text(props, 'title', __('Title', 'seoprostack')),
					text(props, 'intro', __('Introduction', 'seoprostack'))
				]),
				el('div', blockEditor.useBlockProps(), el(components.Placeholder, {
					icon: 'welcome-widgets-menus',
					label: __('Form modal', 'seoprostack') + (id ? ': #' + id : ''),
					instructions: lines.join(' ')
				})));
		},
		save: function () {
			return null;
		}
	});
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.serverSideRender, window.wp.i18n));
