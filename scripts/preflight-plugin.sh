#!/usr/bin/env bash
# SEO Pro Stack's own release checks. scripts/preflight-release.sh (a core
# file from the starter plugin) sources this file and calls plugin_preflight
# with the unpacked GitHub build; section, ok, warn and err come from there.
#
# SPDX-License-Identifier: GPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Marcus Quinn
# Additional terms (GPL-3.0 section 7(b)): SEOPROSTACK-ATTRIBUTION.txt

# Presets and starter data must never set the same setting: Add starter data
# leaves stored settings alone and Apply preset overwrites them, so a shared
# setting would end up depending on which ran first. Also checks the JSON parses.
check_presets_starters() {
	local dir="$1"
	section "Presets and starter data"
	if ! command -v node >/dev/null 2>&1; then
		warn "node not found, presets and starter data not checked"
		return 0
	fi
	local out
	out="$(node -e '
		const fs = require("fs"), path = require("path");
		const dir = process.argv[1], problems = [], presetOptions = {};
		const load = (folder) => {
			const full = path.join(dir, folder);
			if (!fs.existsSync(full)) return [];
			return fs.readdirSync(full).filter((f) => f.endsWith(".json")).sort().map((f) => {
				try { return [folder + "/" + f, JSON.parse(fs.readFileSync(path.join(full, f), "utf8"))]; }
				catch (e) { problems.push("ERROR " + folder + "/" + f + " is not valid JSON: " + e.message); return null; }
			}).filter(Boolean);
		};
		const presets = load("presets"), starters = load("starters");
		for (const [file, data] of presets) {
			for (const name of Object.keys((data && data.options) || {})) (presetOptions[name] = presetOptions[name] || []).push(file);
			// Every setting the preset changes is named in the dialog, by the paths the dialog uses.
			const paths = new Set();
			const walk = (p, v) => (v && typeof v === "object" && !Array.isArray(v) && Object.keys(v).length) ? Object.entries(v).forEach(([k, w]) => walk(p + "." + k, w)) : paths.add(p);
			for (const [name, value] of Object.entries((data && data.options) || {})) walk(name, value);
			const named = (data && data.settings) || {};
			for (const p of paths) if (!named[p] || !named[p].label) problems.push("ERROR " + file + " has no settings label for " + p);
			for (const p of Object.keys(named)) if (!paths.has(p)) problems.push("ERROR " + file + " names a setting it does not set: " + p);
		}
		let settings = 0;
		for (const [file, data] of starters) {
			for (const items of Object.values((data && data.items) || {})) {
				for (const item of Array.isArray(items) ? items : []) {
					if (!item || typeof item.option !== "string") continue;
					settings++;
					for (const preset of presetOptions[item.option] || []) problems.push("ERROR " + file + " and " + preset + " both set " + item.option);
				}
			}
		}
		problems.push("COUNT " + presets.length + " " + starters.length + " " + settings);
		process.stdout.write(problems.join("\n") + "\n");
	' "$dir" 2>&1 || true)"
	local line errors=0 counts=""
	while IFS= read -r line; do
		case "$line" in
		"ERROR "*)
			err "${line#ERROR }"
			errors=$((errors + 1))
			;;
		"COUNT "*) counts="${line#COUNT }" ;;
		"") ;;
		*)
			err "presets and starter data check failed: $line"
			errors=$((errors + 1))
			;;
		esac
	done <<<"$out"
	if [[ -z "$counts" && "$errors" -eq 0 ]]; then
		err "presets and starter data check did not run"
	elif [[ "$errors" -eq 0 ]]; then
		local presets starters settings
		read -r presets starters settings <<<"$counts"
		ok "$presets presets and $starters starter files parse; none of the $settings starter settings is also in a preset"
	fi
	return 0
}

plugin_preflight() {
	local dir="$1"
	if [[ -d "$dir/presets" || -d "$dir/starters" ]]; then
		check_presets_starters "$dir"
	fi
	return 0
}
