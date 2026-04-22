<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\TranslationRuntime;

/**
 * Tests for context-window related calculations.
 *
 * Constants used by the methods under test:
 *   MIN_TRANSLATION_CHARS         = 1200
 *   MAX_TRANSLATION_CHARS         = 48000
 *   MIN_CONTEXT_WINDOW_TOKENS     = 2048
 *   SAFE_CHARS_PER_CONTEXT_TOKEN  = 0.5
 */
class ContextWindowTest extends TestCase {

	// -----------------------------------------------------------------------
	// get_translation_chunk_char_limit_from_context_window
	// -----------------------------------------------------------------------

	public function test_normal_context_window_produces_expected_char_limit(): void {
		// 8192 * 0.5 = 4096 → in [1200, 8000] → 4096.
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'get_chunk_char_limit_from_context_window',
			[ 8192 ]
		);
		$this->assertSame( 4096, $result );
	}

	public function test_small_context_window_is_clamped_to_min_chars(): void {
		// 2048 (MIN tokens) * 0.5 = 1024 → below MIN_TRANSLATION_CHARS (1200) → 1200.
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'get_chunk_char_limit_from_context_window',
			[ 2048 ]
		);
		$this->assertSame( 1200, $result );
	}

	public function test_below_min_token_threshold_is_clamped_to_min_tokens_first(): void {
		// 1000 tokens < MIN_CONTEXT_WINDOW_TOKENS (2048) → clamped to 2048 → 1024 → 1200.
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'get_chunk_char_limit_from_context_window',
			[ 1000 ]
		);
		$this->assertSame( 1200, $result );
	}

	public function test_large_context_window_is_clamped_to_max_chars(): void {
		// 200000 * 0.5 = 100000 → above MAX_TRANSLATION_CHARS (48000) → 48000.
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'get_chunk_char_limit_from_context_window',
			[ 200000 ]
		);
		$this->assertSame( 48000, $result );
	}

	public function test_context_window_just_above_threshold_for_max(): void {
		// 96000 * 0.5 = 48000 → exactly MAX → 48000.
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'get_chunk_char_limit_from_context_window',
			[ 96000 ]
		);
		$this->assertSame( 48000, $result );
	}

	// -----------------------------------------------------------------------
	// extract_context_window_tokens_from_error
	// -----------------------------------------------------------------------

	public function test_extracts_tokens_from_context_size_pattern(): void {
		$error  = new \WP_Error( 'ai_error', 'context size (4096 tokens) exceeded' );
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'extract_context_window_tokens_from_error',
			[ $error ]
		);
		$this->assertSame( 4096, $result );
	}

	public function test_extracts_tokens_from_maximum_context_length_pattern(): void {
		$error  = new \WP_Error( 'ai_error', 'maximum context length is 8192 tokens for this model' );
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'extract_context_window_tokens_from_error',
			[ $error ]
		);
		$this->assertSame( 8192, $result );
	}

	public function test_extracts_tokens_from_context_window_pattern(): void {
		$error  = new \WP_Error( 'ai_error', 'context window 16000 tokens reached' );
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'extract_context_window_tokens_from_error',
			[ $error ]
		);
		$this->assertSame( 16000, $result );
	}

	public function test_extracts_tokens_from_context_window_of_pattern(): void {
		$error  = new \WP_Error( 'ai_error', 'context window of 32768 tokens' );
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'extract_context_window_tokens_from_error',
			[ $error ]
		);
		$this->assertSame( 32768, $result );
	}

	public function test_returns_zero_when_no_pattern_matches(): void {
		$error  = new \WP_Error( 'ai_error', 'Some unrelated error message without token info' );
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'extract_context_window_tokens_from_error',
			[ $error ]
		);
		$this->assertSame( 0, $result );
	}

	public function test_returns_zero_for_empty_error(): void {
		$error  = new \WP_Error();
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'extract_context_window_tokens_from_error',
			[ $error ]
		);
		$this->assertSame( 0, $result );
	}

	public function test_returns_first_match_from_multiple_messages(): void {
		$error = new \WP_Error();
		$error->add( 'first', 'context size (2048 tokens) used' );
		$error->add( 'second', 'maximum context length is 4096 tokens' );
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'extract_context_window_tokens_from_error',
			[ $error ]
		);
		$this->assertSame( 2048, $result );
	}

	public function test_pattern_matching_is_case_insensitive(): void {
		$error  = new \WP_Error( 'ai_error', 'Context Size (4096 Tokens) reached' );
		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'extract_context_window_tokens_from_error',
			[ $error ]
		);
		$this->assertSame( 4096, $result );
	}
}
