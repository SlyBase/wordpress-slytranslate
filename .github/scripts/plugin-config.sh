#!/usr/bin/env bash
# Meant to be sourced, not executed directly.
# Detects the plugin slug automatically by looking for a subdirectory in the
# repository root that contains a same-named PHP file with a "Plugin Name:" header.
#
#   source "$(dirname "${BASH_SOURCE[0]}")/plugin-config.sh"

_pc_script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_pc_repo_root="$(cd "$_pc_script_dir/../.." && pwd)"

plugin_slug=""
for _pc_dir in "$_pc_repo_root"/*/; do
	_pc_base="$(basename "$_pc_dir")"
	for _pc_file in "${_pc_dir}"*.php; do
		if [[ -f "$_pc_file" ]] && grep -q "Plugin Name:" "$_pc_file"; then
			plugin_slug="$_pc_base"
			break 2
		fi
	done
done

if [[ -z "$plugin_slug" ]]; then
	echo "plugin-config.sh: Could not auto-detect plugin slug in '$_pc_repo_root'" >&2
	# If the script was sourced, return non-zero; if executed, exit non-zero.
	if [[ "${BASH_SOURCE[0]}" != "${0}" ]]; then
		return 1
	else
		exit 1
	fi
fi

unset _pc_script_dir _pc_repo_root _pc_dir _pc_base _pc_php
