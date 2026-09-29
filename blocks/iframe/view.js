/**
 * allstars/iframe: pass the page's query string (e.g. UTM tags) to iframes
 * that opt in with data-allstars-pass-params. Existing parameters on the
 * iframe URL win. Runs in the browser so cached pages keep working.
 */
(function () {
	'use strict';

	function run() {
		if (!window.location.search || !window.URL || !window.URLSearchParams) {
			return;
		}
		var params = new URLSearchParams(window.location.search);
		var frames = document.querySelectorAll('iframe[data-allstars-pass-params]');
		Array.prototype.forEach.call(frames, function (frame) {
			try {
				var url = new URL(frame.getAttribute('src'), window.location.href);
				var changed = false;
				params.forEach(function (value, key) {
					if (!url.searchParams.has(key)) {
						url.searchParams.set(key, value);
						changed = true;
					}
				});
				if (changed) {
					frame.setAttribute('src', url.toString());
				}
			} catch (e) {
				// Leave the iframe unchanged if its URL cannot be parsed.
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
})();
