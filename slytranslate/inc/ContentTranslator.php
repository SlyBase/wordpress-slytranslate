<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Block-aware content translation pipeline.
 *
 * Parses post content into Gutenberg blocks, skips non-translatable blocks
 * (code, preformatted, …), and batches translatable runs for efficiency.
 */
class ContentTranslator {

	private const LIST_ITEM_BATCH_MIN_ITEMS = 2;
	private const LIST_ITEM_BATCH_MAX_ITEMS = 12;
	private const LIST_ITEM_BATCH_MAX_CHARS = 1800;
	private const MICRO_BATCH_MIN_ITEMS = 3;
	private const MICRO_BATCH_MIN_ITEMS_ADAPTIVE = 2;
	private const MICRO_BATCH_MAX_ITEMS = 12;
	private const MICRO_BATCH_MAX_CHARS = 2200;
	private const MICRO_BATCH_ADAPTIVE_MAX_TOTAL_BLOCKS = 12;
	private const MICRO_BATCH_ADAPTIVE_MAX_TOTAL_CHARS = 4800;
	private const PLACEHOLDER_STRICT_PROMPT_MIN_COMMENTS = 4;
	private const SMALL_WRAPPER_BLOCK_MAX_CHARS = 900;
	private const TINY_GROUP_CHAR_THRESHOLD = 260;
	private const TINY_GROUP_COALESCE_MAX_CHARS = 900;
	private const TINY_GROUP_SERIES_STOP_THRESHOLD = 3;

	/**
	 * Translate full post content, using the block parser when available.
	 *
	 * @return string|\WP_Error
	 */
	public static function translate_post_content(
		string $content,
		string $to,
		string $from = 'en',
		string $additional_prompt = ''
	): string|\WP_Error {
		if ( '' === trim( $content ) ) {
			return '';
		}

		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return TranslationRuntime::translate_text( $content, $to, $from, $additional_prompt );
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || empty( $blocks ) ) {
			return TranslationRuntime::translate_text( $content, $to, $from, $additional_prompt );
		}

		return self::translate_block_sections( $blocks, $to, $from, $additional_prompt );
	}

	/**
	 * Translate an already-parsed block tree. Avoids re-parsing when the caller
	 * already holds the block array from a previous parse_blocks() call.
	 *
	 * @param array|null $blocks    Pre-parsed block array, or null to fall back to raw content parsing.
	 * @param string     $raw       Raw post content (used when $blocks is null or empty).
	 * @param string     $to        Target language code.
	 * @param string     $from      Source language code.
	 * @param string     $additional_prompt
	 * @return string|\WP_Error
	 */
	public static function translate_parsed_blocks(
		?array $blocks,
		string $raw,
		string $to,
		string $from = 'en',
		string $additional_prompt = ''
	): string|\WP_Error {
		if ( is_array( $blocks ) && ! empty( $blocks ) ) {
			if ( '' === trim( $raw ) ) {
				return '';
			}
			return self::translate_block_sections( $blocks, $to, $from, $additional_prompt );
		}
		return self::translate_post_content( $raw, $to, $from, $additional_prompt );
	}

	/**
	 * Split blocks into translatable / non-translatable runs and translate each run.
	 * Translatable runs are further grouped into size-bounded chunks so that individual
	 * blocks are never split across API calls.
	 *
	 * @return string|\WP_Error
	 */
	private static function translate_block_sections(
		array $blocks,
		string $to,
		string $from = 'en',
		string $additional_prompt = ''
	): mixed {
		// Block structure is guaranteed by reconstruction at this level; skip strict
		// HTML tag count validation that fires on inline formatting loss (e.g. <strong>).
		TranslationRuntime::set_skip_html_tag_validation( true );

		try {
			return self::do_translate_block_sections( $blocks, $to, $from, $additional_prompt );
		} finally {
			TranslationRuntime::set_skip_html_tag_validation( false );
		}
	}

	private static function do_translate_block_sections(
		array $blocks,
		string $to,
		string $from,
		string $additional_prompt
	): mixed {
		$translated_sections = array();
		$pending_blocks      = array();
		$chunk_char_limit    = TranslationRuntime::get_chunk_char_limit();

		foreach ( $blocks as $block ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			// parse_blocks() emits freeform null blocks for inter-block whitespace.
			// Treating these as hard run boundaries creates mostly 1-block groups
			// and prevents micro-batching on real neighboring content blocks.
			if ( self::is_ignorable_whitespace_block( $block ) ) {
				continue;
			}

			if ( self::should_skip_block( $block ) || ! self::has_translatable_content( $block ) ) {
				$result = self::translate_pending_blocks( $pending_blocks, $chunk_char_limit, $to, $from, $additional_prompt );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$translated_sections = array_merge( $translated_sections, $result );
				$pending_blocks      = array();
				$translated_sections[] = serialize_blocks( array( $block ) );
				continue;
			}

			$pending_blocks[] = $block;
		}

		$result = self::translate_pending_blocks( $pending_blocks, $chunk_char_limit, $to, $from, $additional_prompt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$translated_sections = array_merge( $translated_sections, $result );

		return implode( '', $translated_sections );
	}

	/**
	 * Group pending translatable blocks into size-bounded chunks and translate each group.
	 *
	 * @return string[]|\WP_Error Array of translated strings, or WP_Error on first failure.
	 */
	private static function translate_pending_blocks(
		array $pending_blocks,
		int $chunk_char_limit,
		string $to,
		string $from,
		string $additional_prompt
	): mixed {
		if ( empty( $pending_blocks ) ) {
			return array();
		}

		$sections      = array();
		$pending_chars = self::serialized_block_char_length( $pending_blocks );
		$groups        = self::prepare_translation_groups( $pending_blocks, $chunk_char_limit );

		$single_block_groups = 0;
		foreach ( $groups as $group ) {
			if ( 1 === count( $group ) ) {
				++$single_block_groups;
			}
		}

		TimingLogger::increment( 'content_groups_total', count( $groups ) );
		TimingLogger::increment( 'content_single_block_groups', $single_block_groups );
		TimingLogger::log( 'content_group_plan', array(
			'groups'              => count( $groups ),
			'single_block_groups' => $single_block_groups,
			'pending_blocks'      => count( $pending_blocks ),
			'pending_chars'       => $pending_chars,
		) );

		foreach ( $groups as $group ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			$micro_batch = self::translate_short_non_list_blocks_batch(
				$group,
				$to,
				$from,
				$additional_prompt,
				array(
					'pending_blocks' => count( $pending_blocks ),
					'pending_chars'  => $pending_chars,
				)
			);
			if ( is_string( $micro_batch ) ) {
				$sections[] = $micro_batch;
				continue;
			}

			$group_started   = TimingLogger::start();
			$calls_before    = (int) ( TimingLogger::get_counters()['ai_calls'] ?? 0 );
			$group_chars     = function_exists( 'serialize_blocks' ) && function_exists( 'mb_strlen' )
				? (int) mb_strlen( serialize_blocks( $group ), 'UTF-8' )
				: 0;
			$translated      = self::translate_serialized_blocks( $group, $to, $from, $additional_prompt );
			$calls_after     = (int) ( TimingLogger::get_counters()['ai_calls'] ?? 0 );
			$subcalls        = $calls_after - $calls_before;

			if ( $group_chars > 0 && $group_chars <= self::TINY_GROUP_CHAR_THRESHOLD && $subcalls > 0 ) {
				TimingLogger::increment( 'tiny_calls', $subcalls );
				TimingLogger::log( 'content_tiny_group', array(
					'blocks'   => count( $group ),
					'chars'    => $group_chars,
					'subcalls' => $subcalls,
				) );
			}

			$ok              = ! is_wp_error( $translated );
			TimingLogger::log( 'content_group_done', array(
				'blocks'      => count( $group ),
				'chars'       => $group_chars,
				'subcalls'    => $subcalls,
				'duration_ms' => TimingLogger::stop( $group_started ),
				'ok'          => $ok,
				'reason'      => $ok ? '' : $translated->get_error_code(),
			) );

			if ( ! $ok ) {
				return $translated;
			}

			if ( '' !== $translated ) {
				$sections[] = $translated;
			}
		}

		return $sections;
	}

	/**
	 * Build translation groups with wrapper-aware consolidation heuristics.
	 *
	 * The soft-min rebalancing in TextSplitter reduces avoidable one-block
	 * groups. Additional passes then merge adjacent short wrapper groups and
	 * coalesce tiny-group series so the micro-batch path can activate.
	 *
	 * @param array[] $pending_blocks
	 * @return array[]
	 */
	private static function prepare_translation_groups( array $pending_blocks, int $chunk_char_limit ): array {
		$groups = TextSplitter::group_blocks_for_translation(
			$pending_blocks,
			$chunk_char_limit,
			2,
			self::MICRO_BATCH_MAX_CHARS
		);

		if ( empty( $groups ) ) {
			return $groups;
		}

		$groups = self::apply_min_block_group_heuristic( $groups );
		$groups = self::consolidate_small_wrapper_groups( $groups );
		$groups = self::coalesce_tiny_group_series( $groups );

		return self::isolate_nested_block_groups( $groups );
	}

	/**
	 * Ensure small one-block groups are merged with adjacent wrapper groups when possible.
	 *
	 * @param array[] $groups
	 * @return array[]
	 */
	private static function apply_min_block_group_heuristic( array $groups ): array {
		$rebalanced = array();
		$count      = count( $groups );
		$merges     = 0;

		for ( $index = 0; $index < $count; $index++ ) {
			$current = $groups[ $index ];

			if ( 1 === count( $current ) && self::is_small_wrapper_group( $current ) ) {
				if ( isset( $groups[ $index + 1 ] ) && self::can_merge_wrapper_groups( $current, $groups[ $index + 1 ] ) ) {
					$rebalanced[] = array_merge( $current, $groups[ $index + 1 ] );
					++$index;
					++$merges;
					continue;
				}

				$last_index = count( $rebalanced ) - 1;
				if ( $last_index >= 0 && self::can_merge_wrapper_groups( $rebalanced[ $last_index ], $current ) ) {
					$rebalanced[ $last_index ] = array_merge( $rebalanced[ $last_index ], $current );
					++$merges;
					continue;
				}
			}

			$rebalanced[] = $current;
		}

		if ( $merges > 0 ) {
			TimingLogger::log( 'content_group_compaction', array(
				'stage'  => 'min_block_heuristic',
				'merges' => $merges,
			) );
		}

		return $rebalanced;
	}

	/**
	 * Consolidate consecutive short-wrapper groups before translation.
	 *
	 * @param array[] $groups
	 * @return array[]
	 */
	private static function consolidate_small_wrapper_groups( array $groups ): array {
		if ( count( $groups ) < 2 ) {
			return $groups;
		}

		$compacted = array();
		$merges    = 0;

		foreach ( $groups as $group ) {
			if ( empty( $compacted ) ) {
				$compacted[] = $group;
				continue;
			}

			$last_index = count( $compacted ) - 1;
			if ( self::can_merge_wrapper_groups( $compacted[ $last_index ], $group ) ) {
				$compacted[ $last_index ] = array_merge( $compacted[ $last_index ], $group );
				++$merges;
				continue;
			}

			$compacted[] = $group;
		}

		if ( $merges > 0 ) {
			TimingLogger::log( 'content_group_compaction', array(
				'stage'  => 'wrapper_pre_consolidation',
				'merges' => $merges,
			) );
		}

		return $compacted;
	}

	/**
	 * Coalesce runs of tiny wrapper groups to reduce tiny-call series.
	 *
	 * @param array[] $groups
	 * @return array[]
	 */
	private static function coalesce_tiny_group_series( array $groups ): array {
		if ( count( $groups ) < 2 ) {
			return $groups;
		}

		$coalesced        = array();
		$series_count     = 0;
		$merged_group_sum = 0;
		$index            = 0;
		$total            = count( $groups );

		while ( $index < $total ) {
			if ( ! self::is_tiny_wrapper_group( $groups[ $index ] ) ) {
				$coalesced[] = $groups[ $index ];
				++$index;
				continue;
			}

			$run = array();
			while ( $index < $total && self::is_tiny_wrapper_group( $groups[ $index ] ) ) {
				$run[] = $groups[ $index ];
				++$index;
			}

			if ( count( $run ) >= self::TINY_GROUP_SERIES_STOP_THRESHOLD ) {
				++$series_count;
			}

			$packed = self::pack_tiny_group_series( $run );
			$merged_group_sum += max( 0, count( $run ) - count( $packed ) );
			$coalesced = array_merge( $coalesced, $packed );
		}

		if ( $merged_group_sum > 0 ) {
			TimingLogger::log( 'content_tiny_series_coalesced', array(
				'series'        => $series_count,
				'merged_groups' => $merged_group_sum,
				'stop_after'    => self::TINY_GROUP_SERIES_STOP_THRESHOLD,
			) );
		}

		return $coalesced;
	}

	/**
	 * Split mixed groups so nested-wrapper blocks do not force flat wrappers
	 * into expensive group-level structure-drift fallbacks.
	 *
	 * @param array[] $groups
	 * @return array[]
	 */
	private static function isolate_nested_block_groups( array $groups ): array {
		if ( empty( $groups ) ) {
			return $groups;
		}

		$isolated        = array();
		$split_groups    = 0;
		$isolated_blocks = 0;

		foreach ( $groups as $group ) {
			if ( count( $group ) < 2 || ! self::is_mixed_nested_group( $group ) ) {
				$isolated[] = $group;
				continue;
			}

			$flat_buffer = array();

			foreach ( $group as $block ) {
				if ( self::has_recursive_inner_blocks( $block ) ) {
					if ( ! empty( $flat_buffer ) ) {
						$isolated[]  = $flat_buffer;
						$flat_buffer = array();
					}

					$isolated[] = array( $block );
					++$isolated_blocks;
					continue;
				}

				$flat_buffer[] = $block;
			}

			if ( ! empty( $flat_buffer ) ) {
				$isolated[] = $flat_buffer;
			}

			++$split_groups;
		}

		if ( $split_groups > 0 ) {
			TimingLogger::log( 'content_group_compaction', array(
				'stage'           => 'nested_block_isolation',
				'groups'          => $split_groups,
				'isolated_blocks' => $isolated_blocks,
			) );
		}

		return $isolated;
	}

	/**
	 * @param array[] $group
	 */
	private static function is_mixed_nested_group( array $group ): bool {
		$has_nested = false;
		$has_flat   = false;

		foreach ( $group as $block ) {
			if ( ! is_array( $block ) ) {
				return false;
			}

			if ( self::has_recursive_inner_blocks( $block ) ) {
				$has_nested = true;
			} else {
				$has_flat = true;
			}

			if ( $has_nested && $has_flat ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<int, array<string, mixed>>> $series
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private static function pack_tiny_group_series( array $series ): array {
		if ( empty( $series ) ) {
			return array();
		}

		$packed  = array();
		$current = array_shift( $series );

		foreach ( $series as $group ) {
			if ( self::can_merge_wrapper_groups( $current, $group, self::TINY_GROUP_COALESCE_MAX_CHARS ) ) {
				$current = array_merge( $current, $group );
				continue;
			}

			$packed[] = $current;
			$current  = $group;
		}

		$packed[] = $current;

		return $packed;
	}

	/**
	 * @param array[] $left
	 * @param array[] $right
	 */
	private static function can_merge_wrapper_groups( array $left, array $right, int $char_limit = self::MICRO_BATCH_MAX_CHARS ): bool {
		if ( empty( $left ) || empty( $right ) ) {
			return false;
		}

		if ( ! self::is_small_wrapper_group( $left ) || ! self::is_small_wrapper_group( $right ) ) {
			return false;
		}

		$combined_blocks = count( $left ) + count( $right );
		if ( $combined_blocks > self::MICRO_BATCH_MAX_ITEMS ) {
			return false;
		}

		$merged_chars = self::serialized_block_char_length( array_merge( $left, $right ) );
		return $merged_chars > 0 && $merged_chars <= max( 1, $char_limit );
	}

	/**
	 * @param array[] $group
	 */
	private static function is_small_wrapper_group( array $group ): bool {
		if ( empty( $group ) ) {
			return false;
		}

		foreach ( $group as $block ) {
			if ( ! is_array( $block ) ) {
				return false;
			}

			if ( ! self::is_wrapper_batch_candidate_block( $block ) ) {
				return false;
			}

			if ( self::serialized_block_char_length( array( $block ) ) > self::SMALL_WRAPPER_BLOCK_MAX_CHARS ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array[] $group
	 */
	private static function is_tiny_wrapper_group( array $group ): bool {
		if ( ! self::is_small_wrapper_group( $group ) ) {
			return false;
		}

		$chars = self::serialized_block_char_length( $group );
		return $chars > 0 && $chars <= self::TINY_GROUP_CHAR_THRESHOLD;
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function is_wrapper_batch_candidate_block( array $block ): bool {
		if ( ! empty( $block['innerBlocks'] ) ) {
			return false;
		}

		$block_name = (string) ( $block['blockName'] ?? '' );
		if ( 'core/list' === $block_name || 'core/list-item' === $block_name ) {
			return false;
		}

		return true;
	}

	/**
	 * True when a block contains meaningful nested block content.
	 */
	private static function has_recursive_inner_blocks( array $block ): bool {
		$inner_blocks = $block['innerBlocks'] ?? null;
		if ( ! is_array( $inner_blocks ) || empty( $inner_blocks ) ) {
			return false;
		}

		foreach ( $inner_blocks as $inner_block ) {
			if ( ! is_array( $inner_block ) ) {
				continue;
			}

			if ( ! empty( $inner_block['blockName'] ) || ! empty( $inner_block['innerBlocks'] ) ) {
				return true;
			}

			$inner_html = (string) ( $inner_block['innerHTML'] ?? '' );
			if ( '' !== trim( wp_strip_all_tags( $inner_html ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array[] $blocks
	 */
	private static function serialized_block_char_length( array $blocks ): int {
		if ( empty( $blocks ) || ! function_exists( 'serialize_blocks' ) ) {
			return 0;
		}

		$serialized = serialize_blocks( $blocks );
		if ( ! is_string( $serialized ) || '' === $serialized ) {
			return 0;
		}

		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $serialized, 'UTF-8' );
		}

		return strlen( $serialized );
	}

	/**
	 * UTF-8-aware string length (falls back to strlen when mbstring is absent).
	 */
	private static function char_length( string $text ): int {
		return function_exists( 'mb_strlen' )
			? (int) mb_strlen( $text, 'UTF-8' )
			: strlen( $text );
	}

	/**
	 * True for freeform parse_blocks entries that carry only inter-block whitespace.
	 *
	 * Keeping these as hard section boundaries forces tiny 1-block translation
	 * runs. We can safely ignore them because block serialization already inserts
	 * stable separators around real blocks.
	 */
	private static function is_ignorable_whitespace_block( array $block ): bool {
		$block_name = $block['blockName'] ?? null;
		if ( is_string( $block_name ) && '' !== trim( $block_name ) ) {
			return false;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			return false;
		}

		$serialized = function_exists( 'serialize_blocks' )
			? serialize_blocks( array( $block ) )
			: (string) ( $block['innerHTML'] ?? '' );

		if ( '' === $serialized ) {
			return true;
		}

		$without_tags = wp_strip_all_tags( $serialized );
		return '' === trim( html_entity_decode( $without_tags, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function translate_serialized_blocks(
		array $blocks,
		string $to,
		string $from = 'en',
		string $additional_prompt = ''
	): mixed {
		if ( empty( $blocks ) ) {
			return '';
		}

		if ( self::should_use_recursive_inner_block_fast_path( $blocks ) ) {
			TimingLogger::log( 'content_group_fast_path', array(
				'reason'     => 'single_list_wrapper',
				'blocks'     => 1,
				'block_name' => $blocks[0]['blockName'] ?? '(unknown)',
			) );

			$fast_path_result = self::translate_block_with_inner_blocks( $blocks[0], $to, $from, $additional_prompt );
			if ( ! is_wp_error( $fast_path_result ) ) {
				return $fast_path_result;
			}

			if ( 'translation_cancelled' === $fast_path_result->get_error_code() ) {
				return $fast_path_result;
			}
		}

		$serialized = serialize_blocks( $blocks );
		if ( '' === trim( $serialized ) ) {
			return $serialized;
		}

		$result = self::translate_with_block_comment_preservation( $serialized, $to, $from, $additional_prompt );

		// If group translation failed a validator check (structural drift, length
		// drift, plain-text missing, runaway output, assistant reply, …), fall
		// back to translating each block individually. translate_single_block()
		// provides additional fallbacks (recursive inner-block translation,
		// keep-in-source last resort) that the group path does not.
		if ( self::is_validation_error( $result ) ) {
			TimingLogger::increment( 'fallbacks' );
			TimingLogger::log( 'content_block_fallback', array(
				'reason' => 'group_' . self::short_validation_reason( $result ),
				'blocks' => count( $blocks ),
			) );

			// Optimisation: when the group contains exactly one block, the group
			// serialization and the block serialization are identical, so calling
			// translate_with_block_comment_preservation() again inside
			// translate_single_block() would just repeat the same failing model
			// call(s). Pass the existing result so the single-block fallback can
			// skip straight to inner-block recursion / keep-in-source.
			if ( 1 === count( $blocks ) ) {
				return self::translate_single_block( $blocks[0], $to, $from, $additional_prompt, $result );
			}

			$individual_results = array();
			foreach ( $blocks as $block ) {
				if ( TranslationProgressTracker::is_cancelled() ) {
					return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
				}
				$single_result = self::translate_single_block( $block, $to, $from, $additional_prompt );
				if ( is_wp_error( $single_result ) ) {
					return $single_result;
				}
				$individual_results[] = $single_result;
			}
			return implode( '', $individual_results );
		}

		return $result;
	}

	/**
	 * Translate a single parsed block, with recursive fallback for blocks that have inner blocks.
	 *
	 * @return string|\WP_Error
	 */
	private static function translate_single_block(
		array $block,
		string $to,
		string $from,
		string $additional_prompt,
		$prior_result = null
	): mixed {
		$single_serialized = serialize_blocks( array( $block ) );

		// Re-use a prior WBCP result when the caller already ran exactly this
		// call (e.g. a single-block group that failed validation). Only accept
		// WP_Error results so we never skip a successful translation attempt.
		if ( is_wp_error( $prior_result ) ) {
			$result = $prior_result;
		} else {
			$result = self::translate_with_block_comment_preservation( $single_serialized, $to, $from, $additional_prompt );
		}

		// When a block with inner blocks (e.g. core/list) fails placeholder preservation,
		// translate its inner blocks individually and reconstruct the outer wrapper.
		if ( is_wp_error( $result )
			&& 'invalid_translation_structure_drift' === $result->get_error_code()
			&& ! empty( $block['innerBlocks'] )
		) {
			$recursive = self::translate_block_with_inner_blocks( $block, $to, $from, $additional_prompt );
			if ( ! is_wp_error( $recursive ) ) {
				return $recursive;
			}
			$result = $recursive;
		}

		if ( is_wp_error( $result ) && 'invalid_translation_language_passthrough' === $result->get_error_code() ) {
			$recovered = self::recover_passthrough_single_block_translation( $single_serialized, $to, $from, $additional_prompt );
			if ( ! is_wp_error( $recovered ) ) {
				TimingLogger::log( 'content_block_passthrough_recovered', array(
					'block_name' => $block['blockName'] ?? '(unknown)',
				) );
				return $recovered;
			}
			$result = $recovered;
		}

		// Last-resort fallback: a single block that keeps failing validator
		// checks (structural drift, length drift, plain-text missing, runaway
		// output, assistant reply) would otherwise tear down the entire
		// post-translation job. Returning the original serialized block keeps
		// the post mostly translated and only leaves this one block in the
		// source language, which is far better UX than a hard failure. Real
		// errors (cancellation, model errors, transport errors) are still
		// propagated unchanged.
		if ( self::is_validation_error( $result ) ) {
			if ( 'invalid_translation_language_passthrough' === $result->get_error_code() ) {
				return $result;
			}

			$reason = self::short_validation_reason( $result );
			TimingLogger::log( 'content_block_kept_in_source', array(
				'reason'     => $reason,
				'block_name' => $block['blockName'] ?? '(unknown)',
			) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$preview = mb_substr( preg_replace( '/\s+/', ' ', $single_serialized ) ?? '', 0, 200 );
				TimingLogger::log( 'content_block_kept_in_source_debug', array(
					'reason'     => $reason,
					'block_name' => $block['blockName'] ?? '(unknown)',
					'preview'    => $preview,
				) );
			}
			return $single_serialized;
		}

		return $result;
	}

	/**
	 * Retry a single-block translation without Gutenberg placeholders when the
	 * model echoed source language content unchanged.
	 *
	 * @return string|\WP_Error
	 */
	private static function recover_passthrough_single_block_translation(
		string $single_serialized,
		string $to,
		string $from,
		string $additional_prompt
	): mixed {
		$matches = array();
		$count   = preg_match_all( '/<!--\s*\/?wp:[^>]+-->/iu', $single_serialized, $matches );
		if ( false === $count || empty( $matches[0] ) || ! is_array( $matches[0] ) ) {
			return new \WP_Error( 'invalid_translation_language_passthrough', TranslationValidator::get_language_passthrough_error_message( $to ) );
		}

		$block_comments = $matches[0];
		$inner_html     = self::extract_simple_wrapper_inner_html( $single_serialized, $block_comments );
		if ( null === $inner_html ) {
			return new \WP_Error( 'invalid_translation_language_passthrough', TranslationValidator::get_language_passthrough_error_message( $to ) );
		}

		$pass_through_hint = 'CRITICAL: The previous attempt returned source-language text unchanged. Translate every sentence into the target language and keep source-language carry-over to an absolute minimum.';
		$plain_text_hint   = 'The input is a short plain-text snippet from one Gutenberg block. Return only the translated text, no explanations.';
		$block_html_hint   = 'The input is HTML from one Gutenberg block. Translate only visible text and preserve HTML tags exactly. Return only translated HTML.';
		$plain_text_prompt = trim( $additional_prompt . "\n\n" . $pass_through_hint . "\n\n" . $plain_text_hint );
		$block_html_prompt = trim( $additional_prompt . "\n\n" . $pass_through_hint . "\n\n" . $block_html_hint );
		$unwrapped         = self::unwrap_single_element( $inner_html );

		if ( null !== $unwrapped ) {
			$prompt             = self::unwrapped_content_prompt( $unwrapped['content'], $plain_text_prompt, $block_html_prompt );
			$translated_content = TranslationRuntime::translate_text( $unwrapped['content'], $to, $from, $prompt );
			if ( is_wp_error( $translated_content ) ) {
				return $translated_content;
			}

			$translated_content_str = trim( (string) $translated_content );
			if ( str_starts_with( $translated_content_str, $unwrapped['open_tag'] )
				&& str_ends_with( $translated_content_str, $unwrapped['close_tag'] )
			) {
				$translated_content_str = trim( substr(
					$translated_content_str,
					strlen( $unwrapped['open_tag'] ),
					-strlen( $unwrapped['close_tag'] )
				) );
			}

			$translated_inner = $unwrapped['open_tag'] . $translated_content_str . $unwrapped['close_tag'];
		} else {
			$translated_inner = TranslationRuntime::translate_text( $inner_html, $to, $from, $block_html_prompt );
			if ( is_wp_error( $translated_inner ) ) {
				return $translated_inner;
			}
			$translated_inner = (string) $translated_inner;
		}

		$reconstructed = $block_comments[0] . "\n" . $translated_inner . "\n" . $block_comments[ count( $block_comments ) - 1 ];
		$validation    = TranslationValidator::validate( $single_serialized, $reconstructed, $to );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		return $reconstructed;
	}

	/**
	 * True when $value is a WP_Error produced by TranslationValidator (codes
	 * prefixed with invalid_translation_).
	 */
	private static function is_validation_error( $value ): bool {
		if ( ! is_wp_error( $value ) ) {
			return false;
		}

		return 0 === strpos( (string) $value->get_error_code(), 'invalid_translation_' );
	}

	/**
	 * Return the reason suffix of a validation error code for log tagging.
	 * E.g. 'invalid_translation_length_drift' -> 'length_drift'.
	 */
	private static function short_validation_reason( \WP_Error $error ): string {
		$code = (string) $error->get_error_code();
		return (string) preg_replace( '/^invalid_translation_/', '', $code );
	}

	/**
	 * Single core/list wrapper blocks with innerBlocks are a poor fit for the
	 * placeholder-preservation group path: live logs showed repeated
	 * group_structure_drift failures followed by a successful recursive
	 * inner-block translation of the exact same list. Route these blocks
	 * directly to the existing recursive helper and skip the wasted first
	 * outer-block model call.
	 */
	private static function should_use_recursive_inner_block_fast_path( array $blocks ): bool {
		if ( 1 !== count( $blocks ) ) {
			return false;
		}

		$block = $blocks[0];
		if ( ! is_array( $block ) ) {
			return false;
		}

		$block_name = $block['blockName'] ?? '';
		if ( ! in_array( $block_name, array( 'core/list', 'core/group', 'core/quote', 'core/columns', 'core/column' ), true ) ) {
			return false;
		}

		return self::has_recursive_inner_blocks( $block );
	}

	/**
	 * Translate a block by recursively translating its inner blocks and preserving the outer wrapper.
	 *
	 * @return string|\WP_Error
	 */
	private static function translate_block_with_inner_blocks(
		array $block,
		string $to,
		string $from,
		string $additional_prompt
	): mixed {
		$list_item_batch = self::translate_simple_list_items_batch( $block, $to, $from, $additional_prompt );
		if ( is_array( $list_item_batch ) ) {
			$translated_inner_blocks = $list_item_batch;
		} else {
			$translated_inner_blocks = array();
			foreach ( $block['innerBlocks'] as $inner_block ) {
				if ( TranslationProgressTracker::is_cancelled() ) {
					return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
				}
				$inner_result = self::translate_single_block( $inner_block, $to, $from, $additional_prompt );
				if ( is_wp_error( $inner_result ) ) {
					return $inner_result;
				}
				$translated_inner_blocks[] = $inner_result;
			}
		}

		// Reconstruct: use the block's innerContent template which interleaves
		// static HTML (null entries = inner block slots) with literal content.
		$inner_content = $block['innerContent'] ?? array();
		$inner_idx     = 0;
		$output        = '';

		foreach ( $inner_content as $piece ) {
			if ( null === $piece ) {
				// Slot for an inner block.
				$output .= $translated_inner_blocks[ $inner_idx ] ?? '';
				++$inner_idx;
			} else {
				// Static HTML between inner blocks (e.g. <ul>, </ul> wrappers).
				$output .= $piece;
			}
		}

		// Wrap with the outer block comment.
		$block_name = self::normalize_block_comment_name( (string) ( $block['blockName'] ?? '' ) );
		$attrs_json = ! empty( $block['attrs'] ) ? ' ' . wp_json_encode( $block['attrs'] ) : '';
		$open_comment  = '<!-- wp:' . $block_name . $attrs_json . ' -->';
		$close_comment = '<!-- /wp:' . $block_name . ' -->';

		return $open_comment . "\n" . $output . "\n" . $close_comment;
	}

	/**
	 * Try to translate simple list-item inner blocks in a single JSON batch call.
	 *
	 * Returns null when the block is not an eligible list shape or when any
	 * batch step fails; callers then fall back to the existing per-item path.
	 *
	 * @return string[]|null Serialized translated list-item blocks or null.
	 */
	private static function translate_simple_list_items_batch(
		array $block,
		string $to,
		string $from,
		string $additional_prompt
	): ?array {
		if ( 'core/list' !== ( $block['blockName'] ?? '' ) ) {
			return self::skip_list_batch( 'not_core_list' );
		}

		TimingLogger::increment( 'list_batch_candidates' );

		$inner_blocks = $block['innerBlocks'] ?? array();
		if ( ! is_array( $inner_blocks ) ) {
			return self::skip_list_batch( 'inner_blocks_not_array' );
		}

		$inner_count = count( $inner_blocks );
		if ( $inner_count < self::LIST_ITEM_BATCH_MIN_ITEMS || $inner_count > self::LIST_ITEM_BATCH_MAX_ITEMS ) {
			return self::skip_list_batch( 'item_count_out_of_range', array( 'items' => $inner_count ) );
		}

		$candidates   = array();
		$metadata     = array();
		$item_plan    = array();
		$total_chars  = 0;

		foreach ( $inner_blocks as $index => $inner_block ) {
			if ( ! is_array( $inner_block ) || 'core/list-item' !== ( $inner_block['blockName'] ?? '' ) ) {
				return self::skip_list_batch( 'non_list_item_inner_block', array( 'index' => $index ) );
			}

			if ( ! empty( $inner_block['innerBlocks'] ) ) {
				$item_plan[] = array(
					'type'  => 'recursive',
					'block' => $inner_block,
					'index' => $index,
				);
				continue;
			}

			$serialized = serialize_blocks( array( $inner_block ) );
			if ( '' === trim( $serialized ) ) {
				return self::skip_list_batch( 'empty_serialized_inner_block', array( 'index' => $index ) );
			}

			$matches = array();
			$count   = preg_match_all( '/<!--\s*\/?wp:[^>]+-->/iu', $serialized, $matches );
			if ( false === $count || empty( $matches[0] ) || ! is_array( $matches[0] ) ) {
				return self::skip_list_batch( 'missing_block_comments', array( 'index' => $index ) );
			}

			$block_comments = $matches[0];
			$inner_html     = self::extract_simple_wrapper_inner_html( $serialized, $block_comments );
			if ( null === $inner_html ) {
				return self::skip_list_batch( 'inner_html_extraction_failed', array( 'index' => $index ) );
			}

			$list_item_parts = self::extract_list_item_content_parts( $inner_html );
			if ( null === $list_item_parts ) {
				return self::skip_list_batch( 'list_item_wrapper_parse_failed', array( 'index' => $index ) );
			}

			$item_key                = 'item_' . $index;
			$candidates[ $item_key ] = $list_item_parts['content'];
			$metadata[ $item_key ]   = array(
				'open_tag'      => $list_item_parts['open_tag'],
				'close_tag'     => $list_item_parts['close_tag'],
				'open_comment'  => $block_comments[0],
				'close_comment' => $block_comments[ count( $block_comments ) - 1 ],
			);
			$item_plan[] = array(
				'type' => 'batch',
				'key'  => $item_key,
				'index'=> $index,
			);

			$total_chars += function_exists( 'mb_strlen' )
				? (int) mb_strlen( $list_item_parts['content'], 'UTF-8' )
				: strlen( $list_item_parts['content'] );
		}

		if ( count( $candidates ) < self::LIST_ITEM_BATCH_MIN_ITEMS ) {
			return self::skip_list_batch( 'insufficient_flat_items_for_batch', array( 'items' => count( $candidates ) ) );
		}

		if ( $total_chars < 1 || $total_chars > self::LIST_ITEM_BATCH_MAX_CHARS ) {
			return self::skip_list_batch( 'total_chars_out_of_range', array( 'total_chars' => $total_chars ) );
		}

		$json_input = wp_json_encode( $candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json_input ) {
			return self::skip_list_batch( 'json_encode_failed' );
		}

		$json_hint = 'The input is a JSON object of list-item content fragments. Translate only values, preserve inline HTML tags inside values, keep keys unchanged, and return ONLY valid JSON with the identical keys.';
		$prompt    = '' !== trim( $additional_prompt )
			? trim( $additional_prompt ) . "\n\n" . $json_hint
			: $json_hint;

		$result = TranslationRuntime::translate_text( $json_input, $to, $from, $prompt );
		if ( is_wp_error( $result ) ) {
			return self::skip_list_batch( 'batch_call_error', array( 'error' => $result->get_error_code() ) );
		}

		$result_str = trim( (string) $result );
		$result_str = (string) preg_replace( '/^```(?:json)?\s*/i', '', $result_str );
		$result_str = (string) preg_replace( '/\s*```\s*$/i', '', $result_str );
		$decoded    = json_decode( trim( $result_str ), true );
		if ( ! is_array( $decoded ) ) {
			return self::skip_list_batch( 'batch_json_decode_failed' );
		}

		$translated_inner_blocks = array();
		foreach ( $item_plan as $item_step ) {
			if ( 'recursive' === $item_step['type'] ) {
				$recursive = self::translate_single_block( $item_step['block'], $to, $from, $additional_prompt );
				if ( is_wp_error( $recursive ) ) {
					return self::skip_list_batch( 'recursive_item_failed', array(
						'index' => $item_step['index'],
						'error' => $recursive->get_error_code(),
					) );
				}
				$translated_inner_blocks[] = $recursive;
				continue;
			}

			$item_key = $item_step['key'];
			if ( ! array_key_exists( $item_key, $decoded ) || ! is_string( $decoded[ $item_key ] ) ) {
				return self::skip_list_batch( 'batch_missing_item_key', array( 'key' => $item_key ) );
			}

			$source_content     = $candidates[ $item_key ];
			$translated_content = $decoded[ $item_key ];
			$validation         = TranslationValidator::validate( $source_content, $translated_content, $to );
			if ( is_wp_error( $validation ) ) {
				return self::skip_list_batch( 'batch_item_validation_failed', array(
					'key'   => $item_key,
					'error' => $validation->get_error_code(),
				) );
			}

			$item_meta = $metadata[ $item_key ];
			$translated_inner_blocks[] = $item_meta['open_comment']
				. "\n"
				. $item_meta['open_tag']
				. $translated_content
				. $item_meta['close_tag']
				. "\n"
				. $item_meta['close_comment'];
		}

		TimingLogger::increment( 'list_batch_hits' );

		TimingLogger::log( 'content_list_batch', array(
			'items'      => count( $translated_inner_blocks ),
			'batched_items' => count( $candidates ),
			'recursive_items' => $inner_count - count( $candidates ),
			'total_chars'=> $total_chars,
			'subcalls'   => 1,
		) );

		return $translated_inner_blocks;
	}

	private static function skip_list_batch( string $reason, array $context = array() ): ?array {
		TimingLogger::increment( self::reason_counter_key( 'list_batch_skip_', $reason ) );

		$payload = array( 'reason' => $reason );
		if ( ! empty( $context ) ) {
			$payload = array_merge( $payload, $context );
		}

		TimingLogger::log( 'content_list_batch_skip', $payload );
		return null;
	}

	/**
	 * Try a single JSON call for a group of short, simple non-list blocks.
	 *
	 * Returns a translated serialized group string on success. Returns null
	 * when the group is not eligible or when validation fails.
	 */
	private static function resolve_micro_batch_min_items( array $group, array $context ): int {
		if ( 2 !== count( $group ) || ! self::is_small_wrapper_group( $group ) ) {
			return self::MICRO_BATCH_MIN_ITEMS;
		}

		$pending_blocks = (int) ( $context['pending_blocks'] ?? 0 );
		$pending_chars  = (int) ( $context['pending_chars'] ?? 0 );

		if ( ( $pending_blocks > 0 && $pending_blocks <= self::MICRO_BATCH_ADAPTIVE_MAX_TOTAL_BLOCKS )
			|| ( $pending_chars > 0 && $pending_chars <= self::MICRO_BATCH_ADAPTIVE_MAX_TOTAL_CHARS )
		) {
			return self::MICRO_BATCH_MIN_ITEMS_ADAPTIVE;
		}

		return self::MICRO_BATCH_MIN_ITEMS;
	}

	/**
	 * Returns a translated serialized group string on success. Returns null
	 * when the group is not eligible or when validation fails.
	 */
	private static function translate_short_non_list_blocks_batch(
		array $group,
		string $to,
		string $from,
		string $additional_prompt,
		array $context = array()
	): ?string {
		TimingLogger::increment( 'micro_batch_candidates' );

		$group_count = count( $group );
		$min_items   = self::resolve_micro_batch_min_items( $group, $context );
		if ( $group_count < $min_items || $group_count > self::MICRO_BATCH_MAX_ITEMS ) {
			return self::skip_micro_batch( 'group_size_out_of_range', array(
				'blocks'    => $group_count,
				'min_items' => $min_items,
			) );
		}

		$candidates  = array();
		$metadata    = array();
		$total_chars = 0;

		foreach ( $group as $index => $block ) {
			if ( ! is_array( $block ) ) {
				return self::skip_micro_batch( 'invalid_block_payload', array( 'index' => $index ) );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				return self::skip_micro_batch( 'block_has_inner_blocks', array( 'index' => $index ) );
			}

			if ( 'core/list' === ( $block['blockName'] ?? '' ) || 'core/list-item' === ( $block['blockName'] ?? '' ) ) {
				return self::skip_micro_batch( 'list_block_in_group', array( 'index' => $index ) );
			}

			$serialized = serialize_blocks( array( $block ) );
			if ( '' === trim( $serialized ) ) {
				return self::skip_micro_batch( 'empty_serialized_block', array( 'index' => $index ) );
			}

			$matches = array();
			$count   = preg_match_all( '/<!--\s*\/?wp:[^>]+-->/iu', $serialized, $matches );
			if ( false === $count || empty( $matches[0] ) || ! is_array( $matches[0] ) ) {
				return self::skip_micro_batch( 'missing_block_comments', array( 'index' => $index ) );
			}

			$block_comments = $matches[0];
			$inner_html     = self::extract_simple_wrapper_inner_html( $serialized, $block_comments );
			if ( null === $inner_html ) {
				return self::skip_micro_batch( 'inner_html_extraction_failed', array( 'index' => $index ) );
			}

			$item_key                = 'block_' . $index;
			$candidates[ $item_key ] = $inner_html;
			$metadata[ $item_key ]   = array(
				'open_comment'  => $block_comments[0],
				'close_comment' => $block_comments[ count( $block_comments ) - 1 ],
			);

			$total_chars += function_exists( 'mb_strlen' )
				? (int) mb_strlen( $inner_html, 'UTF-8' )
				: strlen( $inner_html );
		}

		if ( $total_chars < 1 || $total_chars > self::MICRO_BATCH_MAX_CHARS ) {
			return self::skip_micro_batch( 'total_chars_out_of_range', array( 'total_chars' => $total_chars ) );
		}

		$json_input = wp_json_encode( $candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json_input ) {
			return self::skip_micro_batch( 'json_encode_failed' );
		}

		$json_hint = 'The input is a JSON object where each value is inner HTML from one Gutenberg block. Translate only visible text inside the values, preserve all HTML tags and whitespace structure in each value, keep keys unchanged, and return ONLY valid JSON.';
		$prompt    = '' !== trim( $additional_prompt )
			? trim( $additional_prompt ) . "\n\n" . $json_hint
			: $json_hint;

		$result = TranslationRuntime::translate_text( $json_input, $to, $from, $prompt );
		if ( is_wp_error( $result ) ) {
			return self::skip_micro_batch( 'batch_call_error', array( 'error' => $result->get_error_code() ) );
		}

		$result_str = trim( (string) $result );
		$result_str = (string) preg_replace( '/^```(?:json)?\s*/i', '', $result_str );
		$result_str = (string) preg_replace( '/\s*```\s*$/i', '', $result_str );
		$decoded    = json_decode( trim( $result_str ), true );
		if ( ! is_array( $decoded ) ) {
			return self::skip_micro_batch( 'batch_json_decode_failed' );
		}

		$translated = array();
		foreach ( $candidates as $item_key => $source_inner_html ) {
			if ( ! array_key_exists( $item_key, $decoded ) || ! is_string( $decoded[ $item_key ] ) ) {
				return self::skip_micro_batch( 'batch_missing_item_key', array( 'key' => $item_key ) );
			}

			$validation = TranslationValidator::validate( $source_inner_html, $decoded[ $item_key ], $to );
			if ( is_wp_error( $validation ) ) {
				return self::skip_micro_batch( 'batch_item_validation_failed', array(
					'key'   => $item_key,
					'error' => $validation->get_error_code(),
				) );
			}

			$translated[] = $metadata[ $item_key ]['open_comment']
				. "\n"
				. $decoded[ $item_key ]
				. "\n"
				. $metadata[ $item_key ]['close_comment'];
		}

		TimingLogger::increment( 'micro_batch_hits' );
		TimingLogger::log( 'content_micro_batch', array(
			'blocks'      => count( $translated ),
			'total_chars' => $total_chars,
			'subcalls'    => 1,
		) );

		return implode( '', $translated );
	}

	private static function skip_micro_batch( string $reason, array $context = array() ): ?string {
		TimingLogger::increment( self::reason_counter_key( 'micro_batch_skip_', $reason ) );

		$payload = array( 'reason' => $reason );
		if ( ! empty( $context ) ) {
			$payload = array_merge( $payload, $context );
		}

		TimingLogger::log( 'content_micro_batch_skip', $payload );
		return null;
	}

	private static function reason_counter_key( string $prefix, string $reason ): string {
		$normalized = strtolower( (string) preg_replace( '/[^a-z0-9_]+/', '_', $reason ) );
		$normalized = trim( $normalized, '_' );

		if ( '' === $normalized ) {
			$normalized = 'unknown';
		}

		return $prefix . $normalized;
	}

	/**
	 * Extract <li> wrapper and inner content from one list-item fragment.
	 *
	 * Accepts nested HTML inside the item content and only requires the outer
	 * list-item wrapper so the batch path can handle richer real-world list
	 * markup (e.g. <li><p>..</p></li>) without falling back to per-item calls.
	 *
	 * @return array{open_tag:string,close_tag:string,content:string}|null
	 */
	private static function extract_list_item_content_parts( string $inner_html ): ?array {
		$inner_html = trim( $inner_html );
		if ( '' === $inner_html ) {
			return null;
		}

		$matches = array();
		if ( 1 !== preg_match( '/^(<li\b[^>]*>)([\S\s]*)(<\/li>)$/iu', $inner_html, $matches ) ) {
			return null;
		}

		$content = trim( (string) $matches[2] );
		if ( '' === $content ) {
			return null;
		}

		return array(
			'open_tag'  => (string) $matches[1],
			'close_tag' => (string) $matches[3],
			'content'   => $content,
		);
	}

	/**
	 * Gutenberg block comments omit the core/ namespace for core blocks.
	 */
	private static function normalize_block_comment_name( string $block_name ): string {
		$block_name = trim( $block_name );
		if ( str_starts_with( $block_name, 'core/' ) ) {
			return substr( $block_name, 5 );
		}
		return $block_name;
	}

	/**
	 * Translate serialized Gutenberg content while preserving block comments.
	 *
	 * Gutenberg block comments (<!-- wp:... -->) are replaced with stable
	 * neutral placeholders before the text is sent to the model, then restored
	 * afterward. This prevents small translation models from dropping inner
	 * block structure markers (e.g. <!-- wp:list-item -->) which would
	 * otherwise trigger a structure-drift validation error.
	 *
	 * @return string|\WP_Error
	 */
	private static function translate_with_block_comment_preservation(
		string $serialized,
		string $to,
		string $from,
		string $additional_prompt
	): mixed {
		$block_comments = array();
		$index          = 0;

		$stripped = preg_replace_callback(
			'/<!--\s*\/?wp:[^>]+-->/iu',
			static function ( array $matches ) use ( &$block_comments, &$index ): string {
				$placeholder              = '<!--SLYWPC' . $index . '-->';
				$block_comments[ $index ] = $matches[0];
				++$index;
				return $placeholder;
			},
			$serialized
		);

		// Length-control hints for block-level translation calls.
		//
		// Small instruct models (Phi-4-mini etc.) treat unwrapped paragraph
		// content as an open-ended question and happily return multi-sentence
		// explanations instead of a 1:1 translation. Without an explicit
		// "translate, do not expand" hint the validator's length_drift check
		// then kills the call, burning two extra AI calls and ultimately
		// leaves the block in the source language.
		$plain_text_hint  = 'The input is a short text snippet from a single Gutenberg block. Translate it 1:1 and return ONLY the translated text — no explanations, no commentary, no extra paragraphs. Preserve source symbols and math notation; do not rewrite Unicode symbols as LaTeX or ASCII. Keep the output length roughly proportional to the input.';
		$block_inner_hint = 'The input is the inner content of a single Gutenberg block. Translate only the visible text, preserve ALL HTML tags verbatim (including inline tags like <em>, <strong>, <code>, <a>, <kbd>), preserve source symbols and math notation, and keep the output length roughly proportional to the input. Return ONLY the translated HTML — no explanations, commentary, or extra paragraphs.';
		$plain_text_prompt  = trim( $additional_prompt . "\n\n" . $plain_text_hint );
		$block_inner_prompt = trim( $additional_prompt . "\n\n" . $block_inner_hint );

		// Fall back to translating the original string if regex fails.
		if ( null === $stripped || empty( $block_comments ) ) {
			return TranslationRuntime::translate_text( $serialized, $to, $from, $block_inner_prompt );
		}

		// Fast path for simple wrapper blocks (exactly open + close comment around content):
		// Skip placeholders entirely and translate the inner HTML directly.
		$inner_html = self::extract_simple_wrapper_inner_html( $serialized, $block_comments );
		if ( null !== $inner_html ) {
			$unwrapped = self::unwrap_single_element( $inner_html );

			// Optimisation: when the inner HTML is a single wrapping element with text-only
			// content (e.g. `<p>foo</p>`, `<h2>bar</h2>`), small translation models like
			// TranslateGemma reliably drop the outer wrapper from the response, which forces
			// us to redo the call with the unwrapped content anyway. Skip the wrapper round
			// trip entirely and translate just the content from the start, then reconstruct
			// the wrapper. This halves the AI calls for the very common paragraph-only and
			// heading-only blocks that dominate typical post content.
			if ( null !== $unwrapped ) {
				// The unwrapped content is plain-ish text (possibly with inline HTML);
				// the plain-text hint keeps Phi-4-mini from expanding it into commentary.
				$unwrapped_prompt = self::unwrapped_content_prompt( $unwrapped['content'], $plain_text_prompt, $block_inner_prompt );
				$translated_content = TranslationRuntime::translate_text( $unwrapped['content'], $to, $from, $unwrapped_prompt );
				if ( is_wp_error( $translated_content ) ) {
					return $translated_content;
				}

				// Strip an outer wrapper the model may have added back (matching the original
				// open/close tag) so we don't end up with <p><p>…</p></p> after reconstruction.
				$translated_content_str = trim( (string) $translated_content );
				if ( str_starts_with( $translated_content_str, $unwrapped['open_tag'] )
					&& str_ends_with( $translated_content_str, $unwrapped['close_tag'] )
				) {
					$translated_content_str = trim( substr(
						$translated_content_str,
						strlen( $unwrapped['open_tag'] ),
						-strlen( $unwrapped['close_tag'] )
					) );
				}

				$translated_inner = $unwrapped['open_tag'] . $translated_content_str . $unwrapped['close_tag'];

				// Inline-formatting integrity check: when the source contained inline tags
				// such as <em>, <strong>, <code>, <a>, <kbd>, … but the translation dropped
				// them, retry once with a stricter prompt that explicitly tells the model to
				// preserve every inline HTML tag verbatim. The validator's tag-count check is
				// bypassed at block level (skip_html_tag_validation=true), so this guard is
				// the only thing preventing silent inline-formatting regressions.
				if ( self::has_inline_formatting_loss( $inner_html, $translated_inner ) ) {
					$strict_prompt = trim( $unwrapped_prompt . "\n\nCRITICAL: Preserve every inline HTML tag from the source verbatim, including <em>, <strong>, <code>, <a>, <kbd>, <mark>, <b>, <i>, <u>, <s>, <del>, <ins>, <sub>, <sup>, <abbr>, <cite>, <q>. Do not drop, rename, or convert them to plain text or markdown." );
					$retry_inline  = TranslationRuntime::translate_text( $unwrapped['content'], $to, $from, $strict_prompt );
					if ( ! is_wp_error( $retry_inline ) ) {
						$retry_str = trim( (string) $retry_inline );
						if ( str_starts_with( $retry_str, $unwrapped['open_tag'] )
							&& str_ends_with( $retry_str, $unwrapped['close_tag'] )
						) {
							$retry_str = trim( substr(
								$retry_str,
								strlen( $unwrapped['open_tag'] ),
								-strlen( $unwrapped['close_tag'] )
							) );
						}
						$retry_reconstructed = $unwrapped['open_tag'] . $retry_str . $unwrapped['close_tag'];
						if ( ! self::has_inline_formatting_loss( $inner_html, $retry_reconstructed ) ) {
							$translated_inner = $retry_reconstructed;
						} else {
							// Both unwrap attempts dropped inline tags; fall through to the
							// full inner-HTML path which sends the complete <p>…</p> to the
							// model with an explicit tag-preservation instruction.
							$translated_inner = null;
						}
					} else {
						// Retry model call failed; fall through to inner-HTML path.
						$translated_inner = null;
					}
				}

				if ( null !== $translated_inner ) {
					return $block_comments[0] . "\n" . $translated_inner . "\n" . $block_comments[1];
				}
				// Inline-tag preservation failed in both unwrap attempts; fall through to
				// translate the full inner HTML with explicit tag-preservation instructions.
			}

			// No safe single-element unwrap available (multiple top-level elements or nested
			// block-level elements inside the wrapper) — translate the full inner HTML and
			// trust the validator. There is no automatic content-only retry here because the
			// HTML structure cannot be reconstructed by simple concatenation.
			$translated_inner = TranslationRuntime::translate_text( $inner_html, $to, $from, $block_inner_prompt );

			$structure_drift = is_wp_error( $translated_inner )
				&& 'invalid_translation_structure_drift' === $translated_inner->get_error_code();
			if ( $structure_drift ) {
				return $translated_inner;
			}

			if ( ! is_wp_error( $translated_inner )
				&& self::has_inline_formatting_loss( $inner_html, (string) $translated_inner )
			) {
				$strict_prompt = trim( $block_inner_prompt . "\n\nCRITICAL: Preserve every inline HTML tag from the source verbatim, including <em>, <strong>, <code>, <a>, <kbd>, <mark>, <b>, <i>, <u>, <s>, <del>, <ins>, <sub>, <sup>, <abbr>, <cite>, <q>. Do not drop, rename, or convert them to plain text or markdown." );
				$retry_inline  = TranslationRuntime::translate_text( $inner_html, $to, $from, $strict_prompt );
				if ( ! is_wp_error( $retry_inline )
					&& ! self::has_inline_formatting_loss( $inner_html, (string) $retry_inline )
				) {
					$translated_inner = $retry_inline;
				} elseif ( ! is_wp_error( $retry_inline ) ) {
					// The strict retry also lost some inline tags but returned a
					// translation. Accept it — a block with minor formatting loss is
					// far better UX than leaving the entire block in the source language.
					$translated_inner = $retry_inline;
				}
				// If $retry_inline is a WP_Error, keep $translated_inner from attempt 3.
			}

			if ( is_wp_error( $translated_inner ) ) {
				return $translated_inner;
			}
			return $block_comments[0] . "\n" . $translated_inner . "\n" . $block_comments[1];
		}

		// Append a sentinel so the last real placeholder never sits at the very end of the
		// string. Translation models reliably drop trailing HTML comments that have no content
		// after them; the sentinel gives those placeholders something to anchor to and is
		// stripped from the result before block comments are restored.
		$sentinel  = "\n<!--SLYWPCSENTINEL-->";
		$stripped .= $sentinel;

		$retry_prompt                  = $block_inner_prompt . "\n\nCRITICAL: Preserve ALL placeholder markers like <!--SLYWPC0-->, <!--SLYWPC1-->, <!--SLYWPC2--> etc. exactly as they appear. Do not remove, merge, or alter these markers.";
		$placeholder_count             = count( $block_comments );
		$use_strict_placeholder_prompt = $placeholder_count >= self::PLACEHOLDER_STRICT_PROMPT_MIN_COMMENTS;

		if ( $use_strict_placeholder_prompt ) {
			TimingLogger::log( 'content_placeholder_mode', array(
				'mode'         => 'strict_first',
				'placeholders' => $placeholder_count,
			) );
		}

		$translated_stripped = TranslationRuntime::translate_text(
			$stripped,
			$to,
			$from,
			$use_strict_placeholder_prompt ? $retry_prompt : $block_inner_prompt
		);
		if ( is_wp_error( $translated_stripped ) ) {
			return $translated_stripped;
		}

		// Remove the sentinel. The model may reformat surrounding whitespace slightly.
		$translated_stripped = (string) preg_replace( '/\s*<!--SLYWPCSENTINEL-->\s*$/i', '', $translated_stripped );

		// Verify every placeholder survived the translation.
		$placeholders_missing = false;
		foreach ( array_keys( $block_comments ) as $i ) {
			if ( false === strpos( $translated_stripped, '<!--SLYWPC' . $i . '-->' ) ) {
				$placeholders_missing = true;
				break;
			}
		}

		// Retry once with an explicit preservation instruction when the initial
		// call was not already strict.
		if ( $placeholders_missing ) {
			$still_missing = true;

			if ( ! $use_strict_placeholder_prompt ) {
				$stripped_retry      = $stripped; // sentinel already appended above
				$translated_stripped = TranslationRuntime::translate_text( $stripped_retry, $to, $from, $retry_prompt );
				if ( is_wp_error( $translated_stripped ) ) {
					return $translated_stripped;
				}
				$translated_stripped = (string) preg_replace( '/\s*<!--SLYWPCSENTINEL-->\s*$/i', '', $translated_stripped );

				$still_missing = false;
				foreach ( array_keys( $block_comments ) as $i ) {
					if ( false === strpos( $translated_stripped, '<!--SLYWPC' . $i . '-->' ) ) {
						$still_missing = true;
						break;
					}
				}
			}

			// Final fallback for simple wrapper blocks (open + close comment only):
			// Translate the inner HTML without any placeholders and re-wrap.
			if ( $still_missing ) {
				$inner_html = self::extract_simple_wrapper_inner_html( $serialized, $block_comments );
				if ( null !== $inner_html ) {
					$fallback_prompt  = self::unwrapped_content_prompt( $inner_html, $plain_text_prompt, $block_inner_prompt );
					$translated_inner = TranslationRuntime::translate_text( $inner_html, $to, $from, $fallback_prompt );
					if ( is_wp_error( $translated_inner ) ) {
						return $translated_inner;
					}
					return $block_comments[0] . "\n" . $translated_inner . "\n" . $block_comments[ count( $block_comments ) - 1 ];
				}

				return new \WP_Error(
					'invalid_translation_structure_drift',
					__( 'The translated output lost required structure such as HTML, Gutenberg block comments, URLs, or code fences.', 'slytranslate' )
				);
			}
		}

		// Restore original block comments.
		$restored = preg_replace_callback(
			'/<!--SLYWPC(\d+)-->/i',
			static function ( array $matches ) use ( $block_comments ): string {
				$i = (int) $matches[1];
				return $block_comments[ $i ] ?? $matches[0];
			},
			$translated_stripped
		);

		return $restored ?? $translated_stripped;
	}

	private static function inline_formatting_loss_error(): \WP_Error {
		return new \WP_Error(
			'invalid_translation_structure_drift',
			__( 'The translated output lost required structure such as HTML, Gutenberg block comments, URLs, or code fences.', 'slytranslate' )
		);
	}

	/**
	 * For simple wrapper blocks (exactly 2 block comments: one at start, one at end),
	 * extract the inner HTML between the opening and closing comments.
	 *
	 * Returns null if the structure is not a simple wrapper.
	 */
	private static function extract_simple_wrapper_inner_html( string $serialized, array $block_comments ): ?string {
		if ( count( $block_comments ) !== 2 ) {
			return null;
		}

		$open  = $block_comments[0];
		$close = $block_comments[ count( $block_comments ) - 1 ];

		// Primary check: literal open/close comments after trimming whitespace.
		$trimmed = trim( $serialized );
		if ( str_starts_with( $trimmed, $open ) && str_ends_with( $trimmed, $close ) ) {
			$inner = trim( substr( $trimmed, strlen( $open ), -strlen( $close ) ) );
			return '' !== $inner ? $inner : null;
		}

		// Whitespace-tolerant fallback: serialize_blocks() occasionally emits the
		// closing comment with an extra trailing newline or a preceding space that
		// is not produced by parse_blocks(), which made the literal endsWith check
		// fail and forced the expensive placeholder pipeline for plain paragraph
		// blocks. Locate the first occurrence of $open at position 0 and the last
		// occurrence of $close; if they wrap the entire trimmed string with only
		// whitespace outside, treat it as a simple wrapper.
		$open_pos  = strpos( $trimmed, $open );
		$close_pos = strrpos( $trimmed, $close );
		if ( 0 !== $open_pos || false === $close_pos || $close_pos <= strlen( $open ) ) {
			return null;
		}

		$after_close = substr( $trimmed, $close_pos + strlen( $close ) );
		if ( '' !== trim( $after_close ) ) {
			return null;
		}

		$inner = trim( substr( $trimmed, strlen( $open ), $close_pos - strlen( $open ) ) );
		return '' !== $inner ? $inner : null;
	}

	/**
	 * Choose the tighter prompt variant for unwrapped block content.
	 *
	 * When the inner text contains no inline HTML tags at all, we can tell the
	 * model it's a plain-text snippet (strongest length-control signal). When
	 * the content already carries inline tags like <em> or <code>, we must keep
	 * the block-inner variant so the preservation instruction stays in place.
	 */
	private static function unwrapped_content_prompt(
		string $content,
		string $plain_text_prompt,
		string $block_inner_prompt
	): string {
		return preg_match( '/<[a-z]/i', $content ) ? $block_inner_prompt : $plain_text_prompt;
	}

	/**
	 * Detect content wrapped in a single HTML element (e.g. <p>text</p>).
	 *
	 * Returns an array with 'open_tag', 'close_tag', and 'content' if the
	 * HTML is a single wrapping element with text-only content inside.
	 * Returns null otherwise.
	 */
	private static function unwrap_single_element( string $html ): ?array {
		$html = trim( $html );

		if ( ! preg_match( '/^(<([a-z][a-z0-9]*)\b[^>]*>)(.*?)(<\/\2>)$/is', $html, $m ) ) {
			return null;
		}

		// Only unwrap when the inner content has no nested block-level elements.
		if ( preg_match( '/<(?:p|div|h[1-6]|ul|ol|li|blockquote|table|figure)\b/i', $m[3] ) ) {
			return null;
		}

		$content = trim( $m[3] );
		if ( '' === $content ) {
			return null;
		}

		return array(
			'open_tag'  => $m[1],
			'close_tag' => $m[4],
			'content'   => $content,
		);
	}

	/**
	 * Return true when the translated HTML lost inline-formatting tags that the
	 * source contained (e.g. <em>, <strong>, <code>, <a>, <kbd>, <mark>, …).
	 *
	 * Used by the simple-wrapper fast path because block-level translation runs
	 * with skip_html_tag_validation=true, which disables the validator's tag
	 * count check. Without this guard, drops of <em>/<strong>/<code> wrappers
	 * would silently degrade translated content.
	 */
	private static function has_inline_formatting_loss( string $source_html, string $translated_html ): bool {
		$pattern = '/<(em|strong|code|a|kbd|mark|b|i|u|s|del|ins|sub|sup|abbr|cite|q|dfn|samp|var|small)\b[^>]*>/iu';

		$source_matches     = array();
		$translated_matches = array();

		$source_count     = preg_match_all( $pattern, $source_html, $source_matches );
		$translated_count = preg_match_all( $pattern, $translated_html, $translated_matches );

		if ( false === $source_count || $source_count < 1 ) {
			return false;
		}

		if ( false === $translated_count ) {
			return true;
		}

		// Compare per-tag-name counts so renaming or dropping specific tags is detected
		// (e.g. source has 2× <em> and 1× <code>, translation has 0× <em> and 1× <code>).
		$source_by_tag     = array_count_values( array_map( 'strtolower', (array) ( $source_matches[1] ?? array() ) ) );
		$translated_by_tag = array_count_values( array_map( 'strtolower', (array) ( $translated_matches[1] ?? array() ) ) );

		foreach ( $source_by_tag as $tag => $count ) {
			$got = $translated_by_tag[ $tag ] ?? 0;
			if ( $got < $count ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return true when a Gutenberg block should be skipped during translation
	 * (code, preformatted, HTML, etc.).
	 */
	public static function should_skip_block( array $block ): bool {
		return TextSplitter::should_skip_block_translation( $block );
	}

	/**
	 * Return true when a block fragment contains translatable text.
	 */
	public static function should_translate_fragment( string $fragment ): bool {
		return TextSplitter::should_translate_block_fragment( $fragment );
	}

	/**
	 * Return true when the serialised block contains translatable text.
	 *
	 * Blocks without usable text content (e.g. core/separator, core/image with
	 * empty alt, core/spacer) are passed through unchanged rather than sent to
	 * the translation model, preventing URL-loss validation errors.
	 */
	private static function has_translatable_content( array $block ): bool {
		return TextSplitter::should_translate_block_fragment( serialize_blocks( array( $block ) ) );
	}

	/* ---------------------------------------------------------------
	 * String-table fast path (used by StringTableContentAdapter)
	 * ------------------------------------------------------------- */

	/**
	 * Translate a flat list of text units via JSON batching.
	 *
	 * Returns a map of lookup_key → translated_string, ready to hand off to
	 * TranslatePressAdapter::create_translation() as `content_string_pairs`.
	 *
	 * @param array<int, array{id: string, source: string, lookup_keys: string[]}> $units
	 * @return array<string, string>|\WP_Error
	 */
	public static function translate_string_table_units(
		array $units,
		string $to,
		string $from,
		string $additional_prompt = ''
	): array|\WP_Error {
		if ( empty( $units ) ) {
			return array();
		}

		$pairs    = array();
		$batches  = self::chunk_string_table_units( $units, self::STRING_TABLE_BATCH_MAX_ITEMS );
		$json_hint = self::string_table_batch_prompt();
		$prompt    = '' !== trim( $additional_prompt )
			? trim( $additional_prompt ) . "\n\n" . $json_hint
			: $json_hint;

		foreach ( $batches as $batch_index => $batch ) {
			$batch_started = TimingLogger::start();

			$result = self::translate_string_table_batch_with_retry( $batch, $to, $from, $prompt, $batch_index );
			if ( is_wp_error( $result ) ) {
				TimingLogger::log( 'content_string_batch_error', array(
					'batch'       => $batch_index,
					'error'       => $result->get_error_code(),
					'duration_ms' => TimingLogger::stop( $batch_started ),
				) );
				return $result;
			}

			$batch_chars = 0;
			foreach ( $batch as $unit ) {
				$batch_chars += self::char_length( $unit['source'] );
			}

			foreach ( $batch as $unit ) {
				$id = $unit['id'];
				if ( ! isset( $result[ $id ] ) || ! is_string( $result[ $id ] ) ) {
					return new \WP_Error( 'string_batch_missing_key', __( 'The translation batch omitted a string.', 'slytranslate' ) );
				}

				$translated = $result[ $id ];
				$validation = TranslationValidator::validate( $unit['source'], $translated, $to );
				if ( is_wp_error( $validation ) ) {
					return $validation;
				}

				foreach ( $unit['lookup_keys'] as $lookup_key ) {
					$pairs[ $lookup_key ] = $translated;
				}
			}

			TimingLogger::log( 'content_string_batch_done', array(
				'batch'       => $batch_index,
				'items'       => count( $batch ),
				'chars'       => $batch_chars,
				'duration_ms' => TimingLogger::stop( $batch_started ),
				'ok'          => true,
			) );
		}

		return $pairs;
	}

	/**
	 * Translate a single batch, retrying by halving on JSON-decode failure.
	 *
	 * @param array<int, array{id: string, source: string, lookup_keys: string[]}> $batch
	 * @return array<string, string>|\WP_Error
	 */
	private static function translate_string_table_batch_with_retry(
		array $batch,
		string $to,
		string $from,
		string $prompt,
		int $batch_index = 0,
		int $depth = 0
	): array|\WP_Error {
		$result = self::translate_one_string_table_batch( $batch, $to, $from, $prompt, $batch_index );
		if ( ! is_wp_error( $result ) || 'string_batch_json_decode_failed' !== $result->get_error_code() ) {
			return $result;
		}

		if ( count( $batch ) <= 1 || $depth >= 4 ) {
			return $result;
		}

		$halves = array_chunk( $batch, (int) ceil( count( $batch ) / 2 ) );
		$merged = array();
		foreach ( $halves as $half ) {
			$partial = self::translate_string_table_batch_with_retry( $half, $to, $from, $prompt, $batch_index, $depth + 1 );
			if ( is_wp_error( $partial ) ) {
				return $partial;
			}
			$merged = array_merge( $merged, $partial );
		}

		TimingLogger::increment( 'string_batch_split_retries' );
		return $merged;
	}

	/**
	 * Execute one JSON batch call and return a decoded id→translation map.
	 *
	 * @param array<int, array{id: string, source: string, lookup_keys: string[]}> $batch
	 * @return array<string, string>|\WP_Error
	 */
	private static function translate_one_string_table_batch(
		array $batch,
		string $to,
		string $from,
		string $prompt,
		int $batch_index = 0
	): array|\WP_Error {
		$input = array();
		foreach ( $batch as $unit ) {
			$input[ $unit['id'] ] = $unit['source'];
		}

		$json_input = wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json_input ) {
			return new \WP_Error( 'string_batch_json_encode_failed', __( 'Could not encode translation batch.', 'slytranslate' ) );
		}

		$input_chars = self::char_length( $json_input );
		$safe_limit  = self::get_string_table_batch_char_limit();
		if ( $input_chars > $safe_limit ) {
			TimingLogger::log( 'content_string_batch_oversized', array(
				'batch'       => $batch_index,
				'items'       => count( $batch ),
				'input_chars' => $input_chars,
				'safe_limit'  => $safe_limit,
			) );
		}

		$result = TranslationRuntime::translate_text( $json_input, $to, $from, $prompt );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::decode_string_table_json( (string) $result, $batch_index, $input_chars );
	}

	/**
	 * Strip code fences, decode JSON and return diagnostic WP_Error on failure.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function decode_string_table_json( string $raw, int $batch_index, int $input_chars ): array|\WP_Error {
		$stripped = trim( $raw );
		$stripped = (string) preg_replace( '/^```(?:json)?\s*/i', '', $stripped );
		$stripped = (string) preg_replace( '/\s*```\s*$/i', '', $stripped );
		$stripped = trim( $stripped );

		$decoded = json_decode( $stripped, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		TimingLogger::log( 'content_string_batch_decode_failed', array(
			'batch'             => $batch_index,
			'input_chars'       => $input_chars,
			'output_chars'      => self::char_length( $stripped ),
			'json_error'        => json_last_error_msg(),
			'object_count_hint' => substr_count( $stripped, '}{' ) + 1,
			'ends_with_brace'   => str_ends_with( rtrim( $stripped ), '}' ) ? 1 : 0,
			'output_excerpt'    => mb_substr( (string) preg_replace( '/\s+/', ' ', $stripped ), 0, 240 ),
		) );

		return new \WP_Error( 'string_batch_json_decode_failed', __( 'Translation batch did not return valid JSON.', 'slytranslate' ) );
	}

	/**
	 * Prompt hint for string-table JSON batch calls.
	 */
	private static function string_table_batch_prompt(): string {
		return 'The input is a JSON object of independent WordPress text segments. Translate only the values, keep all keys unchanged, preserve inline placeholders and whitespace intent, and return ONLY valid JSON with the identical keys.';
	}

	/**
	 * Maximum batch size per item count.
	 */
	private const STRING_TABLE_BATCH_MAX_ITEMS = 24;

	/**
	 * Safety margin subtracted from the runtime chunk limit for batch sizing.
	 */
	private const STRING_TABLE_BATCH_SAFETY_CHARS = 200;

	/**
	 * Return the maximum encoded JSON length (in chars) for a single string-table batch.
	 *
	 * The value is derived from the runtime chunk limit so that no batch ever
	 * gets internally split by TranslationRuntime::translate_text().
	 */
	public static function get_string_table_batch_char_limit(): int {
		$runtime_limit = TranslationRuntime::get_chunk_char_limit();
		return max( 400, $runtime_limit - self::STRING_TABLE_BATCH_SAFETY_CHARS );
	}

	/**
	 * Measure the encoded JSON length of a batch to decide whether adding
	 * another unit would exceed the safe limit.
	 *
	 * @param array<int, array{id: string, source: string, lookup_keys: string[]}> $batch
	 */
	private static function encoded_string_batch_length( array $batch ): int {
		$input = array();
		foreach ( $batch as $unit ) {
			$input[ $unit['id'] ] = $unit['source'];
		}

		$json = wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return PHP_INT_MAX;
		}

		return self::char_length( $json );
	}

	/**
	 * Split translation units into encoded-length-bounded batches.
	 *
	 * Uses the actual JSON-encoded length of each candidate batch to ensure no
	 * batch exceeds the runtime chunk limit (minus a safety margin).
	 *
	 * @param array<int, array{id: string, source: string, lookup_keys: string[]}> $units
	 * @return array<int, array<int, array{id: string, source: string, lookup_keys: string[]}>>
	 */
	private static function chunk_string_table_units( array $units, int $max_items ): array {
		$max_chars = self::get_string_table_batch_char_limit();
		$chunks    = array();
		$current   = array();

		foreach ( $units as $unit ) {
			$candidate   = $current;
			$candidate[] = $unit;

			if ( ! empty( $current )
				&& ( count( $candidate ) > $max_items || self::encoded_string_batch_length( $candidate ) > $max_chars )
			) {
				$chunks[] = $current;
				$current  = array( $unit );
				continue;
			}

			$current = $candidate;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}
}
