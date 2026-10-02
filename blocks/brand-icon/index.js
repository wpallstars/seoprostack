/**
 * seoprostack/brand-icon editor script. Plain ES5, no build step; the server
 * renders the block. Icons are found through GET seoprostack/v1/brand-icons
 * (never the whole set) and drawn here from their path data.
 */
(function (blocks, element, blockEditor, components, i18n, apiFetch, url) {
	'use strict';

	var el = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var __ = i18n.__;
	var cache = {};

	/* As brand_color() in PHP: near-black and near-white logos take the text colour. */
	function brandColor(hex) {
		if (!/^[0-9a-f]{6}$/i.test(hex || '')) {
			return 'currentColor';
		}
		var light = [[0, 0.2126], [2, 0.7152], [4, 0.0722]].reduce(function (sum, p) {
			var c = parseInt(hex.substr(p[0], 2), 16) / 255;
			return sum + p[1] * (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
		}, 0);
		return light < 0.02 || light > 0.85 ? 'currentColor' : '#' + hex;
	}

	function fetchIcons(query) {
		var key = query.slug ? 'slug:' + query.slug : 'q:' + query.search;
		if (!cache[key]) {
			cache[key] = apiFetch({ path: url.addQueryArgs('/seoprostack/v1/brand-icons', query) }).then(function (res) {
				return res.icons || [];
			}, function (e) {
				delete cache[key];
				throw e;
			});
		}
		return cache[key];
	}

	function Svg(props) {
		var icon = props.icon;
		return el('svg', {
			viewBox: icon.viewBox,
			width: props.size,
			height: props.size,
			'aria-hidden': true,
			focusable: false,
			style: { display: 'block', fill: props.color }
		}, icon.paths.map(function (d, i) {
			return el('path', { key: i, d: d });
		}));
	}

	/* Search box and results grid. */
	function Picker(props) {
		var _s = useState('');
		var search = _s[0];
		var setSearch = _s[1];
		var _r = useState(null);
		var results = _r[0];
		var setResults = _r[1];
		var _e = useState(false);
		var failed = _e[0];
		var setFailed = _e[1];

		useEffect(function () {
			var live = true;
			var timer = window.setTimeout(function () {
				fetchIcons({ search: search }).then(function (icons) {
					if (live) {
						setResults(icons);
						setFailed(false);
					}
				}, function () {
					if (live) {
						setFailed(true);
					}
				});
			}, search ? 250 : 0);
			return function () {
				live = false;
				window.clearTimeout(timer);
			};
		}, [search]);

		return el('div', { className: 'sps-brand-icon-picker' },
			el(components.SearchControl || components.TextControl, {
				label: __('Search brand icons', 'seoprostack'),
				placeholder: __('Search brand icons', 'seoprostack'),
				value: search,
				onChange: setSearch,
				__nextHasNoMarginBottom: true
			}),
			failed ? el('p', null, __('Icons could not be loaded. Please try again.', 'seoprostack')) : null,
			null === results && !failed ? el(components.Spinner) : null,
			results && !results.length ? el('p', null, __('No icons found.', 'seoprostack')) : null,
			results && results.length ? el('div', {
				role: 'listbox',
				'aria-label': __('Brand icons', 'seoprostack'),
				style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(44px, 1fr))', gap: '4px', marginTop: '8px', maxHeight: props.tall ? '320px' : '260px', overflowY: 'auto' }
			}, results.map(function (icon) {
				var selected = icon.slug === props.value;
				return el(components.Button, {
					key: icon.slug,
					label: icon.title,
					showTooltip: true,
					isPressed: selected,
					'aria-selected': selected,
					role: 'option',
					onClick: function () {
						props.onChange(icon);
					},
					style: { height: '44px', justifyContent: 'center' }
				}, el(Svg, { icon: icon, size: 24, color: brandColor(icon.hex) }));
			})) : null
		);
	}

	blocks.registerBlockType('seoprostack/brand-icon', {
		edit: function (props) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = blockEditor.useBlockProps();
			var _i = useState(null);
			var icon = _i[0];
			var setIcon = _i[1];

			useEffect(function () {
				if (!a.icon) {
					setIcon(null);
					return;
				}
				if (icon && icon.slug === a.icon) {
					return;
				}
				fetchIcons({ slug: a.icon }).then(function (icons) {
					setIcon(icons[0] || false);
				}, function () {
					setIcon(false);
				});
			}, [a.icon]);

			function choose(chosen) {
				cache['slug:' + chosen.slug] = Promise.resolve([chosen]);
				setIcon(chosen);
				set({ icon: chosen.slug });
			}

			var inspector = el(blockEditor.InspectorControls, null,
				el(components.PanelBody, { title: __('Icon', 'seoprostack') },
					el(Picker, { value: a.icon, onChange: choose })
				),
				el(components.PanelBody, { title: __('Settings', 'seoprostack') },
					el(components.ToggleControl, {
						label: __('Brand colour', 'seoprostack'),
						help: a.brandColor ? __('The brand’s own colour.', 'seoprostack') : __('The text colour (choose one under Styles, or it follows the theme and its dark mode).', 'seoprostack'),
						checked: a.brandColor,
						onChange: function (v) { set({ brandColor: v }); }
					}),
					el(components.RangeControl, {
						label: __('Size (px)', 'seoprostack'),
						min: 12,
						max: 256,
						value: a.size,
						onChange: function (v) { set({ size: v || 32 }); }
					}),
					el(components.TextControl, {
						label: __('Link', 'seoprostack'),
						type: 'url',
						placeholder: 'https://',
						value: a.url,
						onChange: function (v) { set({ url: v }); }
					}),
					a.url ? el(components.ToggleControl, {
						label: __('Open in a new tab', 'seoprostack'),
						checked: a.newTab,
						onChange: function (v) { set({ newTab: v }); }
					}) : null,
					el(components.TextControl, {
						label: __('Name for screen readers', 'seoprostack'),
						help: __('Leave empty for the brand’s name.', 'seoprostack'),
						placeholder: icon ? icon.title : '',
						value: a.label,
						onChange: function (v) { set({ label: v }); }
					})
				)
			);

			if (!a.icon || false === icon) {
				return el('div', blockProps, inspector,
					el(components.Placeholder, {
						icon: 'star-filled',
						label: __('Brand icon', 'seoprostack'),
						instructions: false === icon ? __('This icon is no longer in the set. Choose another.', 'seoprostack') : __('Search for a brand and choose its icon.', 'seoprostack')
					}, el('div', { style: { width: '100%' } }, el(Picker, { value: a.icon, onChange: choose, tall: true })))
				);
			}

			return el('div', blockProps, inspector,
				icon ? el('span', { style: { display: 'inline-block', lineHeight: 0 }, title: a.label || icon.title },
					el(Svg, { icon: icon, size: a.size, color: a.brandColor ? brandColor(icon.hex) : 'currentColor' })
				) : el(components.Spinner)
			);
		},
		save: function () {
			return null;
		}
	});
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.apiFetch, window.wp.url));
