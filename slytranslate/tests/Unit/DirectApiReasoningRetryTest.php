<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\DirectApiTranslationClient;

/**
 * Reasoning-capable models served via llama.cpp (Qwen3 family, etc.) emit
 * their chain-of-thought into a separate `reasoning_content` field. When the
 * `max_tokens` budget is exhausted by reasoning before the final translation
 * is produced, the response carries `content: ""` plus a populated
 * `reasoning_content`. The DirectApi client must detect this and retry once
 * with `chat_template_kwargs.enable_thinking=false` so the next call returns
 * the actual translation.
 */
final class DirectApiReasoningRetryTest extends TestCase {

	public function test_retries_with_thinking_disabled_when_only_reasoning_is_returned(): void {
		$captured_bodies = array();

		$this->stubWpFunction(
			'wp_remote_post',
			function ( $url, $args ) use ( &$captured_bodies ) {
				$body                = json_decode( (string) ( $args['body'] ?? '' ), true );
				$captured_bodies[]   = is_array( $body ) ? $body : array();
				$attempt             = count( $captured_bodies );

				if ( 1 === $attempt ) {
					// First attempt: reasoning ate the whole budget.
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => json_encode( array(
							'choices' => array(
								array(
									'finish_reason' => 'length',
									'message'       => array(
										'role'              => 'assistant',
										'content'           => '',
										'reasoning_content' => "Thinking Process:\n1. Translate Katze ...",
									),
								),
							),
						) ),
					);
				}

				// Second attempt: thinking disabled → real translation.
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array(
						'choices' => array(
							array(
								'finish_reason' => 'stop',
								'message'       => array(
									'role'    => 'assistant',
									'content' => '<p>The cat sits on the windowsill.</p>',
								),
							),
						),
					) ),
				);
			}
		);

		$result = DirectApiTranslationClient::translate(
			'<p>Die Katze sitzt auf der Fensterbank.</p>',
			'Translate the content from de to en.',
			true,
			'Qwen3.5-9B-Q4_K_M',
			'http://example.test:8080',
			0.0,
			200,
			array()
		);

		$this->assertSame( '<p>The cat sits on the windowsill.</p>', $result );
		$this->assertCount( 2, $captured_bodies, 'Expected exactly one retry.' );

		$this->assertArrayNotHasKey(
			'chat_template_kwargs',
			$captured_bodies[0],
			'First request must not preemptively disable thinking.'
		);
		$this->assertSame(
			array( 'enable_thinking' => false ),
			$captured_bodies[1]['chat_template_kwargs'] ?? null,
			'Retry must inject chat_template_kwargs.enable_thinking=false.'
		);
	}

	public function test_retry_preserves_existing_chat_template_kwargs(): void {
		$captured_bodies = array();

		$this->stubWpFunction(
			'wp_remote_post',
			function ( $url, $args ) use ( &$captured_bodies ) {
				$body                = json_decode( (string) ( $args['body'] ?? '' ), true );
				$captured_bodies[]   = is_array( $body ) ? $body : array();
				$attempt             = count( $captured_bodies );

				if ( 1 === $attempt ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => json_encode( array(
							'choices' => array(
								array(
									'message' => array(
										'role'              => 'assistant',
										'content'           => '',
										'reasoning_content' => 'Thinking ...',
									),
								),
							),
						) ),
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array(
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'translated',
								),
							),
						),
					) ),
				);
			}
		);

		$result = DirectApiTranslationClient::translate(
			'source',
			'',
			false,
			'Qwen3.5-9B-Q4_K_M',
			'http://example.test:8080',
			0.0,
			200,
			array(
				'chat_template_kwargs' => array(
					'source_language' => 'German',
					'target_language' => 'English',
				),
			)
		);

		$this->assertSame( 'translated', $result );
		$this->assertSame(
			array(
				'source_language' => 'German',
				'target_language' => 'English',
				'enable_thinking' => false,
			),
			$captured_bodies[1]['chat_template_kwargs'] ?? null
		);
	}

	public function test_returns_null_when_retry_also_emits_only_reasoning(): void {
		$call_count = 0;

		$this->stubWpFunction(
			'wp_remote_post',
			function () use ( &$call_count ) {
				$call_count++;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array(
						'choices' => array(
							array(
								'message' => array(
									'role'              => 'assistant',
									'content'           => '',
									'reasoning_content' => 'Still thinking ...',
								),
							),
						),
					) ),
				);
			}
		);

		$result = DirectApiTranslationClient::translate(
			'source',
			'',
			false,
			'Qwen3.5-9B-Q4_K_M',
			'http://example.test:8080',
			0.0,
			200,
			array()
		);

		$this->assertNull( $result );
		$this->assertSame( 2, $call_count, 'Should have retried exactly once.' );
	}

	public function test_no_retry_when_content_is_returned(): void {
		$call_count = 0;

		$this->stubWpFunction(
			'wp_remote_post',
			function () use ( &$call_count ) {
				$call_count++;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( array(
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'translated content',
								),
							),
						),
					) ),
				);
			}
		);

		$result = DirectApiTranslationClient::translate(
			'source',
			'',
			false,
			'Llama-3.1-8B-Instruct-Q4_K_M',
			'http://example.test:8080',
			0.0,
			200,
			array()
		);

		$this->assertSame( 'translated content', $result );
		$this->assertSame( 1, $call_count );
	}
}
