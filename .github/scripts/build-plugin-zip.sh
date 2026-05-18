#!/usr/bin/env bash

set -euo pipefail

output_zip="${1:?Usage: build-plugin-zip.sh <output-zip>}"
caller_cwd="$(pwd)"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
# shellcheck source=plugin-config.sh
source "$script_dir/plugin-config.sh"
plugin_dir="$repo_root/$plugin_slug"
staging_root="$(mktemp -d)"
trap 'rm -rf "$staging_root"' EXIT
staging_plugin_dir="$staging_root/$plugin_slug"

if [[ "$output_zip" = /* ]]; then
	output_zip_path="$output_zip"
else
	output_zip_path="$caller_cwd/$output_zip"
fi

mkdir -p "$(dirname "$output_zip_path")"
rm -f "$output_zip_path"

rsync -a \
	--exclude='.DS_Store' \
	--exclude='.gitignore' \
	--exclude='tests/' \
	--exclude='vendor/' \
	--exclude='phpunit.xml.dist' \
	--exclude='.phpunit.result.cache' \
	"$plugin_dir/" "$staging_plugin_dir/"

if ! command -v composer >/dev/null 2>&1; then
	echo "composer is required to build a distributable plugin ZIP" >&2
	exit 1
fi

# Rebuild a clean production autoloader instead of relying on a local dev vendor tree.
composer install \
	--working-dir="$staging_plugin_dir" \
	--no-dev \
	--no-interaction \
	--prefer-dist \
	--optimize-autoloader

rm -f "$staging_plugin_dir/composer.lock"

if [[ ! -f "$staging_plugin_dir/vendor/autoload.php" ]]; then
	echo "Failed to generate vendor/autoload.php for plugin ZIP" >&2
	exit 1
fi

(
	cd "$staging_root"
	zip -rq "$output_zip_path" "$plugin_slug"
)

echo "Created plugin archive: $output_zip_path"
unzip -l "$output_zip_path"
