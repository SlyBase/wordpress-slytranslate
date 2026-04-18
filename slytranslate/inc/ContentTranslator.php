<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Block-aware content translation pipeline.
 *
 * Parses post content into Gutenberg blocks, skips non-translatable blocks
 * (code, preformatted, …), and batches translatable runs for efficiency.
 */
class ContentTranslator {

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
	): mixed {
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
		$translated_sections = array();
		$pending_blocks      = array();
		$chunk_char_limit    = TranslationRuntime::get_chunk_char_limit();

		foreach ( $blocks as $block ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
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

		$sections = array();

		foreach ( TextSplitter::group_blocks_for_translation( $pending_blocks, $chunk_char_limit ) as $group ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			$translated = self::translate_serialized_blocks( $group, $to, $from, $additional_prompt );
			if ( is_wp_error( $translated ) ) {
				return $translated;
			}

			if ( '' !== $translated ) {
				$sections[] = $translated;
			}
		}

		return $sections;
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

		$serialized = serialize_blocks( $blocks );
		if ( '' === trim( $serialized ) ) {
			return $serialized;
		}

		return self::translate_with_block_comment_preservation( $serialized, $to, $from, $additional_prompt );
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

		// Fall back to translating the original string if regex fails.
		if ( null === $stripped || empty( $block_comments ) ) {
			return TranslationRuntime::translate_text( $serialized, $to, $from, $additional_prompt );
		}

		$translated_stripped = TranslationRuntime::translate_text( $stripped, $to, $from, $additional_prompt );
		if ( is_wp_error( $translated_stripped ) ) {
			return $translated_stripped;
		}

		// Verify every placeholder survived the translation.
		foreach ( array_keys( $block_comments ) as $i ) {
			if ( false === strpos( $translated_stripped, '<!--SLYWPC' . $i . '-->' ) ) {
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
}
