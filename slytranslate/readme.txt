=== SlyTranslate - AI Translation Abilities ===
Contributors: timonf
Tags: ai, translation, abilities-api, polylang, multilingual
Requires at least: 7.0
Tested up to: 7.0.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

AI translation abilities for WordPress using the AI Client and Abilities API for text and content translation.

== Description ==

SlyTranslate - AI Translation Abilities provides AI-powered translation as WordPress Abilities, making them available to AI agents, automation tools, and the WordPress REST API.

**Abilities provided:**

* **ai-translate/get-languages** – Lists all available languages from the active translation plugin.
* **ai-translate/get-translation-status** – Shows which translations exist for a given content item.
* **ai-translate/get-untranslated** – Lists posts, pages, or custom post types that still need a translation for a target language.
* **ai-translate/translate-text** – Translates arbitrary text between languages.
* **ai-translate/translate-content** – Translates a post, page, or custom post type entry and creates or updates the translation.
* **ai-translate/translate-content-bulk** – Bulk-translates multiple entries at once (either by explicit IDs or by post type, max 50).
* **ai-translate/configure** – Read or update plugin settings (prompt template, meta key configuration, SEO defaults, auto-translate toggle, optional context window token override).

**Architecture:**

* Uses the WordPress AI Client (`wp_ai_client_prompt()`) for LLM access — no API keys to configure in this plugin. Set up your preferred AI provider via Settings > Connectors.
* For local LLMs via Ollama, LM Studio, LocalAI, vLLM, or other OpenAI-compatible endpoints, we recommend **Ultimate AI Connector for Compatible Endpoints**: https://wordpress.org/plugins/ultimate-ai-connector-compatible-endpoints/
* Long content is translated in chunks. The plugin derives a safe chunk size from the active model, can learn tighter limits from provider errors, and supports a manual context window token override.
* Translation plugin support via an adapter interface. Currently supports **Polylang**, including posts, pages, custom post types, and associated taxonomies. Additional adapters (WPML, TranslatePress, etc.) can be added.
* Popular SEO plugins are detected automatically and their key SEO meta fields can be translated or cleared without manual configuration.
* The block editor gets an **AI Translate** document settings panel for launching content translations directly from the editor when a translation plugin is active, plus a selected-text action for translating highlighted text inline with SlyTranslate via `ai-translate/translate-text` even without a translation plugin.
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

Use the `ai-translate/configure` ability via the REST API or any tool that supports WordPress Abilities.

= How do I tune chunking for large posts? =

Use the `context_window_tokens` field on the `ai-translate/configure` ability to override the detected model context window when your provider uses a smaller or larger limit than the plugin inferred.

= Can I use local LLMs? =

Yes. For local LLMs or self-hosted inference servers, we recommend **Ultimate AI Connector for Compatible Endpoints**: https://wordpress.org/plugins/ultimate-ai-connector-compatible-endpoints/ Configure your compatible endpoint once in Settings > Connectors, and SlyTranslate can use models exposed by tools such as Ollama, LM Studio, LocalAI, or vLLM through the WordPress AI Client.

= Can I translate pages or custom post types? =

Yes. The `translate-content`, `translate-content-bulk`, `get-translation-status`, and `get-untranslated` abilities work with any Polylang-enabled post type, including pages and custom post types.

= How do SEO plugin fields get translated? =

The plugin auto-detects supported SEO plugins such as Yoast SEO, Rank Math, All in One SEO, The SEO Framework, SEOpress, and Slim SEO. Their most important title/description meta fields are translated automatically, while analysis scores are cleared so the SEO plugin can recalculate them.

= Can I trigger translation from the block editor? =

Yes. The block editor includes an **AI Translate** panel in the document settings sidebar when a translation plugin is active. It loads translation status and can create or update translations directly from the editor. When you highlight text inside supported rich-text fields, you also get a **Translate with SlyTranslate** action for inline translation of just that selection via `ai-translate/translate-text`, even without a translation plugin.

= Does this work without Polylang? =

Yes, for text translation. The `translate-text` ability and the block editor's selected-text translation action work independently. The translation abilities that create or manage translated content (`get-languages`, `get-translation-status`, `get-untranslated`, `translate-content`, `translate-content-bulk`) still require a translation plugin, currently Polylang.

== Changelog ==

= 1.0.0 =
* Complete rewrite for WordPress 7.0 Abilities API, based on AI Translate For Polylang by James Low
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