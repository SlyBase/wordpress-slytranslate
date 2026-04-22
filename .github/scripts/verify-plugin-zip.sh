#!/usr/bin/env bash

set -euo pipefail

zip_file="${1:?Usage: verify-plugin-zip.sh <zip-file>}"
zip_entries="$(unzip -Z1 "$zip_file")"

unzip -tq "$zip_file"

blacklist_patterns=(
	'^slytranslate/tests/'
	'^slytranslate/composer\.lock$'
	'^slytranslate/phpunit\.xml\.dist$'
	'^slytranslate/\.gitignore$'
	'^slytranslate/\.phpunit\.result\.cache$'
	'^slytranslate(?:/.*)?/\.DS_Store$'
)

for pattern in "${blacklist_patterns[@]}"; do
	if printf '%s\n' "$zip_entries" | grep -Eq "$pattern"; then
		echo "::error::Archive contains excluded entry matching $pattern"
		exit 1
	fi
done

required_files=(
	'slytranslate/ai-translate.php'
	'slytranslate/composer.json'
	'slytranslate/readme.txt'
	'slytranslate/vendor/autoload.php'
)

for required_file in "${required_files[@]}"; do
	if ! printf '%s\n' "$zip_entries" | grep -Fxq "$required_file"; then
		echo "::error::Archive is missing required file: $required_file"
		exit 1
	fi
done

required_prefixes=(
	'slytranslate/assets/'
	'slytranslate/inc/'
	'slytranslate/languages/'
)

for required_prefix in "${required_prefixes[@]}"; do
	if ! printf '%s\n' "$zip_entries" | grep -Fq "$required_prefix"; then
		echo "::error::Archive is missing required path prefix: $required_prefix"
		exit 1
	fi
done

echo "Archive verification passed: $zip_file"