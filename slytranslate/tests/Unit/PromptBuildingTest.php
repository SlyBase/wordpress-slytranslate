<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use Brain\Monkey\Functions;

/**
 * Tests for AI_Translate::prompt().
 */
class PromptBuildingTest extends TestCase {

	public function test_substitutes_language_codes_in_default_template(): void {
		Functions\when( 'get_option' )
			->alias( function ( $option, $default = false ) {
				// Return defaults: no custom template, no addon.
				return $default;
			} );

		$result = AI_Translate::prompt( 'de', 'en' );
		$this->assertStringContainsString( 'de', $result );
		$this->assertStringContainsString( 'en', $result );
		$this->assertStringNotContainsString( '{TO_CODE}', $result );
		$this->assertStringNotContainsString( '{FROM_CODE}', $result );
	}

	public function test_uses_custom_template_from_option(): void {
		Functions\when( 'get_option' )
			->alias( function ( $option, $default = false ) {
				if ( 'ai_translate_prompt' === $option ) {
					return 'Translate from {FROM_CODE} into {TO_CODE}.';
				}
				return $default;
			} );

		$result = AI_Translate::prompt( 'fr', 'es' );
		$this->assertSame( 'Translate from es into fr.', $result );
	}

	public function test_appends_global_addon_when_set(): void {
		Functions\when( 'get_option' )
			->alias( function ( $option, $default = false ) {
				if ( 'ai_translate_prompt_addon' === $option ) {
					return 'Be formal.';
				}
				return $default;
			} );

		$result = AI_Translate::prompt( 'de', 'en' );
		$this->assertStringContainsString( 'Be formal.', $result );
	}

	public function test_appends_additional_prompt_parameter(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$result = AI_Translate::prompt( 'de', 'en', 'Use simple language.' );
		$this->assertStringContainsString( 'Use simple language.', $result );
	}

	public function test_does_not_append_empty_additional_prompt(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$result_empty  = AI_Translate::prompt( 'de', 'en', '' );
		$result_spaces = AI_Translate::prompt( 'de', 'en', '   ' );
		// Neither should append anything beyond the base prompt.
		$this->assertSame( $result_empty, $result_spaces );
	}

	public function test_does_not_append_whitespace_only_addon(): void {
		Functions\when( 'get_option' )
			->alias( function ( $option, $default = false ) {
				if ( 'ai_translate_prompt_addon' === $option ) {
					return '   ';
				}
				return $default;
			} );

		$base_result    = AI_Translate::prompt( 'de', 'en' );
		// Whitespace-only addon should not add a separator line.
		$this->assertStringNotContainsString( "\n\n   ", $base_result );
	}

	public function test_combines_addon_and_additional_prompt_with_double_newline(): void {
		Functions\when( 'get_option' )
			->alias( function ( $option, $default = false ) {
				if ( 'ai_translate_prompt_addon' === $option ) {
					return 'Addon text.';
				}
				return $default;
			} );

		$result = AI_Translate::prompt( 'de', 'en', 'Extra instruction.' );
		$parts  = explode( "\n\n", $result );
		$this->assertCount( 3, $parts );
		$this->assertSame( 'Addon text.', $parts[1] );
		$this->assertSame( 'Extra instruction.', $parts[2] );
	}
}
