---
applyTo: '**'
description: 'Blueprint for SlyTranslate single-entry language-plugin adapters, with WPGlobus as the concrete profile and smoke-test requirements.'
---

# Single-Entry Language Plugin Adapter Blueprint: WPGlobus

## 1. How to Reuse This File

Use this file as a template for other language-plugin adapter instructions.

- Keep the section layout.
- Replace the plugin profile in section 2.
- Replace the storage syntax, detection signals, and source-language resolution rules in sections 3 to 7.
- Keep the SlyTranslate integration section grounded in the current codebase instead of describing a future plan.
- Keep plugin-agnostic rules in the main sections and isolate plugin-specific details inside the profile bullets and examples.

## 2. Plugin Profile: WPGlobus

- Plugin type: single-entry multilingual plugin.
- Upstream source: https://plugins.svn.wordpress.org/wpglobus/trunk/
- Upstream version family checked for these rules: WPGlobus 3.0.2 trunk.
- Storage model: multiple language variants are stored inline inside one post record.
- Inline syntax: `{:en}Hello{:}{:de}Hallo{:}`.
- Opening tag constant: `WPGlobus::LOCALE_TAG_START` => `{:lang}`.
- Closing tag constant: `WPGlobus::LOCALE_TAG_END` => `{:}`.
- Full tag format constant: `WPGlobus::LOCALE_TAG` => `{:%s}%s{:}`.
- Default-language source of truth: `WPGlobus::Config()->default_language` or `wpglobus_default_language()`.
- Language list source of truth: `WPGlobus::Config()->enabled_languages` with legacy fallback to `open_languages` or `languages`.
- Current editor language is builder-aware and may come from request state, builder state, post meta, or builder cookie.
- Post-language mutation support in SlyTranslate: not implemented for WPGlobus.
- Translation linking model: no cross-post relation, because translations live in the same post.

## 3. Storage Topology and Data Model

For WPGlobus, treat the plugin as a single-entry adapter.

- `post_title`, `post_content`, and `post_excerpt` may contain inline language markup.
- A translation does not create a sibling post. It updates the same post ID.
- Plain unmarked values must be interpreted as default-language-only content.
- Existing target-language presence is determined from the inline content variant, not from an external translation table.
- In the current SlyTranslate code, `get_post_translations()` considers a language translated only when the extracted `post_content` variant is non-empty after trimming.

Meta handling must stay conservative.

- Do not assume every meta key is multilingual.
- WPGlobus exposes configurable multilingual post-meta support through its own options and vendor config, notably `wpglobus_option_post_meta_settings` and `WPGlobus::Config()->meta` in admin and REST contexts.
- In SlyTranslate, only merge inline language markup into string meta values that already participate in multilingual storage or are explicitly intended to do so.
- For non-string meta values, keep passthrough behavior unless the adapter logic is deliberately extended.

Post-type support must remain WordPress-native.

- WPGlobus does not maintain Polylang-style translation posts.
- Use `post_type_supports( $post_type, 'title' )` and `post_type_supports( $post_type, 'editor' )` before assuming a field should be read or written.

## 4. Upstream Detection, Config, and Editor Signals

Detection should remain broad enough to cover older and newer WPGlobus versions.

```php
class_exists( 'WPGlobus', false ) || function_exists( 'wpglobus_current_language' )
```

Preferred upstream signals for WPGlobus are:

- `WPGlobus::Config()->enabled_languages`
- `WPGlobus::Config()->default_language`
- `WPGlobus::Config()->en_language_name`
- `WPGlobus::get_language_meta_key()` which resolves to `wpglobus_language`
- `WPGlobus::Config()->builder->get_language( $post_id )`
- `WPGlobus::Config()->builder->get_cookie_name()` which defaults upstream to `wpglobus-builder-language`

Important cookie distinction from upstream:

- `wpglobus-language` is the front-end language cookie used for URL and redirect behavior.
- `wpglobus-builder-language` is the builder/editor cookie used for admin editing context.
- For SlyTranslate source-language resolution in admin editing flows, prefer builder signals over the front-end cookie.

Upstream builder behavior matters here.

- `WPGlobus_Config_Builder::get_language()` resolves the active edit language from post meta, request params, REST context, request URI, and fallback defaults.
- The builder writes and reads the hidden meta field `wpglobus_language`.
- The builder cookie stores language plus optional post ID, for example `de+123`.

## 5. SlyTranslate Adapter Contract

`WpglobusAdapter` must implement the normal `TranslationPluginAdapter` contract with single-entry semantics.

- `is_available()`: return true when WPGlobus detection succeeds.
- `get_languages()`: return a map of configured language code to language name.
- `get_post_language()`: resolve the currently active source language for the editing context.
- `get_language_variant()`: extract one language segment from inline markup or return the plain default-language value.
- `get_post_translations()`: report which languages already have non-empty inline content in the current post.
- `create_translation()`: merge translated fields back into the same post record.
- `link_translation()`: no-op returning true.

Current source-language resolution order must match the existing adapter behavior.

1. Request-level language hints via `language`, `wpglobus-language`, `wpglobus_language`, and `WPGlobus::get_language_meta_key()`.
2. Builder cookie using `WPGlobus::Config()->builder->get_cookie_name()` or the default `wpglobus-builder-language` format.
3. Builder runtime language via `WPGlobus::Config()->builder->get_language( $post_id )`.
4. `wpglobus_current_language()` when available.
5. `WPGlobus::Config()->language` or `WPGlobus::Config()->current_language`.
6. `get_query_var( 'lang' )`.
7. Default language fallback in the caller.

Do not reduce this to `wpglobus_current_language()` only. That misses the active builder tab and breaks status or translation actions in admin editing flows.

## 6. Current SlyTranslate Integration Surface

These integration points already exist in the current codebase and must be documented as current state, not as future work.

- `slytranslate/inc/WpglobusAdapter.php`: concrete adapter implementation already exists.
- `slytranslate/slytranslate.php`: `AI_Translate::get_adapter()` already includes `new WpglobusAdapter()` in the candidate list.
- `slytranslate/slytranslate.php`: `AI_Translate::is_single_entry_translation_mode()` already treats `WpglobusAdapter` as single-entry.
- `slytranslate/inc/TranslationQueryService.php`: status handling and list queries already branch for `WpglobusAdapter` as single-entry mode.
- `slytranslate/inc/EditorBootstrap.php`: bootstrap data already exposes `singleEntryTranslationMode` and plugin languages for the active adapter.
- `slytranslate/inc/PostTranslationService.php`: post translation already extracts title, content, and excerpt from the selected WPGlobus source language before translation.
- `slytranslate/inc/ListTableTranslation.php`: list-table actions already treat WPGlobus like other single-entry adapters, including overwrite-aware existing-language handling.
- `slytranslate/inc/LanguageMutationService.php`: set-post-language is not available for WPGlobus because the adapter does not implement `TranslationMutationAdapter`.

When updating this instruction file, describe only what the code currently does. Do not leave stale bullets such as "NEW file" or "OPTIONAL integration point" once the behavior already shipped.

## 7. Parsing and Merge Rules

### 7.1 Markup Detection

Current SlyTranslate logic detects WPGlobus markup by opening tag only.

```php
preg_match( '/\{:[a-z]{2,10}\}/', $value )
```

Keep this distinct from WP Multilang syntax.

- WPGlobus: `{:en}text{:}`
- WP Multilang: `[:en]text[:]`

### 7.2 Extract One Language Variant

Use the current non-greedy multiline pattern.

```php
$pattern = '/\{:' . preg_quote( $language_code, '/' ) . '\}([\S\s]*?)\{:\}/m';
```

Behavior must match the current adapter.

- Empty language code returns an empty string.
- Empty value returns an empty string.
- Plain unmarked values return the original content only for the default language.
- Marked values return the captured segment for the requested language.
- If markup is present but the requested language segment is not found, return an empty string.

Do not document a broader malformed-markup fallback than the code actually implements.

### 7.3 Merge a Target Translation Back into the Value

The current adapter follows this shape.

```php
$make_tag = static function ( string $lang, string $text ): string {
    return '{:' . $lang . '}' . $text . '{:}';
};
```

Required behavior:

- If the existing value is plain text, wrap the source fallback in the source language tag when available, then append the target tag.
- If the existing value already contains WPGlobus markup and the target segment exists, replace only that segment.
- If the target segment does not exist, append it.
- If the source segment is unexpectedly missing and a source fallback is available, add the source segment before appending the target.
- If the target language is empty, return the original value unchanged.

Current sanitization behavior in SlyTranslate is asymmetric by field and should be preserved unless intentionally changed.

- `post_title` and string meta values are passed through `sanitize_text_field()` before merging.
- `post_content` and `post_excerpt` are merged as provided by the translation flow.

## 8. Testing Requirements

Unit coverage already exists and should be extended alongside any behavior change.

- `slytranslate/tests/Unit/WpglobusAdapterTest.php` covers availability detection.
- It covers language-map resolution.
- It covers source-language resolution through request params, builder runtime, query var, default fallback, and builder cookie.
- It covers markup extraction for plain values, multiline values, and missing languages.
- It covers merge semantics for plain values, replacement, append, and missing-source repair.
- It covers markup detection so WPGlobus syntax is not confused with WP Multilang syntax.

If adapter behavior changes, update or add focused tests before widening scope.

Recommended narrow validation command:

```bash
cd slytranslate && ./vendor/bin/phpunit --filter WpglobusAdapterTest
```

## 9. Deployment and Smoke Test Requirements

For WPGlobus work, a deploy and translation smoke test remain mandatory before reporting completion.

- Build and verify the plugin ZIP.
- Deploy to the WP-Globus WordPress pod.
- After deploy, run a test translation for post `1109` through the WordPress MCP tools.
- If needed, use `mcp_wordpress-sly_mcp-adapter-discover-abilities` first to confirm the translation ability name.
- Execute the translation through `mcp_wordpress-sly_mcp-adapter-execute-ability`.
- Always use the model `Ministral-8B-Instruct-2410-Q4_K_M`.
- The additional instruction must contain exactly: `Anreden mit "du" statt "Sie". junger aber professioneller ton.`
- Do not report the WPGlobus task as complete until this smoke test succeeds.

The smoke test must verify WPGlobus-specific persistence.

- The translated post remains the same post ID.
- The translated content is stored in WPGlobus inline format.
- The requested target language segment is present after translation.
- Existing source-language content remains intact.

## 10. Adaptation Checklist for Another Language Plugin

When copying this file for another plugin, replace these plugin-specific facts.

- Translation topology: single-entry or multi-post.
- Markup syntax or database schema.
- Detection hooks, classes, and helper functions.
- Language-list and default-language sources.
- Current edit-language resolution order.
- Meta-field multilingual contract.
- Whether `TranslationMutationAdapter` support exists.
- Which SlyTranslate services treat the adapter as single-entry.
- Which unit tests and smoke tests are required.

If the new plugin does not use inline markup, section 7 should be rewritten entirely instead of lightly edited.