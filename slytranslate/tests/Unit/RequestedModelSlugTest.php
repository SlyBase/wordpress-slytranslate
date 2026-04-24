<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\TranslationRuntime;

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

	public function test_translategemma_slug_maps_to_strict_direct_api_profile(): void {
		$this->assertTrue( TranslationRuntime::model_requires_strict_direct_api( 'translategemma-4b-it.Q4_K_M' ) );
	}
}