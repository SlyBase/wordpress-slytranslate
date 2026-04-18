<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\TranslationRuntime;
use AI_Translate\TranslationValidator;
use Brain\Monkey\Functions;

class TranslationOutputValidationTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', null );
		$this->setStaticProperty( TranslationRuntime::class, 'last_diagnostics', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );

		parent::tearDown();
	}

	public function test_rejects_assistant_style_markdown_reply(): void {
		$source_text = 'WordPress AI - MCP setup and auto translation';
		$translated  = "Okay, let's break this down.\n\n**Strengths:**\n* Clear structure\n* Good examples\n\n**Suggestions for improvement:**\n* Add more detail";

		$result = $this->invokeStatic( AI_Translate::class, 'validate_translated_output', array( $source_text, $translated ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_rejects_gutenberg_structure_drift(): void {
		$source_text = "<!-- wp:paragraph -->\n<p>See https://example.com for details.</p>\n<!-- /wp:paragraph -->";
		$translated  = '<p>Weitere Informationen folgen bald.</p>';

		$result = $this->invokeStatic( AI_Translate::class, 'validate_translated_output', array( $source_text, $translated ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_structure_drift', $result->get_error_code() );
	}

	public function test_allows_preserved_gutenberg_structure(): void {
		$source_text = "<!-- wp:paragraph -->\n<p>See https://example.com for details.</p>\n<!-- /wp:paragraph -->";
		$translated  = "<!-- wp:paragraph -->\n<p>Siehe https://example.com für Details.</p>\n<!-- /wp:paragraph -->";

		$result = $this->invokeStatic( AI_Translate::class, 'validate_translated_output', array( $source_text, $translated ) );

		$this->assertNull( $result );
	}

	public function test_placeholder_content_skips_html_tag_count_check(): void {
		// Stripped content (block comments replaced with SLYWPC placeholders) may lose
		// inline HTML tags like <strong>/<code> via small translation models without it
		// being a real structural failure – the block structure is verified externally
		// via placeholder restoration.
		$source_text = '<!--SLYWPC0--><p>Some text with <strong>bold</strong> and <code>code</code>.</p><!--SLYWPC1-->';
		$translated  = '<!--SLYWPC0--><p>Etwas Text mit fett und Code.</p><!--SLYWPC1-->';

		$result = TranslationValidator::validate( $source_text, $translated );

		$this->assertNull( $result );
	}

	public function test_placeholder_content_still_checks_url_integrity(): void {
		$source_text = '<!--SLYWPC0--><p>Visit <a href="https://example.com">here</a>.</p><!--SLYWPC1-->';
		$translated  = '<!--SLYWPC0--><p>Besuchen Sie hier.</p><!--SLYWPC1-->';

		$result = TranslationValidator::validate( $source_text, $translated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_structure_drift', $result->get_error_code() );
	}

	public function test_extracted_translation_validator_is_callable_directly(): void {
		$source_text = 'WordPress AI - MCP setup and auto translation';
		$translated  = "Okay, let's break this down.\n\n**Strengths:**\n* Clear structure";

		$result = TranslationValidator::validate( $source_text, $translated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_translate_chunk_retries_once_for_standard_models_after_invalid_output(): void {
		$call_count = 0;
		$prompts    = array();

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'gpt-4o',
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
		Functions\when( 'wp_ai_client_prompt' )->alias(
			static function ( string $text ) use ( &$call_count, &$prompts ) {
				return new class( $call_count, $prompts ) {
					private int $call_count;
					private array $prompts;

					public function __construct( int &$call_count, array &$prompts ) {
						$this->call_count = &$call_count;
						$this->prompts    = &$prompts;
					}

					public function using_system_instruction( string $prompt ) {
						$this->prompts[] = $prompt;
						return $this;
					}

					public function using_temperature( int $temperature ) {
						return $this;
					}

					public function using_model_preference( string $model_slug ) {
						return $this;
					}

					public function generate_text(): string {
						++$this->call_count;

						if ( 1 === $this->call_count ) {
							return "Okay, let's break this down.\n\n**Strengths:**\n* Clear structure";
						}

						return 'Ein sauberer Titel';
					}
				};
			}
		);

		$result = $this->invokeStatic( AI_Translate::class, 'translate_chunk', array( 'Clean title', 'Translate this.' ) );

		$this->assertSame( 'Ein sauberer Titel', $result );
		$this->assertCount( 2, $prompts );
		$this->assertStringContainsString( 'CRITICAL: Return only the translated content.', $prompts[1] );
	}
}