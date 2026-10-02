/**
 * seoprostack/post-reactions editor script: a preview of the buttons and
 * which ones to show. Plain ES5, no build step; the server renders the block.
 */
(function (blocks, element, blockEditor, components, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	var PATHS = {
		heart: 'M12 20.5s-7.5-4.6-7.5-10.1A4.4 4.4 0 0 1 12 7.6a4.4 4.4 0 0 1 7.5 2.8c0 5.5-7.5 10.1-7.5 10.1z',
		bookmark: 'M6.5 3.5h11v17L12 16.6l-5.5 3.9z',
		share: 'M12 3.5v11M7.5 8 12 3.5 16.5 8M5.5 12.5v7h13v-7'
	};

	function button(icon, label, cls, count) {
		return el('span', { className: 'sps-reactions__button ' + cls },
			el('svg', { className: 'sps-reactions__icon', viewBox: '0 0 24 24', width: 20, height: 20, 'aria-hidden': true },
				el('path', { d: PATHS[icon] })
			),
			el('span', { className: 'sps-reactions__label' }, label),
			undefined === count ? null : el('span', { className: 'sps-reactions__count' }, count)
		);
	}

	blocks.registerBlockType('seoprostack/post-reactions', {
		edit: function (props) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = blockEditor.useBlockProps({ className: 'sps-reactions' });
			return el(element.Fragment, null,
				el(blockEditor.InspectorControls, null,
					el(components.PanelBody, { title: __('Buttons', 'seoprostack') },
						el(components.ToggleControl, { label: __('Like', 'seoprostack'), checked: a.showLike, onChange: function (v) { set({ showLike: v }); } }),
						el(components.ToggleControl, { label: __('Save', 'seoprostack'), checked: a.showSave, onChange: function (v) { set({ showSave: v }); } }),
						el(components.ToggleControl, { label: __('Share', 'seoprostack'), checked: a.showShare, onChange: function (v) { set({ showShare: v }); } })
					)
				),
				el('div', blockProps,
					a.showLike ? button('heart', __('Like', 'seoprostack'), 'sps-reactions__like', '0') : null,
					a.showSave ? button('bookmark', __('Save', 'seoprostack'), 'sps-reactions__save') : null,
					a.showShare ? button('share', __('Share', 'seoprostack'), 'sps-reactions__share') : null,
					!a.showLike && !a.showSave && !a.showShare ? el('em', null, __('Choose at least one button.', 'seoprostack')) : null
				)
			);
		},
		save: function () {
			return null;
		}
	});
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n));
