/**
 * allstars/iframe editor script.
 *
 * Plain ES5 with wp.element.createElement so the plugin needs no build step.
 * The saved content is empty: render.php builds the iframe on the server.
 */
(function (blocks, element, blockEditor, components, i18n) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var useState = element.useState;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var BlockControls = blockEditor.BlockControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;
	var CheckboxControl = components.CheckboxControl;
	var Placeholder = components.Placeholder;
	var Button = components.Button;
	var Notice = components.Notice;
	var ToolbarGroup = components.ToolbarGroup;
	var ToolbarButton = components.ToolbarButton;

	var cfg = window.allstarsIframe || { domains: [] };

	var SANDBOX = [
		['allow-scripts', __('Run scripts', 'allstars')],
		['allow-same-origin', __('Treat as its own site (cookies, storage)', 'allstars')],
		['allow-forms', __('Submit forms', 'allstars')],
		['allow-popups', __('Open pop-ups', 'allstars')],
		['allow-popups-to-escape-sandbox', __('Pop-ups without restrictions', 'allstars')],
		['allow-presentation', __('Presentation mode', 'allstars')],
		['allow-modals', __('Alerts and dialogs', 'allstars')],
		['allow-downloads', __('Downloads', 'allstars')],
		['allow-top-navigation-by-user-activation', __('Navigate this page when clicked', 'allstars')]
	];

	var ALLOW = [
		['autoplay', __('Autoplay', 'allstars')],
		['camera', __('Camera', 'allstars')],
		['microphone', __('Microphone', 'allstars')],
		['geolocation', __('Location', 'allstars')],
		['clipboard-write', __('Copy to clipboard', 'allstars')],
		['encrypted-media', __('Protected media', 'allstars')],
		['picture-in-picture', __('Picture-in-picture', 'allstars')],
		['payment', __('Payments', 'allstars')],
		['web-share', __('Share', 'allstars')]
	];

	var RATIOS = [
		{ value: '', label: __('Fixed size', 'allstars') },
		{ value: '16/9', label: '16:9' },
		{ value: '4/3', label: '4:3' },
		{ value: '3/2', label: '3:2' },
		{ value: '1/1', label: '1:1' },
		{ value: '9/16', label: '9:16' },
		{ value: '21/9', label: '21:9' }
	];

	var REFERRER = [
		'strict-origin-when-cross-origin',
		'no-referrer',
		'no-referrer-when-downgrade',
		'origin',
		'origin-when-cross-origin',
		'same-origin',
		'strict-origin',
		'unsafe-url'
	].map(function (value) {
		return { value: value, label: value };
	});

	/**
	 * Check a URL against the allow-list. Returns an error message or ''.
	 */
	function urlProblem(url) {
		var parsed;
		try {
			parsed = new URL(url);
		} catch (e) {
			return __('Enter a full web address starting with https://', 'allstars');
		}
		if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
			return __('Only http and https addresses can be embedded.', 'allstars');
		}
		if (cfg.domains && cfg.domains.length) {
			var host = parsed.hostname.toLowerCase().replace(/^www\./, '');
			var ok = cfg.domains.some(function (domain) {
				return host === domain || host.slice(-(domain.length + 1)) === '.' + domain;
			});
			if (!ok) {
				return __('This domain is not on the allowed list in Settings → Allstars, so it will not be shown.', 'allstars');
			}
		}
		return '';
	}

	function toggleIn(list, value, on) {
		var next = (list || []).filter(function (item) {
			return item !== value;
		});
		if (on) {
			next.push(value);
		}
		return next;
	}

	function checkboxes(options, selected, onChange) {
		return options.map(function (option) {
			return el(CheckboxControl, {
				key: option[0],
				label: option[1],
				checked: (selected || []).indexOf(option[0]) !== -1,
				onChange: function (on) {
					onChange(toggleIn(selected, option[0], on));
				},
				__nextHasNoMarginBottom: true
			});
		});
	}

	function Edit(props) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = useBlockProps();
		var draftState = useState(a.url);
		var draft = draftState[0];
		var setDraft = draftState[1];
		var editingState = useState(!a.url);
		var editing = editingState[0];
		var setEditing = editingState[1];

		var problem = a.url ? urlProblem(a.url) : '';

		if (editing || !a.url) {
			var draftProblem = draft ? urlProblem(draft) : '';
			return el('div', blockProps,
				el(Placeholder, {
					icon: 'embed-generic',
					label: __('iFrame', 'allstars'),
					instructions: __('Paste the address of the page to embed.', 'allstars')
				},
					el('form', {
						className: 'allstars-iframe-url-form',
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
								label: __('Page address', 'allstars'),
								hideLabelFromVision: true,
								onChange: setDraft,
								__nextHasNoMarginBottom: true
							})
						),
						el(Button, { variant: 'primary', type: 'submit', disabled: !draft || !!draftProblem }, __('Embed', 'allstars'))
					),
					draftProblem ? el('p', { className: 'components-placeholder__instructions', style: { color: '#cc1818', marginTop: '8px' } }, draftProblem) : null
				)
			);
		}

		var frameStyle = a.aspectRatio
			? { width: '100%', height: 'auto', aspectRatio: a.aspectRatio }
			: { width: /%$/.test(a.width) ? a.width : parseInt(a.width, 10) + 'px', height: a.height + 'px', maxWidth: '100%' };

		return el(Fragment, {},
			el(BlockControls, {},
				el(ToolbarGroup, {},
					el(ToolbarButton, {
						icon: 'edit',
						label: __('Change address', 'allstars'),
						onClick: function () {
							setDraft(a.url);
							setEditing(true);
						}
					})
				)
			),
			el(InspectorControls, {},
				el(PanelBody, { title: __('Page', 'allstars') },
					el(TextControl, {
						label: __('Title', 'allstars'),
						help: __('Describes the embed for screen readers.', 'allstars'),
						value: a.title,
						onChange: function (value) { set({ title: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(ToggleControl, {
						label: __('Pass page URL parameters', 'allstars'),
						help: __('Adds this page’s query string (for example UTM tags) to the embedded address.', 'allstars'),
						checked: a.passParams,
						onChange: function (value) { set({ passParams: value }); },
						__nextHasNoMarginBottom: true
					})
				),
				el(PanelBody, { title: __('Size', 'allstars') },
					el(SelectControl, {
						label: __('Aspect ratio', 'allstars'),
						value: a.aspectRatio,
						options: RATIOS,
						onChange: function (value) { set({ aspectRatio: value }); },
						__nextHasNoMarginBottom: true
					}),
					a.aspectRatio ? null : el(TextControl, {
						label: __('Width', 'allstars'),
						help: __('Pixels (e.g. 600) or a percentage (e.g. 100%).', 'allstars'),
						value: a.width,
						onChange: function (value) { set({ width: value }); },
						__nextHasNoMarginBottom: true
					}),
					a.aspectRatio ? null : el(TextControl, {
						type: 'number',
						label: __('Height (px)', 'allstars'),
						min: 50,
						max: 5000,
						value: a.height,
						onChange: function (value) { set({ height: parseInt(value, 10) || 500 }); },
						__nextHasNoMarginBottom: true
					}),
					el(ToggleControl, {
						label: __('Show border', 'allstars'),
						checked: a.showBorder,
						onChange: function (value) { set({ showBorder: value }); },
						__nextHasNoMarginBottom: true
					})
				),
				el(PanelBody, { title: __('Loading and permissions', 'allstars'), initialOpen: false },
					el(ToggleControl, {
						label: __('Lazy load', 'allstars'),
						help: __('Load the page only when it scrolls into view.', 'allstars'),
						checked: a.lazy,
						onChange: function (value) { set({ lazy: value }); },
						__nextHasNoMarginBottom: true
					}),
					el(ToggleControl, {
						label: __('Allow full screen', 'allstars'),
						checked: a.allowFullscreen,
						onChange: function (value) { set({ allowFullscreen: value }); },
						__nextHasNoMarginBottom: true
					}),
					el('p', { style: { fontWeight: 600, margin: '16px 0 8px' } }, __('Allow the embedded page to use', 'allstars')),
					checkboxes(ALLOW, a.allow, function (value) { set({ allow: value }); }),
					el(SelectControl, {
						label: __('Referrer policy', 'allstars'),
						help: __('How much of this page’s address is sent to the embedded site.', 'allstars'),
						value: a.referrerPolicy,
						options: REFERRER,
						onChange: function (value) { set({ referrerPolicy: value }); },
						__nextHasNoMarginBottom: true
					})
				),
				el(PanelBody, { title: __('Sandbox', 'allstars'), initialOpen: false },
					el(ToggleControl, {
						label: __('Sandbox the embedded page', 'allstars'),
						help: a.sandbox
							? __('Recommended. The page can only do what is ticked below.', 'allstars')
							: __('The embedded page is not restricted. Only turn this off for sites you trust.', 'allstars'),
						checked: a.sandbox,
						onChange: function (value) { set({ sandbox: value }); },
						__nextHasNoMarginBottom: true
					}),
					a.sandbox ? checkboxes(SANDBOX, a.sandboxAllow, function (value) { set({ sandboxAllow: value }); }) : null
				)
			),
			el('figure', blockProps,
				problem ? el(Notice, { status: 'warning', isDismissible: false }, problem) : null,
				el('div', { style: { position: 'relative' } },
					el('iframe', {
						src: a.url,
						title: a.title || __('Embedded content', 'allstars'),
						className: 'wp-block-allstars-iframe__frame' + (a.showBorder ? ' has-border' : ''),
						style: frameStyle,
						sandbox: 'allow-scripts allow-same-origin',
						loading: 'lazy',
						referrerPolicy: a.referrerPolicy
					}),
					// Clicks select the block instead of interacting with the page.
					props.isSelected ? null : el('div', { style: { position: 'absolute', inset: 0 } })
				)
			)
		);
	}

	blocks.registerBlockType('allstars/iframe', {
		edit: Edit,
		save: function () {
			return null;
		}
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
