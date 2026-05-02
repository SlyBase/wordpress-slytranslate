#!/usr/bin/env bash

set -euo pipefail

# shellcheck source=plugin-config.sh
source "$(dirname "${BASH_SOURCE[0]}")/plugin-config.sh"
zip_file="${1:?Usage: verify-plugin-zip.sh <zip-file>}"

# Quick integrity test
unzip -tq "$zip_file"

# Materialize normalized entries to a temp file (strip leading ./ and trailing /)
tmp_entries=$(mktemp)
trap 'rm -f "${tmp_entries:-}"' EXIT
unzip -Z1 "$zip_file" | sed -e 's#^\./##' -e 's#/$##' > "$tmp_entries"

# Ensure we have a plugin slug; fall back to the archive top-level folder if needed
if [[ -z "${plugin_slug:-}" ]]; then
	first_entry="${zip_entries[0]:-}"
	plugin_slug="${first_entry%%/*}"
	if [[ -z "$plugin_slug" ]]; then
		echo "::error::Could not determine plugin slug from environment or archive" >&2
		exit 1
	fi
	echo "Detected plugin_slug from archive: $plugin_slug"
fi

# Build blacklist patterns (with plugin_slug expanded). Use grep -E for regex checks.
blacklist_patterns=(
	"^${plugin_slug}/tests/"
	"^${plugin_slug}/composer\.lock$"
	"^${plugin_slug}/phpunit\.xml\.dist$"
	"^${plugin_slug}/\.gitignore$"
	"^${plugin_slug}/\.phpunit\.result\.cache$"
	"^${plugin_slug}(/.*)?/\.DS_Store$"
)

for pattern in "${blacklist_patterns[@]}"; do
	if grep -Eq "$pattern" "$tmp_entries"; then
		echo "::error::Archive contains excluded entry matching $pattern"
		exit 1
	fi
done

# Required files and prefixes
required_files=(
	"${plugin_slug}/${plugin_slug}.php"
	"${plugin_slug}/readme.txt"
	"${plugin_slug}/uninstall.php"
)

for required_file in "${required_files[@]}"; do
	if ! grep -Fxq "$required_file" "$tmp_entries"; then
		echo "::error::Archive is missing required file: $required_file"
		exit 1
	fi
done

required_prefixes=(
	"${plugin_slug}/languages/"
)

for required_prefix in "${required_prefixes[@]}"; do
	if ! grep -Fq "${required_prefix%/}" "$tmp_entries"; then
		echo "::error::Archive is missing required path prefix: $required_prefix"
		exit 1
	fi
done

echo "Archive verification passed: $zip_file"