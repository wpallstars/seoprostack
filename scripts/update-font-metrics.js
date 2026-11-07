#!/usr/bin/env node
/**
 * Refresh assets/font-metrics/ from the newest @capsizecss/metrics release on
 * npm, and the capital letters' widths from Google Fonts. Run through
 * scripts/update-font-metrics.sh.
 *
 * Writes:
 *   assets/font-metrics/metrics.json  for Keep text still while fonts load
 *                                     (SEOProStack_Font_Fallbacks):
 *     version   the @capsizecss/metrics version
 *     fonts     lower-case family name => [category, unitsPerEm, ascent,
 *               descent, lineGap, xWidthAvg, capsWidthAvg] for the regular
 *               face, and six more for the bold (700) face when the family
 *               has one. Category: s sans-serif, e serif, d display,
 *               h handwriting. Monospace and icon, emoji and barcode fonts
 *               are left out.
 *     bases     the local fonts the fallbacks are made from (Arial, Times New
 *               Roman, Roboto, Noto Serif), in the same shape.
 *   assets/font-metrics/LICENSE.txt   sources and licence
 *
 * xWidthAvg is Capsize's average character width for Latin text, weighted by
 * how often each letter appears in English; it sets size-adjust.
 * capsWidthAvg is the same for the capital letters A to Z, measured here from
 * the TrueType copy Google Fonts serves with just those letters (Arimo and
 * Tinos for Arial and Times New Roman: they have the same widths). Fonts'
 * capitals and small letters are not in the same proportion, so the
 * fallback sizes its capitals on their own (0 when not measured: a font not
 * on Google Fonts). No font file is kept.
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
/* The local fonts, and the Google Fonts family with their widths. */
const BASES = { arial: 'Arimo', timesNewRoman: 'Tinos', roboto: 'Roboto', notoSerif: 'Noto Serif' };
const GOOGLE_CSS = 'https://fonts.googleapis.com/css2';
/* Capitals, most used first, and how often each appears in English (%). */
const CAPS = 'ETAOINSHRDLCUMWFGYPBVKJXQZ';
const CAPS_FREQ = [12.70, 9.06, 8.17, 7.51, 6.97, 6.75, 6.33, 6.09, 5.99, 4.25, 4.03, 2.78, 2.76, 2.41, 2.36, 2.23, 2.02, 1.97, 1.93, 1.29, 0.98, 0.77, 0.15, 0.15, 0.10, 0.07];
/* Google Fonts requests at a time. */
const PARALLEL = 12;

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

/* A TrueType font's tables: tag => offset. */
function sfntTables(b) {
	const out = {};
	if (b.length < 12) {
		return out;
	}
	const count = b.readUInt16BE(4);
	for (let i = 0; i < count && 12 + i * 16 + 16 <= b.length; i++) {
		const at = 12 + i * 16;
		out[b.toString('latin1', at, at + 4)] = b.readUInt32BE(at + 8);
	}
	return out;
}

/* Character code => glyph index, from a cmap table (formats 4 and 12), or null. */
function cmapLookup(b, at) {
	const count = b.readUInt16BE(at + 2);
	let sub = -1;
	for (let i = 0; i < count; i++) {
		const rec = at + 4 + i * 8;
		const platform = b.readUInt16BE(rec);
		const encoding = b.readUInt16BE(rec + 2);
		if (platform === 0 || (platform === 3 && (encoding === 1 || encoding === 10))) {
			sub = at + b.readUInt32BE(rec + 4);
			if (platform === 3) {
				break;
			}
		}
	}
	if (sub < 0) {
		return null;
	}
	const format = b.readUInt16BE(sub);
	if (format === 4) {
		const segs = b.readUInt16BE(sub + 6) / 2;
		const ends = sub + 14;
		const starts = ends + segs * 2 + 2;
		const deltas = starts + segs * 2;
		const ranges = deltas + segs * 2;
		return (code) => {
			for (let i = 0; i < segs; i++) {
				if (b.readUInt16BE(ends + i * 2) < code) {
					continue;
				}
				const start = b.readUInt16BE(starts + i * 2);
				if (start > code) {
					return 0;
				}
				const delta = b.readInt16BE(deltas + i * 2);
				const range = b.readUInt16BE(ranges + i * 2);
				if (!range) {
					return (code + delta) & 0xffff;
				}
				const glyph = b.readUInt16BE(ranges + i * 2 + range + (code - start) * 2);
				return glyph ? (glyph + delta) & 0xffff : 0;
			}
			return 0;
		};
	}
	if (format === 12) {
		const groups = b.readUInt32BE(sub + 12);
		return (code) => {
			for (let i = 0; i < groups; i++) {
				const g = sub + 16 + i * 12;
				if (code >= b.readUInt32BE(g) && code <= b.readUInt32BE(g + 4)) {
					return b.readUInt32BE(g + 8) + code - b.readUInt32BE(g);
				}
			}
			return 0;
		};
	}
	return null;
}

/* Average advance width of A to Z (English frequency), per em, or 0. */
function capsPerEm(b) {
	const t = sfntTables(b);
	if (!t.head || !t.hhea || !t.hmtx || !t.cmap) {
		return 0;
	}
	const upm = b.readUInt16BE(t.head + 18);
	const metrics = b.readUInt16BE(t.hhea + 34);
	const glyph = cmapLookup(b, t.cmap);
	if (!glyph || !upm || !metrics) {
		return 0;
	}
	let sum = 0;
	let weight = 0;
	for (let i = 0; i < CAPS.length; i++) {
		const g = glyph(CAPS.charCodeAt(i));
		if (!g) {
			return 0;
		}
		sum += b.readUInt16BE(t.hmtx + Math.min(g, metrics - 1) * 4) * CAPS_FREQ[i];
		weight += CAPS_FREQ[i];
	}
	return sum / weight / upm;
}

/* Capital letters' average width per em of a Google Fonts family: weight => value. */
async function googleCaps(family, weights) {
	const url = GOOGLE_CSS + '?family=' + encodeURIComponent(family) + ':wght@' + weights.join(';') + '&text=' + CAPS;
	for (let attempt = 1; attempt <= 3; attempt++) {
		try {
			const res = await fetch(url);
			if (res.status === 400) {
				return {}; // Not on Google Fonts, or not at these weights.
			}
			if (!res.ok) {
				throw new Error('Google Fonts said ' + res.status);
			}
			const out = {};
			for (const m of (await res.text()).matchAll(/font-weight:\s*(\d+);[^}]*?src:\s*url\(([^)]+)\)\s*format\('truetype'\)/g)) {
				const font = await fetch(m[2]);
				if (font.ok) {
					out[m[1]] = capsPerEm(Buffer.from(await font.arrayBuffer()));
				}
			}
			return out;
		} catch (e) {
			if (attempt === 3) {
				throw new Error(family + ': ' + e.message);
			}
			await new Promise((r) => setTimeout(r, attempt * 2000));
		}
	}
	return {};
}

/* Run fn on each item, PARALLEL at a time. */
async function eachLimited(items, fn) {
	let next = 0;
	const workers = Array.from({ length: PARALLEL }, async () => {
		while (next < items.length) {
			const item = items[next++];
			await fn(item);
		}
	});
	await Promise.all(workers);
}

/* [unitsPerEm, ascent, descent, lineGap, xWidthAvg] of one face. */
function face(m) {
	const latin = m.subsets && m.subsets.latin && m.subsets.latin.xWidthAvg;
	const values = [m.unitsPerEm, m.ascent, m.descent, m.lineGap, latin || m.xWidthAvg];
	return values.every((v) => Number.isFinite(v)) && m.unitsPerEm > 0 && values[4] > 0 ? values : null;
}

/* Regular, then bold when the family has a bold face: { regular, bold }. */
function faces(m) {
	const regular = face((m.variants && m.variants.regular) || m);
	if (!regular) {
		return null;
	}
	return { regular, bold: m.variants && m.variants['700'] ? face(m.variants['700']) : null };
}

/* A family's values, each face with its capitals' width (in its units) added. */
async function measured(f, google) {
	const caps = await googleCaps(google, f.bold ? [400, 700] : [400]);
	const add = (values, weight) => values.concat(Math.round((caps[weight] || 0) * values[0]));
	return f.bold ? add(f.regular, '400').concat(add(f.bold, '700')) : add(f.regular, '400');
}

async function main() {
	const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'font-metrics-'));
	try {
		const pkg = await fetchPackage(PACKAGE, tmp);
		const { entireMetricsCollection: all } = require(path.join(pkg.dir, 'dist', 'entireMetricsCollection.cjs'));
		const fonts = {};
		const todo = [];
		Object.keys(all).sort().forEach((key) => {
			const m = all[key];
			const cat = CATEGORIES[m.category];
			const name = String(m.familyName || '').trim();
			const f = cat && name && !SKIP.test(name) ? faces(m) : null;
			if (f) {
				todo.push({ key: name.toLowerCase(), name, cat, f });
			}
		});
		let done = 0;
		await eachLimited(todo, async (item) => {
			fonts[item.key] = [item.cat].concat(await measured(item.f, item.name));
			if (++done % 200 === 0) {
				console.log('Measured capitals: ' + done + ' of ' + todo.length);
			}
		});
		const bases = {};
		for (const key of Object.keys(BASES)) {
			const f = all[key] ? faces(all[key]) : null;
			const values = f && f.bold ? await measured(f, BASES[key]) : null;
			if (!values || !values[5] || !values[11]) {
				throw new Error('no regular and bold metrics and capitals for ' + key);
			}
			bases[key] = values;
		}
		// In name order, as they finish in any order.
		const sorted = {};
		Object.keys(fonts).sort().forEach((key) => {
			sorted[key] = fonts[key];
		});
		fs.mkdirSync(OUT, { recursive: true });
		fs.writeFileSync(path.join(OUT, 'metrics.json'), JSON.stringify({ version: pkg.version, fonts: sorted, bases }) + '\n');
		const licence = fs.readFileSync(path.join(pkg.dir, 'LICENSE'), 'utf8').trim();
		fs.writeFileSync(path.join(OUT, 'LICENSE.txt'), [
			'Font metrics in this folder',
			'',
			'metrics.json is made from ' + PACKAGE + ' ' + pkg.version + ' (https://github.com/seek-oss/capsize),',
			'by SEEK, under the MIT License below: the measurements of Google Fonts and',
			'common system fonts, for Keep text still while fonts load. The capital',
			'letters\' average widths are measured by scripts/update-font-metrics.js from',
			'the fonts Google Fonts serves (each under its own open licence). No font',
			'files.',
			'',
			licence,
			'',
		].join('\n'));
		const keys = Object.keys(sorted);
		console.log(PACKAGE + ' ' + pkg.version + ': ' + keys.length + ' families, ' + keys.filter((k) => sorted[k].length > 7).length + ' with bold, ' + keys.filter((k) => sorted[k][6] > 0).length + ' with capitals measured.');
	} finally {
		fs.rmSync(tmp, { recursive: true, force: true });
	}
}

main().catch((e) => {
	console.error('update-font-metrics: ' + e.message);
	process.exit(1);
});
