#!/usr/bin/env bash
# Extract and compile SEO Pro Stack's bundled translation catalogues.
# Run before a release commit; release zips are built from committed files.
# Usage: bash scripts/translations.sh [pot|compile|all]
#
# SPDX-License-Identifier: GPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Marcus Quinn
# Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
# Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.

set -euo pipefail

root="$(git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --show-toplevel)"
mode="${1:-all}"

case "$mode" in
pot|compile|all) ;;
*) printf 'Usage: bash scripts/translations.sh [pot|compile|all]\n' >&2; exit 1 ;;
esac

if [[ "$mode" != compile ]]; then
    # A fixed creation date avoids timestamp-only changes on every extraction.
    wp i18n make-pot "$root" "$root/languages/seoprostack.pot" \
        --slug=seoprostack --domain=seoprostack --exclude=dist,scripts \
        --headers='{"POT-Creation-Date":"2026-11-01 00:00+0000"}'
fi

if [[ "$mode" != pot ]]; then
    for locale in nl_BE de_DE fr_CA; do
        po="$root/languages/seoprostack-$locale.po"
        msgfmt --check --check-format -o "$root/languages/seoprostack-$locale.mo" "$po"
        # Keep JS entries in the PO: the same source is also used for MO files.
        wp i18n make-json "$po" "$root/languages" --no-purge --pretty-print
    done
fi
