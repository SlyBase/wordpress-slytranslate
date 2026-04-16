=== SlyTranslate - AI Translation Abilities ===
Contributors: timonf
Tags: ai, translation, abilities-api, polylang, multilingual
Requires at least: 7.0
Tested up to: 7.0.0
Requires PHP: 8.1
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

AI translation abilities for WordPress using WordPress 7 native AI Connectors as a core feature, plus the AI Client and Abilities API for text and content translation.

== Description ==

SlyTranslate - AI Translation Abilities provides AI-powered translation as WordPress Abilities, making them available to AI agents, automation tools, and the WordPress REST API. Polylang translation is based on AI Translate For Polylang by jamesdlow (https://de.wordpress.org/plugins/ai-translate-for-polylang/).


**Abilities provided:**

* **ai-translate/get-languages** – Lists all available languages from the active translation plugin.
* **ai-translate/get-translation-status** – Shows which translations exist for a given content item.
* **ai-translate/get-untranslated** – Lists posts, pages, or custom post types that still need a translation for a target language.
* **ai-translate/translate-text** – Translates arbitrary text between languages.
* **ai-translate/translate-content** – Translates a post, page, or custom post type entry and creates or updates the translation.
* **ai-translate/translate-content-bulk** – Bulk-translates multiple entries at once (either by explicit IDs or by post type, max 50).
* **ai-translate/configure** – Read or update plugin settings (prompt template, meta key configuration, SEO defaults, auto-translate toggle, model slug, optional context window token override, direct API URL for OpenAI-compatible endpoints).

**Architecture:**

* Uses the WordPress AI Client (`wp_ai_client_prompt()`) for LLM access — no API keys to configure in this plugin. Set up your preferred AI provider via Settings > Connectors.
* For local LLMs via Ollama, LM Studio, LocalAI, vLLM, or other OpenAI-compatible endpoints, we recommend **Ultimate AI Connector for Compatible Endpoints**: https://wordpress.org/plugins/ultimate-ai-connector-compatible-endpoints/
* Optional **Direct API** path (`direct_api_url`): connect directly to any OpenAI-compatible endpoint without the WordPress AI Client, with automatic fallback. Enables `chat_template_kwargs` support for specialized models such as TranslateGemma (auto-detected on configuration save).
* Long content is translated in chunks. The plugin derives a safe chunk size from the active model, learns tighter limits from provider error messages, retries automatically with a smaller chunk on context-window errors, and supports a manual context window token override.
* Block content is parsed before translation: code and preformatted blocks are skipped, and consecutive translatable blocks are batched together for efficiency.
* Translation plugin support via an adapter interface. Currently supports **Polylang**, including posts, pages, custom post types, and associated taxonomies. Additional adapters (WPML, TranslatePress, etc.) can be added.
* Popular SEO plugins are detected automatically and their key SEO meta fields can be translated or cleared without manual configuration.
* The block editor gets an **AI Translate** document settings panel for launching content translations directly from the editor when a translation plugin is active, including a model selector dropdown that lists all registered AI Client models and persists the choice per user. A selected-text action for translating highlighted text inline via `ai-translate/translate-text` is available even without a translation plugin.
* All abilities are exposed via the REST API (`/wp-abilities/v1/`) and marked public for MCP Adapter discovery via `/wp-json/mcp/mcp-adapter-default-server`.
* Polylang auto-translate hooks are preserved for backward compatibility — creating a new translation in Polylang still triggers automatic translation.
* Plugin labels and descriptions are translation-ready and include a bundled German (`de_DE`) translation.

**Requirements:**

* WordPress 7.0+
* PHP 8.1+
* WordPress MCP Adapter for MCP client access
* An AI provider plugin configured via Settings > Connectors (e.g., AI Provider for OpenAI, AI Provider for Anthropic, AI Provider for Google, or **Ultimate AI Connector for Compatible Endpoints** for local/OpenAI-compatible endpoints)
* A translation plugin for content translation workflows across posts, pages, and custom post types: Polylang (recommended), with more adapters planned

== Installation ==

1. Ensure WordPress 7.0+ and PHP 8.1+ are running.
2. Optional for content translation workflows across posts, pages, and custom post types: install and activate a translation plugin (e.g., Polylang).
3. Install and activate an AI provider plugin and configure it via Settings > Connectors.
4. For local LLMs, we recommend **Ultimate AI Connector for Compatible Endpoints** so SlyTranslate can use endpoints such as Ollama, LM Studio, LocalAI, vLLM, or other compatible `/v1/chat/completions` servers.
5. Optional: Install and activate WordPress MCP Adapter if you want MCP clients to discover these abilities.
6. Upload the `slytranslate` folder to `/wp-content/plugins/` or install via the plugin directory.
7. Activate SlyTranslate - AI Translation Abilities.
8. Text translation abilities are now available via the Abilities API, REST API, and MCP Adapter. Content translation workflows become available once a translation plugin is active.

== Frequently Asked Questions ==

= Where do I configure API keys? =

API keys are managed centrally in WordPress via Settings > Connectors. This plugin does not handle API keys directly.

= How do I change the translation prompt? =

Use the `ai-translate/configure` ability via the REST API or any tool that supports WordPress Abilities. The `prompt_template` field sets the base instructions including language pair placeholders (`{FROM_CODE}`, `{TO_CODE}`). The `prompt_addon` field adds a site-wide addition that is always appended after the template — useful for global style requirements such as formal language or domain-specific vocabulary.

= How do I add per-request translation instructions? =

The `ai-translate/translate-text` and `ai-translate/translate-content` abilities accept an optional `additional_prompt` field. This text is appended after the prompt template and the site-wide add-on, so it can override or extend the defaults just for that request. In the block editor, the **AI Translate** panel and the selected-text toolbar each provide an **Additional instructions** textarea. The value you enter there is saved per user and pre-filled the next time you open the editor, so you do not need to re-enter recurring style preferences.

= What is the difference between prompt_template, prompt_addon, and additional_prompt? =

* **prompt_template** — the base translation instruction including `{FROM_CODE}` and `{TO_CODE}` placeholders. Managed by `ai-translate/configure`, requires `manage_options` capability.
* **prompt_addon** — a site-wide addition always appended after the template. Also managed by `ai-translate/configure` and admin-only. Use this for global requirements that apply to every translation on the site.
* **additional_prompt** (per-request) — an optional field on `translate-text` and `translate-content`. Appended last, after the template and the site-wide add-on. Available to any user who can run translation abilities. In the block editor this is the **Additional instructions** textarea; the last used value is stored per user.

= How do I tune chunking for large posts? =

Use the `context_window_tokens` field on the `ai-translate/configure` ability to override the detected model context window when your provider uses a smaller or larger limit than the plugin inferred.

= Can I use local LLMs? =

Yes. For local LLMs or self-hosted inference servers, we recommend **Ultimate AI Connector for Compatible Endpoints**: https://wordpress.org/plugins/ultimate-ai-connector-compatible-endpoints/ Configure your compatible endpoint once in Settings > Connectors, and SlyTranslate can use models exposed by tools such as Ollama, LM Studio, LocalAI, or vLLM through the WordPress AI Client.

For models that require request-level parameters such as `chat_template_kwargs` (e.g. TranslateGemma on llama.cpp), use the `direct_api_url` setting on `ai-translate/configure` to bypass the AI Client and send requests directly to the endpoint.

= Can I use TranslateGemma or other specialized translation models? =

Yes. Set `direct_api_url` via `ai-translate/configure` to the URL of your llama.cpp (or other OpenAI-compatible) server. The plugin will automatically probe for `chat_template_kwargs` support and, when detected, include `source_lang_code` and `target_lang_code` in every translation request so the model can use its native language-routing. A custom Jinja chat template is required on the llama.cpp side to make this work — a ready-to-use template and setup guide are included in the plugin repository.

= Can I translate pages or custom post types? =

Yes. The `translate-content`, `translate-content-bulk`, `get-translation-status`, and `get-untranslated` abilities work with any Polylang-enabled post type, including pages and custom post types.

= How do SEO plugin fields get translated? =

The plugin auto-detects supported SEO plugins such as Yoast SEO, Rank Math, All in One SEO, The SEO Framework, SEOpress, and Slim SEO. Their most important title/description meta fields are translated automatically, while analysis scores are cleared so the SEO plugin can recalculate them.

= Can I trigger translation from the block editor? =

Yes. The block editor includes an **AI Translate** panel in the document settings sidebar when a translation plugin is active. It loads translation status and can create or update translations directly from the editor. When you highlight text inside supported rich-text fields, you also get a **Translate with SlyTranslate** action for inline translation of just that selection via `ai-translate/translate-text`, even without a translation plugin.

= Does this work without Polylang? =

Yes, for text translation. The `translate-text` ability and the block editor's selected-text translation action work independently. The translation abilities that create or manage translated content (`get-languages`, `get-translation-status`, `get-untranslated`, `translate-content`, `translate-content-bulk`) still require a translation plugin, currently Polylang.

== Changelog ==

= 1.2.0 =
* **Direct API support**: new `direct_api_url` setting on `ai-translate/configure` — connect directly to any OpenAI-compatible endpoint (llama.cpp, vLLM, Ollama, LM Studio, LocalAI) without the WordPress AI Client in the request path; automatic fallback to the standard AI Client when the direct call fails
* **TranslateGemma `chat_template_kwargs` auto-detection**: when a Direct API URL is configured, the plugin probes the server for `chat_template_kwargs` support (sends "cat" en → de, checks for "Katze"); when detected, every translation request includes `source_lang_code` / `target_lang_code` for models like TranslateGemma that use Jinja-based language routing
* **Per-request `model_slug`**: the `translate-text`, `translate-content`, and `translate-content-bulk` abilities now accept an optional `model_slug` field to override the site-wide default on a per-call basis
* **Block editor model selector**: new model dropdown in the AI Translate sidebar panel and the selected-text translation modal; lists all models registered with the WordPress AI Client, persists the selection in `localStorage`, and shows the effective default as "Auto" label
* Added TranslateGemma to the known model context-window table (8 192 tokens)
* Added newer models to context-window detection: o4-mini, o3, gpt-4.5, gpt-4.1, Sonar, Grok
* Slim SEO: translate only the `title` and `description` fields inside the serialised `slim_seo` meta value instead of overwriting the full array
* `ai-translate/configure` output now includes effective runtime values: `effective_meta_keys_translate`, `effective_meta_keys_clear`, `effective_context_window_tokens`, `effective_chunk_chars`, `learned_context_window_tokens`, `direct_api_kwargs_supported`

= 1.1.1 =
* Block editor: added **Cancel translation** button that appears while a translation is in progress, allowing the request to be aborted without leaving the editor
* Added `model_slug` setting to `ai-translate/configure` ability — explicitly select which model the AI connector should use for translations (e.g. `gemma3:27b`); leave empty to use the connector default
* Removed dependency on the third-party AI Services plugin for model detection; model selection is now handled entirely via the `model_slug` setting and the WordPress AI Client

= 1.1.0 =
* Added site-wide `prompt_addon` field to `ai-translate/configure` ability — a global addition always appended after the prompt template
* Added optional `additional_prompt` field to `ai-translate/translate-text` and `ai-translate/translate-content` abilities for per-request style instructions
* Block editor: new **Additional instructions** textarea in the AI Translate panel (Sidebar) and the selected-text modal (Toolbar), pre-filled with the last used value per user
* Last used additional prompt is stored in user meta and passed as bootstrap data to the editor, so it persists across posts and page reloads
* New REST endpoint `ai-translate/user-preference` for saving the user-specific additional prompt

= 1.0.1 =
* Fixed block editor sidebar translations ignoring the active connector model selection by passing the detected runtime model as AI Client preference

= 1.0.0 =
* Complete rewrite for WordPress 7.0 Abilities API, based on AI Translate For Polylang by jamesdlow (https://de.wordpress.org/plugins/ai-translate-for-polylang/)
* LLM integration via WordPress AI Client (no more direct API key management)
* 7 abilities registered: get-languages, get-translation-status, get-untranslated, translate-text, translate-content, translate-content-bulk, configure
* Adapter architecture: TranslationPluginAdapter interface with PolylangAdapter implementation
* Bulk translation support for posts, pages, and custom post types (max 50 items)
* Automatic SEO plugin integration for common title and description meta fields
* Block editor AI Translate panel for in-editor translation workflows
* Bundled German translation for ability labels and editor UI
* All abilities exposed via REST API (show_in_rest)
* Settings menu removed — configuration via ai-translate/configure ability
* Requires WordPress 7.0+, PHP 8.1+