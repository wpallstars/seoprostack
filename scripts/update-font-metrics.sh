#!/usr/bin/env bash
# Refresh the font measurements of Keep text still while fonts load
# (assets/font-metrics/) from the newest @capsizecss/metrics release on npm,
# which follows new Google Fonts. Run it now and then, and commit the result.
#
# Usage: scripts/update-font-metrics.sh
#
# Needs Node.js 18 or newer (for fetch) and tar. Contacts registry.npmjs.org
# only; nothing is published.
#
# SPDX-License-Identifier: GPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Marcus Quinn
# Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt
# Provenance: wpas-5149fc42f5cbc37c. Copies, even of part, keep these lines.

set -euo pipefail

die() {
	local message="$1"
	printf 'update-font-metrics: %s\n' "$message" >&2
	exit 1
}

main() {
	cd "$(git rev-parse --show-toplevel)"
	command -v node >/dev/null 2>&1 || die "Node.js 18 or newer is needed"
	command -v tar >/dev/null 2>&1 || die "tar is needed"

	local major
	major="$(node -p 'process.versions.node.split(".")[0]')"
	[[ "$major" -ge 18 ]] || die "Node.js 18 or newer is needed (found $major)"

	node scripts/update-font-metrics.js
	printf 'Size: %s\n' "$(du -h assets/font-metrics/metrics.json | cut -f1)"
	git status --short assets/font-metrics
	return 0
}

main "$@"
