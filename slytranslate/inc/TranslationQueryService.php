<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Read model for translation: language lists, status queries, post enumeration,
 * and bulk source resolution.
 */
class TranslationQueryService {

	/* ---------------------------------------------------------------
	 * Execute callbacks (delegated from AI_Translate public methods)
	 * ------------------------------------------------------------- */

	public static function execute_get_languages(): mixed {
		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}
		$languages = $adapter->get_languages();
		$result    = array();
		foreach ( $languages as $code => $name ) {
			$result[] = array( 'code' => $code, 'name' => $name );
		}
		return $result;
	}

	public static function execute_get_translation_status( $input ): mixed {
		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'slytranslate' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden_post', __( 'You are not allowed to inspect this content item.', 'slytranslate' ) );
		}

		$post_type_check = self::validate_translatable_post_type( $post->post_type );
		if ( is_wp_error( $post_type_check ) ) {
			return $post_type_check;
		}

		$source_lang  = $adapter->get_post_language( $post_id );
		$translations = $adapter->get_post_translations( $post_id );
		$languages    = $adapter->get_languages();
		$is_single_entry_mode = $adapter instanceof WpMultilangAdapter;
		$source_title         = $post->post_title;

		if ( $is_single_entry_mode ) {
			$source_title = $adapter->get_language_variant( (string) $post->post_title, (string) $source_lang );
		}

		$status = array();
		foreach ( $languages as $code => $name ) {
			if ( $code === $source_lang ) {
				continue;
			}

			if ( $is_single_entry_mode ) {
				$status[] = array(
					'lang'        => (string) $code,
					'post_id'     => 0,
					'exists'      => isset( $translations[ $code ] ) && absint( $translations[ $code ] ) > 0,
					'title'       => '',
					'post_status' => '',
					'edit_link'   => '',
				);
				continue;
			}

			$status[] = self::build_translation_status_entry( $code, isset( $translations[ $code ] ) ? absint( $translations[ $code ] ) : 0 );
		}

		return array(
			'source_post_id'   => $post_id,
			'source_post_type' => $post->post_type,
			'source_title'     => $source_title,
			'source_language'  => $source_lang ?? '',
			'translations'     => $status,
			'single_entry_mode' => $is_single_entry_mode,
		);
	}

	public static function execute_get_untranslated( $input ): mixed {
		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}

		$target_language = sanitize_key( $input['target_language'] ?? '' );
		if ( '' === $target_language ) {
			return new \WP_Error( 'missing_target_language', __( 'Target language is required.', 'slytranslate' ) );
		}

		$post_type       = sanitize_key( $input['post_type'] ?? 'post' );
		$post_type_check = self::validate_translatable_post_type( $post_type );
		if ( is_wp_error( $post_type_check ) ) {
			return $post_type_check;
		}

		$limit    = self::normalize_limit( $input['limit'] ?? 20 );
		$post_ids = self::query_post_ids_by_type( $post_type, $limit * 3 );
		$items    = array();

		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$source_language = $adapter->get_post_language( $post_id ) ?? '';
			if ( '' !== $source_language && $source_language === $target_language ) {
				continue;
			}

			if ( self::get_existing_translation_id( $post_id, $target_language, $adapter ) > 0 ) {
				continue;
			}

			$items[] = self::build_source_post_summary( $post, $source_language );
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array(
			'post_type'       => $post_type,
			'target_language' => $target_language,
			'items'           => $items,
			'total'           => count( $items ),
		);
	}

	/* ---------------------------------------------------------------
	 * Status and summary builders
	 * ------------------------------------------------------------- */

	public static function build_translation_status_entry( string $language_code, int $translated_post_id ): array {
		$translated_post = $translated_post_id > 0 ? get_post( $translated_post_id ) : null;
		$can_access_post = $translated_post instanceof \WP_Post && self::current_user_can_access_post_details( $translated_post );

		return array(
			'lang'        => $language_code,
			'post_id'     => $can_access_post ? $translated_post->ID : 0,
			'exists'      => (bool) $translated_post,
			'title'       => $can_access_post ? $translated_post->post_title : '',
			'post_status' => $can_access_post ? $translated_post->post_status : '',
			'edit_link'   => $can_access_post ? (string) get_edit_post_link( $translated_post->ID, 'raw' ) : '',
		);
	}

	public static function build_source_post_summary( \WP_Post $post, string $source_language = '' ): array {
		return array(
			'post_id'         => $post->ID,
			'title'           => $post->post_title,
			'post_type'       => $post->post_type,
			'post_status'     => $post->post_status,
			'source_language' => $source_language,
			'edit_link'       => (string) get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	/* ---------------------------------------------------------------
	 * Translation lookup
	 * ------------------------------------------------------------- */

	public static function get_existing_translation_id(
		int $post_id,
		string $target_language,
		?TranslationPluginAdapter $adapter = null
	): int {
		if ( '' === $target_language ) {
			return 0;
		}

		$adapter = $adapter ?: AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return 0;
		}

		$translations       = $adapter->get_post_translations( $post_id );
		$translated_post_id = isset( $translations[ $target_language ] ) ? absint( $translations[ $target_language ] ) : 0;

		if ( $translated_post_id < 1 || false === get_post_status( $translated_post_id ) ) {
			return 0;
		}

		return $translated_post_id;
	}

	/* ---------------------------------------------------------------
	 * Bulk source resolution
	 * ------------------------------------------------------------- */

	public static function resolve_bulk_source_post_ids( array $input ): mixed {
		if ( ! empty( $input['post_ids'] ) && is_array( $input['post_ids'] ) ) {
			$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $input['post_ids'] ) ) ) );
			if ( ! empty( $post_ids ) ) {
				return array_slice( $post_ids, 0, 50 );
			}
		}

		$post_type = sanitize_key( $input['post_type'] ?? '' );
		if ( '' === $post_type ) {
			return new \WP_Error( 'missing_post_selection', __( 'Provide either post_ids or a post_type to translate in bulk.', 'slytranslate' ) );
		}

		$post_type_check = self::validate_translatable_post_type( $post_type );
		if ( is_wp_error( $post_type_check ) ) {
			return $post_type_check;
		}

		return self::query_post_ids_by_type( $post_type, self::normalize_limit( $input['limit'] ?? 20 ) );
	}

	public static function query_post_ids_by_type( string $post_type, int $limit ): array {
		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => self::get_queryable_source_post_statuses(),
			'posts_per_page'         => max( 1, $limit ),
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$adapter = AI_Translate::get_adapter();
		if ( $adapter instanceof PolylangAdapter ) {
			$query_args['lang'] = '';
		} elseif ( $adapter instanceof WpMultilangAdapter ) {
			$query_args['lang'] = 'all';
		}

		$post_ids = get_posts( $query_args );

		if ( ! is_array( $post_ids ) ) {
			return array();
		}

		return array_values( array_map( 'absint', $post_ids ) );
	}

	public static function normalize_limit( $limit ): int {
		$limit = absint( $limit );
		if ( $limit < 1 ) {
			$limit = 20;
		}
		return min( 50, $limit );
	}

	/* ---------------------------------------------------------------
	 * Post-type and post-status validation
	 * ------------------------------------------------------------- */

	public static function validate_translatable_post_type( string $post_type ): mixed {
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'The requested post type does not exist.', 'slytranslate' ) );
		}

		$adapter = AI_Translate::get_adapter();

		if ( $adapter instanceof PolylangAdapter && function_exists( 'pll_is_translated_post_type' ) && ! pll_is_translated_post_type( $post_type ) ) {
			return new \WP_Error(
				'post_type_not_translatable',
				sprintf(
					/* translators: %s: post type slug. */
					__( 'The post type "%s" is not enabled for translation in Polylang.', 'slytranslate' ),
					$post_type
				)
			);
		}

		if ( $adapter instanceof WpMultilangAdapter && function_exists( 'wpm_get_post_config' ) && null === wpm_get_post_config( $post_type ) ) {
			return new \WP_Error(
				'post_type_not_translatable',
				sprintf(
					/* translators: %s: post type slug. */
					__( 'The post type "%s" is not enabled for translation in WP Multilang.', 'slytranslate' ),
					$post_type
				)
			);
		}

		return true;
	}

	public static function is_registered_post_status( $post_status ): bool {
		if ( ! is_string( $post_status ) ) {
			return false;
		}

		$post_status = sanitize_key( $post_status );
		if ( '' === $post_status ) {
			return false;
		}

		if ( function_exists( 'post_status_exists' ) ) {
			return \post_status_exists( $post_status );
		}

		return null !== get_post_status_object( $post_status );
	}

	private static function get_queryable_source_post_statuses(): array {
		$post_statuses = apply_filters(
			'ai_translate_source_post_statuses',
			array( 'publish', 'draft', 'future', 'pending', 'private' )
		);

		if ( ! is_array( $post_statuses ) ) {
			$post_statuses = array( 'publish', 'draft', 'future', 'pending', 'private' );
		}

		$post_statuses = array_values(
			array_filter(
				array_unique( array_map( 'sanitize_key', $post_statuses ) ),
				static function ( $status ): bool {
					return self::is_registered_post_status( $status );
				}
			)
		);

		return ! empty( $post_statuses ) ? $post_statuses : array( 'publish' );
	}

	private static function current_user_can_access_post_details( \WP_Post $post ): bool {
		return current_user_can( 'edit_post', $post->ID ) || current_user_can( 'read_post', $post->ID );
	}
}
