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

	public function test_allows_visible_url_text_replaced_with_descriptive_anchor_text(): void {
		// Models legitimately replace visible URL text like <a href="…">https://…</a>
		// with descriptive anchor text like <a href="…">Automattic Privacy Policy</a>.
		// The old all-occurrences regex produced a false positive here (source_count=2,
		// translated_count=1). The fix counts only href/src/action attribute URLs.
		$source_text = '<!--SLYWPC0--><p>Details at <a href="https://automattic.com/privacy/">https://automattic.com/privacy/</a>.</p><!--SLYWPC1-->';
		$translated  = '<!--SLYWPC0--><p>Details at <a href="https://automattic.com/privacy/">Automattic Privacy Policy</a>.</p><!--SLYWPC1-->';

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

	public function test_allows_tag_only_source_with_tag_only_translation(): void {
		// Some Gutenberg / HTML fragments contain no plain text at all
		// (anchor wrappers around media, empty link shells, image-only
		// blocks, …). The model legitimately echoes the structural markup
		// back; failing those over "missing plain text" tore down the
		// whole content phase even though there was nothing to translate.
		foreach (
			array(
				array( '<a href="https://example.com/x.jpg"></a>', '<a href="https://example.com/x.jpg"></a>' ),
				array( '<img src="https://example.com/a.jpg" alt="" />', '<img src="https://example.com/a.jpg" alt="" />' ),
				array( '<!--SLYWPC0--><!--SLYWPC1-->', '<!--SLYWPC0--><!--SLYWPC1-->' ),
			) as $pair
		) {
			[ $source, $translated ] = $pair;
			$result                  = TranslationValidator::validate( $source, $translated );
			$this->assertNull(
				$result,
				'Tag-only source/translation pair should pass: ' . $source
			);
		}
	}

	public function test_still_rejects_translation_that_drops_actual_plain_text(): void {
		$source_text = '<p>Hello world</p>';
		$translated  = '<p></p>';

		$result = TranslationValidator::validate( $source_text, $translated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_plain_text_missing', $result->get_error_code() );
	}

	public function test_rejects_short_input_hallucinated_long_numbered_list_explanation(): void {
		// Observed with Phi-4-mini-instruct: a 68-char English source was
		// replied to with a 1170-char German numbered instruction list
		// ("1. Wählen Sie eine API: … 2. Registrieren Sie sich …"). The
		// rawtext contains \n and markdown list markers, but
		// normalize_text_for_validation() collapsed the newlines before
		// the guard inspected them, letting the hallucination through.
		$source     = 'If you want to connect any AI chat API (local or others like groq..)';
		$translated = "Wenn Sie eine AI-Chat-API verbinden möchten, folgen Sie diesen Schritten:\n\n"
			. "1. Wählen Sie eine API: Entscheiden Sie, welche AI-Chat-API Sie verwenden möchten.\n"
			. "2. Registrieren Sie sich für den API-Zugriff: Besuchen Sie die Website des Dienstanbieters und melden Sie sich für einen API-Zugriff an.\n"
			. "3. Lesen Sie die Dokumentation: Machen Sie sich mit der API-Dokumentation des Dienstanbieters vertraut.\n"
			. "4. Installieren Sie die erforderlichen Bibliotheken.\n"
			. "5. Konfigurieren Sie Ihre Anfragen mit API-Schlüsseln.";

		$result = TranslationValidator::validate( $source, $translated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_length_drift', $result->get_error_code() );
	}

	public function test_rejects_short_input_hallucinated_long_prose_without_structure(): void {
		// The hard-ratio ceiling catches hallucinated explanations that
		// have no markdown markers or newlines but are still 6x+ the
		// source length.
		$source     = 'Select model';
		$translated = str_repeat( 'Dies ist eine lange erklärende Antwort über Modelle. ', 30 );

		$result = TranslationValidator::validate( $source, $translated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_length_drift', $result->get_error_code() );
	}

	public function test_rejects_short_input_hallucinated_code_fence_with_fake_model_list(): void {
		// Observed with small instruction-tuned models: a trivial list item
		// "Select model" was "translated" into a markdown HTML code fence
		// with a fake model list. The code-fence markers and injected
		// structure the 12-char source did not have must trip either the
		// short-text length drift or the structural drift guard — either
		// outcome routes the block through the fallback cascade.
		$source     = 'Select model';
		$translated = "5. ```html\nModel 1\nModel 2\nModel 3\nModel 4\nModel 5\nModel 6\nModel 7\n```";

		$result = TranslationValidator::validate( $source, $translated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'invalid_translation_length_drift', 'invalid_translation_structure_drift' )
		);
	}

	public function test_allows_short_plain_translation_proportional_to_source(): void {
		// Legitimate short translations must still pass — e.g. greetings
		// that legitimately expand slightly without any injected structure.
		$source     = 'Hello!';
		$translated = 'Hallo zusammen!';

		$result = TranslationValidator::validate( $source, $translated );

		$this->assertNull( $result );
	}

	public function test_unwraps_pseudo_xml_single_word_translation_from_small_models(): void {
		// Phi-4-mini (and similar small models) occasionally emit single-noun
		// translations wrapped in pseudo-XML tags such as `<responsible>` or
		// `<communication-partner>`. Without unwrapping, wp_strip_all_tags
		// erased the entire output and the validator failed the chunk —
		// tearing down the whole content phase over one short word.
		$cases = array(
			array( 'Verantwortlicher',       '<responsible>',           'responsible' ),
			array( 'Kommunikationspartner.', '<communication-partner>', 'communication partner' ),
		);

		foreach ( $cases as $case ) {
			[ $source, $model_output, $expected ] = $case;

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
				static function ( string $text ) use ( $model_output ) {
					return new class( $model_output ) {
						private string $reply;

						public function __construct( string $reply ) {
							$this->reply = $reply;
						}

						public function using_system_instruction( string $prompt ) { return $this; }
						public function using_temperature( int $temperature ) { return $this; }
						public function using_model_preference( string $model_slug ) { return $this; }
						public function generate_text(): string { return $this->reply; }
					};
				}
			);

			$result = $this->invokeStatic( AI_Translate::class, 'translate_chunk', array( $source, 'Translate this.' ) );

			$this->assertSame( $expected, $result, 'Pseudo-tag output should be unwrapped: ' . $model_output );
		}
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