#!/usr/bin/env bash
# Run Plugin Check (the WordPress.org review tool) on the release zips, in a
# disposable WordPress in Docker. Nothing is kept: the containers, volume and
# network are removed when it ends, and no other site is touched.
#
# Usage: scripts/plugin-check.sh [--ref REF] [--zip FILE]... [--keep-output DIR]
#   --ref REF          Build both zips from REF (default: HEAD) and check them.
#   --zip FILE         Check this zip instead (repeatable); it must hold one
#                      seoprostack/ folder.
#   --keep-output DIR  Save each full report as JSON in DIR.
#
# Exit status: 0 when no zip has Plugin Check errors. Warnings are listed;
# review them before a WordPress.org submission.
# Needs Docker and internet access (WordPress and Plugin Check are downloaded).

set -euo pipefail

readonly SLUG="seoprostack"
readonly CLI_IMAGE="${SEOPROSTACK_CLI_IMAGE:-wordpress:cli-php8.3}"
readonly DB_IMAGE="${SEOPROSTACK_DB_IMAGE:-mariadb:10.6}"
readonly DB_PASSWORD="plugincheck"

NAME="sps-plugincheck-$$"
TMP_DIR=""
STARTED=0

die() {
	local message="$1"
	printf 'plugin-check: %s\n' "$message" >&2
	exit 2
}

usage() {
	sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'
	return 0
}

cleanup() {
	if [ "$STARTED" -eq 1 ]; then
		docker rm -f "$NAME-db" >/dev/null 2>&1 || true
		docker volume rm "$NAME-wp" >/dev/null 2>&1 || true
		docker network rm "$NAME" >/dev/null 2>&1 || true
	fi
	if [ -n "$TMP_DIR" ] && [ -d "$TMP_DIR" ]; then
		rm -rf "$TMP_DIR"
	fi
	return 0
}

# wp-cli in the disposable site; zips are mounted at /zips.
wp_cli() {
	docker run --rm --network "$NAME" -v "$NAME-wp:/var/www/html" -v "$TMP_DIR/zips:/zips:ro" \
		-e WORDPRESS_DB_HOST="$NAME-db" --user 33:33 "$CLI_IMAGE" wp "$@"
	return $?
}

start_site() {
	printf 'Starting a disposable WordPress (%s)...\n' "$NAME"
	STARTED=1
	docker network create "$NAME" >/dev/null
	docker volume create "$NAME-wp" >/dev/null
	docker run -d --name "$NAME-db" --network "$NAME" \
		-e MARIADB_ROOT_PASSWORD="$DB_PASSWORD" -e MARIADB_DATABASE=wordpress "$DB_IMAGE" >/dev/null
	# The volume starts owned by root; let www-data (33) write to it.
	docker run --rm -v "$NAME-wp:/var/www/html" --user 0:0 "$CLI_IMAGE" chown 33:33 /var/www/html

	local waited=0
	until docker exec "$NAME-db" mariadb-admin ping -uroot -p"$DB_PASSWORD" --silent >/dev/null 2>&1; do
		[ "$waited" -lt 90 ] || die "the database did not start"
		sleep 2
		waited=$((waited + 2))
	done

	wp_cli core download --quiet
	wp_cli config create --dbname=wordpress --dbuser=root --dbpass="$DB_PASSWORD" --dbhost="$NAME-db" --skip-check --quiet
	wp_cli core install --url=http://localhost --title=PluginCheck --admin_user=admin --admin_password="$DB_PASSWORD" \
		--admin_email=admin@example.com --skip-email --quiet
	wp_cli plugin install plugin-check --activate --quiet
	printf 'WordPress %s, Plugin Check %s\n' "$(wp_cli core version)" "$(wp_cli plugin get plugin-check --field=version)"
	return 0
}

# Check one zip; returns 1 when Plugin Check reports errors.
check_zip() {
	local zip_name="$1"
	local keep="$2"
	local report errors warnings
	printf '\n== %s ==\n' "$zip_name"
	wp_cli plugin install "/zips/$zip_name" --force --quiet
	report="$(wp_cli plugin check "$SLUG" --format=json 2>&1 || true)"
	if [ -n "$keep" ]; then
		printf '%s\n' "$report" >"$keep/${zip_name%.zip}-plugin-check.json"
	fi
	errors="$(printf '%s\n' "$report" | grep -o '"type":"ERROR"' | wc -l | tr -d ' ')"
	warnings="$(printf '%s\n' "$report" | grep -o '"type":"WARNING"' | wc -l | tr -d ' ')"
	# Readable summary: one line per finding (type, code, file:line).
	printf '%s\n' "$report" | awk '
		/^FILE: / { file = substr($0, 7); next }
		/^\[/ {
			n = split($0, items, "},{")
			for (i = 1; i <= n; i++) {
				t = items[i]; c = items[i]; l = items[i]
				sub(/.*"type":"/, "", t); sub(/".*/, "", t)
				sub(/.*"code":"/, "", c); sub(/".*/, "", c)
				sub(/.*"line":/, "", l); sub(/[^0-9].*/, "", l)
				printf "  %-7s %s  %s:%s\n", t, c, file, l
			}
		}'
	printf '%s error(s), %s warning(s)\n' "$errors" "$warnings"
	wp_cli plugin delete "$SLUG" --quiet || true
	[ "$errors" -eq 0 ] || return 1
	return 0
}

main() {
	local ref="HEAD"
	local keep=""
	local zips=""
	local arg
	while [ $# -gt 0 ]; do
		arg="$1"
		case "$arg" in
		--ref)
			[ $# -ge 2 ] || die "--ref needs a value"
			ref="$2"
			shift
			;;
		--zip)
			[ $# -ge 2 ] || die "--zip needs a file"
			[ -f "$2" ] || die "no such zip: $2"
			zips="$zips
$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"
			shift
			;;
		--keep-output)
			[ $# -ge 2 ] || die "--keep-output needs a folder"
			mkdir -p "$2"
			keep="$(cd "$2" && pwd)"
			shift
			;;
		-h | --help)
			usage
			return 0
			;;
		*) die "unknown argument: $arg" ;;
		esac
		shift
	done

	command -v docker >/dev/null 2>&1 || die "needs Docker"
	docker info >/dev/null 2>&1 || die "Docker is not running"
	local root
	root="$(git rev-parse --show-toplevel)" || die "run this inside a checkout of the plugin"

	trap cleanup EXIT
	TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/seoprostack-plugincheck.XXXXXX")"
	mkdir -p "$TMP_DIR/zips"
	chmod 755 "$TMP_DIR" "$TMP_DIR/zips"

	local zip_path
	if [ -z "$zips" ]; then
		"$root/scripts/build-release.sh" --ref "$ref" --out "$TMP_DIR/zips" --quiet >/dev/null || die "build failed"
	else
		while IFS= read -r zip_path; do
			[ -n "$zip_path" ] && cp "$zip_path" "$TMP_DIR/zips/"
		done <<EOF
$zips
EOF
	fi
	chmod 644 "$TMP_DIR"/zips/*.zip

	start_site
	local failed=0 zip_name
	for zip_path in "$TMP_DIR"/zips/*.zip; do
		zip_name="$(basename "$zip_path")"
		check_zip "$zip_name" "$keep" || failed=1
	done

	printf '\n'
	if [ "$failed" -eq 1 ]; then
		printf 'Plugin Check found errors.\n'
		return 1
	fi
	printf 'Plugin Check: no errors.\n'
	return 0
}

main "$@"
