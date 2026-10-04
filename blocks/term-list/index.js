/**
 * seoprostack/term-list editor script.
 *
 * Plain ES5 with wp.element.createElement so the plugin needs no build step.
 * The saved content is empty: render.php builds the list on the server, and
 * the editor shows the same output.
 */
(function (blocks, element, blockEditor, components, i18n, data, ServerSideRender) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelColorSettings = blockEditor.PanelColorSettings;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var TextControl = components.TextControl;
	var Disabled = components.Disabled;

	var LAYOUTS = [
		{ value: 'list', label: __('List', 'seoprostack') },
		{ value: 'grid', label: __('Grid', 'seoprostack') },
		{ value: 'cloud', label: __('Tag cloud', 'seoprostack') },
		{ value: 'inline', label: __('Comma-separated', 'seoprostack') },
		{ value: 'index', label: __('A–Z index', 'seoprostack') },
		{ value: 'dropdown', label: __('Drop-down', 'seoprostack') }
	];

	var SOURCES = [
		{ value: 'all', label: __('All terms', 'seoprostack') },
		{ value: 'post', label: __('This post’s terms', 'seoprostack') }
	];

	var ORDERS = [
		{ value: 'name', label: __('Name', 'seoprostack') },
		{ value: 'count', label: __('Most posts first', 'seoprostack') },
		{ value: 'random', label: __('Random', 'seoprostack') }
	];

	var GROUP_STYLES = [
		{ value: 'headings', label: __('Under headings', 'seoprostack') },
		{ value: 'accordion', label: __('Accordion', 'seoprostack') },
		{ value: 'tabs', label: __('Tabs', 'seoprostack') }
	];

	var settings = window.seoprostackTermList || {};

	var MARKERS = [
		{ value: '', label: __('Theme default', 'seoprostack') },
		{ value: 'none', label: __('None', 'seoprostack') },
		{ value: 'disc', label: __('Dot', 'seoprostack') },
		{ value: 'circle', label: __('Circle', 'seoprostack') },
		{ value: 'square', label: __('Square', 'seoprostack') },
		{ value: 'decimal', label: __('Numbers', 'seoprostack') }
	];

	var PRESET = 'var:preset|color|';

	/** The editor's colour palette setting (useSettings from WordPress 6.5, useSetting before). */
	var usePalette = blockEditor.useSettings
		? function () { return blockEditor.useSettings('color.palette')[0]; }
		: function () { return blockEditor.useSetting('color.palette'); };

	/** Palette entries as one list, whether the setting is a list or split by origin. */
	function flatPalette(palette) {
		if (Array.isArray(palette)) {
			return palette;
		}
		var out = [];
		['theme', 'custom', 'default'].forEach(function (origin) {
			if (palette && Array.isArray(palette[origin])) {
				out = out.concat(palette[origin]);
			}
		});
		return out;
	}

	/**
	 * A colour picked from the palette is stored as a preset reference, so
	 * the page uses the palette variable and follows dark mode switchers.
	 */
	function toStored(colors, value) {
		if (!value) {
			return '';
		}
		var match = colors.filter(function (c) {
			return c && c.slug && String(c.color).toLowerCase() === String(value).toLowerCase();
		})[0];
		return match ? PRESET + match.slug : value;
	}

	/** The colour to show in the picker for a stored value. */
	function toShown(colors, value) {
		if (typeof value !== 'string' || value.indexOf(PRESET) !== 0) {
			return value || undefined;
		}
		var slug = value.slice(PRESET.length);
		var match = colors.filter(function (c) { return c && c.slug === slug; })[0];
		return match ? match.color : undefined;
	}

	function Edit(props) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = useBlockProps();
		var colors = flatPalette(usePalette());

		var taxonomies = data.useSelect(function (select) {
			return select('core').getTaxonomies({ per_page: -1 });
		}, []);

		var options = (taxonomies || []).filter(function (tax) {
			return !tax.visibility || tax.visibility.publicly_queryable;
		}).map(function (tax) {
			return { value: tax.slug, label: tax.name };
		});
		if (a.taxonomy && !options.some(function (o) { return o.value === a.taxonomy; })) {
			options.unshift({ value: a.taxonomy, label: a.taxonomy });
		}

		var current = (taxonomies || []).filter(function (tax) { return tax.slug === a.taxonomy; })[0];
		var hierarchical = !!(current && current.hierarchical);
		var groupings = [{ value: 'none', label: __('No groups', 'seoprostack') }];
		if (hierarchical || a.groupBy === 'parent') {
			groupings.push({ value: 'parent', label: __('By top-level term', 'seoprostack') });
		}
		if (settings.tagGroups || a.groupBy === 'tag-groups') {
			groupings.push({ value: 'tag-groups', label: __('By Tag Groups group', 'seoprostack') });
		}
		var canGroup = a.layout !== 'index';
		var grouped = canGroup && a.groupBy && a.groupBy !== 'none';
		var postId = props.context && props.context.postId;

		return el(Fragment, {},
			el(InspectorControls, {},
				el(PanelBody, { title: __('Terms', 'seoprostack') },
					el(SelectControl, {
						label: __('Taxonomy', 'seoprostack'),
						value: a.taxonomy,
						options: options,
						onChange: function (value) { set({ taxonomy: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(SelectControl, {
						label: __('Terms to show', 'seoprostack'),
						value: a.source,
						options: SOURCES,
						help: a.source === 'post' ? __('The terms of the post or page the block is on, or of each post in a Query Loop.', 'seoprostack') : undefined,
						onChange: function (value) { set({ source: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(SelectControl, {
						label: __('Show as', 'seoprostack'),
						value: a.layout,
						options: LAYOUTS,
						onChange: function (value) { set({ layout: value }); },
						__nextHasNoMarginBottom: true
					}),
					a.layout === 'grid' || a.layout === 'index' ? el(RangeControl, {
						label: __('Columns', 'seoprostack'),
						value: a.columns,
						min: 1,
						max: 6,
						onChange: function (value) { set({ columns: value || 3 }); },
						__nextHasNoMarginBottom: true
					}) : null,
					a.layout === 'index' ? el(ToggleControl, {
						label: __('Show letter links', 'seoprostack'),
						checked: a.indexNav,
						onChange: function (value) { set({ indexNav: value }); },
						__nextHasNoMarginBottom: true
					}) : null,
					a.layout === 'cloud' || a.layout === 'inline' ? el(ToggleControl, {
						label: __('Show as boxes', 'seoprostack'),
						checked: a.pills,
						onChange: function (value) { set({ pills: value }); },
						__nextHasNoMarginBottom: true
					}) : null,
					el(ToggleControl, {
						label: __('Show post counts', 'seoprostack'),
						checked: a.showCount,
						onChange: function (value) { set({ showCount: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(ToggleControl, {
						label: __('Show child terms', 'seoprostack'),
						help: __('Off shows only top-level terms.', 'seoprostack'),
						checked: a.showChildren,
						onChange: function (value) { set({ showChildren: value }); },
						__nextHasNoMarginBottom: true
					}),
					a.source === 'post' ? null : el(ToggleControl, {
						label: __('Show empty terms', 'seoprostack'),
						checked: a.showEmpty,
						onChange: function (value) { set({ showEmpty: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(TextControl, {
						label: __('Text when there are no terms', 'seoprostack'),
						help: __('Leave empty to show nothing.', 'seoprostack'),
						value: a.emptyText,
						onChange: function (value) { set({ emptyText: value }); },
						__nextHasNoMarginBottom: true
					})
				),
				el(PanelBody, { title: __('Order and number', 'seoprostack'), initialOpen: false },
					el(SelectControl, {
						label: __('Order', 'seoprostack'),
						value: a.orderBy,
						options: ORDERS,
						onChange: function (value) { set({ orderBy: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(RangeControl, {
						label: __('Most terms to show', 'seoprostack'),
						help: __('0 shows all. When there are more, those with the most posts are shown.', 'seoprostack'),
						value: a.number,
						min: 0,
						max: 200,
						onChange: function (value) { set({ number: value || 0 }); },
						__nextHasNoMarginBottom: true
					}),
					el(RangeControl, {
						label: __('Fewest posts a term needs', 'seoprostack'),
						value: a.minCount,
						min: 0,
						max: 50,
						onChange: function (value) { set({ minCount: value || 0 }); },
						__nextHasNoMarginBottom: true
					})
				),
				canGroup && groupings.length > 1 ? el(PanelBody, { title: __('Groups', 'seoprostack'), initialOpen: grouped },
					el(SelectControl, {
						label: __('Group terms', 'seoprostack'),
						value: a.groupBy,
						options: groupings,
						onChange: function (value) { set({ groupBy: value }); },
						__nextHasNoMarginBottom: true
					}),
					grouped && a.layout !== 'dropdown' ? el(SelectControl, {
						label: __('Show groups', 'seoprostack'),
						value: a.groupStyle,
						options: GROUP_STYLES,
						onChange: function (value) { set({ groupStyle: value }); },
						__nextHasNoMarginBottom: true
					}) : null
				) : null,
				a.layout === 'cloud' ? el(PanelBody, { title: __('Tag cloud sizes', 'seoprostack'), initialOpen: false },
					el(RangeControl, {
						label: __('Smallest text (em)', 'seoprostack'),
						value: a.smallest,
						min: 0.5,
						max: 4,
						step: 0.125,
						onChange: function (value) { set({ smallest: value || 0.875 }); },
						__nextHasNoMarginBottom: true
					}),
					el(RangeControl, {
						label: __('Largest text (em)', 'seoprostack'),
						help: __('Terms with more posts are larger. Set both the same for one size.', 'seoprostack'),
						value: a.largest,
						min: 0.5,
						max: 6,
						step: 0.125,
						onChange: function (value) { set({ largest: value || 1.75 }); },
						__nextHasNoMarginBottom: true
					})
				) : null,
				a.layout === 'dropdown' ? null : el(PanelBody, { title: __('Spacing and markers', 'seoprostack'), initialOpen: false },
					a.layout === 'list' ? el(SelectControl, {
						label: __('List marker', 'seoprostack'),
						value: a.marker,
						options: MARKERS,
						onChange: function (value) { set({ marker: value }); },
						__nextHasNoMarginBottom: true
					}) : null,
					el(RangeControl, {
						label: __('Space between items (px)', 'seoprostack'),
						value: a.gap,
						min: 0,
						max: 80,
						allowReset: true,
						onChange: function (value) { set({ gap: value }); },
						__nextHasNoMarginBottom: true
					})
				),
				a.layout === 'dropdown' ? null : el(PanelColorSettings, {
					title: __('Link colours', 'seoprostack'),
					initialOpen: false,
					colorSettings: [
						{
							label: __('Link', 'seoprostack'),
							value: toShown(colors, a.linkColor),
							onChange: function (value) { set({ linkColor: toStored(colors, value) }); }
						},
						{
							label: __('Link on hover', 'seoprostack'),
							value: toShown(colors, a.linkHoverColor),
							onChange: function (value) { set({ linkHoverColor: toStored(colors, value) }); }
						}
					]
				})
			),
			el('div', blockProps,
				el(Disabled, {},
					el(ServerSideRender, {
						block: 'seoprostack/term-list',
						attributes: a,
						urlQueryArgs: postId ? { post_id: postId } : undefined,
						skipBlockSupportAttributes: true
					})
				)
			)
		);
	}

	blocks.registerBlockType('seoprostack/term-list', {
		edit: Edit,
		save: function () {
			return null;
		}
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.data, window.wp.serverSideRender);
