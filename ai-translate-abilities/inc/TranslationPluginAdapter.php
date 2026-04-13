<?php

namespace AI_Translate;

interface TranslationPluginAdapter {

	/**
	 * Check if the translation plugin is active and available.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Get all available languages.
	 *
	 * @return array Associative array of language code => language name, e.g. ['de' => 'Deutsch', 'en' => 'English'].
	 */
	public function get_languages(): array;

	/**
	 * Get the language code of a post.
	 *
	 * @param int $post_id
	 * @return string|null Language code or null if not set.
	 */
	public function get_post_language( int $post_id ): ?string;

	/**
	 * Get all existing translations for a post.
	 *
	 * @param int $post_id
	 * @return array Associative array of language code => post ID, e.g. ['de' => 42, 'en' => 17].
	 */
	public function get_post_translations( int $post_id ): array;

	/**
	 * Create a translated post from a source post.
	 *
	 * @param int    $source_post_id The source post to translate from.
	 * @param string $target_lang    Target language code.
	 * @param array  $data           Translated data: 'post_title', 'post_content', 'post_excerpt', 'meta' (optional).
	 * @return int|\WP_Error The new post ID on success, WP_Error on failure.
	 */
	public function create_translation( int $source_post_id, string $target_lang, array $data );

	/**
	 * Link two posts as translations of each other.
	 *
	 * @param int    $source_post_id
	 * @param int    $translated_post_id
	 * @param string $target_lang
	 * @return bool
	 */
	public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool;
}
