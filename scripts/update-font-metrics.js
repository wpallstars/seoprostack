#!/usr/bin/env node
/**
 * Refresh assets/font-metrics/ from the newest @capsizecss/metrics release on
 * npm. Run through scripts/update-font-metrics.sh.
 *
 * Writes:
 *   assets/font-metrics/metrics.json  for Keep text still while fonts load
 *                                     (SEOProStack_Font_Fallbacks):
 *     version   the @capsizecss/metrics version
 *     fonts     lower-case family name => [category, unitsPerEm, ascent,
 *               descent, lineGap, xWidthAvg] for the regular face, and five
 *               more for the bold (700) face when the family has one.
 *               Category: s sans-serif, e serif, d display, h handwriting.
 *               Monospace and icon, emoji and barcode fonts are left out.
 *     bases     the local fonts the fallbacks are made from (Arial, Times New
 *               Roman, Roboto, Noto Serif), in the same shape.
 *   assets/font-metrics/LICENSE.txt   source and licence
 *
 * xWidthAvg is Capsize's average character width for Latin text, weighted by
 * how often each letter appears in English; it sets size-adjust.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 * Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'assets', 'font-metrics');
const PACKAGE = '@capsizecss/metrics';
const CATEGORIES = { 'sans-serif': 's', serif: 'e', display: 'd', handwriting: 'h' };
/* Fonts for icons, emoji or barcodes, whose text is not read as words. */
const SKIP = /^(Material (Icons|Symbols)|Noto (Color )?Emoji|Libre Barcode|Redacted|Wavefont|Linefont|Flow (Block|Circular|Rounded))/;
const BASES = ['arial', 'timesNewRoman', 'roboto', 'notoSerif'];

async function fetchPackage(name, dir) {
	const res = await fetch('https://registry.npmjs.org/' + name.replaceAll('/', '%2F') + '/latest');
	if (!res.ok) {
		throw new Error(name + ': registry said ' + res.status);
	}
	const meta = await res.json();
	const tgz = path.join(dir, 'package.tgz');
	const body = await fetch(meta.dist.tarball);
	if (!body.ok) {
		throw new Error(name + ': download said ' + body.status);
	}
	fs.writeFileSync(tgz, Buffer.from(await body.arrayBuffer()));
	execFileSync('tar', ['-xzf', tgz, '-C', dir]);
	return { version: meta.version, dir: path.join(dir, 'package') };
}

/* [unitsPerEm, ascent, descent, lineGap, xWidthAvg] of one face. */
function face(m) {
	const latin = m.subsets && m.subsets.latin && m.subsets.latin.xWidthAvg;
	const values = [m.unitsPerEm, m.ascent, m.descent, m.lineGap, latin || m.xWidthAvg];
	return values.every((v) => Number.isFinite(v)) && m.unitsPerEm > 0 && values[4] > 0 ? values : null;
}

/* Regular, then bold when the family has a bold face. */
function faces(m) {
	const regular = face((m.variants && m.variants.regular) || m);
	if (!regular) {
		return null;
	}
	const bold = m.variants && m.variants['700'] ? face(m.variants['700']) : null;
	return bold ? regular.concat(bold) : regular;
}

async function main() {
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'font-metrics-'));
	try {
		const pkg = await fetchPackage(PACKAGE, tmp);
		const { entireMetricsCollection: all } = require(path.join(pkg.dir, 'dist', 'entireMetricsCollection.cjs'));
		const fonts = {};
		Object.keys(all).sort().forEach((key) => {
			const m = all[key];
			const cat = CATEGORIES[m.category];
			const name = String(m.familyName || '').trim();
			if (!cat || !name || SKIP.test(name)) {
				return;
			}
			const values = faces(m);
			if (values) {
				fonts[name.toLowerCase()] = [cat].concat(values);
			}
		});
		const bases = {};
		BASES.forEach((key) => {
			const values = all[key] ? faces(all[key]) : null;
			if (!values || values.length < 10) {
				throw new Error('no regular and bold metrics for ' + key);
			}
			bases[key] = values;
		});
		fs.mkdirSync(OUT, { recursive: true });
		fs.writeFileSync(path.join(OUT, 'metrics.json'), JSON.stringify({ version: pkg.version, fonts, bases }) + '\n');
		const licence = fs.readFileSync(path.join(pkg.dir, 'LICENSE'), 'utf8').trim();
		fs.writeFileSync(path.join(OUT, 'LICENSE.txt'), [
			'Font metrics in this folder',
			'',
			'metrics.json is made from ' + PACKAGE + ' ' + pkg.version + ' (https://github.com/seek-oss/capsize),',
			'by SEEK, under the MIT License below: the measurements of Google Fonts and',
			'common system fonts, for Keep text still while fonts load. No font files.',
			'',
			licence,
			'',
		].join('\n'));
		console.log(PACKAGE + ' ' + pkg.version + ': ' + Object.keys(fonts).length + ' families, ' + Object.keys(fonts).filter((k) => fonts[k].length > 6).length + ' with bold.');
	} finally {
		fs.rmSync(tmp, { recursive: true, force: true });
	}
}

main().catch((e) => {
	console.error('update-font-metrics: ' + e.message);
	process.exit(1);
});
