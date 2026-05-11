---
applyTo: '**'
description: 'Blueprint for SlyTranslate single-entry language-plugin adapters, with WP Multilang as the concrete profile and smoke-test requirements.'
---

# Single-Entry Language Plugin Adapter Blueprint: WP Multilang

## 1. How to Reuse This File

Use this file as a template for other single-entry language-plugin adapter instructions.

- Keep the section layout.
- Replace the plugin profile in section 2.
- Replace the storage syntax, detection signals, and source-language resolution rules in sections 3 to 7.
- Keep the SlyTranslate integration section grounded in the current codebase instead of describing a future plan.
- Keep plugin-agnostic rules in the main sections and isolate plugin-specific details inside the profile bullets and examples.

## 2. Plugin Profile: WP Multilang

- Plugin type: single-entry multilingual plugin.
- Upstream source: https://github.com/ahmedkaludi/wp-multilang
- Relevant upstream developer docs:
  - https://wp-multilang.com/docs/
  - https://wp-multilang.com/docs/knowledge-base/how-to-use-rest-api-requests-to-specify-language-preferences-in-wp-multilang/
- Upstream version family checked for these rules: WP Multilang 2.4.27 and public docs as of 2026-05.
- Storage model: multiple language variants are stored inline inside one WordPress post record.
- Inline syntax: `[:en]Hello[:de]Hallo[:]`.
- Default-language source of truth: `wpm_get_default_language()`.
- Language list source of truth: `wpm_get_languages()`.
- Current language source of truth: `wpm_get_language()`.
- Post-type configuration source of truth: `wpm_get_post_config( $post_type )`.
- Encode/decode helpers: `wpm_string_to_ml_array()` and `wpm_ml_array_to_string()`.
- Current editor model in SlyTranslate: generic WordPress editor and list-table flows. There is no WP Multilang-specific editor integration file in the current codebase.
- Post-language mutation support in SlyTranslate: not implemented for WP Multilang.
- Translation linking model: no cross-post relation, because translations live in the same post.

## 3. Storage Topology and Data Model

For WP Multilang, treat the plugin as a single-entry adapter.

- `post_title`, `post_content`, and `post_excerpt` may contain inline language markup.
- A translation does not create a sibling post. It updates the same post ID.
- Plain unmarked values must be interpreted as default-language-only content.
- Existing target-language presence is determined from the inline content variant, not from an external translation table.
- In the current SlyTranslate code, `get_post_translations()` considers a language translated only when the extracted `post_content` variant is non-empty after trimming.

Meta handling must stay conservative.

- Do not assume every meta key is multilingual.
- In SlyTranslate, only meta values explicitly passed into the adapter are persisted.
- String meta values are merged back into WP Multilang inline syntax.
- Non-string meta values are stored as provided.

Current adapter persistence also maintains post-language visibility metadata.

- After a successful translation, SlyTranslate updates the `_languages` post meta to include the source and target language codes.
- Existing `_languages` entries must be preserved unless the task explicitly changes visibility behavior.

Post-type support must remain WP Multilang-aware.

- `TranslationQueryService::validate_translatable_post_type()` rejects post types whose WP Multilang config resolves to `null` through `wpm_get_post_config()`.
- Bulk and status queries must continue to respect WP Multilang's post-type configuration instead of assuming every post type is translatable.
- `TranslationQueryService::query_post_ids_by_type()` sets `lang` to `all` for WP Multilang so source-post enumeration is not restricted to the current language view.

## 4. Upstream Detection, Config, and REST Signals

Detection should remain strict and consistent with the current adapter.

```php
defined( 'WPM_PLUGIN_FILE' )
	&& function_exists( 'wpm_get_languages' )
	&& function_exists( 'wpm_get_default_language' )
	&& function_exists( 'wpm_string_to_ml_array' )
	&& function_exists( 'wpm_ml_array_to_string' )
```

Preferred upstream signals for WP Multilang are:

- `wpm_get_languages()`
- `wpm_get_default_language()`
- `wpm_get_language()`
- `wpm_get_post_config()`
- `wpm_string_to_ml_array()`
- `wpm_ml_array_to_string()`
- `wpm_translate_string()`
- `wpm_translate_value()`
- `wpm_translate_url()`

Important upstream behavior from the public docs and source:

- WP Multilang uses inline syntax like `[:en]...[:de]...[:]` and can also translate multidimensional arrays of configured values.
- `wpm_get_languages()` returns enabled languages keyed by ISO-style codes with metadata such as `name`, `locale`, `translation`, and `flag`.
- `wpm_get_language()` is the upstream resolver for the current language and handles admin, AJAX, REST, URL, and user-language context internally.
- Admin editing flows use upstream request context such as `edit_lang`, while REST and public requests can use the `lang` query parameter.
- Upstream adds `lang` as a public query var and documents REST requests such as `/wp-json/wp/v2/posts/?lang=it` for language-specific responses.
- Translation scope is configured upstream through `wpm-config.json` and `wpm_*_config` filters rather than through a per-post relation table.

Current SlyTranslate status:

- The current adapter uses the PHP function API only.
- The current SlyTranslate codebase does not consume WP Multilang REST responses directly.
- The current SlyTranslate codebase does not write `wpm-config.json`; it only respects upstream configuration through functions like `wpm_get_post_config()`.
- There is no dedicated WP Multilang visual-editor integration file in the current codebase.

## 5. SlyTranslate Adapter Contract

`WpMultilangAdapter` must implement the normal `TranslationPluginAdapter` contract with single-entry semantics.

- `is_available()`: return true only when the WP Multilang plugin constant and required helper functions exist.
- `get_languages()`: return configured language codes mapped to human-readable names.
- `get_post_language()`: return the currently active source language from WP Multilang, falling back to the configured default language.
- `get_language_variant()`: extract one language segment from inline markup or return the plain default-language value.
- `get_post_translations()`: report which languages already have non-empty inline content in the current post.
- `create_translation()`: merge translated fields back into the same post record and update `_languages` visibility meta.
- `link_translation()`: no-op returning true.

Current source-language resolution must match the existing adapter behavior.

1. Call `wpm_get_language()` when available and accept the result only if it matches an enabled language code.
2. If that does not yield an enabled language, fall back to `wpm_get_default_language()`.

Do not reimplement WP Multilang's own request parsing inside SlyTranslate. The current adapter intentionally delegates current-language resolution to `wpm_get_language()` and only applies a local default-language fallback.

Also note the current `PostTranslationService` and bulk-translation behavior.

- The optional caller-provided `source_language` override is currently honored for `WpMultilangAdapter` and `WpglobusAdapter`.
- `PostTranslationService::translate_post()` extracts title, content, and excerpt from the selected source language before translation.
- `AI_Translate::execute_translate_posts()` also honors a requested `source_language` only for WP Multilang and WPGlobus.

### 5.1 Ability Call Hints

- Use `ai-translate/get-languages` when the target language code is unknown. Otherwise call `ai-translate/get-translation-status` before `ai-translate/translate-content` so the agent can inspect `source_language`, `single_entry_mode`, and existing target-language presence.
- Expect `single_entry_mode` to be `true`, and expect `translated_post_id` to potentially equal `source_post_id` because WP Multilang stores all language variants in the same post.
- In status responses, rely on `exists` rather than a sibling-post `post_id` to detect whether a target variant is already present.
- Omit `source_language` unless you intentionally pin a specific WP Multilang source variant. When pinning, reuse `get-translation-status.source_language` or another confirmed enabled language code.
- Use `overwrite=true` only when replacing an existing inline target-language variant. Without overwrite, existing target-language content should short-circuit instead of being rewritten.

## 6. Current SlyTranslate Integration Surface

These integration points already exist in the current codebase and must be documented as current state, not as future work.

- `slytranslate/inc/WpMultilangAdapter.php`: concrete adapter implementation already exists.
- `slytranslate/slytranslate.php`: `AI_Translate::get_adapter()` already includes `new WpMultilangAdapter()` in the candidate list.
- `slytranslate/slytranslate.php`: `AI_Translate::is_single_entry_translation_mode()` already treats `WpMultilangAdapter` as single-entry.
- `slytranslate/inc/PostTranslationService.php`: post translation already extracts title, content, and excerpt from the selected WP Multilang source language before translation.
- `slytranslate/slytranslate.php`: bulk translation already honors the optional `source_language` override for WP Multilang.
- `slytranslate/inc/TranslationQueryService.php`: status handling, untranslated-item lookup, post-type validation, and bulk-source queries already branch for `WpMultilangAdapter` as single-entry mode.
- `slytranslate/inc/TranslationQueryService.php`: `query_post_ids_by_type()` already sets `lang` to `all` for WP Multilang.
- `slytranslate/inc/TranslationQueryService.php`: `validate_translatable_post_type()` already gates on `wpm_get_post_config()` when available.
- `slytranslate/inc/EditorBootstrap.php`: bootstrap data already exposes `singleEntryTranslationMode` and translation-plugin languages for the active adapter.
- `slytranslate/inc/ListTableTranslation.php`: list-table actions already treat WP Multilang like other single-entry adapters, including overwrite-aware existing-language handling and source-language selection in the picker.
- `slytranslate/inc/LanguageMutationService.php`: set-post-language is not available for WP Multilang because the adapter does not implement `TranslationMutationAdapter`.

When updating this instruction file, describe only what the code currently does. Do not imply sibling posts, relation maps, dedicated WP Multilang sidebar integrations, or post-language mutation support unless those behaviors are actually added.

## 7. Parsing and Merge Rules

### 7.1 Inline Syntax and Decode Path

WP Multilang stores variants inline with tags like `[:en]Text[:de]Text[:]`.

- The current adapter does not use its own bespoke regex parser. It delegates decoding to upstream `wpm_string_to_ml_array()`.
- Upstream multilingual-string detection uses patterns like `#\[:[a-z-]+\]#im`; keep this distinct from WPGlobus syntax.
- `wpm_string_to_ml_array()` pre-fills the enabled-language map, treats untagged content as default-language content, and trims returned segment values.
- Plain unmarked values therefore remain default-language-only in SlyTranslate.

Do not replace upstream decode behavior with custom parsing unless the storage contract deliberately changes.

### 7.2 Extract One Language Variant

Behavior must match the current adapter.

- Empty language code returns an empty string.
- Empty value returns an empty string.
- If `wpm_string_to_ml_array()` returns an array, return the requested language segment when present.
- If the decoder does not return an array, return the original content only for the default language.
- If markup is present but the requested language segment is missing, return an empty string.

Important current behavior:

- Unlike upstream `wpm_translate_string()`, the current adapter does not fall back to default-language text for a missing non-default variant.
- For translation existence checks, an empty extracted variant means "not translated yet" even when the default-language content exists.

### 7.3 Merge a Target Translation Back into the Value

The current adapter follows this shape.

```php
$decoded = wpm_string_to_ml_array( $existing_value );
$map     = is_array( $decoded ) ? $this->normalize_language_map( $decoded ) : array();

if ( '' !== $source_language && ! array_key_exists( $source_language, $map ) ) {
	$map[ $source_language ] = $source_fallback;
}

$map[ $target_language ] = $target_value;

return $this->encode_language_map( $map );
```

Required behavior:

- If the target language is empty, return the original value unchanged.
- If the existing value is plain text, wrap the source fallback in the source language tag when available, then add the target tag.
- If the existing value already contains WP Multilang markup and the target segment exists, replace only that segment.
- If the target segment does not exist, append it through the encoded language map.
- If the source segment is unexpectedly missing and a source fallback is available, add the source segment before saving the target.
- Prefer upstream `wpm_ml_array_to_string()` for encoding.
- If upstream encoding returns an empty string, fall back to manual `[:lang]value` concatenation plus the closing `[:]` marker.

Current sanitization behavior in SlyTranslate is asymmetric by field and should be preserved unless intentionally changed.

- `post_title` and string meta values are passed through `sanitize_text_field()` before merging.
- `post_content` and `post_excerpt` are merged as provided by the translation flow.

### 7.4 Post-Language Visibility Meta

Current adapter persistence also updates `_languages`.

- Existing `_languages` values are normalized to sanitized language codes.
- The source and target language codes are appended and deduplicated.
- Non-string meta values remain passthrough values and are not converted into multilingual markup.

If a task changes this behavior, add focused tests because the visibility meta is part of the persisted WP Multilang state, not just a UI hint.

## 8. Testing Requirements

Unit coverage now exists and should be extended alongside any behavior change.

- `slytranslate/tests/Unit/WpMultilangAdapterTest.php` covers availability gating, language-map resolution, current-language fallback, inline extraction, translation existence detection, merge semantics, and the core `create_translation()` persistence path including `_languages` updates.
- If adapter behavior changes, extend that test file with focused coverage for any new persistence, overwrite, or meta-handling branches.
- If query behavior changes, update `slytranslate/tests/Unit/TranslationQueryServiceTest.php`.
- If list-table behavior changes, update `slytranslate/tests/Unit/ListTableTranslationTest.php`.
- If ability exposure or REST-route behavior changes, update `slytranslate/tests/Unit/AbilityRegistrationTest.php`, `slytranslate/tests/Unit/EditorRestRouteRegistrationTest.php`, and `slytranslate/tests/Unit/AbilityInputValidationTest.php` as needed.

Recommended narrow validation command:

```bash
cd slytranslate && ./vendor/bin/phpunit --filter WpMultilangAdapterTest
```

For ability or route changes, widen the filter to include:

```bash
cd slytranslate && ./vendor/bin/phpunit --filter 'WpMultilangAdapterTest|TranslationQueryServiceTest|ListTableTranslationTest|AbilityRegistrationTest|EditorRestRouteRegistrationTest|AbilityInputValidationTest'
```

## 9. Deployment and Smoke Test Requirements

For WP Multilang work, a deploy and translation smoke test remain mandatory before reporting completion.

- Build and verify the plugin ZIP.
- Deploy to the WordPress environment that is actually running WP Multilang for this workspace.
- The current workspace does not expose a dedicated WP Multilang build-and-deploy VS Code task in the same way as the TranslatePress and WPGlobus environments, so confirm the active deployment target from the workspace environment before treating deployment as complete.
- After deploy, run a test translation for post `5` through the WordPress MCP tools.
- MCP environment: `wordpress-wpmultilang`.
- Translation direction: `de` -> `en`.
- If needed, use the WP Multilang MCP ability-discovery tool first to confirm the translation ability name.
- Execute the translation through the WP Multilang MCP ability execution tool.
- Use the model `Ministral-3-3B-Instruct-2512-Q4_K_M`.
- Use `overwrite=true` so the smoke test exercises the current inline-merge persistence path instead of short-circuiting on an existing translation.
- Do not report the WP Multilang task as complete until this smoke test succeeds.

The smoke test must verify WP Multilang-specific persistence.

- The translated item remains the same post ID.
- The translated content is stored in WP Multilang inline format.
- The requested target language segment is present after translation.
- The source-language content remains intact.
- The `_languages` post meta still contains the relevant source and target language codes.
- Overwrite paths update the existing inline target-language segment instead of creating duplicate posts or duplicate relation records.

## 10. Adaptation Checklist for Another Language Plugin

When copying this file for another plugin, replace these plugin-specific facts.

- Translation topology: single-entry, multi-post, or string-table.
- Markup syntax or storage schema.
- Detection hooks, public helper functions, and configuration surfaces.
- Language-list and default-language sources.
- Current-language resolution behavior.
- Meta-field multilingual contract.
- Whether `TranslationMutationAdapter` support exists.
- Which SlyTranslate services treat the adapter as single-entry.
- Which unit tests and smoke tests are required.

If the new plugin does not use inline markup in the same post record, section 7 should be rewritten entirely instead of lightly edited.