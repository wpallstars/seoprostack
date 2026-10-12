#!/usr/bin/env node
/**
 * Dark mode audit of wp-admin: visits screens of a test site in Dark and
 * reports, per screen, the light surfaces (opaque backgrounds with relative
 * luminance above 0.55 and an area above 1500 px²) and the text below WCAG
 * AA (4.5:1, or 3:1 for large text) against what is painted behind it.
 * A developer tool for the dark fallback and hand-written sections
 * (DESIGN.md → Colour mode), not a CI gate.
 *
 * Settings come from environment variables only; never commit their values:
 *   SPS_AUDIT_URL     the site's address, such as https://example.test
 *   SPS_AUDIT_USER    an administrator's user name (login only)
 *   SPS_AUDIT_PASS    their password (login only)
 *   SPS_AUDIT_STATE   where the login is kept (default: the system temp folder)
 *   PLAYWRIGHT_PATH   Playwright's folder or index.js, when it is not installed globally
 * The person's colour mode must be Dark (or System) on the site.
 *
 * Usage:
 *   node scripts/dark-audit.mjs login
 *   node scripts/dark-audit.mjs urls <urls-file>
 *   node scripts/dark-audit.mjs audit <urls-file> <out-dir> [--shots] [--from=N] [--to=N]
 *   node scripts/dark-audit.mjs compare <before/report.json> <after/report.json>
 *   node scripts/dark-audit.mjs shot <url-or-path> <file.png>
 *
 * `audit` writes <out-dir>/report.json and <out-dir>/summary.md, and with
 * --shots a screenshot of each screen (viewport only).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
 */
import { readFileSync, writeFileSync, mkdirSync, existsSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

const [cmd, ...rest] = process.argv.slice(2);
const flags = Object.fromEntries(rest.filter((a) => a.startsWith('--')).map((a) => {
	const [k, v] = a.slice(2).split('=');
	return [k, v === undefined ? true : v];
}));
const args = rest.filter((a) => !a.startsWith('--'));
const BASE = (process.env.SPS_AUDIT_URL || '').replace(/\/+$/, '');
const STATE = process.env.SPS_AUDIT_STATE || join(tmpdir(), 'sps-dark-audit-state.json');

function fail(message) {
	console.error(message);
	process.exit(1);
}

async function playwright() {
	const tries = [];
	if (process.env.PLAYWRIGHT_PATH) {
		const p = process.env.PLAYWRIGHT_PATH;
		tries.push(existsSync(p) && statSync(p).isDirectory() ? join(p, 'index.js') : p);
	}
	try {
		tries.push(join(execFileSync('npm', ['root', '-g'], { encoding: 'utf8' }).trim(), 'playwright', 'index.js'));
	} catch {
		// npm is not on the path.
	}
	for (const file of tries) {
		if (existsSync(file)) {
			const pw = await import(pathToFileURL(file).href);
			return pw.chromium || pw.default.chromium;
		}
	}
	try {
		const pw = await import('playwright');
		return pw.chromium || pw.default.chromium;
	} catch {
		return fail('Playwright not found: install it globally (npm i -g playwright) or set PLAYWRIGHT_PATH.');
	}
}

async function browser() {
	if (!BASE) {
		fail('Set SPS_AUDIT_URL to the test site\'s address.');
	}
	const chromium = await playwright();
	const b = await chromium.launch({ headless: true });
	const ctx = await b.newContext({
		// Local test sites use self-signed certificates.
		ignoreHTTPSErrors: true,
		viewport: { width: 1440, height: 900 },
		colorScheme: 'dark',
		storageState: cmd !== 'login' && existsSync(STATE) ? STATE : undefined,
	});
	// SEO Pro Stack's Licence checks dialog covers the screen until answered.
	await ctx.addInitScript(() => {
		const add = () => {
			const st = document.createElement('style');
			st.textContent = 'dialog#sps-licence{display:none!important}';
			document.documentElement.appendChild(st);
		};
		if (document.documentElement) {
			add();
		} else {
			new MutationObserver((m, o) => {
				if (document.documentElement) {
					o.disconnect();
					add();
				}
			}).observe(document, { childList: true });
		}
	});
	return { b, ctx };
}

function full(url) {
	return /^https?:/.test(url) ? url : BASE + (url.startsWith('/') ? '' : '/') + url;
}

// Whether an address is one of the site's admin screens.
function own(url) {
	return url.startsWith(BASE + '/wp-admin/');
}

// Runs in the page: light surfaces and text below AA.
function scan() {
	// Any CSS colour (rgb, oklch, color-mix() results) to sRGB through a 1px canvas.
	const cv = document.createElement('canvas');
	cv.width = 1;
	cv.height = 1;
	const cx = cv.getContext('2d', { willReadFrequently: true });
	const cache = new Map();
	function parse(c) {
		if (!c || c === 'transparent' || c === 'none') {
			return null;
		}
		const m = c.match(/^rgba?\(([^)]+)\)$/);
		if (m) {
			const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
			return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
		}
		if (cache.has(c)) {
			return cache.get(c);
		}
		cx.clearRect(0, 0, 1, 1);
		cx.fillStyle = 'rgba(0, 0, 0, 0)';
		cx.fillStyle = c;
		cx.fillRect(0, 0, 1, 1);
		const d = cx.getImageData(0, 0, 1, 1).data;
		const out = d[3] === 0 ? null : { r: d[0], g: d[1], b: d[2], a: d[3] / 255 };
		cache.set(c, out);
		return out;
	}
	function lum(c) {
		const f = (v) => {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
		};
		return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
	}
	// HSL saturation: brand colours (buttons, badges) are saturated, light greys and tints are not light-and-saturated.
	function brand(c) {
		const r = c.r / 255, g = c.g / 255, b = c.b / 255;
		const max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2;
		const s = max === min ? 0 : (max - min) / (1 - Math.abs(2 * l - 1));
		return s > 0.45 && l < 0.85;
	}
	function blend(top, under) {
		const a = top.a;
		return { r: top.r * a + under.r * (1 - a), g: top.g * a + under.g * (1 - a), b: top.b * a + under.b * (1 - a), a: 1 };
	}
	function bgOf(el) {
		const stack = [];
		for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
			const s = getComputedStyle(n);
			if (s.backgroundImage && s.backgroundImage !== 'none' && !/gradient/.test(s.backgroundImage) && n !== document.documentElement && n !== document.body) {
				return null;
			}
			const c = parse(s.backgroundColor);
			if (c && c.a > 0) {
				stack.push(c);
				if (c.a >= 0.99) {
					break;
				}
			}
		}
		let out = { r: 16, g: 21, b: 23, a: 1 };
		for (let i = stack.length - 1; i >= 0; i--) {
			out = blend(stack[i], out);
		}
		return out;
	}
	function name(el) {
		const parts = [];
		for (let n = el, i = 0; n && n.nodeType === 1 && i < 4; n = n.parentElement, i++) {
			let s = n.tagName.toLowerCase();
			if (n.id) {
				s += '#' + n.id;
			}
			const cls = (typeof n.className === 'string' ? n.className : '').trim().split(/\s+/).filter(Boolean).slice(0, 3);
			if (cls.length) {
				s += '.' + cls.join('.');
			}
			parts.unshift(s);
			if (n.id) {
				break;
			}
		}
		return parts.join(' > ');
	}
	function visible(el) {
		const r = el.getBoundingClientRect();
		if (r.width < 2 || r.height < 2) {
			return false;
		}
		const s = getComputedStyle(el);
		return s.visibility !== 'hidden' && s.display !== 'none' && Number(s.opacity) > 0.1;
	}
	const content = document.getElementById('wpbody-content') || document.body;
	const wide = content.getBoundingClientRect().width * 0.9;
	const light = new Map();
	const low = new Map();
	for (const el of document.querySelectorAll('body *')) {
		// Site previews (the block editor's canvas, the site icon's preview) keep the site's colours.
		if (el.closest('#adminmenumain, #wpadminbar, iframe, svg, .screen-reader-text, [aria-hidden="true"], .editor-styles-wrapper, #site-icon-preview')) {
			continue;
		}
		if (!visible(el)) {
			continue;
		}
		const s = getComputedStyle(el);
		const c = parse(s.backgroundColor);
		const r = el.getBoundingClientRect();
		const isLight = c && c.a > 0.5 && lum(c) > 0.55;
		if (isLight && ((r.width * r.height > 1500 && !['IMG', 'VIDEO', 'CANVAS', 'INPUT'].includes(el.tagName)) || (el.tagName === 'INPUT' && r.width > 30))) {
			const k = name(el);
			if (!light.has(k)) {
				light.set(k, { el: k, bg: s.backgroundColor, size: Math.round(r.width) + 'x' + Math.round(r.height), wide: r.width >= wide && r.height >= 40, brand: brand(c), text: (el.innerText || el.value || '').trim().slice(0, 50) });
			}
		}
		// Text owned directly by this element.
		let own = '';
		for (const t of el.childNodes) {
			if (t.nodeType === 3) {
				own += t.textContent;
			}
		}
		own = own.trim();
		if (!own && el.tagName !== 'INPUT' && el.tagName !== 'SELECT') {
			continue;
		}
		if (!own && el.tagName === 'INPUT' && /^(checkbox|radio|hidden|range|color|file|image|submit|button|reset)$/.test(el.type)) {
			continue;
		}
		const fg = parse(s.color);
		const bg = bgOf(el);
		if (!fg || !bg) {
			continue;
		}
		const f = fg.a < 1 ? blend(fg, bg) : fg;
		const L1 = lum(f), L2 = lum(bg);
		const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
		const size = parseFloat(s.fontSize);
		if (!(size > 0)) {
			continue;
		}
		const big = size >= 24 || (size >= 18.66 && Number(s.fontWeight) >= 700);
		const need = big ? 3 : 4.5;
		const disabled = el.matches(':disabled, [aria-disabled="true"], .disabled, .button-disabled') || !!el.closest(':disabled, .disabled');
		if (ratio < need && !disabled) {
			const k = name(el);
			const e = low.get(k) || { el: k, ratio: Math.round(ratio * 100) / 100, fg: s.color, bg: `rgb(${Math.round(bg.r)} ${Math.round(bg.g)} ${Math.round(bg.b)})`, text: (own || el.value || '').slice(0, 40), n: 0 };
			e.n++;
			low.set(k, e);
		}
	}
	const lights = [...light.values()];
	return {
		dark: document.documentElement.classList.contains('sps-dark'),
		fallback: document.querySelectorAll('link[id^="seoprostack-dark-fallback-"]').length,
		title: document.title,
		lightCount: lights.length,
		neutralCount: lights.filter((l) => !l.brand).length,
		wideCount: lights.filter((l) => l.wide && !l.brand).length,
		lowCount: low.size,
		light: lights.slice(0, 40),
		low: [...low.values()].sort((a, b) => a.ratio - b.ratio).slice(0, 40),
	};
}

function path(url) {
	return url.replace(/^https?:\/\/[^/]+/, '');
}

function totals(report) {
	const dark = report.filter((r) => r.dark && !r.error);
	const sum = (k) => dark.reduce((n, r) => n + (r[k] || 0), 0);
	return { screens: report.length, dark: dark.length, notDark: report.filter((r) => !r.dark && !r.error).length, errors: report.filter((r) => r.error).length, light: sum('lightCount'), neutral: sum('neutralCount'), wide: sum('wideCount'), low: sum('lowCount') };
}

function summary(report) {
	const t = totals(report);
	const lines = [
		'# Dark mode audit',
		'',
		`Screens: ${t.screens} (dark ${t.dark}, not dark ${t.notDark}, errors ${t.errors})`,
		'',
		'| Measure | Total |',
		'| --- | ---: |',
		`| Light surfaces | ${t.light} |`,
		`| … not brand colours | ${t.neutral} |`,
		`| … full-width, not brand | ${t.wide} |`,
		`| Text below AA | ${t.low} |`,
		'',
		'## Screens by light surfaces',
		'',
		'| # | Screen | Light | Not brand | Wide | Low text |',
		'| ---: | --- | ---: | ---: | ---: | ---: |',
	];
	[...report].filter((r) => r.dark && !r.error).sort((a, b) => b.lightCount - a.lightCount || b.lowCount - a.lowCount).forEach((r) => {
		lines.push(`| ${r.i} | \`${path(r.url)}\` | ${r.lightCount} | ${r.neutralCount} | ${r.wideCount} | ${r.lowCount} |`);
	});
	const off = report.filter((r) => !r.dark || r.error);
	if (off.length) {
		lines.push('', '## Not dark or not loaded', '');
		off.forEach((r) => lines.push(`- ${r.i} \`${path(r.url)}\`${r.error ? ': ' + r.error : ''}`));
	}
	return lines.join('\n') + '\n';
}

if (cmd === 'login') {
	const user = process.env.SPS_AUDIT_USER;
	const pass = process.env.SPS_AUDIT_PASS;
	if (!user || !pass) {
		fail('Set SPS_AUDIT_USER and SPS_AUDIT_PASS.');
	}
	const { b, ctx } = await browser();
	const page = await ctx.newPage();
	await page.goto(BASE + '/wp-login.php');
	await page.fill('#user_login', user);
	await page.fill('#user_pass', pass);
	await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
	const ok = /\/wp-admin\//.test(page.url());
	await ctx.storageState({ path: STATE });
	await b.close();
	console.log(ok ? 'Logged in; state kept in ' + STATE : 'Login did not reach wp-admin.');
	process.exit(ok ? 0 : 1);
} else if (cmd === 'urls') {
	const [file] = args;
	if (!file) {
		fail('Usage: urls <urls-file>');
	}
	const { b, ctx } = await browser();
	const page = await ctx.newPage();
	await page.goto(BASE + '/wp-admin/', { waitUntil: 'domcontentloaded' });
	// The site's own admin screens only: menus also link to plugins' websites.
	const urls = (await page.$$eval('#adminmenu a[href]', (as) => [...new Set(as.map((a) => a.href.split('#')[0]))])).filter(own);
	writeFileSync(file, urls.join('\n') + '\n');
	console.log(urls.length, 'screens written to', file);
	await b.close();
} else if (cmd === 'audit') {
	const [file, out] = args;
	if (!file || !out) {
		fail('Usage: audit <urls-file> <out-dir> [--shots] [--from=N] [--to=N]');
	}
	mkdirSync(out, { recursive: true });
	const all = readFileSync(file, 'utf8').split('\n').map((s) => s.trim()).filter(Boolean).map(full).filter(own);
	const from = Number(flags.from || 0);
	const to = flags.to === undefined ? all.length : Number(flags.to);
	const { b, ctx } = await browser();
	const report = [];
	for (let i = from; i < Math.min(to, all.length); i++) {
		const url = full(all[i]);
		const page = await ctx.newPage();
		page.setDefaultTimeout(30000);
		try {
			await page.goto(url, { waitUntil: 'load', timeout: 60000 });
			await page.waitForTimeout(2500);
			const res = await page.evaluate(scan);
			if (/\/wp-login\.php/.test(page.url())) {
				throw new Error('logged out: run login');
			}
			report.push({ i, url, ...res });
			if (flags.shots) {
				await page.screenshot({ path: join(out, String(i).padStart(3, '0') + '.png') });
			}
			console.log(i, path(url), 'light', res.lightCount, 'not-brand', res.neutralCount, 'low', res.lowCount, res.dark ? '' : 'NOT DARK');
		} catch (e) {
			report.push({ i, url, error: e.message.split('\n')[0] });
			console.log(i, path(url), 'ERROR', e.message.split('\n')[0]);
		}
		await page.close();
	}
	await b.close();
	writeFileSync(join(out, 'report.json'), JSON.stringify(report, null, 1));
	writeFileSync(join(out, 'summary.md'), summary(report));
	console.log(JSON.stringify(totals(report)));
} else if (cmd === 'compare') {
	const [a, c] = args;
	if (!a || !c) {
		fail('Usage: compare <before/report.json> <after/report.json>');
	}
	const before = JSON.parse(readFileSync(a, 'utf8'));
	const after = JSON.parse(readFileSync(c, 'utf8'));
	const ta = totals(before);
	const tb = totals(after);
	const pct = (x, y) => (x ? Math.round((1 - y / x) * 1000) / 10 + '%' : '-');
	console.log('| Measure | Before | After | Drop |\n| --- | ---: | ---: | ---: |');
	for (const [k, label] of [['light', 'Light surfaces'], ['neutral', 'Not brand colours'], ['wide', 'Full-width, not brand'], ['low', 'Text below AA']]) {
		console.log(`| ${label} | ${ta[k]} | ${tb[k]} | ${pct(ta[k], tb[k])} |`);
	}
	const byPath = new Map(before.map((r) => [path(r.url), r]));
	const worse = after.filter((r) => r.dark && byPath.get(path(r.url)) && (r.lightCount > byPath.get(path(r.url)).lightCount || r.lowCount > byPath.get(path(r.url)).lowCount + 2));
	if (worse.length) {
		console.log('\nScreens with more light surfaces, or more low-contrast text, than before:');
		worse.forEach((r) => {
			const o = byPath.get(path(r.url));
			console.log(`- \`${path(r.url)}\`: light ${o.lightCount} → ${r.lightCount}, low ${o.lowCount} → ${r.lowCount}`);
		});
	}
} else if (cmd === 'shot') {
	const [url, file] = args;
	if (!url || !file) {
		fail('Usage: shot <url-or-path> <file.png>');
	}
	const { b, ctx } = await browser();
	const page = await ctx.newPage();
	await page.goto(full(url), { waitUntil: 'load', timeout: 90000 });
	await page.waitForTimeout(3000);
	await page.screenshot({ path: file });
	const res = await page.evaluate(scan);
	console.log(JSON.stringify({ dark: res.dark, fallback: res.fallback, light: res.light.slice(0, 15), low: res.low.slice(0, 15) }, null, 1));
	await b.close();
} else {
	fail('Usage: node scripts/dark-audit.mjs login | urls <file> | audit <urls-file> <out-dir> [--shots] [--from=N] [--to=N] | compare <a.json> <b.json> | shot <url> <file>');
}
