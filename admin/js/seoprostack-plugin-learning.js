/*
 * Relearn retained, read-only admin views using this administrator's session.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 */
(function () {
    'use strict';
    if (typeof seoprostackPluginLearning === 'undefined' || typeof AbortController === 'undefined') {
        return;
    }
    var config = seoprostackPluginLearning;
    var owner = Date.now() + '-' + Math.random().toString(36).slice(2);
    var stopped = false;
    var controller = null;
    var lastInput = Date.now();
    var lease = 60000;
    var urls = config.urls.filter(function (url) {
        var parsed = new URL(url, location.href);
        return parsed.origin === location.origin;
    });

    function readLock() {
        try {
            return JSON.parse(localStorage.getItem(config.lock) || 'null');
        } catch (error) {
            stopped = true; // No shared storage: do not risk simultaneous tabs.
            return null;
        }
    }

    function release() {
        var lock = readLock();
        if (lock && lock.owner === owner) {
            try {
                localStorage.removeItem(config.lock);
            } catch (error) {
                // Storage became unavailable; the short lease will expire.
            }
        }
    }

    function stop() {
        stopped = true;
        if (controller) {
            controller.abort();
        }
        release();
    }

    function renew() {
        var lock = readLock();
        if (stopped || !lock || lock.owner !== owner || lock.expires <= Date.now()) {
            return false;
        }
        try {
            localStorage.setItem(config.lock, JSON.stringify({ owner: owner, expires: Date.now() + lease }));
            return true;
        } catch (error) {
            stop();
            return false;
        }
    }

    function idle(callback) {
        // Leave space between requests, and let the owner's clicks go first.
        setTimeout(function () {
            if (stopped) {
                return;
            }
            if (document.hidden || Date.now() - lastInput < 3000) {
                idle(callback);
                return;
            }
            if (window.requestIdleCallback) {
                window.requestIdleCallback(callback, { timeout: 5000 });
            } else {
                setTimeout(callback, 1000);
            }
        }, 3000);
    }

    function next() {
        if (stopped) {
            return;
        }
        if (document.hidden || Date.now() - lastInput < 3000) {
            idle(next);
            return;
        }
        if (!urls.length) {
            stop();
            return;
        }
        if (!renew()) {
            stop();
            return;
        }
        controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 30000);
        fetch(urls.shift(), {
            credentials: 'same-origin',
            headers: { 'X-Seoprostack-Learning': '1' },
            redirect: 'error',
            cache: 'no-store',
            signal: controller.signal
        }).then(function (response) {
            // Drain the body before another request: learning runs in the footer.
            if (!response.ok) {
                throw new Error('Screen unavailable');
            }
            return response.text();
        }).then(function () {
            clearTimeout(timeout);
            controller = null;
            idle(next);
        }).catch(function () {
            clearTimeout(timeout);
            stop(); // No retry loop on errors, expired sessions or redirects.
        });
    }

    window.addEventListener('pagehide', stop);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stop();
        }
    });
    ['pointerdown', 'keydown'].forEach(function (event) {
        document.addEventListener(event, function () { lastInput = Date.now(); }, { passive: true });
    });
    window.addEventListener('storage', function (event) {
        if (event.key === config.lock) {
            var lock = readLock();
            if (lock && lock.owner !== owner) {
                stop();
            }
        }
    });

    // Claim then verify after contention settles; leases outlive the fetch timeout.
    idle(function () {
        var lock = readLock();
        if (stopped || (lock && lock.expires > Date.now())) {
            return;
        }
        try {
            localStorage.setItem(config.lock, JSON.stringify({ owner: owner, expires: Date.now() + lease }));
        } catch (error) {
            stopped = true;
            return;
        }
        setTimeout(next, 250);
    });
}());
