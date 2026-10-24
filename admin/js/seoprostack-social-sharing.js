/**
 * Social sharing: a Share buttons choice (Default, Show or Hide) in the
 * block editor's summary panel, kept in the `_kt_share_control` post meta
 * that Kadence Simple Share used, so its choices carry over. The Dev tab
 * moves it to the top of that tab.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (wp, cfg) {
    if (!wp || !cfg || !wp.plugins || !wp.element || !wp.components || !wp.data) {
        return;
    }
    var el = wp.element.createElement;
    var Info = (wp.editor && wp.editor.PluginPostStatusInfo) || (wp.editPost && wp.editPost.PluginPostStatusInfo);
    if (!Info) {
        return;
    }

    function ShareControl() {
        var meta = wp.data.useSelect(function (select) {
            return select('core/editor').getEditedPostAttribute('meta');
        }, []);
        var editPost = wp.data.useDispatch('core/editor').editPost;
        // Not in the REST API for this post type: nothing to show.
        if (!meta || !Object.prototype.hasOwnProperty.call(meta, cfg.meta)) {
            return null;
        }
        var value = meta[cfg.meta] === 'show' || meta[cfg.meta] === 'hide' ? meta[cfg.meta] : '';
        return el(Info, { className: 'seoprostack-share-control' },
            el('div', { style: { width: '100%' } },
                el(wp.components.SelectControl, {
                    label: cfg.label,
                    value: value,
                    options: cfg.options,
                    onChange: function (next) {
                        var change = {};
                        change[cfg.meta] = next;
                        editPost({ meta: change });
                    },
                    __next40pxDefaultSize: true,
                    __nextHasNoMarginBottom: true
                })
            )
        );
    }

    wp.plugins.registerPlugin('seoprostack-social-sharing', { render: ShareControl });
})(window.wp, window.seoprostackSocialSharing);
