<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\TranslationRuntime;

/**
 * Tests for the WP AI Client chat_template_kwargs injection helper.
 *
 * Reasoning-capable models (Qwen3 family, GLM-4.6, etc.) need
 * chat_template_kwargs.enable_thinking=false in the outgoing chat
 * completions request body so the model returns the translated text in
 * `content` instead of consuming the budget on `reasoning_content`.
 *
 * The injection is performed by mutating outgoing wp_remote_post args
 * via an http_request_args filter; here we cover the pure mutator and
 * the profile extraction.
 */
class WpAiClientChatTemplateKwargsInjectionTest extends TestCase {

	public function test_injects_chat_template_kwargs_into_chat_completions_post_body(): void {
		$args = array(
			'method' => 'POST',
			'body'   => wp_json_encode( array(
				'model'    => 'Qwen3.5-9B-Q4_K_M',
				'messages' => array(
					array( 'role' => 'user', 'content' => 'Hello' ),
				),
			) ),
		);

		$result = TranslationRuntime::inject_extra_request_body_into_http_args(
			$args,
			'http://192.168.1.10:8080/v1/chat/completions',
			array( 'chat_template_kwargs' => array( 'enable_thinking' => false ) )
		);

		$decoded = json_decode( (string) $result['body'], true );
		$this->assertIsArray( $decoded );
		$this->assertSame( false, $decoded['chat_template_kwargs']['enable_thinking'] );
	}

	public function test_merges_with_existing_chat_template_kwargs_in_body(): void {
		$args = array(
			'method' => 'POST',
			'body'   => wp_json_encode( array(
				'model'                => 'Qwen3.5-9B-Q4_K_M',
				'messages'             => array( array( 'role' => 'user', 'content' => 'x' ) ),
				'chat_template_kwargs' => array( 'source_lang' => 'en' ),
			) ),
		);

		$result = TranslationRuntime::inject_extra_request_body_into_http_args(
			$args,
			'http://example.test/v1/chat/completions',
			array( 'chat_template_kwargs' => array( 'enable_thinking' => false ) )
		);

		$decoded = json_decode( (string) $result['body'], true );
		$this->assertSame( 'en', $decoded['chat_template_kwargs']['source_lang'] );
		$this->assertSame( false, $decoded['chat_template_kwargs']['enable_thinking'] );
	}

	public function test_does_not_mutate_non_chat_completions_endpoints(): void {
		$body = wp_json_encode( array( 'model' => 'm', 'prompt' => 'p' ) );
		$args = array( 'method' => 'POST', 'body' => $body );

		$result = TranslationRuntime::inject_extra_request_body_into_http_args(
			$args,
			'http://example.test/v1/completions',
			array( 'chat_template_kwargs' => array( 'enable_thinking' => false ) )
		);

		$this->assertSame( $body, $result['body'] );
	}

	public function test_does_not_mutate_non_post_requests(): void {
		$body = wp_json_encode( array( 'messages' => array() ) );
		$args = array( 'method' => 'GET', 'body' => $body );

		$result = TranslationRuntime::inject_extra_request_body_into_http_args(
			$args,
			'http://example.test/v1/chat/completions',
			array( 'chat_template_kwargs' => array( 'enable_thinking' => false ) )
		);

		$this->assertSame( $body, $result['body'] );
	}

	public function test_returns_args_unchanged_when_no_injections(): void {
		$args = array( 'method' => 'POST', 'body' => '{"messages":[]}' );
		$result = TranslationRuntime::inject_extra_request_body_into_http_args(
			$args,
			'http://example.test/v1/chat/completions',
			array()
		);
		$this->assertSame( $args, $result );
	}

	public function test_extracts_chat_template_kwargs_from_qwen_profile(): void {
		$profile = array(
			'extra_request_body' => array(
				'chat_template_kwargs' => array( 'enable_thinking' => false ),
			),
		);

		$injections = TranslationRuntime::extract_wp_ai_client_request_body_injections( $profile );
		$this->assertSame( array( 'chat_template_kwargs' => array( 'enable_thinking' => false ) ), $injections );
	}

	public function test_injects_openrouter_reasoning_and_provider_overrides(): void {
		$args = array(
			'method' => 'POST',
			'body'   => wp_json_encode( array(
				'model'    => 'nvidia/nemotron-3-super-120b-a12b:free',
				'messages' => array(
					array( 'role' => 'user', 'content' => 'Hello' ),
				),
			) ),
		);

		$result = TranslationRuntime::inject_extra_request_body_into_http_args(
			$args,
			'https://openrouter.ai/api/v1/chat/completions',
			array(
				'reasoning' => array(
					'effort'  => 'none',
					'exclude' => true,
				),
				'provider'  => array(
					'require_parameters' => true,
				),
			)
		);

		$decoded = json_decode( (string) $result['body'], true );
		$this->assertSame( 'none', $decoded['reasoning']['effort'] );
		$this->assertSame( true, $decoded['reasoning']['exclude'] );
		$this->assertSame( true, $decoded['provider']['require_parameters'] );
	}

	public function test_extracts_safe_openrouter_keys_from_profile(): void {
		$profile = TranslationRuntime::get_model_profile( 'nvidia/nemotron-3-super-120b-a12b:free' );

		$injections = TranslationRuntime::extract_wp_ai_client_request_body_injections(
			$profile,
			true,
			'nvidia/nemotron-3-super-120b-a12b:free'
		);

		$this->assertSame(
			array(
				'chat_template_kwargs' => array( 'enable_thinking' => false ),
				'reasoning'            => array( 'effort' => 'none', 'exclude' => true ),
				'provider'             => array( 'require_parameters' => true ),
			),
			$injections
		);
	}

	public function test_does_not_add_openrouter_keys_for_generic_nemotron_profile_without_matching_slug(): void {
		$profile = TranslationRuntime::get_model_profile( 'nvidia/nemotron-super-49b-v1' );

		$injections = TranslationRuntime::extract_wp_ai_client_request_body_injections(
			$profile,
			true,
			'nvidia/nemotron-super-49b-v1'
		);

		$this->assertSame(
			array(
				'chat_template_kwargs' => array( 'enable_thinking' => false ),
			),
			$injections
		);
	}

	public function test_extract_returns_empty_when_profile_has_no_kwargs(): void {
		$profile = array( 'extra_request_body' => array() );
		$this->assertSame( array(), TranslationRuntime::extract_wp_ai_client_request_body_injections( $profile ) );
	}
}
