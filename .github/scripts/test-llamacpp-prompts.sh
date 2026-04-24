#!/usr/bin/env bash

set -euo pipefail

DEFAULT_PROMPT_TEMPLATE='Translate the content from {FROM_CODE} to {TO_CODE} preserving html, formatting, embedded media, and source symbols. Do not rewrite Unicode symbols or math notation as LaTeX or ASCII. Only return the new content.'

usage() {
	cat <<'EOF'
Usage:
  test-llamacpp-prompts.sh --model <model-slug> [options]

Runs one or both prompt styles against an OpenAI-compatible llama.cpp endpoint:
  1) Plugin-default style (system prompt + user text)
  2) Bilingual-frame style (user-only prompt)

Options:
  --model <slug>               Model slug (required)
  --url <endpoint>             Chat completions URL
                               (default: http://127.0.0.1:8080/v1/chat/completions)
  --source <lang-code>         Source language code (default: en)
  --target <lang-code>         Target language code (default: de)
  --text <text>                Source text to translate
  --text-file <path>           Read source text from file
  --global-addon <text>        Optional global addon appended to default prompt
  --additional-prompt <text>   Optional additional style instruction
  --validation-attempt <n>     Bilingual retry level (adds CRITICAL line when n > 0)
                               (default: 0)
  --max-tokens <n>             max_tokens sent to endpoint (default: 2048)
  --temperature <n>            temperature sent to endpoint (default: 0)
  --timeout <sec>              HTTP timeout in seconds (default: 120)
  --only <default|bilingual|both>
                               Which prompt mode to run (default: both)
  --hide-prompts               Do not print built prompts before request
  -h, --help                   Show this help

Examples:
  .github/scripts/test-llamacpp-prompts.sh \
    --model Ministral-8B-Instruct-2410-Q4_K_M \
    --source en --target de \
    --text "Translate the recently created post into English and French."

  .github/scripts/test-llamacpp-prompts.sh \
    --model llama-3.3-70b \
    --text-file ./sample.txt \
    --only bilingual
EOF
}

has_non_whitespace() {
	[[ -n "${1//[[:space:]]/}" ]]
}

resolve_language_label() {
	local code="${1:-}"
	local fallback="${2:-Language}"
	local normalized
	normalized="$(printf '%s' "$code" | tr '[:upper:]' '[:lower:]')"

	case "$normalized" in
		en) echo "English" ;;
		de) echo "German" ;;
		fr) echo "French" ;;
		es) echo "Spanish" ;;
		it) echo "Italian" ;;
		nl) echo "Dutch" ;;
		pl) echo "Polish" ;;
		"") echo "$fallback" ;;
		*) echo "${normalized^^}" ;;
	esac
}

json_escape() {
	local escaped="$1"
	escaped=${escaped//\\/\\\\}
	escaped=${escaped//\"/\\\"}
	escaped=${escaped//$'\n'/\\n}
	escaped=${escaped//$'\r'/\\r}
	escaped=${escaped//$'\t'/\\t}
	printf '%s' "$escaped"
}

build_default_prompt() {
	local from_code="$1"
	local to_code="$2"
	local global_addon="$3"
	local additional_prompt="$4"

	local prompt="$DEFAULT_PROMPT_TEMPLATE"
	prompt="${prompt//\{FROM_CODE\}/$from_code}"
	prompt="${prompt//\{TO_CODE\}/$to_code}"

	if has_non_whitespace "$global_addon"; then
		prompt+=$'\n\n'
		prompt+="$global_addon"
	fi

	if has_non_whitespace "$additional_prompt"; then
		prompt+=$'\n\n'
		prompt+="Additional style instructions (do NOT translate these lines, apply them to the user-provided content): $additional_prompt"
	fi

	printf '%s' "$prompt"
}

build_bilingual_frame_prompt() {
	local text="$1"
	local prompt="$2"
	local source_code="$3"
	local target_code="$4"
	local validation_attempt="$5"

	local source_label target_label result
	source_label="$(resolve_language_label "$source_code" "Source")"
	target_label="$(resolve_language_label "$target_code" "Target")"

	result="Translate the following text from ${source_label} into ${target_label}."

	if has_non_whitespace "$prompt"; then
		result+=$'\n\n'
		result+="Follow these translation rules: $prompt"
	fi

	if (( validation_attempt > 0 )); then
		result+=$'\n\n'
		result+="CRITICAL: Return only ${target_label}. Do not copy sentences in ${source_label}."
	fi

	result+=$'\n\n'
	result+="${source_label}:"
	result+=$'\n\n'
	result+="$text"
	result+=$'\n\n'
	result+="${target_label}:"

	printf '%s' "$result"
}

call_completion() {
	local case_name="$1"
	local endpoint="$2"
	local model_slug="$3"
	local system_prompt="$4"
	local user_prompt="$5"
	local use_system_prompt="$6"
	local request_timeout="$7"
	local request_temperature="$8"
	local request_max_tokens="$9"
	local print_prompts="${10}"

	local escaped_model escaped_system escaped_user messages_json payload
	escaped_model="$(json_escape "$model_slug")"
	escaped_user="$(json_escape "$user_prompt")"

	if [[ "$use_system_prompt" == "1" ]]; then
		escaped_system="$(json_escape "$system_prompt")"
		messages_json="$(printf '[{"role":"system","content":"%s"},{"role":"user","content":"%s"}]' "$escaped_system" "$escaped_user")"
	else
		messages_json="$(printf '[{"role":"user","content":"%s"}]' "$escaped_user")"
	fi

	payload="$(printf '{"model":"%s","temperature":%s,"max_tokens":%s,"messages":%s}' "$escaped_model" "$request_temperature" "$request_max_tokens" "$messages_json")"

	printf '\n===== %s =====\n' "$case_name"

	if [[ "$print_prompts" == "1" ]]; then
		if [[ "$use_system_prompt" == "1" ]]; then
			printf '\n--- system prompt ---\n%s\n' "$system_prompt"
		fi
		printf '\n--- user content ---\n%s\n' "$user_prompt"
	fi

	local response_file http_code response_body
	response_file="$(mktemp)"
	http_code="$(curl -sS -m "$request_timeout" \
		-H 'Content-Type: application/json' \
		-o "$response_file" \
		-w '%{http_code}' \
		--data "$payload" \
		"$endpoint" || true)"
	response_body="$(cat "$response_file")"
	rm -f "$response_file"

	if [[ ! "$http_code" =~ ^[0-9]{3}$ ]]; then
		echo "Request failed before HTTP status was returned." >&2
		return 1
	fi

	if (( http_code < 200 || http_code > 299 )); then
		printf '\nHTTP %s\n' "$http_code" >&2
		if command -v jq >/dev/null 2>&1; then
			printf '%s' "$response_body" | jq . >&2 || printf '%s\n' "$response_body" >&2
		else
			printf '%s\n' "$response_body" >&2
		fi
		return 1
	fi

	printf '\n--- response ---\n'
	if command -v jq >/dev/null 2>&1; then
		local content finish_reason
		content="$(printf '%s' "$response_body" | jq -r '.choices[0].message.content // empty')"
		finish_reason="$(printf '%s' "$response_body" | jq -r '.choices[0].finish_reason // empty')"

		if has_non_whitespace "$content"; then
			printf '%s\n' "$content"
		else
			printf '%s\n' "$response_body"
		fi

		if has_non_whitespace "$finish_reason"; then
			printf '(finish_reason: %s)\n' "$finish_reason"
		fi
	else
		printf '%s\n' "$response_body"
		echo "Hint: Install jq for cleaner response extraction (brew install jq)."
	fi
}

url="http://127.0.0.1:8080/v1/chat/completions"
source_lang="en"
target_lang="de"
input_text=""
text_file=""
global_addon=""
additional_prompt=""
validation_attempt="0"
max_tokens="2048"
temperature="0"
timeout_seconds="120"
mode="both"
show_prompts="1"
model_slug=""

while [[ $# -gt 0 ]]; do
	case "$1" in
		--model)
			model_slug="${2:-}"
			shift 2
			;;
		--url)
			url="${2:-}"
			shift 2
			;;
		--source)
			source_lang="${2:-}"
			shift 2
			;;
		--target)
			target_lang="${2:-}"
			shift 2
			;;
		--text)
			input_text="${2:-}"
			shift 2
			;;
		--text-file)
			text_file="${2:-}"
			shift 2
			;;
		--global-addon)
			global_addon="${2:-}"
			shift 2
			;;
		--additional-prompt)
			additional_prompt="${2:-}"
			shift 2
			;;
		--validation-attempt)
			validation_attempt="${2:-}"
			shift 2
			;;
		--max-tokens)
			max_tokens="${2:-}"
			shift 2
			;;
		--temperature)
			temperature="${2:-}"
			shift 2
			;;
		--timeout)
			timeout_seconds="${2:-}"
			shift 2
			;;
		--only)
			mode="${2:-}"
			shift 2
			;;
		--hide-prompts)
			show_prompts="0"
			shift
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			echo "Unknown argument: $1" >&2
			usage >&2
			exit 1
			;;
	esac
done

if ! has_non_whitespace "$model_slug"; then
	echo "--model is required." >&2
	usage >&2
	exit 1
fi

if has_non_whitespace "$text_file"; then
	if [[ ! -f "$text_file" ]]; then
		echo "text file not found: $text_file" >&2
		exit 1
	fi
	input_text="$(cat "$text_file")"
elif ! has_non_whitespace "$input_text" && [[ ! -t 0 ]]; then
	input_text="$(cat)"
fi

if ! has_non_whitespace "$input_text"; then
	echo "No input text provided. Use --text, --text-file, or pipe stdin." >&2
	usage >&2
	exit 1
fi

if [[ ! "$mode" =~ ^(default|bilingual|both)$ ]]; then
	echo "--only must be one of: default, bilingual, both" >&2
	exit 1
fi

if [[ ! "$validation_attempt" =~ ^[0-9]+$ ]]; then
	echo "--validation-attempt must be a non-negative integer." >&2
	exit 1
fi

default_prompt="$(build_default_prompt "$source_lang" "$target_lang" "$global_addon" "$additional_prompt")"
bilingual_prompt="$(build_bilingual_frame_prompt "$input_text" "$default_prompt" "$source_lang" "$target_lang" "$validation_attempt")"

if [[ "$mode" == "default" || "$mode" == "both" ]]; then
	call_completion \
		"CASE 1: default (plugin-like system+user)" \
		"$url" \
		"$model_slug" \
		"$default_prompt" \
		"$input_text" \
		"1" \
		"$timeout_seconds" \
		"$temperature" \
		"$max_tokens" \
		"$show_prompts"
fi

if [[ "$mode" == "bilingual" || "$mode" == "both" ]]; then
	call_completion \
		"CASE 2: bilingual-frame (plugin style user-only)" \
		"$url" \
		"$model_slug" \
		"" \
		"$bilingual_prompt" \
		"0" \
		"$timeout_seconds" \
		"$temperature" \
		"$max_tokens" \
		"$show_prompts"
fi
