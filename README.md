# SlyTranslate - AI Translation Abilities

AI-powered translation abilities for WordPress using the WordPress AI Client and the Abilities API.

SlyTranslate exposes translation workflows as reusable WordPress abilities, so they can be used from the block editor, the REST API, MCP clients, and automation tooling.

## Highlights

- Translate arbitrary text with `ai-translate/translate-text`
- Translate serialized Gutenberg blocks with `ai-translate/translate-blocks`
- Translate posts, pages, and custom post types with `ai-translate/translate-content`
- Bulk-translate multiple entries with `ai-translate/translate-content-bulk`
- Inspect translation status, untranslated content, and live translation progress
- Translate SEO title and description fields for major SEO plugins, including legacy Genesis meta on mixed or migrated sites
- Use a block-editor sidebar for content translation workflows, including a model selector
- Real-time translation progress bar with phase and chunk tracking in the editor sidebar
- Translate selected text inline in the editor, even without a translation plugin
- Cancel running translations, refresh the available model list, and persist per-user additional instructions across editor flows
- Connect directly to any OpenAI-compatible endpoint for models that need `chat_template_kwargs` (e.g. TranslateGemma)
- Resolve translation behavior via a central model-profile registry (request mode, prompt style, extra payload keys, chunk strategy, retry policy)
- TowerInstruct gets a dedicated profile with user-only bilingual framing plus conservative chunking and stricter passthrough retries for German targets
- Expose abilities over REST and MCP-friendly discovery
- Large models (gemma-4, Llama 3.1+) are recognised with their full context window, so a ~48 000-char post translates in one AI call instead of twelve
- Eligible SEO meta fields (title, description) are translated in a single batched AI call, reducing meta-phase round-trips by up to N−1 calls

## What This Plugin Does

SlyTranslate is built for WordPress sites that want AI-assisted translation without hard-coding a single provider into the plugin itself.

It uses the WordPress AI Client for model access, so connector setup happens centrally in WordPress under Settings > Connectors. Once a connector is configured, SlyTranslate can use it for plain text translation as well as full content translation workflows.

## Abilities

| Ability | Purpose |
| --- | --- |
| `ai-translate/get-languages` | List languages exposed by the active translation plugin |
| `ai-translate/get-translation-status` | Show which translations exist for a content item |
| `ai-translate/get-untranslated` | Find posts, pages, or CPT entries still missing a target translation |
| `ai-translate/translate-text` | Translate arbitrary text between languages; accepts optional `model_slug` per request |
| `ai-translate/translate-blocks` | Translate serialized Gutenberg block content while preserving block markup; accepts optional `additional_prompt` and `model_slug` per request |
| `ai-translate/translate-content` | Create or update a translated content item; accepts optional `model_slug` per request |
| `ai-translate/translate-content-bulk` | Bulk-translate multiple content items; accepts either explicit `post_ids` or `post_type` plus `limit`, and also supports `additional_prompt` plus optional `model_slug` |
| `ai-translate/get-progress` | Return the current progress state for a running content translation job |
| `ai-translate/cancel-translation` | Signal a running translation to stop and clear its progress state |
| `ai-translate/get-available-models` | List models exposed by the configured AI connectors; can bypass the cached model list |
| `ai-translate/save-additional-prompt` | Persist the current user's Additional instructions value for reuse in editor and list-table flows |
| `ai-translate/configure` | Read or update plugin settings; call with an empty object to inspect current defaults, and use it only for persistent site-wide settings rather than one-off translation overrides |

## Editor Experience

SlyTranslate adds two editor-facing workflows:

- An AI Translate document panel for content translation when a translation plugin is active, including a live progress bar and a Translate now / Cancel translation toggle during active jobs
- Inline selected-text and block translation dialogs that reuse the sidebar's active model selection and the same side-by-side source/target picker layout as the post/page translation dialog, including a vertically flush swap control

The sidebar includes a model dropdown that lists all models registered with the WordPress AI Client. The selection persists across the editor and is reused by the inline and block translation dialogs without showing a second model picker there.

Post/page list-table translations use an AJAX progress dialog plus the same persistent background-task bar. The dialog loads the same live model list as the editor sidebar and pre-fills Additional instructions from the saved per-user preference. If the running dialog is dismissed or the user leaves the current wp-admin screen mid-translation, the job is handed off automatically to that background bar so progress and the eventually created draft stay visible.

The same global bridge is now available even before the user has any previously recorded background job, so the first explicit Continue in background handoff works immediately instead of depending on a prior translation run. The long-running translation request is also sent with browser keepalive semantics, which makes handoff during wp-admin navigation materially more reliable.

During full-content translations, the sidebar polls a lightweight REST endpoint and shows the current phase plus chunk progress for long content, so editors can see whether the plugin is translating the title, content, excerpt, metadata, or saving the translated post.

The selected-text workflow is independent from Polylang and uses `ai-translate/translate-text`, which makes it useful even on sites that only want inline text translation.

## AI Provider Setup

SlyTranslate uses the WordPress AI Client via `wp_ai_client_prompt()`. That means API and endpoint setup is delegated to connector plugins.

### Recommended for Local LLMs

For local llama.cpp-based models, use **AI Provider for llama.cpp**.

For other local or self-hosted OpenAI-compatible LLM endpoints, use **Ultimate AI Connector for Compatible Endpoints**.

AI Provider for llama.cpp is a good fit if you want to use:

- llama.cpp

Configure it once in Settings > Connectors and SlyTranslate can use the discovered llama.cpp models through the normal WordPress AI Client flow.

Ultimate AI Connector for Compatible Endpoints is a good fit for endpoints such as Ollama, LM Studio, LocalAI, vLLM, text-generation-webui, and other compatible `/v1/chat/completions` servers.

For other self-hosted OpenAI-compatible endpoints, you can also use SlyTranslate's optional `direct_api_url` setting.

### Direct API (for Advanced Models)

Some models require request-level parameters that connector plugins cannot pass through — for example `chat_template_kwargs` for TranslateGemma running on llama.cpp. For these cases, set `direct_api_url` via `ai-translate/configure` to point directly at the OpenAI-compatible endpoint.

When a direct URL is configured:

- Translation requests go directly to that endpoint, bypassing the WordPress AI Client
- The plugin auto-detects whether the server supports `chat_template_kwargs` and enables them automatically when available
- Standard instruct/chat models fall back to the WordPress AI Client path if the direct call fails
- TranslateGemma is treated fail-safe: if `chat_template_kwargs` support cannot be confirmed or the direct call fails, SlyTranslate returns an error instead of silently falling back
- `ai-translate/configure` exposes `last_transport_diagnostics`, so admins can inspect the last connector/direct-API transport, requested/effective model slug, fallback status, and the captured error code/message without reading `debug.log`
- Translation output is validated before it is accepted: empty results, assistant-style essays, implausibly long short-text responses, symbol-notation drift such as Unicode arrows rewritten as LaTeX, and major structure loss are rejected; standard models get one stricter retry before the request fails

The direct API path is optional. All standard connectors continue to work without it. TranslateGemma is the exception: for reliable translation it needs both `direct_api_url` and working `chat_template_kwargs` support.

## Translation Plugin Support

Post and content-entry translation workflows currently rely on a translation plugin adapter.

- Supported today: Polylang
- Planned/possible later: WPML, TranslatePress, additional adapters

If no translation plugin is active, text translation still works, including the inline selected-text action in the block editor.

## SEO Plugin Support

SlyTranslate auto-detects common SEO plugins and translates the most important title and description fields while clearing derived analysis data so the SEO plugin can rebuild it.

At runtime, the plugin merges the active SEO profile with SEO meta keys actually present on the source post. That keeps legacy or mixed setups working, for example when a site now runs The SEO Framework but older content still stores Genesis SEO titles and descriptions.

Supported SEO integrations include:

- Genesis SEO
- Yoast SEO
- Rank Math
- All in One SEO
- The SEO Framework
- SEOpress
- Slim SEO

## Architecture Notes

- Uses the WordPress AI Client instead of storing provider-specific API keys in this plugin
- Optional direct API path (`direct_api_url`) bypasses the AI Client for models that require full control over the request body (e.g. `chat_template_kwargs`); standard models still fall back automatically, while TranslateGemma fails closed when direct API or kwargs support are unavailable
- Model-specific behavior is resolved through a central profile registry (`slytranslate_model_profiles`) so new model families can define request mode, prompt style, extra request body keys, chunk strategy, and retry policy without patching multiple core flows
- Validates translated output before saving: rejects empty or chatty responses, implausibly long title-like output, symbol-notation drift such as Unicode arrows rewritten as LaTeX, and structure drift such as missing HTML, Gutenberg comments, URLs, or code fences
- Editor REST endpoints require a structured `input` payload, and translation-status responses only include target-post details when the current user can access that translation
- Translates long content in chunks; derives safe chunk sizes from the active model, learns tighter limits from provider error messages, and retries automatically with a smaller chunk on context-window errors
- Block content is parsed before translation: code blocks are skipped and consecutive translatable blocks are batched together for efficiency
- Exposes abilities over REST at `/wp-abilities/v1/`
- Marks abilities as public for MCP adapter discovery via `/wp-json/mcp/mcp-adapter-default-server`

## Requirements

- WordPress 7.0+
- PHP 8.1+
- An AI connector/plugin configured in Settings > Connectors
- Polylang for content translation workflows across posts, pages, and custom post types
- WordPress MCP Adapter if you want MCP client discovery

## Installation

1. Ensure WordPress 7.0+ and PHP 8.1+ are running.
2. Install and activate an AI connector plugin and configure it in Settings > Connectors.
3. For local llama.cpp-based LLMs, install "AI Provider for llama.cpp" and connect it in Settings > Connectors. For other OpenAI-compatible local/self-hosted endpoints, install "Ultimate AI Connector for Compatible Endpoints".
4. Optional for translated content workflows across posts, pages, and custom post types: install and activate Polylang.
5. Optional for MCP discovery: install and activate the WordPress MCP Adapter.
6. Copy the `slytranslate` directory into `/wp-content/plugins/`. The plugin currently still needs to be installed manually until the WordPress.org plugin directory listing is approved (pending).
7. Activate SlyTranslate - AI Translation Abilities.

## REST and MCP

All abilities are exposed through the WordPress Abilities API and can be invoked over REST.

- REST base: `/wp-abilities/v1/`
- Run an ability: `/wp-abilities/v1/run/{ability_name}`
- MCP adapter discovery: `/wp-json/mcp/mcp-adapter-default-server`

For MCP clients, treat each ability schema as the business payload for that ability. Some clients wrap the call in transport-specific objects such as `parameters` or `input`, but the SlyTranslate-specific fields are the ones listed in the ability schema itself.

Recommended selection order for LLM clients:

1. Call `ai-translate/get-languages` when the target language code is unknown.
2. Call `ai-translate/get-available-models` before sending `model_slug` if the available model identifiers are unknown or the connector changed.
3. Call `ai-translate/get-translation-status` before `ai-translate/translate-content` when overwrite behaviour depends on an existing target post.
4. Call `ai-translate/get-untranslated` before `ai-translate/translate-content-bulk` when the source post IDs are not known yet.
5. Call `ai-translate/configure` with `{}` to read persistent defaults; use `model_slug` or `additional_prompt` on `translate-*` abilities for one-off request overrides.

Canonical ability payload examples:

Use the empty object below to read the current `ai-translate/configure` state.

```json
{}
```

Use the following with `ai-translate/get-available-models` after connector changes.

```json
{
	"refresh": true
}
```

Use the following with `ai-translate/translate-content` for one specific post.

```json
{
	"post_id": 42,
	"target_language": "en",
	"model_slug": "gemma3:27b"
}
```

Use the following with `ai-translate/translate-content-bulk` when the exact source posts are already known.

```json
{
	"post_ids": [42, 55],
	"target_language": "en",
	"additional_prompt": "Keep the marketing tone concise."
}
```

Use the following with `ai-translate/translate-content-bulk` when the plugin should discover the source posts by type. If `post_ids` and `post_type` are both sent, `post_ids` take precedence.

```json
{
	"post_type": "post",
	"limit": 10,
	"target_language": "en"
}
```

## Repository Layout

- `slytranslate/`: WordPress plugin root
- `slytranslate/ai-translate.php`: main plugin bootstrap and ability registration
- `slytranslate/assets/editor-plugin.js`: Gutenberg editor integration
- `slytranslate/inc/`: translation and SEO adapter code
- `seo-plugin-test-matrix.md`: SEO integration notes and testing matrix

## FAQ

### Does this work without Polylang?

Yes, for text translation.

`ai-translate/translate-text` and `ai-translate/translate-blocks` work without a translation plugin, and the inline selected-text editor action is available for that workflow. Content translation workflows such as `translate-content`, `translate-content-bulk`, `get-languages`, `get-translation-status`, and `get-untranslated` still require a translation plugin, currently Polylang.

### Can I translate pages or custom post types?

Yes.

`ai-translate/translate-content`, `ai-translate/translate-content-bulk`, `ai-translate/get-translation-status`, and `ai-translate/get-untranslated` work with any Polylang-enabled post type, including pages and custom post types.

### What happens if I close the post list translation dialog or leave the page mid-translation?

The running translation is handed off automatically to the same global background-task bar that the explicit `Continue in background` action uses. That keeps progress visible across wp-admin screens and avoids the confusing case where the translated draft appears a few seconds later without any visible running task.

The same dialog also reuses your saved Additional instructions and the current model selection from the editor sidebar, so the list-table flow starts with the same translation defaults.

### Where do I configure prompts?

Use the `ai-translate/configure` ability to read or update prompt and plugin settings.

Call it with an empty object to read the current site-wide defaults without changing anything.

### How are API keys handled?

They are handled by the connector configured in Settings > Connectors, not by SlyTranslate itself.

### Can I use TranslateGemma or other specialized translation models?

Yes. Set `direct_api_url` via `ai-translate/configure` to the URL of your llama.cpp server running TranslateGemma. The plugin automatically probes for `chat_template_kwargs` support, re-probes when TranslateGemma is selected and kwargs are missing, and uses the model's native language-routing for every request once confirmed.

TranslateGemma now fails closed: if no direct API URL is configured, if kwargs support cannot be confirmed, or if the direct API request fails, SlyTranslate returns an error instead of silently falling back to the generic WordPress AI Client path. The `ai-translate/configure` response exposes `direct_api_kwargs_last_probed_at`, `translategemma_runtime_ready`, and `translategemma_runtime_status` for diagnostics.

For both connector-based local models and direct API failures, `ai-translate/configure` now also includes `last_transport_diagnostics` with the last runtime transport, requested/effective model slug, fallback status, and the last captured error code/message.

A custom Jinja chat template is still required on the llama.cpp side — see `translategemma-llama-cpp-guide.md` in this repository.

### How does TowerInstruct support work?

TowerInstruct is handled through a dedicated model profile. Requests use a user-only bilingual frame (`English:` source and `German:` target) instead of a separate system role, and chunking is intentionally conservative for long inputs.

When the target is German and the model obviously passes through English text, runtime validation returns `invalid_translation_language_passthrough`; the Tower retry profile then re-runs translation with stricter instructions and smaller retry chunks.

### How do SEO plugin fields get translated?

SlyTranslate combines user-configured meta keys with known SEO-plugin profiles. For runtime translation, it does not rely only on the currently detected SEO plugin: it also inspects the source post's real meta keys and merges any matching supported profiles. That means legacy Genesis fields such as `_genesis_title` and `_genesis_description` are still translated on sites that have since switched to another SEO plugin.

Known text fields are translated, while rebuild-only analysis keys are cleared only for the plugins that explicitly need that behavior. Unknown Genesis flags, robots settings, and URL-like fields are left untouched unless you add them yourself via `ai-translate/configure`.

### What happens if a model returns a chat answer instead of a translation?

SlyTranslate validates translation output before saving it. The plugin rejects empty responses, explanatory assistant replies, implausibly long short-text outputs, symbol-notation drift such as Unicode arrows rewritten as LaTeX, and structure loss in block content such as missing Gutenberg comments, HTML tags, URLs, or code fences. For standard instruct/chat models, it automatically retries once with stricter output instructions. For TranslateGemma, the request fails immediately once the output is deemed invalid.

## Development

This repository contains the plugin source in the `slytranslate` directory. The WordPress.org-style plugin readme remains in `slytranslate/readme.txt`, while this file is intended for GitHub visitors.

### VS Code Workflow

The workspace includes VS Code tasks for the local plugin packaging and deploy loop:

- `SlyTranslate: Build Plugin ZIP` creates `slytranslate.zip` in the repository root.
- `SlyTranslate: Verify Plugin ZIP` runs the existing archive guardrails against that ZIP.
- `SlyTranslate: Deploy Plugin ZIP to WordPress Pod` copies the ZIP contents into `/var/www/html/wp-content/plugins/slytranslate` in the `wordpress` container of the first pod matching `app.kubernetes.io/instance=slybase-com,app.kubernetes.io/name=wordpress` in namespace `websites`.
- `SlyTranslate: Build and Deploy Plugin ZIP` runs the full build, verify, and deploy sequence.

If you use the `spencerwmiles.vscode-task-buttons` extension, the workspace settings also add a `SlyTranslate` status-bar button with the same task entry points.

If you update a `.po` file under `slytranslate/languages/`, rebuild the matching `.mo` file with `msgfmt` before committing. CI recompiles each tracked PO file and byte-compares the generated MO output against the committed MO artifact.

## License

MIT