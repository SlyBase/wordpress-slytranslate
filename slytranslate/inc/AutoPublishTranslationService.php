<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-translate posts when they are published.
 *
 * Consumes the slytranslate_new_post option: on transition to `publish`,
 * translations for all missing target languages are queued as drafts (never
 * auto-published — editorial review stays mandatory). Hard dependency on
 * TranslationQueue: without a background transport nothing is queued, because
 * a synchronous mode would block publish requests for minutes.
 */
class AutoPublishTranslationService {

	/** Marker meta on translations created by this plugin (loop guard). */
	public const GENERATED_META_KEY = '_slytranslate_generated';

	/**
	 * Hook callback for transition_post_status (priority 10, 3 args).
	 *
	 * @param string        $new_status New post status.
	 * @param string        $old_status Previous post status.
	 * @param \WP_Post|null $post       The post being transitioned.
	 */
	public static function handle_transition_post_status( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || $post->ID < 1 ) {
			return;
		}
		if ( get_option( 'slytranslate_new_post', '0' ) !== '1' ) {
			return;
		}

		// Loop guard: translations created by this plugin must not trigger
		// another round of translations.
		if ( get_post_meta( $post->ID, self::GENERATED_META_KEY, true ) ) {
			return;
		}

		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter || AI_Translate::is_single_entry_translation_mode() ) {
			return;
		}

		if ( is_wp_error( TranslationQueryService::validate_translatable_post_type( (string) $post->post_type ) ) ) {
			return;
		}

		// Only translate posts in the source language: secondary-language posts
		// are usually translations themselves.
		$source_language = (string) ( $adapter->get_post_language( $post->ID ) ?? '' );
		if ( '' === $source_language ) {
			return;
		}
		if ( function_exists( 'pll_default_language' ) && (string) \pll_default_language() !== $source_language ) {
			return;
		}

		if ( ! TranslationQueue::is_available() ) {
			TimingLogger::log( 'auto_translate_skipped', array(
				'post'   => $post->ID,
				'reason' => 'queue_unavailable',
			) );
			return;
		}

		$existing_translations = $adapter->get_post_translations( $post->ID );
		$existing_translations = is_array( $existing_translations ) ? $existing_translations : array();

		foreach ( array_keys( $adapter->get_languages() ) as $language_code ) {
			$language_code = sanitize_key( (string) $language_code );
			if ( '' === $language_code || $language_code === $source_language ) {
				continue;
			}
			if ( ! empty( $existing_translations[ $language_code ] ) ) {
				continue;
			}

			TranslationQueue::enqueue_bulk(
				array( $post->ID ),
				$language_code,
				array(
					'post_status' => 'draft',
					'overwrite'   => false,
				)
			);
		}
	}
}
