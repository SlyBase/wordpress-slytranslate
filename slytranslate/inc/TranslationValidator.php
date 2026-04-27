<?php

namespace AI_Translate;

if ( ! defined( 'ABSPATH' ) ) exit;

class TranslationValidator {

	private const PROTECTED_SYMBOL_SEQUENCE_MAP = array(
		'→' => array( '$\\rightarrow$', '\\rightarrow' ),
		'←' => array( '$\\leftarrow$', '\\leftarrow' ),
		'↔' => array( '$\\leftrightarrow$', '\\leftrightarrow' ),
		'⇒' => array( '$\\Rightarrow$', '\\Rightarrow' ),
		'⇐' => array( '$\\Leftarrow$', '\\Leftarrow' ),
		'⇔' => array( '$\\Leftrightarrow$', '\\Leftrightarrow' ),
		'×' => array( '$\\times$', '\\times' ),
		'÷' => array( '$\\div$', '\\div' ),
		'≤' => array( '$\\leq$', '\\leq' ),
		'≥' => array( '$\\geq$', '\\geq' ),
	);

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

	/**
	 * Non-trivial sources must have at least this many words before the
	 * collapsed-output guard fires. Below this threshold a single-word
	 * translation (e.g. a proper noun or brand name) can be correct.
	 */
	private const COLLAPSED_OUTPUT_MIN_SOURCE_WORDS = 5;

	public static function validate( string $source_text, string $translated_text, ?string $target_language = null ) {
		$source_text     = (string) $source_text;
		$translated_text = self::normalize_bilingual_frame_label_leakage( $source_text, (string) $translated_text, $target_language );

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

		if ( self::has_prompt_instruction_leakage( $source_text, $translated_text ) ) {
			return new \WP_Error(
				'invalid_translation_assistant_reply',
				__( 'The model leaked prompt instructions into the translated output instead of returning only translated content.', 'slytranslate' )
			);
		}

		if ( self::has_uninformative_stopword_only_output( $source_plain, $translated_plain, $target_language ) ) {
			return new \WP_Error(
				'invalid_translation_low_information',
				__( 'The translated output collapsed to a low-information stopword instead of a meaningful translation.', 'slytranslate' )
			);
		}

		if ( self::has_collapsed_output( $source_plain, $translated_plain ) ) {
			return new \WP_Error(
				'invalid_translation_low_information',
				__( 'The translated output collapsed to a single word for a non-trivial source, indicating the model failed to translate.', 'slytranslate' )
			);
		}

		if ( self::is_obvious_language_passthrough( $source_plain, $translated_plain, $target_language ) ) {
			return new \WP_Error(
				'invalid_translation_language_passthrough',
				__( 'The translated output still appears to be in the source language instead of German.', 'slytranslate' )
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

		if ( self::has_symbol_translation_drift( $source_text, $translated_text ) ) {
			return new \WP_Error(
				'invalid_translation_symbol_drift',
				__( 'The translated output rewrote source symbols into a different notation such as LaTeX instead of preserving them.', 'slytranslate' )
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

	public static function normalize_symbol_notation( string $source_text, string $translated_text ): string {
		foreach ( self::PROTECTED_SYMBOL_SEQUENCE_MAP as $symbol => $variants ) {
			if ( false === strpos( $source_text, $symbol ) ) {
				continue;
			}

			$source_has_variant = false;
			foreach ( $variants as $variant ) {
				if ( false !== strpos( $source_text, $variant ) ) {
					$source_has_variant = true;
					break;
				}
			}

			if ( $source_has_variant ) {
				continue;
			}

			$translated_text = str_replace( $variants, $symbol, $translated_text );
		}

		return $translated_text;
	}

	private static function normalize_bilingual_frame_label_leakage( string $source_text, string $translated_text, ?string $target_language ): string {
		$target = strtolower( trim( (string) $target_language ) );
		if ( '' === $target || 0 !== strpos( $target, 'de' ) ) {
			return $translated_text;
		}

		$source_trimmed = ltrim( $source_text );
		if ( 1 === preg_match( '/^(?:\*\*)?\s*(?:german|deutsch)\s*:/iu', $source_trimmed ) ) {
			return $translated_text;
		}

		$translated_trimmed = ltrim( $translated_text );
		$normalized         = preg_replace(
			'/^(?:\*\*)?\s*(?:german|deutsch)\s*:\s*(?:\*\*)?\s*/iu',
			'',
			$translated_trimmed,
			1,
			$count
		);

		if ( ! is_string( $normalized ) || 1 !== $count ) {
			return $translated_text;
		}

		return $normalized;
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

	private static function has_prompt_instruction_leakage( string $source_text, string $translated_text ): bool {
		$source_lower     = strtolower( $source_text );
		$translated_lower = strtolower( $translated_text );
		$source_plain     = self::normalize_text_for_validation( $source_text );
		$translated_plain = self::normalize_text_for_validation( $translated_text );

		$instruction_markers = array(
			'critical output format',
			'mandatory translation rules',
			'critical: apply every translation rule above exactly',
			'critical: keep obeying the user-provided translation rules above',
		);

		foreach ( $instruction_markers as $marker ) {
			if ( false === strpos( $source_lower, $marker ) && false !== strpos( $translated_lower, $marker ) ) {
				return true;
			}
		}

		$source_has_critical_label     = false !== strpos( $source_lower, 'critical:' );
		$translated_has_critical_label = false !== strpos( $translated_lower, 'critical:' );
		if ( false === strpos( $source_lower, 'critical' ) && false !== strpos( $translated_lower, 'critical' ) ) {
			return true;
		}
		if ( ! $source_has_critical_label && $translated_has_critical_label ) {
			if ( 1 === preg_match( '/critical\s*:\s*(?:apply|keep|obey|return|format|rule|rules|translation|wenden|befolgen|ausgabe|regel|regeln|obigen|above|only)\b/iu', $translated_text ) ) {
				return true;
			}
		}

		$source_has_mandatory_label     = false !== strpos( $source_lower, 'mandatory:' );
		$translated_has_mandatory_label = false !== strpos( $translated_lower, 'mandatory:' );
		if ( false === strpos( $source_lower, 'mandatory' ) && false !== strpos( $translated_lower, 'mandatory' ) ) {
			return true;
		}
		if ( ! $source_has_mandatory_label && $translated_has_mandatory_label ) {
			if ( 1 === preg_match( '/mandatory\s*:\s*(?:translation|rule|rules|output|format|instruction|instructions|apply|obey|return|only)\b/iu', $translated_text ) ) {
				return true;
			}
		}

		if ( false === strpos( $source_lower, '<slytranslate' ) && false !== strpos( $translated_lower, '<slytranslate' ) ) {
			return true;
		}

		$retry_directive_pattern = '/\breturn\s+only\s+(?:[a-z]{2,3}(?:-[a-z]{2,3})?|english|german|deutsch|source|target)(?:\s+language)?\b.{0,80}?\bdo\s+not\s+copy\s+sentences?\s+in\s+(?:[a-z]{2,3}(?:-[a-z]{2,3})?|english|german|deutsch|source|target)(?:\s+language)?\b/iu';
		if ( 1 !== preg_match( $retry_directive_pattern, $source_plain )
			&& 1 === preg_match( $retry_directive_pattern, $translated_plain )
		) {
			return true;
		}

		$source_has_bilingual_labels = 1 === preg_match( '/(?:^|\b)(?:source|target|en|de|english|german|deutsch)\s*:/iu', $source_plain );
		if ( ! $source_has_bilingual_labels && 1 === preg_match( '/(?:^|\b)(?:source|target|en|de|english|german|deutsch)\s*:/iu', $translated_plain ) ) {
			return true;
		}

		return false;
	}

	private static function has_uninformative_stopword_only_output( string $source_plain, string $translated_plain, ?string $target_language ): bool {
		$target = strtolower( trim( (string) $target_language ) );
		if ( '' === $target || 0 !== strpos( $target, 'de' ) ) {
			return false;
		}

		$source_word_count = preg_match_all( '/\p{L}+/u', $source_plain, $source_words );
		if ( $source_word_count < 2 || self::text_length( $source_plain ) < 12 ) {
			return false;
		}

		$translated_word_count = preg_match_all( '/\p{L}+/u', $translated_plain, $translated_words );
		if ( 1 !== $translated_word_count ) {
			return false;
		}

		$token = '';
		if ( isset( $translated_words[0][0] ) && is_string( $translated_words[0][0] ) ) {
			$token = strtolower( $translated_words[0][0] );
		}

		if ( '' === $token ) {
			return false;
		}

		$uninformative_tokens = array(
			'and',
			'or',
			'but',
			'und',
			'oder',
			'aber',
			'sowie',
			'also',
			'dann',
			'the',
		);

		return in_array( $token, $uninformative_tokens, true );
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

	/**
	 * Detect a collapsed output: a non-trivial multi-word source that was
	 * "translated" to a single word. This catches cases where a model returns
	 * only a fragment (e.g. "The" for a 200-char paragraph) — a failure mode
	 * that the over-length guards miss because the output is too short, not
	 * too long. Language-agnostic: applies to any source/target combination.
	 */
	private static function has_collapsed_output( string $source_plain, string $translated_plain ): bool {
		$source_word_count = preg_match_all( '/\p{L}+/u', $source_plain );
		if ( $source_word_count < self::COLLAPSED_OUTPUT_MIN_SOURCE_WORDS ) {
			return false;
		}

		$translated_word_count = preg_match_all( '/\p{L}+/u', $translated_plain );

		return 1 === $translated_word_count;
	}

	private static function has_excessive_short_text_growth( string $source_plain, string $translated_plain, string $translated_raw ): bool {		$source_length = self::text_length( $source_plain );
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
		$source_block_comment_sequence     = self::extract_block_comment_signature_sequence( $source_text );
		$translated_block_comment_sequence = self::extract_block_comment_signature_sequence( $translated_text );
		$source_block_comment_count        = count( $source_block_comment_sequence );
		$translated_block_comment_count    = count( $translated_block_comment_sequence );
		if ( $source_block_comment_count > 0 && $source_block_comment_count !== $translated_block_comment_count ) {
			return true;
		}

		// Count parity alone is too weak: models can keep the same number of block
		// comments but flip direction (e.g. `<!-- /wp:list-item -->` ->
		// `<!-- wp:list-item /-->`), which breaks Gutenberg validation while passing
		// a pure count check.
		if ( $source_block_comment_count > 0 && $source_block_comment_sequence !== $translated_block_comment_sequence ) {
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
	 * Return Gutenberg block comments as a normalized signature sequence.
	 *
	 * Each entry uses one of:
	 * - open:block-name
	 * - close:block-name
	 * - self:block-name
	 */
	private static function extract_block_comment_signature_sequence( string $text ): array {
		$matches = array();
		$count   = preg_match_all(
			'/<!--\s*(\/?)wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)?)(?:\s+[^>]*)?-->/iu',
			$text,
			$matches,
			PREG_SET_ORDER
		);

		if ( false === $count || $count < 1 || ! is_array( $matches ) ) {
			return array();
		}

		$sequence = array();
		foreach ( $matches as $match ) {
			$full_comment = isset( $match[0] ) ? (string) $match[0] : '';
			$prefix       = isset( $match[1] ) ? (string) $match[1] : '';
			$block_name   = isset( $match[2] ) ? strtolower( (string) $match[2] ) : '';

			if ( '' === $block_name ) {
				continue;
			}

			$type = '/' === $prefix ? 'close' : 'open';
			if ( 'close' !== $type && 1 === preg_match( '/\/\s*-->$/u', $full_comment ) ) {
				$type = 'self';
			}

			$sequence[] = $type . ':' . $block_name;
		}

		return $sequence;
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

	private static function has_symbol_translation_drift( string $source_text, string $translated_text ): bool {
		foreach ( self::PROTECTED_SYMBOL_SEQUENCE_MAP as $symbol => $variants ) {
			if ( false === strpos( $source_text, $symbol ) ) {
				continue;
			}

			$source_has_variant = false;
			foreach ( $variants as $variant ) {
				if ( false !== strpos( $source_text, $variant ) ) {
					$source_has_variant = true;
					break;
				}
			}

			if ( $source_has_variant ) {
				continue;
			}

			foreach ( $variants as $variant ) {
				if ( false !== strpos( $translated_text, $variant ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function text_length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		return strlen( $text );
	}

	private static function normalize_for_passthrough_compare( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}

		return strtolower( $text );
	}

	private static function count_english_markers( string $text ): int {
		$count = preg_match_all( '/\b(?:the|and|with|for|from|this|that|are|you|your|into|please|translate|content|following)\b/iu', $text );

		return false === $count ? 0 : $count;
	}

	private static function count_german_markers( string $text ): int {
		$count = preg_match_all( '/\b(?:der|die|das|und|mit|nicht|ist|sind|ein|eine|fuer|für|oder|auf|von|im|den|dem|zu)\b/iu', $text );

		return false === $count ? 0 : $count;
	}

	private static function is_obvious_language_passthrough( string $source_plain, string $translated_plain, ?string $target_language ): bool {
		$target = strtolower( trim( (string) $target_language ) );
		if ( '' === $target || 0 !== strpos( $target, 'de' ) ) {
			return false;
		}

		if ( self::text_length( $source_plain ) < 30 || self::text_length( $translated_plain ) < 30 ) {
			return false;
		}

		$source_normalized     = self::normalize_for_passthrough_compare( $source_plain );
		$translated_normalized = self::normalize_for_passthrough_compare( $translated_plain );
		if ( '' === $source_normalized || '' === $translated_normalized ) {
			return false;
		}

		$english_markers = self::count_english_markers( $translated_normalized );
		$german_markers  = self::count_german_markers( $translated_normalized );

		if ( $source_normalized === $translated_normalized ) {
			return $english_markers >= 2 && 0 === $german_markers;
		}

		$source_tokens     = preg_split( '/\s+/u', $source_normalized, -1, PREG_SPLIT_NO_EMPTY );
		$translated_tokens = preg_split( '/\s+/u', $translated_normalized, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $source_tokens ) || ! is_array( $translated_tokens ) ) {
			return false;
		}

		if ( count( $source_tokens ) < 8 || count( $translated_tokens ) < 8 ) {
			return false;
		}

		$shared_limit    = min( count( $source_tokens ), count( $translated_tokens ) );
		$max_token_count = max( count( $source_tokens ), count( $translated_tokens ) );
		$same_position   = 0;

		for ( $index = 0; $index < $shared_limit; $index++ ) {
			if ( $source_tokens[ $index ] === $translated_tokens[ $index ] ) {
				$same_position++;
			}
		}

		$position_ratio = $max_token_count > 0 ? ( $same_position / $max_token_count ) : 0;

		return $position_ratio >= 0.9 && $english_markers >= 2 && 0 === $german_markers;
	}
}
