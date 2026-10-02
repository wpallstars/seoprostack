/**
 * Faster editor with Kadence Blocks: keeps Kadence's design library in the
 * browser between editor pages.
 *
 * Kadence Blocks already keeps what it downloads on the server, but the
 * editor asks the site for the whole library (about 15 MB) every time the
 * design library is opened in a new editor page, and sends each request
 * twice. This answers Kadence's own library requests from the browser's
 * Cache Storage, and sends each one to the site only once while it is on
 * its way.
 *
 * Only Kadence's own libraries (sections, pages, templates) are kept, never
 * cloud or custom libraries (Kadence checks their expiry on each request),
 * licence, account or AI data. The copy belongs to a token that changes
 * when Kadence's copy on the server changes (its files, version, licence)
 * and per user, so old copies are dropped on the next editor page. Kadence's
 * Sync button (force_reload) always goes to the site and drops the kept copy.
 *
 * @package SEOProStack
 */
(function () {
    'use strict';

    var config = window.seoprostackKadenceLibrary;
    if (!config || !config.token || !window.wp || !window.wp.apiFetch) {
        return;
    }

    var PREFIX = 'seoprostack-kadence-library-';
    var cacheName = PREFIX + config.token;
    var ROUTE = /^\/?kb-design-library\/v1\/(get_library|get_library_categories)\/?$/;
    var LIBRARIES = ['section', 'templates', 'pages', 'template'];
    var store = window.isSecureContext && window.caches ? window.caches : null;
    var inFlight = {};

    function noop() {}

    // Drop copies kept under an earlier token.
    if (store) {
        store.keys().then(function (names) {
            names.forEach(function (name) {
                if (name.indexOf(PREFIX) === 0 && name !== cacheName) {
                    store.delete(name).catch(noop);
                }
            });
        }).catch(noop);
    }

    function read(key) {
        if (!store) {
            return Promise.resolve(undefined);
        }
        return store.open(cacheName).then(function (cache) {
            return cache.match(key);
        }).then(function (response) {
            return response ? response.json() : undefined;
        }).catch(function () {
            return undefined;
        });
    }

    function write(key, data) {
        if (!store) {
            return;
        }
        store.open(cacheName).then(function (cache) {
            return cache.put(key, new Response(JSON.stringify(data), {
                headers: { 'Content-Type': 'application/json' }
            }));
        }).catch(noop);
    }

    // Kadence's Sync: forget every kept answer for that library.
    function forget(library) {
        if (!store) {
            return;
        }
        store.open(cacheName).then(function (cache) {
            return cache.keys().then(function (requests) {
                requests.forEach(function (request) {
                    var params = new URL(request.url).searchParams;
                    if (params.get('library') === library) {
                        cache.delete(request).catch(noop);
                    }
                });
            });
        }).catch(noop);
    }

    // Kadence answers failures with an error status (apiFetch rejects) or
    // the strings "error" or "failed"; only keep real libraries.
    function worthKeeping(data) {
        if (typeof data === 'string') {
            return data.length > 2 && data !== 'error' && data !== 'failed';
        }
        return data !== null && typeof data === 'object';
    }

    window.wp.apiFetch.use(function (options, next) {
        var path = typeof options.path === 'string' ? options.path : '';
        var method = (options.method || 'GET').toUpperCase();
        if (!path || method !== 'GET' || options.parse === false) {
            return next(options);
        }
        var split = path.indexOf('?');
        var route = split === -1 ? path : path.slice(0, split);
        var match = route.match(ROUTE);
        if (!match) {
            return next(options);
        }
        var params = new URLSearchParams(split === -1 ? '' : path.slice(split + 1));
        var library = params.get('library') || '';
        if (LIBRARIES.indexOf(library) === -1 || params.get('library_url')) {
            return next(options);
        }
        var reload = params.get('force_reload');
        if (reload === 'true' || reload === '1') {
            forget(library);
            return next(options);
        }

        params.delete('force_reload');
        params.delete('_locale');
        params.sort();
        var key = window.location.origin + '/seoprostack-kadence-library/' + match[1] + '?' + params.toString();
        if (inFlight[key]) {
            return inFlight[key];
        }

        var request = read(key).then(function (kept) {
            if (kept !== undefined) {
                return kept;
            }
            return next(options).then(function (data) {
                if (worthKeeping(data)) {
                    write(key, data);
                }
                return data;
            });
        });
        inFlight[key] = request;
        request.then(function () {
            delete inFlight[key];
        }, function () {
            delete inFlight[key];
        });
        return request;
    });
})();
