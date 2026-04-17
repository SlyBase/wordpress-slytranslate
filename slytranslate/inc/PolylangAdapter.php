<?php

namespace AI_Translate;

if ( ! defined( 'ABSPATH' ) ) exit;

class PolylangAdapter implements TranslationPluginAdapter {

	public function is_available(): bool {
		return function_exists( 'pll_languages_list' );
	}

	public function get_languages(): array {
		if ( ! $this->is_available() ) {
			return array();
		}
		$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
		$names = pll_languages_list( array( 'fields' => 'name' ) );
		if ( ! is_array( $slugs ) || ! is_array( $names ) || count( $slugs ) !== count( $names ) ) {
			return array();
		}

		return array_combine( $slugs, $names ) ?: array();
	}

	public function get_post_language( int $post_id ): ?string {
		if ( ! $this->is_available() ) {
			return null;
		}
		$lang = pll_get_post_language( $post_id );
		return $lang ?: null;
	}

	public function get_post_translations( int $post_id ): array {
		if ( ! $this->is_available() ) {
			return array();
		}
		return pll_get_post_translations( $post_id );
	}

	public function create_translation( int $source_post_id, string $target_lang, array $data ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error( 'polylang_not_available', 'Polylang is not active.' );
		}

		$post = get_post( $source_post_id );
		if ( ! $post ) {
			return new \WP_Error( 'source_post_not_found', 'Source post not found.' );
		}

		$from_lang = pll_get_post_language( $source_post_id );
		$existing  = pll_get_post( $source_post_id, $target_lang );
		$overwrite = ! empty( $data['overwrite'] );

		if ( $existing && get_post_status( $existing ) !== false && ! $overwrite ) {
			return new \WP_Error(
				'translation_exists',
				sprintf( 'A translation for language "%s" already exists (post %d).', $target_lang, $existing )
			);
		}

		$translation_id = $existing;

		// Use a filter to preserve the original post author.
		$author_override = function ( $data ) use ( $post ) {
			$data['post_author'] = $post->post_author;
			return $data;
		};

		if ( ! $translation_id ) {
			add_filter( 'wp_insert_post_data', $author_override, 99 );
			$translation_id = wp_insert_post( wp_slash( array(
				'post_status'       => 'draft',
				'post_title'        => $post->post_title . " ({$target_lang})",
				'post_content'      => ' ',
				'post_type'         => $post->post_type,
				'post_author'       => $post->post_author,
				'post_date'         => $post->post_date,
				'post_date_gmt'     => $post->post_date_gmt,
				'post_modified'     => $post->post_modified,
				'post_modified_gmt' => $post->post_modified_gmt,
			) ) );
			remove_filter( 'wp_insert_post_data', $author_override, 99 );

			if ( is_wp_error( $translation_id ) ) {
				return $translation_id;
			}

			pll_set_post_language( $translation_id, $target_lang );
		}

		$update_data = array( 'ID' => $translation_id );
		if ( isset( $data['post_title'] ) ) {
			$update_data['post_title'] = sanitize_text_field( $data['post_title'] );
		}
		if ( isset( $data['post_content'] ) ) {
			$update_data['post_content'] = wp_kses_post( $data['post_content'] );
		}
		if ( isset( $data['post_excerpt'] ) ) {
			$update_data['post_excerpt'] = wp_kses_post( $data['post_excerpt'] );
		}
		$update_data['post_status'] = $data['post_status'] ?? 'draft';

		wp_update_post( wp_slash( $update_data ) );

		// Copy and process meta.
		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $key => $value ) {
				update_post_meta( $translation_id, $key, is_string( $value ) ? sanitize_text_field( $value ) : $value );
			}
		}

		// Copy taxonomy terms, translating them when Polylang provides a matching target-language term.
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy, array( 'language', 'post_translations' ), true ) ) {
				continue;
			}

			$term_ids = wp_get_object_terms( $source_post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
				continue;
			}

			$translated_term_ids = array();
			foreach ( $term_ids as $term_id ) {
				$translated_term_id = pll_get_term( $term_id, $target_lang );
				if ( $translated_term_id ) {
					$translated_term_ids[] = $translated_term_id;
					continue;
				}

				if ( function_exists( 'pll_is_translated_taxonomy' ) && ! pll_is_translated_taxonomy( $taxonomy ) ) {
					$translated_term_ids[] = $term_id;
				}
			}

			if ( ! empty( $translated_term_ids ) ) {
				wp_set_object_terms( $translation_id, $translated_term_ids, $taxonomy, false );
			}
		}

		// Link the translation.
		$this->link_translation( $source_post_id, $translation_id, $target_lang );

		return $translation_id;
	}

	public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}
		$source_lang = pll_get_post_language( $source_post_id );
		pll_save_post_translations( array(
			$source_lang => $source_post_id,
			$target_lang => $translated_post_id,
		) );
		return true;
	}
}
