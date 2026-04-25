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

	public function test_tower_profile_direct_request_is_user_only_bilingual_frame(): void {
		$captured_body = array();

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
			static function ( string $endpoint, array $args ) use ( &$captured_body ) {
				$decoded_body  = json_decode( $args['body'] ?? '', true );
				$captured_body = is_array( $decoded_body ) ? $decoded_body : array();

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'content' => 'Hallo Welt',
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
		$this->assertCount( 1, $captured_body['messages'] ?? array() );
		$this->assertSame( 'user', $captured_body['messages'][0]['role'] ?? null );
		$this->assertStringContainsString( 'Translate the following text from EN into DE.', $captured_body['messages'][0]['content'] ?? '' );
		$this->assertStringContainsString( 'EN:', $captured_body['messages'][0]['content'] ?? '' );
		$this->assertStringContainsString( 'DE:', $captured_body['messages'][0]['content'] ?? '' );
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
					public function using_temperature( int $temperature ) { return $this; }
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
					public function using_temperature( int $temperature ) { return $this; }
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

	public function test_non_translategemma_model_limit_error_retries_without_wp_ai_client_fallback(): void {
		$calls      = 0;
		$wp_fallback_calls = 0;

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
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function () use ( &$wp_fallback_calls ) {
				++$wp_fallback_calls;
				throw new \RuntimeException( 'wp_ai_client_prompt must not be reached when direct API model-limit retry succeeds.' );
			}
		);
		$this->stubWpFunction( 'wp_remote_post',
			static function () use ( &$calls ) {
				++$calls;
				if ( 1 === $calls ) {
					return array(
						'response' => array( 'code' => 500 ),
						'body'     => 'Internal Server Error (500) - model limit reached, try again later',
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'content' => 'Hallo Welt',
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
		$this->assertSame( 2, $calls );
		$this->assertSame( 0, $wp_fallback_calls );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'direct_api', $diagnostics['transport'] );
		$this->assertSame( '', $diagnostics['failure_reason'] );
	}

	public function test_non_translategemma_direct_api_response_is_validated_and_retried(): void {
		$calls      = array();
		$call_count = 0;

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
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function () {
				throw new \RuntimeException( 'wp_ai_client_prompt must not be reached when direct API retries succeed.' );
			}
		);
		$this->stubWpFunction( 'wp_remote_post',
			static function ( string $endpoint, array $args ) use ( &$call_count, &$calls ) {
				++$call_count;
				$decoded_body = json_decode( $args['body'] ?? '', true );
				$calls[]      = is_array( $decoded_body ) ? $decoded_body : array();

				$content = 1 === $call_count
					? "Okay, let's break this down.\n\n**Strengths:**\n* Clear structure"
					: 'Hallo Welt';

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'content' => $content,
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
		$this->assertCount( 2, $calls );
		$this->assertSame( 'Prompt', $calls[0]['messages'][0]['content'] ?? null );
		$this->assertStringContainsString( 'CRITICAL: Return only the translated content.', $calls[1]['messages'][0]['content'] ?? '' );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'direct_api', $diagnostics['transport'] );
		$this->assertSame( '', $diagnostics['failure_reason'] );
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
