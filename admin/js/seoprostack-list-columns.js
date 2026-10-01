/**
 * Readable list columns: when the main column of a fixed-layout list table
 * (the title, name or other column WordPress marks as primary) is narrower
 * than a fifth of the table, give it a quarter. The browser narrows the
 * other columns to make room. Checked again after Screen Options changes
 * and window resizes.
 *
 * @package SEOProStack
 */
(function () {
	'use strict';

	var MIN = 0.2;
	var GIVE = '25%';
	// Below this width WordPress stacks list columns under the title.
	var STACKED = 782;

	function fit() {
		var tables = document.querySelectorAll('table.wp-list-table.fixed');
		for (var i = 0; i < tables.length; i++) {
			var table = tables[i];
			var head = table.querySelector('thead th.column-primary');
			if (!head) {
				continue;
			}
			head.style.width = '';
			if (window.innerWidth <= STACKED) {
				continue;
			}
			var total = table.getBoundingClientRect().width;
			if (total > 0 && head.getBoundingClientRect().width / total < MIN) {
				head.style.width = GIVE;
			}
		}
	}

	var queued = false;
	function later() {
		if (queued) {
			return;
		}
		queued = true;
		window.requestAnimationFrame(function () {
			queued = false;
			fit();
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', fit);
	} else {
		fit();
	}
	// Columns some plugins add with their scripts.
	window.addEventListener('load', later);
	window.addEventListener('resize', later);
	document.addEventListener('change', function (event) {
		if (event.target && event.target.classList && event.target.classList.contains('hide-column-tog')) {
			later();
		}
	});
})();
