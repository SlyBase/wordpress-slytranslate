#!/usr/bin/env bash

set -euo pipefail

# shellcheck source=plugin-config.sh
source "$(dirname "${BASH_SOURCE[0]}")/plugin-config.sh"
zip_file="${1:?Usage: deploy-plugin-to-wordpress-pod.sh <plugin-zip>}"
namespace="${DEPLOY_NAMESPACE:-websites}"
pod_selector="${DEPLOY_POD_SELECTOR:-app.kubernetes.io/instance=slybase-com,app.kubernetes.io/name=wordpress}"
preferred_container="${DEPLOY_CONTAINER:-wordpress}"
kubectl_bin="${KUBECTL_BIN:-}"
target_dir="/var/www/html/wp-content/plugins/${plugin_slug}"
staging_root="$(mktemp -d)"
tar_create_args=(-C "$staging_root/$plugin_slug" -cf - .)
trap 'rm -rf "$staging_root"' EXIT

resolve_kubectl_bin() {
	local candidate

	if [[ -n "$kubectl_bin" ]]; then
		candidate="$kubectl_bin"
		if [[ -x "$candidate" ]] && "$candidate" version --client >/dev/null 2>&1; then
			printf '%s' "$candidate"
			return 0
		fi
		echo "Configured kubectl binary is not usable: $candidate" >&2
	fi

	candidate="$(command -v kubectl || true)"
	if [[ -n "$candidate" ]] && "$candidate" version --client >/dev/null 2>&1; then
		printf '%s' "$candidate"
		return 0
	fi

	for candidate in /opt/homebrew/bin/kubectl /usr/local/bin/kubectl; do
		if [[ -x "$candidate" ]] && "$candidate" version --client >/dev/null 2>&1; then
			printf '%s' "$candidate"
			return 0
		fi
	done

	return 1
}

kubectl_exec() {
	"$kubectl_cmd" "$@"
}

# All matching pods, one name per line. Deployments with multiple replicas
# (e.g. a scaled StatefulSet) must receive the plugin on every pod — wp-admin
# requests are load-balanced, so a partially deployed plugin flaps per request.
resolve_pod_names() {
	local pod_names
	pod_names="$(kubectl_exec -n "$namespace" get pod -l "$pod_selector" --field-selector=status.phase=Running -o jsonpath='{range .items[*]}{.metadata.name}{"\n"}{end}')"

	if [[ -z "$pod_names" ]]; then
		pod_names="$(kubectl_exec -n "$namespace" get pod -l "$pod_selector" -o jsonpath='{range .items[*]}{.metadata.name}{"\n"}{end}')"
	fi

	printf '%s' "$pod_names"
}

resolve_container_name() {
	local pod_name="$1"
	local containers
	local container
	containers="$(kubectl_exec -n "$namespace" get pod "$pod_name" -o jsonpath='{range .spec.containers[*]}{.name}{"\n"}{end}')"

	if [[ -z "$containers" ]]; then
		echo "No containers found in pod '$pod_name'" >&2
		return 1
	fi

	while IFS= read -r container; do
		if [[ "$container" == "$preferred_container" ]]; then
			printf '%s' "$container"
			return 0
		fi
	done <<< "$containers"

	container="$(printf '%s\n' "$containers" | head -n 1)"
	echo "Preferred container '$preferred_container' not found in pod '$pod_name'. Using '$container'." >&2
	printf '%s' "$container"
}

deploy_to_pod() {
	local pod_name="$1"
	local container="$2"

	COPYFILE_DISABLE=1 COPY_EXTENDED_ATTRIBUTES_DISABLE=1 \
		tar "${tar_create_args[@]}" | \
		kubectl_exec -n "$namespace" exec -i "$pod_name" -c "$container" -- sh -lc "rm -rf '$target_dir' && mkdir -p '$target_dir' && tar -C '$target_dir' -xf -"
}

if [[ "$(uname -s)" == 'Darwin' ]]; then
	tar_create_args=(--no-mac-metadata --no-xattrs "${tar_create_args[@]}")
fi

if [[ ! -f "$zip_file" ]]; then
	echo "ZIP archive not found: $zip_file" >&2
	exit 1
fi

for required_command in tar unzip; do
	if ! command -v "$required_command" >/dev/null 2>&1; then
		echo "Required command not found: $required_command" >&2
		exit 1
	fi
done

kubectl_cmd="$(resolve_kubectl_bin)"
if [[ -z "$kubectl_cmd" ]]; then
	echo "Unable to find a usable kubectl binary. Set KUBECTL_BIN to a working executable path." >&2
	exit 1
fi

pod_names="$(resolve_pod_names)"

if [[ -z "$pod_names" ]]; then
	echo "No pod found for selector '$pod_selector' in namespace '$namespace'" >&2
	exit 1
fi

unzip -q "$zip_file" -d "$staging_root"

if [[ ! -d "$staging_root/$plugin_slug" ]]; then
	echo "Archive does not contain expected top-level directory: $plugin_slug" >&2
	exit 1
fi

if [[ "$(uname -s)" == 'Darwin' ]] && command -v xattr >/dev/null 2>&1; then
	xattr -cr "$staging_root/$plugin_slug"
fi

deployed_pods=()
while IFS= read -r pod_name; do
	[[ -z "$pod_name" ]] && continue
	container="$(resolve_container_name "$pod_name")"

	echo "Deploying $zip_file to pod $pod_name ($namespace/$container)"
	if ! deploy_to_pod "$pod_name" "$container"; then
		echo "Initial deploy attempt to $pod_name failed. Retrying once..." >&2
		container="$(resolve_container_name "$pod_name")"
		deploy_to_pod "$pod_name" "$container"
	fi
	deployed_pods+=("$pod_name")
done <<< "$pod_names"

if [[ ${#deployed_pods[@]} -eq 0 ]]; then
	echo "No running pod received the deployment for selector '$pod_selector'" >&2
	exit 1
fi

echo "Deployment completed: $target_dir on pod(s) ${deployed_pods[*]}"
