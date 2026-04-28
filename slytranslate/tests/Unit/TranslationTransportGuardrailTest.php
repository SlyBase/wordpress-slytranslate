<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\TranslationRuntime;

class TranslationTransportGuardrailTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', null );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', null );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );
		$this->setStaticProperty( TranslationRuntime::class, 'last_diagnostics', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );
		$this->setStaticProperty( TranslationRuntime::class, 'rate_limit_retry_depth', 0 );

		parent::tearDown();
	}

	public function test_translate_chunk_normalizes_nested_openrouter_rate_limit_payload_from_connector_error(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'google/gemma-4-31b-it:free',
			'direct_api_url' => '',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'de' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'rate_limit_retry_depth', 3 );

		$this->stubWpFunctionReturn( 'get_option', false );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () {
				return new class {
					public function using_temperature( $temperature ) {
						return $this;
					}

					public function using_model_preference( $model_slug ) {
						return $this;
					}

					public function using_max_tokens( $max_tokens ) {
						return $this;
					}

					public function generate_text() {
						return new \WP_Error(
							'http_bad_gateway',
							'Bad Gateway (502) - Provider returned error',
							array(
								'body' => wp_json_encode(
									array(
										'error' => array(
											'message'  => 'Provider returned error',
											'code'     => 429,
											'metadata' => array(
												'raw' => 'google/gemma-4-31b-it:free is temporarily rate-limited upstream. Please retry shortly, or add your own key to accumulate your rate limits: https://openrouter.ai/settings/integrations',
											),
										),
									)
								),
							)
						);
					}
				};
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Katze', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'translation_provider_rate_limited', $result->get_error_code() );
		$this->assertStringContainsString( 'temporarily rate-limited upstream', $result->get_error_message() );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'translation_provider_rate_limited', $diagnostics['error_code'] );
		$this->assertStringContainsString( 'temporarily rate-limited upstream', $diagnostics['error_message'] );
	}

	public function test_translate_chunk_surfaces_nested_provider_message_from_generic_connector_502_error(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'nvidia/nemotron-3-super-120b-a12b:free',
			'direct_api_url' => '',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$this->stubWpFunctionReturn( 'get_option', false );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () {
				return new class {
					public function using_system_instruction( string $prompt ) {
						return $this;
					}

					public function using_temperature( $temperature ) {
						return $this;
					}

					public function using_model_preference( $model_slug ) {
						return $this;
					}

					public function using_max_tokens( $max_tokens ) {
						return $this;
					}

					public function generate_text() {
						return new \WP_Error(
							'prompt_upstream_server_error',
							'Bad Gateway (502) - Provider returned error',
							array(
								'body' => wp_json_encode(
									array(
										'error' => array(
											'message'  => 'Provider returned error',
											'metadata' => array(
												'raw' => 'The upstream provider rejected model nvidia/nemotron-3-super-120b-a12b:free because the request payload was not supported.',
											),
										),
									)
								),
							)
						);
					}
				};
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'prompt_upstream_server_error', $result->get_error_code() );
		$this->assertStringContainsString( 'request payload was not supported', $result->get_error_message() );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'prompt_upstream_server_error', $diagnostics['error_code'] );
		$this->assertStringContainsString( 'request payload was not supported', $diagnostics['error_message'] );
	}

	public function test_tower_profile_with_force_direct_api_still_uses_wp_ai_client_transport(): void {
		$direct_api_calls = 0;
		$captured         = array(
			'user_content'        => '',
			'system_prompt_calls' => 0,
			'system_prompt'       => '',
			'temperature'         => null,
			'model'               => '',
		);

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'TowerInstruct-7B-v0.2.Q4_K_M',
			'direct_api_url' => 'http://llama.local:8080',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'slytranslate_force_direct_api' === $option ) {
					return '1';
				}

				if ( 'slytranslate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		$this->stubWpFunction( 'wp_remote_post',
			static function () use ( &$direct_api_calls ) {
				++$direct_api_calls;
				throw new \RuntimeException( 'wp_remote_post must not be reached for non-strict models when force_direct_api is enabled.' );
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) use ( &$captured ) {
				$captured['user_content'] = $text;

				return new class( $captured ) {
					private array $captured;

					public function __construct( array &$captured ) {
						$this->captured =& $captured;
					}

					public function using_system_instruction( string $prompt ) {
						++$this->captured['system_prompt_calls'];
						$this->captured['system_prompt'] = $prompt;
						return $this;
					}

					public function using_temperature( $temperature ) {
						$this->captured['temperature'] = (float) $temperature;
						return $this;
					}

					public function using_model_preference( string $model_slug ) {
						$this->captured['model'] = $model_slug;
						return $this;
					}

					public function using_max_tokens( int $max_tokens ) {
						return $this;
					}

					public function generate_text(): string {
						return 'Hallo Welt';
					}
				};
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertSame( 'Hallo Welt', $result );
		$this->assertSame( 0, $direct_api_calls );
		$this->assertStringContainsString( 'Translate the following text from EN into DE.', $captured['user_content'] );
		$this->assertStringContainsString( 'EN:', $captured['user_content'] );
		$this->assertStringContainsString( 'DE:', $captured['user_content'] );
		$this->assertSame( 0, $captured['system_prompt_calls'] );
		$this->assertEqualsWithDelta( 0.2, (float) $captured['temperature'], 0.0001 );
		$this->assertSame( 'TowerInstruct-7B-v0.2.Q4_K_M', $captured['model'] );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'wp_ai_client', $diagnostics['transport'] );
	}

	public function test_madlad_profile_is_blocked_before_chat_transport_is_called(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'madlad400-10b-mt.Q4_K_M',
			'direct_api_url' => '',
		) );

		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function () {
				throw new \RuntimeException( 'wp_ai_client_prompt must not be reached for MadLad chat-transport guardrail.' );
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'model_chat_transport_unsupported', $result->get_error_code() );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'blocked', $diagnostics['transport'] );
		$this->assertSame( 'chat_transport_unsupported', $diagnostics['failure_reason'] );
	}

	public function test_non_translategemma_still_falls_back_to_wp_ai_client(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'gemma-3-4b-it',
			'direct_api_url' => 'http://llama.local:8080',
		) );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'slytranslate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		// Non-connection failure (4xx) returns null → falls through to wp_ai_client.
		$this->stubWpFunctionReturn( 'wp_remote_post', array(
			'response' => array( 'code' => 502 ),
			'body'     => '',
		) );
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) {
				return new class( $text ) {
					private string $text;

					public function __construct( string $text ) {
						$this->text = $text;
					}

					public function using_system_instruction( string $prompt ) {
						return $this;
					}

					public function using_temperature( float $temperature ) {
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

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertSame( 'Hallo Welt', $result );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'wp_ai_client', $diagnostics['transport'] );
		$this->assertTrue( $diagnostics['fallback_allowed'] );
	}

	public function test_wp_ai_client_error_records_error_details_in_diagnostics(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'qwen/qwen3-32b',
			'direct_api_url' => '',
		) );

		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function () {
				return new class {
					public function using_system_instruction( string $prompt ) { return $this; }
					public function using_temperature( float $temperature ) { return $this; }
					public function using_model_preference( string $model_slug ) { return $this; }
					public function using_max_tokens( int $max_tokens ) { return $this; }
					public function generate_text(): \WP_Error {
						return new \WP_Error( 'prompt_network_error', 'Local connector timed out.' );
					}
				};
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'prompt_network_error', $result->get_error_code() );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'wp_ai_client', $diagnostics['transport'] );
		$this->assertSame( 'prompt_network_error', $diagnostics['failure_reason'] );
		$this->assertSame( 'prompt_network_error', $diagnostics['error_code'] );
		$this->assertSame( 'Local connector timed out.', $diagnostics['error_message'] );
		$this->assertSame( 'qwen/qwen3-32b', $diagnostics['requested_model_slug'] );
		$this->assertSame( 'qwen/qwen3-32b', $diagnostics['effective_model_slug'] );
	}

	public function test_non_translategemma_connection_error_falls_back_to_wp_ai_client(): void {
		// A single timeout / connection drop on the direct API must not
		// tear down the entire content phase: for non-strict models the
		// runtime falls back to the WP AI Client transport for THIS chunk
		// only and lets subsequent chunks try the direct path again.
		// Strict-direct-API models like TranslateGemma still hard-fail
		// (covered by the dedicated translategemma guardrail tests).
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'gemma-3-4b-it',
			'direct_api_url' => 'http://llama.local:8080',
		) );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'slytranslate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		$this->stubWpFunctionReturn( 'wp_remote_post', new \WP_Error( 'http_failed', 'Connection refused' ) );
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function () {
				return new class {
					public function using_system_instruction( string $prompt ) { return $this; }
					public function using_temperature( float $temperature ) { return $this; }
					public function using_model_preference( string $model_slug ) { return $this; }
					public function generate_text(): string { return 'Hallo Welt'; }
				};
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertSame( 'Hallo Welt', $result );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'wp_ai_client', $diagnostics['transport'] );
		$this->assertTrue( $diagnostics['fallback_allowed'] );
	}

	public function test_non_translategemma_force_direct_api_uses_wp_ai_client_transport(): void {
		$direct_api_calls  = 0;
		$wp_transport_calls = 0;

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'gemma-3-4b-it',
			'direct_api_url' => 'http://llama.local:8080',
		) );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'slytranslate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}
				if ( 'slytranslate_force_direct_api' === $option ) {
					return '1';
				}

				return $default;
			}
		);
		$this->stubWpFunction( 'wp_remote_post',
			static function () use ( &$direct_api_calls ) {
				++$direct_api_calls;
				throw new \RuntimeException( 'wp_remote_post must not be reached for non-strict models when force_direct_api is enabled.' );
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function () use ( &$wp_transport_calls ) {
				++$wp_transport_calls;
				return new class {
					public function using_system_instruction( string $prompt ) { return $this; }
					public function using_temperature( float $temperature ) { return $this; }
					public function using_model_preference( string $model_slug ) { return $this; }
					public function using_max_tokens( int $max_tokens ) { return $this; }
					public function generate_text(): string { return 'Hallo Welt'; }
				};
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertSame( 'Hallo Welt', $result );
		$this->assertSame( 0, $direct_api_calls );
		$this->assertSame( 1, $wp_transport_calls );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'wp_ai_client', $diagnostics['transport'] );
		$this->assertSame( '', $diagnostics['failure_reason'] );
	}
}
