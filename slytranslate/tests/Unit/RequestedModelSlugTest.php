<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\TranslationRuntime;

class RequestedModelSlugTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );

		parent::tearDown();
	}

	public function test_returns_per_request_override_before_runtime_context_model(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'qwen/qwen3-32b',
			'direct_api_url' => '',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', 'Phi-4-mini-instruct-Q4_K_M' );

		$this->assertSame( 'Phi-4-mini-instruct-Q4_K_M', TranslationRuntime::get_requested_model_slug() );
	}

	public function test_falls_back_to_runtime_context_model_when_no_override_is_set(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'qwen/qwen3-32b',
			'direct_api_url' => '',
		) );

		$this->assertSame( 'qwen/qwen3-32b', TranslationRuntime::get_requested_model_slug() );
	}

	public function test_tower_slug_maps_to_bilingual_profile_rules(): void {
		$model_slug = 'TowerInstruct-7B-v0.2.Q4_K_M';

		$this->assertSame( 'bilingual_frame', TranslationRuntime::get_prompt_style_for_model( $model_slug ) );
		$this->assertSame( 'tower_conservative', TranslationRuntime::get_chunk_strategy_for_model( $model_slug ) );
		$this->assertTrue( TranslationRuntime::is_tower_model( $model_slug ) );
	}

	public function test_salamandra_slug_maps_to_conservative_bilingual_profile(): void {
		$model_slug = 'salamandraTA_7B_inst_q4';
		$profile    = TranslationRuntime::get_model_profile( $model_slug );

		$this->assertSame( 'salamandra', $profile['id'] );
		$this->assertSame( 'bilingual_frame', TranslationRuntime::get_prompt_style_for_model( $model_slug ) );
		$this->assertSame( 'tower_conservative', TranslationRuntime::get_chunk_strategy_for_model( $model_slug ) );
		$this->assertSame( 1200, (int) ( $profile['retry_profile']['retry_chunk_chars'] ?? 0 ) );
		$this->assertEqualsWithDelta( 0.2, (float) ( $profile['temperature'] ?? 0.0 ), 0.0001 );
	}

	public function test_madlad_slug_maps_to_chat_unsupported_profile(): void {
		$model_slug = 'madlad400-10b-mt.Q4_K_M';
		$profile    = TranslationRuntime::get_model_profile( $model_slug );

		$this->assertSame( 'madlad', $profile['id'] );
		$this->assertFalse( ! empty( $profile['supports_chat_completions'] ) );
	}

	public function test_ministral_slug_maps_to_bilingual_profile_rules(): void {
		$model_slug = 'Ministral-8B-Instruct-2410-Q4_K_M';
		$profile    = TranslationRuntime::get_model_profile( $model_slug );

		$this->assertSame( 'ministral', $profile['id'] );
		$this->assertSame( 'bilingual_frame', TranslationRuntime::get_prompt_style_for_model( $model_slug ) );
		$this->assertSame( 'default', TranslationRuntime::get_chunk_strategy_for_model( $model_slug ) );
		$this->assertFalse( TranslationRuntime::model_requires_strict_direct_api( $model_slug ) );
		$this->assertSame( 1400, (int) ( $profile['retry_profile']['retry_chunk_chars'] ?? 0 ) );
	}

	public function test_ministral3_slug_maps_to_conservative_profile_rules(): void {
		$model_slug = 'Ministral-3-3B-Instruct-2512-Q4_K_M';
		$profile    = TranslationRuntime::get_model_profile( $model_slug );

		$this->assertSame( 'ministral3', $profile['id'] );
		$this->assertSame( 'bilingual_frame', TranslationRuntime::get_prompt_style_for_model( $model_slug ) );
		$this->assertSame( 'tower_conservative', TranslationRuntime::get_chunk_strategy_for_model( $model_slug ) );
		$this->assertSame( 1400, (int) ( $profile['retry_profile']['retry_chunk_chars'] ?? 0 ) );
	}

	public function test_qwen_slug_maps_to_thinking_aware_profile(): void {
		$model_slug = 'Qwen3.5-9B-Q4_K_M';
		$profile    = TranslationRuntime::get_model_profile( $model_slug );

		$this->assertSame( 'qwen_thinking_aware', $profile['id'] );
		$this->assertFalse( ! empty( $profile['requires_chat_template_kwargs'] ) );
		$this->assertArrayHasKey( 'chat_template_kwargs', $profile['extra_request_body'] );
		$this->assertSame( 'bilingual_frame', TranslationRuntime::get_prompt_style_for_model( $model_slug ) );
	}

	public function test_gemma4_slug_maps_to_thinking_aware_profile(): void {
		$model_slug = 'gemma-4-E4B-it-Q6_K';
		$profile    = TranslationRuntime::get_model_profile( $model_slug );

		$this->assertSame( 'gemma4_thinking_aware', $profile['id'] );
		$this->assertFalse( ! empty( $profile['requires_chat_template_kwargs'] ) );
		$this->assertArrayHasKey( 'chat_template_kwargs', $profile['extra_request_body'] );
	}

	public function test_phi4_slug_maps_to_thinking_aware_profile(): void {
		$model_slug = 'Phi-4-mini-instruct-Q4_K_M';
		$profile    = TranslationRuntime::get_model_profile( $model_slug );

		$this->assertSame( 'phi4_thinking_aware', $profile['id'] );
		$this->assertFalse( ! empty( $profile['requires_chat_template_kwargs'] ) );
		$this->assertArrayHasKey( 'chat_template_kwargs', $profile['extra_request_body'] );
		$this->assertSame( 'bilingual_frame', TranslationRuntime::get_prompt_style_for_model( $model_slug ) );
	}

	public function test_nemotron_slug_maps_to_system_prompt_profile(): void {
		$model_slug = 'nvidia/nemotron-3-super-120b-a12b:free';
		$profile    = TranslationRuntime::get_model_profile( $model_slug );

		$this->assertSame( 'nemotron_system', $profile['id'] );
		$this->assertSame( 'generic_template', TranslationRuntime::get_prompt_style_for_model( $model_slug ) );
		$this->assertFalse( ! empty( $profile['requires_chat_template_kwargs'] ) );
	}

	public function test_with_model_slug_override_normalizes_openrouter_label_prefix(): void {
		$result = TranslationRuntime::with_model_slug_override(
			array( 'model_slug' => 'openrouter nvidia/nemotron-3-super-120b-a12b:free' ),
			static function (): string {
				return TranslationRuntime::get_requested_model_slug();
			}
		);

		$this->assertSame( 'nvidia/nemotron-3-super-120b-a12b:free', $result );
	}

	public function test_translategemma_slug_routes_through_wp_ai_client_with_chat_template_kwargs(): void {
		$model_slug = 'translategemma-4b-it.Q4_K_M';
		$profile    = TranslationRuntime::get_model_profile( $model_slug );

		$this->assertSame( 'translategemma', $profile['id'] );
		$this->assertFalse( TranslationRuntime::model_requires_strict_direct_api( $model_slug ) );
		$this->assertTrue( ! empty( $profile['requires_chat_template_kwargs'] ) );
		$this->assertArrayHasKey( 'chat_template_kwargs', $profile['extra_request_body'] );
	}
}