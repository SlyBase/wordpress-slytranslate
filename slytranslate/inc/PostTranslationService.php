<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the translation of a single post: title, content, excerpt,
 * meta fields, and adapter persistence.
 */
class PostTranslationService {

	/* ---------------------------------------------------------------
	 * Main entry point
	 * ------------------------------------------------------------- */

	/**
	 * Translate a post and create / update its translation via the adapter.
	 *
	 * @param int    $post_id         Source post ID.
	 * @param string $to              Target language code.
	 * @param string $status          Desired post status for the translated post.
	 * @param bool   $overwrite       Whether to overwrite an existing translation.
	 * @param bool   $translate_title Whether to translate the post title.
	 * @param string $additional_prompt Per-request extra prompt instructions.
	 * @return int|\WP_Error Translated post ID or WP_Error on failure.
	 */
	public static function translate_post(
		int $post_id,
		string $to,
		string $status = '',
		bool $overwrite = false,
		bool $translate_title = true,
		string $additional_prompt = ''
	): mixed {
		$additional_prompt = is_string( $additional_prompt ) ? sanitize_textarea_field( $additional_prompt ) : '';

		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Source post not found.', 'slytranslate' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden_post', __( 'You are not allowed to translate this content item.', 'slytranslate' ) );
		}

		$post_type_check = TranslationQueryService::validate_translatable_post_type( $post->post_type );
		if ( is_wp_error( $post_type_check ) ) {
			return $post_type_check;
		}

		$from = $adapter->get_post_language( $post_id ) ?? 'en';
		$to   = sanitize_key( $to );

		if ( '' !== $from && $from === $to ) {
			return new \WP_Error( 'same_language', __( 'Source and target languages must be different.', 'slytranslate' ) );
		}

		$existing_translation = TranslationQueryService::get_existing_translation_id( $post_id, $to, $adapter );
		if ( $existing_translation && get_post_status( $existing_translation ) !== false && ! $overwrite ) {
			return new \WP_Error(
				'translation_exists',
				sprintf(
					/* translators: 1: language code, 2: post ID. */
					__( 'A translation for language "%1$s" already exists (post %2$d).', 'slytranslate' ),
					$to,
					$existing_translation
				)
			);
		}
		if ( $existing_translation && ! current_user_can( 'edit_post', $existing_translation ) ) {
			return new \WP_Error( 'forbidden_translation', __( 'You are not allowed to update the existing translation.', 'slytranslate' ) );
		}

		$target_status = self::normalize_post_status( $status, $post );

		// Initialise progress context.
		$chunk_char_limit    = TranslationRuntime::get_chunk_char_limit();
		$content_total_chunks = TextSplitter::count_content_translation_chunks( $post->post_content, $chunk_char_limit );
		TranslationProgressTracker::initialize_context( $translate_title, $content_total_chunks );

		try {
			// Translate title.
			if ( $translate_title ) {
				TranslationProgressTracker::mark_phase( 'title' );
				$title = TranslationRuntime::translate_text( $post->post_title, $to, $from, $additional_prompt );
				if ( is_wp_error( $title ) ) {
					return $title;
				}
				TranslationProgressTracker::advance_steps();
			} else {
				$title = $post->post_title;
			}

			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			// Translate content.
			if ( TranslationProgressTracker::has_content_progress() ) {
				TranslationProgressTracker::mark_phase( 'content' );
			}

			$content = ContentTranslator::translate_post_content( $post->post_content, $to, $from, $additional_prompt );
			if ( is_wp_error( $content ) ) {
				return $content;
			}

			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			// Translate excerpt.
			TranslationProgressTracker::mark_phase( 'excerpt' );
			$excerpt = TranslationRuntime::translate_text( $post->post_excerpt, $to, $from, $additional_prompt );
			if ( is_wp_error( $excerpt ) ) {
				return $excerpt;
			}

			TranslationProgressTracker::advance_steps();

			// Translate / clear meta.
			TranslationProgressTracker::mark_phase( 'meta' );
			$processed_meta = MetaTranslationService::prepare_translation_meta( $post_id, $to, $from, $additional_prompt );
			if ( is_wp_error( $processed_meta ) ) {
				return $processed_meta;
			}

			TranslationProgressTracker::advance_steps();
			TranslationProgressTracker::mark_phase( 'saving' );

			// Persist via adapter.
			$result = $adapter->create_translation( $post_id, $to, array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_status'  => $target_status,
				'meta'         => $processed_meta,
				'overwrite'    => $overwrite,
			) );

			if ( ! is_wp_error( $result ) ) {
				TranslationProgressTracker::advance_steps();
				TranslationProgressTracker::set_progress( 'done' );
			}

			return $result;
		} finally {
			TranslationProgressTracker::clear_context();
		}
	}

	/* ---------------------------------------------------------------
	 * Post-status normalisation
	 * ------------------------------------------------------------- */

	public static function normalize_post_status( $requested_status, \WP_Post $post ): string {
		if ( is_string( $requested_status ) ) {
			$requested_status = sanitize_key( $requested_status );
			if ( '' !== $requested_status
				&& TranslationQueryService::is_registered_post_status( $requested_status )
				&& ! in_array( $requested_status, array( 'auto-draft', 'inherit', 'trash' ), true )
			) {
				return $requested_status;
			}
		}

		$source_status = get_post_status( $post );
		if ( is_string( $source_status )
			&& TranslationQueryService::is_registered_post_status( $source_status )
			&& ! in_array( $source_status, array( 'auto-draft', 'inherit', 'trash' ), true )
		) {
			return $source_status;
		}

		return 'draft';
	}
}
