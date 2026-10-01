/**
 * seoprostack/term-list: open the chosen term when a drop-down changes.
 * Option values are term links built on the server.
 */
(function () {
	'use strict';

	document.addEventListener('change', function (event) {
		var select = event.target;
		if (!select || !select.matches || !select.matches('select[data-sps-term-select]')) {
			return;
		}
		var url = select.value;
		if (url && /^https?:\/\//i.test(url)) {
			window.location.href = url;
		}
	});
})();
