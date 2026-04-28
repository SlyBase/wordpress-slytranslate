<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\TranslationRuntime;

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
				if ( 'slytranslate_prompt' === $option ) {
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
				if ( 'slytranslate_prompt_addon' === $option ) {
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
				if ( 'slytranslate_prompt_addon' === $option ) {
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
				if ( 'slytranslate_prompt_addon' === $option ) {
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
			array( 'Hello world', 'Prompt', $profile, 'TowerInstruct-7B-v0.2.Q4_K_M', false, 0 )
		);

		$this->assertSame( 'bilingual_frame', TranslationRuntime::get_prompt_style_for_model( 'towerinstruct-7b' ) );
		$this->assertFalse( $payload['use_system_prompt'] );
		$this->assertSame( '', $payload['system_prompt'] );
		$this->assertStringContainsString( 'Translate the following text from EN into DE.', $payload['user_content'] );
		$this->assertStringContainsString( 'CRITICAL OUTPUT FORMAT: Return only the translation enclosed in <slytranslate-output> and </slytranslate-output>.', $payload['user_content'] );
		$this->assertStringContainsString( 'MANDATORY TRANSLATION RULES (obey exactly): Prompt', $payload['user_content'] );
		$this->assertStringContainsString( 'CRITICAL: Apply every translation rule above exactly.', $payload['user_content'] );
		$this->assertStringContainsString( 'EN:', $payload['user_content'] );
		$this->assertStringContainsString( 'DE:', $payload['user_content'] );
		$this->assertEqualsWithDelta( 0.2, (float) ( $payload['temperature'] ?? 0.0 ), 0.0001 );
	}

	public function test_ministral_profile_builds_bilingual_user_only_payload(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$profile = TranslationRuntime::get_model_profile( 'Ministral-8B-Instruct-2410-Q4_K_M' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array( 'Hello world', 'Prompt', $profile, 'Ministral-8B-Instruct-2410-Q4_K_M', false, 0 )
		);

		$this->assertSame( 'bilingual_frame', TranslationRuntime::get_prompt_style_for_model( 'ministral-8b-instruct' ) );
		$this->assertFalse( $payload['use_system_prompt'] );
		$this->assertSame( '', $payload['system_prompt'] );
		$this->assertStringContainsString( 'Translate the following text from EN into DE.', $payload['user_content'] );
		$this->assertStringContainsString( 'CRITICAL OUTPUT FORMAT: Return only the translation enclosed in <slytranslate-output> and </slytranslate-output>.', $payload['user_content'] );
		$this->assertStringContainsString( 'MANDATORY TRANSLATION RULES (obey exactly): Prompt', $payload['user_content'] );
		$this->assertStringContainsString( 'CRITICAL: Apply every translation rule above exactly.', $payload['user_content'] );
		$this->assertStringContainsString( 'EN:', $payload['user_content'] );
		$this->assertStringContainsString( 'DE:', $payload['user_content'] );
	}

	public function test_nemotron_profile_keeps_system_plus_user_payload_shape(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'de' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'en' );

		$profile = TranslationRuntime::get_model_profile( 'nvidia/nemotron-3-super-120b-a12b:free' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array( 'Katze', 'Prompt', $profile, 'nvidia/nemotron-3-super-120b-a12b:free', true, 0 )
		);

		$this->assertSame( 'nemotron_system', $profile['id'] ?? '' );
		$this->assertSame( 'generic_template', TranslationRuntime::get_prompt_style_for_model( 'nvidia/nemotron-3-super-120b-a12b:free' ) );
		$this->assertTrue( $payload['use_system_prompt'] );
		$this->assertSame( 'Prompt', $payload['system_prompt'] );
		$this->assertSame( 'Katze', $payload['user_content'] );
		$this->assertSame(
			array(
				'chat_template_kwargs' => array(
					'enable_thinking' => false,
				),
				'reasoning'            => array(
					'effort'  => 'none',
					'exclude' => true,
				),
				'provider'             => array(
					'require_parameters' => true,
				),
			),
			$payload['extra_request_body']
		);
	}

	public function test_bilingual_payload_uses_generic_labels_for_any_language_codes(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'pt_BR' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'zh-hans' );

		$profile = TranslationRuntime::get_model_profile( 'Ministral-8B-Instruct-2410-Q4_K_M' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array( 'Hello world', 'Prompt', $profile, 'Ministral-8B-Instruct-2410-Q4_K_M', false, 0 )
		);

		$this->assertStringContainsString( 'Translate the following text from PT-BR into ZH-HANS.', $payload['user_content'] );
		$this->assertStringContainsString( 'PT-BR:', $payload['user_content'] );
		$this->assertStringContainsString( 'ZH-HANS:', $payload['user_content'] );
	}

	public function test_ministral_bilingual_payload_keeps_user_additional_prompt_verbatim(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$user_prompt = 'Anreden mit "du" statt "Sie". junger aber professioneller ton.';
		$profile = TranslationRuntime::get_model_profile( 'Ministral-3-3B-Instruct-2512-Q4_K_M' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array(
				'Please open your dashboard.',
				$user_prompt,
				$profile,
				'Ministral-3-3B-Instruct-2512-Q4_K_M',
				false,
				0
			)
		);

		$this->assertStringContainsString( 'MANDATORY TRANSLATION RULES (obey exactly): ' . $user_prompt, $payload['user_content'] );
		$this->assertStringContainsString( 'CRITICAL: Apply every translation rule above exactly.', $payload['user_content'] );
		$this->assertStringNotContainsString( 'STYLE REQUIREMENT (German):', $payload['user_content'] );
	}

	public function test_qwen_profile_adds_enable_thinking_false_kwargs_when_supported(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$profile = TranslationRuntime::get_model_profile( 'Qwen3.5-4B-Q4_K_M' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array( 'Hello world', 'Prompt', $profile, 'Qwen3.5-4B-Q4_K_M', true, 0 )
		);

		$this->assertSame( 'qwen_thinking_aware', $profile['id'] ?? '' );
		$this->assertSame(
			array(
				'chat_template_kwargs' => array(
					'enable_thinking' => false,
				),
			),
			$payload['extra_request_body']
		);
	}

	public function test_phi4_profile_adds_enable_thinking_false_kwargs_when_supported(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$profile = TranslationRuntime::get_model_profile( 'Phi-4-mini-instruct-Q4_K_M' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array( 'Hello world', 'Prompt', $profile, 'Phi-4-mini-instruct-Q4_K_M', true, 0 )
		);

		$this->assertSame( 'phi4_thinking_aware', $profile['id'] ?? '' );
		$this->assertSame(
			array(
				'chat_template_kwargs' => array(
					'enable_thinking' => false,
				),
			),
			$payload['extra_request_body']
		);
	}

	public function test_default_profile_builds_bilingual_user_only_payload_shape(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$profile = TranslationRuntime::get_model_profile( 'gpt-4o' );
		$payload = $this->invokeStatic(
			TranslationRuntime::class,
			'build_transport_payload',
			array( 'Hello world', 'Prompt', $profile, 'gpt-4o', false, 0 )
		);

		$this->assertFalse( $payload['use_system_prompt'] );
		$this->assertSame( '', $payload['system_prompt'] );
		$this->assertStringContainsString( 'Translate the following text from EN into DE.', $payload['user_content'] );
		$this->assertStringContainsString( 'EN:', $payload['user_content'] );
		$this->assertStringContainsString( 'DE:', $payload['user_content'] );
	}
}
