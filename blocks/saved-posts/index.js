/**
 * seoprostack/saved-posts editor script: settings and an example list.
 * Plain ES5, no build step; the server renders the block and the front-end
 * script fills it with the visitor's own saved posts.
 */
(function (blocks, element, blockEditor, components, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType('seoprostack/saved-posts', {
		edit: function (props) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = blockEditor.useBlockProps({ className: 'sps-saved' });
			var example = [__('A saved post', 'seoprostack'), __('Another saved post', 'seoprostack')];
			return el(element.Fragment, null,
				el(blockEditor.InspectorControls, null,
					el(components.PanelBody, { title: __('Saved posts', 'seoprostack') },
						el(components.ToggleControl, { label: __('Show excerpts', 'seoprostack'), checked: a.showExcerpt, onChange: function (v) { set({ showExcerpt: v }); } }),
						el(components.ToggleControl, { label: __('Show Remove buttons', 'seoprostack'), checked: a.showRemove, onChange: function (v) { set({ showRemove: v }); } }),
						el(components.TextControl, {
							label: __('Text when nothing is saved', 'seoprostack'),
							placeholder: __('Nothing saved yet.', 'seoprostack'),
							value: a.emptyText,
							onChange: function (v) { set({ emptyText: v }); }
						})
					)
				),
				el('div', blockProps,
					el('ul', { className: 'sps-saved__list' }, example.map(function (title) {
						return el('li', { key: title, className: 'sps-saved__item' },
							el('a', { className: 'sps-saved__link', href: '#', onClick: function (e) { e.preventDefault(); } }, title),
							a.showExcerpt ? el('p', { className: 'sps-saved__excerpt' }, __('The post’s excerpt.', 'seoprostack')) : null,
							a.showRemove ? el('button', { type: 'button', className: 'sps-reactions__button sps-saved__remove', disabled: true }, __('Remove', 'seoprostack')) : null
						);
					})),
					el('p', { className: 'sps-saved__note' }, __('Each visitor sees the posts they saved.', 'seoprostack'))
				)
			);
		},
		save: function () {
			return null;
		}
	});
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n));
