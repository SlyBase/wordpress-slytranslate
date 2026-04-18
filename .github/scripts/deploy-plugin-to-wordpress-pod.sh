#!/usr/bin/env bash

set -euo pipefail

zip_file="${1:?Usage: deploy-plugin-to-wordpress-pod.sh <plugin-zip>}"
namespace='websites'
pod_selector='app.kubernetes.io/instance=slybase-com,app.kubernetes.io/name=wordpress'
container='wordpress'
plugin_slug='slytranslate'
target_dir="/var/www/html/wp-content/plugins/${plugin_slug}"
staging_root="$(mktemp -d)"
tar_create_args=(-C "$staging_root/$plugin_slug" -cf - .)
trap 'rm -rf "$staging_root"' EXIT

if [[ "$(uname -s)" == 'Darwin' ]]; then
	tar_create_args=(--no-mac-metadata --no-xattrs "${tar_create_args[@]}")
fi

if [[ ! -f "$zip_file" ]]; then
	echo "ZIP archive not found: $zip_file" >&2
	exit 1
fi

for required_command in kubectl tar unzip; do
	if ! command -v "$required_command" >/dev/null 2>&1; then
		echo "Required command not found: $required_command" >&2
		exit 1
	fi
done

pod_name="$(kubectl -n "$namespace" get pod -l "$pod_selector" -o jsonpath='{.items[0].metadata.name}')"

if [[ -z "$pod_name" ]]; then
	echo "No pod found for selector '$pod_selector' in namespace '$namespace'" >&2
	exit 1
fi

echo "Deploying $zip_file to pod $pod_name ($namespace/$container)"
unzip -q "$zip_file" -d "$staging_root"

if [[ ! -d "$staging_root/$plugin_slug" ]]; then
	echo "Archive does not contain expected top-level directory: $plugin_slug" >&2
	exit 1
fi

if [[ "$(uname -s)" == 'Darwin' ]] && command -v xattr >/dev/null 2>&1; then
	xattr -cr "$staging_root/$plugin_slug"
fi

COPYFILE_DISABLE=1 COPY_EXTENDED_ATTRIBUTES_DISABLE=1 \
	tar "${tar_create_args[@]}" | \
	kubectl -n "$namespace" exec -i "$pod_name" -c "$container" -- sh -lc "rm -rf '$target_dir' && mkdir -p '$target_dir' && tar -C '$target_dir' -xf -"

echo "Deployment completed: $target_dir on pod $pod_name"