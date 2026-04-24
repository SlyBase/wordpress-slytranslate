<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\TranslationRuntime;

/**
 * Tests for AI_Translate::prompt().
 */
class PromptBuildingTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', null );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );

		parent::tearDown();
	}

	public function test_substitutes_language_codes_in_default_template(): void {
		$this->stubWpFunction( 'get_option',
			function ( $option, $default = false ) {
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
		$this->stubWpFunction( 'get_option',
			function ( $option, $default = false ) {
				if ( 'ai_translate_prompt' === $option ) {
					return 'Translate from {FROM_CODE} into {TO_CODE}.';
				}
				return $default;
			} );

		$result = AI_Translate::prompt( 'fr', 'es' );
		$this->assertSame( 'Translate from es into fr.', $result );
	}

	public function test_appends_global_addon_when_set(): void {
		$this->stubWpFunction( 'get_option',
			function ( $option, $default = false ) {
				if ( 'ai_translate_prompt_addon' === $option ) {
					return 'Be formal.';
				}
				return $default;
			} );

		$result = AI_Translate::prompt( 'de', 'en' );
		$this->assertStringContainsString( 'Be formal.', $result );
	}

	public function test_appends_additional_prompt_parameter(): void {
		$this->stubWpFunctionReturn( 'get_option', false );

		$result = AI_Translate::prompt( 'de', 'en', 'Use simple language.' );
		$this->assertStringContainsString( 'Use simple language.', $result );
	}

	public function test_does_not_append_empty_additional_prompt(): void {
		$this->stubWpFunctionReturn( 'get_option', false );

		$result_empty  = AI_Translate::prompt( 'de', 'en', '' );
		$result_spaces = AI_Translate::prompt( 'de', 'en', '   ' );
		// Neither should append anything beyond the base prompt.
		$this->assertSame( $result_empty, $result_spaces );
	}

	public function test_does_not_append_whitespace_only_addon(): void {
		$this->stubWpFunction( 'get_option',
			function ( $option, $default = false ) {
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
		$this->stubWpFunction( 'get_option',
			function ( $option, $default = false ) {
				if ( 'ai_translate_prompt_addon' === $option ) {
					return 'Addon text.';
				}
				return $default;
			} );

		$result = AI_Translate::prompt( 'de', 'en', 'Extra instruction.' );
		$parts  = explode( "\n\n", $result );
		$this->assertCount( 3, $parts );
		$this->assertSame( 'Addon text.', $parts[1] );
		$this->assertSame( 'Additional style instructions (do NOT translate these lines, apply them to the user-provided content): Extra instruction.', $parts[2] );
	}

	public function test_tower_profile_builds_bilingual_user_only_payload(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$profile = TranslationRuntime::get_model_profile( 'TowerInstruct-7B-v0.2.Q4_K_M' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array( 'Hello world', 'Prompt', $profile, false, 0 )
		);

		$this->assertSame( 'bilingual_frame', TranslationRuntime::get_prompt_style_for_model( 'towerinstruct-7b' ) );
		$this->assertFalse( $payload['use_system_prompt'] );
		$this->assertSame( '', $payload['system_prompt'] );
		$this->assertStringContainsString( 'Translate the following text from English into German.', $payload['user_content'] );
		$this->assertStringContainsString( 'English:', $payload['user_content'] );
		$this->assertStringContainsString( 'German:', $payload['user_content'] );
	}

	public function test_ministral_profile_builds_bilingual_user_only_payload(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$profile = TranslationRuntime::get_model_profile( 'Ministral-8B-Instruct-2410-Q4_K_M' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array( 'Hello world', 'Prompt', $profile, false, 0 )
		);

		$this->assertSame( 'bilingual_frame', TranslationRuntime::get_prompt_style_for_model( 'ministral-8b-instruct' ) );
		$this->assertFalse( $payload['use_system_prompt'] );
		$this->assertSame( '', $payload['system_prompt'] );
		$this->assertStringContainsString( 'Translate the following text from English into German.', $payload['user_content'] );
		$this->assertStringContainsString( 'English:', $payload['user_content'] );
		$this->assertStringContainsString( 'German:', $payload['user_content'] );
	}

	public function test_bilingual_payload_promotes_informal_du_requirement_when_requested(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$profile = TranslationRuntime::get_model_profile( 'Ministral-3-3B-Instruct-2512-Q4_K_M' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array(
				'Please open your dashboard.',
				'Anreden mit "du" statt "Sie". junger aber professioneller ton.',
				$profile,
				false,
				0
			)
		);

		$this->assertStringContainsString( 'STYLE REQUIREMENT (German): Use informal address ("du"/"dir"/"dein"). Never use formal address ("Sie"/"Ihnen"/"Ihr").', $payload['user_content'] );
	}

	public function test_default_profile_keeps_system_plus_user_payload_shape(): void {
		$profile = TranslationRuntime::get_model_profile( 'gpt-4o' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array( 'Hello world', 'Prompt', $profile, false, 0 )
		);

		$this->assertTrue( $payload['use_system_prompt'] );
		$this->assertSame( 'Prompt', $payload['system_prompt'] );
		$this->assertSame( 'Hello world', $payload['user_content'] );
	}
}
