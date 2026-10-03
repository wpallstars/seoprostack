/**
 * seoprostack/related-posts editor script.
 *
 * Plain ES5 with wp.element.createElement so the plugin needs no build step.
 * The saved content is empty: the server builds the list, and the editor
 * shows the same output for the post being edited.
 */
(function (blocks, element, blockEditor, components, i18n, data, ServerSideRender) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var TextControl = components.TextControl;
	var CheckboxControl = components.CheckboxControl;
	var BaseControl = components.BaseControl;
	var Disabled = components.Disabled;
	var Placeholder = components.Placeholder;

	function Edit(props) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = useBlockProps();
		var postId = props.context && props.context.postId;

		var postType = data.useSelect(function (select) {
			var editor = select('core/editor');
			return editor && editor.getCurrentPostType ? editor.getCurrentPostType() : '';
		}, []);

		var taxonomies = data.useSelect(function (select) {
			return select('core').getTaxonomies({ per_page: -1 });
		}, []);

		var choices = (taxonomies || []).filter(function (tax) {
			var visible = !tax.visibility || tax.visibility.publicly_queryable;
			return visible && tax.slug !== 'post_format' && (!postType || (tax.types || []).indexOf(postType) !== -1);
		});

		function toggleTaxonomy(slug, on) {
			var list = (a.taxonomies || []).filter(function (s) { return s !== slug; });
			if (on) {
				list.push(slug);
			}
			set({ taxonomies: list });
		}

		return el(Fragment, {},
			el(InspectorControls, {},
				el(PanelBody, { title: __('Related posts', 'seoprostack') },
					el(RangeControl, {
						label: __('Number of posts', 'seoprostack'),
						value: a.number,
						min: 1,
						max: 24,
						onChange: function (value) { set({ number: value || 4 }); },
						__nextHasNoMarginBottom: true
					}),
					el(SelectControl, {
						label: __('Show as', 'seoprostack'),
						value: a.layout,
						options: [
							{ value: 'grid', label: __('Grid with pictures', 'seoprostack') },
							{ value: 'list', label: __('List', 'seoprostack') }
						],
						onChange: function (value) { set({ layout: value }); },
						__nextHasNoMarginBottom: true
					}),
					a.layout === 'grid' ? el(RangeControl, {
						label: __('Columns', 'seoprostack'),
						value: a.columns,
						min: 1,
						max: 6,
						onChange: function (value) { set({ columns: value || 4 }); },
						__nextHasNoMarginBottom: true
					}) : null,
					a.layout === 'grid' ? el(ToggleControl, {
						label: __('Show featured images', 'seoprostack'),
						checked: a.showImage,
						onChange: function (value) { set({ showImage: value }); },
						__nextHasNoMarginBottom: true
					}) : null,
					el(ToggleControl, {
						label: __('Show dates', 'seoprostack'),
						checked: a.showDate,
						onChange: function (value) { set({ showDate: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(ToggleControl, {
						label: __('Show a heading', 'seoprostack'),
						checked: a.showTitle,
						onChange: function (value) { set({ showTitle: value }); },
						__nextHasNoMarginBottom: true
					}),
					a.showTitle ? el(TextControl, {
						label: __('Heading', 'seoprostack'),
						placeholder: __('Related posts', 'seoprostack'),
						value: a.title,
						onChange: function (value) { set({ title: value }); },
						__nextHasNoMarginBottom: true
					}) : null
				),
				choices.length > 1 ? el(PanelBody, { title: __('Compare', 'seoprostack'), initialOpen: false },
					el(BaseControl, {
						help: __('None ticked compares them all. Terms used on few posts count for more.', 'seoprostack'),
						__nextHasNoMarginBottom: true
					},
						choices.map(function (tax) {
							return el(CheckboxControl, {
								key: tax.slug,
								label: tax.name,
								checked: (a.taxonomies || []).indexOf(tax.slug) !== -1,
								onChange: function (on) { toggleTaxonomy(tax.slug, on); },
								__nextHasNoMarginBottom: true
							});
						})
					)
				) : null
			),
			el('div', blockProps,
				el(Disabled, {},
					el(ServerSideRender, {
						block: 'seoprostack/related-posts',
						attributes: a,
						urlQueryArgs: postId ? { post_id: postId } : undefined,
						skipBlockSupportAttributes: true,
						EmptyResponsePlaceholder: function () {
							return el(Placeholder, {
								icon: 'excerpt-view',
								label: __('Related posts', 'seoprostack'),
								instructions: __('No related posts yet. Posts that share categories, tags or other terms with this one will show here.', 'seoprostack')
							});
						}
					})
				)
			)
		);
	}

	blocks.registerBlockType('seoprostack/related-posts', {
		edit: Edit,
		save: function () {
			return null;
		}
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.data, window.wp.serverSideRender);
