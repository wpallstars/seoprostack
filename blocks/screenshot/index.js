/**
 * seoprostack/screenshot editor script, and the editor side of Browser Shots'
 * block (browser-shots/browser-shots), which can be converted.
 *
 * Plain ES5 with wp.element.createElement so the plugin needs no build step.
 * The saved content is empty: render.php builds the image on the server.
 */
(function (blocks, element, blockEditor, components, data, i18n, apiFetch) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;
	var InspectorControls = blockEditor.InspectorControls;
	var BlockControls = blockEditor.BlockControls;
	var RichText = blockEditor.RichText;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;
	var Placeholder = components.Placeholder;
	var Button = components.Button;
	var Notice = components.Notice;
	var Spinner = components.Spinner;
	var ToolbarGroup = components.ToolbarGroup;
	var ToolbarButton = components.ToolbarButton;

	var cfg = window.seoprostackScreenshots || { width: 1920, height: 1080, canCapture: true };

	/**
	 * Check an address. Returns an error message or ''.
	 */
	function urlProblem(url) {
		var parsed;
		try {
			parsed = new URL(url);
		} catch (e) {
			return __('Enter a full web address starting with https://', 'seoprostack');
		}
		if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
			return __('Only http and https addresses can be captured.', 'seoprostack');
		}
		return '';
	}

	function hostOf(url) {
		try {
			return new URL(url).hostname;
		} catch (e) {
			return url;
		}
	}

	/**
	 * "4/3" → [4, 3]; anything else → null (the browser window).
	 */
	function parseRatio(ratio) {
		var m = /^\s*(\d{1,5})\s*[\/:]\s*(\d{1,5})\s*$/.exec(ratio || '');
		return m && +m[1] && +m[2] ? [+m[1], +m[2]] : null;
	}

	/**
	 * "theme-palette3" → "theme-palette-3", as WordPress names preset
	 * variables (Kadence's palette slugs end in a number).
	 */
	function kebab(value) {
		return String(value)
			.replace(/([a-z])([A-Z])/g, '$1-$2')
			.replace(/([a-zA-Z])([0-9])/g, '$1-$2')
			.replace(/([0-9])([a-zA-Z])/g, '$1-$2')
			.replace(/[^a-zA-Z0-9]+/g, '-')
			.replace(/^-+|-+$/g, '')
			.toLowerCase();
	}

	/**
	 * "var:preset|shadow|natural" → "var(--wp--preset--shadow--natural)".
	 */
	function cssValue(value) {
		return typeof value === 'string' && value.indexOf('var:') === 0
			? 'var(--wp--' + value.slice(4).split('|').map(kebab).join('--') + ')'
			: value;
	}

	/**
	 * Border and shadow for the picture, matching imageStyle() in PHP.
	 */
	function imageStyle(a) {
		var style = a.style || {};
		var border = style.border || {};
		var css = {};
		var color = a.borderColor ? cssValue('var:preset|color|' + a.borderColor) : cssValue(border.color);
		if (border.width) {
			css.borderWidth = border.width;
		}
		if (border.style) {
			css.borderStyle = border.style;
		}
		if (color) {
			css.borderColor = color;
		}
		if (typeof border.radius === 'string') {
			css.borderRadius = border.radius;
		} else if (border.radius) {
			['topLeft', 'topRight', 'bottomLeft', 'bottomRight'].forEach(function (corner) {
				if (border.radius[corner]) {
					css['border' + corner.charAt(0).toUpperCase() + corner.slice(1) + 'Radius'] = border.radius[corner];
				}
			});
		}
		['top', 'right', 'bottom', 'left'].forEach(function (side) {
			var s = border[side];
			var name = 'border' + side.charAt(0).toUpperCase() + side.slice(1);
			if (!s) {
				return;
			}
			if (s.width) {
				css[name + 'Width'] = s.width;
			}
			if (s.style) {
				css[name + 'Style'] = s.style;
			}
			if (s.color) {
				css[name + 'Color'] = cssValue(s.color);
			}
		});
		if (style.shadow) {
			css.boxShadow = cssValue(style.shadow);
		}
		return css;
	}

	function gcd(a, b) {
		return b ? gcd(b, a % b) : a;
	}

	function ratioOptions(current) {
		var options = [
			{ value: '', label: sprintf(__('Browser window (%1$d × %2$d)', 'seoprostack'), cfg.width, cfg.height) },
			{ value: '16/9', label: '16:9' },
			{ value: '16/10', label: '16:10' },
			{ value: '4/3', label: '4:3' },
			{ value: '3/2', label: '3:2' },
			{ value: '1/1', label: __('Square', 'seoprostack') }
		];
		var known = options.some(function (option) {
			return option.value === current;
		});
		if (current && !known) {
			options.push({ value: current, label: current.replace('/', ':') });
		}
		return options;
	}

	function Edit(props) {
		var a = props.attributes;
		var set = props.setAttributes;
		var postId = props.context && props.context.postId ? props.context.postId : 0;
		var blockProps = useBlockProps({ className: 'seoprostack-screenshot' + (a.width ? '' : ' is-fill'), style: a.width ? { width: a.width + 'px' } : undefined });

		var draftState = useState(a.url);
		var draft = draftState[0];
		var setDraft = draftState[1];
		var editingState = useState(!a.url);
		var editing = editingState[0];
		var setEditing = editingState[1];
		var shotState = useState(null);
		var shot = shotState[0];
		var setShot = shotState[1];
		var busyState = useState(false);
		var busy = busyState[0];
		var setBusy = busyState[1];
		var errorState = useState('');
		var error = errorState[0];
		var setError = errorState[1];
		var roundState = useState(0);
		var round = roundState[0];
		var setRound = roundState[1];
		var renew = useRef(false);

		useEffect(function () {
			if (!a.url || editing) {
				return undefined;
			}
			var cancelled = false;
			var refresh = renew.current;
			renew.current = false;
			setBusy(true);
			setError('');
			apiFetch({
				path: '/seoprostack/v1/screenshot',
				method: 'POST',
				data: { url: a.url, ratio: a.aspectRatio, refresh: refresh, post: postId }
			}).then(function (response) {
				if (cancelled) {
					return;
				}
				setShot(response && response.id ? response : null);
				setError(response && !response.id && response.message ? response.message : '');
				setBusy(false);
			}).catch(function (err) {
				if (cancelled) {
					return;
				}
				setError(err && err.message ? err.message : __('The screenshot could not be taken.', 'seoprostack'));
				setBusy(false);
			});
			return function () {
				cancelled = true;
			};
		}, [a.url, a.aspectRatio, editing, round]);

		if (editing || !a.url) {
			var draftProblem = draft ? urlProblem(draft) : '';
			return el('div', blockProps,
				el(Placeholder, {
					icon: 'camera',
					label: __('Screenshot', 'seoprostack'),
					instructions: __('Enter the address of the page to capture. The screenshot is saved to the Media Library.', 'seoprostack')
				},
					el('form', {
						style: { display: 'flex', gap: '8px', width: '100%', flexWrap: 'wrap' },
						onSubmit: function (event) {
							event.preventDefault();
							if (draft && !urlProblem(draft)) {
								set({ url: draft.trim() });
								setEditing(false);
							}
						}
					},
						el('div', { style: { flex: '1 1 260px' } },
							el(TextControl, {
								type: 'url',
								value: draft,
								placeholder: 'https://',
								label: __('Page address', 'seoprostack'),
								hideLabelFromVision: true,
								onChange: setDraft,
								__nextHasNoMarginBottom: true
							})
						),
						el(Button, { variant: 'primary', type: 'submit', disabled: !draft || !!draftProblem }, __('Take screenshot', 'seoprostack'))
					),
					draftProblem ? el('p', { className: 'components-placeholder__instructions', style: { color: '#cc1818', marginTop: '8px' } }, draftProblem) : null
				)
			);
		}

		var ratio = parseRatio(a.aspectRatio) || [cfg.width, cfg.height];
		var alt = a.alt || sprintf(__('Screenshot of %s', 'seoprostack'), hostOf(a.url));
		var looks = imageStyle(a);
		var boxStyle = Object.assign({ aspectRatio: ratio[0] + '/' + ratio[1] }, looks);
		var picture;
		if (busy) {
			picture = el('span', { className: 'seoprostack-screenshot__pending', style: boxStyle },
				el(Spinner),
				el('span', {}, sprintf(__('Getting the screenshot of %s. A new one can take up to a minute.', 'seoprostack'), hostOf(a.url)))
			);
		} else if (shot) {
			picture = el('img', { src: shot.src, alt: alt, className: 'seoprostack-screenshot__image', style: looks });
		} else {
			picture = el('span', { className: 'seoprostack-screenshot__pending', style: boxStyle }, hostOf(a.url));
		}

		var linkOptions = [
			{ value: 'page', label: __('The captured page', 'seoprostack') },
			{ value: 'post', label: __('This post', 'seoprostack') },
			{ value: 'custom', label: __('Another address', 'seoprostack') },
			{ value: 'none', label: __('No link', 'seoprostack') }
		];

		return el(Fragment, {},
			el(BlockControls, {},
				el(ToolbarGroup, {},
					el(ToolbarButton, {
						icon: 'edit',
						label: __('Change address', 'seoprostack'),
						onClick: function () {
							setDraft(a.url);
							setEditing(true);
						}
					}),
					cfg.canCapture ? el(ToolbarButton, {
						icon: 'update',
						label: __('Take a new screenshot', 'seoprostack'),
						disabled: busy,
						onClick: function () {
							renew.current = true;
							setRound(round + 1);
						}
					}) : null
				)
			),
			el(InspectorControls, {},
				el(PanelBody, { title: __('Screenshot', 'seoprostack') },
					el(TextareaControl, {
						label: __('Alternative text', 'seoprostack'),
						help: __('Describe the page for people who cannot see it. Leave empty to use “Screenshot of” and the site name.', 'seoprostack'),
						value: a.alt,
						onChange: function (value) { set({ alt: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(SelectControl, {
						label: __('Shape', 'seoprostack'),
						help: __('The browser window’s width is set in Settings → SEO Pro Stack; the shape sets its height. A new shape takes a new screenshot.', 'seoprostack'),
						value: a.aspectRatio,
						options: ratioOptions(a.aspectRatio),
						onChange: function (value) { set({ aspectRatio: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(TextControl, {
						type: 'number',
						label: __('Width on the page (px)', 'seoprostack'),
						help: __('Leave empty to fill the space available.', 'seoprostack'),
						min: 50,
						max: 3840,
						value: a.width ? String(a.width) : '',
						onChange: function (value) { set({ width: Math.max(0, parseInt(value, 10) || 0) }); },
						__nextHasNoMarginBottom: true
					}),
					shot && shot.taken ? el('p', { className: 'components-base-control__help' }, sprintf(__('Taken on %s.', 'seoprostack'), shot.taken)) : null
				),
				el(PanelBody, { title: __('Link', 'seoprostack'), initialOpen: false },
					el(SelectControl, {
						label: __('Link to', 'seoprostack'),
						value: a.linkTo,
						options: linkOptions,
						onChange: function (value) { set({ linkTo: value }); },
						__nextHasNoMarginBottom: true
					}),
					a.linkTo === 'custom' ? el(TextControl, {
						type: 'url',
						label: __('Address', 'seoprostack'),
						value: a.href,
						placeholder: 'https://',
						onChange: function (value) { set({ href: value }); },
						__nextHasNoMarginBottom: true
					}) : null,
					a.linkTo !== 'none' ? el(ToggleControl, {
						label: __('Open in a new tab', 'seoprostack'),
						checked: a.newTab,
						onChange: function (value) { set({ newTab: value }); },
						__nextHasNoMarginBottom: true
					}) : null,
					a.linkTo !== 'none' ? el(ToggleControl, {
						label: __('Add nofollow', 'seoprostack'),
						help: __('Asks search engines not to follow the link.', 'seoprostack'),
						checked: a.nofollow,
						onChange: function (value) { set({ nofollow: value }); },
						__nextHasNoMarginBottom: true
					}) : null,
					a.linkTo !== 'none' ? el(ToggleControl, {
						label: __('Sponsored', 'seoprostack'),
						help: __('Marks a paid, affiliate or sponsored link.', 'seoprostack'),
						checked: a.sponsored,
						onChange: function (value) { set({ sponsored: value }); },
						__nextHasNoMarginBottom: true
					}) : null,
					a.linkTo !== 'none' ? el(ToggleControl, {
						label: __('User-generated', 'seoprostack'),
						help: __('Marks a link added by a visitor, such as in a comment or forum post.', 'seoprostack'),
						checked: a.ugc,
						onChange: function (value) { set({ ugc: value }); },
						__nextHasNoMarginBottom: true
					}) : null
				)
			),
			el('figure', blockProps,
				error ? el(Notice, { status: 'warning', isDismissible: false }, error) : null,
				picture,
				props.isSelected || a.caption ? el(RichText, {
					tagName: 'figcaption',
					className: 'wp-element-caption',
					placeholder: __('Add caption', 'seoprostack'),
					value: a.caption,
					onChange: function (value) { set({ caption: value }); }
				}) : null
			)
		);
	}

	blocks.registerBlockType('seoprostack/screenshot', {
		edit: Edit,
		save: function () {
			return null;
		}
	});

	/**
	 * Screenshot block attributes for a Browser Shots block.
	 */
	function fromLegacy(a) {
		var width = parseInt(a.width, 10) || 600;
		var height = parseInt(a.height, 10) || 450;
		var d = gcd(width, height) || 1;
		var linkTo = 'page';
		if (a.display_link === false) {
			linkTo = 'none';
		} else if (a.post_links || a.link === 'PERMALINK' || a.link === 'http://PERMALINK') {
			linkTo = 'post';
		} else if (a.link) {
			linkTo = 'custom';
		}
		return {
			url: a.url || '',
			alt: a.alt || '',
			caption: a.content || '',
			aspectRatio: (width / d) + '/' + (height / d),
			width: width,
			linkTo: linkTo,
			href: linkTo === 'custom' ? a.link : '',
			newTab: a.target === '_blank',
			nofollow: /(^|\s)nofollow(\s|$)/.test(a.rel || ''),
			sponsored: /(^|\s)sponsored(\s|$)/.test(a.rel || ''),
			ugc: /(^|\s)ugc(\s|$)/.test(a.rel || ''),
			align: a.align || undefined
		};
	}

	function LegacyEdit(props) {
		var blockProps = useBlockProps();
		var replaceBlocks = data.useDispatch('core/block-editor').replaceBlocks;
		var url = props.attributes.url || '';
		return el('div', blockProps,
			el(Placeholder, {
				icon: 'camera',
				label: 'Browser Shots',
				instructions: url
					? sprintf(__('A screenshot of %s. It still shows on the site. Convert it to a Screenshot block to change it.', 'seoprostack'), url)
					: __('An empty Browser Shots block. Convert it to a Screenshot block to add an address.', 'seoprostack')
			},
				el(Button, {
					variant: 'primary',
					onClick: function () {
						replaceBlocks(props.clientId, blocks.createBlock('seoprostack/screenshot', fromLegacy(props.attributes)));
					}
				}, __('Convert to Screenshot block', 'seoprostack'))
			)
		);
	}

	// Registered on the server only while Browser Shots is inactive.
	if (!blocks.getBlockType('browser-shots/browser-shots')) {
		blocks.registerBlockType('browser-shots/browser-shots', {
			title: 'Browser Shots',
			category: 'embed',
			icon: 'camera',
			supports: { inserter: false, html: false, align: ['left', 'center', 'right'] },
			transforms: {
				to: [{
					type: 'block',
					blocks: ['seoprostack/screenshot'],
					transform: function (attributes) {
						return blocks.createBlock('seoprostack/screenshot', fromLegacy(attributes));
					}
				}]
			},
			edit: LegacyEdit,
			save: function () {
				return null;
			}
		});
	}
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.data, window.wp.i18n, window.wp.apiFetch);
