<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\PolylangAdapter;
use SlyTranslate\TermTranslationService;

class TermTranslationServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setStaticProperty( AI_Translate::class, 'adapter', new TermTestPolylangAdapter() );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

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

	public function test_translate_term_creates_sets_language_and_links(): void {
		$this->stubAiResponse( 'Guides' );
		$this->stubWpFunctionReturn( 'pll_get_term', 0 );
		$this->stubWpFunction( 'get_term', static function () {
			return new \WP_Term( array(
				'term_id'     => 7,
				'name'        => 'Anleitungen',
				'taxonomy'    => 'category',
				'description' => '',
			) );
		} );

		$inserted_args     = array();
		$language_calls    = array();
		$translation_links = array();

		$this->stubWpFunction( 'wp_insert_term', static function ( $name, $taxonomy, $args ) use ( &$inserted_args ) {
			$inserted_args = array( $name, $taxonomy, $args );
			return array( 'term_id' => 55, 'term_taxonomy_id' => 55 );
		} );
		$this->stubWpFunction( 'pll_set_term_language', static function ( $term_id, $lang ) use ( &$language_calls ) {
			$language_calls[] = array( $term_id, $lang );
			return true;
		} );
		$this->stubWpFunction( 'pll_save_term_translations', static function ( $translations ) use ( &$translation_links ) {
			$translation_links[] = $translations;
			return true;
		} );

		$result = TermTranslationService::translate_term( 7, 'en', 'de' );

		$this->assertSame( 55, $result );
		$this->assertSame( 'Guides', $inserted_args[0] );
		$this->assertSame( 'category', $inserted_args[1] );
		$this->assertSame( 'guides', $inserted_args[2]['slug'] );
		$this->assertSame( array( array( 55, 'en' ) ), $language_calls );
		$this->assertSame( array( array( 'de' => 7, 'en' => 55 ) ), $translation_links );
	}

	public function test_translate_term_returns_existing_linked_translation_without_ai_call(): void {
		$this->stubWpFunctionReturn( 'pll_get_term', 9 );
		$this->stubWpFunction( 'wp_ai_client_prompt', static function () {
			throw new \RuntimeException( 'AI must not be called for already translated terms.' );
		} );
		$this->stubWpFunction( 'wp_insert_term', static function () {
			throw new \RuntimeException( 'wp_insert_term must not be called for already translated terms.' );
		} );

		$this->assertSame( 9, TermTranslationService::translate_term( 7, 'en', 'de' ) );
	}

	public function test_translate_term_reuses_term_when_translated_name_already_exists(): void {
		$this->stubAiResponse( 'Guides' );
		$this->stubWpFunctionReturn( 'pll_get_term', 0 );
		$this->stubWpFunction( 'get_term', static function () {
			return new \WP_Term( array( 'term_id' => 7, 'name' => 'Anleitungen', 'taxonomy' => 'category' ) );
		} );
		$this->stubWpFunction( 'wp_insert_term', static function () {
			return new \WP_Error( 'term_exists', 'A term with the name provided already exists.', 12 );
		} );

		$translation_links = array();
		$this->stubWpFunction( 'pll_save_term_translations', static function ( $translations ) use ( &$translation_links ) {
			$translation_links[] = $translations;
			return true;
		} );

		$this->assertSame( 12, TermTranslationService::translate_term( 7, 'en', 'de' ) );
		$this->assertSame( array( array( 'de' => 7, 'en' => 12 ) ), $translation_links );
	}

	public function test_translate_term_fails_for_untranslated_taxonomy(): void {
		$this->stubWpFunctionReturn( 'pll_get_term', 0 );
		$this->stubWpFunctionReturn( 'pll_is_translated_taxonomy', false );
		$this->stubWpFunction( 'get_term', static function () {
			return new \WP_Term( array( 'term_id' => 7, 'name' => 'Anleitungen', 'taxonomy' => 'product_attr' ) );
		} );

		$result = TermTranslationService::translate_term( 7, 'en', 'de' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'taxonomy_not_translated', $result->get_error_code() );
	}

	public function test_execute_translate_terms_dry_run_reports_without_writing(): void {
		$this->stubWpFunction( 'wp_ai_client_prompt', static function () {
			throw new \RuntimeException( 'AI must not be called in dry-run mode.' );
		} );
		$this->stubWpFunction( 'pll_get_term', static function ( $term_id ) {
			return 2 === $term_id ? 77 : 0;
		} );

		$result = TermTranslationService::execute_translate_terms( array(
			'taxonomy'        => 'category',
			'target_language' => 'en',
			'source_language' => 'de',
			'term_ids'        => array( 1, 2 ),
			'dry_run'         => true,
		) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $result['succeeded'] );
		$this->assertSame( 'would_translate', $result['results'][0]['status'] );
		$this->assertSame( 'skipped', $result['results'][1]['status'] );
		$this->assertSame( 77, $result['results'][1]['translated_term_id'] );
	}

	public function test_execute_translate_terms_requires_supported_adapter(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		$this->stubWpFunction( 'apply_filters', static fn( $tag, $value ) => $value );

		$result = TermTranslationService::execute_translate_terms( array(
			'taxonomy'        => 'category',
			'target_language' => 'en',
		) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'term_translation_unsupported', $result->get_error_code() );
	}

	public function test_execute_translate_terms_uses_default_language_as_source(): void {
		$this->stubWpFunctionReturn( 'pll_default_language', 'en' );

		$result = TermTranslationService::execute_translate_terms( array(
			'taxonomy'        => 'category',
			'target_language' => 'en',
			'term_ids'        => array( 1 ),
		) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_source_language', $result->get_error_code() );
	}
}

/**
 * PolylangAdapter double whose availability checks always pass.
 */
class TermTestPolylangAdapter extends PolylangAdapter {
	public function is_available(): bool {
		return true;
	}

	public function supports_term_translation(): bool {
		return true;
	}
}
