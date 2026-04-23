<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\TranslationRuntime;
use AI_Translate\TranslationValidator;

class RunawayOutputValidationTest extends TestCase {

	public function test_rejects_runaway_output_for_observed_live_pattern_763_to_23390(): void {
		$source     = str_repeat( 'Some translatable sentence. ', 30 ); // ~840 chars > short-text band
		$translated = str_repeat( 'Hallucinated paragraph repeated again and again. ', 600 ); // ~30k chars

		$result = TranslationValidator::validate( $source, $translated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_runaway_output', $result->get_error_code() );
	}

	public function test_rejects_runaway_output_for_observed_live_pattern_2176_to_21478(): void {
		$source     = str_repeat( 'Translatable content with structure. ', 60 ); // ~2200 chars
		$translated = str_repeat( 'Runaway hallucinated explanation goes on. ', 520 ); // ~22k chars

		$result = TranslationValidator::validate( $source, $translated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_runaway_output', $result->get_error_code() );
	}

	public function test_allows_normal_translation_with_modest_growth(): void {
		$source     = str_repeat( 'A short English sentence. ', 50 ); // ~1300 chars
		$translated = str_repeat( 'Ein kurzer deutscher Satz mit etwas Ueberlaenge. ', 50 ); // ~2450 chars (~1.9x)

		$result = TranslationValidator::validate( $source, $translated );

		$this->assertNull( $result );
	}

	public function test_short_inputs_remain_governed_by_short_text_guard(): void {
		// 100 source chars -> 350 chars: handled by has_excessive_short_text_growth (4x for <=220),
		// not by the runaway guard (which kicks in at 221+ chars). Plain text without markdown
		// or newlines stays valid because growth alone (3.5x) is below the short-text threshold.
		$source     = str_repeat( 'Hi there ', 11 ); // ~99 chars
		$translated = str_repeat( 'Hallo zusammen ', 23 ); // ~345 chars, single line, no markdown

		$result = TranslationValidator::validate( $source, $translated );

		$this->assertNull( $result );
	}

	public function test_compute_max_output_tokens_caps_runaway_inputs(): void {
		$tokens_for_small_input  = TranslationRuntime::compute_max_output_tokens( 50 );
		$tokens_for_medium_input = TranslationRuntime::compute_max_output_tokens( 2000 );
		$tokens_for_huge_input   = TranslationRuntime::compute_max_output_tokens( 100000 );

		$this->assertSame( 256, $tokens_for_small_input, 'Short inputs floor at 256 tokens.' );
		$this->assertGreaterThanOrEqual( 256, $tokens_for_medium_input );
		$this->assertLessThanOrEqual( 32768, $tokens_for_medium_input );
		$this->assertSame( 32768, $tokens_for_huge_input, 'Huge inputs cap at the 32768 ceiling.' );
	}

	public function test_is_retryable_validation_error_code_includes_runaway(): void {
		$this->assertTrue( TranslationRuntime::is_retryable_validation_error_code( 'invalid_translation_runaway_output' ) );
		$this->assertTrue( TranslationRuntime::is_retryable_validation_error_code( 'invalid_translation_assistant_reply' ) );
		$this->assertFalse( TranslationRuntime::is_retryable_validation_error_code( 'translation_cancelled' ) );
	}
}
