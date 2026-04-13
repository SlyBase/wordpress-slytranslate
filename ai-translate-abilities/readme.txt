=== AI Translate Abilities ===
Contributors: timonf
Tags: ai, translation, abilities-api, polylang, multilingual
Requires at least: 7.0
Tested up to: 7.0.0
Requires PHP: 8.1
Stable tag: trunk
License: MIT
License URI: https://opensource.org/licenses/MIT

AI translation abilities for WordPress. Translates posts and text using the WordPress AI Client and Abilities API. Based on AI Translate For Polylang by James Low.

== Description ==

AI Translate Abilities provides AI-powered translation as WordPress Abilities, making them available to AI agents, automation tools, and the WordPress REST API.

**Abilities provided:**

* **ai-translate/get-languages** – Lists all available languages from the active translation plugin.
* **ai-translate/get-translation-status** – Shows which translations exist for a given post.
* **ai-translate/translate-text** – Translates arbitrary text between languages.
* **ai-translate/translate-post** – Translates an entire post (title, content, excerpt, meta) and creates the translation in the translation plugin.
* **ai-translate/translate-posts** – Bulk-translates multiple posts at once (max 50).
* **ai-translate/configure** – Read or update plugin settings (prompt template, meta key configuration, auto-translate toggle).

**Architecture:**

* Uses the WordPress AI Client (`wp_ai_client_prompt()`) for LLM access — no API keys to configure in this plugin. Set up your preferred AI provider via Settings > Connectors.
* Translation plugin support via an adapter interface. Currently supports **Polylang**. Additional adapters (WPML, TranslatePress, etc.) can be added.
* All abilities are exposed via the REST API (`/wp-abilities/v1/`) for external tool and AI agent integration.
* Polylang auto-translate hooks are preserved for backward compatibility — creating a new translation in Polylang still triggers automatic translation.

**Requirements:**

* WordPress 7.0+
* PHP 8.1+
* An AI provider plugin (e.g., AI Provider for OpenAI, AI Provider for Anthropic, AI Provider for Google) configured via Settings > Connectors
* A translation plugin: Polylang (recommended), with more adapters planned

== Installation ==

1. Ensure WordPress 7.0+ and PHP 8.1+ are running.
2. Install and activate a translation plugin (e.g., Polylang).
3. Install and activate an AI provider plugin and configure it via Settings > Connectors.
4. Upload the `ai-translate-abilities` folder to `/wp-content/plugins/` or install via the plugin directory.
5. Activate AI Translate Abilities.
6. Translation abilities are now available via the Abilities API and REST API.

== Frequently Asked Questions ==

= Where do I configure API keys? =

API keys are managed centrally in WordPress via Settings > Connectors. This plugin does not handle API keys directly.

= How do I change the translation prompt? =

Use the `ai-translate/configure` ability via the REST API or any tool that supports WordPress Abilities.

= Does this work without Polylang? =

The translation abilities that create posts (`translate-post`, `translate-posts`) require a translation plugin (currently Polylang). The `translate-text` ability works independently.

== Changelog ==

= 1.0.0 =
* Complete rewrite for WordPress 7.0 Abilities API, based on AI Translate For Polylang by James Low
* LLM integration via WordPress AI Client (no more direct API key management)
* 6 abilities registered: get-languages, get-translation-status, translate-text, translate-post, translate-posts, configure
* Adapter architecture: TranslationPluginAdapter interface with PolylangAdapter implementation
* Bulk translation support (translate-posts, max 50 posts)
* All abilities exposed via REST API (show_in_rest)
* Settings menu removed — configuration via ai-translate/configure ability
* Requires WordPress 7.0+, PHP 8.1+