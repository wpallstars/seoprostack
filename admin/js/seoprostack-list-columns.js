/**
 * Readable list columns.
 *
 * WordPress lays out list tables with fixed column widths. Plugins give
 * their columns widths of their own, and when those add up to more than the
 * table, the columns without one (the title, and often other plugins'
 * columns) get nothing.
 *
 * When the main column (the one WordPress marks as primary) is narrower than
 * a fifth of the table, or any column has been squeezed to nothing, this
 * gives the main column a quarter of the table and narrows the others
 * towards the narrowest they can be without breaking words. If that is not
 * enough, the main column gives up some of its quarter (down to FLOOR)
 * before other columns break words. Lists that already fit are left alone.
 * Checked again after Screen Options changes and window resizes.
 *
 * @package SEOProStack
 */
(function () {
	'use strict';

	// The main column should have at least this share of the table...
	var MIN = 0.2;
	// ...and gets this share when it needs room...
	var GIVE = 0.25;
	// ...giving up to this many pixels, or this share, before other columns
	// break words.
	var FLOOR = 160;
	var FLOOR_SHARE = 0.15;
	// A column narrower than this has been squeezed out.
	var SQUEEZED = 24;
	// Columns given this width or less, if their content fits (checkboxes,
	// icons, counts), keep it.
	var SMALL = 48;
	// Below this window width WordPress stacks list columns under the title.
	var STACKED = 782;
	var MARK = 'data-seoprostack-width';

	function width(el) {
		return el.getBoundingClientRect().width;
	}

	// Sets a cell's outer width, whatever its box-sizing.
	function setWidth(cell, outer) {
		var s = window.getComputedStyle(cell);
		var inner = outer;
		if ('border-box' !== s.boxSizing) {
			inner -= (parseFloat(s.paddingLeft) || 0) + (parseFloat(s.paddingRight) || 0) +
				(parseFloat(s.borderLeftWidth) || 0) + (parseFloat(s.borderRightWidth) || 0);
		}
		cell.style.width = Math.max(0, Math.floor(inner)) + 'px';
		cell.setAttribute(MARK, '1');
	}

	function reset(table) {
		var set = table.querySelectorAll('[' + MARK + ']');
		for (var i = 0; i < set.length; i++) {
			set[i].style.width = '';
			set[i].removeAttribute(MARK);
		}
	}

	function visibleColumns(table) {
		var row = table.querySelector('thead tr');
		var out = [];
		if (!row) {
			return out;
		}
		for (var i = 0; i < row.children.length; i++) {
			var cell = row.children[i];
			if ('none' !== window.getComputedStyle(cell).display) {
				out.push(cell);
			}
		}
		return out;
	}

	function fitTable(table) {
		reset(table);
		var head = table.querySelector('thead .column-primary');
		if (!head || window.innerWidth <= STACKED) {
			return;
		}
		var total = width(table);
		var cols = visibleColumns(table);
		if (total <= 0 || cols.length < 2) {
			return;
		}
		var others = [];
		var given = [];
		var squeezed = false;
		for (var i = 0; i < cols.length; i++) {
			if (cols[i] === head) {
				continue;
			}
			others.push(cols[i]);
			given.push(width(cols[i]));
			if (given[given.length - 1] < SQUEEZED) {
				squeezed = true;
			}
		}
		if (!squeezed && width(head) / total >= MIN) {
			return;
		}

		// The narrowest each column can be without breaking words.
		var layout = table.style.tableLayout;
		var tableWidth = table.style.width;
		table.style.tableLayout = 'auto';
		table.style.width = '1px';
		var narrow = [];
		for (var j = 0; j < others.length; j++) {
			narrow.push(width(others[j]));
		}
		table.style.tableLayout = layout;
		table.style.width = tableWidth;

		// Each column wants the width it was given, and never less than its
		// narrowest; a squeezed-out column wants its narrowest. Small columns
		// whose content fits (checkboxes, icons, counts) keep the width they
		// were given.
		var want = [];
		var keep = [];
		var sumWant = 0;
		var sumNarrow = 0;
		var sumKeep = 0;
		for (var k = 0; k < others.length; k++) {
			keep.push(given[k] <= SMALL && narrow[k] <= given[k]);
			if (keep[k]) {
				narrow[k] = given[k];
				sumKeep += given[k];
			}
			want.push(Math.max(given[k], narrow[k]));
			sumWant += want[k];
			sumNarrow += narrow[k];
		}

		var ideal = total * GIVE;
		var floor = Math.min(ideal, Math.max(FLOOR, total * FLOOR_SHARE));
		var widths = [];
		var n;
		if (sumWant + ideal <= total) {
			// Room for everything: the main column gets the rest.
			widths = want;
		} else if (sumNarrow + ideal <= total) {
			// Narrow the others towards their narrowest, in proportion.
			var share = (total - ideal - sumNarrow) / (sumWant - sumNarrow);
			for (n = 0; n < others.length; n++) {
				widths.push(narrow[n] + (want[n] - narrow[n]) * share);
			}
		} else if (sumNarrow + floor <= total) {
			// The main column gives up some of its quarter first.
			widths = narrow;
		} else {
			// Too many columns for the screen: some words have to break.
			var scale = (total - floor - sumKeep) / (sumNarrow - sumKeep);
			if (scale <= 0 || !isFinite(scale)) {
				return;
			}
			for (n = 0; n < others.length; n++) {
				widths.push(keep[n] ? narrow[n] : narrow[n] * scale);
			}
		}

		// Every other column gets a width; the main column takes what is left.
		for (n = 0; n < others.length; n++) {
			setWidth(others[n], widths[n]);
		}
	}

	function fit() {
		var tables = document.querySelectorAll('table.wp-list-table.fixed');
		for (var i = 0; i < tables.length; i++) {
			fitTable(tables[i]);
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
