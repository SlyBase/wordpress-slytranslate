<?php

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\TranslationRuntime;

/**
 * Guardrails for the unified WordPress AI Client transport path. After
 * the v1.6.0-beta.34 unification, ALL chat-capable models — including
 * TranslateGemma — go through the WP AI Client connector. The plugin no
 * longer issues direct API calls. The only legitimate hard block is for
 * non-chat models (e.g. madlad400) which the plugin must reject before
 * touching the connector.
 */
class TranslationConnectorGuardrailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setStaticProperty( TranslationRuntime::class, 'context', null );
		$this->setStaticProperty( TranslationRuntime::class, 'last_diagnostics', null );
		$this->setStaticProperty( TranslationRuntime::class, 'rate_limit_retry_depth', 3 );
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', null );
		$this->setStaticProperty( TranslationRuntime::class, 'wp_ai_client_kwargs_support_cache', array() );
	}

	public function test_madlad_profile_is_blocked_before_chat_transport_is_called(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug' => '',
			'model_slug'   => 'madlad400-10b-mt.Q4_K_M',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );
		$this->stubWpFunctionReturn( 'get_option', false );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () {
				throw new \RuntimeException( 'wp_ai_client_prompt must not be reached for non-chat models.' );
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world.', 'Prompt' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'model_chat_transport_unsupported', $result->get_error_code() );
	}

	public function test_translategemma_routes_via_wp_ai_client_and_injects_chat_template_kwargs(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug' => '',
			'model_slug'   => 'translategemma-4b-it.Q4_K_M',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$this->stubWpFunctionReturn( 'get_option', false );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () {
				return new class {
					public function using_temperature( $temperature ) { return $this; }
					public function using_model_preference( $model_slug ) { return $this; }
					public function using_max_tokens( $max_tokens ) { return $this; }
					public function generate_text() { return 'Hallo Welt'; }
				};
			}
		);

		$injections = TranslationRuntime::extract_wp_ai_client_request_body_injections(
			TranslationRuntime::get_model_profile( 'translategemma-4b-it.Q4_K_M' )
		);

		$this->assertArrayHasKey( 'chat_template_kwargs', $injections );
		$this->assertSame( 'en', $injections['chat_template_kwargs']['source_lang_code'] );
		$this->assertSame( 'de', $injections['chat_template_kwargs']['target_lang_code'] );

		$mutated = TranslationRuntime::inject_extra_request_body_into_http_args(
			array( 'method' => 'POST', 'body' => wp_json_encode( array( 'model' => 'translategemma-4b-it.Q4_K_M', 'messages' => array() ) ) ),
			'http://192.168.178.42:8080/v1/chat/completions',
			$injections
		);

		$decoded = json_decode( $mutated['body'], true );
		$this->assertSame( 'en', $decoded['chat_template_kwargs']['source_lang_code'] );
		$this->assertSame( 'de', $decoded['chat_template_kwargs']['target_lang_code'] );

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );
		$this->assertSame( 'Hallo Welt', $result );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'wp_ai_client', $diagnostics['transport'] );
	}

	public function test_translate_chunk_normalizes_nested_openrouter_rate_limit_payload_from_connector_error(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug' => '',
			'model_slug'   => 'google/gemma-4-31b-it:free',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'de' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'rate_limit_retry_depth', 3 );

		$this->stubWpFunctionReturn( 'get_option', false );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () {
				return new class {
					public function using_temperature( $temperature ) { return $this; }
					public function using_model_preference( $model_slug ) { return $this; }
					public function using_max_tokens( $max_tokens ) { return $this; }
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
												'raw' => 'google/gemma-4-31b-it:free is temporarily rate-limited upstream.',
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

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'translation_provider_rate_limited', $diagnostics['error_code'] );
	}

	public function test_qwen_retries_without_chat_template_kwargs_when_connector_rejects_them(): void {
		$call_count = 0;

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug' => '',
			'model_slug'   => 'Qwen3.5-9B-Q4_K_M',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$injections = TranslationRuntime::extract_wp_ai_client_request_body_injections(
			TranslationRuntime::get_model_profile( 'Qwen3.5-9B-Q4_K_M' )
		);
		$this->assertArrayHasKey( 'chat_template_kwargs', $injections );

		$this->stubWpFunctionReturn( 'get_option', false );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () use ( &$call_count ) {
				return new class( $call_count ) {
					private int $call_count;

					public function __construct( int &$call_count ) {
						$this->call_count =& $call_count;
					}

					public function using_temperature( $temperature ) { return $this; }
					public function using_model_preference( $model_slug ) { return $this; }
					public function using_max_tokens( $max_tokens ) { return $this; }

					public function generate_text() {
						++$this->call_count;

						if ( 1 === $this->call_count ) {
							return new \WP_Error( 'prompt_bad_request', 'chat_template_kwargs is not allowed by this endpoint' );
						}

						return 'Hallo Welt';
					}
				};
			}
		);

		$first_result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Hello world', 'Prompt' ) );
		$this->assertSame( 'Hallo Welt', $first_result );

		$second_result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Good morning', 'Prompt' ) );
		$this->assertSame( 'Hallo Welt', $second_result );

		$this->assertSame( 3, $call_count );

		$diagnostics = $this->getStaticProperty( TranslationRuntime::class, 'last_diagnostics' );
		$this->assertSame( 'wp_ai_client', $diagnostics['transport'] );
		$this->assertSame( '', $diagnostics['failure_reason'] );
	}
}
