<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Glossary / do-not-translate list.
 *
 * Stored in the slytranslate_glossary option as an array of entries:
 *
 *   array( 'term' => 'SlyBase',   'mode' => 'keep' )
 *   array( 'term' => 'Anleitung', 'mode' => 'translate', 'to' => array( 'en' => 'Guide' ) )
 *
 * The glossary is injected into the prompt as a compact instruction block.
 */
class GlossaryService {

	private const MAX_ENTRIES = 500;

	/**
	 * Glossary entries above this count are pre-filtered against the source
	 * text so very large glossaries do not eat the context window.
	 */
	private const PREFILTER_THRESHOLD = 20;

	/**
	 * @return array<int, array{term:string, mode:string, to:array<string,string>}>
	 */
	public static function get_entries(): array {
		return self::sanitize_entries( get_option( 'slytranslate_glossary', array() ) );
	}

	/**
	 * Sanitizer for the slytranslate_glossary option (Settings API + configure).
	 *
	 * @param mixed $value Raw option value.
	 * @return array<int, array{term:string, mode:string, to:array<string,string>}>
	 */
	public static function sanitize_entries( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$entries = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$term = isset( $entry['term'] ) && is_string( $entry['term'] ) ? sanitize_text_field( $entry['term'] ) : '';
			if ( '' === $term ) {
				continue;
			}

			$mode = isset( $entry['mode'] ) && is_string( $entry['mode'] ) ? sanitize_key( $entry['mode'] ) : 'keep';
			if ( ! in_array( $mode, array( 'keep', 'translate' ), true ) ) {
				$mode = 'keep';
			}

			$to = array();
			if ( 'translate' === $mode && isset( $entry['to'] ) && is_array( $entry['to'] ) ) {
				foreach ( $entry['to'] as $language_code => $translation ) {
					$code        = sanitize_key( (string) $language_code );
					$translation = is_string( $translation ) ? sanitize_text_field( $translation ) : '';
					if ( '' !== $code && '' !== $translation ) {
						$to[ $code ] = $translation;
					}
				}
			}

			if ( 'translate' === $mode && empty( $to ) ) {
				// A fixed-translation entry without translations is useless.
				continue;
			}

			$entries[] = array( 'term' => $term, 'mode' => $mode, 'to' => $to );
			if ( count( $entries ) >= self::MAX_ENTRIES ) {
				break;
			}
		}

		return $entries;
	}

	/**
	 * Build the prompt block for the target language.
	 *
	 * Returns an empty string when the glossary is empty or no entry applies.
	 * When the glossary is large and source text is available, entries are
	 * pre-filtered to terms actually present in the text.
	 *
	 * @param string $to          Target language code.
	 * @param string $source_text Full source text of the current translation call ('' = no filtering).
	 */
	public static function build_prompt_block( string $to, string $source_text = '' ): string {
		$entries = self::get_entries();
		if ( empty( $entries ) ) {
			return '';
		}

		if ( '' !== $source_text && count( $entries ) > self::PREFILTER_THRESHOLD ) {
			$entries = array_values( array_filter(
				$entries,
				static function ( array $entry ) use ( $source_text ): bool {
					return false !== stripos( $source_text, $entry['term'] );
				}
			) );
		}

		$keep  = array();
		$fixed = array();
		foreach ( $entries as $entry ) {
			if ( 'keep' === $entry['mode'] ) {
				$keep[] = '"' . $entry['term'] . '"';
				continue;
			}
			if ( isset( $entry['to'][ $to ] ) ) {
				$fixed[] = 'Always translate "' . $entry['term'] . '" as "' . $entry['to'][ $to ] . '".';
			}
		}

		$lines = array();
		if ( ! empty( $keep ) ) {
			$lines[] = 'Never translate the following terms, keep them exactly as written: ' . implode( ', ', $keep ) . '.';
		}
		if ( ! empty( $fixed ) ) {
			$lines[] = implode( ' ', $fixed );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "Glossary rules (do NOT translate these instructions, apply them to the user-provided content):\n" . implode( "\n", $lines );
	}
}
