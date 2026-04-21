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

		// Initialise progress context with character-budget per phase, so the
		// progress bar advances at a rate proportional to the actual amount of
		// source text translated rather than counting "phases" at equal weight.
		TranslationProgressTracker::initialize_context( $post_id );

		TimingLogger::reset_counters();
		$job_started_at  = TimingLogger::start();
		$runtime_context = TranslationRuntime::get_runtime_context();
		TimingLogger::log( 'job_start', array(
			'post'      => $post_id,
			'post_type' => $post->post_type,
			'from'      => $from,
			'to'        => $to,
			'model'     => (string) ( $runtime_context['model_slug'] ?? '' ),
			'overwrite' => $overwrite ? 1 : 0,
		) );

		$content_units = self::estimate_content_translation_units( $post->post_content );
		$meta_units    = self::estimate_meta_translation_units( $post_id );

		if ( $translate_title ) {
			TranslationProgressTracker::register_phase_units( 'title', self::char_length( $post->post_title ) );
		}
		if ( $content_units > 0 ) {
			TranslationProgressTracker::register_phase_units( 'content', $content_units );
		}
		TranslationProgressTracker::register_phase_units( 'excerpt', self::char_length( $post->post_excerpt ) );
		if ( $meta_units > 0 ) {
			TranslationProgressTracker::register_phase_units( 'meta', $meta_units );
		}
		// Saving has no source text but takes observable time (adapter
		// create_translation can hit Polylang sync, term assignment, …).
		TranslationProgressTracker::register_phase_units( 'saving', 1 );

		try {
			// Translate title.
			if ( $translate_title ) {
				TranslationProgressTracker::mark_phase( 'title' );
				TimingLogger::log( 'phase_start', array( 'phase' => 'title', 'chars' => self::char_length( $post->post_title ) ) );
				$phase_started = TimingLogger::start();
				$title_prompt = $additional_prompt;
				$title_hint   = 'This is a post title. Translate it concisely and keep a similar length to the original. Do not expand, elaborate, or add content.';
				$title_prompt = '' !== trim( $title_prompt ) ? $title_prompt . "\n\n" . $title_hint : $title_hint;
				$title = TranslationRuntime::translate_text( $post->post_title, $to, $from, $title_prompt );
				if ( is_wp_error( $title ) ) {
					TimingLogger::log( 'phase_end', array( 'phase' => 'title', 'duration_ms' => TimingLogger::stop( $phase_started ), 'ok' => false, 'reason' => $title->get_error_code() ) );
					self::log_job_end( $post_id, $job_started_at, false );
					return $title;
				}
				TimingLogger::log( 'phase_end', array( 'phase' => 'title', 'duration_ms' => TimingLogger::stop( $phase_started ), 'ok' => true ) );
				TranslationProgressTracker::complete_phase( 'title' );
			} else {
				$title = $post->post_title;
			}

			if ( TranslationProgressTracker::is_cancelled() ) {
				self::log_job_end( $post_id, $job_started_at, false, 'cancelled' );
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			// Translate content.
			if ( TranslationProgressTracker::has_content_progress() ) {
				TranslationProgressTracker::mark_phase( 'content' );
			}

			TimingLogger::log( 'phase_start', array( 'phase' => 'content', 'chars' => self::char_length( $post->post_content ) ) );
			$phase_started = TimingLogger::start();
			$content = ContentTranslator::translate_post_content( $post->post_content, $to, $from, $additional_prompt );
			if ( is_wp_error( $content ) ) {
				TimingLogger::log( 'phase_end', array( 'phase' => 'content', 'duration_ms' => TimingLogger::stop( $phase_started ), 'ok' => false, 'reason' => $content->get_error_code() ) );
				self::log_job_end( $post_id, $job_started_at, false );
				return $content;
			}
			TimingLogger::log( 'phase_end', array( 'phase' => 'content', 'duration_ms' => TimingLogger::stop( $phase_started ), 'ok' => true ) );

			// Fill any remaining content budget so the bar never stalls when
			// individual sub-paths (recursive inner-block translation, oversized
			// chunks falling back to verbatim copies) skipped their own
			// advance_units() call.
			TranslationProgressTracker::complete_phase( 'content' );

			if ( TranslationProgressTracker::is_cancelled() ) {
				self::log_job_end( $post_id, $job_started_at, false, 'cancelled' );
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			// Translate excerpt.
			TranslationProgressTracker::mark_phase( 'excerpt' );
			TimingLogger::log( 'phase_start', array( 'phase' => 'excerpt', 'chars' => self::char_length( $post->post_excerpt ) ) );
			$phase_started = TimingLogger::start();
			$excerpt = TranslationRuntime::translate_text( $post->post_excerpt, $to, $from, $additional_prompt );
			if ( is_wp_error( $excerpt ) ) {
				TimingLogger::log( 'phase_end', array( 'phase' => 'excerpt', 'duration_ms' => TimingLogger::stop( $phase_started ), 'ok' => false, 'reason' => $excerpt->get_error_code() ) );
				self::log_job_end( $post_id, $job_started_at, false );
				return $excerpt;
			}
			TimingLogger::log( 'phase_end', array( 'phase' => 'excerpt', 'duration_ms' => TimingLogger::stop( $phase_started ), 'ok' => true ) );

			TranslationProgressTracker::complete_phase( 'excerpt' );

			// Translate / clear meta.
			TranslationProgressTracker::mark_phase( 'meta' );
			TimingLogger::log( 'phase_start', array( 'phase' => 'meta' ) );
			$phase_started = TimingLogger::start();
			$processed_meta = MetaTranslationService::prepare_translation_meta( $post_id, $to, $from, $additional_prompt );
			if ( is_wp_error( $processed_meta ) ) {
				TimingLogger::log( 'phase_end', array( 'phase' => 'meta', 'duration_ms' => TimingLogger::stop( $phase_started ), 'ok' => false, 'reason' => $processed_meta->get_error_code() ) );
				self::log_job_end( $post_id, $job_started_at, false );
				return $processed_meta;
			}
			TimingLogger::log( 'phase_end', array( 'phase' => 'meta', 'duration_ms' => TimingLogger::stop( $phase_started ), 'ok' => true ) );

			TranslationProgressTracker::complete_phase( 'meta' );
			TranslationProgressTracker::mark_phase( 'saving' );

			TimingLogger::log( 'phase_start', array( 'phase' => 'saving' ) );
			$phase_started = TimingLogger::start();

			// Persist via adapter.
			$result = $adapter->create_translation( $post_id, $to, array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_status'  => $target_status,
				'meta'         => $processed_meta,
				'overwrite'    => $overwrite,
			) );

			$saving_ok = ! is_wp_error( $result );
			TimingLogger::log( 'phase_end', array(
				'phase'       => 'saving',
				'duration_ms' => TimingLogger::stop( $phase_started ),
				'ok'          => $saving_ok,
				'reason'      => $saving_ok ? '' : $result->get_error_code(),
			) );

			if ( $saving_ok ) {
				TranslationProgressTracker::complete_phase( 'saving' );
				TranslationProgressTracker::set_progress( 'done' );
			}

			self::log_job_end( $post_id, $job_started_at, $saving_ok );

			return $result;
		} finally {
			// Remove terminal progress state so a later retry cannot briefly
			// inherit the last percentage from this completed/failed job.
			TranslationProgressTracker::clear_progress( $post_id );
		}
	}

	private static function log_job_end( int $post_id, float $job_started_at, bool $ok, string $reason = '' ): void {
		$counters = TimingLogger::get_counters();
		TimingLogger::log( 'job_end', array(
			'post'        => $post_id,
			'total_ms'    => TimingLogger::stop( $job_started_at ),
			'ai_calls'    => $counters['ai_calls'] ?? 0,
			'retries'     => $counters['retries'] ?? 0,
			'fallbacks'   => $counters['fallbacks'] ?? 0,
			'ok'          => $ok,
			'reason'      => $reason,
		) );
	}

	/* ---------------------------------------------------------------
	 * Char-budget helpers used to size progress phases
	 * ------------------------------------------------------------- */

	/**
	 * Approximate the number of source characters that ContentTranslator will
	 * actually feed to the AI client. Skipped blocks (code, preformatted, …)
	 * are excluded so the budget reflects translatable work only.
	 */
	private static function estimate_content_translation_units( string $content ): int {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return 0;
		}

		if ( ! function_exists( 'parse_blocks' ) ) {
			return self::char_length( $content );
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || empty( $blocks ) ) {
			return self::char_length( $content );
		}

		$units = 0;
		foreach ( $blocks as $block ) {
			$units += self::estimate_block_units( $block );
		}

		return $units;
	}

	private static function estimate_block_units( array $block ): int {
		if ( TextSplitter::should_skip_block_translation( $block ) ) {
			return 0;
		}

		$units = 0;
		if ( function_exists( 'serialize_blocks' ) ) {
			$serialized = serialize_blocks( array( $block ) );
			if ( TextSplitter::should_translate_block_fragment( $serialized ) ) {
				$units = self::char_length( $serialized );
			}
		}

		return $units;
	}

	private static function estimate_meta_translation_units( int $post_id ): int {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return 0;
		}

		$meta = get_post_meta( $post_id );
		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return 0;
		}

		$translatable = MetaTranslationService::meta_translate( $post_id );
		if ( empty( $translatable ) ) {
			return 0;
		}

		$units = 0;
		foreach ( $translatable as $key ) {
			if ( ! isset( $meta[ $key ][0] ) ) {
				continue;
			}
			$value  = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $meta[ $key ][0] ) : $meta[ $key ][0];
			$units += self::sum_value_chars( $value );
		}

		return $units;
	}

	private static function sum_value_chars( $value ): int {
		if ( is_string( $value ) ) {
			return self::char_length( $value );
		}
		if ( is_array( $value ) ) {
			$total = 0;
			foreach ( $value as $item ) {
				$total += self::sum_value_chars( $item );
			}
			return $total;
		}
		return 0;
	}

	private static function char_length( $text ): int {
		if ( ! is_string( $text ) || '' === $text ) {
			return 0;
		}
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text, 'UTF-8' );
		}
		return strlen( $text );
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
