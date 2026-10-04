/**
 * seoprostack/link-card editor script, and the editor side of Bookmark
 * Card's block (mamaduka/bookmark-card), which can be converted.
 *
 * Plain ES5 with wp.element.createElement so the plugin needs no build step.
 * The saved content is empty: render.php builds the card on the server.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 */
(function (blocks, element, blockEditor, components, data, i18n, apiFetch, url) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var BlockControls = blockEditor.BlockControls;
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

	var cfg = window.seoprostackLinkCards || { canUpload: false };

	function hostOf(address) {
		try {
			return new URL(address).hostname.replace(/^www\./i, '');
		} catch (e) {
			return '';
		}
	}

	function validUrl(address) {
		try {
			var parsed = new URL(address);
			return parsed.protocol === 'https:' || parsed.protocol === 'http:';
		} catch (e) {
			return false;
		}
	}

	/** Titles and descriptions may arrive with HTML entities. */
	function decode(text) {
		var area = document.createElement('textarea');
		area.innerHTML = String(text || '');
		return area.value.trim();
	}

	function currentPostId() {
		var editor = data.select('core/editor');
		return editor && editor.getCurrentPostId ? editor.getCurrentPostId() || 0 : 0;
	}

	/** Copy a picture into the Media Library. Resolves to { id, src }. */
	function copyImage(image, page) {
		return apiFetch({
			path: '/seoprostack/v1/link-card-image',
			method: 'POST',
			data: { image: image, page: page, post: currentPostId() }
		});
	}

	function Card(props) {
		var a = props.attributes;
		var position = a.mediaPosition || 'right';
		var hasImage = position !== 'none' && props.imageSrc;
		return el('span', { className: 'wp-block-seoprostack-link-card__link' },
			hasImage ? el('span', { className: 'wp-block-seoprostack-link-card__media' }, el('img', { src: props.imageSrc, alt: '' })) : null,
			el('span', { className: 'wp-block-seoprostack-link-card__body' },
				el('span', { className: 'wp-block-seoprostack-link-card__title' }, a.title || a.url),
				a.description ? el('span', { className: 'wp-block-seoprostack-link-card__description' }, a.description) : null,
				el('span', { className: 'wp-block-seoprostack-link-card__site' }, a.siteName || hostOf(a.url))
			)
		);
	}

	/** The wrapper class render_block() sets: where the picture goes. */
	function mediaClass(a, imageSrc) {
		var position = a.mediaPosition || 'right';
		return 'is-media-' + (position !== 'none' && imageSrc ? position : 'none');
	}

	function Edit(props) {
		var a = props.attributes;
		var set = props.setAttributes;
		var editingState = useState(!a.url);
		var editing = editingState[0];
		var setEditing = editingState[1];
		var draftState = useState(a.url);
		var draft = draftState[0];
		var setDraft = draftState[1];
		var busyState = useState('');
		var busy = busyState[0];
		var setBusy = busyState[1];
		var errorState = useState('');
		var error = errorState[0];
		var setError = errorState[1];
		var tried = useRef(false);

		var imageSrc = data.useSelect(function (select) {
			if (!a.imageId) {
				return '';
			}
			var media = select('core').getMedia(a.imageId);
			if (!media) {
				return '';
			}
			var sizes = media.media_details && media.media_details.sizes;
			return sizes && sizes.medium ? sizes.medium.source_url : media.source_url;
		}, [a.imageId]);
		var blockProps = useBlockProps({ className: mediaClass(a, imageSrc) });

		function fetchImage(image, page) {
			if (!image || !cfg.canUpload) {
				return Promise.resolve();
			}
			setBusy(__('Copying the picture to your Media Library…', 'seoprostack'));
			return copyImage(image, page).then(function (res) {
				set({ imageId: res.id || 0 });
			}, function (err) {
				setError(err && err.message ? err.message : __('The picture could not be copied.', 'seoprostack'));
			});
		}

		/* A converted Bookmark Card brings its picture's address: copy it once. */
		useEffect(function () {
			if (a.imageSource && !a.imageId && !tried.current && cfg.canUpload) {
				tried.current = true;
				fetchImage(a.imageSource, a.url).then(function () {
					setBusy('');
				});
			}
		}, [a.imageSource, a.imageId]);

		function load(address) {
			address = String(address || '').trim();
			if (!validUrl(address)) {
				setError(__('Enter a full web address starting with https://', 'seoprostack'));
				return;
			}
			setError('');
			setBusy(__('Reading the page…', 'seoprostack'));
			set({ url: address });
			setEditing(false);
			apiFetch({ path: url.addQueryArgs('/wp-block-editor/v1/url-details', { url: address }) }).then(function (res) {
				var image = res && res.image ? String(res.image) : '';
				set({
					title: decode(res && res.title),
					description: decode(res && res.description),
					siteName: hostOf(address),
					imageSource: image,
					imageId: 0
				});
				tried.current = true;
				return fetchImage(image, address);
			}, function (err) {
				setError((err && err.message ? err.message + ' ' : '') + __('Fill in the title and description in the block settings.', 'seoprostack'));
				set({ siteName: hostOf(address) });
			}).then(function () {
				setBusy('');
			});
		}

		var inspector = el(InspectorControls, null,
			el(PanelBody, { title: __('Card', 'seoprostack') },
				el(TextControl, { label: __('Address', 'seoprostack'), value: a.url, type: 'url', onChange: function (v) { set({ url: v }); } }),
				el(Button, { variant: 'secondary', disabled: !!busy || !validUrl(a.url), onClick: function () { load(a.url); } }, __('Read the page again', 'seoprostack')),
				el('div', { style: { height: 16 } }),
				el(TextControl, { label: __('Title', 'seoprostack'), value: a.title, onChange: function (v) { set({ title: v }); } }),
				el(TextareaControl, { label: __('Description', 'seoprostack'), value: a.description, onChange: function (v) { set({ description: v }); } }),
				el(TextControl, { label: __('Site name', 'seoprostack'), value: a.siteName, placeholder: hostOf(a.url), onChange: function (v) { set({ siteName: v }); } }),
				el(SelectControl, {
					label: __('Picture', 'seoprostack'),
					value: a.mediaPosition || 'right',
					options: [
						{ value: 'right', label: __('On the right', 'seoprostack') },
						{ value: 'left', label: __('On the left', 'seoprostack') },
						{ value: 'top', label: __('Above', 'seoprostack') },
						{ value: 'none', label: __('None', 'seoprostack') }
					],
					help: __('Beside the text when the card is wide enough.', 'seoprostack'),
					onChange: function (v) { set({ mediaPosition: v }); }
				}),
				a.imageSource && !a.imageId && cfg.canUpload ? el(Button, { variant: 'secondary', disabled: !!busy, onClick: function () { fetchImage(a.imageSource, a.url).then(function () { setBusy(''); }); } }, __('Copy the picture', 'seoprostack')) : null
			),
			el(PanelBody, { title: __('Link', 'seoprostack'), initialOpen: false },
				el(ToggleControl, { label: __('Open in a new tab', 'seoprostack'), checked: !!a.newTab, onChange: function (v) { set({ newTab: v }); } }),
				el(ToggleControl, { label: __('Ask search engines not to follow (nofollow)', 'seoprostack'), checked: !!a.nofollow, onChange: function (v) { set({ nofollow: v }); } })
			)
		);

		var notices = [
			busy ? el('p', { key: 'busy', className: 'components-placeholder__instructions' }, el(Spinner), ' ', busy) : null,
			error ? el(Notice, { key: 'error', status: 'warning', isDismissible: true, onRemove: function () { setError(''); } }, error) : null,
			a.imageSource && !a.imageId && !cfg.canUpload ? el(Notice, { key: 'upload', status: 'info', isDismissible: false }, __('Someone who can upload files needs to open this card to add its picture.', 'seoprostack')) : null
		];

		if (editing || !a.url) {
			return el('div', blockProps,
				el(Placeholder, { icon: 'id-alt', label: __('Link card', 'seoprostack'), instructions: __('Paste the address of a page to show a preview card.', 'seoprostack'), className: 'is-placeholder' },
					el('form', {
						style: { display: 'flex', gap: 8, width: '100%', flexWrap: 'wrap' },
						onSubmit: function (e) { e.preventDefault(); load(draft); }
					},
						el('div', { style: { flex: '1 1 260px' } },
							el(TextControl, { value: draft, type: 'url', placeholder: 'https://', 'aria-label': __('Page address', 'seoprostack'), onChange: setDraft, __nextHasNoMarginBottom: true })
						),
						el(Button, { variant: 'primary', type: 'submit' }, __('Show card', 'seoprostack')),
						a.url ? el(Button, { variant: 'tertiary', onClick: function () { setEditing(false); } }, __('Cancel', 'seoprostack')) : null
					),
					notices
				)
			);
		}

		return el(Fragment, null,
			inspector,
			el(BlockControls, null,
				el(ToolbarGroup, null,
					el(ToolbarButton, { icon: 'edit', label: __('Change address', 'seoprostack'), onClick: function () { setDraft(a.url); setEditing(true); } })
				)
			),
			el('div', blockProps,
				notices,
				el(Card, { attributes: a, imageSrc: imageSrc })
			)
		);
	}

	blocks.registerBlockType('seoprostack/link-card', {
		edit: Edit,
		save: function () {
			return null;
		},
		transforms: {
			from: [{
				type: 'block',
				blocks: ['core/embed'],
				transform: function (attrs) {
					return blocks.createBlock('seoprostack/link-card', { url: attrs.url || '' });
				}
			}]
		}
	});

	/*
	 * ------------------------------------------------------------------
	 * Bookmark Card (mamaduka/bookmark-card), while Bookmark Card is inactive.
	 * Its saves must match exactly, or WordPress reports the block as broken.
	 * ------------------------------------------------------------------
	 */

	var LEGACY = 'mamaduka/bookmark-card';
	if (blocks.getBlockType(LEGACY)) {
		return;
	}

	var legacyAttributes = {
		url: { type: 'string', default: '' },
		title: { type: 'string', source: 'text', selector: '.bookmark-card__title', default: '' },
		description: { type: 'string', source: 'text', selector: '.bookmark-card__description', default: '' },
		image: { type: 'string', source: 'attribute', selector: '.bookmark-card__image img', attribute: 'src', default: '' },
		icon: { type: 'string', source: 'attribute', selector: '.bookmark_card__meta-icon', attribute: 'src', default: '' },
		publisher: { type: 'string', source: 'text', selector: '.bookmark_card__meta-publisher', default: '' },
		mediaPosition: { type: 'string', default: 'right' }
	};
	var linkAttributes = {
		linkTarget: { type: 'string' },
		rel: { type: 'string', source: 'attribute', selector: 'figure > a', attribute: 'rel' }
	};
	var legacySupports = {
		html: false,
		reusable: false,
		inserter: false,
		__experimentalBorder: { radius: true, __experimentalDefaultControls: { radius: true } }
	};

	function extend(base, more) {
		var out = {};
		var key;
		for (key in base) {
			if (Object.prototype.hasOwnProperty.call(base, key)) {
				out[key] = base[key];
			}
		}
		for (key in more) {
			if (Object.prototype.hasOwnProperty.call(more, key)) {
				out[key] = more[key];
			}
		}
		return out;
	}

	/**
	 * Bookmark Card's save, by version: 3 is 2.x (alt text, media position),
	 * 2 added link target and rel, 1 is the first.
	 */
	function legacySave(version) {
		return function (props) {
			var a = props.attributes;
			var wrapper = version >= 3 ? blockEditor.useBlockProps.save({ className: a.mediaPosition === 'left' ? 'has-media-on-the-left' : undefined }) : blockEditor.useBlockProps.save();
			var link = { className: 'bookmark-card', href: a.url };
			if (version >= 2) {
				link.target = a.linkTarget;
				link.rel = a.rel;
			}
			var img = version >= 3 ? { src: a.image, alt: a.title } : { src: a.image };
			var icon = version >= 3 ? { className: 'bookmark_card__meta-icon', src: a.icon, alt: '', role: 'presentation' } : { className: 'bookmark_card__meta-icon', src: a.icon };
			return el('figure', wrapper,
				el('a', link,
					a.image ? el('div', { className: 'bookmark-card__image' }, el('img', img)) : null,
					el('div', { className: 'bookmark-card__content' },
						el('div', { className: 'bookmark-card__title' }, a.title),
						el('div', { className: 'bookmark-card__description' }, a.description),
						el('div', { className: 'bookmark_card__meta' },
							a.icon ? el('img', icon) : null,
							el('span', { className: 'bookmark_card__meta-publisher' }, a.publisher)
						)
					)
				)
			);
		};
	}

	/** Link card attributes for a Bookmark Card. */
	function fromLegacy(a) {
		var horizontal = /(^|\s)is-style-horizontal(\s|$)/.test(a.className || '');
		return {
			url: a.url || '',
			title: a.title || '',
			description: a.description || '',
			siteName: a.publisher || '',
			imageSource: a.image || '',
			mediaPosition: horizontal ? (a.mediaPosition === 'left' ? 'left' : 'right') : 'top',
			newTab: a.linkTarget === '_blank',
			nofollow: /(^|\s)nofollow(\s|$)/.test(a.rel || '')
		};
	}

	function LegacyEdit(props) {
		var blockProps = useBlockProps({ className: 'is-media-none' });
		var replace = data.useDispatch('core/block-editor').replaceBlocks;
		var a = props.attributes;
		return el('div', blockProps,
			el(Notice, { status: 'info', isDismissible: false },
				__('This is a Bookmark Card. Convert it to a Link card to keep its picture on your site.', 'seoprostack'),
				' ',
				el(Button, { variant: 'primary', onClick: function () { replace(props.clientId, blocks.createBlock('seoprostack/link-card', fromLegacy(a))); } }, __('Convert to Link card', 'seoprostack'))
			),
			el(Card, { attributes: { url: a.url, title: a.title, description: a.description, siteName: a.publisher, mediaPosition: 'right' }, imageSrc: '' })
		);
	}

	blocks.registerBlockType(LEGACY, {
		apiVersion: 3,
		title: 'Bookmark Card',
		category: 'embed',
		icon: 'id-alt',
		attributes: extend(legacyAttributes, linkAttributes),
		supports: legacySupports,
		styles: [
			{ name: 'default', label: __('Default', 'seoprostack'), isDefault: true },
			{ name: 'horizontal', label: __('Horizontal', 'seoprostack') }
		],
		edit: LegacyEdit,
		save: legacySave(3),
		deprecated: [
			{ attributes: extend(legacyAttributes, linkAttributes), supports: legacySupports, save: legacySave(2) },
			{ attributes: legacyAttributes, supports: legacySupports, save: legacySave(1) }
		],
		transforms: {
			to: [{
				type: 'block',
				blocks: ['seoprostack/link-card'],
				transform: function (a) {
					return blocks.createBlock('seoprostack/link-card', fromLegacy(a));
				}
			}]
		}
	});
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.data, window.wp.i18n, window.wp.apiFetch, window.wp.url));
