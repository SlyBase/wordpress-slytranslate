=== SlyTranslate - AI Translation Abilities ===
Contributors: timonf
Tags: ai, translation, abilities-api, polylang, wp-multilang
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.7.0
License: MIT
License URI: https://opensource.org/licenses/MIT

AI translation abilities for WordPress using native AI Connectors, the AI Client and Abilities API for automated text and content translation.

== Description ==

SlyTranslate brings practical AI translation to WordPress. It is built for teams that need translation directly in editing workflows and also want the same workflows available through REST and MCP automation.

**Why this plugin?**

Use SlyTranslate when you need one consistent translation workflow for:

* page/post translation in wp-admin
* TranslatePress visual-editor translation on the current page
* inline selected-text translation in Gutenberg
* Gutenberg block translation
* bulk translation from list-table actions
* SEO title/description translation in the same process

**Internal flow (short):**

* Uses native WordPress AI connectors through `wp_ai_client_prompt()`.
* Registers translation workflows as WordPress Abilities.
* Exposes abilities over REST (`/wp-abilities/v1/`) and MCP discovery.
* Supports long/structured content with chunking and output validation.
* Optional `direct_api_url` supports OpenAI-compatible endpoints for model-specific payload needs.
* In WP Multilang mode, translation state is detected from language-specific content so placeholder titles do not count as completed translations.
* List-table translation now includes an explicit overwrite option with a confirmation step.
* TranslatePress editor pages get a SlyTranslate sidebar panel that can translate the current singular page with model selection, overwrite, progress, and cancel controls.

**Abilities:**

`ai-translate/get-languages`:  List languages exposed by the active language plugin |
`ai-translate/get-translation-status`: Show translation status for a content item, including `source_language` and `single_entry_mode` |
`ai-translate/set-post-language`: Change the language assignment of an existing content item (only exposed when supported, e.g. Polylang) |
`ai-translate/get-untranslated`: Find content still missing a target translation |
`ai-translate/translate-text`: Translate arbitrary text |
`ai-translate/translate-blocks`: Translate serialized Gutenberg blocks |
`ai-translate/translate-content`: Create or update one translated post/page/CPT entry (call `get-translation-status` first; optional `source_language` + `overwrite`) |
`ai-translate/translate-content-bulk`: Bulk-translate multiple entries (supports optional `source_language` and `overwrite`) |
`ai-translate/get-progress`: Return live progress for a running translation |
`ai-translate/cancel-translation`: Cancel a running translation |
`ai-translate/get-available-models`: List models from configured connectors |
`ai-translate/save-additional-prompt`: Save per-user additional instructions |
`ai-translate/configure`: Read or update persistent plugin settings |

**Requirements:**

* WordPress 6.9+
* PHP 8.1+
* An AI connector configured in WordPress (Settings > Connectors)
* A supported language plugin (Polylang, WP Multilang, WPGlobus, or TranslatePress Multilingual) for content-translation workflows across posts/pages/CPTs
* WordPress MCP Adapter if you want MCP discovery

**Supported plugins:**

* Language plugin: Polylang, WP Multilang, WPGlobus, TranslatePress Multilingual
* SEO plugins: Genesis SEO, Yoast SEO, Rank Math, All in One SEO, The SEO Framework, SEOpress, Slim SEO

**Supported model profiles:**

Any LLM available through a WordPress AI connector works out of the box — no special configuration needed. The following model families additionally have dedicated built-in profiles that tune prompt style, chunking, and retry behavior for better results:

* TranslateGemma: dedicated runtime with `chat_template_kwargs` support through `direct_api_url`
* TowerInstruct / Salamandra: bilingual framing, conservative chunking, stricter retry behavior
* Nvidia Nemotron: system-prompt-aware with reasoning-disable and provider-parameter forwarding
* Qwen 3.x / GLM-4.6v / Gemma 4 / Phi-4: thinking-aware profiles
* EuroLLM / Llama 3.1-8B / SauerkrautLM: conservative chunking tuned for European languages
* Ministral-3 / Ministral-8B: optimized for the Ministral model family

== Installation ==

**Via WordPress Plugin Directory (recommended):**

1. Ensure WordPress 6.9+ and PHP 8.1+ are running.
2. In wp-admin, go to Plugins > Add New and search for "SlyTranslate".
3. Install and activate SlyTranslate.
4. Install and configure an AI connector in Settings > Connectors.
5. Optional for content translation: install and activate Polylang, WP Multilang, WPGlobus, or TranslatePress Multilingual.
6. Optional for local llama.cpp models: install AI Provider for llama.cpp.
7. Optional for other OpenAI-compatible local/self-hosted endpoints: install Ultimate AI Connector for Compatible Endpoints.
8. Optional for MCP discovery: install and activate WordPress MCP Adapter.

**Manual installation:**

1. Ensure WordPress 6.9+ and PHP 8.1+ are running.
2. Copy the `slytranslate` directory to `/wp-content/plugins/`.
3. Activate SlyTranslate in wp-admin.
4. Install and configure an AI connector in Settings > Connectors.
5. Optional for content translation: install and activate Polylang, WP Multilang, WPGlobus, or TranslatePress Multilingual.
6. Optional for local llama.cpp models: install AI Provider for llama.cpp.
7. Optional for other OpenAI-compatible local/self-hosted endpoints: install Ultimate AI Connector for Compatible Endpoints.
8. Optional for MCP discovery: install and activate WordPress MCP Adapter.

== Screenshots ==

1. Panel UI in page/post
2. Inline translation
3. Gutenberg block translation
4. Page/post translation and bulk action
5. Translation UI overview

== Frequently Asked Questions ==

= Does this work without a language plugin? =

Yes, for text and block translation (`translate-text`, `translate-blocks`, inline selected-text workflow). Content translation workflows require a supported language plugin (Polylang, WP Multilang, WPGlobus, or TranslatePress Multilingual).

= Where are API keys configured? =

In WordPress Settings > Connectors, not inside SlyTranslate.

= Can I use bulk translation from post/page lists? =

Yes. Use `translate-content-bulk` through abilities or the wp-admin list-table translation UI.

= Does this work inside the TranslatePress visual editor? =

Yes. On singular pages opened with `?trp-edit-translation=true`, SlyTranslate adds a sidebar panel in the TranslatePress editor so you can translate the current page with the same model, overwrite, progress, and cancel controls used elsewhere in the plugin.

= How does overwriting existing translations work? =

In the list-table dialog, **Overwrite existing translation** is off by default. If a translation already exists, you must enable overwrite and confirm before the translation starts.

= Can I change the language assignment of an existing post without running translation? =

Yes, when the active language plugin supports language mutation (currently Polylang). In that case `ai-translate/set-post-language` is exposed and can be called with `post_id` and `target_language`. By default language conflicts fail with `language_conflict`; use `force` to opt in, and pass `relink=true` when translation relations should be rewritten. In WP Multilang mode this ability is not exposed.

= How do I control prompts and style? =

Use `ai-translate/configure` for persistent defaults and `additional_prompt` on `translate-*` abilities for per-request instructions.

= Which model-specific profiles are supported? =

Any LLM from a WordPress AI connector works without configuration. Built-in dedicated profiles exist for: TranslateGemma, TowerInstruct, Salamandra, Nvidia Nemotron, Qwen 3.x, GLM-4.6v, Gemma 4, Phi-4, EuroLLM, Llama 3.1-8B, SauerkrautLM, Ministral-3, and Ministral-8B. Additional profiles can be registered via the `slytranslate_model_profiles` filter.
