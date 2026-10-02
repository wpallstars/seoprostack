/**
 * Word documents in the editor: drop or paste a .docx file, or choose one
 * from the Options menu. A short-lived "Word document" block sends the file
 * to the server, which returns HTML; wp.blocks.rawHandler() turns that into
 * core blocks, which replace it.
 *
 * Plain ES5 with wp.element.createElement so the plugin needs no build step.
 */
(function (wp, cfg) {
	'use strict';

	if (!wp || !cfg || !wp.blocks || !wp.element || !wp.data) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var _n = wp.i18n._n;
	var BLOCK = 'seoprostack/word-document';
	var files = {};
	var count = 0;

	function isDocx(file) {
		return !!file && /\.docx$/i.test(file.name || '');
	}

	/** Keep the file in memory; blocks only hold a key to it. */
	function blockFor(file) {
		var key = 'doc' + (++count) + '-' + Date.now();
		files[key] = file;
		return wp.blocks.createBlock(BLOCK, { key: key, name: file.name });
	}

	function postId() {
		var editor = wp.data.select('core/editor');
		return editor && editor.getCurrentPostId ? editor.getCurrentPostId() || 0 : 0;
	}

	function convert(file) {
		var body = new window.FormData();
		body.append('file', file, file.name);
		body.append('post', String(postId()));
		return wp.apiFetch({ path: '/seoprostack/v1/word-document', method: 'POST', body: body });
	}

	function Edit(props) {
		var blockProps = wp.blockEditor.useBlockProps();
		var errorState = useState('');
		var error = errorState[0];
		var setError = errorState[1];
		var key = props.attributes.key;
		var name = props.attributes.name;

		function remove() {
			wp.data.dispatch('core/block-editor').removeBlocks([props.clientId]);
		}

		useEffect(function () {
			var file = files[key];
			delete files[key];
			if (!file) {
				setError(__('Drop the document into the editor again to convert it.', 'seoprostack'));
				return;
			}
			if (!cfg.canRead) {
				setError(__('This server cannot open Word documents: ask your host to turn on PHP’s zip extension.', 'seoprostack'));
				return;
			}
			if (cfg.maxBytes && file.size > cfg.maxBytes) {
				setError(__('The document is larger than your site accepts.', 'seoprostack'));
				return;
			}
			convert(file).then(function (res) {
				var blocks = res && res.html ? wp.blocks.rawHandler({ HTML: res.html }) : [];
				if (!blocks.length) {
					setError(__('The document has no text to add.', 'seoprostack'));
					return;
				}
				wp.data.dispatch('core/block-editor').replaceBlocks(props.clientId, blocks);
				if (res.failedImages) {
					wp.data.dispatch('core/notices').createWarningNotice(
						sprintf(
							/* translators: %d: number of pictures */
							_n('%d picture in the document could not be added.', '%d pictures in the document could not be added.', res.failedImages, 'seoprostack'),
							res.failedImages
						),
						{ type: 'snackbar' }
					);
				}
			}, function (err) {
				setError(err && err.message ? err.message : __('The document could not be converted.', 'seoprostack'));
			});
		}, []);

		if (error) {
			return el('div', blockProps,
				el(wp.components.Notice, {
					status: 'error',
					isDismissible: false,
					actions: [{ label: __('Remove', 'seoprostack'), onClick: remove }]
				}, (name ? name + ': ' : '') + error)
			);
		}
		return el('div', blockProps,
			el(wp.components.Placeholder, { icon: 'media-document', label: __('Word document', 'seoprostack') },
				el(wp.components.Spinner),
				/* translators: %s: file name */
				sprintf(__('Converting %s…', 'seoprostack'), name)
			)
		);
	}

	wp.blocks.registerBlockType(BLOCK, {
		apiVersion: 2,
		title: __('Word document', 'seoprostack'),
		description: __('Converts a Word document into blocks.', 'seoprostack'),
		category: 'text',
		icon: 'media-document',
		attributes: {
			key: { type: 'string', default: '' },
			name: { type: 'string', default: '' }
		},
		supports: { inserter: false, html: false, reusable: false },
		edit: Edit,
		save: function () {
			return null;
		},
		transforms: {
			from: [{
				type: 'files',
				// Before core's File block (15), so documents are converted, not attached.
				priority: 5,
				isMatch: function (list) {
					return list.length > 0 && Array.prototype.every.call(list, isDocx);
				},
				transform: function (list) {
					return Array.prototype.map.call(list, blockFor);
				}
			}]
		}
	});

	/* "Word document" in the editor's Options menu. */
	var MoreMenuItem = (wp.editor && wp.editor.PluginMoreMenuItem) || (wp.editPost && wp.editPost.PluginMoreMenuItem);
	if (!MoreMenuItem || !wp.plugins) {
		return;
	}

	function pick() {
		var input = document.createElement('input');
		input.type = 'file';
		input.accept = '.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		input.multiple = true;
		input.addEventListener('change', function () {
			var chosen = Array.prototype.filter.call(input.files || [], isDocx);
			if (!chosen.length) {
				return;
			}
			var editor = wp.data.select('core/block-editor');
			var point = editor.getBlockInsertionPoint ? editor.getBlockInsertionPoint() : {};
			wp.data.dispatch('core/block-editor').insertBlocks(chosen.map(blockFor), point.index, point.rootClientId);
		});
		input.click();
	}

	wp.plugins.registerPlugin('seoprostack-word-import', {
		render: function () {
			return el(MoreMenuItem, { icon: 'media-document', onClick: pick }, __('Word document', 'seoprostack'));
		}
	});
}(window.wp, window.seoprostackWordImport));
