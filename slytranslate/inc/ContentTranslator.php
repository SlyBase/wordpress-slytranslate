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

			$group_started   = TimingLogger::start();
			$calls_before    = (int) ( TimingLogger::get_counters()['ai_calls'] ?? 0 );
			$translated      = self::translate_serialized_blocks( $group, $to, $from, $additional_prompt );
			$calls_after     = (int) ( TimingLogger::get_counters()['ai_calls'] ?? 0 );
			$ok              = ! is_wp_error( $translated );
			TimingLogger::log( 'content_group_done', array(
				'blocks'      => count( $group ),
				'chars'       => function_exists( 'serialize_blocks' ) && function_exists( 'mb_strlen' )
					? (int) mb_strlen( serialize_blocks( $group ), 'UTF-8' )
					: 0,
				'subcalls'    => $calls_after - $calls_before,
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

		if ( 'core/list' !== ( $block['blockName'] ?? '' ) ) {
			return false;
		}

		return ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] );
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
				} else {
					return self::inline_formatting_loss_error();
				}
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

		$retry_prompt        = $block_inner_prompt . "\n\nCRITICAL: Preserve ALL placeholder markers like <!--SLYWPC0-->, <!--SLYWPC1-->, <!--SLYWPC2--> etc. exactly as they appear. Do not remove, merge, or alter these markers.";
		$translated_stripped = TranslationRuntime::translate_text( $stripped, $to, $from, $block_inner_prompt );
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

		// Retry once with an explicit preservation instruction if any placeholder was dropped.
		if ( $placeholders_missing ) {
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
}
