<?php

namespace AI_Translate;

if ( ! defined( 'ABSPATH' ) ) exit;

class TranslationValidator {

	private const MAX_SHORT_TEXT_RESPONSE_RATIO = 4;

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

		if ( '' === $translated_plain ) {
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

		if ( self::has_excessive_short_text_growth( $source_plain, $translated_plain ) ) {
			return new \WP_Error(
				'invalid_translation_length_drift',
				__( 'The translated output is implausibly long for the source text and looks like a generated explanation rather than a translation.', 'slytranslate' )
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
		return 1 === preg_match( '/(^|\n)\s{0,3}(?:[-*+]\s+|\d+\.\s+|#{1,6}\s+)|\*\*[^*\n]+\*\*/u', $text );
	}

	private static function contains_review_markers( string $text ): bool {
		return 1 === preg_match( '/strengths\s*:|suggestions(?:\s+for\s+improvement)?\s*:|overall\s*:|key takeaways\s*:|breakdown|great start|vorschl[aä]ge\s*:|st[aä]rken\s*:|zusammenfassung\s*:|wichtige erkenntnisse\s*:/iu', $text );
	}

	private static function starts_with_assistant_preamble( string $text ): bool {
		return 1 === preg_match( '/^(?:okay|ok|sure|certainly|absolutely|of course|here(?: is|\'s)|let(?:\'|’)s|this is|this guide|for example|in short|overall|great|hier ist|klar|nat[üu]rlich|gerne|lassen(?:\s+sie)?\s+uns|insgesamt|zum beispiel)\b/iu', $text );
	}

	private static function has_excessive_short_text_growth( string $source_plain, string $translated_plain ): bool {
		$source_length = self::text_length( $source_plain );
		if ( $source_length < 1 || $source_length > 220 ) {
			return false;
		}

		$translated_length = self::text_length( $translated_plain );
		if ( $translated_length <= max( 260, $source_length * self::MAX_SHORT_TEXT_RESPONSE_RATIO ) ) {
			return false;
		}

		if ( preg_match( '/\n/u', $translated_plain ) ) {
			return true;
		}

		return self::contains_markdown_structure( $translated_plain ) || self::contains_review_markers( $translated_plain );
	}

	private static function has_structural_translation_drift( string $source_text, string $translated_text ): bool {
		$source_block_comment_count     = self::count_pattern_matches( '/<!--\s*\/?wp:[^>]+-->/iu', $source_text );
		$translated_block_comment_count = self::count_pattern_matches( '/<!--\s*\/?wp:[^>]+-->/iu', $translated_text );
		if ( $source_block_comment_count > 0 && $source_block_comment_count !== $translated_block_comment_count ) {
			return true;
		}

		$source_url_count     = self::count_pattern_matches( '/https?:\/\/[^\s"\'<>]+/iu', $source_text );
		$translated_url_count = self::count_pattern_matches( '/https?:\/\/[^\s"\'<>]+/iu', $translated_text );
		if ( $source_url_count > 0 && $translated_url_count < $source_url_count ) {
			return true;
		}

		$source_code_fence_count     = substr_count( $source_text, '```' );
		$translated_code_fence_count = substr_count( $translated_text, '```' );
		if ( $source_code_fence_count !== $translated_code_fence_count ) {
			return true;
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

	private static function text_length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		return strlen( $text );
	}
}