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
 */
(function (wp, cfg) {
    if (!wp || !wp.data || !wp.domReady || !cfg || !cfg.panels || !window.MutationObserver) {
        return;
    }
    var DOC = 'edit-post/document';
    var TABS = '.editor-sidebar__panel-tabs [role="tablist"], .edit-post-sidebar__panel-tabs [role="tablist"]';
    var ids = [];
    var titles = {};
    var hasTitles = false;
    var open = false;
    var queued = false;
    var button = null;

    cfg.panels.forEach(function (panel) {
        if (panel.id) {
            ids.push(panel.id);
        }
        if (panel.title) {
            titles[panel.title.toLowerCase()] = true;
            hasTitles = true;
        }
    });

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
        if (hasTitles) {
            document.querySelectorAll('.postbox .hndle, .editor-sidebar__panel .components-panel__body-title, .edit-post-sidebar .components-panel__body-title').forEach(function (heading) {
                if (titles[text(heading).toLowerCase()]) {
                    add(heading.closest('.postbox, .components-panel__body'));
                }
            });
        }
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

    function render() {
        queued = false;
        var panels = findPanels();
        if (!cfg.developer) {
            mark(panels, true);
            return;
        }
        var list = document.querySelector(TABS);
        var postTab = list && list.querySelector('[data-tab-id="' + DOC + '"]');
        // Core's last tab (Block), copied for the Dev tab's look.
        var tabs = list ? list.querySelectorAll('[role="tab"]:not([data-sps-dev-tab])') : [];
        var model = tabs.length ? tabs[tabs.length - 1] : null;
        var shown = panels.filter(function (el) {
            return !el.classList.contains('is-hidden');
        });
        // Without a tab to put them in, the panels stay in the Post tab.
        if (!postTab || !model || !shown.length) {
            open = false;
            mark(panels, false);
            clearView();
            if (button && button.parentNode) {
                button.parentNode.removeChild(button);
            }
            return;
        }
        mark(panels, true);
        if (!button || button.parentNode !== list) {
            button = makeButton(model);
            list.appendChild(button);
            watchTabs(list);
        }

        var view = document.getElementById(postTab.getAttribute('aria-controls') || '');
        var isOpen = open && area() === DOC && !!view;
        clearView();
        if (isOpen) {
            showView(view, panels);
            list.style.setProperty('--sps-dev-left', String(button.offsetLeft));
            list.style.setProperty('--sps-dev-width', String(button.offsetWidth));
            list.style.setProperty('--sps-dev-right', String(list.offsetWidth - button.offsetLeft - button.offsetWidth));
            postTab.setAttribute('aria-selected', 'false');
        }
        list.classList.toggle('sps-dev-tab-open', isOpen);
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
