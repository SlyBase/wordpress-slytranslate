<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Opt-in interface for translation adapters that store translations as
 * original→translated string pairs rather than translated post HTML.
 *
 * Implementing this interface activates a JSON-batch fast path in
 * PostTranslationService that translates individual text segments instead of
 * the full Gutenberg block tree. This avoids structure-drift fallbacks,
 * reduces the number of AI calls, and is semantically correct for adapters
 * like TranslatePress that never persist translated post_content.
 */
interface StringTableContentAdapter {

	/**
	 * Build a flat list of translatable text units from post content.
	 *
	 * Each unit has:
	 *   - `id`          Stable key used inside the JSON batch sent to the LLM.
	 *   - `source`      The source-language text to translate.
	 *   - `lookup_keys` All string-table keys under which the translation
	 *                   should be stored (original may differ by whitespace).
	 *
	 * @param string $source_content Raw post_content of the source post.
	 * @return array<int, array{id: string, source: string, lookup_keys: string[]}>
	 */
	public function build_content_translation_units( string $source_content ): array;

	/**
	 * Whether the adapter wants the string-table fast path to be used.
	 *
	 * Returning false allows the adapter to implement the interface but fall
	 * back to the generic block-translation path at runtime.
	 */
	public function supports_pretranslated_content_pairs(): bool;
}
