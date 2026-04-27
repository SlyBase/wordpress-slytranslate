<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\DirectApiTranslationClient;
use AI_Translate\TranslationRuntime;

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

	public function test_translategemma_requires_direct_api_when_no_direct_url_is_configured(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'translategemma-4b-it.Q4_K_M',
			'direct_api_url' => '',
		) );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'translategemma_requires_direct_api', $result->get_error_code() );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'blocked', $diagnostics['transport'] );
		$this->assertSame( 'direct_api_required', $diagnostics['failure_reason'] );
	}

	public function test_translategemma_requires_kwargs_when_live_probe_fails(): void {
		$updated_options = array();

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'translategemma-4b-it.Q4_K_M',
			'direct_api_url' => 'http://llama.local:8080',
		) );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		$this->stubWpFunction( 'update_option',
			static function ( $option, $value, $autoload = null ) use ( &$updated_options ) {
				$updated_options[ $option ] = $value;
				return true;
			}
		);
		$this->stubWpFunctionReturn( 'wp_remote_post', new \WP_Error( 'http_failed', 'boom' ) );

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'translategemma_requires_kwargs', $result->get_error_code() );
		$this->assertSame( '0', $updated_options['ai_translate_direct_api_kwargs_detected'] ?? null );
		$this->assertIsInt( $updated_options['ai_translate_direct_api_kwargs_last_probed_at'] ?? null );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'blocked', $diagnostics['transport'] );
		$this->assertSame( 'kwargs_required', $diagnostics['failure_reason'] );
	}

	public function test_translategemma_does_not_fallback_to_wp_ai_client_when_direct_api_fails(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'translategemma-4b-it.Q4_K_M',
			'direct_api_url' => 'http://llama.local:8080',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '1';
				}

				return $default;
			}
		);
		$this->stubWpFunctionReturn( 'wp_remote_post', new \WP_Error( 'http_failed', 'boom' ) );
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function () {
				throw new \RuntimeException( 'wp_ai_client_prompt must not be reached for TranslateGemma direct API failures.' );
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'direct_api_connection_error', $result->get_error_code() );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'direct_api_failed', $diagnostics['transport'] );
		$this->assertSame( 'direct_api_connection_error', $diagnostics['failure_reason'] );
		$this->assertSame( 'direct_api_connection_error', $diagnostics['error_code'] );
		$this->assertStringContainsString( 'Could not connect to direct API', $diagnostics['error_message'] );
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

	public function test_translategemma_kwargs_request_omits_system_message(): void {
		$captured_body = array();

		$this->mockSuccessfulDirectApiResponse( $captured_body );

		$result = $this->invokeStatic(
			DirectApiTranslationClient::class,
			'translate',
			array(
				'Hello world',
				'Prompt',
				false,
				'translategemma-4b-it.Q4_K_M',
				'http://llama.local:8080',
				0,
				0,
				array(
					'chat_template_kwargs' => array(
						'source_lang_code' => 'en',
						'target_lang_code' => 'de',
					),
				)
			)
		);

		$this->assertSame( 'Hallo Welt', $result );
		$this->assertSame(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello world',
				),
			),
			$captured_body['messages'] ?? null
		);
		$this->assertSame(
			array(
				'source_lang_code' => 'en',
				'target_lang_code' => 'de',
			),
			$captured_body['chat_template_kwargs'] ?? null
		);
	}

	public function test_non_translategemma_kwargs_request_keeps_system_message(): void {
		$captured_body = array();

		$this->mockSuccessfulDirectApiResponse( $captured_body );

		$result = $this->invokeStatic(
			DirectApiTranslationClient::class,
			'translate',
			array(
				'Hello world',
				'Prompt',
				true,
				'gemma-3-4b-it',
				'http://llama.local:8080',
				0,
				0,
				array(
					'chat_template_kwargs' => array(
						'source_lang_code' => 'en',
						'target_lang_code' => 'de',
					),
				)
			)
		);

		$this->assertSame( 'Hallo Welt', $result );
		$this->assertSame(
			array(
				array(
					'role'    => 'system',
					'content' => 'Prompt',
				),
				array(
					'role'    => 'user',
					'content' => 'Hello world',
				),
			),
			$captured_body['messages'] ?? null
		);
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
				if ( 'ai_translate_force_direct_api' === $option ) {
					return '1';
				}

				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
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
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
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
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
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

	public function test_direct_api_returns_structured_model_limit_error_on_retryable_500_body(): void {
		$this->stubWpFunctionReturn( 'wp_remote_post', array(
			'response' => array( 'code' => 500 ),
			'body'     => 'Internal Server Error (500) - model limit reached, try again later',
		) );

		$result = $this->invokeStatic(
			DirectApiTranslationClient::class,
			'translate',
			array(
				'Hello world',
				'Prompt',
				true,
				'gemma-3-4b-it',
				'http://llama.local:8080',
				0,
				0,
				array()
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'direct_api_model_limit_reached', $result->get_error_code() );
		$this->assertStringContainsString( 'model limit (500)', $result->get_error_message() );
	}

	public function test_direct_api_completions_endpoint_returns_choice_text(): void {
		$captured_url  = '';
		$captured_body = array();

		$this->stubWpFunction(
			'wp_remote_post',
			static function ( $url, $args ) use ( &$captured_url, &$captured_body ) {
				$captured_url  = (string) $url;
				$captured_body = json_decode( (string) ( $args['body'] ?? '' ), true );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array(
						'choices' => array(
							array( 'text' => 'Hallo Welt' ),
						),
					) ),
				);
			}
		);

		$result = $this->invokeStatic(
			DirectApiTranslationClient::class,
			'translate',
			array(
				'Hello world',
				'Prompt',
				true,
				'madlad400-10b-mt.Q4_K_M',
				'http://llama.local:8080',
				0,
				0,
				array(),
				'completions'
			)
		);

		$this->assertSame( 'Hallo Welt', $result );
		$this->assertStringEndsWith( '/v1/completions', $captured_url );
		$this->assertIsArray( $captured_body );
		$this->assertArrayHasKey( 'prompt', $captured_body );
		$this->assertArrayNotHasKey( 'messages', $captured_body );
	}

	public function test_direct_api_returns_structured_transient_upstream_error_on_failed_to_load_body(): void {
		$this->stubWpFunctionReturn( 'wp_remote_post', array(
			'response' => array( 'code' => 500 ),
			'body'     => '{"error":{"code":500,"message":"model_name=madlad400-10b-mt.Q4_K_M_failed_to_load","type":"server_error"}}',
		) );

		$result = $this->invokeStatic(
			DirectApiTranslationClient::class,
			'translate',
			array(
				'Hello world',
				'Prompt',
				true,
				'madlad400-10b-mt.Q4_K_M',
				'http://llama.local:8080',
				0,
				0,
				array()
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'direct_api_transient_upstream_error', $result->get_error_code() );
		$this->assertStringContainsString( 'transient upstream error (500)', $result->get_error_message() );
	}

	public function test_madlad_transient_direct_api_error_is_tracked_as_retryable_failure(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'madlad400-10b-mt.Q4_K_M',
			'direct_api_url' => 'http://llama.local:8080',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );
		$this->setStaticProperty( TranslationRuntime::class, 'rate_limit_retry_depth', 3 );
		$this->stubWpFunctionReturn( 'wp_remote_post', array(
			'response' => array( 'code' => 500 ),
			'body'     => 'proxy_error: Failed_to_read_connection',
		) );

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'direct_api_transient_upstream_error', $result->get_error_code() );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'direct_api_failed', $diagnostics['transport'] );
		$this->assertSame( 'direct_api_transient_upstream_error', $diagnostics['failure_reason'] );
		$this->assertSame( 'direct_api_transient_upstream_error', $diagnostics['error_code'] );
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
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}
				if ( 'ai_translate_force_direct_api' === $option ) {
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

	public function test_non_translategemma_force_direct_api_keeps_validation_retry_on_wp_ai_client(): void {
		$direct_api_calls = 0;
		$attempt_calls    = array();
		$call_count       = 0;

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'gemma-3-4b-it',
			'direct_api_url' => 'http://llama.local:8080',
		) );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}
				if ( 'ai_translate_force_direct_api' === $option ) {
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
			static function ( string $text ) use ( &$call_count, &$attempt_calls ) {
				++$call_count;
				$attempt_calls[ $call_count ] = array(
					'user'   => $text,
					'system' => '',
				);

				return new class( $call_count, $attempt_calls ) {
					private int $index;
					private array $attempt_calls;

					public function __construct( int $index, array &$attempt_calls ) {
						$this->index         = $index;
						$this->attempt_calls =& $attempt_calls;
					}

					public function using_system_instruction( string $prompt ) {
						$this->attempt_calls[ $this->index ]['system'] = $prompt;
						return $this;
					}

					public function using_temperature( float $temperature ) {
						return $this;
					}

					public function using_model_preference( string $model_slug ) {
						return $this;
					}

					public function using_max_tokens( int $max_tokens ) {
						return $this;
					}

					public function generate_text(): string {
						return 1 === $this->index
							? "Okay, let's break this down.\n\n**Strengths:**\n* Clear structure"
							: 'Hallo Welt';
					}
				};
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertSame( 'Hallo Welt', $result );
		$this->assertSame( 0, $direct_api_calls );
		$this->assertCount( 2, $attempt_calls );
		$this->assertSame( 'Prompt', $attempt_calls[1]['system'] );
		$this->assertStringContainsString( 'CRITICAL: Return only the translated content.', $attempt_calls[2]['system'] );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'wp_ai_client', $diagnostics['transport'] );
		$this->assertSame( '', $diagnostics['failure_reason'] );
	}

	public function test_empty_wp_ai_client_output_recovers_once_via_direct_api_when_available(): void {
		$captured_direct_body = array();

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'gemma-4-E4B-it-UD-Q8_K_XL',
			'direct_api_url' => 'http://llama.local:8080',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '1';
				}
				if ( 'ai_translate_force_direct_api' === $option ) {
					return '1';
				}

				return $default;
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function () {
				return new class {
					public function using_system_instruction( string $prompt ) { return $this; }
					public function using_temperature( float $temperature ) { return $this; }
					public function using_model_preference( string $model_slug ) { return $this; }
					public function using_max_tokens( int $max_tokens ) { return $this; }
					public function generate_text(): string { return ''; }
				};
			}
		);
		$this->stubWpFunction( 'wp_remote_post',
			static function ( string $endpoint, array $args ) use ( &$captured_direct_body ) {
				$decoded_body         = json_decode( $args['body'] ?? '', true );
				$captured_direct_body = is_array( $decoded_body ) ? $decoded_body : array();

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'content' => '<slytranslate-output>Hallo Welt</slytranslate-output>',
									),
								),
							),
						)
					),
				);
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertSame( 'Hallo Welt', $result );
		$this->assertSame(
			array(
				'enable_thinking' => false,
			),
			$captured_direct_body['chat_template_kwargs'] ?? null
		);

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'direct_api', $diagnostics['transport'] );
	}

	public function test_translategemma_invalid_direct_api_output_fails_closed(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'translategemma-4b-it.Q4_K_M',
			'direct_api_url' => 'http://llama.local:8080',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '1';
				}

				return $default;
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function () {
				throw new \RuntimeException( 'wp_ai_client_prompt must not be reached after invalid TranslateGemma direct API output.' );
			}
		);
		$this->stubWpFunction( 'wp_remote_post',
			static function (): array {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'content' => "Okay, let's break this down.\n\n**Strengths:**\n* Clear structure",
									),
								),
							),
						)
					),
				);
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'direct_api', $diagnostics['transport'] );
		$this->assertSame( 'invalid_translation_assistant_reply', $diagnostics['failure_reason'] );
	}

	private function mockSuccessfulDirectApiResponse( array &$captured_body, string $translated_text = 'Hallo Welt' ): void {
		$this->stubWpFunction( 'wp_remote_post',
			static function ( string $endpoint, array $args ) use ( &$captured_body, $translated_text ) {
				$decoded_body  = json_decode( $args['body'] ?? '', true );
				$captured_body = is_array( $decoded_body ) ? $decoded_body : array();

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'content' => $translated_text,
									),
								),
							),
						)
					),
				);
			}
		);
	}
}
