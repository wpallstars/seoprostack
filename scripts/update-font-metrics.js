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
 *               descent, lineGap, lowerWidth, capsWidth, spaceWidth] for
 *               the regular face, and seven more for the bold (700) face
 *               when the family has one. Category: s sans-serif, e serif,
 *               d display, h handwriting. Monospace and icon, emoji and
 *               barcode fonts are left out.
 *     bases     the local fonts the fallbacks are made from (Arial, Times New
 *               Roman, Roboto, Noto Serif), in the same shape.
 *   assets/font-metrics/LICENSE.txt   sources and licence
 *
 * The heights (unitsPerEm, ascent, descent, lineGap) are Capsize's. The
 * widths, in the font's units, are measured here from the TrueType copy
 * Google Fonts serves with just the letters a to z, A to Z and the space
 * (Arimo and Tinos for Arial and Times New Roman: they have the same
 * widths): lowerWidth and capsWidth are the average small letter and capital,
 * weighted by how often each letter appears in English, and spaceWidth is the
 * space. Fonts' small letters, capitals and spaces are not in the same
 * proportion, so the fallback sizes each on its own. A family Google Fonts
 * does not serve at these weights is left out. No font file is kept.
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
/* Letters, most used first, and how often each appears in English (%). */
const LOWER = 'etaoinshrdlcumwfgypbvkjxqz';
const CAPS = LOWER.toUpperCase();
const FREQ = [12.70, 9.06, 8.17, 7.51, 6.97, 6.75, 6.33, 6.09, 5.99, 4.25, 4.03, 2.78, 2.76, 2.41, 2.36, 2.23, 2.02, 1.97, 1.93, 1.29, 0.98, 0.77, 0.15, 0.15, 0.10, 0.07];
/* The characters measured: a Google Fonts text= subset of just these. */
const MEASURED = LOWER + CAPS + ' ';
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

/*
 * [small letters' average, capitals' average, space] advance widths per em
 * (letters weighted by English frequency), or null when a character is
 * missing.
 */
function widthsPerEm(b) {
	const t = sfntTables(b);
	if (!t.head || !t.hhea || !t.hmtx || !t.cmap) {
		return null;
	}
	const upm = b.readUInt16BE(t.head + 18);
	const metrics = b.readUInt16BE(t.hhea + 34);
	const glyph = cmapLookup(b, t.cmap);
	if (!glyph || !upm || !metrics) {
		return null;
	}
	const advance = (ch) => {
		const g = glyph(ch.charCodeAt(0));
		return g ? b.readUInt16BE(t.hmtx + Math.min(g, metrics - 1) * 4) / upm : 0;
	};
	const average = (letters) => {
		let sum = 0;
		let weight = 0;
		for (let i = 0; i < letters.length; i++) {
			const w = advance(letters[i]);
			if (!w) {
				return 0;
			}
			sum += w * FREQ[i];
			weight += FREQ[i];
		}
		return sum / weight;
	};
	const out = [average(LOWER), average(CAPS), advance(' ')];
	return out.every((v) => v > 0) ? out : null;
}

/* A Google Fonts family's widths per em (widthsPerEm): weight => values. */
async function googleWidths(family, weights) {
	const url = GOOGLE_CSS + '?family=' + encodeURIComponent(family) + ':wght@' + weights.join(';') + '&text=' + encodeURIComponent(MEASURED);
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
				const widths = font.ok ? widthsPerEm(Buffer.from(await font.arrayBuffer())) : null;
				if (widths) {
					out[m[1]] = widths;
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

/* [unitsPerEm, ascent, descent, lineGap] of one face. */
function face(m) {
	const values = [m.unitsPerEm, m.ascent, m.descent, m.lineGap];
	return values.every((v) => Number.isFinite(v)) && m.unitsPerEm > 0 ? values : null;
}

/* Regular, then bold when the family has a bold face: { regular, bold }. */
function faces(m) {
	const regular = face((m.variants && m.variants.regular) || m);
	if (!regular) {
		return null;
	}
	return { regular, bold: m.variants && m.variants['700'] ? face(m.variants['700']) : null };
}

/*
 * A family's values, each face with its widths (in its units) added, or null
 * when Google Fonts does not serve it. Bold is left out when Google Fonts has
 * none at 700.
 */
async function measured(f, google) {
	let widths = await googleWidths(google, f.bold ? [400, 700] : [400]);
	if (f.bold && !widths['400']) {
		widths = await googleWidths(google, [400]);
	}
	if (!widths['400']) {
		return null;
	}
	const add = (values, weight) => values.concat(widths[weight].map((w) => Math.round(w * values[0])));
	return f.bold && widths['700'] ? add(f.regular, '400').concat(add(f.bold, '700')) : add(f.regular, '400');
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
		const left = [];
		await eachLimited(todo, async (item) => {
			const values = await measured(item.f, item.name);
			if (values) {
				fonts[item.key] = [item.cat].concat(values);
			} else {
				left.push(item.name);
			}
			if (++done % 200 === 0) {
				console.log('Measured widths: ' + done + ' of ' + todo.length);
			}
		});
		const bases = {};
		for (const key of Object.keys(BASES)) {
			const f = all[key] ? faces(all[key]) : null;
			const values = f && f.bold ? await measured(f, BASES[key]) : null;
			if (!values || values.length !== 14) {
				throw new Error('no regular and bold metrics and widths for ' + key);
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
			'by SEEK, under the MIT License below: the heights of Google Fonts, for Keep',
			'text still while fonts load. The widths of the small letters, capitals and',
			'space are measured by scripts/update-font-metrics.js from the fonts Google',
			'Fonts serves (each under its own open licence). No font files.',
			'',
			licence,
			'',
		].join('\n'));
		const keys = Object.keys(sorted);
		console.log(PACKAGE + ' ' + pkg.version + ': ' + keys.length + ' families, ' + keys.filter((k) => sorted[k].length > 8).length + ' with bold; left out (not on Google Fonts at 400): ' + left.sort().join(', '));
	} finally {
		fs.rmSync(tmp, { recursive: true, force: true });
	}
}

main().catch((e) => {
	console.error('update-font-metrics: ' + e.message);
	process.exit(1);
});
