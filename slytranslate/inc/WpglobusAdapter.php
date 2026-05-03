<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

class WpglobusAdapter implements TranslationPluginAdapter {

	public function is_available(): bool {
		return class_exists( 'WPGlobus', false ) || function_exists( 'wpglobus_current_language' );
	}

	public function get_languages(): array {
		$language_codes = $this->get_language_codes();
		if ( empty( $language_codes ) ) {
			return array();
		}

		$result = array();
		foreach ( $language_codes as $code ) {
			$language_code = sanitize_key( (string) $code );
			if ( '' === $language_code ) {
				continue;
			}
			$result[ $language_code ] = $this->resolve_language_name( $language_code );
		}

		return $result;
	}

	public function get_post_language( int $post_id ): ?string {
		$default_language = $this->get_default_language_code();
		return '' !== $default_language ? $default_language : null;
	}

	public function get_language_variant( string $value, string $language_code ): string {
		$language_code = sanitize_key( $language_code );
		if ( '' === $language_code || '' === $value ) {
			return '';
		}

		if ( ! $this->has_wpglobus_markup( $value ) ) {
			$default_language = $this->get_default_language_code();
			return $language_code === $default_language ? $value : '';
		}

		$pattern = '/\[\:' . preg_quote( $language_code, '/' ) . '\](.*?)\[\/' . preg_quote( $language_code, '/' ) . '\]/s';
		if ( preg_match( $pattern, $value, $matches ) ) {
			return $matches[1];
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
			return new \WP_Error( 'wpglobus_not_available', __( 'WPGlobus is not active.', 'slytranslate' ) );
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

		$source_title   = $this->get_language_variant( (string) $post->post_title, $source_language );
		$source_content = $this->get_language_variant( (string) $post->post_content, $source_language );
		$source_excerpt = $this->get_language_variant( (string) $post->post_excerpt, $source_language );
		$update_data    = array( 'ID' => $source_post_id );

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

		return $source_post_id;
	}

	public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
		// WPGlobus stores language variants in one post entry; no explicit
		// cross-post relationship is required.
		return true;
	}

	private function get_default_language_code(): string {
		if ( function_exists( 'wpglobus_default_language' ) ) {
			$default = sanitize_key( (string) wpglobus_default_language() );
			if ( '' !== $default ) {
				return $default;
			}
		}

		if ( class_exists( 'WPGlobus', false ) && isset( \WPGlobus::Config()->default_language ) ) {
			$default = sanitize_key( (string) \WPGlobus::Config()->default_language );
			if ( '' !== $default ) {
				return $default;
			}
		}

		return '';
	}

	/**
	 * @return string[]
	 */
	private function get_language_codes(): array {
		if ( function_exists( 'wpglobus_languages_list' ) ) {
			$list = wpglobus_languages_list();
			if ( is_array( $list ) ) {
				return $list;
			}
		}

		if ( class_exists( 'WPGlobus', false ) ) {
			$config = \WPGlobus::Config();
			if ( isset( $config->languages ) && is_array( $config->languages ) ) {
				return $config->languages;
			}
		}

		return array();
	}

	private function resolve_language_name( string $language_code ): string {
		if ( class_exists( 'WPGlobus', false ) ) {
			$config = \WPGlobus::Config();
			if ( isset( $config->en_language_name[ $language_code ] ) ) {
				return (string) $config->en_language_name[ $language_code ];
			}
		}
		return $language_code;
	}

	private function has_wpglobus_markup( string $value ): bool {
		return (bool) preg_match( '/\[\/[a-z]{2,10}\]/', $value );
	}

	private function extract_language_value( string $value, string $language_code, string $default_language ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( ! $this->has_wpglobus_markup( $value ) ) {
			return $language_code === $default_language ? $value : '';
		}

		$pattern = '/\[\:' . preg_quote( $language_code, '/' ) . '\](.*?)\[\/' . preg_quote( $language_code, '/' ) . '\]/s';
		if ( preg_match( $pattern, $value, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	private function merge_language_value(
		string $existing_value,
		string $source_language,
		string $target_language,
		string $target_value,
		string $source_fallback
	): string {
		$source_language = sanitize_key( $source_language );
		$target_language = sanitize_key( $target_language );
		if ( '' === $target_language ) {
			return $existing_value;
		}

		if ( ! $this->has_wpglobus_markup( $existing_value ) ) {
			// Plain value – wrap source in its language tag, append target.
			$result = '';
			if ( '' !== $source_language && '' !== $source_fallback ) {
				$result .= '[:' . $source_language . ']' . $source_fallback . '[/' . $source_language . ']';
			}
			$result .= '[:' . $target_language . ']' . $target_value . '[/' . $target_language . ']';
			return $result;
		}

		// Value already has WPGlobus markup – replace or insert target segment.
		$target_pattern = '/\[\:' . preg_quote( $target_language, '/' ) . '\].*?\[\/' . preg_quote( $target_language, '/' ) . '\]/s';
		$replacement    = '[:' . $target_language . ']' . $target_value . '[/' . $target_language . ']';

		if ( preg_match( $target_pattern, $existing_value ) ) {
			return (string) preg_replace( $target_pattern, $replacement, $existing_value );
		}

		// Ensure source segment exists if it is missing.
		if ( '' !== $source_language ) {
			$source_pattern = '/\[\:' . preg_quote( $source_language, '/' ) . '\].*?\[\/' . preg_quote( $source_language, '/' ) . '\]/s';
			if ( ! preg_match( $source_pattern, $existing_value ) && '' !== $source_fallback ) {
				$existing_value .= '[:' . $source_language . ']' . $source_fallback . '[/' . $source_language . ']';
			}
		}

		return $existing_value . $replacement;
	}
}
