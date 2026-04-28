<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\TextSplitter;
use SlyTranslate\TranslationRuntime;

/**
 * Tests for the text-splitting methods used to break long content into
 * API-friendly chunks.
 *
 * Constants (from AI_Translate):
 *   MIN_TRANSLATION_CHARS = 1200
 *   MAX_TRANSLATION_CHARS = 48000
 */
class TextSplittingTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', null );
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );

		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// hard_split_text
	// -----------------------------------------------------------------------

	public function test_hard_split_returns_single_chunk_when_text_fits(): void {
		$text   = str_repeat( 'a', 100 );
		$result = $this->invokeStatic( TextSplitter::class, 'hard_split_text', [ $text, 2000 ] );
		$this->assertSame( [ $text ], $result );
	}

	public function test_hard_split_splits_exactly_at_max_chars(): void {
		// 2000 chars split into 2 chunks of 1200 (MAX of MIN) each.
		$max   = 1200;
		$text  = str_repeat( 'x', 2400 );
		$result = $this->invokeStatic( TextSplitter::class, 'hard_split_text', [ $text, $max ] );
		$this->assertCount( 2, $result );
		$this->assertSame( str_repeat( 'x', 1200 ), $result[0] );
		$this->assertSame( str_repeat( 'x', 1200 ), $result[1] );
	}

	public function test_hard_split_clamps_max_chars_to_minimum(): void {
		// Passing max_chars below MIN_TRANSLATION_CHARS (1200) gets clamped to 1200.
		$text   = str_repeat( 'y', 1500 );
		$result = $this->invokeStatic( TextSplitter::class, 'hard_split_text', [ $text, 10 ] );
		// Clamped to 1200 → two chunks: 1200 + 300.
		$this->assertCount( 2, $result );
		$this->assertSame( 1200, mb_strlen( $result[0], 'UTF-8' ) );
	}

	public function test_hard_split_handles_multibyte_unicode(): void {
		// Each Japanese character is one Unicode codepoint (one mb_strlen unit).
		$char   = '日';
		$text   = str_repeat( $char, 2400 );
		$result = $this->invokeStatic( TextSplitter::class, 'hard_split_text', [ $text, 1200 ] );
		$this->assertCount( 2, $result );
		$this->assertSame( 1200, mb_strlen( $result[0], 'UTF-8' ) );
		$this->assertSame( 1200, mb_strlen( $result[1], 'UTF-8' ) );
	}

	public function test_hard_split_empty_text_returns_empty_array(): void {
		$result = $this->invokeStatic( TextSplitter::class, 'hard_split_text', [ '', 2000 ] );
		$this->assertSame( [], $result );
	}

	public function test_hard_split_rejoined_equals_original(): void {
		$text   = str_repeat( 'abcde', 600 );
		$result = $this->invokeStatic( TextSplitter::class, 'hard_split_text', [ $text, 1200 ] );
		$this->assertSame( $text, implode( '', $result ) );
	}

	// -----------------------------------------------------------------------
	// split_segment_for_translation
	// -----------------------------------------------------------------------

	public function test_segment_split_returns_single_chunk_when_short(): void {
		$text   = 'Hello world, this is a short sentence.';
		$result = $this->invokeStatic( TextSplitter::class, 'split_segment_for_translation', [ $text, 2000 ] );
		$this->assertSame( [ $text ], $result );
	}

	public function test_segment_split_splits_on_whitespace(): void {
		// Build a text with many short words that push over max_chars.
		$word   = 'word ';
		$text   = str_repeat( $word, 400 ); // 2000 chars; max 1200 → needs split.
		$result = $this->invokeStatic( TextSplitter::class, 'split_segment_for_translation', [ $text, 1200 ] );
		$this->assertGreaterThan( 1, count( $result ) );
		// Rejoining must recover the original text.
		$this->assertSame( $text, implode( '', $result ) );
	}

	public function test_segment_split_handles_single_overlong_word(): void {
		// A single "word" longer than max_chars must be hard-split.
		$word   = str_repeat( 'z', 3000 );
		$result = $this->invokeStatic( TextSplitter::class, 'split_segment_for_translation', [ $word, 1200 ] );
		$this->assertGreaterThan( 1, count( $result ) );
		$this->assertSame( $word, implode( '', $result ) );
	}

	// -----------------------------------------------------------------------
	// split_text_for_translation
	// -----------------------------------------------------------------------

	public function test_text_split_returns_single_chunk_when_text_fits(): void {
		$text   = 'A short post.';
		$result = $this->invokeStatic( TextSplitter::class, 'split_text_for_translation', [ $text, 2000 ] );
		$this->assertSame( [ $text ], $result );
	}

	public function test_text_split_splits_on_double_newlines(): void {
		$para1  = str_repeat( 'First paragraph content. ', 30 );
		$para2  = str_repeat( 'Second paragraph content. ', 30 );
		$text   = $para1 . "\n\n" . $para2;
		$result = $this->invokeStatic( TextSplitter::class, 'split_text_for_translation', [ $text, 1200 ] );
		$this->assertGreaterThan( 1, count( $result ) );
		$this->assertSame( $text, implode( '', $result ) );
	}

	public function test_text_split_splits_on_html_block_tags(): void {
		$content  = str_repeat( '<p>Some content in a paragraph.</p>', 100 );
		$result   = $this->invokeStatic( TextSplitter::class, 'split_text_for_translation', [ $content, 1200 ] );
		$this->assertGreaterThan( 1, count( $result ) );
		$this->assertSame( $content, implode( '', $result ) );
	}

	public function test_text_split_rejoined_equals_original(): void {
		$lines  = implode( "\n\n", array_fill( 0, 20, str_repeat( 'Test sentence. ', 10 ) ) );
		$result = $this->invokeStatic( TextSplitter::class, 'split_text_for_translation', [ $lines, 1200 ] );
		$this->assertSame( $lines, implode( '', $result ) );
	}

	public function test_text_split_no_chunk_exceeds_max_chars(): void {
		$text   = implode( ' ', array_fill( 0, 1000, 'word' ) );
		$max    = 2000;
		$result = $this->invokeStatic( TextSplitter::class, 'split_text_for_translation', [ $text, $max ] );
		foreach ( $result as $chunk ) {
			$this->assertLessThanOrEqual( $max, mb_strlen( $chunk, 'UTF-8' ) );
		}
	}

	public function test_extracted_text_splitter_matches_wrapper_output(): void {
		$text = implode( "\n\n", array_fill( 0, 8, str_repeat( 'Paragraph content. ', 40 ) ) );

		$this->assertSame(
			$this->invokeStatic( TextSplitter::class, 'split_text_for_translation', [ $text, 1200 ] ),
			TextSplitter::split_text_for_translation( $text, 1200 )
		);
	}

	public function test_tower_chunk_strategy_applies_conservative_limit(): void {
		$tower_profile = TranslationRuntime::get_model_profile( 'TowerInstruct-7B-v0.2.Q4_K_M' );
		$limit         = $this->invokeStatic( TranslationRuntime::class, 'apply_chunk_strategy_to_limit', array( 4096, $tower_profile ) );

		$this->assertSame( 'tower_conservative', TranslationRuntime::get_chunk_strategy_for_model( 'towerinstruct-7b' ) );
		$this->assertSame( 1200, $limit );
	}

	public function test_default_chunk_strategy_does_not_change_limit(): void {
		$default_profile = TranslationRuntime::get_model_profile( 'gpt-4o' );
		$limit           = $this->invokeStatic( TranslationRuntime::class, 'apply_chunk_strategy_to_limit', array( 4096, $default_profile ) );

		$this->assertSame( 4096, $limit );
	}

	public function test_ministral_retry_chunk_limit_applies_on_passthrough_failure(): void {
		$limit = $this->invokeStatic(
			TranslationRuntime::class,
			'get_retry_chunk_limit_for_validation_failure',
			array( 'Ministral-8B-Instruct-2410-Q4_K_M', 'invalid_translation_language_passthrough' )
		);

		$this->assertSame( 1800, $limit );
	}
}
