---
applyTo: '**'
description: 'WPGlobus adapter implementation rules for SlyTranslate'
---

# WPGlobus Adapter Implementation Rules

## 1. WPGlobus Storage Model

WPGlobus stores all language variants in a **single post** with inline markup:

```
[:en]Hello world[/en][:de]Hallo Welt[/de][:fr]Bonjour le monde[/fr]
```

This pattern applies to:
- `post_title`
- `post_content` (Gutenberg block HTML with embedded language tags)
- `post_excerpt`
- Configured custom meta fields (opt-in via WPGlobus admin)

## 2. WPGlobus API Functions

| Function | Purpose |
|---|---|
| `wpglobus_current_language()` | Returns current language code (e.g. `'en'`) |
| `wpglobus_languages_list()` | Returns array of configured language codes |
| `WPGlobus()->Shared->Config->Languages->Layer->get_languages_code()` | Low-level language list |
| `wpglobus()->get_languages_code()` | Alternative accessor |

**Detection:**
```php
class_exists( 'WPGlobus', false ) || function_exists( 'wpglobus_current_language' )
```

## 3. Adapter Contract (implements TranslationPluginAdapter)

The `WpglobusAdapter` must implement all methods from `TranslationPluginAdapter`:

- `is_available()` — check WPGlobus is active
- `get_languages()` — return `['en' => 'English', 'de' => 'Deutsch', ...]`
- `get_post_language()` — return source language (default language from WPGlobus config)
- `get_post_translations()` — scan for language variants in single post
- `create_translation()` — merge translated variant into single post
- `link_translation()` — no-op (single post model)

## 4. Parsing / Encoding Logic

### Extract language variant from WPGlobus markup

```php
private function get_language_variant( string $value, string $language_code ): string {
    // Parse [:lang]...[/lang] blocks using regex or WPGlobus helper
    // Return the segment matching $language_code
    // Return empty string if language not found
    // Return original value if no markup detected (single-language post)
}
```

**Regex pattern:**
```php
$pattern = '/\[(' . preg_quote( $language_code, '/' ) . ')\](.*?)\[\/\1\]/s';
preg_match( $pattern, $value, $matches );
```

### Merge translated variant into WPGlobus markup

```php
private function merge_language_value( string $existing_value, string $target_language, string $target_value ): string {
    // If existing value has WPGlobus markup:
    //   - Replace or insert the target_language segment
    // If no markup detected:
    //   - Wrap target_value with [:target_language]...[/target_language]
    //   - Keep existing non-target segments intact
}
```

## 5. Meta Handling

- WPGlobus only handles custom meta fields that are **explicitly configured** in its admin UI
- SlyTranslate must NOT assume all meta fields are multilingual
- For unconfigured meta, treat as single-value (no language markup)
- If meta contains WPGlobus markup, apply the same extract/merge logic

## 6. Post-Type Validation

- WPGlobus does **not** have per-post-type translation toggles like Polylang or WP Multilang
- All posts are potentially multilingual if WPGlobus is active
- However, check `post_type_supports( $post_type, 'title' )` etc. for safety

## 7. Integration Points

### Files to modify:

| File | Change |
|---|---|
| `slytranslate/inc/WpglobusAdapter.php` | **NEW** — WPGlobus adapter implementation |
| `slytranslate/slytranslate.php` | Add `WpglobusAdapter` to `$candidates` array in `get_adapter()` |
| `slytranslate/slytranslate.php` | Add `is_single_entry_translation_mode()` check for `WpglobusAdapter` |
| `slytranslate/inc/TranslationQueryService.php` | Add `WpglobusAdapter` instanceof check for single-entry mode |
| `slytranslate/inc/EditorBootstrap.php` | Pass `singleEntryTranslationMode` for WPGlobus |
| `slytranslate/inc/TranslationMutationAdapter.php` | **OPTIONAL** — if WPGlobus supports set-post-language via `wpglobus_set_language()` |

### Files to update (changelog/readme):

| File | Change |
|---|---|
| `CHANGELOG.md` | Add `### Features` entry for WPGlobus support |
| `slytranslate/changelog.txt` | Mirror the changelog entry |
| `slytranslate/readme.txt` | Update `== Description ==` and abilities list |
| `README.md` | Mirror readme.txt changes |

## 8. Testing Strategy

1. **Local PHPUnit tests:**
   - `WpglobusAdapterTest.php` — unit tests for `get_language_variant`, `merge_language_value`, `get_languages`
   - Test edge cases: empty markup, nested tags, missing language segments, single-language posts

2. **Test pod verification:**
   - Install WPGlobus plugin on test pod (http://192.168.178.21:30111)
   - Configure languages (en, de)
   - Test post 7 translation through SlyTranslate MCP abilities
   - Verify `ai-translate/get-languages` returns WPGlobus languages
   - Verify `ai-translate/translate-content` creates WPGlobus markup correctly

3. **MCP smoke test:**
   - Use `ai-translate/translate-content` on post 7
   - Model: `Qwen3.6-35B-A3B-UD-IQ3_XXS`
   - Additional instruction: `Anreden mit "du" statt "Sie". junger aber professioneller ton.`
   - Verify resulting post content has correct `[:en]...[:de]...` markup

## 9. Key Differences from WP Multilang

| Aspect | WP Multilang | WPGlobus |
|---|---|---|
| Tag format | `[:en]...[:de]...` (no closing tag) | `[:en]...[/en][:de]...[/de]` (closing tag required) |
| API functions | `wpm_string_to_ml_array()`, `wpm_ml_array_to_string()` | Regex parsing, `wpglobus_current_language()` |
| Language config | `wpm_get_languages()` | `wpglobus_languages_list()` or `WPGlobus()->Shared->Config->Languages->Layer->get_languages_code()` |
| Default language | `wpm_get_default_language()` | `wpglobus_default_language()` or config option |
| Meta handling | Automatic per configured keys | Opt-in per custom field |
| Post-type support | `wpm_get_post_config()` | All types (no per-type toggle) |

## 10. Regex Patterns for WPGlobus Markup

**Extract segment:**
```php
$pattern = '/\[' . preg_quote( $lang, '/' ) . '\](.*?)\[\/' . preg_quote( $lang, '/' ) . '\]/s';
```

**Strip all markup (for raw content):**
```php
$pattern = '/\[\/?[a-z]+\](.*?)\[\/[a-z]+\]/s';
```

**Detect if value contains WPGlobus markup:**
```php
boolval( preg_match( '/\[\/?[a-z]+\]/', $value ) )
```

## 11. Security Considerations

- Sanitize all language codes with `sanitize_key()`
- Escape output with `esc_html()` / `esc_attr()` as appropriate
- Use `wp_kses()` for content that may contain HTML
- Validate language codes against WPGlobus configured list before writing
- Never trust raw WPGlobus markup — always validate before parsing

## 12. Fallback Behavior

- If WPGlobus markup is malformed, treat the entire value as the default language variant
- If a language segment is missing, return empty string (not the source language)
- If WPGlobus class exists but no languages configured, return empty array from `get_languages()`