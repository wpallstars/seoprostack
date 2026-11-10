#!/usr/bin/env node
// Checks docs/HOOKS.md against the code (docs/HOOKS.md → Deprecation rule):
//  - every seoprostack_* filter and action called in the code is listed;
//  - every listed hook is still called, or is called through
//    apply_filters_deprecated() / do_action_deprecated().
// Usage: node scripts/check-hooks.mjs   (from anywhere; exits 1 on a mismatch)
//
// SPDX-License-Identifier: GPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Marcus Quinn
// Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const docPath = path.join(root, 'docs', 'HOOKS.md');

// Walk the tree for PHP files (no external command, so no PATH lookup).
const skipDirs = new Set(['.git', 'node_modules', 'vendor']);
const files = [];
const walk = (dir) => {
	// Repository paths only; nothing comes from user input.
	// eslint-disable-next-line security/detect-non-literal-fs-filename
	for (const entry of fs.readdirSync(path.join(root, dir), { withFileTypes: true })) {
		const rel = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			if (!skipDirs.has(entry.name)) walk(rel);
		} else if (entry.name.endsWith('.php')) {
			files.push(rel);
		}
	}
};
walk('.');

// Live hooks: apply_filters( 'name', do_action( 'name', and _ref_array variants.
// Deprecated: the old name is the first argument of the _deprecated functions.
const liveRe = /\b(?:apply_filters|do_action)(?:_ref_array)?\(\s*'(seoprostack_[a-z0-9_]+)'/g;
const deprecatedRe = /\b(?:apply_filters|do_action)_deprecated\(\s*'(seoprostack_[a-z0-9_]+)'/g;
const live = new Set();
const deprecated = new Set();
for (const file of files) {
	// eslint-disable-next-line security/detect-non-literal-fs-filename
	const text = fs.readFileSync(path.join(root, file), 'utf8');
	for (const m of text.matchAll(liveRe)) live.add(m[1]);
	for (const m of text.matchAll(deprecatedRe)) deprecated.add(m[1]);
	// Runtime-built names: SEOProStack_Link_Cards::render( $items, 'pro', ... )
	// calls apply_filters("seoprostack_{$id}_items"), so the finite set of
	// names is read from the render() call sites.
	for (const m of text.matchAll(/SEOProStack_Link_Cards::render\(\s*[^,]+\(\)\s*,\s*'([a-z0-9_]+)'/g)) live.add(`seoprostack_${m[1]}_items`);
}

// eslint-disable-next-line security/detect-non-literal-fs-filename
if (!fs.existsSync(docPath)) {
	console.error('check-hooks: docs/HOOKS.md is missing.');
	process.exit(1);
}
const listed = new Map();
const problems = [];
// eslint-disable-next-line security/detect-non-literal-fs-filename
for (const line of fs.readFileSync(docPath, 'utf8').split('\n')) {
	const cells = line.split(/(?<!\\)\|/).map((c) => c.trim());
	const m = /^`(seoprostack_[a-z0-9_]+)`$/.exec(cells[1] || '');
	if (!m) continue;
	const [name, type, status] = [m[1], cells[2], cells[3]];
	if (listed.has(name)) problems.push(`${name} is listed twice in docs/HOOKS.md.`);
	if (!['filter', 'action'].includes(type)) problems.push(`${name}: type must be filter or action, not "${type}".`);
	if (!['supported', 'internal'].includes(status)) problems.push(`${name}: status must be supported or internal, not "${status}".`);
	listed.set(name, status);
}

for (const name of [...live].sort((x, y) => x.localeCompare(y))) {
	if (!listed.has(name)) problems.push(`${name} is called in the code but not listed: add it to docs/HOOKS.md.`);
}
for (const [name, status] of listed) {
	if (live.has(name) || deprecated.has(name)) continue;
	if (status === 'supported') {
		problems.push(`${name} is a supported hook that is no longer in the code: keep it, or call it through apply_filters_deprecated() / do_action_deprecated() for at least two minor releases (docs/HOOKS.md → Deprecation rule).`);
	} else {
		problems.push(`${name} is listed as internal but no longer in the code: remove its row from docs/HOOKS.md.`);
	}
}

// Release baseline: a supported hook listed at the latest release tag may not
// vanish unless the code two releases back already called it through a
// _deprecated function (the two-release deprecation period). Skipped, with a
// note, when git or the tags are unavailable (for example a shallow clone).
const git = (...args) => {
	try {
		return execFileSync('git', ['-C', root, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
	} catch {
		return null;
	}
};
const tags = (git('tag', '--list', '--sort=-v:refname') || '').split('\n').filter(Boolean);
if (tags.length >= 3) {
	const baselineDoc = git('show', `${tags[0]}:docs/HOOKS.md`);
	const oldCode = git('grep', '-h', '-E', '_deprecated\\(', tags[2], '--', '*.php') || '';
	const oldDeprecated = new Set([...oldCode.matchAll(deprecatedRe)].map((m) => m[1]));
	for (const line of (baselineDoc || '').split('\n')) {
		const cells = line.split(/(?<!\\)\|/).map((c) => c.trim());
		const m = /^`(seoprostack_[a-z0-9_]+)`$/.exec(cells[1] || '');
		if (!m || cells[3] !== 'supported') continue;
		if (live.has(m[1]) || deprecated.has(m[1]) || oldDeprecated.has(m[1])) continue;
		problems.push(`${m[1]} was a supported hook in ${tags[0]} and is gone without being deprecated in ${tags[2]} or earlier: restore it, or deprecate it for two releases first (docs/HOOKS.md → Deprecation rule).`);
	}
} else {
	console.log('check-hooks: fewer than three release tags found; release baseline check skipped.');
}

if (problems.length) {
	console.error('check-hooks: docs/HOOKS.md and the code disagree:');
	for (const p of problems) console.error(`  - ${p}`);
	process.exit(1);
}
console.log(`check-hooks: ${listed.size} hooks listed (${live.size} in code, ${deprecated.size} deprecated); all match.`);
