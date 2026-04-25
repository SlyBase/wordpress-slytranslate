#!/usr/bin/env bash

set -euo pipefail

output_zip="${1:?Usage: build-plugin-zip.sh <output-zip>}"
caller_cwd="$(pwd)"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
plugin_dir="$repo_root/slytranslate"
staging_root="$(mktemp -d)"
trap 'rm -rf "$staging_root"' EXIT

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "Error: required command '$1' not found. Install gettext before building the plugin ZIP." >&2
		exit 1
	fi
}

build_language_files() {
	local languages_dir="$plugin_dir/languages"
	local po_files=()
	local po_file
	local locale_name
	local mo_file
	local temp_mo_file

	shopt -s nullglob
	for po_file in "$languages_dir"/*.po; do
		po_files+=("$po_file")
	done
	shopt -u nullglob

	if (( ${#po_files[@]} == 0 )); then
		echo "No translation source files found in $languages_dir."
		return 0
	fi

	for po_file in "${po_files[@]}"; do
		locale_name="$(basename "${po_file%.po}")"
		mo_file="$languages_dir/${locale_name}.mo"
		temp_mo_file="$(mktemp "${TMPDIR:-/tmp}/slytranslate.XXXXXX.mo")"

		if ! msgfmt --check-format -o "$temp_mo_file" "$po_file"; then
			rm -f "$temp_mo_file"
			exit 1
		fi

		if [[ -f "$mo_file" ]] && cmp -s "$temp_mo_file" "$mo_file"; then
			rm -f "$temp_mo_file"
			echo "Language file already current: $mo_file"
		else
			mv "$temp_mo_file" "$mo_file"
			echo "Recompiled language file: $mo_file"
		fi
	done
}

require_command msgfmt

build_language_files

if [[ "$output_zip" = /* ]]; then
	output_zip_path="$output_zip"
else
	output_zip_path="$caller_cwd/$output_zip"
fi

mkdir -p "$(dirname "$output_zip_path")"
rm -f "$output_zip_path"

rsync -a \
	--exclude='.DS_Store' \
	--exclude='phpunit.xml.dist' \
	--exclude='tests/' \
	--exclude='vendor/' \
	--exclude='.gitignore' \
	--exclude='.phpunit.result.cache' \
	"$plugin_dir/" "$staging_root/slytranslate/"

composer install \
	--no-dev \
	--optimize-autoloader \
	--no-interaction \
	--prefer-dist \
	--working-dir="$staging_root/slytranslate"

rm -f "$staging_root/slytranslate/composer.lock"

(
	cd "$staging_root"
	zip -rq "$output_zip_path" slytranslate
)

echo "Created plugin archive: $output_zip_path"
unzip -l "$output_zip_path"
