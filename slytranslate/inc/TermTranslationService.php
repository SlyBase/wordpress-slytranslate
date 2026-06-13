<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * AI translation of taxonomy terms for sibling-post language plugins (Polylang).
 *
 * Translates a term's name and description, creates the target-language term,
 * sets its language, and links it to the source term. Opt-in via the
 * slytranslate_translate_terms option because it performs write operations
 * outside the post being translated.
 */
class TermTranslationService {

	public static function is_enabled(): bool {
		return get_option( 'slytranslate_translate_terms', '0' ) === '1';
	}

	/**
	 * Whether the active language plugin exposes the term-translation API.
	 *
	 * String-table adapters (TranslatePress/WPGlobus/WP Multilang) are not
	 * affected: their string-table path already translates term names and the
	 * single-post model has no term duplicates.
	 */
	public static function is_term_translation_supported(): bool {
		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter instanceof PolylangAdapter ) {
			return false;
		}

		return function_exists( 'pll_get_term' )
			&& function_exists( 'pll_set_term_language' )
			&& function_exists( 'pll_save_term_translations' );
	}

	/**
	 * Translate one term into the target language and link the translation.
	 *
	 * Returns the existing target-language term when one is already linked.
	 *
	 * @param int    $term_id Source term ID.
	 * @param string $to      Target language code.
	 * @param string $from    Source language code.
	 * @return int|\WP_Error Target-language term ID.
	 */
	public static function translate_term( int $term_id, string $to, string $from ): int|\WP_Error {
		if ( ! self::is_term_translation_supported() ) {
			return new \WP_Error( 'term_translation_unsupported', __( 'The active translation plugin does not support term translation.', 'slytranslate' ) );
		}

		$existing = absint( \pll_get_term( $term_id, $to ) );
		if ( $existing > 0 ) {
			return $existing;
		}

		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'term_not_found', __( 'Source term not found.', 'slytranslate' ) );
		}

		if ( function_exists( 'pll_is_translated_taxonomy' ) && ! \pll_is_translated_taxonomy( $term->taxonomy ) ) {
			return new \WP_Error( 'taxonomy_not_translated', __( 'The taxonomy is not managed by the language plugin.', 'slytranslate' ) );
		}

		$name_hint       = 'This is a taxonomy term name (category, tag, or similar). Translate it concisely and return only the translated name.';
		$translated_name = TranslationRuntime::translate_text( (string) $term->name, $to, $from, $name_hint );
		if ( is_wp_error( $translated_name ) ) {
			return $translated_name;
		}
		$translated_name = trim( (string) $translated_name );
		if ( '' === $translated_name ) {
			$translated_name = (string) $term->name;
		}

		$translated_description = '';
		if ( '' !== trim( (string) $term->description ) ) {
			$translated_description = TranslationRuntime::translate_text( (string) $term->description, $to, $from, '' );
			if ( is_wp_error( $translated_description ) ) {
				// Description is secondary — keep the term creation alive.
				$translated_description = (string) $term->description;
			}
		}

		$inserted = wp_insert_term(
			$translated_name,
			$term->taxonomy,
			array(
				'description' => (string) $translated_description,
				'slug'        => sanitize_title( $translated_name ),
			)
		);

		if ( is_wp_error( $inserted ) ) {
			// A term with the translated name already exists in this taxonomy —
			// reuse it instead of failing the whole post translation.
			$existing_id = absint( $inserted->get_error_data( 'term_exists' ) );
			if ( $existing_id < 1 ) {
				return $inserted;
			}
			$new_term_id = $existing_id;
		} else {
			$new_term_id = absint( $inserted['term_id'] ?? 0 );
		}

		if ( $new_term_id < 1 ) {
			return new \WP_Error( 'term_insert_failed', __( 'The translated term could not be created.', 'slytranslate' ) );
		}

		\pll_set_term_language( $new_term_id, $to );

		$translations = function_exists( 'pll_get_term_translations' ) ? \pll_get_term_translations( $term_id ) : array();
		$translations = is_array( $translations ) ? $translations : array();
		$translations[ $from ] = $term_id;
		$translations[ $to ]   = $new_term_id;
		\pll_save_term_translations( $translations );

		TimingLogger::log( 'term_translated', array(
			'term'     => $term_id,
			'new_term' => $new_term_id,
			'taxonomy' => (string) $term->taxonomy,
			'from'     => $from,
			'to'       => $to,
		) );

		return $new_term_id;
	}

	/**
	 * Execute callback for the ai-translate/translate-terms ability.
	 *
	 * Bulk-translates terms of one taxonomy for existing sites. With dry_run
	 * only reports which terms would be translated.
	 *
	 * @param array $input taxonomy, target_language, optional term_ids, source_language, dry_run.
	 * @return array|\WP_Error
	 */
	public static function execute_translate_terms( $input ): array|\WP_Error {
		$input = is_array( $input ) ? $input : array();

		if ( ! self::is_term_translation_supported() ) {
			return new \WP_Error( 'term_translation_unsupported', __( 'The active translation plugin does not support term translation.', 'slytranslate' ) );
		}

		$taxonomy = isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
		if ( '' === $taxonomy ) {
			return new \WP_Error( 'missing_taxonomy', __( 'A taxonomy is required.', 'slytranslate' ) );
		}

		$to = isset( $input['target_language'] ) && is_string( $input['target_language'] ) ? sanitize_key( $input['target_language'] ) : '';
		if ( '' === $to ) {
			return new \WP_Error( 'missing_target_language', __( 'Target language is required.', 'slytranslate' ) );
		}

		if ( function_exists( 'pll_is_translated_taxonomy' ) && ! \pll_is_translated_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'taxonomy_not_translated', __( 'The taxonomy is not managed by the language plugin.', 'slytranslate' ) );
		}

		$from = isset( $input['source_language'] ) && is_string( $input['source_language'] ) ? sanitize_key( $input['source_language'] ) : '';
		if ( '' === $from && function_exists( 'pll_default_language' ) ) {
			$from = (string) \pll_default_language();
		}
		if ( '' === $from || $from === $to ) {
			return new \WP_Error( 'invalid_source_language', __( 'A source language different from the target language is required.', 'slytranslate' ) );
		}

		$dry_run = ! empty( $input['dry_run'] );

		$term_ids = array();
		if ( isset( $input['term_ids'] ) && is_array( $input['term_ids'] ) ) {
			foreach ( $input['term_ids'] as $term_id ) {
				$term_id = absint( $term_id );
				if ( $term_id > 0 ) {
					$term_ids[] = $term_id;
				}
			}
		} else {
			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'lang'       => $from,
			) );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
			$term_ids = array_map( 'absint', is_array( $terms ) ? $terms : array() );
		}

		return TranslationRuntime::with_model_slug_override(
			$input,
			static function () use ( $term_ids, $to, $from, $dry_run ) {
				$results   = array();
				$succeeded = 0;
				$failed    = 0;
				$skipped   = 0;

				foreach ( $term_ids as $term_id ) {
					$existing = absint( \pll_get_term( $term_id, $to ) );
					if ( $existing > 0 ) {
						$skipped++;
						$results[] = array( 'term_id' => $term_id, 'translated_term_id' => $existing, 'status' => 'skipped', 'error' => null );
						continue;
					}

					if ( $dry_run ) {
						$results[] = array( 'term_id' => $term_id, 'translated_term_id' => 0, 'status' => 'would_translate', 'error' => null );
						continue;
					}

					$translated = self::translate_term( $term_id, $to, $from );
					if ( is_wp_error( $translated ) ) {
						$failed++;
						$results[] = array( 'term_id' => $term_id, 'translated_term_id' => 0, 'status' => 'failed', 'error' => $translated->get_error_message() );
						continue;
					}

					$succeeded++;
					$results[] = array( 'term_id' => $term_id, 'translated_term_id' => $translated, 'status' => 'success', 'error' => null );
				}

				return array(
					'results'   => $results,
					'total'     => count( $term_ids ),
					'succeeded' => $succeeded,
					'failed'    => $failed,
					'skipped'   => $skipped,
					'dry_run'   => $dry_run,
				);
			}
		);
	}
}
