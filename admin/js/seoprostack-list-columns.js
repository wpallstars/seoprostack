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
 * measures how wide each column wants to be, keeps narrow ones (checkboxes,
 * icons) as they are, narrows the rest in proportion and leaves the main
 * column at least a quarter. Lists that already fit are left alone. Checked
 * again after Screen Options changes and window resizes.
 *
 * @package SEOProStack
 */
(function () {
	'use strict';

	// The main column should have at least this share of the table...
	var MIN = 0.2;
	// ...and gets this share when it needs room.
	var GIVE = 0.25;
	// A column narrower than this has been squeezed out.
	var SQUEEZED = 24;
	// Columns this narrow or less (checkboxes, icons, counts) keep their width.
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
		var squeezed = false;
		var fixed = [];
		for (var i = 0; i < cols.length; i++) {
			fixed.push(width(cols[i]));
			if (cols[i] !== head && fixed[i] < SQUEEZED) {
				squeezed = true;
			}
		}
		if (!squeezed && width(head) / total >= MIN) {
			return;
		}

		// How wide each column wants to be, laid out by its content. Small
		// columns keep the larger of that and the width they were given.
		var layout = table.style.tableLayout;
		table.style.tableLayout = 'auto';
		var natural = [];
		for (var j = 0; j < cols.length; j++) {
			var w = width(cols[j]);
			natural.push(w <= SMALL ? Math.max(w, Math.min(fixed[j], SMALL)) : w);
		}
		table.style.tableLayout = layout;

		var room = total - Math.max(total * GIVE, Math.min(natural[cols.indexOf(head)], total * 0.4));
		var flexible = 0;
		for (var k = 0; k < cols.length; k++) {
			if (cols[k] === head) {
				continue;
			}
			if (natural[k] <= SMALL) {
				room -= natural[k];
			} else {
				flexible += natural[k];
			}
		}
		if (room <= 0 || flexible <= 0) {
			return;
		}
		var scale = Math.min(1, room / flexible);

		// Every other column gets a width; the main column takes what is left.
		for (var n = 0; n < cols.length; n++) {
			if (cols[n] === head) {
				continue;
			}
			setWidth(cols[n], natural[n] <= SMALL ? natural[n] : natural[n] * scale);
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
