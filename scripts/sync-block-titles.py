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
import shutil
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


def plain_strings(blocks):
    """Map msgid to msgstr for every translated plain entry."""
    plain = {}
    for block in blocks:
        lines = block.split('\n')
        if field(lines, 'msgctxt') is not None or field(lines, 'msgid_plural') is not None:
            continue
        msgid, msgstr = field(lines, 'msgid'), field(lines, 'msgstr')
        marked = '# machine translation' in lines
        fuzzy = any('fuzzy' in re.split(r'\s*,\s*', line[2:].strip())
                    for line in lines if line.startswith('#,'))
        # Unreviewed (marked or fuzzy) wording must not be copied as reviewed.
        if msgid and msgstr and not marked and not fuzzy:
            plain[msgid] = msgstr
    return plain


def synced_block(block, plain):
    """Return the block with the reviewed msgstr, or None if unchanged."""
    lines = block.split('\n')
    if field(lines, 'msgctxt') != 'block title' or '# machine translation' not in lines:
        return None
    msgid = field(lines, 'msgid')
    if msgid not in plain:
        return None
    start = next(i for i, line in enumerate(lines) if line.startswith('msgstr '))
    end = start + 1
    while end < len(lines) and lines[end].startswith('"'):
        end += 1
    head = [line for line in lines[:start] if line != '# machine translation']
    return '\n'.join([*head, 'msgstr "%s"' % plain[msgid], *lines[end:]])


def sync(path):
    """Sync the block title entries of one catalogue and report the count."""
    with open(path, encoding='utf-8') as handle:
        blocks = handle.read().split('\n\n')
    plain = plain_strings(blocks)
    changed = 0
    for index, block in enumerate(blocks):
        result = synced_block(block, plain)
        if result is not None:
            blocks[index] = result
            changed += 1
    if changed:
        tmp = path + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as handle:
            handle.write('\n\n'.join(blocks))
        shutil.copymode(path, tmp)
        os.replace(tmp, path)
    print('%s: %d block title entries synced' % (path, changed))


LANGUAGES = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'languages')

for name in sys.argv[1:]:
    # Only bundled catalogues: a bare file name, never a path.
    if not re.fullmatch(r'seoprostack-[A-Za-z0-9_@-]+\.po', os.path.basename(name)):
        sys.exit('Not a bundled catalogue: %s' % os.path.basename(name))
    sync(os.path.normpath(os.path.join(LANGUAGES, os.path.basename(name))))
