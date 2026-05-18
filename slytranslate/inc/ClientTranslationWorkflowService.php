<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * MCP client-side translation workflow: prepare and apply abilities.
 *
 * Prepare: decompose a source post into labelled translation units the MCP
 * client can translate with its own LLM.
 * Apply:   accept translated units back and persist them through the active
 * adapter, mirroring what PostTranslationService does for server-side jobs.
 */
class ClientTranslationWorkflowService {

	/* ---------------------------------------------------------------
	 * Constants
	 * ------------------------------------------------------------- */

	/**
	 * Maximum total source characters for a single prepare-single call.
	 * Calls that exceed this should still succeed; the cap is only advisory.
	 */
	private const SINGLE_CHAR_BUDGET = 200000;

	/**
	 * Maximum total source characters across all units in a bulk prepare item.
	 * Items exceeding this are flagged too_large_for_bulk_prepare instead of
	 * being included.
	 */
	private const BULK_ITEM_CHAR_BUDGET = 100000;

	/* ---------------------------------------------------------------
	 * Public API: single-post workflow
	 * ------------------------------------------------------------- */

	/**
	 * Decompose one source post into labelled translation units.
	 *
	 * @param array $input Validated request parameters.
	 * @return array|\WP_Error
	 */
	public static function prepare_single( array $input ): mixed {
		$result = self::validate_job_input( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		[ $post, $adapter, $from, $to ] = $result;

		$overwrite   = ! empty( $input['overwrite'] );
		$post_status = isset( $input['post_status'] ) ? sanitize_key( $input['post_status'] ) : '';

		$existing_translation = TranslationQueryService::get_existing_translation_id( $post->ID, $to, $adapter );
		if ( $existing_translation > 0 && ! $overwrite ) {
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

		$all_meta     = is_callable( 'get_post_meta' ) ? get_post_meta( $post->ID ) : array();
		$all_meta     = is_array( $all_meta ) ? $all_meta : array();
		$units        = self::build_units_for_post( $post, $adapter, $from, $all_meta );
		$source_hash  = self::compute_source_hash( $post );
		$target_status = self::normalize_post_status_for_client( $post_status, $post );

		return array(
			'source_post_id'        => $post->ID,
			'source_language'       => $from,
			'target_language'       => $to,
			'single_entry_mode'     => AI_Translate::is_single_entry_translation_mode(),
			'overwrite'             => $overwrite,
			'post_status'           => $target_status,
			'existing_translation_id' => $existing_translation,
			'source_hash'           => $source_hash,
			'units'                 => $units,
		);
	}

	/**
	 * Persist translated units for one post.
	 *
	 * @param array $input Validated request parameters including 'translations'.
	 * @return array|\WP_Error
	 */
	public static function apply_single( array $input ): mixed {
		$result = self::validate_job_input( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		[ $post, $adapter, $from, $to ] = $result;

		$overwrite         = ! empty( $input['overwrite'] );
		$post_status       = isset( $input['post_status'] ) ? sanitize_key( $input['post_status'] ) : '';
		$source_hash       = isset( $input['source_hash'] ) ? sanitize_text_field( $input['source_hash'] ) : '';
		$allow_stale       = ! empty( $input['allow_stale_source'] );
		$translations_raw  = isset( $input['translations'] ) && is_array( $input['translations'] ) ? $input['translations'] : array();

		// Stale-source check.
		if ( '' !== $source_hash && ! $allow_stale ) {
			$current_hash = self::compute_source_hash( $post );
			if ( $current_hash !== $source_hash ) {
				return new \WP_Error(
					'stale_source',
					__( 'The source post has changed since the translation units were prepared. Set allow_stale_source=true to apply anyway.', 'slytranslate' )
				);
			}
		}

		// Index translations by unit id.
		$by_id = array();
		foreach ( $translations_raw as $item ) {
			if ( isset( $item['id'] ) && is_string( $item['id'] ) && isset( $item['translated'] ) ) {
				$by_id[ $item['id'] ] = $item['translated'];
			}
		}

		// Extract field translations.
		$translated_title   = isset( $by_id['title'] ) ? (string) $by_id['title'] : null;
		$translated_content = null;
		$translated_excerpt = isset( $by_id['excerpt'] ) ? (string) $by_id['excerpt'] : null;

		// Handle content: either a single 'content' unit, or string-table segments.
		$content_string_pairs = null;

		if ( $adapter instanceof StringTableContentAdapter && $adapter->supports_pretranslated_content_pairs() ) {
			// Collect seg_N pairs.
			$seg_pairs = array();
			$original_units = $adapter->build_content_translation_units( (string) $post->post_content );
			foreach ( $original_units as $unit ) {
				$seg_id = $unit['id'];
				if ( isset( $by_id[ $seg_id ] ) ) {
					$seg_pairs[] = array(
						'original'    => $unit['source'],
						'translated'  => (string) $by_id[ $seg_id ],
					);
				}
			}
			if ( ! empty( $seg_pairs ) ) {
				$content_string_pairs = $seg_pairs;
			}
		} else {
			$translated_content = isset( $by_id['content'] ) ? (string) $by_id['content'] : null;
		}

		// Collect translated meta.
		$translated_meta = MetaTranslationService::merge_translated_meta_units( array(), $by_id );

		// Build adapter data array.
		$data = array(
			'overwrite'   => $overwrite,
			'post_status' => self::normalize_post_status_for_client( $post_status, $post ),
			'meta'        => $translated_meta,
		);
		if ( null !== $translated_title ) {
			$data['post_title'] = $translated_title;
		}
		if ( null !== $translated_content ) {
			$data['post_content'] = $translated_content;
		}
		if ( null !== $translated_excerpt ) {
			$data['post_excerpt'] = $translated_excerpt;
		}
		if ( null !== $content_string_pairs ) {
			$data['content_string_pairs'] = $content_string_pairs;
		}

		$translated_post_id = $adapter->create_translation( $post->ID, $to, $data );

		if ( is_wp_error( $translated_post_id ) ) {
			return $translated_post_id;
		}

		$translated_post_id = absint( $translated_post_id );
		$translated_post    = get_post( $translated_post_id );
		$edit_link          = function_exists( 'get_edit_post_link' )
			? (string) get_edit_post_link( $translated_post_id, 'raw' )
			: '';

		return array(
			'translated_post_id'   => $translated_post_id,
			'source_post_id'       => $post->ID,
			'target_language'      => $to,
			'title'                => $translated_post ? (string) $translated_post->post_title : '',
			'translated_post_type' => $translated_post ? (string) $translated_post->post_type : '',
			'post_status'          => $translated_post ? (string) $translated_post->post_status : '',
			'edit_link'            => $edit_link,
		);
	}

	/* ---------------------------------------------------------------
	 * Public API: bulk workflow
	 * ------------------------------------------------------------- */

	/**
	 * Decompose multiple source posts into translation-unit packages.
	 *
	 * @param array $input Validated request parameters.
	 * @return array|\WP_Error
	 */
	public static function prepare_bulk( array $input ): mixed {
		$to      = isset( $input['target_language'] ) ? sanitize_key( $input['target_language'] ) : '';
		if ( '' === $to ) {
			return new \WP_Error( 'missing_target_language', __( 'target_language is required.', 'slytranslate' ) );
		}

		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}

		$source_ids = TranslationQueryService::resolve_bulk_source_post_ids( $input );
		if ( is_wp_error( $source_ids ) ) {
			return $source_ids;
		}

		$overwrite   = ! empty( $input['overwrite'] );
		$post_status = isset( $input['post_status'] ) ? sanitize_key( $input['post_status'] ) : '';

		$packages = array();
		foreach ( $source_ids as $post_id ) {
			$post_id = absint( $post_id );
			$post    = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				$packages[] = array(
					'source_post_id' => $post_id,
					'error'          => 'post_not_found_or_forbidden',
					'units'          => array(),
				);
				continue;
			}

			$from = sanitize_key( (string) ( $adapter->get_post_language( $post_id ) ?? 'en' ) );
			if ( '' === $from ) {
				$from = 'en';
			}

			$existing_translation = TranslationQueryService::get_existing_translation_id( $post_id, $to, $adapter );
			if ( $existing_translation > 0 && ! $overwrite ) {
				$packages[] = array(
					'source_post_id'          => $post_id,
					'existing_translation_id' => $existing_translation,
					'skipped'                 => true,
					'units'                   => array(),
				);
				continue;
			}

			$all_meta = is_callable( 'get_post_meta' ) ? get_post_meta( $post_id ) : array();
			$all_meta = is_array( $all_meta ) ? $all_meta : array();
			$units    = self::build_units_for_post( $post, $adapter, $from, $all_meta );

			$total_chars = 0;
			foreach ( $units as $unit ) {
				$total_chars += strlen( $unit['source'] );
			}

			if ( $total_chars > self::BULK_ITEM_CHAR_BUDGET ) {
				$packages[] = array(
					'source_post_id'           => $post_id,
					'too_large_for_bulk_prepare' => true,
					'total_chars'              => $total_chars,
					'units'                    => array(),
				);
				continue;
			}

			$target_status = self::normalize_post_status_for_client( $post_status, $post );

			$packages[] = array(
				'source_post_id'          => $post_id,
				'source_language'         => $from,
				'target_language'         => $to,
				'single_entry_mode'       => AI_Translate::is_single_entry_translation_mode(),
				'overwrite'               => $overwrite,
				'post_status'             => $target_status,
				'existing_translation_id' => $existing_translation,
				'source_hash'             => self::compute_source_hash( $post ),
				'units'                   => $units,
			);
		}

		return array(
			'target_language' => $to,
			'total'           => count( $packages ),
			'packages'        => $packages,
		);
	}

	/**
	 * Apply translated unit packages for multiple posts.
	 *
	 * @param array $input Validated request parameters including 'packages'.
	 * @return array|\WP_Error
	 */
	public static function apply_bulk( array $input ): mixed {
		$packages_raw = isset( $input['packages'] ) && is_array( $input['packages'] ) ? $input['packages'] : array();
		if ( empty( $packages_raw ) ) {
			return new \WP_Error( 'missing_packages', __( 'packages is required and must be a non-empty array.', 'slytranslate' ) );
		}

		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}

		$results   = array();
		$succeeded = 0;
		$failed    = 0;
		$skipped   = 0;

		foreach ( $packages_raw as $pkg ) {
			if ( ! is_array( $pkg ) ) {
				continue;
			}
			$apply_result = self::apply_single( $pkg );
			$post_id      = isset( $pkg['source_post_id'] ) ? absint( $pkg['source_post_id'] ) : 0;

			if ( is_wp_error( $apply_result ) ) {
				$error_code = $apply_result->get_error_code();
				if ( 'translation_exists' === $error_code ) {
					$results[] = array(
						'status'         => 'skipped',
						'source_post_id' => $post_id,
						'translated_post_id' => 0,
						'error'          => $apply_result->get_error_message(),
						'edit_link'      => '',
					);
					++$skipped;
				} else {
					$results[] = array(
						'status'         => 'failed',
						'source_post_id' => $post_id,
						'translated_post_id' => 0,
						'error'          => $apply_result->get_error_message(),
						'edit_link'      => '',
					);
					++$failed;
				}
			} else {
				$results[] = array(
					'status'             => 'success',
					'source_post_id'     => $post_id,
					'translated_post_id' => $apply_result['translated_post_id'],
					'error'              => '',
					'edit_link'          => $apply_result['edit_link'],
				);
				++$succeeded;
			}
		}

		return array(
			'results'   => $results,
			'total'     => count( $results ),
			'succeeded' => $succeeded,
			'failed'    => $failed,
			'skipped'   => $skipped,
		);
	}

	/* ---------------------------------------------------------------
	 * Private helpers
	 * ------------------------------------------------------------- */

	/**
	 * Validate shared job input and return [post, adapter, from, to] or WP_Error.
	 *
	 * @return array{\WP_Post, TranslationPluginAdapter, string, string}|\WP_Error
	 */
	private static function validate_job_input( array $input ): mixed {
		$post_id = isset( $input['source_post_id'] ) ? absint( $input['source_post_id'] ) : 0;
		if ( $post_id < 1 ) {
			return new \WP_Error( 'missing_source_post_id', __( 'source_post_id is required.', 'slytranslate' ) );
		}

		$to = isset( $input['target_language'] ) ? sanitize_key( $input['target_language'] ) : '';
		if ( '' === $to ) {
			return new \WP_Error( 'missing_target_language', __( 'target_language is required.', 'slytranslate' ) );
		}

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

		$from = sanitize_key( (string) ( $adapter->get_post_language( $post_id ) ?? 'en' ) );
		if ( '' === $from ) {
			$from = 'en';
		}

		// Support source variant override for single-entry adapters.
		if ( $adapter instanceof WpMultilangAdapter || $adapter instanceof WpglobusAdapter ) {
			$requested_from = isset( $input['source_language'] ) ? sanitize_key( $input['source_language'] ) : '';
			if ( '' !== $requested_from ) {
				$available_languages = $adapter->get_languages();
				if ( isset( $available_languages[ $requested_from ] ) ) {
					$from = $requested_from;
				}
			}
			// Extract the correct variant from the inline-markup content.
			$post->post_title   = $adapter->get_language_variant( (string) $post->post_title, $from );
			$post->post_content = $adapter->get_language_variant( (string) $post->post_content, $from );
			$post->post_excerpt = $adapter->get_language_variant( (string) $post->post_excerpt, $from );
		}

		if ( $from === $to ) {
			return new \WP_Error( 'same_language', __( 'Source and target languages must be different.', 'slytranslate' ) );
		}

		return array( $post, $adapter, $from, $to );
	}

	/**
	 * Build all translation units for a post: title, content or string-table
	 * segments, excerpt, and translatable meta fields.
	 *
	 * @param \WP_Post                $post     Source post (content already variant-extracted for single-entry).
	 * @param TranslationPluginAdapter $adapter  Active adapter.
	 * @param string                  $from     Source language code.
	 * @param array                   $all_meta Raw get_post_meta() result.
	 * @return array Translation units.
	 */
	private static function build_units_for_post(
		\WP_Post $post,
		TranslationPluginAdapter $adapter,
		string $from,
		array $all_meta
	): array {
		$units = array();

		// Title unit.
		if ( '' !== trim( (string) $post->post_title ) ) {
			$units[] = array(
				'id'          => 'title',
				'field'       => 'title',
				'source'      => (string) $post->post_title,
				'format'      => 'plain_text',
				'lookup_keys' => array(),
			);
		}

		// Content: string-table segments or single HTML unit.
		if ( $adapter instanceof StringTableContentAdapter && $adapter->supports_pretranslated_content_pairs() ) {
			$seg_units = $adapter->build_content_translation_units( (string) $post->post_content );
			foreach ( $seg_units as $idx => $seg ) {
				$units[] = array(
					'id'          => 'seg_' . $idx,
					'field'       => 'string_table_segment',
					'source'      => $seg['source'],
					'format'      => 'plain_text',
					'lookup_keys' => $seg['lookup_keys'] ?? array(),
				);
			}
		} elseif ( '' !== trim( (string) $post->post_content ) ) {
			$units[] = array(
				'id'          => 'content',
				'field'       => 'content',
				'source'      => (string) $post->post_content,
				'format'      => 'html',
				'lookup_keys' => array(),
			);
		}

		// Excerpt unit.
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			$units[] = array(
				'id'          => 'excerpt',
				'field'       => 'excerpt',
				'source'      => (string) $post->post_excerpt,
				'format'      => 'plain_text',
				'lookup_keys' => array(),
			);
		}

		// Meta units.
		$meta_units = MetaTranslationService::build_meta_units( $post->ID, $all_meta );
		foreach ( $meta_units as $meta_unit ) {
			$units[] = $meta_unit;
		}

		return $units;
	}

	/**
	 * Compute a source hash over title, content, and excerpt to detect
	 * source changes between prepare and apply calls.
	 */
	private static function compute_source_hash( \WP_Post $post ): string {
		return md5( $post->post_title . "\x00" . $post->post_content . "\x00" . $post->post_excerpt );
	}

	/**
	 * Normalise the desired post status for translated content.
	 *
	 * Mirrors PostTranslationService::normalize_post_status() which is private.
	 */
	private static function normalize_post_status_for_client( string $status, \WP_Post $post ): string {
		if ( '' !== $status ) {
			$status = sanitize_key( $status );
			if ( TranslationQueryService::is_registered_post_status( $status )
				&& ! in_array( $status, array( 'auto-draft', 'inherit', 'trash' ), true )
			) {
				return $status;
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
