<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\TranslationRuntime;
use AI_Translate\TranslationValidator;

class TranslationOutputValidationTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'context', null );
		$this->setStaticProperty( TranslationRuntime::class, 'last_diagnostics', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', null );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );

		parent::tearDown();
	}

	public function test_rejects_assistant_style_markdown_reply(): void {
		$source_text = 'WordPress AI - MCP setup and auto translation';
		$translated  = "Okay, let's break this down.\n\n**Strengths:**\n* Clear structure\n* Good examples\n\n**Suggestions for improvement:**\n* Add more detail";

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_rejects_prompt_instruction_leakage_from_bilingual_frame_outputs(): void {
		$source_text = 'Translate this short sentence into German.';
		$translated  = 'CRITICAL OUTPUT FORMAT: Return only the translation enclosed in <slytranslate-output> and </slytranslate-output>.';

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated, 'de' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_rejects_bare_retry_directive_leakage_when_source_has_no_directive(): void {
		$source_text = 'TEST DE Uebersetzung';
		$translated  = 'RETURN ONLY EN. DO NOT COPY SENTENCES IN DE.';

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated, 'en' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_rejects_localized_critical_rule_leakage_when_source_has_no_critical_label(): void {
		$source_text = 'The heading should be translated naturally.';
		$translated  = '<h2>CRITICAL: Wenden Sie alle obigen Uebersetzungsregeln genau an.</h2>';

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated, 'de' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_rejects_malformed_critical_banner_when_source_has_no_critical_term(): void {
		$source_text = 'The sentence should remain a normal screenshot placeholder line.';
		$translated  = '<p><strong><strong>CRITICAL</strong>:</strong>:</strong>:</strong></p>';

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated, 'de' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_rejects_leaked_bilingual_labels_when_source_has_none(): void {
		$source_text = 'A normal heading without bilingual labels.';
		$translated  = "Variant A: Something\n\nDE:";

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated, 'de' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_rejects_html_wrapped_bilingual_labels_when_source_has_none(): void {
		$source_text = 'Simple heading without bilingual role markers.';
		$translated  = '<h3>DE: Ein Titel</h3>';

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated, 'de' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_rejects_system_prompt_echo_starting_with_we_need_to_translate(): void {
		// Nemotron Free on OpenRouter echoes the custom system prompt verbatim.
		// attempt=0 is caught as assistant_reply; attempt=1 (retry) uses the base
		// prompt without the CRITICAL extension, so the output doesn't contain
		// "CRITICAL" and previously slipped through as runaway_output.
		// The new prompt-opener detection must catch both cases as assistant_reply.
		$source = 'Ich entwickle Features, schreibe Commits und erstelle Pull Requests. Danach sollte ein Blogpost erstellt werden, aber das passiert meistens nicht.';
		$translated = 'We need to translate from German to English, preserving HTML, formatting, embedded media, source symbols. The input is plain text, no HTML. Use informal "Du" instead of "Sie". Use young language but professional. So translate accordingly. Original: "' . $source . '" I develop features, write commits and create pull requests.';

		$result = TranslationValidator::validate( $source, $translated, 'en' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_rejects_system_prompt_echo_starting_with_translate_content_from(): void {
		// Default prompt template echo: starts with "Translate the content from de to en...".
		$source     = 'Hallo Welt! Dies ist ein Test.';
		$translated = 'Translate the content from de to en preserving html, formatting, embedded media, and source symbols. Do not rewrite Unicode symbols or math notation as LaTeX or ASCII. Only return the new content. Hello World! This is a test.';

		$result = TranslationValidator::validate( $source, $translated, 'en' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_rejects_output_containing_sly_translate_internal_phrase(): void {
		// If "additional style instructions (do not translate these lines" appears in
		// the output but not in the source, it's a prompt echo.
		$source     = 'Ein einfacher Satz.';
		$translated = 'Additional style instructions (do not translate these lines, apply them to the user-provided content): use informal du. A simple sentence.';

		$result = TranslationValidator::validate( $source, $translated, 'en' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_assistant_reply', $result->get_error_code() );
	}

	public function test_allows_translate_verb_mid_sentence_in_legitimate_translation(): void {
		// A legitimate translation that contains the word "translate" mid-sentence
		// must not be flagged. The prompt-opener check is anchored to the START.
		$source     = 'Bitte übersetze diesen Text.';
		$translated = 'Please translate this text.';

		$result = TranslationValidator::validate( $source, $translated, 'en' );

		$this->assertNull( $result );
	}

	public function test_rejects_low_information_stopword_only_output_for_multiword_source(): void {
		$source_text = 'TEST EN Translation';
		$translated  = 'und';

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated, 'de' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_low_information', $result->get_error_code() );
	}

	public function test_allows_single_word_source_translated_to_stopword(): void {
		$source_text = 'and';
		$translated  = 'und';

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated, 'de' ) );

		$this->assertNull( $result );
	}

	public function test_rejects_obvious_english_passthrough_when_target_is_german(): void {
		$source_text = 'This is the source text in English and it should definitely be translated into German for the final output.';
		$translated  = 'This is the source text in English and it should definitely be translated into German for the final output.';

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated, 'de' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_language_passthrough', $result->get_error_code() );
	}

	public function test_retry_prompt_keeps_user_additional_instruction_text(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );
		$user_prompt = 'Anreden mit "du" statt "Sie". junger aber professioneller ton.';

		$retry_prompt = $this->invokeStatic(
			TranslationRuntime::class,
			'build_retry_prompt',
			array(
				$user_prompt,
				'Ministral-3-3B-Instruct-2512-Q4_K_M',
				'invalid_translation_language_passthrough'
			)
		);

		$this->assertStringContainsString( $user_prompt, $retry_prompt );
		$this->assertStringContainsString( 'CRITICAL OUTPUT FORMAT: Return only the translation enclosed in <slytranslate-output> and </slytranslate-output>.', $retry_prompt );
		$this->assertStringContainsString( 'CRITICAL: Keep obeying the user-provided translation rules above.', $retry_prompt );
	}

	public function test_extracts_wrapper_payload_from_bilingual_model_output(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$source_text = 'Please open your dashboard and continue.';
		$model_output = "Sure, here is the translation.\n\n<slytranslate-output>Bitte oeffne dein Dashboard und fahre mit dem naechsten Schritt fort.</slytranslate-output>\n\nDone.";

		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'finalize_translated_chunk',
			array( $source_text, $model_output, 'Qwen3.5-9B-Instruct-2507-Q4_K_M', 'Prompt', 0 )
		);

		$this->assertSame( 'Bitte oeffne dein Dashboard und fahre mit dem naechsten Schritt fort.', $result );
	}

	public function test_extracts_trailing_target_label_block_from_bilingual_model_output(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$source_text = 'Please open your dashboard and continue.';
		$model_output = "Reasoning: keep tone young but professional.\n\nDE:\nBitte oeffne dein Dashboard und fahre mit dem naechsten Schritt fort.";

		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'finalize_translated_chunk',
			array( $source_text, $model_output, 'Ministral-8B-Instruct-2410-Q4_K_M', 'Prompt', 0 )
		);

		$this->assertSame( 'Bitte oeffne dein Dashboard und fahre mit dem naechsten Schritt fort.', $result );
	}

	public function test_normalizes_leading_german_label_leakage_for_de_target(): void {
		$source_text = 'This sentence should be translated to German without any role labels in the output.';
		$translated  = "German:\nDies ist eine saubere deutsche Uebersetzung.";

		$result = TranslationValidator::validate( $source_text, $translated, 'de' );

		$this->assertNull( $result );
	}

	public function test_rejects_gutenberg_structure_drift(): void {
		$source_text = "<!-- wp:paragraph -->\n<p>See https://example.com for details.</p>\n<!-- /wp:paragraph -->";
		$translated  = '<p>Weitere Informationen folgen bald.</p>';

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_structure_drift', $result->get_error_code() );
	}

	public function test_allows_preserved_gutenberg_structure(): void {
		$source_text = "<!-- wp:paragraph -->\n<p>See https://example.com for details.</p>\n<!-- /wp:paragraph -->";
		$translated  = "<!-- wp:paragraph -->\n<p>Siehe https://example.com für Details.</p>\n<!-- /wp:paragraph -->";

		$result = $this->invokeStatic( TranslationValidator::class, 'validate', array( $source_text, $translated ) );

		$this->assertNull( $result );
	}

	public function test_rejects_gutenberg_comment_direction_drift_with_same_comment_count(): void {
		$source_text = "<!-- wp:list -->\n<ul>\n<!-- wp:list-item -->\n<li>Select model</li>\n<!-- /wp:list-item -->\n</ul>\n<!-- /wp:list -->";
		$translated  = "<!-- wp:list -->\n<ul>\n<!-- wp:list-item -->\n<li>Modell waehlen</li>\n<!-- wp:list-item /-->\n</ul>\n<!-- /wp:list -->";

		$result = TranslationValidator::validate( $source_text, $translated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_structure_drift', $result->get_error_code() );
	}

	public function test_allows_matching_self_closing_block_comment_sequence(): void {
		$source_text = '<!-- wp:image {"id":527} /-->';
		$translated  = '<!-- wp:image {"id":527} /-->';

		$result = TranslationValidator::validate( $source_text, $translated );

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

			$this->stubWpFunction( 'get_option',
				static function ( $option, $default = false ) {
					if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
						return '0';
					}
					return $default;
				}
			);

			$this->stubWpFunction( 'wp_ai_client_prompt',
				static function ( string $text ) use ( $model_output ) {
					return new class( $model_output ) {
						private string $reply;

						public function __construct( string $reply ) {
							$this->reply = $reply;
						}

						public function using_system_instruction( string $prompt ) { return $this; }
						public function using_temperature( float $temperature ) { return $this; }
						public function using_model_preference( string $model_slug ) { return $this; }
						public function generate_text(): string { return $this->reply; }
					};
				}
			);

			$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( $source, 'Translate this.' ) );

			$this->assertSame( $expected, $result, 'Pseudo-tag output should be unwrapped: ' . $model_output );
		}
	}

	public function test_finalize_translated_chunk_normalizes_latex_arrow_sequences_for_unicode_arrow_sources(): void {
		$source_text = 'Die Schleife „Code → Dokumentation → Mehrsprachigkeit“ bricht genau dort ab.';
		$translated  = 'The loop "Code $\rightarrow$ Documentation $\rightarrow$ Multilingualism" breaks right there.';

		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'finalize_translated_chunk',
			array( $source_text, $translated, 'gpt-4o', 'Translate this.', 0 )
		);

		$this->assertSame(
			'The loop "Code → Documentation → Multilingualism" breaks right there.',
			$result
		);
	}

	public function test_validator_rejects_latex_arrow_drift_when_source_uses_unicode_arrow(): void {
		$source_text = 'Die Schleife „Code → Dokumentation → Mehrsprachigkeit“ bricht genau dort ab.';
		$translated  = 'The loop "Code $\rightarrow$ Documentation $\rightarrow$ Multilingualism" breaks right there.';

		$result = TranslationValidator::validate( $source_text, $translated );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_symbol_drift', $result->get_error_code() );
	}

	public function test_translate_chunk_retries_once_for_standard_models_after_invalid_output(): void {
		$call_count    = 0;
		$user_payloads = array();

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'gpt-4o',
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
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) use ( &$call_count, &$user_payloads ) {
				return new class( $text, $call_count, $user_payloads ) {
					private string $text;
					private int $call_count;
					private array $user_payloads;

					public function __construct( string $text, int &$call_count, array &$user_payloads ) {
						$this->text       = $text;
						$this->call_count = &$call_count;
						$this->user_payloads = &$user_payloads;
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
						++$this->call_count;
						$this->user_payloads[] = $this->text;

						if ( 1 === $this->call_count ) {
							return "Okay, let's break this down.\n\n**Strengths:**\n* Clear structure";
						}

						return 'Ein sauberer Titel';
					}
				};
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( 'Clean title', 'Translate this.' ) );

		$this->assertSame( 'Ein sauberer Titel', $result );
		$this->assertCount( 2, $user_payloads );
		$this->assertStringContainsString( 'CRITICAL: Return only the translated content.', $user_payloads[1] );
	}

	public function test_translate_chunk_uses_plain_prompt_recovery_after_repeated_empty_output(): void {
		$call_count = 0;
		$inputs     = array();

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'gemma-4-E4B-it-UD-Q8_K_XL',
			'direct_api_url' => '',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) use ( &$call_count, &$inputs ) {
				return new class( $text, $call_count, $inputs ) {
					private string $text;
					private int $call_count;
					private array $inputs;

					public function __construct( string $text, int &$call_count, array &$inputs ) {
						$this->text       = $text;
						$this->call_count = &$call_count;
						$this->inputs     = &$inputs;
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

					public function using_max_tokens( int $max_tokens ) {
						return $this;
					}

					public function generate_text(): string {
						$this->call_count++;
						$this->inputs[] = $this->text;

						if ( $this->call_count < 3 ) {
							return '';
						}

						return 'Testbeitrag zur Uebersetzung';
					}
				};
			}
		);

		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'translate_chunk',
			array( 'test Post for translation', 'Translate this.' )
		);

		$this->assertSame( 'Testbeitrag zur Uebersetzung', $result );
		$this->assertCount( 3, $inputs );
		$this->assertStringContainsString( 'Translate the following text from EN to DE.', $inputs[2] );
	}

	public function test_translate_chunk_uses_plain_prompt_recovery_after_repeated_assistant_reply(): void {
		// Models like Nemotron Free on OpenRouter echo the system prompt verbatim on
		// every attempt. Both attempt=0 and attempt=1 fail as invalid_translation_assistant_reply.
		// A third attempt using the plain-prompt (user-only, no system message) format
		// must be triggered automatically as a last-resort recovery.
		$call_count = 0;
		$inputs     = array();

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'nvidia/nemotron-3-nano-30b-a3b',
			'direct_api_url' => '',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'de' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'en' );

		$source_text = 'Die Konfiguration ist abgeschlossen. WordPress, SlyTranslate, Polylang, MCP. Übersetzungen funktionieren mit einem Klick.';

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}
				return $default;
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) use ( &$call_count, &$inputs, $source_text ) {
				return new class( $text, $call_count, $inputs, $source_text ) {
					private string $text;
					private int $call_count;
					private array $inputs;
					private string $source_text;

					public function __construct( string $text, int &$call_count, array &$inputs, string $source_text ) {
						$this->text       = $text;
						$this->call_count = &$call_count;
						$this->inputs     = &$inputs;
						$this->source_text = $source_text;
					}

					public function using_system_instruction( string $prompt ) { return $this; }
					public function using_temperature( float $temperature ) { return $this; }
					public function using_model_preference( string $model_slug ) { return $this; }
					public function using_max_tokens( int $max_tokens ) { return $this; }

					public function generate_text(): string {
						$this->call_count++;
						$this->inputs[] = $this->text;

						if ( $this->call_count < 3 ) {
							// Echo the system prompt — Nemotron-style failure
							return 'We need to translate German to English, preserving HTML tags. Input: "' . $this->source_text . '". Translation: The configuration is complete.';
						}

						// Third attempt (plain prompt, user-only) returns clean translation
						return 'The configuration is complete. WordPress, SlyTranslate, Polylang, MCP. Translations work with a single click.';
					}
				};
			}
		);

		$result = $this->invokeStatic(
			TranslationRuntime::class,
			'translate_chunk',
			array( $source_text, 'Translate this.' )
		);

		$this->assertSame( 'The configuration is complete. WordPress, SlyTranslate, Polylang, MCP. Translations work with a single click.', $result );
		$this->assertCount( 3, $inputs );
		// Third call must use plain-prompt format (source text embedded in user message)
		$this->assertStringContainsString( 'Translate the following text from DE to EN.', $inputs[2] );
		$this->assertStringContainsString( $source_text, $inputs[2] );
	}

	public function test_tower_profile_retries_validation_failure_with_smaller_chunks(): void {
		$call_count  = 0;
		$input_texts = array();

		$source = str_repeat( 'This is an English source sentence with repeated words and structure. ', 45 );

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'TowerInstruct-7B-v0.2.Q4_K_M',
			'direct_api_url' => '',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) use ( &$call_count, &$input_texts ) {
				return new class( $text, $call_count, $input_texts ) {
					private string $text;
					private int $call_count;
					private array $input_texts;

					public function __construct( string $text, int &$call_count, array &$input_texts ) {
						$this->text       = $text;
						$this->call_count = &$call_count;
						$this->input_texts = &$input_texts;
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

					public function using_max_tokens( int $max_tokens ) {
						return $this;
					}

					public function generate_text(): string {
						++$this->call_count;
						$this->input_texts[] = $this->text;

						if ( 1 === $this->call_count ) {
							return "Okay, let's break this down.\n\n**Strengths:**\n* Clear structure";
						}

						return 'Dies ist eine deutsche Uebersetzung.';
					}
				};
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( $source, 'Prompt' ) );

		$this->assertIsString( $result );
		$this->assertGreaterThanOrEqual( 3, $call_count );
		$this->assertStringContainsString( 'CRITICAL: Return only the translated content.', $input_texts[1] ?? '' );
	}

	public function test_ministral_profile_retries_passthrough_failure_with_smaller_chunks(): void {
		$call_count  = 0;
		$input_texts = array();

		$source = str_repeat( 'This is an English source sentence with repeated words and structure. ', 45 );

		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'Ministral-8B-Instruct-2410-Q4_K_M',
			'direct_api_url' => '',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				if ( 'ai_translate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) use ( &$call_count, &$input_texts ) {
				return new class( $text, $call_count, $input_texts ) {
					private string $text;
					private int $call_count;
					private array $input_texts;

					public function __construct( string $text, int &$call_count, array &$input_texts ) {
						$this->text        = $text;
						$this->call_count  = &$call_count;
						$this->input_texts = &$input_texts;
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

					public function using_max_tokens( int $max_tokens ) {
						return $this;
					}

					public function generate_text(): string {
						++$this->call_count;
						$this->input_texts[] = $this->text;

						if ( 1 === $this->call_count ) {
							if ( preg_match( '/EN:\\s*(.*?)\\s*DE:\\s*$/s', $this->text, $matches ) && is_string( $matches[1] ?? null ) ) {
								return trim( $matches[1] );
							}

							return $this->text;
						}

						return 'Dies ist eine deutsche Uebersetzung.';
					}
				};
			}
		);

		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_chunk', array( $source, 'Prompt' ) );

		$this->assertIsString( $result );
		$this->assertGreaterThanOrEqual( 3, $call_count );
		$this->assertStringContainsString( 'CRITICAL: Return only the translated content.', $input_texts[1] ?? '' );
		$this->assertStringContainsString( 'CRITICAL: The final output must be in DE.', $input_texts[1] ?? '' );
	}

	public function test_rejects_single_word_output_for_multi_sentence_source(): void {
		// Nemotron on OpenRouter Free returned "The" (3 chars) as the entire
		// translation of a 200-char multi-sentence German paragraph. The
		// stopword guard only covered German-target, so this slipped through.
		// has_collapsed_output() must catch this regardless of target language.
		$source     = 'Die Konfiguration ist abgeschlossen. WordPress 7, SlyTranslate, Polylang, MCP. Übersetzungen funktionieren mit einem Klick – oder über eine Chat-Anfrage. Aber ich wollte noch einen Schritt weiter gehen.';
		$translated = 'The';

		$result = TranslationValidator::validate( $source, $translated, 'en' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_low_information', $result->get_error_code() );
	}

	public function test_rejects_single_word_output_for_multi_sentence_source_german_target(): void {
		$source     = 'This is a complete English paragraph with several sentences and many words worth translating.';
		$translated = 'Dies';

		$result = TranslationValidator::validate( $source, $translated, 'de' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_low_information', $result->get_error_code() );
	}

	public function test_allows_single_word_output_for_single_word_source(): void {
		// A one-word source legitimately translates to one word.
		$source     = 'Verantwortlicher';
		$translated = 'responsible';

		$result = TranslationValidator::validate( $source, $translated, 'en' );

		$this->assertNull( $result );
	}

	public function test_allows_single_word_output_for_short_two_word_source(): void {
		// A source with fewer than COLLAPSED_OUTPUT_MIN_SOURCE_WORDS words
		// must not trigger the collapsed-output guard.
		$source     = 'Guten Morgen';
		$translated = 'Hi';

		$result = TranslationValidator::validate( $source, $translated, 'en' );

		$this->assertNull( $result );
	}

	public function test_allows_normal_short_translation_for_multi_word_source(): void {
		// Legitimate compact EN translation of a medium-length DE source.
		$source     = 'Die Konfiguration ist abgeschlossen.';
		$translated = 'Setup complete.';

		$result = TranslationValidator::validate( $source, $translated, 'en' );

		$this->assertNull( $result );
	}
}
