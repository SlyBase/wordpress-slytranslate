<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\TranslationRuntime;

/**
 * Tests for normalize_reasoning_only_response_body().
 *
 * Thinking/reasoning models (e.g. z-ai/glm-5.1 via OpenRouter) can return
 * a chat-completions response where choices[0].message.content is null or
 * empty while the actual output is in choices[0].message.reasoning or
 * choices[0].message.reasoning_content. The WordPress AI Client cannot parse
 * such responses and throws "No text content found in first candidate."
 *
 * The normalizer promotes the reasoning field to content so the WP AI Client
 * can continue without modification.
 */
class ReasoningResponseNormalizationTest extends TestCase {

	private static function make_response( array $message_override ): string {
		return (string) wp_json_encode( array(
			'id'      => 'chatcmpl-test',
			'object'  => 'chat.completion',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array_merge(
						array(
							'role'    => 'assistant',
							'content' => null,
						),
						$message_override
					),
					'finish_reason' => 'stop',
				),
			),
		) );
	}

	public function test_promotes_reasoning_field_when_content_is_null(): void {
		$body = self::make_response( array(
			'content'   => null,
			'reasoning' => 'Der schnelle braune Fuchs.',
		) );

		$result  = TranslationRuntime::normalize_reasoning_only_response_body( $body );
		$decoded = json_decode( $result, true );

		$this->assertSame( 'Der schnelle braune Fuchs.', $decoded['choices'][0]['message']['content'] );
	}

	public function test_promotes_reasoning_content_field_when_content_is_null(): void {
		$body = self::make_response( array(
			'content'           => null,
			'reasoning_content' => 'Guten Morgen.',
		) );

		$result  = TranslationRuntime::normalize_reasoning_only_response_body( $body );
		$decoded = json_decode( $result, true );

		$this->assertSame( 'Guten Morgen.', $decoded['choices'][0]['message']['content'] );
	}

	public function test_promotes_reasoning_field_when_content_is_empty_string(): void {
		$body = self::make_response( array(
			'content'   => '',
			'reasoning' => 'Hallo Welt.',
		) );

		$result  = TranslationRuntime::normalize_reasoning_only_response_body( $body );
		$decoded = json_decode( $result, true );

		$this->assertSame( 'Hallo Welt.', $decoded['choices'][0]['message']['content'] );
	}

	public function test_prefers_reasoning_over_reasoning_content_when_both_present(): void {
		$body = self::make_response( array(
			'content'           => null,
			'reasoning'         => 'First.',
			'reasoning_content' => 'Second.',
		) );

		$result  = TranslationRuntime::normalize_reasoning_only_response_body( $body );
		$decoded = json_decode( $result, true );

		$this->assertSame( 'First.', $decoded['choices'][0]['message']['content'] );
	}

	public function test_does_not_mutate_when_content_is_non_empty(): void {
		$body = self::make_response( array(
			'content'   => 'Der Fuchs.',
			'reasoning' => 'Some thinking.',
		) );

		$result = TranslationRuntime::normalize_reasoning_only_response_body( $body );

		$this->assertSame( $body, $result );
	}

	public function test_does_not_mutate_when_both_content_and_reasoning_are_absent(): void {
		$body = self::make_response( array( 'content' => null ) );

		$result = TranslationRuntime::normalize_reasoning_only_response_body( $body );

		$this->assertSame( $body, $result );
	}

	public function test_does_not_mutate_when_reasoning_is_empty_string(): void {
		$body = self::make_response( array(
			'content'   => null,
			'reasoning' => '',
		) );

		$result = TranslationRuntime::normalize_reasoning_only_response_body( $body );

		$this->assertSame( $body, $result );
	}

	public function test_does_not_mutate_invalid_json(): void {
		$body = 'not-json';

		$result = TranslationRuntime::normalize_reasoning_only_response_body( $body );

		$this->assertSame( $body, $result );
	}

	public function test_does_not_mutate_empty_body(): void {
		$result = TranslationRuntime::normalize_reasoning_only_response_body( '' );

		$this->assertSame( '', $result );
	}

	public function test_does_not_mutate_when_choices_is_missing(): void {
		$body = (string) wp_json_encode( array( 'id' => 'test', 'object' => 'chat.completion' ) );

		$result = TranslationRuntime::normalize_reasoning_only_response_body( $body );

		$this->assertSame( $body, $result );
	}

	public function test_does_not_mutate_when_choices_is_empty_array(): void {
		$body = (string) wp_json_encode( array(
			'id'      => 'test',
			'choices' => array(),
		) );

		$result = TranslationRuntime::normalize_reasoning_only_response_body( $body );

		$this->assertSame( $body, $result );
	}

	public function test_promotes_reasoning_for_openrouter_style_response(): void {
		$body = (string) wp_json_encode( array(
			'id'      => 'gen-test',
			'model'   => 'z-ai/glm-5.1',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'      => 'assistant',
						'content'   => null,
						'reasoning' => 'Lass mich überlegen... Die Übersetzung ist: Hallo Welt!',
						'refusal'   => null,
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 20,
				'total_tokens'      => 30,
			),
		) );

		$result  = TranslationRuntime::normalize_reasoning_only_response_body( $body );
		$decoded = json_decode( $result, true );

		$this->assertSame(
			'Lass mich überlegen... Die Übersetzung ist: Hallo Welt!',
			$decoded['choices'][0]['message']['content']
		);
		// Other fields remain intact.
		$this->assertSame( 'z-ai/glm-5.1', $decoded['model'] );
		$this->assertSame( 30, $decoded['usage']['total_tokens'] );
	}
}
