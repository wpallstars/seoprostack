#!/usr/bin/env bash
# Refresh the Brand icons set (assets/brand-icons/) from the newest Simple
# Icons and Font Awesome Free releases on npm. Simple Icons publishes new
# icons every week; run this before a release and commit the result.
#
# Usage: scripts/update-brand-icons.sh
#
# Needs Node.js 18 or newer (for fetch) and tar. Contacts registry.npmjs.org
# only; nothing is published.

set -euo pipefail

die() {
	local message="$1"
	printf 'update-brand-icons: %s\n' "$message" >&2
	exit 1
}

main() {
	cd "$(git rev-parse --show-toplevel)"
	command -v node >/dev/null 2>&1 || die "Node.js 18 or newer is needed"
	command -v tar >/dev/null 2>&1 || die "tar is needed"

	local major
	major="$(node -p 'process.versions.node.split(".")[0]')"
	[[ "$major" -ge 18 ]] || die "Node.js 18 or newer is needed (found $major)"

	node scripts/update-brand-icons.js
	printf 'Files: %s; size: %s\n' "$(find assets/brand-icons -type f | wc -l | tr -d ' ')" "$(du -sh assets/brand-icons | cut -f1)"
	git status --short assets/brand-icons | tail -n 5
	return 0
}

main "$@"
