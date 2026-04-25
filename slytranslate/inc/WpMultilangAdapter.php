<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

class WpMultilangAdapter implements TranslationPluginAdapter {

	public function is_available(): bool {
		return defined( 'WPM_PLUGIN_FILE' )
			&& function_exists( 'wpm_get_languages' )
			&& function_exists( 'wpm_get_default_language' )
			&& function_exists( 'wpm_string_to_ml_array' )
			&& function_exists( 'wpm_ml_array_to_string' );
	}

	public function get_languages(): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		$wp_multilang_languages = wpm_get_languages();
		if ( ! is_array( $wp_multilang_languages ) ) {
			return array();
		}

		$result = array();
		foreach ( $wp_multilang_languages as $code => $language_data ) {
			$language_code = sanitize_key( (string) $code );
			if ( '' === $language_code ) {
				continue;
			}

			$name = $language_code;
			if ( is_array( $language_data ) ) {
				$language_name = $language_data['name'] ?? '';
				if ( is_string( $language_name ) && '' !== trim( $language_name ) ) {
					$name = $language_name;
				}
			}

			$result[ $language_code ] = $name;
		}

		return $result;
	}

	public function get_post_language( int $post_id ): ?string {
		if ( $this->is_available() && function_exists( 'wpm_get_language' ) ) {
			$current_language = sanitize_key( (string) wpm_get_language() );
			$languages        = $this->get_languages();

			if ( '' !== $current_language && isset( $languages[ $current_language ] ) ) {
				return $current_language;
			}
		}

		$default_language = $this->get_default_language_code();
		return '' !== $default_language ? $default_language : null;
	}

	public function get_language_variant( string $value, string $language_code ): string {
		$language_code = sanitize_key( $language_code );
		if ( '' === $language_code || '' === $value ) {
			return '';
		}

		$decoded = wpm_string_to_ml_array( $value );
		if ( ! is_array( $decoded ) ) {
			$default_language = $this->get_default_language_code();
			return $language_code === $default_language ? $value : '';
		}

		$normalized = $this->normalize_language_map( $decoded );
		if ( isset( $normalized[ $language_code ] ) && '' !== $normalized[ $language_code ] ) {
			return $normalized[ $language_code ];
		}

		return '';
	}

	public function get_post_translations( int $post_id ): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$languages = array_keys( $this->get_languages() );
		if ( empty( $languages ) ) {
			return array();
		}

		$default_language = $this->get_default_language_code();
		$translations     = array();

		foreach ( $languages as $language_code ) {
			if ( ! is_string( $language_code ) || '' === $language_code ) {
				continue;
			}

			$content_variant = $this->extract_language_value( (string) $post->post_content, $language_code, $default_language );

			if ( '' !== trim( $content_variant ) ) {
				$translations[ $language_code ] = $post_id;
			}
		}

		return $translations;
	}

	public function create_translation( int $source_post_id, string $target_lang, array $data ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error( 'wp_multilang_not_available', __( 'WP Multilang is not active.', 'slytranslate' ) );
		}

		$post = get_post( $source_post_id );
		if ( ! $post ) {
			return new \WP_Error( 'source_post_not_found', __( 'Source post not found.', 'slytranslate' ) );
		}

		$target_lang = sanitize_key( $target_lang );
		if ( '' === $target_lang ) {
			return new \WP_Error( 'invalid_target_language', __( 'Target language is required.', 'slytranslate' ) );
		}

		$available_languages = $this->get_languages();
		if ( ! isset( $available_languages[ $target_lang ] ) ) {
			return new \WP_Error( 'invalid_target_language', __( 'The requested target language is not available.', 'slytranslate' ) );
		}

		$translations = $this->get_post_translations( $source_post_id );
		$overwrite    = ! empty( $data['overwrite'] );

		if ( isset( $translations[ $target_lang ] ) && ! $overwrite ) {
			return new \WP_Error(
				'translation_exists',
				sprintf(
					/* translators: 1: language code, 2: post ID. */
					__( 'A translation for language "%1$s" already exists (post %2$d).', 'slytranslate' ),
					$target_lang,
					$source_post_id
				)
			);
		}

		$source_language = '';
		if ( isset( $data['source_language'] ) ) {
			$requested_source_language = sanitize_key( (string) $data['source_language'] );
			if ( '' !== $requested_source_language && isset( $available_languages[ $requested_source_language ] ) ) {
				$source_language = $requested_source_language;
			}
		}
		if ( '' === $source_language ) {
			$source_language = $this->get_default_language_code();
		}
		$source_title    = $this->get_language_variant( (string) $post->post_title, $source_language );
		$source_content  = $this->get_language_variant( (string) $post->post_content, $source_language );
		$source_excerpt  = $this->get_language_variant( (string) $post->post_excerpt, $source_language );
		$update_data     = array( 'ID' => $source_post_id );

		if ( isset( $data['post_title'] ) ) {
			$update_data['post_title'] = $this->merge_language_value(
				(string) $post->post_title,
				$source_language,
				$target_lang,
				sanitize_text_field( (string) $data['post_title'] ),
				$source_title
			);
		}

		if ( isset( $data['post_content'] ) ) {
			$update_data['post_content'] = $this->merge_language_value(
				(string) $post->post_content,
				$source_language,
				$target_lang,
				(string) $data['post_content'],
				$source_content
			);
		}

		if ( isset( $data['post_excerpt'] ) ) {
			$update_data['post_excerpt'] = $this->merge_language_value(
				(string) $post->post_excerpt,
				$source_language,
				$target_lang,
				(string) $data['post_excerpt'],
				$source_excerpt
			);
		}

		$result = wp_update_post( wp_slash( $update_data ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $key => $value ) {
				$meta_key = is_string( $key ) ? $key : '';
				if ( '' === $meta_key ) {
					continue;
				}

				if ( is_string( $value ) ) {
					$existing_meta = get_post_meta( $source_post_id, $meta_key, true );
					$existing_meta = is_string( $existing_meta ) ? $existing_meta : '';

					update_post_meta(
						$source_post_id,
						$meta_key,
						$this->merge_language_value(
							$existing_meta,
							$source_language,
							$target_lang,
							sanitize_text_field( $value ),
							$existing_meta
						)
					);
					continue;
				}

				update_post_meta( $source_post_id, $meta_key, $value );
			}
		}

		$this->ensure_post_languages_meta( $source_post_id, $source_language, $target_lang );

		return $source_post_id;
	}

	public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
		// WP Multilang stores language variants in one post entry; no explicit
		// cross-post relationship is required.
		return true;
	}

	private function get_default_language_code(): string {
		if ( ! $this->is_available() ) {
			return '';
		}

		$default_language = sanitize_key( (string) wpm_get_default_language() );
		return '' !== $default_language ? $default_language : '';
	}

	private function extract_language_value( string $value, string $language_code, string $default_language ): string {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		$decoded = wpm_string_to_ml_array( $value );
		if ( is_array( $decoded ) ) {
			$normalized = $this->normalize_language_map( $decoded );
			if ( isset( $normalized[ $language_code ] ) ) {
				return $normalized[ $language_code ];
			}

			if ( '' !== $default_language && isset( $normalized[ $default_language ] ) ) {
				return $language_code === $default_language ? $normalized[ $default_language ] : '';
			}
		}

		return $language_code === $default_language ? $value : '';
	}

	private function merge_language_value( string $existing_value, string $source_language, string $target_language, string $target_value, string $source_fallback ): string {
		$source_language = sanitize_key( $source_language );
		$target_language = sanitize_key( $target_language );
		if ( '' === $target_language ) {
			return $existing_value;
		}

		$decoded = wpm_string_to_ml_array( $existing_value );
		$map     = is_array( $decoded ) ? $this->normalize_language_map( $decoded ) : array();

		if ( '' !== $source_language && ! array_key_exists( $source_language, $map ) ) {
			$map[ $source_language ] = $source_fallback;
		}

		$map[ $target_language ] = $target_value;

		return $this->encode_language_map( $map );
	}

	/**
	 * @param array<string, mixed> $value
	 * @return array<string, string>
	 */
	private function normalize_language_map( array $value ): array {
		$normalized = array();
		foreach ( $value as $language_code => $language_value ) {
			$code = sanitize_key( (string) $language_code );
			if ( '' === $code ) {
				continue;
			}
			$normalized[ $code ] = is_scalar( $language_value ) ? (string) $language_value : '';
		}

		return $normalized;
	}

	/**
	 * @param array<string, string> $value
	 */
	private function encode_language_map( array $value ): string {
		$encoded = wpm_ml_array_to_string( $value );
		if ( is_string( $encoded ) && '' !== $encoded ) {
			return $encoded;
		}

		$fallback = '';
		foreach ( $value as $language_code => $language_value ) {
			$code = sanitize_key( (string) $language_code );
			if ( '' === $code || '' === $language_value ) {
				continue;
			}
			$fallback .= '[:' . $code . ']' . $language_value;
		}

		return '' !== $fallback ? $fallback . '[:]' : '';
	}

	private function ensure_post_languages_meta( int $post_id, string $source_language, string $target_language ): void {
		$stored_languages = get_post_meta( $post_id, '_languages', true );
		$stored_languages = is_array( $stored_languages ) ? $stored_languages : array();

		$normalized = array();
		foreach ( $stored_languages as $stored_language ) {
			$code = sanitize_key( (string) $stored_language );
			if ( '' !== $code ) {
				$normalized[] = $code;
			}
		}

		$source_language = sanitize_key( $source_language );
		$target_language = sanitize_key( $target_language );

		if ( '' !== $source_language ) {
			$normalized[] = $source_language;
		}
		if ( '' !== $target_language ) {
			$normalized[] = $target_language;
		}

		$normalized = array_values( array_unique( $normalized ) );
		update_post_meta( $post_id, '_languages', $normalized );
	}
}
