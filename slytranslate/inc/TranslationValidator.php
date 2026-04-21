<?php

namespace AI_Translate;

if ( ! defined( 'ABSPATH' ) ) exit;

class TranslationValidator {

	private const MAX_SHORT_TEXT_RESPONSE_RATIO = 4;

	/**
	 * Above this output/input ratio on short inputs the result is a runaway
	 * generation / hallucination regardless of structural markers. Live
	 * logs showed 68→1170 and 52→881 plain-prose explanations that slipped
	 * past the markdown-structure gate because normalize_text_for_validation
	 * strips newlines before the gate can inspect them.
	 */
	private const HARD_SHORT_TEXT_RATIO_CEILING = 6;

	/**
	 * Maximum allowed output-to-input character ratio for non-trivial inputs.
	 * Translations should rarely exceed ~2x the source length; values above
	 * this threshold indicate the model started generating commentary,
	 * hallucinated content, or entered a runaway generation loop.
	 */
	private const MAX_RUNAWAY_OUTPUT_RATIO = 3;

	/**
	 * Inputs shorter than this skip the runaway guard because the
	 * short-text guard (max 4x growth, max 220 source chars) already
	 * covers them.
	 */
	private const RUNAWAY_GUARD_MIN_SOURCE_CHARS = 221;

	public static function validate( string $source_text, string $translated_text ) {
		$source_text     = (string) $source_text;
		$translated_text = (string) $translated_text;

		if ( '' === trim( $translated_text ) ) {
			return new \WP_Error(
				'invalid_translation_empty',
				__( 'The model returned an empty translation result.', 'slytranslate' )
			);
		}

		$source_plain     = self::normalize_text_for_validation( $source_text );
		$translated_plain = self::normalize_text_for_validation( $translated_text );

		// "Plain text missing" only matters when the source actually had
		// translatable plain text. Tag-only fragments such as a bare
		// <a href="…"></a>, <img …/>, an empty Gutenberg block comment
		// wrapper, or a media-only block normalise to '' on both sides —
		// the model legitimately echoes the structural markup back and
		// there is nothing to translate. Failing those would tear down
		// the whole content phase over fragments that contain no words.
		if ( '' === $translated_plain && '' !== $source_plain ) {
			return new \WP_Error(
				'invalid_translation_plain_text_missing',
				__( 'The translated output did not contain usable text.', 'slytranslate' )
			);
		}

		if ( self::looks_like_assistant_response( $source_text, $translated_text ) ) {
			return new \WP_Error(
				'invalid_translation_assistant_reply',
				__( 'The model returned explanatory assistant text instead of a clean translation.', 'slytranslate' )
			);
		}

		if ( self::has_excessive_short_text_growth( $source_plain, $translated_plain, $translated_text ) ) {
			return new \WP_Error(
				'invalid_translation_length_drift',
				__( 'The translated output is implausibly long for the source text and looks like a generated explanation rather than a translation.', 'slytranslate' )
			);
		}

		if ( self::has_runaway_output_growth( $source_plain, $translated_plain ) ) {
			return new \WP_Error(
				'invalid_translation_runaway_output',
				__( 'The translated output is far longer than the source text, indicating the model entered a runaway generation loop or appended hallucinated content.', 'slytranslate' )
			);
		}

		if ( self::has_structural_translation_drift( $source_text, $translated_text ) ) {
			return new \WP_Error(
				'invalid_translation_structure_drift',
				__( 'The translated output lost required structure such as HTML, Gutenberg block comments, URLs, or code fences.', 'slytranslate' )
			);
		}

		return null;
	}

	private static function normalize_text_for_validation( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return is_string( $text ) ? trim( $text ) : '';
	}

	private static function looks_like_assistant_response( string $source_text, string $translated_text ): bool {
		if ( self::looks_like_markdown_assistant_response( $source_text, $translated_text ) ) {
			return true;
		}

		$translated_plain = self::normalize_text_for_validation( $translated_text );
		if ( '' === $translated_plain ) {
			return false;
		}

		$source_plain = self::normalize_text_for_validation( $source_text );
		if ( self::starts_with_assistant_preamble( $translated_plain ) && ! self::starts_with_assistant_preamble( $source_plain ) ) {
			$translated_line_breaks = preg_match_all( '/\n/u', $translated_text );
			if ( $translated_line_breaks >= 2 || self::contains_review_markers( $translated_text ) ) {
				return true;
			}
		}

		return false;
	}

	private static function looks_like_markdown_assistant_response( string $source_text, string $translated_text ): bool {
		if ( self::contains_markdown_structure( $source_text ) ) {
			return false;
		}

		if ( ! self::contains_markdown_structure( $translated_text ) ) {
			return false;
		}

		return self::contains_review_markers( $translated_text ) || self::starts_with_assistant_preamble( self::normalize_text_for_validation( $translated_text ) );
	}

	private static function contains_markdown_structure( string $text ): bool {
		// Code fences (``` …), ATX headings, list markers, bold markers.
		if ( false !== strpos( $text, '```' ) ) {
			return true;
		}

		return 1 === preg_match( '/(^|\n)\s{0,3}(?:[-*+]\s+|\d+\.\s+|#{1,6}\s+)|\*\*[^*\n]+\*\*/u', $text );
	}

	private static function contains_review_markers( string $text ): bool {
		return 1 === preg_match( '/strengths\s*:|suggestions(?:\s+for\s+improvement)?\s*:|overall\s*:|key takeaways\s*:|breakdown|great start|vorschl[aä]ge\s*:|st[aä]rken\s*:|zusammenfassung\s*:|wichtige erkenntnisse\s*:/iu', $text );
	}

	private static function starts_with_assistant_preamble( string $text ): bool {
		return 1 === preg_match( '/^(?:okay|ok|sure|certainly|absolutely|of course|here(?: is|\'s)|let(?:\'|’)s|this is|this guide|for example|in short|overall|great|hier ist|klar|nat[üu]rlich|gerne|lassen(?:\s+sie)?\s+uns|insgesamt|zum beispiel)\b/iu', $text );
	}

	private static function has_excessive_short_text_growth( string $source_plain, string $translated_plain, string $translated_raw ): bool {
		$source_length = self::text_length( $source_plain );
		if ( $source_length < 1 || $source_length > 220 ) {
			return false;
		}

		$translated_length = self::text_length( $translated_plain );

		// Structural tripwire: if the model injects markdown / code fences /
		// newlines / numbered lists that the (short, plain) source does not
		// have, any output ≥ 3x the source length is a hallucinated
		// explanation. This fires before the absolute floor because
		// expansions like "Select model" (12 chars) → "```html Model 1
		// Model 2 Model 3 ```" (180 chars) stay under the 260-char floor
		// but are still obvious hallucinations.
		$source_has_structure = self::contains_markdown_structure( $source_plain )
			|| false !== strpos( $source_plain, "\n" );

		if ( ! $source_has_structure && $translated_length >= max( 60, $source_length * 3 ) ) {
			if ( false !== strpos( $translated_raw, "\n" )
				|| self::contains_markdown_structure( $translated_raw )
				|| self::contains_review_markers( $translated_raw )
			) {
				return true;
			}
		}

		if ( $translated_length <= max( 260, $source_length * self::MAX_SHORT_TEXT_RESPONSE_RATIO ) ) {
			return false;
		}

		// Extreme growth (>= 6x) is never a legitimate translation regardless
		// of structural markers — short inputs that balloon to hundreds of
		// plain-prose characters are hallucinated explanations.
		if ( $translated_length >= $source_length * self::HARD_SHORT_TEXT_RATIO_CEILING ) {
			return true;
		}

		// Inspect the RAW translated text for multi-line structure / markdown
		// lists. normalize_text_for_validation() collapses all whitespace
		// (including \n) to single spaces, which makes the `(^|\n)` anchor
		// in contains_markdown_structure() always fail on $translated_plain
		// and previously let numbered lists like "1. Schritt\n2. Schritt"
		// slip past the guard.
		if ( preg_match( '/\n/u', $translated_raw ) ) {
			return true;
		}

		if ( self::contains_markdown_structure( $translated_raw ) || self::contains_review_markers( $translated_raw ) ) {
			return true;
		}

		return self::contains_markdown_structure( $translated_plain ) || self::contains_review_markers( $translated_plain );
	}

	/**
	 * Detect runaway output for inputs above the short-text threshold.
	 *
	 * Live debug logs showed translation calls where 763–2.176 source chars
	 * produced 21.000–23.000 translated chars (10x–30x ratio), each call
	 * stalling for 4–5 minutes. Translations above the short-text band rarely
	 * legitimately exceed ~2x the source length.
	 */
	private static function has_runaway_output_growth( string $source_plain, string $translated_plain ): bool {
		$source_length = self::text_length( $source_plain );
		if ( $source_length < self::RUNAWAY_GUARD_MIN_SOURCE_CHARS ) {
			return false;
		}

		$translated_length = self::text_length( $translated_plain );

		return $translated_length > $source_length * self::MAX_RUNAWAY_OUTPUT_RATIO;
	}

	private static function has_structural_translation_drift( string $source_text, string $translated_text ): bool {
		$source_block_comment_count     = self::count_pattern_matches( '/<!--\s*\/?wp:[^>]+-->/iu', $source_text );
		$translated_block_comment_count = self::count_pattern_matches( '/<!--\s*\/?wp:[^>]+-->/iu', $translated_text );
		if ( $source_block_comment_count > 0 && $source_block_comment_count !== $translated_block_comment_count ) {
			return true;
		}

		// Detect HTML→Markdown regression: when the source contains HTML inline
		// formatting (<strong>/<em>/<p>/<br>) but the translation contains
		// Markdown-style formatting (**bold**, *italic*) and lost most of those
		// HTML tags, the model has rewritten HTML as Markdown — which Gutenberg
		// will render as plain text and treat as block-validation failure. This
		// check runs regardless of skip_html_tag_validation because it is the
		// most common quality regression with small translation models.
		if ( self::introduces_markdown_for_html( $source_text, $translated_text ) ) {
			return true;
		}

		// ContentTranslator sets this flag when translating individual blocks whose
		// structural integrity is already guaranteed at the block level. Small models
		// like TranslateGemma may drop inline formatting and URLs from anchor tags.
		if ( TranslationRuntime::should_skip_html_tag_validation() ) {
			return false;
		}

		// Count only URLs that appear as HTML attribute values (href, src, action).
		// Visible-text URLs inside link labels are legitimately replaced with descriptive
		// anchor text during translation; counting them would produce false positives when
		// source content contains patterns like <a href="https://…">https://…</a>.
		$source_url_count     = self::count_pattern_matches( '/\b(?:href|src|action)\s*=\s*["\']https?:\/\/[^\s"\'<>]+["\']/iu', $source_text );
		$translated_url_count = self::count_pattern_matches( '/\b(?:href|src|action)\s*=\s*["\']https?:\/\/[^\s"\'<>]+["\']/iu', $translated_text );
		if ( $source_url_count > 0 && $translated_url_count < $source_url_count ) {
			return true;
		}

		$source_code_fence_count     = substr_count( $source_text, '```' );
		$translated_code_fence_count = substr_count( $translated_text, '```' );
		if ( $source_code_fence_count !== $translated_code_fence_count ) {
			return true;
		}

		// When the source contains block-comment placeholders (<!--SLYWPC…-->), the content
		// was pre-processed by ContentTranslator::translate_with_block_comment_preservation().
		// Block structure is verified externally via placeholder restoration; skipping the
		// HTML tag count check here avoids false positives from small models that legitimately
		// drop or simplify inline formatting tags (e.g. <strong>, <code>) while correctly
		// translating the text content.
		$source_has_placeholders = 1 === preg_match( '/<!--SLYWPC\d+-->/i', $source_text );
		if ( $source_has_placeholders ) {
			return false;
		}

		$source_html_tag_count     = self::count_pattern_matches( '/<\/?[a-z][^>]*>/iu', $source_text );
		$translated_html_tag_count = self::count_pattern_matches( '/<\/?[a-z][^>]*>/iu', $translated_text );
		if ( $source_html_tag_count >= 2 && $translated_html_tag_count < (int) ceil( $source_html_tag_count * 0.6 ) ) {
			return true;
		}

		return false;
	}

	private static function count_pattern_matches( string $pattern, string $text ): int {
		$count = preg_match_all( $pattern, $text, $matches );

		return false === $count ? 0 : $count;
	}

	/**
	 * Detect a regression where HTML inline formatting is replaced with Markdown
	 * formatting (e.g. <strong>X</strong> → **X**). This produces invalid block
	 * markup in Gutenberg ("Block contains unexpected or invalid content").
	 */
	private static function introduces_markdown_for_html( string $source_text, string $translated_text ): bool {
		$source_html_inline = self::count_pattern_matches( '/<(?:p|br|strong|em|b|i|u|code|li|h[1-6])\b[^>]*>/iu', $source_text );
		if ( $source_html_inline < 1 ) {
			return false;
		}

		// Markdown bold (**text**) or markdown headings/list markers introduced.
		$translated_md_bold     = self::count_pattern_matches( '/\*\*[^*\n]+\*\*/u', $translated_text );
		$translated_md_headings = self::count_pattern_matches( '/(^|\n)\s{0,3}#{1,6}\s+/u', $translated_text );
		if ( $translated_md_bold < 1 && $translated_md_headings < 1 ) {
			return false;
		}

		// Source itself uses markdown? Then it's not a regression.
		if ( self::contains_markdown_structure( $source_text ) ) {
			return false;
		}

		$translated_html_inline = self::count_pattern_matches( '/<(?:p|br|strong|em|b|i|u|code|li|h[1-6])\b[^>]*>/iu', $translated_text );

		// If at least half of the source inline-HTML tags are still present, the
		// translation kept the HTML structure and just added some markdown — be
		// lenient. If less than half remain, treat as drift.
		return $translated_html_inline < (int) ceil( $source_html_inline * 0.5 );
	}

	private static function text_length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		return strlen( $text );
	}
}