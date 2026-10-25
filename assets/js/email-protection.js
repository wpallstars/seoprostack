/**
 * Restore protected email text and mail links without interpreting HTML.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
(function () {
    'use strict';

    function decode(value) {
        if (!/^[a-f0-9]{4}(?:[a-f0-9]{2})*$/i.test(value || '')) {
            return '';
        }
        var key = parseInt(value.slice(0, 2), 16);
        var bytes = '';
        for (var index = 2; index < value.length; index += 2) {
            bytes += '%' + ('0' + (parseInt(value.slice(index, index + 2), 16) ^ key).toString(16)).slice(-2);
        }
        try {
            return decodeURIComponent(bytes);
        } catch (error) {
            return '';
        }
    }

    document.querySelectorAll('[data-sps-e]').forEach(function (node) {
        var address = decode(node.getAttribute('data-sps-e'));
        if (address) {
            node.textContent = address;
            node.removeAttribute('data-sps-e');
        }
    });
    document.querySelectorAll('a[data-sps-mailto]').forEach(function (node) {
        var href = decode(node.getAttribute('data-sps-mailto'));
        if (/^mailto:/i.test(href)) {
            node.setAttribute('href', href);
            node.removeAttribute('data-sps-mailto');
        }
    });
}());
