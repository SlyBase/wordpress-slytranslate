---
applyTo: '**'
description: 'Blueprint for SlyTranslate multi-post language-plugin adapters, with Polylang as the concrete profile and smoke-test requirements.'
---

# Multi-Post Language Plugin Adapter Blueprint: Polylang

## 1. How to Reuse This File

Use this file as a template for other multi-post language-plugin adapter instructions.

- Keep the section layout.
- Replace the plugin profile in section 2.
- Replace the storage model, upstream signals, mutation rules, and query behavior in sections 3 to 7.
- Keep the SlyTranslate integration section grounded in the current codebase instead of describing a future plan.
- Keep plugin-agnostic rules in the main sections and isolate Polylang-specific details inside the profile bullets and examples.

## 2. Plugin Profile: Polylang

- Plugin type: multi-post multilingual plugin.
- Upstream source: https://plugins.svn.wordpress.org/polylang/trunk/
- Relevant upstream developer docs:
  - https://polylang.pro/documentation/support/developers/php-constants/
  - https://polylang.pro/documentation/support/developers/how-to-make-a-custom-gutenberg-block-multilingual/
  - https://polylang.pro/documentation/support/developers/rest-api/
  - https://polylang.pro/documentation/support/developers/languages-rest-api/
  - https://polylang.pro/documentation/support/developers/settings-rest-api/
  - https://polylang.pro/documentation/support/developers/function-reference/
- Upstream version family checked for these rules: Polylang trunk and developer docs as of 2026-05.
- Storage model: each language variant is a separate WordPress post linked through a Polylang translation map.
- Inline syntax: none. Polylang does not store multiple language variants inline in `post_title`, `post_content`, or `post_excerpt`.
- Post-language source of truth: `pll_get_post_language( $post_id )`.
- Language list source of truth: `pll_languages_list()`.
- Translation map source of truth: `pll_get_post_translations( $post_id )`.
- Translation lookup source of truth: `pll_get_post( $post_id, $target_lang )`.
- Translation relink source of truth: `pll_save_post_translations()`.
- Current editor model in SlyTranslate: generic WordPress editor, REST, and list-table flows. There is no Polylang-specific visual-editor integration file in the current codebase.
- Post-language mutation support in SlyTranslate: implemented.
- Translation linking model: sibling post relation, because each translation is stored as its own post ID.

## 3. Storage Topology and Data Model

For Polylang, treat the plugin as a multi-post adapter.

- `post_title`, `post_content`, and `post_excerpt` contain exactly one language variant per post.
- A translation creates or updates a sibling post instead of mutating the source post in place.
- Existing target-language presence is determined from Polylang post relations, not from inline markup or string tables.
- In the current SlyTranslate code, `create_translation()` first checks `pll_get_post( $source_post_id, $target_lang )` and only inserts a new post when no target-language sibling exists.
- The translated item must resolve to a different post ID than the source post.

Meta handling must stay conservative.

- SlyTranslate only persists meta values explicitly provided to the adapter.
- String meta values are passed through `sanitize_text_field()` before saving.
- Non-string meta values are stored as provided.
- Do not assume Polylang automatically translates arbitrary meta keys for SlyTranslate. The adapter currently performs explicit copy behavior, not schema-driven multilingual meta resolution.

Taxonomy handling is translation-aware but fallback-friendly.

- SlyTranslate copies taxonomy terms from the source post onto the translated post.
- For translated taxonomies, it prefers `pll_get_term( $term_id, $target_lang )`.
- For non-translated taxonomies, it reuses the original term IDs when `pll_is_translated_taxonomy()` reports false.
- Language and translation bookkeeping taxonomies must not be copied manually.

Post-type support must remain Polylang-aware.

- `TranslationQueryService::validate_translatable_post_type()` rejects post types that are not enabled in Polylang when `pll_is_translated_post_type()` is available.
- Bulk and status queries must continue to respect Polylang's translated-post-type registry instead of assuming every public post type is translatable.

## 4. Upstream Detection, Config, and REST Signals

Detection should remain simple and compatible with Polylang's function-based public API.

```php
function_exists( 'pll_languages_list' )
```

Preferred upstream signals for Polylang are:

- `pll_languages_list()`
- `pll_get_post_language()`
- `pll_get_post_translations()`
- `pll_get_post()`
- `pll_set_post_language()`
- `pll_save_post_translations()`
- `pll_get_term()`
- `pll_is_translated_post_type()`
- `pll_is_translated_taxonomy()`

Important upstream guidance from the function reference:

- Check Polylang function availability with `function_exists()` before calling them.
- Use `pll_languages_list()` for backend language discovery rather than `pll_the_languages()`, which is intended for front-end switchers.
- Use `pll_get_post_language()` and `pll_get_post_translations()` as the canonical post-language and translation-group APIs.
- Use `pll_set_post_language()` and `pll_save_post_translations()` for explicit language and relation mutations.

Important upstream REST behavior:

- Polylang extends WordPress REST endpoints with a `lang` argument for posts and terms.
- Post and term responses can include `lang` and `translations` fields.
- Upstream supports assigning language and translation relations through REST request parameters.
- Upstream also ships `/pll/v1/languages` and `/pll/v1/settings` endpoints for language and settings management.

Current SlyTranslate status:

- The current adapter uses the PHP function API only.
- The current SlyTranslate codebase does not call Polylang REST endpoints directly.
- The current SlyTranslate codebase does not read Polylang PHP constants directly.
- Upstream Gutenberg block translation via `wpml-config.xml` is a separate Polylang Pro integration surface and is not wired into the current adapter logic.

## 5. SlyTranslate Adapter Contract

`PolylangAdapter` must implement `TranslationPluginAdapter` and `TranslationMutationAdapter` with multi-post semantics.

- `is_available()`: return true when Polylang language-list functions exist.
- `get_languages()`: return configured language slugs mapped to human-readable names.
- `get_post_language()`: return the language assigned to the specific post.
- `get_post_translations()`: return the sibling-post translation map keyed by language code.
- `supports_mutation_capability()`: report support for `set_post_language` and `relink_translation` only when the corresponding Polylang functions exist.
- `set_post_language()`: update the post language and tolerate eventual consistency when Polylang reports failure but the language actually changed.
- `relink_post_translations()`: normalize the translation map and rewrite translation relations through Polylang.
- `create_translation()`: create or update a sibling translation post, copy relevant content and metadata, and ensure the Polylang relation is linked.
- `link_translation()`: save the two-post translation relation through `pll_save_post_translations()`.

Current source-language resolution must match the existing Polylang code path.

1. Read the language assigned to the source post with `pll_get_post_language( $post_id )`.
2. Use that value as the canonical source language for the translation operation.

Do not invent WPGlobus-style editor-language detection, TranslatePress-style default-language inference, or request-level source overrides for Polylang. In the current code, Polylang source language is whatever the source post is already assigned to.

Also note the current `PostTranslationService` behavior.

- The optional caller-provided `source_language` override is currently honored for `WpMultilangAdapter` and `WpglobusAdapter`, but not for `PolylangAdapter`.
- Polylang translations are therefore always derived from the source post selected by ID, not from an alternate language segment inside that post.

### 5.1 Ability Call Hints

- Use `ai-translate/get-languages` when the target language code is unknown. Otherwise call `ai-translate/get-translation-status` before `ai-translate/translate-content` so the agent can inspect `source_language`, `single_entry_mode`, and existing target-language presence.
- Expect `single_entry_mode` to be `false`, and expect `translated_post_id` to differ from `source_post_id` because Polylang stores target languages in sibling posts.
- Omit `source_language`; Polylang uses the language already assigned to the source post and does not let the ability pick a different inline source variant.
- Use `overwrite=true` only when updating an existing sibling translation. Use `ai-translate/set-post-language` only for language mutation and relation repair, not for creating translated content.

## 6. Current SlyTranslate Integration Surface

These integration points already exist in the current codebase and must be documented as current state, not as future work.

- `slytranslate/inc/PolylangAdapter.php`: concrete adapter implementation already exists.
- `slytranslate/slytranslate.php`: `AI_Translate::get_adapter()` already includes `new PolylangAdapter()` in the candidate list.
- `slytranslate/slytranslate.php`: Polylang is not treated as single-entry mode in `AI_Translate::is_single_entry_translation_mode()`.
- `slytranslate/inc/PostTranslationService.php`: post translation already delegates persistence to `PolylangAdapter::create_translation()` and uses the source post's assigned language.
- `slytranslate/inc/TranslationQueryService.php`: status, untranslated-item lookup, post-type validation, and bulk-source queries already contain Polylang-specific handling.
- `slytranslate/inc/TranslationQueryService.php`: `query_post_ids_by_type()` already sets `lang` to an empty string for Polylang to bypass the current-language filter.
- `slytranslate/inc/LanguageMutationService.php`: set-post-language and optional relink flows already work through `TranslationMutationAdapter` when Polylang exposes the required functions.
- `slytranslate/inc/AbilityRegistrar.php`: the `ai-translate/set-post-language` ability already describes Polylang as a concrete supported mutation backend.
- `slytranslate/inc/EditorBootstrap.php`: editor bootstrap data already surfaces translation-plugin languages from the active adapter.
- `slytranslate/inc/ListTableTranslation.php`: list-table actions already treat Polylang as classic multi-post mode rather than single-entry mode.
- `slytranslate/slytranslate.php`: editor REST route registration already omits the set-post-language route when the active adapter cannot mutate language.

When updating this instruction file, describe only what the code currently does. Do not present Polylang REST endpoints, Gutenberg export/XLIFF support, or settings APIs as already integrated unless SlyTranslate has actually been wired to them.

## 7. Creation, Linking, and Query Rules

### 7.1 Resolve Existing Translations

Use Polylang's translation relation APIs rather than trying to infer relations from content.

- `pll_get_post_translations( $post_id )` is the canonical group map.
- `pll_get_post( $source_post_id, $target_lang )` is the canonical one-language lookup for create-or-update behavior.
- `get_post_translations()` should continue to return post IDs keyed by language slug.
- If the target translation exists and `overwrite` is false, `create_translation()` must return `translation_exists`.

### 7.2 Create or Update the Target Post

Current adapter persistence follows this shape.

- Load the source post and source language.
- Look up an existing sibling translation for the requested target language.
- If no target exists, insert a new draft sibling post with the same post type, author, and source timestamps.
- Assign the target language to the new sibling post.
- Update the translated post's title, content, excerpt, and status.
- Copy explicitly provided meta.
- Copy taxonomy terms, preferring translated term IDs where available.
- Link the source and target posts through Polylang.

Important current behavior:

- New translated posts are created with a temporary title based on the source title plus `({$target_lang})` until the translated title is written.
- The adapter uses a temporary `wp_insert_post_data` filter to preserve the original author during insert.
- `post_content` is intentionally not pre-filtered through `wp_kses_post()` inside the adapter because WordPress core already applies capability-aware content sanitization during `wp_update_post()`.
- When an existing translation is overwritten, the adapter updates that existing target post instead of creating a second sibling.

### 7.3 Mutate Post Language and Relink Groups

Current mutation behavior must remain optimistic but verifiable.

- `set_post_language()` short-circuits as a no-op when the post already has the target language.
- If `pll_set_post_language()` returns false, the adapter performs a local confirmation check before treating the mutation as failed.
- The confirmation check retries language resolution and also checks whether `pll_get_post( $post_id, $target_language )` resolves back to the same post.
- `relink_post_translations()` normalizes language keys and post IDs before calling `pll_save_post_translations()`.
- Invalid or non-existent posts must be filtered out before rewriting a relation map.

This behavior matters because Polylang can report failure while the post language or relation map still converges to the requested state shortly afterwards.

### 7.4 Query and REST Interop Rules

Current query behavior for Polylang relies on query-level language bypass rather than manual post-by-post language switching.

- `TranslationQueryService::query_post_ids_by_type()` sets `lang` to an empty string so source-post enumeration is not restricted to the current Polylang language context.
- `TranslationQueryService::validate_translatable_post_type()` must continue to gate on `pll_is_translated_post_type()` when available.
- SlyTranslate's current adapter does not consume Polylang REST responses directly, but upstream REST semantics should remain aligned with the same sibling-post model.

Do not replace the current PHP-function integration with REST-only logic unless the codebase has actually been migrated end-to-end.

## 8. Testing Requirements

Unit coverage already exists and should be extended alongside any behavior change.

- `slytranslate/tests/Unit/PolylangAdapterTest.php` covers no-op language mutation, eventual-consistency acceptance when Polylang returns false, hard failure when the language did not change, and relation-save failure behavior.
- `slytranslate/tests/Unit/LanguageMutationServiceTest.php` covers the public set-post-language flow, conflict handling, relink behavior, permissions, and error mapping.
- `slytranslate/tests/Unit/AbilityRegistrationTest.php` and `slytranslate/tests/Unit/EditorRestRouteRegistrationTest.php` cover conditional exposure of the set-post-language ability and REST route.
- `slytranslate/tests/Unit/AbilityInputValidationTest.php` covers input validation for set-post-language execution.

Current coverage is strongest around language mutation and relation rewrites.

- If `create_translation()`, overwrite behavior, taxonomy copying, or meta copying changes, add focused adapter tests because the current suite is thinner on those paths.
- If query behavior changes, add or update focused tests around `TranslationQueryService`.

Recommended narrow validation commands:

```bash
cd slytranslate && ./vendor/bin/phpunit --filter 'PolylangAdapterTest|LanguageMutationServiceTest'
```

For ability-registration or REST-route changes, widen the filter to include:

```bash
cd slytranslate && ./vendor/bin/phpunit --filter 'PolylangAdapterTest|LanguageMutationServiceTest|AbilityRegistrationTest|EditorRestRouteRegistrationTest|AbilityInputValidationTest'
```

## 9. Deployment and Smoke Test Requirements

For Polylang work, a deploy and smoke test remain mandatory before reporting completion.

- Build and verify the plugin ZIP.
- Deploy to the current WordPress environment that is actually running Polylang for this workspace.
- The current repository does not codify a dedicated Polylang chart or a fixed Polylang smoke-test post ID in the same way as the WPGlobus and TranslatePress instructions, so confirm the active target from the workspace tasks or environment before treating deployment as complete.
- After deploy, run a manual MCP smoke test against a real Polylang content item.
- If the change affects translation creation, execute `ai-translate/translate-content` and verify that the target language resolves to a sibling post ID.
- If the change affects language mutation or relation rewrites, also execute `ai-translate/set-post-language` with `relink` only when the test is intended to rewrite the translation group.

The smoke test must verify Polylang-specific persistence.

- The translated content is stored as a separate post ID, not inline in the source post.
- The source post keeps its original language assignment.
- The target language appears in the translation relation map.
- Overwrite paths update the existing sibling translation instead of creating duplicates.
- Taxonomy or meta changes touched by the task remain intact on the translated sibling post.

If this repository later standardizes a dedicated Polylang pod, MCP fixture post, or model requirement, update this section to reflect that exact workflow instead of leaving it generic.

## 10. Adaptation Checklist for Another Language Plugin

When copying this file for another plugin, replace these plugin-specific facts.

- Translation topology: multi-post, single-entry inline-markup, or single-entry string-table.
- Storage model for content, relations, and language assignment.
- Detection hooks, functions, classes, and mutation APIs.
- Language-list and translation-map sources of truth.
- Source-language resolution rules.
- Create-translation, overwrite, and relink behavior.
- Query-filter bypass rules and post-type validation.
- Whether REST APIs are authoritative, optional, or unused.
- Which SlyTranslate services expose adapter-specific behavior.
- Which unit tests and smoke tests are required.

If the new plugin does not use separate translated posts, section 7 should be rewritten entirely instead of lightly edited.