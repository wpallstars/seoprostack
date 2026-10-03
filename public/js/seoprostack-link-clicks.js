/* Optional aggregate link events: no cookies, visitor IDs or destination URLs. */
(function () {
    'use strict';
    if (navigator.globalPrivacyControl || navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.webdriver) {
        return;
    }
    var config = window.seoprostackLinkClicks;
    if (!config || !config.endpoint) {
        return;
    }
    function count(event) {
        if (!event.isTrusted || (event.type === 'auxclick' && event.button !== 1) || (event.type === 'click' && event.button !== 0)) {
            return;
        }
        var target = event.target instanceof Element ? event.target.closest('a[data-sps-link-token]') : null;
        if (!target || target.hasAttribute('download')) {
            return;
        }
        var data = JSON.stringify({post: Number(target.dataset.spsLinkPost), link: target.dataset.spsLinkHash, token: target.dataset.spsLinkToken});
        // Do not block navigation or transmit browser credentials.
        if (typeof window.fetch === 'function') {
            window.fetch(config.endpoint, {
                method: 'POST',
                credentials: 'omit',
                keepalive: true,
                headers: {'Content-Type': 'application/json'},
                body: data
            }).catch(function () {});
        }
    }
    document.addEventListener('click', count, {passive: true});
    document.addEventListener('auxclick', count, {passive: true});
}());
