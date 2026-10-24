/**
 * Simpler block editor, in the post editor (settings from
 * SEOProStack_Editor_Tidy::block_editor(), in window.seoprostackEditorTidy):
 *
 * - kadence: core block => Kadence block. Each core block leaves the
 *   inserter (supports.inserter) while its Kadence block is there, so
 *   Kadence's is the one offered. The core block stays allowed: pasting,
 *   transforms, duplicates and patterns that use it still work.
 * - viewHere: an eye button left of core's View button (which opens the
 *   page in a new tab) that opens the same address in this tab.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (wp, cfg) {
    if (!wp || !cfg) {
        return;
    }

    // Kadence blocks first. Kadence registers its blocks as its scripts
    // load; core registers its own when the editor starts, after them.
    if (cfg.kadence && wp.hooks && wp.blocks) {
        wp.hooks.addFilter('blocks.registerBlockType', 'seoprostack/kadence-first', function (settings, name) {
            var kadence = Object.prototype.hasOwnProperty.call(cfg.kadence, name) ? wp.blocks.getBlockType(cfg.kadence[name]) : null;
            if (!kadence || !wp.blocks.hasBlockSupport(kadence, 'inserter', true)) {
                return settings;
            }
            return Object.assign({}, settings, { supports: Object.assign({}, settings.supports, { inserter: false }) });
        });
    }

    if (!cfg.viewHere || !wp.element || !wp.components || !wp.data || !wp.domReady || !window.MutationObserver) {
        return;
    }
    var el = wp.element.createElement;
    // WordPress's "seen" icon.
    var eye = el('svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', fill: 'currentColor', 'aria-hidden': 'true', focusable: 'false' },
        el('path', { d: 'M3.99961 13C4.67043 13.3354 4.6703 13.3357 4.67017 13.3359L4.67298 13.3305C4.67621 13.3242 4.68184 13.3135 4.68988 13.2985C4.70595 13.2686 4.7316 13.2218 4.76695 13.1608C4.8377 13.0385 4.94692 12.8592 5.09541 12.6419C5.39312 12.2062 5.84436 11.624 6.45435 11.0431C7.67308 9.88241 9.49719 8.75 11.9996 8.75C14.502 8.75 16.3261 9.88241 17.5449 11.0431C18.1549 11.624 18.6061 12.2062 18.9038 12.6419C19.0523 12.8592 19.1615 13.0385 19.2323 13.1608C19.2676 13.2218 19.2933 13.2686 19.3093 13.2985C19.3174 13.3135 19.323 13.3242 19.3262 13.3305L19.3291 13.3359C19.3289 13.3357 19.3288 13.3354 19.9996 13C20.6704 12.6646 20.6703 12.6643 20.6701 12.664L20.6697 12.6632L20.6688 12.6614L20.6662 12.6563L20.6583 12.6408C20.6517 12.6282 20.6427 12.6108 20.631 12.5892C20.6078 12.5459 20.5744 12.4852 20.5306 12.4096C20.4432 12.2584 20.3141 12.0471 20.1423 11.7956C19.7994 11.2938 19.2819 10.626 18.5794 9.9569C17.1731 8.61759 14.9972 7.25 11.9996 7.25C9.00203 7.25 6.82614 8.61759 5.41987 9.9569C4.71736 10.626 4.19984 11.2938 3.85694 11.7956C3.68511 12.0471 3.55605 12.2584 3.4686 12.4096C3.42484 12.4852 3.39142 12.5459 3.36818 12.5892C3.35656 12.6108 3.34748 12.6282 3.34092 12.6408L3.33297 12.6563L3.33041 12.6614L3.32948 12.6632L3.32911 12.664C3.32894 12.6643 3.32879 12.6646 3.99961 13ZM11.9996 16C13.9326 16 15.4996 14.433 15.4996 12.5C15.4996 10.567 13.9326 9 11.9996 9C10.0666 9 8.49961 10.567 8.49961 12.5C8.49961 14.433 10.0666 16 11.9996 16Z' })
    );

    function current(select) {
        var editor = select('core/editor');
        var type = editor && select('core') ? select('core').getPostType(editor.getCurrentPostType()) : null;
        return {
            permalink: editor && editor.isCurrentPostPublished() ? editor.getPermalink() : '',
            label: type && type.labels && type.labels.view_item ? type.labels.view_item : cfg.viewPost
        };
    }

    function ViewHere() {
        var post = wp.data.useSelect(current, []);
        if (!post.permalink) {
            return null;
        }
        return el(wp.components.Button, {
            className: 'sps-view-here',
            icon: eye,
            label: cfg.viewHere.replace('%s', post.label),
            href: post.permalink,
            size: 'compact'
        });
    }

    var host = null;
    var queued = false;

    /** Core's View button: the header link to the page that opens a new tab. */
    function viewLink() {
        var permalink = current(wp.data.select).permalink;
        if (!permalink) {
            return null;
        }
        var links = document.querySelectorAll('.editor-header__settings a[target="_blank"], .edit-post-header__settings a[target="_blank"]');
        for (var i = 0; i < links.length; i++) {
            if (links[i].getAttribute('href') === permalink) {
                return links[i];
            }
        }
        return null;
    }

    function place() {
        queued = false;
        var link = viewLink();
        if (!link) {
            if (host && host.parentNode) {
                host.parentNode.removeChild(host);
            }
            return;
        }
        if (!host) {
            host = document.createElement('span');
            host.style.display = 'contents';
            if (wp.element.createRoot) {
                wp.element.createRoot(host).render(el(ViewHere));
            } else {
                wp.element.render(el(ViewHere), host);
            }
        }
        if (link.previousSibling !== host) {
            link.parentNode.insertBefore(host, link);
        }
    }

    function schedule() {
        if (!queued) {
            queued = true;
            window.requestAnimationFrame(place);
        }
    }

    wp.domReady(function () {
        new window.MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
        schedule();
    });
})(window.wp, window.seoprostackEditorTidy);
