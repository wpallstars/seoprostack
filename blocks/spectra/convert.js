/**
 * Convert Spectra blocks in the editor.
 *
 * With Spectra deactivated, the editor shows its blocks as "missing" blocks
 * that keep their original markup. This adds a Convert button to the ones
 * that have a core equivalent (or the Term list), and a Convert all button.
 * New blocks are made with createBlock(), so core writes markup that is valid
 * for the running WordPress version. Nothing is stored until the post is
 * saved, and saving keeps a revision.
 *
 * Content is read from the saved HTML (what visitors see) through an inert
 * DOMParser document, with Spectra's stored attributes where they add
 * something (media ID, size, alignment, colours).
 *
 * Plain ES5 so the plugin needs no build step.
 */
(function (blocks, element, blockEditor, components, compose, data, hooks, i18n, parser) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var _n = i18n._n;
	var sprintf = i18n.sprintf;
	var createBlock = blocks.createBlock;
	var Notice = components.Notice;

	var REL = ['nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc'];
	var ALIGN = ['left', 'center', 'right'];

	function assign(target) {
		for (var i = 1; i < arguments.length; i++) {
			var source = arguments[i] || {};
			for (var key in source) {
				if (Object.prototype.hasOwnProperty.call(source, key)) {
					target[key] = source[key];
				}
			}
		}
		return target;
	}

	/** Parse HTML without running scripts or loading images. */
	function dom(html) {
		return new window.DOMParser().parseFromString('<!DOCTYPE html><body>' + (html || ''), 'text/html').body;
	}

	function inner(node) {
		return node ? node.innerHTML.trim() : '';
	}

	function cleanRel(rel) {
		var tokens = String(rel || '').toLowerCase().split(/\s+/).filter(function (token, i, all) {
			return REL.indexOf(token) !== -1 && all.indexOf(token) === i;
		});
		return tokens.length ? tokens.join(' ') : undefined;
	}

	function cleanColor(color) {
		color = String(color || '').trim();
		return /^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(color) || /^rgba?\(\s*[0-9.%\s,\/]+\)$/i.test(color) ? color : '';
	}

	/**
	 * Text alignment for a heading or paragraph: an attribute in older
	 * WordPress versions, a typography support in newer ones.
	 */
	function withTextAlign(name, attrs, align) {
		if (ALIGN.indexOf(align) === -1) {
			return attrs;
		}
		var type = blocks.getBlockType(name);
		if (!type) {
			return attrs;
		}
		if (type.attributes && type.attributes.textAlign) {
			attrs.textAlign = align;
		} else if (blocks.hasBlockSupport(name, 'typography.textAlign')) {
			attrs.style = assign({}, attrs.style);
			attrs.style.typography = assign({}, attrs.style.typography, { textAlign: align });
		} else if (type.attributes && type.attributes.align && name === 'core/paragraph') {
			attrs.align = align;
		}
		return attrs;
	}

	function withTextColor(attrs, color) {
		color = cleanColor(color);
		if (color) {
			attrs.style = assign({}, attrs.style, { color: { text: color } });
		}
		return attrs;
	}

	function validAnchor(id) {
		return /^[A-Za-z][\w-]*$/.test(id || '') ? id : undefined;
	}

	var convert = {};

	convert['uagb/advanced-heading'] = function (raw) {
		var root = dom(raw.innerHTML);
		var heading = root.querySelector('.uagb-heading-text');
		if (!heading) {
			return null;
		}
		var a = raw.attrs;
		var level = /^h([1-6])$/i.exec(heading.tagName);
		var attrs = withTextColor({ content: inner(heading) }, a.headingColor);
		var wrap = root.firstElementChild;
		var anchor = validAnchor(wrap && wrap.id);
		if (anchor) {
			attrs.anchor = anchor;
		}
		var name = level ? 'core/heading' : 'core/paragraph';
		if (level) {
			attrs.level = parseInt(level[1], 10);
		}
		var out = [createBlock(name, withTextAlign(name, attrs, a.headingAlign))];

		var desc = inner(root.querySelector('.uagb-desc-text'));
		if (desc) {
			var paragraph = createBlock('core/paragraph', withTextAlign('core/paragraph', withTextColor({ content: desc }, a.subHeadingColor), a.headingAlign));
			if (a.headingDescPosition === 'above-heading') {
				out.unshift(paragraph);
			} else {
				out.push(paragraph);
			}
		}
		return out;
	};

	convert['uagb/image'] = function (raw) {
		var root = dom(raw.innerHTML);
		var img = root.querySelector('img');
		if (!img) {
			return null;
		}
		var a = raw.attrs;
		var attrs = {
			url: a.url || img.getAttribute('src') || '',
			alt: a.alt || img.getAttribute('alt') || '',
			linkDestination: 'none'
		};
		if (!attrs.url) {
			return null;
		}
		if (a.id) {
			attrs.id = parseInt(a.id, 10);
		}
		if (a.sizeSlug) {
			attrs.sizeSlug = String(a.sizeSlug);
		}
		var title = img.getAttribute('title');
		if (title) {
			attrs.title = title;
		}
		var link = root.querySelector('figure a[href], a[href]');
		if (link) {
			attrs.href = link.getAttribute('href');
			attrs.linkDestination = 'custom';
			if (link.getAttribute('target') === '_blank') {
				attrs.linkTarget = '_blank';
			}
			var rel = cleanRel(link.getAttribute('rel'));
			if (rel) {
				attrs.rel = rel;
			}
		}
		var caption = inner(root.querySelector('figcaption'));
		if (caption) {
			attrs.caption = caption;
		}
		if (ALIGN.indexOf(a.align) !== -1) {
			attrs.align = a.align;
		}
		return [createBlock('core/image', attrs)];
	};

	function button(raw) {
		var root = dom(raw.innerHTML);
		var a = raw.attrs;
		var link = root.querySelector('a');
		var text = inner(root.querySelector('.uagb-button__link')) || (link ? inner(link) : '') || a.label || '';
		var attrs = { text: text };
		var url = (link && link.getAttribute('href')) || a.link || '';
		if (url) {
			attrs.url = url;
		}
		if ((link && link.getAttribute('target') === '_blank') || a.opensInNewTab) {
			attrs.linkTarget = '_blank';
		}
		var rel = cleanRel((link ? link.getAttribute('rel') : '') + (a.noFollow ? ' nofollow' : ''));
		if (rel) {
			attrs.rel = rel;
		}
		return createBlock('core/button', attrs);
	}

	function buttons(children, align) {
		var justify = ALIGN.indexOf(align) !== -1 ? align : 'center';
		return createBlock('core/buttons', { layout: { type: 'flex', justifyContent: justify } }, children);
	}

	convert['uagb/buttons'] = function (raw) {
		var children = (raw.innerBlocks || []).filter(function (child) {
			return child.blockName === 'uagb/buttons-child';
		}).map(button);
		return children.length ? [buttons(children, raw.attrs.align)] : null;
	};

	convert['uagb/buttons-child'] = function (raw) {
		return [buttons([button(raw)], 'center')];
	};

	convert['uagb/testimonial'] = function (raw) {
		var root = dom(raw.innerHTML);
		var out = [];
		Array.prototype.forEach.call(root.querySelectorAll('.uagb-testimonial__wrap'), function (item) {
			var text = inner(item.querySelector('.uagb-tm__desc'));
			if (!text) {
				return;
			}
			var name = inner(item.querySelector('.uagb-tm__author-name'));
			var company = inner(item.querySelector('.uagb-tm__company'));
			var inside = [];
			var photo = item.querySelector('.uagb-tm__image img');
			if (photo && photo.getAttribute('src')) {
				inside.push(createBlock('core/image', {
					url: photo.getAttribute('src'),
					alt: photo.getAttribute('alt') || '',
					sizeSlug: 'thumbnail',
					className: 'is-style-rounded',
					linkDestination: 'none'
				}));
			}
			inside.push(createBlock('core/paragraph', { content: text }));
			out.push(createBlock('core/quote', { citation: [name, company].filter(Boolean).join(', ') }, inside));
		});
		return out.length ? out : null;
	};

	convert['uagb/taxonomy-list'] = function (raw) {
		if (!blocks.getBlockType('seoprostack/term-list')) {
			return null;
		}
		var a = raw.attrs;
		var layout = a.layout === 'list' ? 'list' : 'grid';
		if (layout === 'list' && a.listDisplayStyle === 'dropdown') {
			layout = 'dropdown';
		}
		var list = layout === 'list';
		var attrs = {
			taxonomy: a.taxonomyType || 'category',
			layout: layout,
			showCount: a.showCount === undefined ? true : !!a.showCount,
			showEmpty: !!a.showEmptyTaxonomy,
			showChildren: list && !!a.showhierarchy,
			columns: a.columns ? parseInt(a.columns, 10) : 3,
			marker: list ? (a.listStyle || 'disc') : '',
			linkColor: cleanColor(list ? a.listTextColor : a.titleColor),
			linkHoverColor: list ? cleanColor(a.hoverlistTextColor) : '',
			emptyText: a.noTaxDisplaytext || ''
		};
		var gap = list ? a.listBottomMargin : a.rowGap;
		if (gap !== undefined && gap !== '' && !isNaN(gap)) {
			attrs.gap = parseInt(gap, 10);
		}
		if (a.className) {
			attrs.className = a.className;
		}
		return [createBlock('seoprostack/term-list', attrs)];
	};

	/** The original Spectra block behind a missing block, as parsed markup. */
	function rawOf(block) {
		var original = block.attributes.originalContent || '';
		var parsed = parser.parse(original);
		for (var i = 0; i < parsed.length; i++) {
			if (parsed[i].blockName === block.attributes.originalName) {
				parsed[i].attrs = parsed[i].attrs || {};
				return parsed[i];
			}
		}
		return null;
	}

	function convertible(block) {
		return block && block.name === 'core/missing' && Object.prototype.hasOwnProperty.call(convert, block.attributes.originalName || '');
	}

	/** New blocks for a missing Spectra block, or null. */
	function replacement(block) {
		var raw = rawOf(block);
		if (!raw) {
			return null;
		}
		try {
			var out = convert[raw.blockName](raw);
			return out && out.length ? out : null;
		} catch (e) {
			return null;
		}
	}

	/** Every convertible block, not looking inside the ones found. */
	function findAll(list, found) {
		found = found || [];
		(list || []).forEach(function (block) {
			if (convertible(block)) {
				found.push(block);
			} else {
				findAll(block.innerBlocks, found);
			}
		});
		return found;
	}

	function run(targets) {
		var editor = data.dispatch('core/block-editor');
		var notices = data.dispatch('core/notices');
		var done = 0;
		var failed = 0;
		targets.forEach(function (block) {
			var out = replacement(block);
			if (out) {
				editor.replaceBlocks(block.clientId, out);
				done++;
			} else {
				failed++;
			}
		});
		if (done) {
			notices.createNotice('success', sprintf(
				/* translators: %d: number of blocks */
				_n('Converted %d Spectra block. Check it, then save.', 'Converted %d Spectra blocks. Check them, then save.', done, 'seoprostack'),
				done
			), { type: 'snackbar' });
		}
		if (failed) {
			notices.createNotice('warning', sprintf(
				/* translators: %d: number of blocks */
				_n('%d Spectra block could not be converted and is left as it is.', '%d Spectra blocks could not be converted and are left as they are.', failed, 'seoprostack'),
				failed
			), { type: 'snackbar' });
		}
	}

	var withConvert = compose.createHigherOrderComponent(function (BlockEdit) {
		return function (props) {
			if (props.name !== 'core/missing' || !convertible({ name: props.name, attributes: props.attributes })) {
				return el(BlockEdit, props);
			}
			var all = findAll(data.select('core/block-editor').getBlocks());
			var actions = [{
				label: __('Convert', 'seoprostack'),
				variant: 'primary',
				onClick: function () {
					var block = data.select('core/block-editor').getBlock(props.clientId);
					if (block) {
						run([block]);
					}
				}
			}];
			if (all.length > 1) {
				actions.push({
					/* translators: %d: number of blocks */
					label: sprintf(__('Convert all %d', 'seoprostack'), all.length),
					variant: 'secondary',
					onClick: function () {
						run(findAll(data.select('core/block-editor').getBlocks()));
					}
				});
			}
			return el(Fragment, {},
				el(Notice, { status: 'info', isDismissible: false, actions: actions, className: 'sps-spectra-convert' },
					__('This Spectra block can be converted to a core block (Spectra’s Taxonomy List becomes a Term list). Nothing changes until you save.', 'seoprostack')
				),
				el(BlockEdit, props)
			);
		};
	}, 'withSpectraConvert');

	hooks.addFilter('editor.BlockEdit', 'seoprostack/spectra-convert', withConvert);
})(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.compose,
	window.wp.data,
	window.wp.hooks,
	window.wp.i18n,
	window.wp.blockSerializationDefaultParser
);
