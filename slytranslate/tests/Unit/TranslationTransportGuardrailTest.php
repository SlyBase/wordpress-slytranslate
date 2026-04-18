<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use Brain\Monkey\Functions;

class TranslationTransportGuardrailTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'translation_runtime_context', null );
		$this->setStaticProperty( AI_Translate::class, 'translation_source_lang', null );
		$this->setStaticProperty( AI_Translate::class, 'translation_target_lang', null );
		$this->setStaticProperty( AI_Translate::class, 'model_slug_request_override', null );
		$this->setStaticProperty( AI_Translate::class, 'last_translation_transport_diagnostics', null );

		parent::tearDown();
	}

	public function test_translategemma_requires_direct_api_when_no_direct_url_is_configured(): void {
		$this->setStaticProperty( AI_Translate::class, 'translation_runtime_context', array(
			'service_slug'   => '',
			'model_slug'     => 'translategemma-4b-it.Q4_K_M',
			'direct_api_url' => '',
		) );

		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);

		$result = $this->invokeStatic( AI_Translate::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'translategemma_requires_direct_api', $result->get_error_code() );

		$diagnostics = $this->getStaticProperty( AI_Translate::class, 'last_translation_transport_diagnostics' );
		$this->assertSame( 'blocked', $diagnostics['transport'] );
		$this->assertSame( 'direct_api_required', $diagnostics['failure_reason'] );
	}

	public function test_translategemma_requires_kwargs_when_live_probe_fails(): void {
		$updated_options = array();

		$this->setStaticProperty( AI_Translate::class, 'translation_runtime_context', array(
			'service_slug'   => '',
			'model_slug'     => 'translategemma-4b-it.Q4_K_M',
			'direct_api_url' => 'http://llama.local:8080',
		) );

		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $option, $value, $autoload = null ) use ( &$updated_options ) {
				$updated_options[ $option ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_failed', 'boom' ) );

		$result = $this->invokeStatic( AI_Translate::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'translategemma_requires_kwargs', $result->get_error_code() );
		$this->assertSame( '0', $updated_options['ai_translate_direct_api_kwargs_detected'] ?? null );
		$this->assertIsInt( $updated_options['ai_translate_direct_api_kwargs_last_probed_at'] ?? null );

		$diagnostics = $this->getStaticProperty( AI_Translate::class, 'last_translation_transport_diagnostics' );
		$this->assertSame( 'blocked', $diagnostics['transport'] );
		$this->assertSame( 'kwargs_required', $diagnostics['failure_reason'] );
	}

	public function test_translategemma_does_not_fallback_to_wp_ai_client_when_direct_api_fails(): void {
		$this->setStaticProperty( AI_Translate::class, 'translation_runtime_context', array(
			'service_slug'   => '',
			'model_slug'     => 'translategemma-4b-it.Q4_K_M',
			'direct_api_url' => 'http://llama.local:8080',
		) );
		$this->setStaticProperty( AI_Translate::class, 'translation_source_lang', 'en' );
		$this->setStaticProperty( AI_Translate::class, 'translation_target_lang', 'de' );

		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '1';
				}

				return $default;
			}
		);
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_failed', 'boom' ) );
		Functions\when( 'wp_ai_client_prompt' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_ai_client_prompt must not be reached for TranslateGemma direct API failures.' );
			}
		);

		$result = $this->invokeStatic( AI_Translate::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'translategemma_direct_api_failed', $result->get_error_code() );

		$diagnostics = $this->getStaticProperty( AI_Translate::class, 'last_translation_transport_diagnostics' );
		$this->assertSame( 'direct_api_failed', $diagnostics['transport'] );
		$this->assertSame( 'direct_api_failed', $diagnostics['failure_reason'] );
	}

	public function test_non_translategemma_still_falls_back_to_wp_ai_client(): void {
		$this->setStaticProperty( AI_Translate::class, 'translation_runtime_context', array(
			'service_slug'   => '',
			'model_slug'     => 'gemma-3-4b-it',
			'direct_api_url' => 'http://llama.local:8080',
		) );

		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		Functions\when( 'wp_remote_post' )->justReturn( new \WP_Error( 'http_failed', 'boom' ) );
		Functions\when( 'wp_ai_client_prompt' )->alias(
			static function ( string $text ) {
				return new class( $text ) {
					private string $text;

					public function __construct( string $text ) {
						$this->text = $text;
					}

					public function using_system_instruction( string $prompt ) {
						return $this;
					}

					public function using_temperature( int $temperature ) {
						return $this;
					}

					public function using_model_preference( string $model_slug ) {
						return $this;
					}

					public function generate_text(): string {
						return 'Hallo Welt';
					}
				};
			}
		);

		$result = $this->invokeStatic( AI_Translate::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertSame( 'Hallo Welt', $result );

		$diagnostics = $this->getStaticProperty( AI_Translate::class, 'last_translation_transport_diagnostics' );
		$this->assertSame( 'wp_ai_client', $diagnostics['transport'] );
		$this->assertTrue( $diagnostics['fallback_allowed'] );
	}
}