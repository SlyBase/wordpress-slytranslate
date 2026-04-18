# SlyTranslate - AI Translation Abilities

AI-powered translation abilities for WordPress using the WordPress AI Client and the Abilities API.

SlyTranslate exposes translation workflows as reusable WordPress abilities, so they can be used from the block editor, the REST API, MCP clients, and automation tooling.

## Highlights

- Translate arbitrary text with `ai-translate/translate-text`
- Translate posts, pages, and custom post types with `ai-translate/translate-content`
- Bulk-translate multiple entries with `ai-translate/translate-content-bulk`
- Inspect translation status and untranslated content
- Translate SEO title and description fields for major SEO plugins
- Use a block-editor sidebar for content translation workflows, including a model selector
- Real-time translation progress bar with phase and chunk tracking in the editor sidebar
- Translate selected text inline in the editor, even without a translation plugin
- Connect directly to any OpenAI-compatible endpoint for models that need `chat_template_kwargs` (e.g. TranslateGemma)
- Expose abilities over REST and MCP-friendly discovery

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
| `ai-translate/translate-content` | Create or update a translated content item; accepts optional `model_slug` per request |
| `ai-translate/translate-content-bulk` | Bulk-translate multiple content items; accepts optional `model_slug` per request |
| `ai-translate/configure` | Read or update plugin settings (prompt, model, direct API URL, context window, SEO meta keys, direct API diagnostics) |

## Editor Experience

SlyTranslate adds two editor-facing workflows:

- An AI Translate document panel for content translation when a translation plugin is active, including a live progress bar and a Translate now / Cancel translation toggle during active jobs
- A Translate with SlyTranslate action for highlighted text inside supported rich-text fields

Both workflows include a model dropdown that lists all models registered with the WordPress AI Client. The selection persists in `localStorage` and defaults to the site-wide connector model.

During full-content translations, the sidebar polls a lightweight REST endpoint and shows the current phase plus chunk progress for long content, so editors can see whether the plugin is translating the title, content, excerpt, metadata, or saving the translated post.

The selected-text workflow is independent from Polylang and uses `ai-translate/translate-text`, which makes it useful even on sites that only want inline text translation.

## AI Provider Setup

SlyTranslate uses the WordPress AI Client via `wp_ai_client_prompt()`. That means API and endpoint setup is delegated to connector plugins.

### Recommended for Local LLMs

For local models or self-hosted inference servers, use **Ultimate AI Connector for Compatible Endpoints**:

https://wordpress.org/plugins/ultimate-ai-connector-compatible-endpoints/

That connector is a good fit if you want to use:

- Ollama
- LM Studio
- LocalAI
- vLLM
- text-generation-webui
- Other OpenAI-compatible `/v1/chat/completions` endpoints

Configure the endpoint once in Settings > Connectors and SlyTranslate can use the discovered models through the normal WordPress AI Client flow.

### Direct API (for Advanced Models)

Some models require request-level parameters that connector plugins cannot pass through — for example `chat_template_kwargs` for TranslateGemma running on llama.cpp. For these cases, set `direct_api_url` via `ai-translate/configure` to point directly at the OpenAI-compatible endpoint.

When a direct URL is configured:

- Translation requests go directly to that endpoint, bypassing the WordPress AI Client
- The plugin auto-detects whether the server supports `chat_template_kwargs` and enables them automatically when available
- Standard instruct/chat models fall back to the WordPress AI Client path if the direct call fails
- TranslateGemma is treated fail-safe: if `chat_template_kwargs` support cannot be confirmed or the direct call fails, SlyTranslate returns an error instead of silently falling back
- Translation output is validated before it is accepted: empty results, assistant-style essays, implausibly long short-text responses, and major structure loss are rejected; standard models get one stricter retry before the request fails

The direct API path is optional. All standard connectors continue to work without it. TranslateGemma is the exception: for reliable translation it needs both `direct_api_url` and working `chat_template_kwargs` support.

## Translation Plugin Support

Post and content-entry translation workflows currently rely on a translation plugin adapter.

- Supported today: Polylang
- Planned/possible later: WPML, TranslatePress, additional adapters

If no translation plugin is active, text translation still works, including the inline selected-text action in the block editor.

## SEO Plugin Support

SlyTranslate auto-detects common SEO plugins and translates the most important title and description fields while clearing derived analysis data so the SEO plugin can rebuild it.

Supported SEO integrations include:

- Yoast SEO
- Rank Math
- All in One SEO
- The SEO Framework
- SEOpress
- Slim SEO

## Architecture Notes

- Uses the WordPress AI Client instead of storing provider-specific API keys in this plugin
- Optional direct API path (`direct_api_url`) bypasses the AI Client for models that require full control over the request body (e.g. `chat_template_kwargs`); standard models still fall back automatically, while TranslateGemma fails closed when direct API or kwargs support are unavailable
- Validates translated output before saving: rejects empty or chatty responses, implausibly long title-like output, and structure drift such as missing HTML, Gutenberg comments, URLs, or code fences
- Translates long content in chunks; derives safe chunk sizes from the active model, learns tighter limits from provider error messages, and retries automatically with a smaller chunk on context-window errors
- Block content is parsed before translation: code blocks are skipped and consecutive translatable blocks are batched together for efficiency
- Exposes abilities over REST at `/wp-abilities/v1/`
- Marks abilities as public for MCP adapter discovery via `/wp-json/mcp/mcp-adapter-default-server`
- Keeps Polylang auto-translate hooks for backward compatibility

## Requirements

- WordPress 7.0+
- PHP 8.1+
- An AI connector/plugin configured in Settings > Connectors
- Polylang for content translation workflows across posts, pages, and custom post types
- WordPress MCP Adapter if you want MCP client discovery

## Installation

1. Ensure WordPress 7.0+ and PHP 8.1+ are running.
2. Install and activate an AI connector plugin and configure it in Settings > Connectors.
3. For local LLMs, install "Ultimate AI Connector for Compatible Endpoints" and point it at Ollama, LM Studio, LocalAI, vLLM, or another compatible endpoint.
4. Optional for translated content workflows across posts, pages, and custom post types: install and activate Polylang.
5. Optional for MCP discovery: install and activate the WordPress MCP Adapter.
6. Copy the `slytranslate` directory into `/wp-content/plugins/`.
7. Activate SlyTranslate - AI Translation Abilities.

## REST and MCP

All abilities are exposed through the WordPress Abilities API and can be invoked over REST.

- REST base: `/wp-abilities/v1/`
- Run an ability: `/wp-abilities/v1/run/{ability_name}`
- MCP adapter discovery: `/wp-json/mcp/mcp-adapter-default-server`

## Repository Layout

- `slytranslate/`: WordPress plugin root
- `slytranslate/ai-translate.php`: main plugin bootstrap and ability registration
- `slytranslate/assets/editor-plugin.js`: Gutenberg editor integration
- `slytranslate/inc/`: translation and SEO adapter code
- `seo-plugin-test-matrix.md`: SEO integration notes and testing matrix

## FAQ

### Does this work without Polylang?

Yes, for text translation.

`ai-translate/translate-text` works without a translation plugin, and the inline selected-text editor action is available for that workflow. Content translation workflows such as `translate-content` and `translate-content-bulk` still require a translation plugin, currently Polylang.

### Can I translate pages or custom post types?

Yes.

`ai-translate/translate-content`, `ai-translate/translate-content-bulk`, `ai-translate/get-translation-status`, and `ai-translate/get-untranslated` work with any Polylang-enabled post type, including pages and custom post types.

### Where do I configure prompts?

Use the `ai-translate/configure` ability to read or update prompt and plugin settings.

### How are API keys handled?

They are handled by the connector configured in Settings > Connectors, not by SlyTranslate itself.

### Can I use TranslateGemma or other specialized translation models?

Yes. Set `direct_api_url` via `ai-translate/configure` to the URL of your llama.cpp server running TranslateGemma. The plugin automatically probes for `chat_template_kwargs` support, re-probes when TranslateGemma is selected and kwargs are missing, and uses the model's native language-routing for every request once confirmed.

TranslateGemma now fails closed: if no direct API URL is configured, if kwargs support cannot be confirmed, or if the direct API request fails, SlyTranslate returns an error instead of silently falling back to the generic WordPress AI Client path. The `ai-translate/configure` response exposes `direct_api_kwargs_last_probed_at`, `translategemma_runtime_ready`, and `translategemma_runtime_status` for diagnostics.

A custom Jinja chat template is still required on the llama.cpp side — see `translategemma-llama-cpp-guide.md` in this repository.

### What happens if a model returns a chat answer instead of a translation?

SlyTranslate validates translation output before saving it. The plugin rejects empty responses, explanatory assistant replies, implausibly long short-text outputs, and structure loss in block content such as missing Gutenberg comments, HTML tags, URLs, or code fences. For standard instruct/chat models, it automatically retries once with stricter output instructions. For TranslateGemma, the request fails immediately once the output is deemed invalid.

## Development

This repository contains the plugin source in the `slytranslate` directory. The WordPress.org-style plugin readme remains in `slytranslate/readme.txt`, while this file is intended for GitHub visitors.

## License

MIT