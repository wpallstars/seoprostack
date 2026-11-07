#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Marcus Quinn
# Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
# Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.
"""Copy the reviewed plain msgstr into same-msgid "block title" entries.

Block titles come from block.json and are machine-translated on first fill.
When the same msgid already has a reviewed plain entry, that wording wins, so
the block editor and the front end name things alike, and the
"# machine translation" flag goes.

Usage: sync-block-titles.py file.po [...]
"""
import os
import re
import sys


def field(lines, key):
    """Return the joined string value of a PO field, or None."""
    out, found = [], False
    for line in lines:
        if line.startswith(key + ' '):
            found = True
            out.append(line[len(key) + 1:])
        elif found and line.startswith('"'):
            out.append(line)
        elif found:
            break
    if not found:
        return None
    return ''.join(re.findall(r'"((?:[^"\\]|\\.)*)"', '\n'.join(out)))


def sync(path):
    with open(path, encoding='utf-8') as handle:
        text = handle.read()
    blocks = text.split('\n\n')
    plain = {}
    for block in blocks:
        lines = block.split('\n')
        if field(lines, 'msgctxt') is None and field(lines, 'msgid_plural') is None:
            msgid, msgstr = field(lines, 'msgid'), field(lines, 'msgstr')
            if msgid and msgstr:
                plain[msgid] = msgstr
    changed = 0
    for index, block in enumerate(blocks):
        lines = block.split('\n')
        if field(lines, 'msgctxt') != 'block title' or '# machine translation' not in lines:
            continue
        msgid = field(lines, 'msgid')
        if msgid not in plain:
            continue
        new = [line for line in lines if line != '# machine translation']
        start = next(i for i, line in enumerate(new) if line.startswith('msgstr '))
        blocks[index] = '\n'.join(new[:start] + ['msgstr "%s"' % plain[msgid]])
        changed += 1
    if changed:
        with open(path, 'w', encoding='utf-8') as handle:
            handle.write('\n\n'.join(blocks))
    print('%s: %d block title entries synced' % (path, changed))


LANGUAGES = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'languages')

for name in sys.argv[1:]:
    # Only bundled catalogues: a bare file name, never a path.
    if not re.fullmatch(r'seoprostack-[A-Za-z_]+\.po', os.path.basename(name)):
        sys.exit('Not a bundled catalogue: %s' % os.path.basename(name))
    sync(os.path.normpath(os.path.join(LANGUAGES, os.path.basename(name))))
