/**
 * seoprostack/members-only editor script: a box for blocks only some people
 * see. Who sees them is the block's Visibility panel (Restrict content),
 * which starts at "Logged-in people" for this block. Plain ES5, no build
 * step; the server renders the block, or the message in its place.
 */
(function (blocks, element, blockEditor, components, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	function cfg() {
		return window.seoprostackRestrict || { shows: [], message: '' };
	}

	function shownTo(rule) {
		var show = (rule && rule.show) || 'in';
		var match = cfg().shows.filter(function (o) { return o.value === show; })[0];
		var label = match ? match.label : show;
		if (rule && rule.roles && rule.roles.length && ('roles' === show || 'not_roles' === show)) {
			label += ': ' + rule.roles.join(', ');
		}
		return label;
	}

	blocks.registerBlockType('seoprostack/members-only', {
		edit: function (props) {
			var a = props.attributes;
			var blockProps = blockEditor.useBlockProps({
				className: 'sps-members-only',
				style: { outline: '1px dashed rgba(127, 127, 127, 0.5)', outlineOffset: '6px' }
			});
			var innerProps = blockEditor.useInnerBlocksProps({}, {
				template: [['core/paragraph', { placeholder: __('Write what only they see…', 'seoprostack') }]]
			});
			return el(element.Fragment, null,
				el(blockEditor.InspectorControls, null,
					el(components.PanelBody, { title: __('Message', 'seoprostack') },
						el(components.TextareaControl, {
							label: __('Message instead of these blocks', 'seoprostack'),
							help: __('Leave empty for the message set in SEO Pro Stack. Logged-out visitors also get a link to log in.', 'seoprostack'),
							placeholder: cfg().message,
							value: a.message,
							onChange: function (v) { props.setAttributes({ message: v }); }
						})
					)
				),
				el('div', blockProps,
					el('p', { className: 'sps-members-only__label', style: { fontSize: '12px', opacity: 0.7, margin: '0 0 8px' } },
						__('Members only. Shown to:', 'seoprostack') + ' ' + shownTo(a.spsVisibility)
					),
					el('div', innerProps)
				)
			);
		},
		save: function () {
			return el(blockEditor.InnerBlocks.Content);
		}
	});
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n));
