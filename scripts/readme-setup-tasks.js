#!/usr/bin/env node
// Copies the Website Setup board's tasks from starters/fluent-boards.json into
// README.md, between <!-- setup-tasks:start --> and <!-- setup-tasks:end -->,
// so the README gives the same steps and links as the board. Each task becomes
// a heading one level below the README section holding the markers, with its
// description as written (Markdown). Edit the tasks in the JSON, then run this.
//
// Usage: node scripts/readme-setup-tasks.js [--check] [--dir DIR]
//   --check  change nothing; exit 1 if README.md differs from the tasks.
//   --dir    the plugin folder (default: this script's parent folder).
//
// SPDX-License-Identifier: GPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Marcus Quinn

'use strict';

const fs = require('fs');
const path = require('path');

const START = '<!-- setup-tasks:start -->';
const END = '<!-- setup-tasks:end -->';
const NOTE = '<!-- Made by scripts/readme-setup-tasks.js from starters/fluent-boards.json: edit the tasks there, then run it. -->';

const args = process.argv.slice(2);
const check = args.includes('--check');
const dirAt = args.indexOf('--dir');
const dir = dirAt >= 0 && args[dirAt + 1] ? path.resolve(args[dirAt + 1]) : path.resolve(__dirname, '..');

const readmePath = path.join(dir, 'README.md');
const tasksPath = path.join(dir, 'starters', 'fluent-boards.json');

function fail(message) {
	process.stderr.write('readme-setup-tasks: ' + message + '\n');
	process.exit(1);
}

let readme;
let starter;
try {
	readme = fs.readFileSync(readmePath, 'utf8');
	starter = JSON.parse(fs.readFileSync(tasksPath, 'utf8'));
} catch (e) {
	fail(e.message);
}

const tasks = ((starter.items || {}).fluentboards_tasks || []).filter((t) => t && t.board === 'Website Setup' && t.title);
const start = readme.indexOf(START);
const end = readme.indexOf(END);
if (start < 0 || end < start) {
	fail('README.md has no ' + START + ' … ' + END + ' block');
}
if (!tasks.length) {
	fail('starters/fluent-boards.json has no Website Setup tasks');
}

// One level below the heading the block sits under (at most ######).
const above = readme.slice(0, start).match(/^(#{1,6}) /gm);
const level = Math.min(6, (above ? above[above.length - 1].trim().length : 1) + 1);
const hashes = '#'.repeat(level);

const body = [START, NOTE, ''];
tasks.forEach((task, i) => {
	body.push(hashes + ' ' + (i + 1) + '. ' + task.title, '');
	body.push(String(task.description || '').trim().replace(/\r\n/g, '\n'), '');
});
body.push(END);

const next = readme.slice(0, start) + body.join('\n') + readme.slice(end + END.length);
if (next === readme) {
	process.stdout.write('README.md matches the ' + tasks.length + ' Website Setup tasks\n');
	process.exit(0);
}
if (check) {
	fail('README.md differs from the Website Setup tasks in starters/fluent-boards.json: run node scripts/readme-setup-tasks.js');
}
fs.writeFileSync(readmePath, next);
process.stdout.write('README.md: wrote the ' + tasks.length + ' Website Setup tasks\n');
