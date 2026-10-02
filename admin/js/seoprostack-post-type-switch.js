/**
 * Change post type: a Post type choice in the block editor's summary panel.
 * Saves unsaved changes first, asks, changes the type, then reloads the
 * editor at the post's new address.
 */
(function (wp, cfg) {
    if (!wp || !cfg || !wp.plugins || !wp.element || !wp.components) {
        return;
    }
    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var Info = (wp.editor && wp.editor.PluginPostStatusInfo) || (wp.editPost && wp.editPost.PluginPostStatusInfo);
    if (!Info) {
        return;
    }

    function labelOf(value) {
        for (var i = 0; i < cfg.options.length; i++) {
            if (cfg.options[i].value === value) {
                return cfg.options[i].label;
            }
        }
        return value;
    }

    function change(type, setBusy, setError) {
        // eslint-disable-next-line no-alert
        if (!window.confirm(cfg.confirm.replace('%s', labelOf(type)))) {
            return;
        }
        setBusy(true);
        setError('');
        var editor = wp.data.select('core/editor');
        var saved = editor.isEditedPostDirty() ? wp.data.dispatch('core/editor').savePost() : Promise.resolve();
        Promise.resolve(saved)
            .then(function () {
                if (wp.data.select('core/editor').didPostSaveRequestFail()) {
                    throw new Error('save');
                }
                var body = new FormData();
                body.append('action', cfg.action);
                body.append('nonce', cfg.nonce);
                body.append('post', cfg.post);
                body.append('type', type);
                return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' });
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success || !res.data || !res.data.url) {
                    throw new Error('switch');
                }
                // Leave without the "unsaved changes" prompt: everything is saved.
                window.onbeforeunload = null;
                window.location.href = res.data.url;
            })
            .catch(function () {
                setBusy(false);
                setError(cfg.failed);
            });
    }

    function PostType() {
        var b = useState(false), busy = b[0], setBusy = b[1];
        var e = useState(''), error = e[0], setError = e[1];
        return el(Info, { className: 'seoprostack-post-type' },
            el('div', { style: { width: '100%' } },
                el(wp.components.SelectControl, {
                    label: cfg.label,
                    value: cfg.type,
                    options: cfg.options,
                    disabled: busy,
                    onChange: function (type) {
                        if (type !== cfg.type) {
                            change(type, setBusy, setError);
                        }
                    },
                    __next40pxDefaultSize: true,
                    __nextHasNoMarginBottom: true
                }),
                error ? el('p', { role: 'alert', style: { color: '#cc1818' } }, error) : null
            )
        );
    }

    wp.plugins.registerPlugin('seoprostack-post-type-switch', { render: PostType });
})(window.wp, window.seoprostackPostTypeSwitch);
