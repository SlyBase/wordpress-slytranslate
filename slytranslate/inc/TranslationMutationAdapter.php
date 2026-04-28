<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Optional mutation capabilities for translation plugin adapters.
 *
 * The read contract stays on TranslationPluginAdapter. Write-capabilities
 * are discovered dynamically via this interface.
 */
interface TranslationMutationAdapter {

	public const CAPABILITY_SET_POST_LANGUAGE  = 'set_post_language';
	public const CAPABILITY_RELINK_TRANSLATION = 'relink_translation';

	/**
	 * Check whether a mutation capability is supported by this adapter.
	 */
	public function supports_mutation_capability( string $capability ): bool;

	/**
	 * Set the language for a post.
	 *
	 * @return bool|\WP_Error
	 */
	public function set_post_language( int $post_id, string $target_language );

	/**
	 * Save a translation relation map keyed by language code.
	 *
	 * @param array<string, int> $translations
	 * @return bool|\WP_Error
	 */
	public function relink_post_translations( array $translations );
}
