/**
 * seoprostack/review-links editor script: the feedback form is chosen from
 * the site's keyed Fluent forms (window.seoprostackFormKeys), with the
 * rating and comment field names and the wording in the block sidebar. The
 * block shows only after its form is sent, so the editor shows a summary.
 * Plain ES5, no build step; the server renders the block.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (blocks, element, blockEditor, components, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;

	function forms() {
		return (window.seoprostackFormKeys && window.seoprostackFormKeys.forms) || [];
	}

	function links() {
		return (window.seoprostackReviewLinks && window.seoprostackReviewLinks.links) || [];
	}

	function text(props, name, label, help) {
		return el(components.TextControl, {
			label: label,
			help: help,
			value: props.attributes[name] || '',
			onChange: function (value) {
				var change = {};
				change[name] = value;
				props.setAttributes(change);
			}
		});
	}

	blocks.registerBlockType('seoprostack/review-links', {
		edit: function (props) {
			var a = props.attributes;
			var options = [{ value: '', label: __('Choose a form', 'seoprostack') }].concat(forms().map(function (form) {
				return { value: form.key, label: form.title + ' (' + form.key + ')' };
			}));
			if (a.formKey && !forms().some(function (form) { return form.key === a.formKey; })) {
				/* translators: %s: form key */
				options.push({ value: a.formKey, label: sprintf(__('%s (no form on this site)', 'seoprostack'), a.formKey) });
			}
			var names = links();
			var summary = names.length ?
				/* translators: %s: review sites, such as Google, Trustpilot */
				sprintf(__('Shows after the form is sent: thanks, the comment with Copy review, and review links for %s.', 'seoprostack'), names.join(', ')) :
				__('Shows after the form is sent: thanks only. Set the targets of the googlereview, facebookreview or trustpilotreview short links to show review links.', 'seoprostack');
			return el(element.Fragment, null,
				el(blockEditor.InspectorControls, null,
					el(components.PanelBody, { title: __('Feedback form', 'seoprostack') },
						el(components.SelectControl, {
							label: __('Form', 'seoprostack'),
							help: __('The keyed Fluent form on this page that collects the rating and comment.', 'seoprostack'),
							value: a.formKey,
							options: options,
							onChange: function (value) { props.setAttributes({ formKey: value }); }
						}),
						text(props, 'ratingField', __('Rating field name', 'seoprostack'), __('Default: rating.', 'seoprostack')),
						text(props, 'commentField', __('Comment field name', 'seoprostack'), __('Default: comment.', 'seoprostack')),
						el(components.RangeControl, {
							label: __('High rating from', 'seoprostack'),
							help: __('Only the wording changes: review links show for every rating.', 'seoprostack'),
							value: a.highRating,
							min: 1,
							max: 5,
							onChange: function (value) { props.setAttributes({ highRating: value || 4 }); }
						})
					),
					el(components.PanelBody, { title: __('Wording', 'seoprostack'), initialOpen: false },
						text(props, 'thanksHigh', __('Thanks, high rating', 'seoprostack'), __('Default: Thank you for your feedback!', 'seoprostack')),
						text(props, 'thanksLow', __('Thanks, low rating', 'seoprostack'), __('Default: Thank you for telling us. We’ll be in touch to put things right.', 'seoprostack')),
						text(props, 'askHigh', __('Review request, high rating', 'seoprostack'), ''),
						text(props, 'askLow', __('Review request, low rating', 'seoprostack'), ''),
						text(props, 'copyLabel', __('Copy button', 'seoprostack'), __('Default: Copy review', 'seoprostack'))
					)
				),
				el('div', blockEditor.useBlockProps({ className: 'wp-block-seoprostack-review-links is-editor-summary' }),
					el('p', { className: 'wp-block-seoprostack-review-links__thanks' }, __('Review links', 'seoprostack')),
					el('p', null, summary)
				)
			);
		},
		save: function () {
			return null;
		}
	});
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n));
