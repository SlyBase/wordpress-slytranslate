<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\TranslationRuntime;

class ConfigureDiagnosticsTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', null );
		$this->setStaticProperty( TranslationRuntime::class, 'last_diagnostics', null );

		parent::tearDown();
	}

	public function test_execute_configure_exposes_last_transport_diagnostics(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'last_diagnostics', array(
			'transport'            => 'wp_ai_client',
			'model_slug'           => 'qwen/qwen3-32b',
			'requested_model_slug' => 'qwen/qwen3-32b',
			'effective_model_slug' => 'qwen/qwen3-32b',
			'direct_api_url'       => '',
			'kwargs_supported'     => false,
			'fallback_allowed'     => true,
			'failure_reason'       => 'prompt_network_error',
			'error_code'           => 'prompt_network_error',
			'error_message'        => 'Local connector timed out.',
		) );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_prompt' === $option ) {
					return AI_Translate::get_default_prompt();
				}

				return $default;
			}
		);

		$result = AI_Translate::execute_configure( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'wp_ai_client', $result['last_transport_diagnostics']['transport'] ?? null );
		$this->assertSame( 'prompt_network_error', $result['last_transport_diagnostics']['error_code'] ?? null );
		$this->assertSame( 'Local connector timed out.', $result['last_transport_diagnostics']['error_message'] ?? null );
	}
}