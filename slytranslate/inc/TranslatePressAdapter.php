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
class TranslatePressAdapter implements TranslationPluginAdapter, StringTableContentAdapter {

	/** @var array<string, int> */
	private array $translation_lookup_cache = array();

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

	/* ---------------------------------------------------------------
	 * StringTableContentAdapter
	 * ------------------------------------------------------------- */

	public function supports_pretranslated_content_pairs(): bool {
		return true;
	}

	/**
	 * Build translatable text units from post content for the JSON-batch fast path.
	 *
	 * {@inheritDoc}
	 *
	 * @return array<int, array{id: string, source: string, lookup_keys: string[]}>
	 */
	public function build_content_translation_units( string $source_content ): array {
		$segments = $this->extract_string_segments( $source_content );
		$units    = array();

		foreach ( $segments as $index => $segment ) {
			$lookup_keys = $this->build_segment_lookup_keys( $segment );
			if ( empty( $lookup_keys ) ) {
				continue;
			}

			$units[] = array(
				'id'          => 'seg_' . $index,
				'source'      => $segment,
				'lookup_keys' => $lookup_keys,
			);
		}

		return $units;
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
	 * Resolve whether one specific target language already has a translation.
	 *
	 * TranslatePress stores translations in string tables, so an existing target
	 * translation still resolves to the source post ID in single-entry mode.
	 */
	public function get_post_translation_for_language( int $post_id, string $target_lang ): int {
		$post_id     = absint( $post_id );
		$target_lang = sanitize_key( $target_lang );

		if ( $post_id < 1 || '' === $target_lang || ! $this->is_available() ) {
			return 0;
		}

		$cache_key = $this->get_translation_lookup_cache_key( $post_id, $target_lang );
		if ( array_key_exists( $cache_key, $this->translation_lookup_cache ) ) {
			return $this->translation_lookup_cache[ $cache_key ];
		}

		$default_iso2 = $this->get_post_language( $post_id );
		if ( null !== $default_iso2 && '' !== $default_iso2 && $default_iso2 === $target_lang ) {
			return $this->remember_translation_lookup( $cache_key, $post_id );
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return $this->remember_translation_lookup( $cache_key, 0 );
		}

		$query = $this->get_trp_query();
		if ( null === $query ) {
			return $this->remember_translation_lookup( $cache_key, 0 );
		}

		$trp_locale = $this->iso2_to_locale( $target_lang );
		if ( null === $trp_locale ) {
			return $this->remember_translation_lookup( $cache_key, 0 );
		}

		$title    = trim( (string) $post->post_title );
		$existing = $query->get_existing_translations( array( $title ), $trp_locale );
		if ( ! is_array( $existing ) ) {
			return $this->remember_translation_lookup( $cache_key, 0 );
		}

		foreach ( $existing as $row ) {
			if ( ! empty( $row->translated ) && (int) $row->status > 0 ) {
				return $this->remember_translation_lookup( $cache_key, $post_id );
			}
		}

		return $this->remember_translation_lookup( $cache_key, 0 );
	}

	public function get_string_translation( string $source_text, string $target_lang ): ?string {
		if ( ! $this->is_available() ) {
			return null;
		}

		$target_lang = sanitize_key( $target_lang );
		if ( '' === $target_lang ) {
			return null;
		}

		$trp_locale = $this->iso2_to_locale( $target_lang );
		if ( null === $trp_locale ) {
			return null;
		}

		$query = $this->get_trp_query();
		if ( null === $query ) {
			return null;
		}

		$lookup_keys = $this->build_segment_lookup_keys( $source_text );
		if ( empty( $lookup_keys ) ) {
			return null;
		}

		$rows = $query->get_existing_translations( $lookup_keys, $trp_locale );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return null;
		}

		$translations = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row->original, $row->translated ) || empty( $row->status ) ) {
				continue;
			}

			$translated = (string) $row->translated;
			if ( '' === trim( $translated ) ) {
				continue;
			}

			$translations[ (string) $row->original ] = $translated;
		}

		foreach ( $lookup_keys as $lookup_key ) {
			if ( isset( $translations[ $lookup_key ] ) ) {
				return $translations[ $lookup_key ];
			}
		}

		return empty( $translations ) ? null : reset( $translations );
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
		if ( ! $overwrite && $this->get_post_translation_for_language( $source_post_id, $target_lang ) > 0 ) {
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

		if ( isset( $data['content_string_pairs'] ) && is_array( $data['content_string_pairs'] ) ) {
			foreach ( $data['content_string_pairs'] as $original => $translated ) {
				if ( is_string( $original ) && is_string( $translated ) && '' !== trim( $original ) ) {
					$pairs[ $original ] = $translated;
				}
			}
		} elseif ( isset( $data['post_content'] ) ) {
			$pairs = array_merge( $pairs, $this->build_content_pairs( (string) $post->post_content, (string) $data['post_content'] ) );
		}

		$this->save_string_pairs( $pairs, $trp_locale );
		$this->remember_translation_lookup( $this->get_translation_lookup_cache_key( $source_post_id, $target_lang ), $source_post_id );

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
	 * Additionally, WordPress applies wptexturize() to post content during
	 * rendering, converting straight quotes to typographic HTML entities
	 * (e.g. `"text"` → `&#8220;text&#8221;`). If TranslatePress indexes the
	 * rendered form, the dictionary entry differs from the raw segment and
	 * receives no translation (status=0). Generating these render-aware
	 * variants here ensures one translation covers all storage forms.
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

		// Add wptexturize render-aware variants so that TranslatePress dictionary
		// entries created during WordPress rendering are covered by this translation.
		$has_leading_space  = str_starts_with( $normalized, ' ' ) && ! str_starts_with( $trimmed, ' ' );
		$has_trailing_space = str_ends_with( $normalized, ' ' ) && ! str_ends_with( $trimmed, ' ' );

		foreach ( $this->generate_texturize_variants( $trimmed ) as $tx ) {
			$keys[] = $tx;
			// Also add the whitespace-bounded texturized form when applicable.
			if ( $has_leading_space || $has_trailing_space ) {
				$keys[] = ( $has_leading_space ? ' ' : '' ) . $tx . ( $has_trailing_space ? ' ' : '' );
			}
		}

		// Deduplicate and cap at 8 variants to keep the dictionary lean.
		return array_values( array_unique( array_slice( $keys, 0, 8 ) ) );
	}

	/**
	 * Generate wptexturize-based render variants for a segment.
	 *
	 * When wptexturize returns UTF-8 typographic characters, the numeric entity
	 * form is also added so that TranslatePress DB entries using either
	 * representation are covered (e.g. „ vs &#8222;).
	 *
	 * Returns an empty array when wptexturize is not available or produces no
	 * change (e.g. for URLs and code that do not contain typographic characters).
	 *
	 * @return string[]
	 */
	private function generate_texturize_variants( string $segment ): array {
		if ( ! function_exists( 'wptexturize' ) ) {
			return array();
		}

		$texturized = wptexturize( $segment );
		if ( $texturized === $segment ) {
			return array();
		}

		$variants = array( $texturized );

		// When wptexturize returned UTF-8 typographic characters (i.e. the result
		// contains no `&#` entity references yet), also add the numeric-entity form
		// to match TranslatePress DB entries that store the entity-encoded variant.
		if ( false === strpos( $texturized, '&#' ) ) {
			$entity_form = $this->encode_typographic_chars( $texturized );
			if ( $entity_form !== $texturized ) {
				$variants[] = $entity_form;
			}
		}

		return $variants;
	}

	/**
	 * Convert typographic quote and dash characters to HTML numeric entities.
	 *
	 * Only converts the characters commonly produced by wptexturize; leaves all
	 * other characters (including arrows, umlauts, etc.) unchanged so that
	 * partial-entity strings in TranslatePress dictionaries are matched precisely.
	 */
	private function encode_typographic_chars( string $text ): string {
		static $map = array(
			"\xE2\x80\x9C" => '&#8220;', // LEFT DOUBLE QUOTATION MARK  "
			"\xE2\x80\x9D" => '&#8221;', // RIGHT DOUBLE QUOTATION MARK "
			"\xE2\x80\x9E" => '&#8222;', // DOUBLE LOW-9 QUOTATION MARK  „
			"\xE2\x80\x98" => '&#8216;', // LEFT SINGLE QUOTATION MARK  '
			"\xE2\x80\x99" => '&#8217;', // RIGHT SINGLE QUOTATION MARK '
			"\xE2\x80\x9B" => '&#8219;', // SINGLE HIGH-REVERSED-9 QUOTATION MARK ‛
			"\xE2\x80\x93" => '&#8211;', // EN DASH –
			"\xE2\x80\x94" => '&#8212;', // EM DASH —
			"\xC2\xAB"     => '&#171;',  // LEFT-POINTING DOUBLE ANGLE QUOTATION MARK «
			"\xC2\xBB"     => '&#187;',  // RIGHT-POINTING DOUBLE ANGLE QUOTATION MARK »
			"\xE2\x80\xA6" => '&#8230;', // HORIZONTAL ELLIPSIS …
		);

		return strtr( $text, $map );
	}

	private function normalize_segment_whitespace( string $value ): string {
		$normalized = preg_replace( '/[\p{Z}\s]+/u', ' ', $value );
		return is_string( $normalized ) ? $normalized : $value;
	}

	private function trim_segment_whitespace( string $value ): string {
		$trimmed = preg_replace( '/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value );
		return is_string( $trimmed ) ? $trimmed : trim( $value );
	}

	private function get_translation_lookup_cache_key( int $post_id, string $target_lang ): string {
		return $post_id . ':' . $target_lang;
	}

	private function remember_translation_lookup( string $cache_key, int $translation_id ): int {
		$this->translation_lookup_cache[ $cache_key ] = $translation_id;
		return $translation_id;
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

		if ( $this->persist_string_pairs_via_tables( $pairs, $trp_locale ) ) {
			return;
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
			$original_id = isset( $row->original_id ) ? (int) $row->original_id : 0;
			if ( $original_id < 1 && isset( $row->id ) ) {
				$original_id = (int) $row->id;
			}
			$update_rows[] = array(
				'id'          => isset( $row->id ) ? (int) $row->id : 0,
				'original'    => $original,
				'translated'  => $pairs[ $original ],
				'status'      => 2,
				'block_type'  => 0,
				'original_id' => $original_id,
			);
		}

		if ( ! empty( $update_rows ) ) {
			$query->update_strings( $update_rows, $trp_locale );
		}

		TimingLogger::log( 'translatepress_pairs_saved', array(
			'locale'    => $trp_locale,
			'originals' => count( $originals ),
			'inserted'  => count( $missing ),
			'updated'   => count( $update_rows ),
		) );
	}

	/**
	 * Persist string pairs via direct TranslatePress table updates when available.
	 *
	 * On some TranslatePress installs, TRP_Query::get_string_ids() returns the
	 * dictionary row id rather than the original string id, which produces orphan
	 * dictionary rows that the editor cannot resolve later. Reading the real
	 * original-string ids from wp_trp_original_strings avoids that mismatch.
	 */
	private function persist_string_pairs_via_tables( array $pairs, string $trp_locale ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'update' ) || ! method_exists( $wpdb, 'insert' ) ) {
			return false;
		}

		$dictionary_table = $this->get_dictionary_table_name( $trp_locale );
		if ( null === $dictionary_table ) {
			return false;
		}

		$original_id_map = $this->get_original_string_id_map( array_keys( $pairs ) );
		if ( empty( $original_id_map ) ) {
			return false;
		}

		$dictionary_rows = $this->get_dictionary_rows_by_original_id( $dictionary_table, array_values( $original_id_map ) );
		$updated         = 0;
		$inserted        = 0;

		foreach ( $pairs as $original => $translated ) {
			if ( ! isset( $original_id_map[ $original ] ) ) {
				continue;
			}

			$original_id = $original_id_map[ $original ];
			$data        = array(
				'original'    => $original,
				'translated'  => $translated,
				'status'      => 2,
				'block_type'  => 0,
				'original_id' => $original_id,
			);

			if ( isset( $dictionary_rows[ $original_id ] ) && is_array( $dictionary_rows[ $original_id ] ) ) {
				foreach ( $dictionary_rows[ $original_id ] as $dictionary_row ) {
					if ( ! isset( $dictionary_row->id ) ) {
						continue;
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- TranslatePress stores these values in its own dictionary table and this method clears the affected cache entries after writes.
					$wpdb->update( $dictionary_table, $data, array( 'id' => (int) $dictionary_row->id ) );
					$updated++;
				}

				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- TranslatePress stores these values in its own dictionary table and this method clears the affected cache entries after writes.
			$wpdb->insert( $dictionary_table, $data );
			$inserted++;
		}

		if ( $updated > 0 || $inserted > 0 ) {
			$this->delete_dictionary_rows_cache( $dictionary_table, array_values( $original_id_map ) );
		}

		TimingLogger::log( 'translatepress_pairs_saved', array(
			'locale'    => $trp_locale,
			'originals' => count( $pairs ),
			'inserted'  => $inserted,
			'updated'   => $updated,
		) );

		return true;
	}

	/**
	 * @param string[] $originals
	 * @return array<string, int>
	 */
	private function get_original_string_id_map( array $originals ): array {
		global $wpdb;

		$originals = array_values( array_unique( array_filter( $originals, static function ( $value ) {
			return is_string( $value ) && '' !== trim( $value );
		} ) ) );

		if ( empty( $originals ) ) {
			return array();
		}

		$cache_key = $this->get_original_string_id_map_cache_key( $originals );
		$cached    = $this->get_cached_lookup_result( $cache_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $originals ), '%s' ) );
		$table        = $wpdb->prefix . 'trp_original_strings';
		$sql          = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The placeholder list is generated from the sanitized originals array size before being passed into $wpdb->prepare().
			'SELECT id, original FROM %i WHERE original IN (' . $placeholders . ')',
			...array_merge( array( $table ), $originals )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- TranslatePress exposes these rows only through its custom tables and this lookup already uses request-local object-cache entries.
		$rows         = $wpdb->get_results( $sql );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			$this->set_cached_lookup_result( $cache_key, array() );
			return array();
		}

		$result = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row->id, $row->original ) ) {
				continue;
			}

			$result[ (string) $row->original ] = (int) $row->id;
		}

		$this->set_cached_lookup_result( $cache_key, $result );

		return $result;
	}

	/**
	 * @param int[] $original_ids
	 * @return array<int, array<int, object>>
	 */
	private function get_dictionary_rows_by_original_id( string $dictionary_table, array $original_ids ): array {
		global $wpdb;

		$original_ids = array_values( array_unique( array_filter( array_map( 'intval', $original_ids ), static function ( int $value ) {
			return $value > 0;
		} ) ) );

		if ( empty( $original_ids ) ) {
			return array();
		}

		$cache_key = $this->get_dictionary_rows_cache_key( $dictionary_table, $original_ids );
		$cached    = $this->get_cached_lookup_result( $cache_key );
		if ( null !== $cached ) {
			return $cached;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $original_ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The placeholder list is generated from the sanitized original ID array size before being passed into $wpdb->prepare().
			'SELECT id, original_id FROM %i WHERE original_id IN (' . $placeholders . ')',
			...array_merge( array( $dictionary_table ), $original_ids )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- TranslatePress exposes these rows only through its custom tables and this lookup already uses request-local object-cache entries.
		$rows         = $wpdb->get_results( $sql );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			$this->set_cached_lookup_result( $cache_key, array() );
			return array();
		}

		$result = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row->original_id ) ) {
				continue;
			}

			$original_id = (int) $row->original_id;
			if ( ! isset( $result[ $original_id ] ) ) {
				$result[ $original_id ] = array();
			}

			$result[ $original_id ][] = $row;
		}

		$this->set_cached_lookup_result( $cache_key, $result );

		return $result;
	}

	/**
	 * @return array<string, int>|array<int, object>|null
	 */
	private function get_cached_lookup_result( string $cache_key ): ?array {
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return null;
		}

		$cached = wp_cache_get( $cache_key, 'slytranslate_translatepress' );

		return is_array( $cached ) ? $cached : null;
	}

	private function set_cached_lookup_result( string $cache_key, array $value ): void {
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( $cache_key, $value, 'slytranslate_translatepress' );
		}
	}

	private function delete_dictionary_rows_cache( string $dictionary_table, array $original_ids ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $this->get_dictionary_rows_cache_key( $dictionary_table, $original_ids ), 'slytranslate_translatepress' );
		}
	}

	/**
	 * @param string[] $originals
	 */
	private function get_original_string_id_map_cache_key( array $originals ): string {
		return 'original-string-id-map:' . md5( wp_json_encode( array_values( $originals ) ) );
	}

	/**
	 * @param int[] $original_ids
	 */
	private function get_dictionary_rows_cache_key( string $dictionary_table, array $original_ids ): string {
		return 'dictionary-rows:' . md5( $dictionary_table . '|' . wp_json_encode( array_values( $original_ids ) ) );
	}

	private function get_dictionary_table_name( string $trp_locale ): ?string {
		global $wpdb;

		$settings       = get_option( 'trp_settings', array() );
		$default_locale = isset( $settings['default-language'] ) ? (string) $settings['default-language'] : '';
		if ( '' === $default_locale || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return null;
		}

		$normalize_locale = static function ( string $locale ): string {
			return strtolower( str_replace( '-', '_', $locale ) );
		};

		return $wpdb->prefix . 'trp_dictionary_' . $normalize_locale( $default_locale ) . '_' . $normalize_locale( $trp_locale );
	}
}
