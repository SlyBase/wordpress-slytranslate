<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

class TextSplitter {

	private const MIN_TRANSLATION_CHARS = 1200;
	private const SMALL_BLOCK_GROUPING_MAX_CHARS = 900;

	public static function count_content_translation_chunks( string $content, int $chunk_char_limit ): int {
		if ( '' === trim( $content ) ) {
			return 0;
		}

		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return self::count_translation_chunks( $content, $chunk_char_limit );
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || empty( $blocks ) ) {
			return self::count_translation_chunks( $content, $chunk_char_limit );
		}

		$pending_blocks = array();
		$total_chunks   = 0;

		foreach ( $blocks as $block ) {
			if ( self::should_skip_block_translation( $block ) ) {
				$total_chunks  += self::count_serialized_block_chunks( $pending_blocks, $chunk_char_limit );
				$pending_blocks = array();
				continue;
			}

			$pending_blocks[] = $block;
		}

		$total_chunks += self::count_serialized_block_chunks( $pending_blocks, $chunk_char_limit );

		return $total_chunks;
	}

	public static function split_text_for_translation( string $text, int $max_chars ): array {
		$max_chars = max( self::MIN_TRANSLATION_CHARS, $max_chars );

		if ( self::text_length( $text ) <= $max_chars ) {
			return array( $text );
		}

		$segments = preg_split(
			'/(\r?\n\s*\r?\n+|<!--\s*\/?wp:[^>]+-->\s*|<\/(?:p|div|section|article|aside|blockquote|pre|ul|ol|li|h[1-6]|table|thead|tbody|tr|td|th|figure|figcaption|details|summary)>\s*)/iu',
			$text,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( false === $segments || empty( $segments ) ) {
			return self::split_segment_for_translation( $text, $max_chars );
		}

		$chunks  = array();
		$current = '';

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			$segment_chunks = self::split_segment_for_translation( $segment, $max_chars );
			foreach ( $segment_chunks as $segment_chunk ) {
				if ( '' === $current ) {
					$current = $segment_chunk;
					continue;
				}

				if ( self::text_length( $current ) + self::text_length( $segment_chunk ) <= $max_chars ) {
					$current .= $segment_chunk;
					continue;
				}

				$chunks[] = $current;
				$current  = $segment_chunk;
			}
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	public static function split_segment_for_translation( string $segment, int $max_chars ): array {
		$max_chars = max( self::MIN_TRANSLATION_CHARS, $max_chars );

		if ( self::text_length( $segment ) <= $max_chars ) {
			return array( $segment );
		}

		$parts = preg_split( '/(\s+)/u', $segment, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts || empty( $parts ) ) {
			return self::hard_split_text( $segment, $max_chars );
		}

		$chunks  = array();
		$current = '';

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			if ( self::text_length( $part ) > $max_chars ) {
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}

				$chunks = array_merge( $chunks, self::hard_split_text( $part, $max_chars ) );
				continue;
			}

			if ( '' === $current ) {
				$current = $part;
				continue;
			}

			if ( self::text_length( $current ) + self::text_length( $part ) <= $max_chars ) {
				$current .= $part;
				continue;
			}

			$chunks[] = $current;
			$current  = $part;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	public static function hard_split_text( string $text, int $max_chars ): array {
		$max_chars = max( self::MIN_TRANSLATION_CHARS, $max_chars );
		$chunks    = array();
		$length    = self::text_length( $text );

		for ( $offset = 0; $offset < $length; $offset += $max_chars ) {
			$chunks[] = self::text_substr( $text, $offset, $max_chars );
		}

		return $chunks;
	}

	public static function should_skip_block_translation( array $block ): bool {
		$block_name = $block['blockName'] ?? '';
		if ( ! is_string( $block_name ) ) {
			$block_name = '';
		}

		$skip_block_names = array(
			'core/code',
			'core/preformatted',
			'core/html',
			'core/shortcode',
			'core/embed',
			'kevinbatdorf/code-block-pro',
		);

		if ( in_array( $block_name, $skip_block_names, true ) ) {
			return true;
		}

		$attrs = $block['attrs'] ?? array();
		if ( ! is_array( $attrs ) ) {
			return false;
		}

		return isset( $attrs['code'] ) || isset( $attrs['codeHTML'] );
	}

	public static function should_translate_block_fragment( string $fragment ): bool {
		if ( '' === trim( $fragment ) ) {
			return false;
		}

		$text_content = wp_strip_all_tags( $fragment );
		$text_content = html_entity_decode( $text_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text_content = preg_replace( '/\s+/u', ' ', $text_content );

		return is_string( $text_content ) && '' !== trim( $text_content );
	}

	/**
	 * Group translatable blocks into size-bounded groups that each fit within
	 * $chunk_char_limit, without ever splitting an individual block across groups.
	 *
	 * A single block that exceeds $chunk_char_limit on its own forms a group by itself;
	 * the downstream translation layer handles oversized single-block inputs the same
	 * way it always has.
	 *
	 * @param array[] $blocks                Parsed Gutenberg blocks (translatable only).
	 * @param int     $chunk_char_limit      Maximum serialized characters per group.
	 * @param int     $soft_min_group_blocks Optional soft lower bound for group size.
	 *                                       Small groups below this threshold are
	 *                                       opportunistically merged with adjacent
	 *                                       groups when allowed by $soft_merge_char_limit.
	 * @param int     $soft_merge_char_limit Optional merge ceiling used by the soft
	 *                                       minimum heuristic. Defaults to
	 *                                       $chunk_char_limit when not provided.
	 * @return array[]                  Array of block groups (each group is an array of blocks).
	 */
	public static function group_blocks_for_translation(
		array $blocks,
		int $chunk_char_limit,
		int $soft_min_group_blocks = 1,
		int $soft_merge_char_limit = 0
	): array {
		$chunk_char_limit = max( self::MIN_TRANSLATION_CHARS, $chunk_char_limit );
		$groups           = array();
		$current_group    = array();
		$current_size     = 0;

		foreach ( $blocks as $block ) {
			$block_size = self::text_length( serialize_blocks( array( $block ) ) );

			if ( ! empty( $current_group ) && $current_size + $block_size > $chunk_char_limit ) {
				$groups[]      = $current_group;
				$current_group = array();
				$current_size  = 0;
			}

			$current_group[] = $block;
			$current_size   += $block_size;
		}

		if ( ! empty( $current_group ) ) {
			$groups[] = $current_group;
		}

		if ( $soft_min_group_blocks > 1 ) {
			$groups = self::rebalance_small_groups(
				$groups,
				$soft_min_group_blocks,
				$soft_merge_char_limit > 0 ? $soft_merge_char_limit : $chunk_char_limit
			);
		}

		return $groups;
	}

	/**
	 * Merge small groups below the soft minimum into adjacent groups when safe.
	 *
	 * This is intentionally heuristic: groups that cannot be merged while
	 * staying below $soft_merge_char_limit remain unchanged.
	 *
	 * @param array[] $groups
	 * @return array[]
	 */
	private static function rebalance_small_groups(
		array $groups,
		int $soft_min_group_blocks,
		int $soft_merge_char_limit
	): array {
		if ( count( $groups ) < 2 ) {
			return $groups;
		}

		$soft_min_group_blocks = max( 2, $soft_min_group_blocks );
		$soft_merge_char_limit = max( self::MIN_TRANSLATION_CHARS, $soft_merge_char_limit );

		$rebalanced = array();
		$count      = count( $groups );

		for ( $index = 0; $index < $count; $index++ ) {
			$current = $groups[ $index ];

			if ( count( $current ) < $soft_min_group_blocks && self::is_small_block_group( $current ) ) {
				if ( isset( $groups[ $index + 1 ] )
					&& self::can_merge_groups_with_limit( $current, $groups[ $index + 1 ], $soft_merge_char_limit )
				) {
					$rebalanced[] = array_merge( $current, $groups[ $index + 1 ] );
					++$index;
					continue;
				}

				$last_index = count( $rebalanced ) - 1;
				if ( $last_index >= 0
					&& self::can_merge_groups_with_limit( $rebalanced[ $last_index ], $current, $soft_merge_char_limit )
				) {
					$rebalanced[ $last_index ] = array_merge( $rebalanced[ $last_index ], $current );
					continue;
				}
			}

			$rebalanced[] = $current;
		}

		return $rebalanced;
	}

	/**
	 * @param array[] $group
	 */
	private static function is_small_block_group( array $group ): bool {
		if ( empty( $group ) ) {
			return false;
		}

		foreach ( $group as $block ) {
			$size = self::text_length( serialize_blocks( array( $block ) ) );
			if ( $size > self::SMALL_BLOCK_GROUPING_MAX_CHARS ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array[] $left
	 * @param array[] $right
	 */
	private static function can_merge_groups_with_limit( array $left, array $right, int $char_limit ): bool {
		if ( empty( $left ) || empty( $right ) ) {
			return false;
		}

		$merged_size = self::text_length( serialize_blocks( array_merge( $left, $right ) ) );

		return $merged_size > 0 && $merged_size <= $char_limit;
	}

	private static function count_translation_chunks( string $text, int $chunk_char_limit ): int {
		return count( self::split_text_for_translation( $text, $chunk_char_limit ) );
	}

	private static function count_serialized_block_chunks( array $blocks, int $chunk_char_limit ): int {
		if ( empty( $blocks ) ) {
			return 0;
		}

		return count( self::group_blocks_for_translation( $blocks, $chunk_char_limit ) );
	}

	private static function text_length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		return strlen( $text );
	}

	private static function text_substr( string $text, int $offset, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $offset, $length, 'UTF-8' );
		}

		return substr( $text, $offset, $length );
	}
}