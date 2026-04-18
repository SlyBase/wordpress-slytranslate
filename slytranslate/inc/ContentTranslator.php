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

		foreach ( $blocks as $block ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			if ( self::should_skip_block( $block ) ) {
				$translated_section = self::translate_serialized_blocks( $pending_blocks, $to, $from, $additional_prompt );
				if ( is_wp_error( $translated_section ) ) {
					return $translated_section;
				}

				if ( '' !== $translated_section ) {
					$translated_sections[] = $translated_section;
				}

				$pending_blocks        = array();
				$translated_sections[] = serialize_blocks( array( $block ) );
				continue;
			}

			$pending_blocks[] = $block;
		}

		$translated_section = self::translate_serialized_blocks( $pending_blocks, $to, $from, $additional_prompt );
		if ( is_wp_error( $translated_section ) ) {
			return $translated_section;
		}

		if ( '' !== $translated_section ) {
			$translated_sections[] = $translated_section;
		}

		return implode( '', $translated_sections );
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

		return TranslationRuntime::translate_text( $serialized, $to, $from, $additional_prompt );
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
}
