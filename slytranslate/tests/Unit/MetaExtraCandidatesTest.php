<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\MetaTranslationService;
use AI_Translate\TranslationRuntime;

/**
 * Tests for the extra_candidates parameter in MetaTranslationService.
 *
 * Verifies that post title and excerpt pseudo-keys can be folded into the
 * meta batch, reducing remote API calls by up to 2 per post translation.
 */
class MetaExtraCandidatesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setStaticProperty( MetaTranslationService::class, 'meta_translate', null );
		$this->setStaticProperty( MetaTranslationService::class, 'meta_clear', null );
		$this->setStaticProperty( MetaTranslationService::class, 'resolved_meta_key_config', array() );
		$this->setStaticProperty(
			MetaTranslationService::class,
			'seo_plugin_config',
			array(
				'key'       => '',
				'label'     => '',
				'translate' => array(),
				'clear'     => array(),
			)
		);
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', 'en' );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', 'de' );

		$this->stubWpFunction(
			'apply_filters',
			static function ( string $tag, $value, ...$args ) {
				return $value;
			}
		);
		$this->stubWpFunctionReturn( 'get_option', '' );
		$this->stubWpFunction( 'maybe_unserialize', static fn( $value ) => $value );
		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );
		$this->setStaticProperty( TranslationRuntime::class, 'source_lang', null );
		$this->setStaticProperty( TranslationRuntime::class, 'target_lang', null );
		parent::tearDown();
	}

	/* ---------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	/**
	 * Stub wp_ai_client_prompt to return a fluent builder whose generate_text()
	 * emits $response_text wrapped in <slytranslate-output> tags.
	 *
	 * @param string   $response_text The raw text to return from generate_text().
	 * @param string[] $calls         Collector for the user-content strings passed to the builder.
	 */
	private function stubAiResponse( string $response_text, array &$calls = array() ): void {
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function ( string $text ) use ( $response_text, &$calls ) {
				$calls[] = $text;
				return new class( $response_text ) {
					private string $response;

					public function __construct( string $response ) {
						$this->response = $response;
					}

					public function using_system_instruction( string $p ): static { return $this; }
					public function using_temperature( float $t ): static { return $this; }
					public function using_model_preference( string $s ): static { return $this; }
					public function using_max_tokens( int $n ): static { return $this; }
					public function using_max_output_tokens( int $n ): static { return $this; }

					public function generate_text(): string {
						return '<slytranslate-output>' . $this->response . '</slytranslate-output>';
					}
				};
			}
		);
	}

	/* ---------------------------------------------------------------
	 * try_batch_translate_eligible_meta (via invokeStatic)
	 * ------------------------------------------------------------- */

	public function test_two_extra_candidates_alone_meet_batch_threshold(): void {
		$calls = array();
		$this->stubAiResponse(
			'{"_slytranslate_title":"Übersetzter Titel","_slytranslate_excerpt":"Kurzer Überblick"}',
			$calls
		);

		$result = $this->invokeStatic(
			MetaTranslationService::class,
			'try_batch_translate_eligible_meta',
			array(
				array(),  // no regular meta
				array( 'translate' => array(), 'clear' => array() ),  // meta_key_config
				'de',
				'en',
				'',
				array(
					'_slytranslate_title'   => 'Original Title',
					'_slytranslate_excerpt' => 'Short overview',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Übersetzter Titel', $result['_slytranslate_title'] );
		$this->assertSame( 'Kurzer Überblick', $result['_slytranslate_excerpt'] );
		// One AI call for the batch.
		$this->assertCount( 1, $calls );
	}

	public function test_single_extra_candidate_without_meta_skips_batch(): void {
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () {
				throw new \RuntimeException( 'AI must not be called when count < 2.' );
			}
		);

		$result = $this->invokeStatic(
			MetaTranslationService::class,
			'try_batch_translate_eligible_meta',
			array(
				array(),
				array( 'translate' => array(), 'clear' => array() ),
				'de',
				'en',
				'',
				array( '_slytranslate_title' => 'Only Title' ),
			)
		);

		// Below threshold → no batch → null.
		$this->assertNull( $result );
	}

	public function test_one_extra_candidate_plus_one_eligible_meta_triggers_batch(): void {
		$calls = array();
		$this->stubAiResponse(
			'{"_slytranslate_title":"Übersetzter Titel","_yoast_wpseo_metadesc":"SEO Beschreibung"}',
			$calls
		);

		$result = $this->invokeStatic(
			MetaTranslationService::class,
			'try_batch_translate_eligible_meta',
			array(
				array( '_yoast_wpseo_metadesc' => array( 'SEO description' ) ),
				array( 'translate' => array( '_yoast_wpseo_metadesc' ), 'clear' => array() ),
				'de',
				'en',
				'',
				array( '_slytranslate_title' => 'Original Title' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Übersetzter Titel', $result['_slytranslate_title'] );
		$this->assertSame( 'SEO Beschreibung', $result['_yoast_wpseo_metadesc'] );
		$this->assertCount( 1, $calls );
	}

	public function test_batch_returns_null_when_extra_candidate_key_missing_from_response(): void {
		// AI returns JSON that is missing _slytranslate_excerpt → batch validation fails.
		$this->stubAiResponse( '{"_slytranslate_title":"Übersetzter Titel"}' );

		$result = $this->invokeStatic(
			MetaTranslationService::class,
			'try_batch_translate_eligible_meta',
			array(
				array(),
				array( 'translate' => array(), 'clear' => array() ),
				'de',
				'en',
				'',
				array(
					'_slytranslate_title'   => 'Original Title',
					'_slytranslate_excerpt' => 'Short overview',
				),
			)
		);

		// Missing key → null (caller must fall back individually).
		$this->assertNull( $result );
	}

	/* ---------------------------------------------------------------
	 * prepare_translation_meta integration
	 * ------------------------------------------------------------- */

	public function test_prepare_translation_meta_propagates_extra_batch_results(): void {
		$calls = array();
		$this->stubAiResponse(
			'{"_slytranslate_title":"Übersetzter Titel","_slytranslate_excerpt":"Kurzer Überblick"}',
			$calls
		);

		$result = MetaTranslationService::prepare_translation_meta(
			1,
			'de',
			'en',
			'',
			array(),  // no regular meta
			array(
				'_slytranslate_title'   => 'Original Title',
				'_slytranslate_excerpt' => 'Short overview',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Übersetzter Titel', $result['_slytranslate_title'] );
		$this->assertSame( 'Kurzer Überblick', $result['_slytranslate_excerpt'] );
		// Exactly one AI call (the batch).
		$this->assertCount( 1, $calls );
	}

	public function test_prepare_translation_meta_omits_extra_keys_when_batch_fails(): void {
		// AI call returns WP_Error → batch is skipped → no extra keys in result.
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function ( string $text ) {
				return new class {
					public function using_system_instruction( string $p ): static { return $this; }
					public function using_temperature( float $t ): static { return $this; }
					public function using_model_preference( string $s ): static { return $this; }
					public function using_max_tokens( int $n ): static { return $this; }
					public function using_max_output_tokens( int $n ): static { return $this; }

					public function generate_text(): \WP_Error {
						return new \WP_Error( 'ai_error', 'Simulated AI failure.' );
					}
				};
			}
		);

		$result = MetaTranslationService::prepare_translation_meta(
			2,
			'de',
			'en',
			'',
			array(),
			array(
				'_slytranslate_title'   => 'Original Title',
				'_slytranslate_excerpt' => 'Short overview',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( '_slytranslate_title', $result );
		$this->assertArrayNotHasKey( '_slytranslate_excerpt', $result );
	}

	public function test_prepare_translation_meta_without_extra_candidates_unchanged(): void {
		// No extra candidates → existing behaviour: meta not in translate list stays as-is,
		// no AI call.
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () {
				throw new \RuntimeException( 'AI must not be called for non-translatable meta.' );
			}
		);

		$result = MetaTranslationService::prepare_translation_meta(
			3,
			'de',
			'en',
			'',
			array( '_custom_meta' => array( 'custom value' ) )
		);

		$this->assertIsArray( $result );
		// Not in translate list → returned verbatim.
		$this->assertSame( 'custom value', $result['_custom_meta'] );
	}
}
