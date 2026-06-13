<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Translates ACF block field data stored as JSON in the block comment.
 *
 * ACF Pro persists block field values inside the block attributes:
 *
 *   <!-- wp:acf/hero {"name":"acf/hero","data":{"headline":"Hello","_headline":"field_64f…"}} /-->
 *
 * ContentTranslator only handles innerHTML/innerBlocks, so the attrs.data
 * payload of acf/* blocks would pass through untranslated. This class runs as
 * a pre-pass over the parsed block tree: every data entry whose '_'-prefixed
 * sibling holds a 'field_…' reference to a translatable field type is
 * translated in place. Repeater/group sub-fields (slides_0_caption with their
 * own _slides_0_caption reference) are covered by the same per-key check.
 *
 * Serialization stays with serialize_blocks(), which produces correct JSON
 * including Unicode escaping — the JSON is never built manually here.
 */
class AcfBlockTranslator {

	/** Values longer than this skip the JSON batch and get their own call. */
	private const BATCH_MAX_VALUE_CHARS = 1000;

	/* ---------------------------------------------------------------
	 * Pre-pass over a parsed block tree
	 * ------------------------------------------------------------- */

	/**
	 * True when the block is an ACF block carrying field data.
	 */
	public static function is_acf_block_with_data( array $block ): bool {
		$block_name = (string) ( $block['blockName'] ?? '' );
		return str_starts_with( $block_name, 'acf/' )
			&& isset( $block['attrs']['data'] )
			&& is_array( $block['attrs']['data'] )
			&& ! empty( $block['attrs']['data'] );
	}

	/**
	 * Translate the attrs.data payload of every acf/* block in a parsed block
	 * tree (recursively, so nested ACF blocks inside wrappers are covered).
	 *
	 * @param array[] $blocks Parsed blocks from parse_blocks().
	 * @return array[]|\WP_Error Updated blocks, or WP_Error on hard failure.
	 */
	public static function translate_blocks_data(
		array $blocks,
		string $to,
		string $from,
		string $additional_prompt = ''
	): array|\WP_Error {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return $blocks;
		}

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			if ( self::is_acf_block_with_data( $block ) ) {
				$translated_data = self::translate_block_data( $block['attrs']['data'], $to, $from, $additional_prompt );
				if ( is_wp_error( $translated_data ) ) {
					return $translated_data;
				}
				$blocks[ $index ]['attrs']['data'] = $translated_data;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$translated_inner = self::translate_blocks_data( $block['innerBlocks'], $to, $from, $additional_prompt );
				if ( is_wp_error( $translated_inner ) ) {
					return $translated_inner;
				}
				$blocks[ $index ]['innerBlocks'] = $translated_inner;
			}
		}

		return $blocks;
	}

	/**
	 * Translate all translatable entries of one acf/* block data array.
	 *
	 * Mirrors the meta pipeline: short values are batched into one JSON call,
	 * longer values or batch failures fall back to individual calls. A failed
	 * individual translation keeps the source value instead of failing the
	 * whole post; cancellation is propagated.
	 *
	 * @param array $data attrs.data of the block.
	 * @return array|\WP_Error Updated data array.
	 */
	public static function translate_block_data(
		array $data,
		string $to,
		string $from,
		string $additional_prompt = ''
	): array|\WP_Error {
		$translatable = self::collect_translatable_entries( $data );
		if ( empty( $translatable ) ) {
			return $data;
		}

		$batch_results = self::try_batch_translate( $translatable, $to, $from, $additional_prompt );

		foreach ( $translatable as $key => $value ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			if ( is_array( $batch_results ) && array_key_exists( $key, $batch_results ) ) {
				$data[ $key ] = $batch_results[ $key ];
				continue;
			}

			$translated = TranslationRuntime::translate_text( $value, $to, $from, $additional_prompt );
			if ( is_wp_error( $translated ) ) {
				if ( 'translation_cancelled' === $translated->get_error_code() ) {
					return $translated;
				}
				TimingLogger::log( 'acf_block_field_kept_in_source', array(
					'key'    => $key,
					'reason' => $translated->get_error_code(),
				) );
				continue;
			}

			$data[ $key ] = (string) $translated;
		}

		return $data;
	}

	/**
	 * Collect data entries eligible for translation: string values whose
	 * '_'-prefixed sibling references a translatable (non-structured) field.
	 *
	 * @return array<string, string>
	 */
	public static function collect_translatable_entries( array $data ): array {
		$entries = array();

		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || str_starts_with( $key, '_' ) ) {
				continue;
			}
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			$ref = $data[ '_' . $key ] ?? null;
			if ( ! AcfFieldIntrospector::is_translatable_ref( is_string( $ref ) ? $ref : null, false ) ) {
				continue;
			}

			$entries[ $key ] = $value;
		}

		return $entries;
	}

	/**
	 * Translate eligible short values in one JSON batch call.
	 *
	 * @param array<string, string> $candidates
	 * @return array<string, string>|null Translated map or null to fall back.
	 */
	private static function try_batch_translate(
		array $candidates,
		string $to,
		string $from,
		string $additional_prompt
	): ?array {
		$batchable = array();
		foreach ( $candidates as $key => $value ) {
			$length = function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value, 'UTF-8' ) : strlen( $value );
			if ( $length <= self::BATCH_MAX_VALUE_CHARS ) {
				$batchable[ $key ] = $value;
			}
		}

		if ( count( $batchable ) < 2 ) {
			return null;
		}

		$json_input = wp_json_encode( $batchable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json_input ) {
			return null;
		}

		$json_hint    = 'The input is a JSON object of field values from one content block. Translate only the string values, not the keys. Preserve inline HTML tags inside values. Return a valid JSON object with the identical keys and translated values. No explanations, no markdown wrappers — only the JSON.';
		$batch_prompt = '' !== trim( $additional_prompt )
			? trim( $additional_prompt ) . "\n\n" . $json_hint
			: $json_hint;

		$result = TranslationRuntime::translate_text( $json_input, $to, $from, $batch_prompt );

		$ok = ! is_wp_error( $result );
		TimingLogger::log( 'acf_block_batch', array(
			'keys' => array_keys( $batchable ),
			'ok'   => $ok,
		) );

		if ( ! $ok ) {
			return null;
		}

		$result_str = trim( (string) $result );
		$result_str = (string) preg_replace( '/^```(?:json)?\s*/i', '', $result_str );
		$result_str = (string) preg_replace( '/\s*```\s*$/i', '', $result_str );

		$decoded = json_decode( trim( $result_str ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		foreach ( array_keys( $batchable ) as $key ) {
			if ( ! array_key_exists( $key, $decoded ) || ! is_string( $decoded[ $key ] ) ) {
				return null;
			}

			$validation = TranslationValidator::validate( $batchable[ $key ], $decoded[ $key ], $to );
			if ( is_wp_error( $validation ) ) {
				return null;
			}
		}

		return $decoded;
	}

	/* ---------------------------------------------------------------
	 * Client-translation workflow units
	 * ------------------------------------------------------------- */

	/**
	 * Build client-translation units for all ACF block fields in a post's
	 * serialized content. Unit ids encode the block path and field key:
	 * "acf_block:{path}:{key}" where {path} is dot-joined block indices.
	 *
	 * @return array<int, array{id:string,field:string,source:string,format:string,lookup_keys:array}>
	 */
	public static function build_block_units( string $content ): array {
		if ( '' === trim( $content )
			|| ! function_exists( 'parse_blocks' )
			|| ! function_exists( 'acf_get_field' )
		) {
			return array();
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) ) {
			return array();
		}

		return self::collect_block_units( $blocks, '' );
	}

	/**
	 * @param array[] $blocks
	 * @param string  $path_prefix Dot-joined indices of parent blocks.
	 */
	private static function collect_block_units( array $blocks, string $path_prefix ): array {
		$units = array();

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$path = '' === $path_prefix ? (string) $index : $path_prefix . '.' . $index;

			if ( self::is_acf_block_with_data( $block ) ) {
				foreach ( self::collect_translatable_entries( $block['attrs']['data'] ) as $key => $value ) {
					$units[] = array(
						'id'          => 'acf_block:' . $path . ':' . $key,
						'field'       => 'acf_block',
						'source'      => $value,
						'format'      => 'plain_text',
						'lookup_keys' => array(),
					);
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$units = array_merge( $units, self::collect_block_units( $block['innerBlocks'], $path ) );
			}
		}

		return $units;
	}

	/**
	 * Merge translated acf_block units back into serialized post content.
	 *
	 * Defensive by design: when the content cannot be parsed, a path no longer
	 * resolves to an acf/* block, or the target key is missing, that unit is
	 * skipped and the content is otherwise returned re-serialized. The block
	 * structure of the translated content must match the source structure
	 * (clients are instructed to preserve block markup).
	 *
	 * @param string                $content      Serialized block content.
	 * @param array<string, string> $translations Unit id => translated string.
	 * @return string Updated serialized content.
	 */
	public static function apply_block_unit_translations( string $content, array $translations ): string {
		$acf_translations = array();
		foreach ( $translations as $unit_id => $translated ) {
			if ( is_string( $unit_id ) && str_starts_with( $unit_id, 'acf_block:' ) && is_string( $translated ) ) {
				$acf_translations[ $unit_id ] = $translated;
			}
		}

		if ( empty( $acf_translations )
			|| '' === trim( $content )
			|| ! function_exists( 'parse_blocks' )
			|| ! function_exists( 'serialize_blocks' )
		) {
			return $content;
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || empty( $blocks ) ) {
			return $content;
		}

		$applied = 0;
		foreach ( $acf_translations as $unit_id => $translated ) {
			$parts = explode( ':', $unit_id, 3 );
			if ( 3 !== count( $parts ) || '' === $parts[1] || '' === $parts[2] ) {
				continue;
			}

			if ( self::set_block_data_value( $blocks, explode( '.', $parts[1] ), $parts[2], $translated ) ) {
				++$applied;
			}
		}

		if ( 0 === $applied ) {
			return $content;
		}

		$serialized = serialize_blocks( $blocks );
		return is_string( $serialized ) && '' !== $serialized ? $serialized : $content;
	}

	/**
	 * Set one data value at the block addressed by the index path.
	 *
	 * @param array[]  $blocks Parsed blocks (modified in place).
	 * @param string[] $path   Block index path segments.
	 * @return bool True when the value was written.
	 */
	private static function set_block_data_value( array &$blocks, array $path, string $key, string $value ): bool {
		$segment = array_shift( $path );
		if ( null === $segment || ! ctype_digit( $segment ) ) {
			return false;
		}

		$index = (int) $segment;
		if ( ! isset( $blocks[ $index ] ) || ! is_array( $blocks[ $index ] ) ) {
			return false;
		}

		if ( ! empty( $path ) ) {
			if ( empty( $blocks[ $index ]['innerBlocks'] ) || ! is_array( $blocks[ $index ]['innerBlocks'] ) ) {
				return false;
			}
			return self::set_block_data_value( $blocks[ $index ]['innerBlocks'], $path, $key, $value );
		}

		if ( ! self::is_acf_block_with_data( $blocks[ $index ] )
			|| ! array_key_exists( $key, $blocks[ $index ]['attrs']['data'] )
			|| ! is_string( $blocks[ $index ]['attrs']['data'][ $key ] )
		) {
			return false;
		}

		$blocks[ $index ]['attrs']['data'][ $key ] = $value;
		return true;
	}
}
