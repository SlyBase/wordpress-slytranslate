---
applyTo: '**'
description: 'Blueprint for SlyTranslate single-entry string-table adapters, with TranslatePress as the concrete profile and smoke-test requirements.'
---

# Single-Entry String-Table Adapter Blueprint: TranslatePress

## 1. How to Reuse This File

Use this file as a template for other string-table-based language-plugin adapter instructions.

- Keep the section layout.
- Replace the plugin profile in section 2.
- Replace the storage schema, upstream signals, URL rules, and source-language rules in sections 3 to 7.
- Keep the SlyTranslate integration section grounded in the current codebase instead of describing a future plan.
- Keep plugin-agnostic rules in the main sections and isolate TranslatePress-specific details inside the profile bullets and examples.

## 2. Plugin Profile: TranslatePress

- Plugin type: single-entry multilingual plugin with string-table storage.
- Upstream source: https://plugins.svn.wordpress.org/translatepress-multilingual/
- Relevant upstream developer docs:
  - https://translatepress.com/docs/developers/translating-an-internal-url/
  - https://translatepress.com/docs/developers/extracting-translated-urls/
  - https://translatepress.com/docs/developers/get-the-translated-url-for-a-particular-language/
- Upstream version family checked for these rules: TranslatePress Multilingual trunk and developer docs as of 2026-05.
- Storage model: source content stays in the original WordPress post record, while target-language strings are stored in TranslatePress dictionary tables.
- Inline syntax: none. TranslatePress does not store inline language markup in `post_title`, `post_content`, or `post_excerpt`.
- Default-language source of truth: `get_option( 'trp_settings' )['default-language']`.
- Configured language list source of truth: `get_option( 'trp_settings' )['translation-languages']`.
- Published URL language list source of truth: TranslatePress settings component `get_settings()['publish-languages']`.
- URL conversion source of truth: `TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' )`.
- Current editor model in SlyTranslate: TranslatePress visual editor integration translates the currently visible string field instead of treating the visual editor as a full-post translation screen.
- Post-language mutation support in SlyTranslate: not implemented for TranslatePress.
- Translation linking model: no cross-post relation, because target translations resolve back to the source post ID in single-entry mode.

## 3. Storage Topology and Data Model

For TranslatePress, treat the plugin as a single-entry adapter backed by string tables.

- `post_title`, `post_content`, and `post_excerpt` remain the source-language values in `wp_posts`.
- A translation does not create a sibling post. It updates TranslatePress dictionary rows and still resolves to the same source post ID.
- Plain post values must be interpreted as the default-language source, not as mixed-language content.
- Existing target-language presence is determined from TranslatePress dictionary rows, not from an external translation post table.
- In the current SlyTranslate code, `get_post_translations()` and `get_post_translation_for_language()` detect an existing translation by querying the source post title in the requested locale and requiring a non-empty translated value with `status > 0`.

Dictionary persistence is row-based.

- SlyTranslate writes original-to-translated string pairs into TranslatePress dictionary storage.
- The preferred save path resolves real original-string IDs from `wp_trp_original_strings` and updates or inserts rows in `wp_trp_dictionary_<default>_<target>`.
- If that direct-table path is unavailable, SlyTranslate falls back to `TRP_Query` methods such as `insert_strings()`, `get_string_ids()`, and `update_strings()`.
- Persisted translated rows must use approved status `2`.

Meta handling must stay conservative.

- Do not assume every meta key is represented in TranslatePress string tables.
- In SlyTranslate, only translated values that are explicitly passed into the adapter should be persisted.
- For non-string meta values, keep passthrough behavior unless the adapter contract is deliberately extended.

Post-type support must remain WordPress-native.

- TranslatePress does not maintain Polylang-style translation posts.
- Use normal WordPress post-type checks instead of assuming a TranslatePress-only post registry.

## 4. Upstream Detection, Config, and URL Signals

Detection should remain strict and simple for TranslatePress.

```php
class_exists( 'TRP_Translate_Press', false )
```

Preferred upstream signals for TranslatePress are:

- `get_option( 'trp_settings' )`
- `TRP_Translate_Press::get_trp_instance()`
- `TRP_Translate_Press::get_trp_instance()->get_component( 'query' )`
- `TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )`
- `TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' )`
- `trp_settings['default-language']`
- `trp_settings['translation-languages']`
- `settings['publish-languages']`

Important URL behavior from upstream docs:

- TranslatePress automatically converts internal links for the currently viewed language, including translated slugs.
- For explicit URL conversion, upstream recommends `get_url_for_language( $lang, $url, '' )` on the `url_converter` component.
- Upstream examples keep the third argument as an empty string and iterate over `publish-languages` when a full translated-URL set is needed.
- Do not build translated URLs by concatenating language directories manually. TranslatePress can also alter translated slugs, not just the language prefix.

Current SlyTranslate status:

- The current adapter uses TranslatePress locale settings and query access.
- The current SlyTranslate codebase does not yet call TranslatePress `url_converter` directly.
- If TranslatePress-specific URL translation support is added later, prefer `url_converter` over custom path rewriting.

## 5. SlyTranslate Adapter Contract

`TranslatePressAdapter` must implement `TranslationPluginAdapter` and `StringTableContentAdapter` with single-entry semantics.

- `is_available()`: return true when TranslatePress detection succeeds.
- `get_languages()`: return configured non-default target languages as ISO-2 keys mapped to human-readable names.
- `get_post_language()`: resolve the configured default language from `trp_settings` and convert it to ISO-2.
- `get_language_variant()`: return the raw source value unchanged, because TranslatePress stores source content directly in the post.
- `get_post_translations()`: report which languages already have approved dictionary rows for the current post.
- `get_post_translation_for_language()`: resolve one target-language translation using a locale-scoped dictionary lookup and return the source post ID when it exists.
- `get_string_translation()`: return the best existing dictionary translation for one source string and requested target language.
- `supports_pretranslated_content_pairs()`: return true.
- `build_content_translation_units()`: emit TranslatePress string-table units shaped as `{id, source, lookup_keys}`.
- `create_translation()`: persist translated title, excerpt, and content string pairs into TranslatePress dictionary storage and return the source post ID on success.
- `link_translation()`: no-op returning true.

Current source-language resolution must match the existing TranslatePress code path.

1. Read `trp_settings['default-language']`.
2. Convert the locale to ISO-2 via `locale_to_iso2()`.
3. Use that result as the post source language.

Do not invent WPGlobus-style builder-language logic or Polylang-style per-post source overrides for TranslatePress. In the current code, TranslatePress source language is the configured default language.

Also note the current PostTranslationService behavior.

- The optional caller-provided `source_language` override is currently honored for `WpMultilangAdapter` and `WpglobusAdapter`, but not for `TranslatePressAdapter`.
- `get_language_variant()` is intentionally a passthrough, because the source post already contains the canonical source-language text.

## 6. Current SlyTranslate Integration Surface

These integration points already exist in the current codebase and must be documented as current state, not as future work.

- `slytranslate/inc/TranslatePressAdapter.php`: concrete adapter implementation already exists.
- `slytranslate/slytranslate.php`: `AI_Translate::get_adapter()` already includes `new TranslatePressAdapter()` in the candidate list.
- `slytranslate/slytranslate.php`: `AI_Translate::is_single_entry_translation_mode()` already treats `TranslatePressAdapter` as single-entry.
- `slytranslate/inc/TranslatePressEditorIntegration.php`: registers TranslatePress editor hooks, bootstrap data, and the dedicated sidebar assets.
- `slytranslate/assets/translatepress-editor.js`: translates only the active visible TranslatePress string field, refreshes context after in-app navigation, and infers the visible target language from the active editor scope.
- `slytranslate/inc/PostTranslationService.php`: already uses the `StringTableContentAdapter` fast path and can pass `content_string_pairs` into `TranslatePressAdapter::create_translation()`.
- `slytranslate/inc/TranslationQueryService.php`: status handling, untranslated-item lookup, and bulk queries already treat TranslatePress as single-entry mode.
- `slytranslate/inc/TranslationQueryService.php`: `get_existing_translation_id()` already delegates to `TranslatePressAdapter::get_post_translation_for_language()` for locale-scoped existence checks.
- `slytranslate/inc/ListTableTranslation.php`: list-table actions already treat TranslatePress like other single-entry adapters, including overwrite-aware existing-language handling.
- `slytranslate/inc/LanguageMutationService.php`: set-post-language is not available for TranslatePress because the adapter does not implement `TranslationMutationAdapter`.

When updating this instruction file, describe only what the code currently does. Do not present TranslatePress URL conversion or slug export helpers as already integrated unless the SlyTranslate code has actually been wired to those APIs.

## 7. String-Table, Segment, and URL Rules

### 7.1 Source-Value Model

TranslatePress does not use inline language markup.

- The source post values are the canonical default-language content.
- The translated values live in TranslatePress dictionary rows.
- `get_language_variant()` should therefore remain a passthrough unless the upstream storage model changes.

### 7.2 Extract Content Segments

Current SlyTranslate logic extracts TranslatePress text units from HTML text nodes in document order.

- `extract_string_segments()` uses `DOMDocument` plus `DOMXPath( '//text()' )`.
- Whitespace-only nodes are filtered out.
- Inline boundaries remain significant, so markup such as `<a>`, `<code>`, and `<strong>` splits one paragraph into multiple TranslatePress lookup segments.
- Segment order must be preserved exactly because the fallback content-pair builder relies on positional matching.

Examples from the current unit tests:

- `<p>Text</p>` -> `['Text']`
- `<p>Vor <a>Link</a> nach</p>` -> `['Vor ', 'Link', ' nach']`
- `<p>AI (<code>fn()</code>)</p>` -> `['AI (', 'fn()', ')']`

### 7.3 Build Lookup Keys for Dictionary Matches

Current SlyTranslate logic generates multiple lookup variants for each visible segment.

- Start with the normalized source segment.
- Add the trimmed variant when leading or trailing whitespace differs.
- Add `wptexturize()` render variants when WordPress would convert the segment during rendering.
- Add numeric-entity forms for typographic characters such as German quotes and dashes when needed.
- Add whitespace-bounded texturized variants when the source segment originally carried edge whitespace.
- Deduplicate the result and cap it at 8 variants.

This behavior matters because TranslatePress may index rendered originals differently from raw DOM text.

- A visible source string can appear in the dictionary both as raw text and as a texturized or entity-encoded render-time variant.
- `get_string_translation()` should prefer an exact lookup-key match first and only fall back to another translated variant when no exact key exists.
- When persisting a translation, all meaningful lookup variants should receive the same translated value so the frontend does not fall back to the source language for render-time variants.

### 7.4 Persist String Pairs

Current adapter persistence follows this shape.

- If `content_string_pairs` is provided, persist those pairs directly and skip rebuilding pairs from translated HTML.
- Otherwise, build pairs by matching extracted original and translated segments positionally.
- If original and translated segment counts diverge, fall back to storing the full content as a single string pair.
- Insert missing originals before updating dictionary rows.
- Prefer direct table writes with the real original-string IDs when available, because some `TRP_Query::get_string_ids()` paths can surface dictionary-row IDs instead of the original-string IDs required by the editor.

Do not regress the real-original-ID path. Orphaned dictionary rows can make the TranslatePress editor ignore translations that appear to exist in the database.

### 7.5 Internal URL Handling

TranslatePress upstream provides a dedicated URL conversion API and that should remain the default rule for any future URL-aware integration work.

- To translate one internal URL for one language, use `url_converter->get_url_for_language( $lang, $url, '' )`.
- To extract translated URLs for all active languages, iterate over `publish-languages` and skip the default language.
- To display or export a URL for a chosen language, convert from the default-language URL instead of manually rewriting slugs.

Current SlyTranslate limitation:

- The current SlyTranslate TranslatePress adapter does not yet integrate `url_converter` into its translation persistence flow.
- If URL-specific translation support is added, use the upstream API and document the actual call sites in section 6.

## 8. Testing Requirements

Unit coverage already exists and should be extended alongside any behavior change.

- `slytranslate/tests/Unit/TranslatePressAdapterTest.php` covers availability detection, locale mapping, default-language resolution, and configured-language handling.
- It covers locale-scoped existence checks, request-local translation lookup caching, and exact-match priority in `get_string_translation()`.
- It covers DOM text-node extraction across inline markup boundaries.
- It covers dictionary persistence with approved status `2`, real original-string IDs, missing-original insertion, trimmed lookup variants, and texturized or entity-encoded lookup variants.
- It covers `build_content_translation_units()` and the `content_string_pairs` fast path.
- `slytranslate/tests/Unit/TranslatePressEditorIntegrationTest.php` covers asset registration, localized bootstrap data, adapter gating, and current-URL-based context refresh after in-app navigation.
- `slytranslate/tests/Unit/TranslationQueryServiceTest.php` and `slytranslate/tests/Unit/ListTableTranslationTest.php` should be updated when TranslatePress single-entry status or list-table behavior changes.

Recommended narrow validation commands:

```bash
cd slytranslate && ./vendor/bin/phpunit --filter 'TranslatePressAdapterTest|TranslatePressEditorIntegrationTest'
```

For status or list-table changes, widen the filter to include:

```bash
cd slytranslate && ./vendor/bin/phpunit --filter 'TranslatePressAdapterTest|TranslatePressEditorIntegrationTest|TranslationQueryServiceTest|ListTableTranslationTest'
```

## 9. Deployment and Smoke Test Requirements

For TranslatePress work, a deploy and translation smoke test remain mandatory before reporting completion.

- Build and verify the plugin ZIP.
- Deploy to the TranslatePress WordPress pod.
- After deploy, run a test translation for post `11` through the WordPress MCP tools.
- Use the mutating ability `ai-translate/translate-content`.
- Always use the model `Ministral-3-3B-Instruct-2512-Q4_K_M`.
- The additional instruction must contain exactly: `Anreden mit "du" statt "Sie". junger aber professioneller ton.`
- Use `overwrite: true` so the smoke test exercises the current persistence path instead of short-circuiting on an existing translation.
- Do not report the TranslatePress task as complete until this smoke test succeeds.

The smoke test must verify TranslatePress-specific persistence.

- The translated item remains the same source post ID.
- The target language is reported as existing through SlyTranslate status lookup.
- The translation is visible in the TranslatePress frontend or visual editor for the target language.
- Inline-segment cases with links or texturized quotes do not regress back to the source language.
- The source `wp_posts` content remains the source-language canonical content while the translated strings live in TranslatePress dictionary storage.

## 10. Adaptation Checklist for Another Language Plugin

When copying this file for another plugin, replace these plugin-specific facts.

- Translation topology: single-entry string-table, single-entry inline-markup, or multi-post.
- Storage schema and dictionary tables.
- Detection hooks, classes, and helper functions.
- Language-list and default-language sources.
- Source-language resolution order.
- Segment extraction and lookup-key generation rules.
- URL conversion APIs and slug-handling rules.
- Whether `StringTableContentAdapter` or `TranslationMutationAdapter` support exists.
- Which SlyTranslate services treat the adapter as single-entry.
- Which unit tests and smoke tests are required.

If the new plugin does not use string tables, section 7 should be rewritten entirely instead of lightly edited.