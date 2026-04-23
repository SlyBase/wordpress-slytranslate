=== SlyTranslate - AI Translation Abilities ===
Contributors: timonf
Tags: ai, translation, abilities-api, polylang, multilingual
Requires at least: 7.0
Tested up to: 7.0.0
Requires PHP: 8.1
Stable tag: 1.6.0
License: MIT
License URI: https://opensource.org/licenses/MIT

AI translation abilities for WordPress using native AI Connectors, the AI Client and Abilities API for automated text and content translation.

== Description ==

SlyTranslate - AI Translation Abilities provides AI-powered translation as WordPress Abilities, making them available to AI agents, automation tools, and the WordPress REST API. Polylang translation is based on AI Translate For Polylang by jamesdlow (https://de.wordpress.org/plugins/ai-translate-for-polylang/).


**Abilities provided:**

* **ai-translate/get-languages** – Lists all available languages from the active translation plugin.
* **ai-translate/get-translation-status** – Shows which translations exist for a given content item.
* **ai-translate/get-untranslated** – Lists posts, pages, or custom post types that still need a translation for a target language.
* **ai-translate/translate-text** – Translates arbitrary text between languages.
* **ai-translate/translate-blocks** – Translates serialized Gutenberg block content while preserving block structure.
* **ai-translate/translate-content** – Translates a post, page, or custom post type entry and creates or updates the translation.
* **ai-translate/translate-content-bulk** – Bulk-translates multiple entries at once (either by explicit IDs or by post type, max 50) and also accepts per-request additional instructions.
* **ai-translate/get-progress** – Returns the current progress for a running content translation job.
* **ai-translate/cancel-translation** – Cancels a running translation job and clears its progress indicator.
* **ai-translate/get-available-models** – Lists translation models exposed by the configured AI connectors, with optional cache refresh.
* **ai-translate/save-additional-prompt** – Saves the current user's additional translation instructions for reuse in the editor and list-table flows.
* **ai-translate/configure** – Read or update plugin settings (prompt template, prompt add-on, meta key configuration, SEO defaults, auto-translate toggle, model slug, optional context window token override, direct API URL for OpenAI-compatible endpoints, optional force-direct-API mode, and direct API plus last-transport diagnostics).

**Architecture:**

* Uses the WordPress AI Client (`wp_ai_client_prompt()`) for LLM access — no API keys to configure in this plugin. Set up your preferred AI provider via Settings > Connectors.
* For local llama.cpp-based LLM setups, we recommend **AI Provider for llama.cpp**.
* For other local or self-hosted OpenAI-compatible LLM endpoints (for example Ollama, LM Studio, LocalAI, or vLLM), we recommend **Ultimate AI Connector for Compatible Endpoints**: https://wordpress.org/plugins/ultimate-ai-connector-compatible-endpoints/
* Optional **Direct API** path (`direct_api_url`): connect directly to any OpenAI-compatible endpoint without the WordPress AI Client. Standard instruct/chat models still fall back automatically when the direct call fails. TranslateGemma is handled fail-safe instead: if no direct API URL is configured, if `chat_template_kwargs` support cannot be confirmed, or if the direct call fails, SlyTranslate returns an error instead of silently falling back. The `ai-translate/configure` response includes probe diagnostics such as the last kwargs probe timestamp, the TranslateGemma runtime status, and the last transport diagnostic snapshot with requested/effective model slug plus the last error code/message.
* Long content is translated in chunks. The plugin derives a safe chunk size from the active model, learns tighter limits from provider error messages, retries automatically with a smaller chunk on context-window errors, and supports a manual context window token override.
* Translated outputs are validated before they are accepted. SlyTranslate rejects empty/chatty assistant-style answers, implausibly long title-like responses, symbol-notation drift such as Unicode arrows rewritten as LaTeX, and structure drift such as missing HTML tags, Gutenberg block comments, URLs, or code fences. For standard instruct/chat models, the plugin retries once with stricter output instructions before failing.
* Editor REST requests require a structured `input` payload, and translation-status responses only expose target-post details such as title and edit link when the current user can access that translation.
* Block content is parsed before translation: code and preformatted blocks are skipped, and consecutive translatable blocks are batched together for efficiency.
* Translation plugin support via an adapter interface. Currently supports **Polylang**, including posts, pages, custom post types, and associated taxonomies. Additional adapters (WPML, TranslatePress, etc.) can be added.
* Popular SEO plugins are detected automatically and their key SEO meta fields can be translated or cleared without manual configuration. At runtime, SlyTranslate also inspects the source post's real SEO meta keys and merges any matching supported profiles, so legacy or mixed setups (for example Genesis meta on a site that now uses The SEO Framework) continue to translate correctly.
* The block editor gets an **AI Translate** document settings panel for launching content translations directly from the editor when a translation plugin is active, including a model selector dropdown that lists all registered AI Client models and persists the choice per user. During active content translations, the panel shows a live progress bar with phase and chunk tracking, and the main action button toggles from **Translate now** to **Cancel translation**. The inline selected-text action and the block-translation dialog reuse that same model selection, show the source/target language pickers side by side with a swap button, and keep the picker columns visually flush at the top and bottom like the post/page translation flow.
* Post/page list-table translations use an AJAX progress dialog plus the same persistent background-task bar. The dialog loads the same live model list as the editor sidebar and pre-fills **Additional instructions** from the saved per-user preference, so the list-table flow and editor panel stay in sync. If the running dialog is dismissed or the user leaves the admin page mid-translation, the job is handed off automatically to the background bar so progress and the eventually created draft remain visible instead of appearing unexpectedly later.
* All abilities are exposed via the REST API (`/wp-abilities/v1/`) and marked public for MCP Adapter discovery via `/wp-json/mcp/mcp-adapter-default-server`.
* For MCP clients, the ability schema describes the business payload. Some clients wrap calls in transport-specific `parameters` or `input` objects, but the SlyTranslate-specific fields are always the ones defined on the ability itself.
* Plugin labels and descriptions are translation-ready and include a bundled German (`de_DE`) translation.

**Requirements:**

* WordPress 7.0+
* PHP 8.1+
* WordPress MCP Adapter for MCP client access
* An AI provider plugin configured via Settings > Connectors (e.g., AI Provider for OpenAI, AI Provider for Anthropic, AI Provider for Google, **AI Provider for llama.cpp** for local llama.cpp-based setups, or **Ultimate AI Connector for Compatible Endpoints** for other OpenAI-compatible local/self-hosted endpoints)
* A translation plugin for content translation workflows across posts, pages, and custom post types: Polylang (recommended), with more adapters planned

== Installation ==

1. Ensure WordPress 7.0+ and PHP 8.1+ are running.
2. Optional for content translation workflows across posts, pages, and custom post types: install and activate a translation plugin (e.g., Polylang).
3. Install and activate an AI provider plugin and configure it via Settings > Connectors.
4. For local llama.cpp-based LLMs, we recommend **AI Provider for llama.cpp** so SlyTranslate can use locally exposed llama.cpp models through the WordPress AI Client. For other OpenAI-compatible local/self-hosted endpoints, we recommend **Ultimate AI Connector for Compatible Endpoints**.
5. Optional: Install and activate WordPress MCP Adapter if you want MCP clients to discover these abilities.
6. Upload the `slytranslate` folder to `/wp-content/plugins/`. This plugin currently still needs to be installed manually until the WordPress.org plugin directory listing is approved (pending).
7. Activate SlyTranslate - AI Translation Abilities.
8. Text translation abilities are now available via the Abilities API, REST API, and MCP Adapter. Content translation workflows become available once a translation plugin is active.

== Frequently Asked Questions ==

= Where do I configure API keys? =

API keys are managed centrally in WordPress via Settings > Connectors. This plugin does not handle API keys directly.

= How do I change the translation prompt? =

Use the `ai-translate/configure` ability via the REST API or any tool that supports WordPress Abilities. The `prompt_template` field sets the base instructions including language pair placeholders (`{FROM_CODE}`, `{TO_CODE}`). The `prompt_addon` field adds a site-wide addition that is always appended after the template — useful for global style requirements such as formal language or domain-specific vocabulary.

Call `ai-translate/configure` with an empty object if you only want to read the current site-wide defaults without changing anything.


= How do I add per-request translation instructions? =

The `ai-translate/translate-text`, `ai-translate/translate-blocks`, `ai-translate/translate-content`, and `ai-translate/translate-content-bulk` abilities accept an optional `additional_prompt` field. This text is appended after the prompt template and the site-wide add-on, so it can override or extend the defaults just for that request. In wp-admin, the **AI Translate** panel, the selected-text/block translation dialogs, and the post/page list-table picker share that saved per-user value, so recurring style preferences are pre-filled across the translation UI.

= What is the difference between prompt_template, prompt_addon, and additional_prompt? =

* **prompt_template** — the base translation instruction including `{FROM_CODE}` and `{TO_CODE}` placeholders. Managed by `ai-translate/configure`, requires `manage_options` capability.
* **prompt_addon** — a site-wide addition always appended after the template. Also managed by `ai-translate/configure` and admin-only. Use this for global requirements that apply to every translation on the site.
* **additional_prompt** (per-request) — an optional field on `translate-text`, `translate-blocks`, `translate-content`, and `translate-content-bulk`. Appended last, after the template and the site-wide add-on. Available to any user who can run translation abilities. In the block editor this is the **Additional instructions** textarea; the last used value is stored per user.

= How do I tune chunking for large posts? =

Use the `context_window_tokens` field on the `ai-translate/configure` ability to override the detected model context window when your provider uses a smaller or larger limit than the plugin inferred.

= Can I use local LLMs? =

Yes. For local llama.cpp-based LLMs, we recommend **AI Provider for llama.cpp** so the local server is available through WordPress Settings > Connectors and the WordPress AI Client.

For other local or self-hosted OpenAI-compatible inference servers, we recommend **Ultimate AI Connector for Compatible Endpoints**. If you need request-level parameters such as `chat_template_kwargs`, you can also use the `direct_api_url` setting on `ai-translate/configure` to send translation requests directly to that endpoint.

For models that require request-level parameters such as `chat_template_kwargs` (e.g. TranslateGemma on llama.cpp), use the `direct_api_url` setting on `ai-translate/configure` to bypass the AI Client and send requests directly to the endpoint.

= Can I use TranslateGemma or other specialized translation models? =

Yes. Set `direct_api_url` via `ai-translate/configure` to the URL of your llama.cpp (or other OpenAI-compatible) server. The plugin automatically probes for `chat_template_kwargs` support, re-probes when TranslateGemma is selected and kwargs are still missing, and then includes `source_lang_code` and `target_lang_code` in every translation request so the model can use its native language-routing.

TranslateGemma is now handled fail-safe: if no direct API URL is configured, if kwargs support cannot be confirmed, or if the direct API request fails, SlyTranslate returns an error instead of silently falling back to the generic WordPress AI Client path. The `ai-translate/configure` response exposes diagnostics such as `direct_api_kwargs_last_probed_at`, `translategemma_runtime_ready`, and `translategemma_runtime_status`.

For connector-based local models and direct API failures alike, `ai-translate/configure` now also exposes `last_transport_diagnostics`, including the last transport (`wp_ai_client` or direct API), the requested/effective model slug, fallback status, and the last error code/message captured by the runtime.

A custom Jinja chat template is required on the llama.cpp side to make this work — a ready-to-use template and setup guide are included in the plugin repository.

= What happens if a model returns a chat answer instead of a translation? =

SlyTranslate validates translation output before saving it. The plugin rejects empty responses, explanatory assistant replies, implausibly long short-text outputs, symbol-notation drift such as Unicode arrows rewritten as LaTeX, and structure loss in block content such as missing Gutenberg comments, HTML tags, URLs, or code fences. For standard instruct/chat models, it automatically retries once with stricter output instructions. For TranslateGemma, the request fails immediately once the output is deemed invalid.

= Can I translate pages or custom post types? =

Yes. The `translate-content`, `translate-content-bulk`, `get-translation-status`, and `get-untranslated` abilities work with any Polylang-enabled post type, including pages and custom post types.

= What is the best MCP call order for an LLM? =

Use the read-only abilities to remove ambiguity before mutating content:

* Call `ai-translate/get-languages` when the target language code is not known yet.
* Call `ai-translate/get-available-models` before sending `model_slug` if the available identifiers are unknown or the connector has changed.
* Call `ai-translate/get-translation-status` before `ai-translate/translate-content` when you need to inspect existing target posts or decide whether `overwrite` should be true.
* Call `ai-translate/get-untranslated` before `ai-translate/translate-content-bulk` when you do not already know the source post IDs.
* Call `ai-translate/configure` with an empty object to read persistent defaults; use `model_slug` and `additional_prompt` on `translate-*` abilities for one-off overrides.

For `ai-translate/translate-content-bulk`, choose one source selector: either `post_ids` or `post_type` with an optional `limit`. If both are sent, the plugin uses `post_ids` and ignores `post_type`.

= How do SEO plugin fields get translated? =

The plugin auto-detects supported SEO plugins such as Genesis SEO, Yoast SEO, Rank Math, All in One SEO, The SEO Framework, SEOpress, and Slim SEO. Their most important title/description meta fields are translated automatically, while analysis scores are cleared so the SEO plugin can recalculate them.

For runtime translation, the plugin does not rely only on the currently detected SEO plugin. It also checks the source post's stored meta keys and merges any matching supported profiles, so legacy Genesis fields like `_genesis_title` and `_genesis_description` are still translated even after a later SEO-plugin migration. Unknown Genesis flags, robots settings, and URL-like fields are left untouched unless you add them explicitly via `ai-translate/configure`.

= Can I trigger translation from the block editor? =

Yes. The block editor includes an **AI Translate** panel in the document settings sidebar when a translation plugin is active. It loads translation status, can create or update translations directly from the editor, and shows a live progress bar while a translation is running. During that time, the main action button switches to **Cancel translation** so you can stop the current job without leaving the editor. When you highlight text or open the block-level translation dialog, those modals reuse the sidebar's active model selection and the same side-by-side language picker layout as the post/page translation dialog.

= What happens if I close the post list translation dialog or leave the page mid-translation? =

The running translation is handed off automatically to the same global background-task bar that the **Continue in background** button uses. That means progress stays visible across wp-admin screens and the created draft does not appear "out of nowhere" after the dialog disappeared.

= Does this work without Polylang? =

Yes, for text translation. The `translate-text` ability and the block editor's selected-text translation action work independently. The translation abilities that create or manage translated content (`get-languages`, `get-translation-status`, `get-untranslated`, `translate-content`, `translate-content-bulk`) still require a translation plugin, currently Polylang.

== Changelog ==

= 1.6.0 =
* Performance: raised `MAX_OUTPUT_TOKENS_CEILING` from 8 192 to 32 768 tokens so large posts are no longer truncated mid-translation and fall back to slow per-block mode.
* Performance: added `gemma-4` to the known model context-window table (131 072 tokens), fixing the substring match that caused gemma-4 variants to be handled with only 8 192 tokens, splitting a ~48 000-char post into 12 chunks instead of 1.
* Performance: the computed chunk char limit is now cached for the duration of each translation job (cleared on model switch and between jobs), eliminating repeated option reads and model table lookups.
* Performance: eligible short-string SEO meta values (e.g. Yoast/Slim SEO title and description) are now translated in a single batched AI call instead of one call per key, reducing meta-phase AI round-trips by up to N−1 calls.

= Unreleased =
* Dev: added a repository instruction that blocks Autopilot completion until local validation, a versioned beta commit, Build and Deploy Plugin ZIP, and a WordPress MCP translation smoke test for post 1109 have all succeeded.

= 1.5.6 =
* MCP: sharpened public ability descriptions so discovery now hints at the intended read-only preparation flow before mutating translation calls.
* MCP: `ai-translate/translate-content-bulk` now documents its real source-selection contract more explicitly and exposes the already-supported `additional_prompt` input in the public ability schema.
* MCP: `ai-translate/configure` now documents the empty-object read pattern and clearer field guidance for persistent site-wide settings such as meta key strings and context-window overrides.
* Docs: README, plugin readme, and workflow guidance now include clearer MCP call-order hints and canonical payload examples for common LLM-driven translation flows.
* Tests: ability contract coverage now protects the sharper MCP schema details, and bulk translation validation now asserts the missing post-selection error path.
* Translation quality: source-aware symbol preservation now keeps Unicode arrows and similar math symbols from being rewritten as LaTeX notation, with a matching validation guard and no extra model round-trip in the success path.

= 1.5.5 =
* Diagnostics: `ai-translate/configure` now returns `last_transport_diagnostics`, exposing the last runtime transport, requested/effective model slug, fallback flag, and the last captured error code/message for connector or direct-API failures.
* Logging: post-translation `job_start` now uses the same requested model slug as the runtime transport path, preventing misleading model mismatches in debug logs when a per-request override is active.
* Connector diagnostics: `wp_ai_client` and strict direct-API failure paths now retain structured error details in runtime diagnostics instead of only logging them to `debug.log`.
* UI: the global background-task bridge now renders on every eligible wp-admin screen, so the first explicit **Continue in background** click works even before the user has any previously recorded recent translation job.
* Reliability: list-table translations now send their long-running translation requests with browser `keepalive`, so switching to another wp-admin area no longer aborts the translation after the background handoff starts.

= 1.5.4 =
* i18n: added missing translators comments for placeholder-based list-table status strings.
* Security: legacy bulk-action admin notices now carry and verify a dedicated notice nonce before reading result counters.
* Dev: replaced mt_rand() jitter with wp_rand(), documented intentional debug-only logging/time-limit calls for PHPCS, prefixed uninstall variables, and kept composer.json in the distributed plugin ZIP.
* Cleanup: removed unreleased Polylang auto-translate legacy hooks and bridge code.

= 1.5.3 =
* i18n: added German translation for "The source language is managed by your language plugin." hint text.
* UI: translation status heading and list in the editor sidebar panel now have explicit left-align styles on both elements so WordPress panel CSS cannot override alignment.
* UI: source/target language labels in the list-table picker now have margin:0 and padding:0 to prevent WordPress admin CSS from adding vertical spacing between label and select.
* UI: select elements in the list-table picker now have max-width:none to prevent WordPress admin's default max-width from capping their width in the flex/grid layout.
* UI: dashicons in the swap and refresh buttons in the list-table picker are now centered via margin-based block layout (display:block;margin:auto) instead of display:inline-flex, which WordPress admin .button CSS overrides.
* UI: refresh button in the list-table model picker row now has explicit height:36px and the select matches at height:36px so both flex items are the same height and align-items:center works correctly.
* UI: source language in the sidebar panel now shows the full language name (e.g. "English") instead of the raw code, with a hint that the source language is managed by the language plugin.
* UI: additional instructions (optional) field moved to below the AI model selector and above the "Translate now" button in the sidebar panel.
* UI: LLM model selector and refresh button now fill the full available width in the editor sidebar.
* UI: language-switch icon in the inline/block/page translation dialogs is now vertically centred in its grid cell.
* UI: source-language label in the inline/block/page translation dialogs no longer creates extra vertical space due to the hidden placeholder text wrapping.
* Fix: restored the plugin-owned `ai-translate/v1` admin REST bridge for editor and list-table translation flows.
* UI: inline selected-text and block translation dialogs now reuse the sidebar's active LLM selection and consistent side-by-side source/target picker layout.
* Dev: replaced the Brain Monkey and Patchwork-based test mocking layer with lightweight local WordPress function stubs.


= 1.5.2 =
* Security: SSRF allowlist for direct API URL with slytranslate_allow_internal_direct_api filter (1.1)
* Security: error_log calls guarded by WP_DEBUG (1.2)
* Security: additional_prompt capability-gated to edit_others_posts (1.4)
* Security: set_time_limit capped via slytranslate_max_request_seconds filter, no longer disabled entirely (1.5)
* Security: Direct API error body snippet logged only under WP_DEBUG, stripped and capped at 120 chars (1.6)
* Security: wp_unslash applied to $_GET reads in single-translate handler (1.7)
* Security: Default prompt template moved to private const with slytranslate_default_prompt_template filter (1.8)
* Performance: parse_blocks() called only once per post translation (2.1)
* Performance: get_post_meta() called only once per post translation (2.2)
* Performance: Polling backoff in foreground dialog and background bar (2.3)
* Performance: Background task bar only rendered when active/recent jobs exist (2.4)
* Performance: PSR-4 autoloader via Composer, replaces 18 synchronous require_once calls (2.5)
* Legacy: Inline JS for list-table dialog and background bar extracted to dedicated asset files (1.3)
* Legacy: Test-only wrapper methods removed from AI_Translate; tests now call service classes directly (3.1)
* Legacy: Duplicate /translate-post REST alias removed (3.2)
* Legacy: Version, REST namespace and editor script handle consolidated in Plugin::class constants (3.3)
* Legacy: LegacyPolylangBridge hooks registered conditionally when auto-translate-new is enabled (3.4)
* Legacy: Legacy ai_translate_to_<lang> bulk action gated behind slytranslate_legacy_bulk_actions_enabled filter (3.5)
* Legacy: enqueue_editor_plugin wrapper stub removed from AI_Translate (3.6)

= 1.5.1 =
* Architecture: removed custom `EditorRestController` and all `ai-translate/v1/` REST routes; all endpoints are now served through the WordPress Abilities API (`wp/v2/abilities/`).
* Architecture: added 5 new abilities — `ai-translate/translate-blocks`, `ai-translate/get-progress`, `ai-translate/cancel-translation`, `ai-translate/get-available-models`, `ai-translate/save-additional-prompt`.
* Architecture: plugin bootstrap is now guarded by `plugins_loaded` and requires `wp_ai_client_prompt` to be available before registering hooks.
* Settings: all plugin options are now registered via the WordPress Settings API (`admin_init` + `register_setting()`), enabling sanitization callbacks and REST exposure.
* Settings: all `update_option()` calls now pass `autoload = false` to avoid loading all options on every page request.
* Settings: added `ai_translate_force_direct_api` option (opt-in flag). The direct API is now only activated automatically for TranslateGemma models or when this flag is explicitly set to `1`. Previously, any non-empty `direct_api_url` + `model_slug` combination triggered direct-API mode.
* Architecture: `slytranslate/uninstall.php` added; cleans up all plugin options, user meta, and transients on plugin deletion.
* Architecture: activation hook (`register_activation_hook`) migrates existing options to `autoload = false`.
* Internals: `EditorBootstrap::get_editor_rest_base_path()` returns `/wp/v2/abilities/`; hard-coded `delete_transient('aipf_llamacpp_model_ids')` replaced by `do_action('slytranslate_refresh_provider_caches')`.

= 1.5.0 =
* UI: list-table row action and bulk action collapsed to a single "Translate" entry each; a unified picker dialog lets the user choose source/target language (with swap button), AI model, and additional instructions before starting.
* UI: editor sidebar panel renamed "Translate (SlyTranslate)"; title translation is now always on (toggle removed); translation-status list simplified ("Not translated yet" / "Open" link).
* UI: model-selector refresh button added to the editor sidebar (same cache-busting behaviour as the list-table picker).
* UI: inline and block-level translation modals now include a source↔target swap button.
* UI: background-task bar overhauled — compact/collapsible, per-post-title labels, strictly monotonic progress, auto-dismisses finished tasks after 5 s, full content width, "Clear finished" action.
* UI: progress bar now advances proportionally to translated character volume instead of step count; chunk counter label removed.
* Performance: `core/list` blocks skip the outer group-translation attempt and go directly to the recursive inner-block path, removing one wasted model call per list wrapper.
* Performance: block translation is 5–20× faster on small instruct models — short plain-text hint for paragraph fast-path, skip redundant group-fallback calls, fix trailing-whitespace block-comment mismatch.
* Performance: chunk size ceiling raised from 24 000 → 48 000 chars; max output tokens ceiling from 4 096 → 8 192 (both tunable via new filters). Simple-wrapper blocks now send only inner HTML to the model (single call instead of two).
* Feature: context-window size is now discovered dynamically from the direct API's `/v1/models` response (`context_window`, `context_length`, `meta.n_ctx_train`/`meta.n_ctx`) and cached per model; no plugin update needed for new endpoints.
* Fix: HTTP 429 rate-limit responses handled transparently on both transports with a parse-pause-retry loop (up to 3 attempts, `Retry-After` / inline hint parsed, 500 ms jitter).
* Fix: direct-API timeout raised to 120 s; transport errors fall back to the WP AI Client for that chunk and resume the direct path on the next.
* Fix: direct API is now skipped entirely when no explicit model slug is in scope; model-slug resolution falls back to the WP AI Client registry (`findModelsMetadataForSupport()`).
* Fix: validator failures (`invalid_translation_*`) no longer abort the entire job — the offending block is kept in the source language and the rest of the post is translated normally.
* Fix: runaway output (>3× source length for inputs >220 chars) rejected with `invalid_translation_runaway_output`; max output-token budget enforced per chunk on both transports.
* Fix: short-input length-drift guard checks raw (un-normalised) text and adds a 6× ratio hard ceiling, catching numbered-list hallucinations that previously bypassed the markdown regex.
* Fix: markdown code fences flagged by `contains_markdown_structure()`; structural tripwire rejects plain-source → structured-output expansions ≥ 3× regardless of absolute floor.
* Fix: pseudo-XML single-tag outputs (e.g. `<responsible>`) unwrapped to plain text before validation.
* Fix: tag-only fragments (image-only blocks, empty block-comment shells) no longer fail `invalid_translation_plain_text_missing`.
* Fix: simple-wrapper fast path verifies inline-formatting tag counts (em/strong/code/a/…) and retries if any tag is dropped; also detects and recovers corrupted wrapper boundaries (missing opening tag, stray markdown).
* Fix: translated content is no longer passed through `wp_kses_post()` before saving (prevented SVG attribute case, stripped custom-block attributes, broke Gutenberg block validation).
* Fix: HTML→Markdown inline-formatting regression (e.g. `<strong>` → `**`) detected and triggers retry/fallback.
* Fix: progress transient cleared on job completion; cancel endpoint also resets progress to zero; parallel translations keyed by `(user_id, post_id)`.
* Fix: inline/block replacement in the editor applied synchronously to avoid stale closure references on re-mount; source-language dropdown defaults to the post's actual language.
* Debug: `ai_validation_failed` event now includes 240-char excerpts of source and output; `direct_api_error_body` logs non-2xx response bodies; full per-job wall-clock timeline emitted when `WP_DEBUG=true`; bg-bar respects `window.SLY_TRANSLATE_DEBUG`.
* i18n: German translations updated for all new strings; `slytranslate-de_DE.mo` recompiled.
* Fix: the "Refresh model list" button in the post/page list-table translation dialog now actually returns a fresh model list when the user switched the configured AI connector (e.g. from a local llama.cpp server to Groq). Previously only the SlyTranslate-side 5-minute transient (`ai_translate_available_models`) was cleared, but the WordPress AI Client's own per-provider `ModelMetadataDirectory` cache (default TTL: 24 hours, persisted via the PSR-16 cache configured through `AiClient::setCache()`) kept serving the model list from the previously active connector. The refresh path now additionally calls `invalidateCaches()` on every registered provider's `modelMetadataDirectory()` and clears the `aipf_llamacpp_model_ids` transient used by `ai-provider-for-llamacpp`, so connector changes take effect on the next refresh click without waiting 24 hours.
* i18n: German translations added for the new model-picker dialog strings ("AI model", "Start translation", "Refresh model list", "Loading available models...", "Connector default", bulk-translation title / progress / completion messages, …) and recompiled `slytranslate-de_DE.mo`.
* UI: the post/page list-table translation flow now opens a model-picker dialog before translation starts. The dialog lists every model registered by the active AI Connector (queried live via the new `ai-translate/available-models` REST route, with a refresh button to bypass the 5-minute model cache) and remembers the last selection per browser. Bulk translations show one dialog for the whole selection and then process the items as background tasks via the existing bg-bar, so each post still gets its own progress / cancel UI. The previous behaviour silently reused a stale `aiTranslateModelSlug` value from the editor sidebar, which made the list-table action send the wrong model after switching connectors.
* Internal: `EditorBootstrap::get_available_models()` now accepts an optional `$force_refresh` flag to bypass the `ai_translate_available_models` transient. The new REST endpoint `ai-translate/v1/ai-translate/available-models` exposes this to the admin UI so users can pick up newly configured connectors immediately.
* Fix: selected-text translation in the block editor no longer confuses the additional prompt with the content to translate. The additional prompt is now clearly prefixed as style instructions in the system message so models do not treat it as translatable content.
* Fix: when the configured direct API endpoint is unreachable (connection refused, timeout), the error is now returned immediately with a clear message including the endpoint URL, instead of silently falling back to the WordPress AI Client (which would fail with the same infrastructure).
* Fix: translation of posts with dense Gutenberg block structure (e.g. privacy policy pages) no longer fails with a "lost required structure" error. When grouped block translation drops placeholders even after retry, the plugin now falls back to translating blocks individually instead of aborting.
* Fix: translation of posts with dense Gutenberg block structure (e.g. privacy policy pages) no longer fails with a "lost required structure" error. The closing block comment of each translated chunk was silently dropped by translation models because it became a trailing HTML comment with nothing after it; a sentinel marker is now appended before sending to the model and stripped afterward, so all placeholders are preserved through translation.
* Fix: translation of posts containing links where the visible anchor text is a URL (e.g. `<a href="https://…">https://…</a>`) no longer triggers a false "URL lost" structural-drift error when the model correctly replaces the visible URL with descriptive text. The URL integrity check now counts only URLs in `href`/`src`/`action` attributes rather than all URL occurrences in the text.
* Editor: added "Translate → [Language]" row-action links to the post/page list tables; links appear only for languages that do not yet have a translation of that post.
* Editor: added "Translate with AI → [Language]" entries to the bulk-action dropdown on all post/page list-table screens; results (created, skipped, errors) are reported via an admin notice after processing.

= 1.4.1 =
* Refactoring: Extracted TranslationProgressTracker, TranslationRuntime, DirectApiTranslationClient, ContentTranslator, MetaTranslationService, PostTranslationService, EditorRestController, LegacyPolylangBridge, and TranslationQueryService from the monolithic AI_Translate class to improve maintainability. All public APIs and test contracts remain stable.
* Refactoring: extracted text-splitting and translation output validation into dedicated helper classes while keeping the public AI_Translate API and test contracts stable.
* Guardrails: direct API translations now pass through the same output validation and retry logic as the WordPress AI Client path, so invalid assistant-style or structure-breaking responses are rejected consistently.
* Security: editor REST routes now require structured input payloads, translation abilities reject malformed required inputs with explicit errors, and translation-status details are hidden when the current user cannot access the target post.
* SEO: runtime SEO meta resolution now merges the active SEO profile with supported source-post meta keys, so legacy Genesis `_genesis_title` and `_genesis_description` fields are translated even when another SEO plugin is currently active.
* TranslateGemma: in direct API kwargs mode, translation requests no longer send the system prompt, preventing llama.cpp templates from echoing prompt or style-guidance text at the start of translated chunks.
* Editor: moved the "Refresh translation status" button below the translation-status list in the block editor sidebar.
* Translation: block-aware chunking now groups complete Gutenberg blocks into size-bounded chunks instead of splitting serialized content at character boundaries, preventing structural-drift validation errors on posts with complex blocks (e.g. `kevinbatdorf/code-block-pro`).
* Translation: Gutenberg block comments (`<!-- wp:… -->`) are replaced with neutral placeholders before being sent to the translation model and restored afterward, preventing small models (e.g. TranslateGemma 4B) from dropping inner block structure markers such as `<!-- wp:list-item -->`.
* Translation: blocks without translatable text content (e.g. `core/image` with empty alt text, `core/separator`) are now passed through unchanged instead of being sent to the model, eliminating spurious URL-loss validation errors.
* Guardrails: the structure-drift validator skips the HTML tag count check when the source content contains block-comment placeholders (`<!--SLYWPC…-->`), since block structure is verified externally via placeholder restoration; URL and code-fence integrity checks remain active.

= 1.4.0 =
* Editor: added a real-time translation progress bar to the AI Translate sidebar panel, including phase labels and content chunk tracking.
* Editor: the main translation button now toggles to **Cancel translation** while a translation is running, replacing the separate cancel button.
* API: added server-side translation progress tracking via WordPress transients and a polling REST endpoint for the editor sidebar.
* TranslateGemma: fail closed when no `direct_api_url` is configured, when `chat_template_kwargs` support cannot be confirmed, or when the direct API request fails — no more silent fallback to the generic AI Client path.
* Diagnostics: `ai-translate/configure` now exposes the last kwargs probe timestamp plus a TranslateGemma runtime readiness/status indicator.
* Guardrails: validates translated output before saving and rejects empty/chat-style responses, implausibly long short-text outputs, and structure loss in Gutenberg/HTML content.

= 1.3.3 =
* Editor: shortened TranslateGemma warning text on the Additional instructions field — removed the specific model name examples ("Gemma 3 IT or Qwen2.5 Instruct").
* Editor: moved the TranslateGemma warning from below the Additional instructions textarea to below the AI model dropdown, so it appears directly next to the relevant control.
* i18n: added German translation for the TranslateGemma warning string in `slytranslate-de_DE.po` and registered the msgid in `slytranslate.pot`.

= 1.3.2 =
* Bug fix: in TranslateGemma plain mode (no `chat_template_kwargs`), the system message is no longer prepended to the user turn — this prevented the model from echoing back translation instructions as output text instead of translating the content.
* Improvement: in TranslateGemma kwargs mode, an optional style guidance hint derived from the `additional_prompt` / `prompt_addon` is now appended after the native "Please translate…" sentence (`Style guidance: …`). Effectiveness is limited by design — TranslateGemma is not an instruction-following model.
* Editor: the **Additional instructions** field in the AI Translate sidebar panel and the selected-text translation modal now shows a warning when the active model slug contains `translategemma`, explaining that style guidelines are not reliably supported and suggesting Instruct-LLMs (e.g. Gemma 3 IT, Qwen2.5 Instruct) as alternatives.

= 1.3.1 =
* Security: sanitized AI-generated content before saving to the database — post title is now passed through `sanitize_text_field()`, post content and excerpt through `wp_kses_post()`, and translated meta values through `sanitize_text_field()` in `PolylangAdapter`. Prevents stored XSS if an AI provider returns malicious markup.
* Security: capped `additional_prompt` to 2000 characters across all three entry points (`translate-text`, `translate-content`, and the user-preference endpoint) to reduce prompt injection attack surface.
* Build: downgraded PHPUnit requirement from `^11.0` to `^10.5` so the test suite runs on PHP 8.1 (the minimum supported version); updated `composer.lock` accordingly.
* Build: rebuilt `slytranslate-de_DE.mo` from the current `slytranslate-de_DE.po` to bring the tracked binary translation file back in sync.

= 1.3.0 =
* Security hardening: sanitized `additional_prompt` inputs, blocked internal WordPress meta keys from being copied during translation, validated direct API URLs to http(s) only, capped `context_window_tokens`, and hardened Polylang language mapping.
* Bug fixes: synchronized version metadata, aligned the `auto_translate_new` default, and cleaned up translation content handling.
* Performance: cached AI model discovery in the editor bootstrap, reused the direct API URL from runtime context, and reduced direct API probe blocking during configuration saves.
* Refactoring: removed unused parsed-block translators, deduplicated meta-key normalization, extracted model override and translation-meta helpers, and simplified bulk/content execution flow.
* Architecture: split editor bootstrap and configuration persistence into dedicated module classes and moved ability registration behind a dedicated registrar.

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
