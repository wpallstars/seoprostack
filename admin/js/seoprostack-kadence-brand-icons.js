/**
 * Brand icons in Kadence Blocks' icon pickers.
 *
 * Kadence reads its icons through the kadence.icon_options (name => {vB, cD})
 * and kadence.icon_options_names (category => names) filters each time a
 * picker or icon is drawn. Icons already in use come with the page; the
 * list and the other shapes load from the plugin's icon files in the
 * background, which the browser keeps.
 */
(function () {
	'use strict';
	var config = window.seoprostackKadenceBrandIcons;
	if (!config || !window.wp || !wp.hooks || !window.fetch) {
		return;
	}
	var icons = Object.assign({}, config.icons || {});
	var names = [];

	function get(file) {
		return fetch(config.base + file + '?ver=' + encodeURIComponent(config.ver), { credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) {
				throw new Error(file + ': ' + r.status);
			}
			return r.json();
		});
	}

	function entry(shape) {
		// A few shapes are stored as objects ({"0": viewBox, "1": path}).
		shape = Array.isArray(shape) ? shape : Object.values(shape);
		return {
			vB: shape[0],
			cD: shape.slice(1).map(function (d) {
				return { nE: 'path', aBs: { d: d } };
			}),
		};
	}

	wp.hooks.addFilter('kadence.icon_options', 'seoprostack/brand-icons', function (options) {
		return Object.assign({}, options, icons);
	});
	wp.hooks.addFilter('kadence.icon_options_names', 'seoprostack/brand-icons', function (categories) {
		if (!names.length) {
			return categories;
		}
		var out = Object.assign({}, categories);
		out[config.label] = names;
		return out;
	});

	get('index.json')
		.then(function (index) {
			var shards = {};
			var listed = [];
			(index.icons || []).forEach(function (row) {
				listed.push(config.prefix + row[0]);
				shards[row[4]] = true;
			});
			return Promise.all(
				Object.keys(shards).map(function (shard) {
					return get('shapes-' + shard + '.json').then(function (shapes) {
						Object.keys(shapes).forEach(function (slug) {
							icons[config.prefix + slug] = entry(shapes[slug]);
						});
					});
				})
			).then(function () {
				// Only icons with shapes; the category shows once all are in.
				names = listed.filter(function (name) {
					return icons[name];
				});
			});
		})
		.catch(function (e) {
			window.console.error('SEO Pro Stack brand icons:', e);
		});
})();
