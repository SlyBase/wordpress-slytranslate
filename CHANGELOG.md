# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.6.0]
### Features
- WP Multilang: major new integration support for the WP Multilang plugin alongside the existing Polylang integration.

### Changes
- Performance: post title and excerpt are now translated in the same batched AI call as SEO meta fields, saving up to 2 API requests per post.
- Architecture: introduced a central model-profile registry (`slytranslate_model_profiles`) driving prompt style, chunk strategy, and retry behavior per model family.
- Profiles: added dedicated profiles for major local model families including Ministral, Tower, Qwen, GLM, Gemma 4, Phi-4, Nemotron, EuroLLM, and Llama 3.1/Sauerkraut.
- Performance: SEO meta values (Yoast/Slim SEO title & description) are now translated in a single batched AI call instead of one per key.
- UI: list-table translation now includes an explicit overwrite option with a confirmation warning.
- Performance: background progress polling now stops automatically when no translation is running and pauses when the browser tab is hidden, reducing unnecessary RAM and CPU use in long-lived admin tabs.
- Transport: chat-capable model families now use the WordPress AI Client connector as the standard translation path.
- Admin: list-table dialog and background task bar scripts are now loaded through enqueued assets with localized bootstrap data instead of inline admin JavaScript.
- Admin: list-table dialog markup styles are now loaded from an enqueued stylesheet instead of inline style attributes.
- Compatibility: plugin header and plugin readme metadata now declare WordPress 6.9 as minimum and tested version format.
- Prompting: tone/formality rules now come solely from `additional_prompt`; hardcoded DE formality rule removed.
- API: content-translation abilities now accept an optional source language for stricter WP Multilang requests.
- MCP: `get-translation-status` now publicly advertises `single_entry_mode`, and `translate-content` guidance now documents the safer source-language call order.

### Fixes
- i18n: added the missing German translation for the list-table overwrite confirmation warning.
- Reliability: reasoning-aware models now inject thinking-related `chat_template_kwargs` more safely, reducing empty connector outputs.
- Reliability: remote reasoning models (e.g. OpenRouter GLM-5.1) that return output in `reasoning` or `reasoning_content` instead of `content` are now handled transparently — the reasoning text is promoted to the content field before the WP AI Client parses the response, preventing the "No text content found in first candidate" error.
- Validation: collapsed output guard now rejects a single-word translation of any multi-sentence source, regardless of target language; previously the check was restricted to German-target only, allowing a 202-char paragraph to be saved as just "The" when Nemotron Free returned a collapsed response.
- Validation: prompt-echo detection now catches responses where the model echoes the system prompt verbatim (e.g. "We need to translate from German to English..."); previously the retry attempt slipped through as runaway_output because the echoed base prompt lacked the CRITICAL extension added during retry, causing German source blocks to be kept silently.
- Reliability: when a model echoes the system prompt on both the initial attempt and the retry (both fail as `invalid_translation_assistant_reply`), a third recovery attempt now uses a plain user-only prompt without a system role; this unblocks Nemotron-family models that enter thinking mode on every system-prompted request.
- Reliability: the plain-prompt recovery now uses a quoted-arrow format (`"{text}" →`) instead of language-labelled or instruction-style prompts; this eliminates remaining echo failures and prevents Nemotron from producing empty responses (which previously caused a HTTP 500 for the whole translation job).
- Reliability: transport errors during plain-prompt recovery (e.g. empty model response) are now treated as soft validator failures, keeping the block in the source language instead of aborting the entire translation with HTTP 500.
- Reliability: repeated source-language passthrough now also triggers the third user-only plain-prompt recovery attempt, helping Nemotron-family models recover English output instead of leaving German blocks unchanged.
- Reliability: Nemotron-family models now retry failed paragraph translations with much smaller chunks, improving stubborn German source blocks that previously stayed untranslated after repeated prompt-echo failures.
- Validation: passthrough detection now also catches German carry-over in English-target translations, including outputs that wrap unchanged source text in extra headings or summary prose.
- Validation: English-target translations now also reject obvious German rewrites that paraphrase the source instead of translating it, preventing wrong-language content from being saved as a successful English result.
- Polylang: translation creation no longer fails when the new target draft already has the requested language.
- Security: single and bulk translation admin notices now require a verified notice nonce before result parameters are read.
- Security: `ai_translate_learned_context_windows` now sanitizes and bounds learned model context-window values via the Settings API callback.
- Validation: `gemma-4` context window corrected to 131,072 tokens (was 8,192), fixing excessive over-chunking.
- Validation: added passthrough detection for English carry-over in German-target translations.
- Reliability: connector timeout errors now trigger an automatic smaller-chunk retry instead of failing the whole translation immediately.
- WP Multilang: list-table translations now return a dedicated source-language mismatch error when the selected source is not the active language.
- WP Multilang: `source_language` can now explicitly select the source variant in MCP and bulk ability calls instead of being locked to the active language.
- Gutenberg: recursive list-wrapper reconstruction now preserves valid block comment names to prevent malformed list blocks.
- Stability: translated posts now clear inherited `_oembed_*` cache meta so corrupted embed cache values can no longer crash the editor preload.


## [1.5.6]
- MCP: sharpened public ability descriptions so discovery now hints at the intended read-only preparation flow before mutating translation calls.
- MCP: `ai-translate/translate-content-bulk` now documents its real source-selection contract more explicitly and exposes the already-supported `additional_prompt` input in the public ability schema.
- MCP: `ai-translate/configure` now documents the empty-object read pattern and clearer field guidance for persistent site-wide settings such as meta key strings and context-window overrides.
- Docs: README, plugin readme, and workflow guidance now include clearer MCP call-order hints and canonical payload examples for common LLM-driven translation flows.
- Tests: ability contract coverage now protects the sharper MCP schema details, and bulk translation validation now asserts the missing post-selection error path.
- Translation quality: source-aware symbol preservation now keeps Unicode arrows and similar math symbols from being rewritten as LaTeX notation, with a matching validation guard and no extra model round-trip in the success path.

## [1.5.5]
- Diagnostics: `ai-translate/configure` now returns `last_transport_diagnostics`, exposing the last runtime transport, requested/effective model slug, fallback flag, and the last captured error code/message for connector or direct-API failures.
- Logging: post-translation `job_start` now uses the same requested model slug as the runtime transport path, preventing misleading model mismatches in debug logs when a per-request override is active.
- Connector diagnostics: `wp_ai_client` and strict direct-API failure paths now retain structured error details in runtime diagnostics instead of only logging them to `debug.log`.
- UI: the global background-task bridge now renders on every eligible wp-admin screen, so the first explicit **Continue in background** click works even before the user has any previously recorded recent translation job.
- Reliability: list-table translations now send their long-running translation requests with browser `keepalive`, so switching to another wp-admin area no longer aborts the translation after the background handoff starts.

## [1.5.4]
- i18n: added missing translators comments for placeholder-based list-table status strings.
- Security: legacy bulk-action admin notices now carry and verify a dedicated notice nonce before reading result counters.
- Dev: replaced mt_rand() jitter with wp_rand(), documented intentional debug-only logging/time-limit calls for PHPCS, prefixed uninstall variables, and kept composer.json in the distributed plugin ZIP.
- Cleanup: removed unreleased Polylang auto-translate legacy hooks and bridge code.

## [1.5.3]
- i18n: added German translation for "The source language is managed by your language plugin." hint text.
- UI: translation status heading and list in the editor sidebar panel now have explicit left-align styles on both elements so WordPress panel CSS cannot override alignment.
- UI: source/target language labels in the list-table picker now have margin:0 and padding:0 to prevent WordPress admin CSS from adding vertical spacing between label and select.
- UI: select elements in the list-table picker now have max-width:none to prevent WordPress admin's default max-width from capping their width in the flex/grid layout.
- UI: dashicons in the swap and refresh buttons in the list-table picker are now centered via margin-based block layout (display:block;margin:auto) instead of display:inline-flex, which WordPress admin .button CSS overrides.
- UI: refresh button in the list-table model picker row now has explicit height:36px and the select matches at height:36px so both flex items are the same height and align-items:center works correctly.
- UI: source language in the sidebar panel now shows the full language name (e.g. "English") instead of the raw code, with a hint that the source language is managed by the language plugin.
- UI: additional instructions (optional) field moved to below the AI model selector and above the "Translate now" button in the sidebar panel.
- UI: LLM model selector and refresh button now fill the full available width in the editor sidebar.
- UI: language-switch icon in the inline/block/page translation dialogs is now vertically centred in its grid cell.
- UI: source-language label in the inline/block/page translation dialogs no longer creates extra vertical space due to the hidden placeholder text wrapping.
- Fix: restored the plugin-owned `ai-translate/v1` admin REST bridge for editor and list-table translation flows.
- UI: inline selected-text and block translation dialogs now reuse the sidebar's active LLM selection and consistent side-by-side source/target picker layout.
- Dev: replaced the Brain Monkey and Patchwork-based test mocking layer with lightweight local WordPress function stubs.


## [1.5.2]
- Security: SSRF allowlist for direct API URL with slytranslate_allow_internal_direct_api filter (1.1)
- Security: error_log calls guarded by WP_DEBUG (1.2)
- Security: additional_prompt capability-gated to edit_others_posts (1.4)
- Security: set_time_limit capped via slytranslate_max_request_seconds filter, no longer disabled entirely (1.5)
- Security: Direct API error body snippet logged only under WP_DEBUG, stripped and capped at 120 chars (1.6)
- Security: wp_unslash applied to $_GET reads in single-translate handler (1.7)
- Security: Default prompt template moved to private const with slytranslate_default_prompt_template filter (1.8)
- Performance: parse_blocks() called only once per post translation (2.1)
- Performance: get_post_meta() called only once per post translation (2.2)
- Performance: Polling backoff in foreground dialog and background bar (2.3)
- Performance: Background task bar only rendered when active/recent jobs exist (2.4)
- Performance: PSR-4 autoloader via Composer, replaces 18 synchronous require_once calls (2.5)
- Legacy: Inline JS for list-table dialog and background bar extracted to dedicated asset files (1.3)
- Legacy: Test-only wrapper methods removed from AI_Translate; tests now call service classes directly (3.1)
- Legacy: Duplicate /translate-post REST alias removed (3.2)
- Legacy: Version, REST namespace and editor script handle consolidated in Plugin::class constants (3.3)
- Legacy: LegacyPolylangBridge hooks registered conditionally when auto-translate-new is enabled (3.4)
- Legacy: Legacy ai_translate_to_<lang> bulk action gated behind slytranslate_legacy_bulk_actions_enabled filter (3.5)
- Legacy: enqueue_editor_plugin wrapper stub removed from AI_Translate (3.6)

## [1.5.1]
- Architecture: removed custom `EditorRestController` and all `ai-translate/v1/` REST routes; all endpoints are now served through the WordPress Abilities API (`wp/v2/abilities/`).
- Architecture: added 5 new abilities — `ai-translate/translate-blocks`, `ai-translate/get-progress`, `ai-translate/cancel-translation`, `ai-translate/get-available-models`, `ai-translate/save-additional-prompt`.
- Architecture: plugin bootstrap is now guarded by `plugins_loaded` and requires `wp_ai_client_prompt` to be available before registering hooks.
- Settings: all plugin options are now registered via the WordPress Settings API (`admin_init` + `register_setting()`), enabling sanitization callbacks and REST exposure.
- Settings: all `update_option()` calls now pass `autoload = false` to avoid loading all options on every page request.
- Settings: added `ai_translate_force_direct_api` option (opt-in flag). The direct API is now only activated automatically for TranslateGemma models or when this flag is explicitly set to `1`. Previously, any non-empty `direct_api_url` + `model_slug` combination triggered direct-API mode.
- Architecture: `slytranslate/uninstall.php` added; cleans up all plugin options, user meta, and transients on plugin deletion.
- Architecture: activation hook (`register_activation_hook`) migrates existing options to `autoload = false`.
- Internals: `EditorBootstrap::get_editor_rest_base_path()` returns `/wp/v2/abilities/`; hard-coded `delete_transient('aipf_llamacpp_model_ids')` replaced by `do_action('slytranslate_refresh_provider_caches')`.

## [1.5.0]
- UI: list-table row action and bulk action collapsed to a single "Translate" entry each; a unified picker dialog lets the user choose source/target language (with swap button), AI model, and additional instructions before starting.
- UI: editor sidebar panel renamed "Translate (SlyTranslate)"; title translation is now always on (toggle removed); translation-status list simplified ("Not translated yet" / "Open" link).
- UI: model-selector refresh button added to the editor sidebar (same cache-busting behaviour as the list-table picker).
- UI: inline and block-level translation modals now include a source↔target swap button.
- UI: background-task bar overhauled — compact/collapsible, per-post-title labels, strictly monotonic progress, auto-dismisses finished tasks after 5 s, full content width, "Clear finished" action.
- UI: progress bar now advances proportionally to translated character volume instead of step count; chunk counter label removed.
- Performance: `core/list` blocks skip the outer group-translation attempt and go directly to the recursive inner-block path, removing one wasted model call per list wrapper.
- Performance: block translation is 5–20× faster on small instruct models — short plain-text hint for paragraph fast-path, skip redundant group-fallback calls, fix trailing-whitespace block-comment mismatch.
- Performance: chunk size ceiling raised from 24 000 → 48 000 chars; max output tokens ceiling from 4 096 → 8 192 (both tunable via new filters). Simple-wrapper blocks now send only inner HTML to the model (single call instead of two).
- Feature: context-window size is now discovered dynamically from the direct API's `/v1/models` response (`context_window`, `context_length`, `meta.n_ctx_train`/`meta.n_ctx`) and cached per model; no plugin update needed for new endpoints.
- Fix: HTTP 429 rate-limit responses handled transparently on both transports with a parse-pause-retry loop (up to 3 attempts, `Retry-After` / inline hint parsed, 500 ms jitter).
- Fix: direct-API timeout raised to 120 s; transport errors fall back to the WP AI Client for that chunk and resume the direct path on the next.
- Fix: direct API is now skipped entirely when no explicit model slug is in scope; model-slug resolution falls back to the WP AI Client registry (`findModelsMetadataForSupport()`).
- Fix: validator failures (`invalid_translation_*`) no longer abort the entire job — the offending block is kept in the source language and the rest of the post is translated normally.
- Fix: runaway output (>3× source length for inputs >220 chars) rejected with `invalid_translation_runaway_output`; max output-token budget enforced per chunk on both transports.
- Fix: short-input length-drift guard checks raw (un-normalised) text and adds a 6× ratio hard ceiling, catching numbered-list hallucinations that previously bypassed the markdown regex.
- Fix: markdown code fences flagged by `contains_markdown_structure()`; structural tripwire rejects plain-source → structured-output expansions ≥ 3× regardless of absolute floor.
- Fix: pseudo-XML single-tag outputs (e.g. `<responsible>`) unwrapped to plain text before validation.
- Fix: tag-only fragments (image-only blocks, empty block-comment shells) no longer fail `invalid_translation_plain_text_missing`.
- Fix: simple-wrapper fast path verifies inline-formatting tag counts (em/strong/code/a/…) and retries if any tag is dropped; also detects and recovers corrupted wrapper boundaries (missing opening tag, stray markdown).
- Fix: translated content is no longer passed through `wp_kses_post()` before saving (prevented SVG attribute case, stripped custom-block attributes, broke Gutenberg block validation).
- Fix: HTML→Markdown inline-formatting regression (e.g. `<strong>` → `**`) detected and triggers retry/fallback.
- Fix: progress transient cleared on job completion; cancel endpoint also resets progress to zero; parallel translations keyed by `(user_id, post_id)`.
- Fix: inline/block replacement in the editor applied synchronously to avoid stale closure references on re-mount; source-language dropdown defaults to the post's actual language.
- Debug: `ai_validation_failed` event now includes 240-char excerpts of source and output; `direct_api_error_body` logs non-2xx response bodies; full per-job wall-clock timeline emitted when `WP_DEBUG=true`; bg-bar respects `window.SLY_TRANSLATE_DEBUG`.
- i18n: German translations updated for all new strings; `slytranslate-de_DE.mo` recompiled.
- Fix: the "Refresh model list" button in the post/page list-table translation dialog now actually returns a fresh model list when the user switched the configured AI connector (e.g. from a local llama.cpp server to Groq). Previously only the SlyTranslate-side 5-minute transient (`ai_translate_available_models`) was cleared, but the WordPress AI Client's own per-provider `ModelMetadataDirectory` cache (default TTL: 24 hours, persisted via the PSR-16 cache configured through `AiClient::setCache()`) kept serving the model list from the previously active connector. The refresh path now additionally calls `invalidateCaches()` on every registered provider's `modelMetadataDirectory()` and clears the `aipf_llamacpp_model_ids` transient used by `ai-provider-for-llamacpp`, so connector changes take effect on the next refresh click without waiting 24 hours.
- i18n: German translations added for the new model-picker dialog strings ("AI model", "Start translation", "Refresh model list", "Loading available models...", "Connector default", bulk-translation title / progress / completion messages, …) and recompiled `slytranslate-de_DE.mo`.
- UI: the post/page list-table translation flow now opens a model-picker dialog before translation starts. The dialog lists every model registered by the active AI Connector (queried live via the new `ai-translate/available-models` REST route, with a refresh button to bypass the 5-minute model cache) and remembers the last selection per browser. Bulk translations show one dialog for the whole selection and then process the items as background tasks via the existing bg-bar, so each post still gets its own progress / cancel UI. The previous behaviour silently reused a stale `aiTranslateModelSlug` value from the editor sidebar, which made the list-table action send the wrong model after switching connectors.
- Internal: `EditorBootstrap::get_available_models()` now accepts an optional `$force_refresh` flag to bypass the `ai_translate_available_models` transient. The new REST endpoint `ai-translate/v1/ai-translate/available-models` exposes this to the admin UI so users can pick up newly configured connectors immediately.
- Fix: selected-text translation in the block editor no longer confuses the additional prompt with the content to translate. The additional prompt is now clearly prefixed as style instructions in the system message so models do not treat it as translatable content.
- Fix: when the configured direct API endpoint is unreachable (connection refused, timeout), the error is now returned immediately with a clear message including the endpoint URL, instead of silently falling back to the WordPress AI Client (which would fail with the same infrastructure).
- Fix: translation of posts with dense Gutenberg block structure (e.g. privacy policy pages) no longer fails with a "lost required structure" error. When grouped block translation drops placeholders even after retry, the plugin now falls back to translating blocks individually instead of aborting.
- Fix: translation of posts with dense Gutenberg block structure (e.g. privacy policy pages) no longer fails with a "lost required structure" error. The closing block comment of each translated chunk was silently dropped by translation models because it became a trailing HTML comment with nothing after it; a sentinel marker is now appended before sending to the model and stripped afterward, so all placeholders are preserved through translation.
- Fix: translation of posts containing links where the visible anchor text is a URL (e.g. `<a href="https://…">https://…</a>`) no longer triggers a false "URL lost" structural-drift error when the model correctly replaces the visible URL with descriptive text. The URL integrity check now counts only URLs in `href`/`src`/`action` attributes rather than all URL occurrences in the text.
- Editor: added "Translate → [Language]" row-action links to the post/page list tables; links appear only for languages that do not yet have a translation of that post.
- Editor: added "Translate with AI → [Language]" entries to the bulk-action dropdown on all post/page list-table screens; results (created, skipped, errors) are reported via an admin notice after processing.

## [1.4.1]
- Refactoring: Extracted TranslationProgressTracker, TranslationRuntime, DirectApiTranslationClient, ContentTranslator, MetaTranslationService, PostTranslationService, EditorRestController, LegacyPolylangBridge, and TranslationQueryService from the monolithic AI_Translate class to improve maintainability. All public APIs and test contracts remain stable.
- Refactoring: extracted text-splitting and translation output validation into dedicated helper classes while keeping the public AI_Translate API and test contracts stable.
- Guardrails: direct API translations now pass through the same output validation and retry logic as the WordPress AI Client path, so invalid assistant-style or structure-breaking responses are rejected consistently.
- Security: editor REST routes now require structured input payloads, translation abilities reject malformed required inputs with explicit errors, and translation-status details are hidden when the current user cannot access the target post.
- SEO: runtime SEO meta resolution now merges the active SEO profile with supported source-post meta keys, so legacy Genesis `_genesis_title` and `_genesis_description` fields are translated even when another SEO plugin is currently active.
- TranslateGemma: in direct API kwargs mode, translation requests no longer send the system prompt, preventing llama.cpp templates from echoing prompt or style-guidance text at the start of translated chunks.
- Editor: moved the "Refresh translation status" button below the translation-status list in the block editor sidebar.
- Translation: block-aware chunking now groups complete Gutenberg blocks into size-bounded chunks instead of splitting serialized content at character boundaries, preventing structural-drift validation errors on posts with complex blocks (e.g. `kevinbatdorf/code-block-pro`).
- Translation: Gutenberg block comments (`<!-- wp:… -->`) are replaced with neutral placeholders before being sent to the translation model and restored afterward, preventing small models (e.g. TranslateGemma 4B) from dropping inner block structure markers such as `<!-- wp:list-item -->`.
- Translation: blocks without translatable text content (e.g. `core/image` with empty alt text, `core/separator`) are now passed through unchanged instead of being sent to the model, eliminating spurious URL-loss validation errors.
- Guardrails: the structure-drift validator skips the HTML tag count check when the source content contains block-comment placeholders (`<!--SLYWPC…-->`), since block structure is verified externally via placeholder restoration; URL and code-fence integrity checks remain active.

## [1.4.0]
- Editor: added a real-time translation progress bar to the AI Translate sidebar panel, including phase labels and content chunk tracking.
- Editor: the main translation button now toggles to **Cancel translation** while a translation is running, replacing the separate cancel button.
- API: added server-side translation progress tracking via WordPress transients and a polling REST endpoint for the editor sidebar.
- TranslateGemma: fail closed when no `direct_api_url` is configured, when `chat_template_kwargs` support cannot be confirmed, or when the direct API request fails — no more silent fallback to the generic AI Client path.
- Diagnostics: `ai-translate/configure` now exposes the last kwargs probe timestamp plus a TranslateGemma runtime readiness/status indicator.
- Guardrails: validates translated output before saving and rejects empty/chat-style responses, implausibly long short-text outputs, and structure loss in Gutenberg/HTML content.

## [1.3.3]
- Editor: shortened TranslateGemma warning text on the Additional instructions field — removed the specific model name examples ("Gemma 3 IT or Qwen2.5 Instruct").
- Editor: moved the TranslateGemma warning from below the Additional instructions textarea to below the AI model dropdown, so it appears directly next to the relevant control.
- i18n: added German translation for the TranslateGemma warning string in `slytranslate-de_DE.po` and registered the msgid in `slytranslate.pot`.

## [1.3.2]
- Bug fix: in TranslateGemma plain mode (no `chat_template_kwargs`), the system message is no longer prepended to the user turn — this prevented the model from echoing back translation instructions as output text instead of translating the content.
- Improvement: in TranslateGemma kwargs mode, an optional style guidance hint derived from the `additional_prompt` / `prompt_addon` is now appended after the native "Please translate…" sentence (`Style guidance: …`). Effectiveness is limited by design — TranslateGemma is not an instruction-following model.
- Editor: the **Additional instructions** field in the AI Translate sidebar panel and the selected-text translation modal now shows a warning when the active model slug contains `translategemma`, explaining that style guidelines are not reliably supported and suggesting Instruct-LLMs (e.g. Gemma 3 IT, Qwen2.5 Instruct) as alternatives.

## [1.3.1]
- Security: sanitized AI-generated content before saving to the database — post title is now passed through `sanitize_text_field()`, post content and excerpt through `wp_kses_post()`, and translated meta values through `sanitize_text_field()` in `PolylangAdapter`. Prevents stored XSS if an AI provider returns malicious markup.
- Security: capped `additional_prompt` to 2000 characters across all three entry points (`translate-text`, `translate-content`, and the user-preference endpoint) to reduce prompt injection attack surface.
- Build: downgraded PHPUnit requirement from `^11.0` to `^10.5` so the test suite runs on PHP 8.1 (the minimum supported version); updated `composer.lock` accordingly.
- Build: rebuilt `slytranslate-de_DE.mo` from the current `slytranslate-de_DE.po` to bring the tracked binary translation file back in sync.

## [1.3.0]
- Security hardening: sanitized `additional_prompt` inputs, blocked internal WordPress meta keys from being copied during translation, validated direct API URLs to http(s) only, capped `context_window_tokens`, and hardened Polylang language mapping.
- Bug fixes: synchronized version metadata, aligned the `auto_translate_new` default, and cleaned up translation content handling.
- Performance: cached AI model discovery in the editor bootstrap, reused the direct API URL from runtime context, and reduced direct API probe blocking during configuration saves.
- Refactoring: removed unused parsed-block translators, deduplicated meta-key normalization, extracted model override and translation-meta helpers, and simplified bulk/content execution flow.
- Architecture: split editor bootstrap and configuration persistence into dedicated module classes and moved ability registration behind a dedicated registrar.

## [1.2.0]
- **Direct API support**: new `direct_api_url` setting on `ai-translate/configure` — connect directly to any OpenAI-compatible endpoint (llama.cpp, vLLM, Ollama, LM Studio, LocalAI) without the WordPress AI Client in the request path; automatic fallback to the standard AI Client when the direct call fails
- **TranslateGemma `chat_template_kwargs` auto-detection**: when a Direct API URL is configured, the plugin probes the server for `chat_template_kwargs` support (sends "cat" en → de, checks for "Katze"); when detected, every translation request includes `source_lang_code` / `target_lang_code` for models like TranslateGemma that use Jinja-based language routing
- **Per-request `model_slug`**: the `translate-text`, `translate-content`, and `translate-content-bulk` abilities now accept an optional `model_slug` field to override the site-wide default on a per-call basis
- **Block editor model selector**: new model dropdown in the AI Translate sidebar panel and the selected-text translation modal; lists all models registered with the WordPress AI Client, persists the selection in `localStorage`, and shows the effective default as "Auto" label
- Added TranslateGemma to the known model context-window table (8 192 tokens)
- Added newer models to context-window detection: o4-mini, o3, gpt-4.5, gpt-4.1, Sonar, Grok
- Slim SEO: translate only the `title` and `description` fields inside the serialised `slim_seo` meta value instead of overwriting the full array
- `ai-translate/configure` output now includes effective runtime values: `effective_meta_keys_translate`, `effective_meta_keys_clear`, `effective_context_window_tokens`, `effective_chunk_chars`, `learned_context_window_tokens`, `direct_api_kwargs_supported`

## [1.1.1]
- Block editor: added **Cancel translation** button that appears while a translation is in progress, allowing the request to be aborted without leaving the editor
- Added `model_slug` setting to `ai-translate/configure` ability — explicitly select which model the AI connector should use for translations (e.g. `gemma3:27b`); leave empty to use the connector default
- Removed dependency on the third-party AI Services plugin for model detection; model selection is now handled entirely via the `model_slug` setting and the WordPress AI Client

## [1.1.0]
- Added site-wide `prompt_addon` field to `ai-translate/configure` ability — a global addition always appended after the prompt template
- Added optional `additional_prompt` field to `ai-translate/translate-text` and `ai-translate/translate-content` abilities for per-request style instructions
- Block editor: new **Additional instructions** textarea in the AI Translate panel (Sidebar) and the selected-text modal (Toolbar), pre-filled with the last used value per user
- Last used additional prompt is stored in user meta and passed as bootstrap data to the editor, so it persists across posts and page reloads
- New REST endpoint `ai-translate/user-preference` for saving the user-specific additional prompt

## [1.0.1]
- Fixed block editor sidebar translations ignoring the active connector model selection by passing the detected runtime model as AI Client preference

## [1.0.0]
- Complete rewrite for WordPress 7.0 Abilities API, based on AI Translate For Polylang by jamesdlow (https://de.wordpress.org/plugins/ai-translate-for-polylang/)
- LLM integration via WordPress AI Client (no more direct API key management)
- 7 abilities registered: get-languages, get-translation-status, get-untranslated, translate-text, translate-content, translate-content-bulk, configure
- Adapter architecture: TranslationPluginAdapter interface with PolylangAdapter implementation
- Bulk translation support for posts, pages, and custom post types (max 50 items)
- Automatic SEO plugin integration for common title and description meta fields
- Block editor AI Translate panel for in-editor translation workflows
- Bundled German translation for ability labels and editor UI
- All abilities exposed via REST API (show_in_rest)
- Settings menu removed — configuration via ai-translate/configure ability
- Requires WordPress 7.0+, PHP 8.1+
