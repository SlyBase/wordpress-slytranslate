<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Mutation use-case for changing a post language through optional adapter write-capabilities.
 */
class LanguageMutationService {

	public static function execute_set_post_language( $input ): mixed {
		$input = is_array( $input ) ? $input : array();

		$post_id = self::require_positive_int_input(
			$input,
			'post_id',
			'invalid_post_id',
			__( 'A valid post ID is required.', 'slytranslate' )
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$target_language = self::require_language_code_input(
			$input,
			'target_language',
			'missing_target_language',
			__( 'Target language is required.', 'slytranslate' )
		);
		if ( is_wp_error( $target_language ) ) {
			return $target_language;
		}

		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}

		$mutation_adapter = self::resolve_mutation_adapter( $adapter );
		if ( is_wp_error( $mutation_adapter ) ) {
			return $mutation_adapter;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_post_id', __( 'A valid post ID is required.', 'slytranslate' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden_post', __( 'You are not allowed to change this content item.', 'slytranslate' ) );
		}

		$post_type_check = TranslationQueryService::validate_translatable_post_type( $post->post_type );
		if ( is_wp_error( $post_type_check ) ) {
			return $post_type_check;
		}

		$languages = $adapter->get_languages();
		if ( ! is_array( $languages ) || ! array_key_exists( $target_language, $languages ) ) {
			return new \WP_Error( 'invalid_target_language', __( 'The requested target language is not available.', 'slytranslate' ) );
		}

		$source_language = $adapter->get_post_language( $post_id ) ?? '';
		if ( '' !== $source_language && $source_language === $target_language ) {
			return new \WP_Error( 'language_already_set', __( 'The content item already uses the requested language.', 'slytranslate' ) );
		}

		$force          = ! empty( $input['force'] );
		$should_relink  = ! empty( $input['relink'] );
		$translations   = self::normalize_translation_map( $adapter->get_post_translations( $post_id ) );
		$conflict_post  = self::detect_conflict_post_id( $translations, $target_language, $post_id );

		if ( $conflict_post > 0 && ! $force ) {
			return new \WP_Error(
				'language_conflict',
				sprintf(
					/* translators: %d: conflicting post ID. */
					__( 'Another translation already uses this target language (post %d). Use force to override.', 'slytranslate' ),
					$conflict_post
				)
			);
		}

		if ( $should_relink && ! $mutation_adapter->supports_mutation_capability( TranslationMutationAdapter::CAPABILITY_RELINK_TRANSLATION ) ) {
			return new \WP_Error( 'unsupported_language_mutation', __( 'The active translation plugin does not support translation relinking.', 'slytranslate' ) );
		}

		$set_result = $mutation_adapter->set_post_language( $post_id, $target_language );
		if ( is_wp_error( $set_result ) ) {
			return $set_result;
		}
		if ( false === $set_result ) {
			return new \WP_Error( 'polylang_update_failed', __( 'The translation plugin could not update the post language.', 'slytranslate' ) );
		}

		if ( $should_relink ) {
			$relink_map    = self::build_relink_map( $translations, $post_id, $target_language );
			$relink_result = $mutation_adapter->relink_post_translations( $relink_map );
			if ( is_wp_error( $relink_result ) ) {
				return $relink_result;
			}
			if ( false === $relink_result ) {
				return new \WP_Error( 'polylang_update_failed', __( 'The translation plugin could not rewrite translation links.', 'slytranslate' ) );
			}
		}

		$current_language = $adapter->get_post_language( $post_id ) ?? '';
		if ( '' === $current_language ) {
			$current_language = $target_language;
		}

		return array(
			'post_id'         => $post_id,
			'source_language' => $source_language,
			'target_language' => $current_language,
			'translations'    => self::normalize_translation_map( $adapter->get_post_translations( $post_id ) ),
			'changed'         => $source_language !== $current_language,
			'edit_link'       => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}

	private static function resolve_mutation_adapter( TranslationPluginAdapter $adapter ): mixed {
		if ( ! ( $adapter instanceof TranslationMutationAdapter ) ) {
			return new \WP_Error( 'unsupported_language_mutation', __( 'The active translation plugin does not support post-language mutation.', 'slytranslate' ) );
		}

		if ( ! $adapter->supports_mutation_capability( TranslationMutationAdapter::CAPABILITY_SET_POST_LANGUAGE ) ) {
			return new \WP_Error( 'unsupported_language_mutation', __( 'The active translation plugin does not support post-language mutation.', 'slytranslate' ) );
		}

		return $adapter;
	}

	private static function require_positive_int_input( array $input, string $key, string $error_code, string $message ) {
		if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) {
			return new \WP_Error( $error_code, $message );
		}

		$value = absint( $input[ $key ] );
		if ( $value < 1 ) {
			return new \WP_Error( $error_code, $message );
		}

		return $value;
	}

	private static function require_language_code_input( array $input, string $key, string $error_code, string $message ) {
		if ( ! array_key_exists( $key, $input ) || ! is_string( $input[ $key ] ) ) {
			return new \WP_Error( $error_code, $message );
		}

		$language_code = sanitize_key( trim( $input[ $key ] ) );
		if ( '' === $language_code ) {
			return new \WP_Error( $error_code, $message );
		}

		return $language_code;
	}

	/**
	 * @param array<string, mixed> $translations
	 * @return array<string, int>
	 */
	private static function normalize_translation_map( array $translations ): array {
		$normalized = array();
		foreach ( $translations as $language_code => $translated_post_id ) {
			$code = sanitize_key( (string) $language_code );
			$id   = absint( $translated_post_id );
			if ( '' === $code || $id < 1 ) {
				continue;
			}
			if ( false === get_post_status( $id ) ) {
				continue;
			}
			$normalized[ $code ] = $id;
		}

		return $normalized;
	}

	/**
	 * @param array<string, int> $translations
	 */
	private static function detect_conflict_post_id( array $translations, string $target_language, int $post_id ): int {
		if ( ! isset( $translations[ $target_language ] ) ) {
			return 0;
		}

		$translated_post_id = absint( $translations[ $target_language ] );
		if ( $translated_post_id < 1 || $translated_post_id === $post_id ) {
			return 0;
		}

		return false !== get_post_status( $translated_post_id ) ? $translated_post_id : 0;
	}

	/**
	 * @param array<string, int> $translations
	 * @return array<string, int>
	 */
	private static function build_relink_map( array $translations, int $post_id, string $target_language ): array {
		$relink_map = array();
		foreach ( $translations as $language_code => $translated_post_id ) {
			if ( $translated_post_id === $post_id ) {
				continue;
			}
			$relink_map[ $language_code ] = $translated_post_id;
		}

		$relink_map[ $target_language ] = $post_id;

		return $relink_map;
	}
}
