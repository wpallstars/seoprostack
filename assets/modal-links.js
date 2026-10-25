/**
 * Modal links: a link to #id of a modal or drawer on the page opens it.
 *
 * Kadence opens its drawers (login, search, mobile menu, cart) only from its
 * own toggles, and its Modal block only from links that had the
 * modal-trigger class when the page loaded, so a link in text to
 * #login-drawer only jumped to a hidden element. Now, on a plain click of a
 * link to #id on this page, where that element is a modal or drawer:
 *
 * - its own opener on the page is clicked ([data-toggle-target="#id"],
 *   [data-modal-open="id"], [data-micromodal-trigger="id"] or
 *   [aria-controls="id"], outside the modal), so the code that owns it opens
 *   it, with its focus handling and scroll lock;
 * - otherwise a Kadence Modal block opens with MicroModal (Kadence's own
 *   settings), and a <dialog> gets a cancelable seoprostack:open-modal event
 *   first, so the plugin that owns it can prepare and open it, then
 *   showModal().
 *
 * One already open is left as it is. A page loaded with such a hash opens it
 * too. Other links (anchors, tabs, accordions, Cookie Consent's
 * #cookie-settings) keep their own behaviour, as do clicks with a modifier
 * key. Links that open a modal get aria-haspopup="dialog" and aria-controls.
 *
 * window.seoprostackModalLinks.selectors (seoprostack_modal_links_selectors
 * filter) adds CSS selectors for other kinds of modal.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 *
 * @package SEOProStack
 */
(function (w, d) {
    'use strict';

    if (!d.querySelector || !w.URL || !Element.prototype.closest) {
        return;
    }

    var cfg = w.seoprostackModalLinks || {};
    // A filtered selector the browser cannot read is dropped on its own, so
    // it does not stop the built-in ones from matching.
    var extra = (Array.isArray(cfg.selectors) ? cfg.selectors : []).filter(function (selector) {
        if ('string' !== typeof selector || !selector) {
            return false;
        }
        try {
            d.createDocumentFragment().querySelector(selector);
            return true;
        } catch (e) {
            return false;
        }
    });
    var MODALS = ['dialog', '[role="dialog"]', '[role="alertdialog"]', '[aria-modal="true"]', '.popup-drawer', '.kadence-block-pro-modal']
        .concat(extra)
        .join(',');
    // Links whose own code opens what they point at.
    var OWNED = '[data-toggle-target],[data-modal-open],[data-micromodal-trigger],.modal-trigger';

    /** Value for a double-quoted CSS attribute selector. */
    function quote(value) {
        return '"' + value.replace(/["\\]/g, '\\$&') + '"';
    }

    function samePage(url) {
        var here = w.location;
        var trim = function (path) { return path.replace(/\/+$/, ''); };
        return url.origin === here.origin && trim(url.pathname) === trim(here.pathname) && url.search === here.search;
    }

    /** The modal or drawer a hash names, or null. */
    function modalFor(hash) {
        var id = (hash || '').slice(1);
        if (!id) {
            return null;
        }
        try {
            id = decodeURIComponent(id);
        } catch (e) {
            return null;
        }
        var el = d.getElementById(id);
        return el && el.matches(MODALS) ? el : null;
    }

    /** The modal a link opens, or null. */
    function linkTarget(a) {
        var href = a.getAttribute('href');
        if (!href || href.indexOf('#') === -1 || a.matches(OWNED) || a.hasAttribute('download')
            || (a.target && a.target !== '_self')) {
            return null;
        }
        var url;
        try {
            url = new URL(a.href, d.baseURI);
        } catch (e) {
            return null;
        }
        return samePage(url) ? modalFor(url.hash) : null;
    }

    function isOpen(el) {
        if ('DIALOG' === el.tagName) {
            return el.open;
        }
        if (el.classList.contains('show-drawer') || el.classList.contains('is-open')) {
            return true;
        }
        if ('true' === el.getAttribute('aria-hidden') || el.hidden) {
            return false;
        }
        return el.getClientRects().length > 0 && 'hidden' !== w.getComputedStyle(el).visibility;
    }

    /** The modal's own opener on the page, outside the modal, or null. */
    function opener(el, link) {
        var id = el.id;
        var found = d.querySelectorAll(
            '[data-toggle-target=' + quote('#' + id) + '],[data-modal-open=' + quote(id) + '],'
            + '[data-micromodal-trigger=' + quote(id) + '],[aria-controls=' + quote(id) + ']'
        );
        for (var i = 0; i < found.length; i++) {
            // Not a link this script handles (it gets aria-controls too).
            if (found[i] !== link && !el.contains(found[i]) && !(found[i].matches('a[href*="#"]') && linkTarget(found[i]))) {
                return found[i];
            }
        }
        return null;
    }

    /** Open a modal; false when this script cannot. */
    function open(el, link) {
        if (isOpen(el)) {
            return true;
        }
        var button = opener(el, link);
        if (button) {
            button.click();
            return true;
        }
        if (el.classList.contains('kadence-block-pro-modal') && w.MicroModal && w.MicroModal.show) {
            // Kadence Blocks Pro's settings (kt-modal-init.js).
            w.MicroModal.show(el.id, {
                onShow: function () {
                    w.dispatchEvent(new Event('resize'));
                },
                openTrigger: 'data-modal-open',
                closeTrigger: 'data-modal-close',
                disableScroll: true,
                disableFocus: false,
                awaitCloseAnimation: true
            });
            return true;
        }
        if ('DIALOG' === el.tagName) {
            var event = new CustomEvent('seoprostack:open-modal', { bubbles: true, cancelable: true, detail: { link: link || null } });
            if (el.dispatchEvent(event) && !el.open && 'function' === typeof el.showModal) {
                el.showModal();
            }
            return true;
        }
        return false;
    }

    // Capture phase: before Kadence's smooth scrolling, which listens on
    // each same-page anchor link and would scroll to the hidden element.
    d.addEventListener('click', function (e) {
        if (e.defaultPrevented || 0 !== e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        var a = e.target && e.target.closest ? e.target.closest('a[href*="#"]') : null;
        var el = a ? linkTarget(a) : null;
        if (el && open(el, a)) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    function ready() {
        var links = d.querySelectorAll('a[href*="#"]');
        for (var i = 0; i < links.length; i++) {
            var el = linkTarget(links[i]);
            if (el) {
                links[i].setAttribute('aria-haspopup', 'dialog');
                links[i].setAttribute('aria-controls', el.id);
            }
        }
        var named = modalFor(w.location.hash);
        if (named) {
            open(named, null);
        }
    }

    // After the page's own scripts have set their modals up.
    if ('complete' === d.readyState) {
        w.setTimeout(ready, 0);
    } else {
        w.addEventListener('load', ready);
    }
})(window, document);
