# SlyTranslate - AI Translation Abilities

AI-powered translation abilities for WordPress using the WordPress AI Client and the Abilities API.

SlyTranslate exposes translation workflows as reusable WordPress abilities, so they can be used from the block editor, the REST API, MCP clients, and automation tooling.

## Highlights

- Translate arbitrary text with `ai-translate/translate-text`
- Translate posts, pages, and custom post types with `ai-translate/translate-content`
- Bulk-translate multiple entries with `ai-translate/translate-content-bulk`
- Inspect translation status and untranslated content
- Translate SEO title and description fields for major SEO plugins
- Use a block-editor sidebar for content translation workflows
- Translate selected text inline in the editor, even without a translation plugin
- Expose abilities over REST and MCP-friendly discovery

## What This Plugin Does

SlyTranslate is built for WordPress sites that want AI-assisted translation without hard-coding a single provider into the plugin itself.

It uses the WordPress AI Client for model access, so connector setup happens centrally in WordPress under Settings > Connectors. Once a connector is configured, SlyTranslate can use it for plain text translation as well as full content translation workflows.

## Abilities

| Ability | Purpose |
| --- | --- |
| `ai-translate/get-languages` | List languages exposed by the active translation plugin |
| `ai-translate/get-translation-status` | Show which translations exist for a content item |
| `ai-translate/get-untranslated` | Find posts, pages, or CPT entries still missing a target translation |
| `ai-translate/translate-text` | Translate arbitrary text between languages |
| `ai-translate/translate-content` | Create or update a translated content item |
| `ai-translate/translate-content-bulk` | Bulk-translate multiple content items |
| `ai-translate/configure` | Read or update plugin settings |

## Editor Experience

SlyTranslate adds two editor-facing workflows:

- An AI Translate document panel for content translation when a translation plugin is active
- A Translate with SlyTranslate action for highlighted text inside supported rich-text fields

The selected-text workflow is independent from Polylang and uses `ai-translate/translate-text`, which makes it useful even on sites that only want inline text translation.

## AI Provider Setup

SlyTranslate uses the WordPress AI Client via `wp_ai_client_prompt()`. That means API and endpoint setup is delegated to connector plugins.

### Recommended for Local LLMs

For local models or self-hosted inference servers, use **Ultimate AI Connector for Compatible Endpoints**:

https://wordpress.org/plugins/ultimate-ai-connector-compatible-endpoints/

That connector is a good fit if you want to use:

- Ollama
- LM Studio
- LocalAI
- vLLM
- text-generation-webui
- Other OpenAI-compatible `/v1/chat/completions` endpoints

Configure the endpoint once in Settings > Connectors and SlyTranslate can use the discovered models through the normal WordPress AI Client flow.

## Translation Plugin Support

Post and content-entry translation workflows currently rely on a translation plugin adapter.

- Supported today: Polylang
- Planned/possible later: WPML, TranslatePress, additional adapters

If no translation plugin is active, text translation still works, including the inline selected-text action in the block editor.

## SEO Plugin Support

SlyTranslate auto-detects common SEO plugins and translates the most important title and description fields while clearing derived analysis data so the SEO plugin can rebuild it.

Supported SEO integrations include:

- Yoast SEO
- Rank Math
- All in One SEO
- The SEO Framework
- SEOpress
- Slim SEO

## Architecture Notes

- Uses the WordPress AI Client instead of storing provider-specific API keys in this plugin
- Translates long content in chunks and can adapt chunk sizes based on provider/model limits
- Exposes abilities over REST at `/wp-abilities/v1/`
- Marks abilities as public for MCP adapter discovery via `/wp-json/mcp/mcp-adapter-default-server`
- Keeps Polylang auto-translate hooks for backward compatibility

## Requirements

- WordPress 7.0+
- PHP 8.1+
- An AI connector/plugin configured in Settings > Connectors
- Polylang for content translation workflows across posts, pages, and custom post types
- WordPress MCP Adapter if you want MCP client discovery

## Installation

1. Ensure WordPress 7.0+ and PHP 8.1+ are running.
2. Install and activate an AI connector plugin and configure it in Settings > Connectors.
3. For local LLMs, install "Ultimate AI Connector for Compatible Endpoints" and point it at Ollama, LM Studio, LocalAI, vLLM, or another compatible endpoint.
4. Optional for translated content workflows across posts, pages, and custom post types: install and activate Polylang.
5. Optional for MCP discovery: install and activate the WordPress MCP Adapter.
6. Copy the `slytranslate` directory into `/wp-content/plugins/`.
7. Activate SlyTranslate - AI Translation Abilities.

## REST and MCP

All abilities are exposed through the WordPress Abilities API and can be invoked over REST.

- REST base: `/wp-abilities/v1/`
- Run an ability: `/wp-abilities/v1/run/{ability_name}`
- MCP adapter discovery: `/wp-json/mcp/mcp-adapter-default-server`

## Repository Layout

- `slytranslate/`: WordPress plugin root
- `slytranslate/ai-translate.php`: main plugin bootstrap and ability registration
- `slytranslate/assets/editor-plugin.js`: Gutenberg editor integration
- `slytranslate/inc/`: translation and SEO adapter code
- `seo-plugin-test-matrix.md`: SEO integration notes and testing matrix

## FAQ

### Does this work without Polylang?

Yes, for text translation.

`ai-translate/translate-text` works without a translation plugin, and the inline selected-text editor action is available for that workflow. Content translation workflows such as `translate-content` and `translate-content-bulk` still require a translation plugin, currently Polylang.

### Can I translate pages or custom post types?

Yes.

`ai-translate/translate-content`, `ai-translate/translate-content-bulk`, `ai-translate/get-translation-status`, and `ai-translate/get-untranslated` work with any Polylang-enabled post type, including pages and custom post types.

### Where do I configure prompts?

Use the `ai-translate/configure` ability to read or update prompt and plugin settings.

### How are API keys handled?

They are handled by the connector configured in Settings > Connectors, not by SlyTranslate itself.

## Development

This repository contains the plugin source in the `slytranslate` directory. The WordPress.org-style plugin readme remains in `slytranslate/readme.txt`, while this file is intended for GitHub visitors.

## License

MIT