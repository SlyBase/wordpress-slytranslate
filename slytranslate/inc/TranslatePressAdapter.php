<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * TranslatePress Multilingual adapter for SlyTranslate.
 *
 * TranslatePress stores translations as string pairs in its own DB tables –
 * no language markup in post content, no separate translated posts.
 * This adapter operates in single-entry-translation mode.
 */
class TranslatePressAdapter implements TranslationPluginAdapter {

	public function is_available(): bool {
		return class_exists( 'TRP_Translate_Press', false );
	}

	public function get_languages(): array {
		$settings = get_option( 'trp_settings', array() );
		$locales  = isset( $settings['translation-languages'] ) && is_array( $settings['translation-languages'] )
			? $settings['translation-languages']
			: array();
		$default  = isset( $settings['default-language'] ) ? (string) $settings['default-language'] : '';

		$result = array();
		foreach ( $locales as $locale ) {
			$locale = (string) $locale;
			if ( '' === $locale || $locale === $default ) {
				continue;
			}
			$iso2 = $this->locale_to_iso2( $locale );
			if ( '' === $iso2 ) {
				continue;
			}
			$result[ $iso2 ] = $this->resolve_locale_label( $locale );
		}

		return $result;
	}

	/**
	 * TranslatePress stores source content directly in the post (no inline markup),
	 * so the value itself is always the language variant for the default language.
	 */
	public function get_language_variant( string $value, string $language_code ): string {
		return $value;
	}

	public function get_post_language( int $post_id ): ?string {
		$settings = get_option( 'trp_settings', array() );
		$default  = isset( $settings['default-language'] ) ? (string) $settings['default-language'] : '';
		if ( '' === $default ) {
			return null;
		}
		return $this->locale_to_iso2( $default );
	}

	public function get_post_translations( int $post_id ): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return array();
		}

		$default_iso2 = $this->get_post_language( $post_id );
		$result       = array();
		if ( null !== $default_iso2 && '' !== $default_iso2 ) {
			$result[ $default_iso2 ] = $post_id;
		}

		$query = $this->get_trp_query();
		if ( null === $query ) {
			return $result;
		}

		$title = trim( (string) $post->post_title );

		foreach ( $this->get_languages() as $iso2 => $label ) {
			$trp_locale = $this->iso2_to_locale( (string) $iso2 );
			if ( null === $trp_locale ) {
				continue;
			}

			$existing = $query->get_existing_translations( array( $title ), $trp_locale );
			if ( ! is_array( $existing ) ) {
				continue;
			}

			foreach ( $existing as $row ) {
				if ( ! empty( $row->translated ) && (int) $row->status > 0 ) {
					$result[ $iso2 ] = $post_id;
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * Save translated strings into TranslatePress DB tables.
	 *
	 * @return int|\WP_Error Source post ID on success, WP_Error on failure.
	 */
	public function create_translation( int $source_post_id, string $target_lang, array $data ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error( 'translatepress_not_available', __( 'TranslatePress is not active.', 'slytranslate' ) );
		}

		$post = get_post( $source_post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
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

		$overwrite = ! empty( $data['overwrite'] );
		if ( ! $overwrite ) {
			$translations = $this->get_post_translations( $source_post_id );
			if ( isset( $translations[ $target_lang ] ) ) {
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
		}

		$trp_locale = $this->iso2_to_locale( $target_lang );
		if ( null === $trp_locale ) {
			return new \WP_Error( 'locale_not_found', __( 'Could not resolve locale for target language.', 'slytranslate' ) );
		}

		$pairs = array();

		if ( isset( $data['post_title'] ) ) {
			$original_title = trim( (string) $post->post_title );
			if ( '' !== $original_title ) {
				$pairs[ $original_title ] = sanitize_text_field( (string) $data['post_title'] );
			}
		}

		if ( isset( $data['post_excerpt'] ) ) {
			$original_excerpt = trim( (string) $post->post_excerpt );
			if ( '' !== $original_excerpt ) {
				$pairs[ $original_excerpt ] = sanitize_text_field( (string) $data['post_excerpt'] );
			}
		}

		if ( isset( $data['post_content'] ) ) {
			$pairs = array_merge( $pairs, $this->build_content_pairs( (string) $post->post_content, (string) $data['post_content'] ) );
		}

		$this->save_string_pairs( $pairs, $trp_locale );

		return $source_post_id;
	}

	/**
	 * No-op: TranslatePress uses a single-post model, no cross-post linking required.
	 */
	public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
		return true;
	}

	/* ---------------------------------------------------------------
	 * Protected: seam for unit-testing TRP_Query access
	 * ------------------------------------------------------------- */

	/**
	 * @return object|null TRP_Query instance, or null if not available.
	 */
	protected function get_trp_query(): ?object {
		if ( ! class_exists( 'TRP_Translate_Press', false ) ) {
			return null;
		}
		$trp = \TRP_Translate_Press::get_trp_instance();
		if ( ! $trp ) {
			return null;
		}
		return $trp->get_component( 'query' ) ?: null;
	}

	/* ---------------------------------------------------------------
	 * Private helpers
	 * ------------------------------------------------------------- */

	/**
	 * Convert a locale string (e.g. 'de_DE') to an ISO-2 language code ('de').
	 */
	private function locale_to_iso2( string $locale ): string {
		return sanitize_key( strtolower( substr( $locale, 0, 2 ) ) );
	}

	/**
	 * Find the first TranslatePress locale that matches the given ISO-2 code.
	 */
	private function iso2_to_locale( string $iso2 ): ?string {
		$settings = get_option( 'trp_settings', array() );
		$locales  = isset( $settings['translation-languages'] ) && is_array( $settings['translation-languages'] )
			? $settings['translation-languages']
			: array();

		foreach ( $locales as $locale ) {
			if ( $this->locale_to_iso2( (string) $locale ) === $iso2 ) {
				return (string) $locale;
			}
		}

		return null;
	}

	/**
	 * Resolve a human-readable label for a TranslatePress locale.
	 */
	private function resolve_locale_label( string $locale ): string {
		if ( class_exists( 'TRP_Translate_Press', false ) ) {
			$trp = \TRP_Translate_Press::get_trp_instance();
			if ( $trp ) {
				$languages_component = $trp->get_component( 'languages' );
				if ( $languages_component && method_exists( $languages_component, 'get_language_names' ) ) {
					$names = $languages_component->get_language_names( array( $locale ) );
					if ( is_array( $names ) && isset( $names[ $locale ] ) ) {
						return (string) $names[ $locale ];
					}
				}
			}
		}

		$map = array(
			'de_DE'  => 'Deutsch',
			'de_AT'  => 'Deutsch (Österreich)',
			'de_CH'  => 'Deutsch (Schweiz)',
			'en_US'  => 'English (US)',
			'en_GB'  => 'English (UK)',
			'fr_FR'  => 'Français',
			'fr_BE'  => 'Français (Belgique)',
			'es_ES'  => 'Español',
			'es_MX'  => 'Español (México)',
			'it_IT'  => 'Italiano',
			'pt_PT'  => 'Português',
			'pt_BR'  => 'Português (Brasil)',
			'nl_NL'  => 'Nederlands',
			'pl_PL'  => 'Polski',
			'ru_RU'  => 'Русский',
			'ja_JP'  => '日本語',
			'zh_CN'  => '中文 (简体)',
			'zh_TW'  => '中文 (繁體)',
			'ar_AR'  => 'العربية',
			'ko_KR'  => '한국어',
			'sv_SE'  => 'Svenska',
			'da_DK'  => 'Dansk',
			'fi_FI'  => 'Suomi',
			'nb_NO'  => 'Norsk',
			'tr_TR'  => 'Türkçe',
			'cs_CZ'  => 'Čeština',
			'sk_SK'  => 'Slovenčina',
			'ro_RO'  => 'Română',
			'hu_HU'  => 'Magyar',
			'el_GR'  => 'Ελληνικά',
			'bg_BG'  => 'Български',
			'hr_HR'  => 'Hrvatski',
			'uk_UA'  => 'Українська',
		);

		return $map[ $locale ] ?? $locale;
	}

	/**
	 * Build original→translated string pairs from post content.
	 *
	 * Attempts positional matching of DOM text nodes. Falls back to storing
	 * the entire content as a single string pair if segment counts differ.
	 *
	 * @return array<string, string>
	 */
	private function build_content_pairs( string $original_content, string $translated_content ): array {
		if ( '' === trim( $original_content ) ) {
			return array();
		}

		$original_segments   = $this->extract_string_segments( $original_content );
		$translated_segments = $this->extract_string_segments( $translated_content );

		$pairs = array();

		if ( count( $original_segments ) === count( $translated_segments ) && ! empty( $original_segments ) ) {
			foreach ( $original_segments as $i => $original_segment ) {
				foreach ( $this->build_segment_lookup_keys( $original_segment ) as $lookup_key ) {
					$pairs[ $lookup_key ] = $translated_segments[ $i ];
				}
			}
			return $pairs;
		}

		// Fallback: store entire content as single string pair.
		$pairs[ $original_content ] = $translated_content;
		return $pairs;
	}

	/**
	 * Extract all non-whitespace text nodes from HTML in document order.
	 *
	 * TranslatePress segments HTML at inline-element boundaries during its
	 * render process. This method replicates that behaviour so that the
	 * resulting segments can be matched positionally against the translated
	 * HTML provided by the LLM.
	 *
	 * @return string[]
	 */
	private function extract_string_segments( string $html ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		// The '<?xml encoding="UTF-8">' PI forces libxml to treat the source
		// as UTF-8 without adding unwanted <html>/<body> wrappers.
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath      = new \DOMXPath( $dom );
		$text_nodes = $xpath->query( '//text()' );

		$segments = array();
		foreach ( $text_nodes as $node ) {
			$text = (string) $node->nodeValue;
			$normalized = $this->normalize_segment_whitespace( $text );
			if ( '' !== $this->trim_segment_whitespace( $normalized ) ) {
				$segments[] = $normalized;
			}
		}

		return $segments;
	}

	/**
	 * Build exact-match lookup keys for one TranslatePress text segment.
	 *
	 * TranslatePress can persist the same visible text both with and without
	 * boundary whitespace around inline elements. Writing both variants keeps
	 * lookups stable across those parser differences.
	 *
	 * @return string[]
	 */
	private function build_segment_lookup_keys( string $segment ): array {
		$normalized = $this->normalize_segment_whitespace( $segment );
		$trimmed    = $this->trim_segment_whitespace( $normalized );

		if ( '' === $trimmed ) {
			return array();
		}

		$keys = array( $normalized );
		if ( $trimmed !== $normalized ) {
			$keys[] = $trimmed;
		}

		return array_values( array_unique( $keys ) );
	}

	private function normalize_segment_whitespace( string $value ): string {
		$normalized = preg_replace( '/[\p{Z}\s]+/u', ' ', $value );
		return is_string( $normalized ) ? $normalized : $value;
	}

	private function trim_segment_whitespace( string $value ): string {
		$trimmed = preg_replace( '/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value );
		return is_string( $trimmed ) ? $trimmed : trim( $value );
	}

	/**
	 * Persist string pairs into TranslatePress DB tables via TRP_Query.
	 *
	 * @param array<string, string> $pairs       Map of original → translated string.
	 * @param string                $trp_locale  TranslatePress locale (e.g. 'en_US').
	 */
	private function save_string_pairs( array $pairs, string $trp_locale ): void {
		if ( empty( $pairs ) ) {
			return;
		}

		$query = $this->get_trp_query();
		if ( null === $query ) {
			return;
		}

		$originals = array_keys( $pairs );

		// Determine which originals are already in the dictionary.
		$existing_originals = array();
		$existing           = $query->get_existing_translations( $originals, $trp_locale );
		if ( is_array( $existing ) ) {
			foreach ( $existing as $row ) {
				if ( isset( $row->original ) ) {
					$existing_originals[] = (string) $row->original;
				}
			}
		}

		// Insert strings that are missing from the dictionary.
		$missing = array_values( array_diff( $originals, $existing_originals ) );
		if ( ! empty( $missing ) ) {
			$query->insert_strings( $missing, $trp_locale );
		}

		// Re-fetch IDs for all originals (including newly inserted ones).
		$string_id_rows = $query->get_string_ids( $originals, $trp_locale );
		if ( ! is_array( $string_id_rows ) || empty( $string_id_rows ) ) {
			return;
		}

		$update_rows = array();
		foreach ( $string_id_rows as $row ) {
			if ( ! isset( $row->original ) ) {
				continue;
			}
			$original = (string) $row->original;
			if ( ! isset( $pairs[ $original ] ) ) {
				continue;
			}
			$update_rows[] = array(
				'id'          => isset( $row->id ) ? (int) $row->id : 0,
				'original'    => $original,
				'translated'  => $pairs[ $original ],
				'status'      => 2,
				'block_type'  => 0,
				'original_id' => isset( $row->original_id ) ? (int) $row->original_id : 0,
			);
		}

		if ( ! empty( $update_rows ) ) {
			$query->update_strings( $update_rows, $trp_locale );
		}
	}
}
