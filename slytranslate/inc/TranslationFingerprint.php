<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Shared content fingerprinting for change detection.
 *
 * Used by the client translation workflow (stale-source detection) and the
 * server-side diff retranslation (skip unchanged blocks/meta on overwrite).
 */
class TranslationFingerprint {

	/** Post meta key on the *translated* post: array index → sha256 of the source top-level block. */
	public const UNIT_HASHES_META_KEY = '_slytranslate_unit_hashes';

	/** Post meta key on the *translated* post: meta key → sha256 of the source meta value. */
	public const META_HASHES_META_KEY = '_slytranslate_meta_hashes';

	public static function hash( string $text ): string {
		return hash( 'sha256', $text );
	}

	/**
	 * Whole-post fingerprint over title, content, and excerpt.
	 *
	 * Kept md5-based for compatibility with source_hash values already handed
	 * out to clients by prepare-client-translation.
	 */
	public static function compute_post_fingerprint( \WP_Post $post ): string {
		return md5( $post->post_title . "\x00" . $post->post_content . "\x00" . $post->post_excerpt );
	}

	/**
	 * Hash every top-level block of a parsed block list.
	 *
	 * @param array $blocks Result of parse_blocks().
	 * @return string[] Index → sha256 of the serialized block.
	 */
	public static function compute_block_hashes( array $blocks ): array {
		if ( ! function_exists( 'serialize_blocks' ) ) {
			return array();
		}

		$hashes = array();
		foreach ( array_values( $blocks ) as $index => $block ) {
			if ( ! is_array( $block ) ) {
				return array();
			}
			$hashes[ $index ] = self::hash( serialize_blocks( array( $block ) ) );
		}

		return $hashes;
	}

	/**
	 * Hash the source values of the given meta keys.
	 *
	 * @param array    $all_meta Raw get_post_meta() result (values wrapped in arrays).
	 * @param string[] $keys     Meta keys to fingerprint.
	 * @return array<string,string> meta key → sha256.
	 */
	public static function compute_meta_hashes( array $all_meta, array $keys ): array {
		$hashes = array();
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! isset( $all_meta[ $key ] ) ) {
				continue;
			}
			$raw = $all_meta[ $key ];
			if ( is_array( $raw ) ) {
				$raw = reset( $raw );
			}
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$hashes[ $key ] = self::hash( (string) $raw );
		}

		return $hashes;
	}

	/**
	 * Build a reuse map for diff-based retranslation of an existing translation.
	 *
	 * Matches by block *content hash* (not index) so reordered blocks are still
	 * reused. Falls back to null (full retranslation) when no hashes were
	 * stored, the stored hash count does not match the translated post's block
	 * count (blocks were inserted/removed since), or nothing matches.
	 *
	 * @param array $source_blocks      parse_blocks() result of the current source content.
	 * @param int   $translated_post_id Existing translation to harvest translated blocks from.
	 * @return array<string,string>|null source block hash → serialized translated block.
	 */
	public static function plan_block_reuse( array $source_blocks, int $translated_post_id ): ?array {
		if ( empty( $source_blocks ) || $translated_post_id < 1 ) {
			return null;
		}
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return null;
		}

		$stored = get_post_meta( $translated_post_id, self::UNIT_HASHES_META_KEY, true );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return null;
		}

		$translated_post = get_post( $translated_post_id );
		if ( ! $translated_post || '' === trim( (string) $translated_post->post_content ) ) {
			return null;
		}

		$translated_blocks = parse_blocks( $translated_post->post_content );
		if ( ! is_array( $translated_blocks ) || count( $translated_blocks ) !== count( $stored ) ) {
			// Block structure of the translation diverged from what we recorded
			// (manual edits, inserts, deletes) — a positional mapping would be
			// wrong, so force a full retranslation.
			return null;
		}

		$stored            = array_values( $stored );
		$translated_blocks = array_values( $translated_blocks );

		$map = array();
		foreach ( $stored as $index => $source_hash ) {
			if ( ! is_string( $source_hash ) || '' === $source_hash || ! isset( $translated_blocks[ $index ] ) ) {
				continue;
			}
			if ( ! isset( $map[ $source_hash ] ) ) {
				$map[ $source_hash ] = serialize_blocks( array( $translated_blocks[ $index ] ) );
			}
		}

		if ( empty( $map ) ) {
			return null;
		}

		$reusable = 0;
		foreach ( $source_blocks as $block ) {
			if ( is_array( $block ) && isset( $map[ self::hash( serialize_blocks( array( $block ) ) ) ] ) ) {
				$reusable++;
			}
		}

		return $reusable > 0 ? $map : null;
	}

	/**
	 * Persist block and meta fingerprints of the source on the translated post
	 * so the next overwrite can diff against them.
	 *
	 * @param int      $translated_post_id Target post that received the translation.
	 * @param array    $source_blocks      parse_blocks() result of the source content (may be empty).
	 * @param array    $all_meta           Raw source post meta.
	 * @param string[] $translated_keys    Meta keys that went through translation.
	 */
	public static function store_fingerprints( int $translated_post_id, array $source_blocks, array $all_meta, array $translated_keys ): void {
		if ( $translated_post_id < 1 ) {
			return;
		}

		$block_hashes = self::compute_block_hashes( $source_blocks );
		if ( ! empty( $block_hashes ) ) {
			update_post_meta( $translated_post_id, self::UNIT_HASHES_META_KEY, $block_hashes );
		} else {
			delete_post_meta( $translated_post_id, self::UNIT_HASHES_META_KEY );
		}

		$meta_hashes = self::compute_meta_hashes( $all_meta, $translated_keys );
		if ( ! empty( $meta_hashes ) ) {
			update_post_meta( $translated_post_id, self::META_HASHES_META_KEY, $meta_hashes );
		} else {
			delete_post_meta( $translated_post_id, self::META_HASHES_META_KEY );
		}
	}

	/**
	 * Translatable meta keys whose source value is unchanged compared to the
	 * fingerprints stored on the existing translation.
	 *
	 * @param int      $translated_post_id Existing translation.
	 * @param array    $all_meta           Raw source post meta.
	 * @param string[] $translate_keys     Effective translate keys for the source post.
	 * @return string[] Keys that can keep their already-translated target value.
	 */
	public static function get_unchanged_meta_keys( int $translated_post_id, array $all_meta, array $translate_keys ): array {
		if ( $translated_post_id < 1 || empty( $translate_keys ) ) {
			return array();
		}

		$stored = get_post_meta( $translated_post_id, self::META_HASHES_META_KEY, true );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return array();
		}

		$current   = self::compute_meta_hashes( $all_meta, $translate_keys );
		$unchanged = array();
		foreach ( $current as $key => $hash ) {
			if ( isset( $stored[ $key ] ) && $stored[ $key ] === $hash ) {
				$unchanged[] = $key;
			}
		}

		return $unchanged;
	}
}
