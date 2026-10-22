/**
 * Developer admins, html limit: in the block editor, warn people who are
 * not developers as soon as a Custom HTML, Classic or Shortcode block holds
 * embed code that core's content filter removes when they save (the tags in
 * window.seoprostackEmbedNotice.tags), and again after each save while it is
 * there. The warning goes when the code does. Nothing is stored.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (wp, cfg) {
    if (!wp || !wp.data || !wp.blocks || !wp.domReady || !cfg || !cfg.tags || !cfg.tags.length || !cfg.message) {
        return;
    }
    var NOTICE = 'seoprostack-embed-code';
    // Blocks that take raw HTML. Their HTML is read as saved, not from an
    // attribute: WordPress 7.1 moved the Custom HTML block's out of
    // `content`.
    var BLOCKS = { 'core/html': true, 'core/freeform': true, 'core/shortcode': true };
    // Opening tags; each name is looked up in the tags removed.
    var TAG = /<([a-z][a-z0-9]*)\b/gi;
    var tags = {};
    var lastBlocks = null;
    var found = false;
    var saving = false;

    cfg.tags.forEach(function (tag) {
        tags[String(tag).toLowerCase()] = true;
    });

    function html(block) {
        try {
            return wp.blocks.getBlockContent(block) || '';
        } catch (e) {
            return '';
        }
    }

    function hasTag(text) {
        var match;
        TAG.lastIndex = 0;
        while ((match = TAG.exec(text)) !== null) {
            if (tags[match[1].toLowerCase()]) {
                return true;
            }
        }
        return false;
    }

    function hasEmbed(blocks) {
        for (var i = 0; i < blocks.length; i++) {
            var block = blocks[i];
            if (BLOCKS[block.name] && hasTag(html(block))) {
                return true;
            }
            if (block.innerBlocks && block.innerBlocks.length && hasEmbed(block.innerBlocks)) {
                return true;
            }
        }
        return false;
    }

    function show() {
        wp.data.dispatch('core/notices').createWarningNotice(cfg.message, { id: NOTICE, isDismissible: true });
    }

    function hide() {
        wp.data.dispatch('core/notices').removeNotice(NOTICE);
    }

    wp.domReady(function () {
        wp.data.subscribe(function () {
            var blockEditor = wp.data.select('core/block-editor');
            var editor = wp.data.select('core/editor');
            if (!blockEditor || !editor) {
                return;
            }
            // State first: the notices below run this listener again.
            var change = '';
            var blocks = blockEditor.getBlocks();
            if (blocks !== lastBlocks) {
                lastBlocks = blocks;
                var now = hasEmbed(blocks);
                if (now !== found) {
                    change = now ? 'show' : 'hide';
                }
                found = now;
            }
            var isSaving = editor.isSavingPost() && !editor.isAutosavingPost();
            var saved = saving && !isSaving;
            saving = isSaving;
            if (saved && found) {
                change = 'show';
            }
            if ('show' === change) {
                show();
            } else if ('hide' === change) {
                hide();
            }
        });
    });
})(window.wp, window.seoprostackEmbedNotice);
