/**
 * Dev tab in the block editor: a Dev tab after the Post and Block tabs of
 * the settings sidebar, for developers, holding the panels listed in
 * window.seoprostackEditorDevTab.panels in A–Z order. For everyone else
 * those panels are hidden and there is no tab.
 *
 * The panels stay where WordPress renders them, so meta boxes stay inside
 * their form and still save: the Dev tab is the Post tab showing only
 * them (the sps-dev-* classes; styles in SEOProStack_Editor_Dev_Tab::css()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function (wp, cfg) {
    if (!wp || !wp.data || !wp.domReady || !cfg || !cfg.panels || !window.MutationObserver) {
        return;
    }
    var DOC = 'edit-post/document';
    var TABS = '.editor-sidebar__panel-tabs [role="tablist"], .edit-post-sidebar__panel-tabs [role="tablist"]';
    var ids = [];
    var open = false;
    var queued = false;
    var button = null;

    cfg.panels.forEach(function (panel) {
        if (panel.id) {
            ids.push(panel.id);
        }
    });

    /**
     * Panel titles to match, in English and, for panels that name their
     * plugin's text domain, as that plugin shows them (its translations are
     * loaded by the time panels render, so this runs each time).
     */
    function titles() {
        var set = {};
        cfg.panels.forEach(function (panel) {
            if (!panel.title) {
                return;
            }
            set[panel.title.toLowerCase()] = true;
            if (panel.domain && wp.i18n) {
                set[wp.i18n.__(panel.title, panel.domain).toLowerCase()] = true;
            }
        });
        return set;
    }

    function text(el) {
        return el ? (el.textContent || '').replace(/\s+/g, ' ').trim() : '';
    }

    function area() {
        var store = wp.data.select('core/interface');
        return store && store.getActiveComplementaryArea ? store.getActiveComplementaryArea('core') : null;
    }

    function titleOf(panel) {
        return text(panel.querySelector('.hndle, .components-panel__body-title'));
    }

    /** Developer panels in the page: meta boxes (wherever their form is) and sidebar panels. */
    function findPanels() {
        var found = [];
        function add(el) {
            if (el && found.indexOf(el) === -1) {
                found.push(el);
            }
        }
        ids.forEach(function (id) {
            var el = document.getElementById(id);
            if (el && el.classList.contains('postbox')) {
                add(el);
            }
        });
        var wanted = titles();
        document.querySelectorAll('.postbox .hndle, .editor-sidebar .components-panel__body-title, .edit-post-sidebar .components-panel__body-title').forEach(function (heading) {
            if (wanted[text(heading).toLowerCase()]) {
                add(heading.closest('.postbox, .components-panel__body'));
            }
        });
        return found;
    }

    function mark(panels, on) {
        document.querySelectorAll('.sps-dev-panel').forEach(function (el) {
            if (!on || panels.indexOf(el) === -1) {
                el.classList.remove('sps-dev-panel');
            }
        });
        if (on) {
            panels.forEach(function (el) {
                el.classList.add('sps-dev-panel');
            });
        }
    }

    function openDev() {
        open = true;
        if (area() !== DOC) {
            wp.data.dispatch('core/interface').enableComplementaryArea('core', DOC);
        }
        schedule();
    }

    function makeButton(model) {
        var tab = model.cloneNode(true);
        ['id', 'aria-controls', 'data-tab-id', 'data-focus-visible', 'data-active-item', 'data-active', 'tabindex'].forEach(function (name) {
            tab.removeAttribute(name);
        });
        tab.setAttribute('type', 'button');
        tab.setAttribute('data-sps-dev-tab', '');
        var label = tab.querySelector('span');
        (label || tab).textContent = cfg.label;
        tab.addEventListener('click', openDev);
        return tab;
    }

    /** Leave the Dev tab when Post or Block is chosen. */
    function watchTabs(list) {
        if (list.getAttribute('data-sps-dev-watched')) {
            return;
        }
        list.setAttribute('data-sps-dev-watched', '1');
        list.addEventListener('click', function (event) {
            var tab = event.target.closest('[role="tab"]');
            if (tab && !tab.hasAttribute('data-sps-dev-tab') && open) {
                open = false;
                schedule();
            }
        }, true);
    }

    function clearView() {
        document.querySelectorAll('.sps-dev-view, .sps-dev-flat').forEach(function (el) {
            el.classList.remove('sps-dev-view', 'sps-dev-flat');
        });
        document.querySelectorAll('.sps-dev-panel[style*="order"]').forEach(function (el) {
            el.style.removeProperty('order');
        });
    }

    /** Show only the developer panels in the Post tab's panel, flat and A–Z. */
    function showView(view, panels) {
        var inside = panels.filter(function (el) {
            return view.contains(el);
        }).sort(function (a, b) {
            return titleOf(a).localeCompare(titleOf(b), undefined, { sensitivity: 'base' });
        });
        view.classList.add('sps-dev-view');
        inside.forEach(function (el, index) {
            el.style.order = String(index);
            for (var up = el.parentElement; up && up !== view; up = up.parentElement) {
                up.classList.add('sps-dev-flat');
            }
        });
    }

    /** The sidebar's tab list, its Post tab, and core's last tab (Block), copied for the Dev tab's look. */
    function findTabs() {
        var list = document.querySelector(TABS);
        if (!list) {
            return null;
        }
        var tabs = list.querySelectorAll('[role="tab"]:not([data-sps-dev-tab])');
        var postTab = list.querySelector('[data-tab-id="' + DOC + '"]');
        if (!postTab || !tabs.length) {
            return null;
        }
        return { list: list, postTab: postTab, model: tabs[tabs.length - 1] };
    }

    /** Without a tab to put them in, the panels stay in the Post tab. */
    function removeTab(panels) {
        open = false;
        mark(panels, false);
        clearView();
        if (button && button.parentNode) {
            button.parentNode.removeChild(button);
        }
    }

    function placeTab(tabs) {
        if (!button || button.parentNode !== tabs.list) {
            button = makeButton(tabs.model);
            tabs.list.appendChild(button);
            watchTabs(tabs.list);
        }
    }

    /** Post shows as not chosen while Dev is open, and as chosen again after. */
    function markPostTab(postTab, isOpen) {
        if (isOpen && postTab.getAttribute('aria-selected') === 'true') {
            postTab.setAttribute('aria-selected', 'false');
            postTab.setAttribute('data-sps-dev-unselected', '');
        } else if (!isOpen && postTab.hasAttribute('data-sps-dev-unselected')) {
            // The editor still has Post chosen and will not mark it again.
            postTab.removeAttribute('data-sps-dev-unselected');
            postTab.setAttribute('aria-selected', area() === DOC ? 'true' : 'false');
        }
    }

    /** Move the tab line under Dev. */
    function underline(list) {
        list.style.setProperty('--sps-dev-left', String(button.offsetLeft));
        list.style.setProperty('--sps-dev-width', String(button.offsetWidth));
        list.style.setProperty('--sps-dev-right', String(list.offsetWidth - button.offsetLeft - button.offsetWidth));
    }

    function render() {
        queued = false;
        var panels = findPanels();
        if (!cfg.developer) {
            mark(panels, true);
            return;
        }
        var tabs = findTabs();
        var shown = panels.some(function (el) {
            return !el.classList.contains('is-hidden');
        });
        if (!tabs || !shown) {
            removeTab(panels);
            return;
        }
        mark(panels, true);
        placeTab(tabs);

        var view = document.getElementById(tabs.postTab.getAttribute('aria-controls') || '');
        var isOpen = open && area() === DOC && !!view;
        clearView();
        if (isOpen) {
            showView(view, panels);
            underline(tabs.list);
        }
        markPostTab(tabs.postTab, isOpen);
        tabs.list.classList.toggle('sps-dev-tab-open', isOpen);
        button.setAttribute('aria-selected', isOpen ? 'true' : 'false');
    }

    function schedule() {
        if (!queued) {
            queued = true;
            window.requestAnimationFrame(render);
        }
    }

    wp.domReady(function () {
        var last = area();
        // Leave the Dev tab when the sidebar closes or shows a block.
        wp.data.subscribe(function () {
            var now = area();
            if (now !== last) {
                last = now;
                if (now !== DOC) {
                    open = false;
                }
                schedule();
            }
        });
        new window.MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
        schedule();
    });
})(window.wp, window.seoprostackEditorDevTab);
