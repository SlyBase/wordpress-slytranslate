<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\AutoPublishTranslationService;
use SlyTranslate\PolylangAdapter;

/**
 * Covers the create_translation() additions: opt-in slug translation, opt-in
 * term translation for missing target-language terms, and the generated-post
 * loop-guard marker.
 */
class PolylangCreateTranslationTest extends TestCase {

	/** @var array<string, mixed> */
	private array $stored_options = array();

	/** @var array<int, array{0:int, 1:string, 2:mixed}> */
	private array $meta_updates = array();

	/** @var array<int, array> */
	private array $post_updates = array();

	protected function setUp(): void {
		parent::setUp();

		$this->stored_options = array();
		$this->meta_updates   = array();
		$this->post_updates   = array();

		$this->stubWpFunction( 'get_option', function ( $option, $default = false ) {
			return $this->stored_options[ $option ] ?? $default;
		} );
		$this->stubWpFunction( 'get_post', static function ( $post_id = null ) {
			return new \WP_Post( array(
				'ID'         => 5,
				'post_title' => 'Hallo Welt',
				'post_type'  => 'post',
			) );
		} );
		$this->stubWpFunctionReturn( 'pll_get_post', 0 );
		$this->stubWpFunctionReturn( 'wp_insert_post', 99 );
		$this->stubWpFunction( 'wp_update_post', function ( $postarr ) {
			$this->post_updates[] = $postarr;
			return is_array( $postarr ) && isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		} );
		$this->stubWpFunction( 'update_post_meta', function ( $post_id, $key, $value ) {
			$this->meta_updates[] = array( (int) $post_id, (string) $key, $value );
			return true;
		} );
		$this->stubWpFunctionReturn( 'get_object_taxonomies', array() );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

	private function make_adapter(): CreateTranslationPolylangDouble {
		$adapter = new CreateTranslationPolylangDouble();
		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		return $adapter;
	}

	public function test_create_translation_marks_generated_posts(): void {
		$adapter = $this->make_adapter();

		$result = $adapter->create_translation( 5, 'en', array( 'post_title' => 'Hello World' ) );

		$this->assertSame( 99, $result );
		$this->assertContains(
			array( 99, AutoPublishTranslationService::GENERATED_META_KEY, '1' ),
			$this->meta_updates
		);
	}

	public function test_slug_translation_is_off_by_default(): void {
		$adapter = $this->make_adapter();

		$adapter->create_translation( 5, 'en', array( 'post_title' => 'Hello World' ) );

		$this->assertArrayNotHasKey( 'post_name', $this->post_updates[0] );
	}

	public function test_slug_is_derived_from_translated_title_when_opted_in(): void {
		$this->stored_options['slytranslate_translate_slugs'] = '1';
		$adapter = $this->make_adapter();

		$adapter->create_translation( 5, 'en', array( 'post_title' => 'Hello World' ) );

		$this->assertSame( 'hello-world', $this->post_updates[0]['post_name'] );
	}

	public function test_existing_translation_slug_is_left_untouched_unless_auto_stub(): void {
		$this->stored_options['slytranslate_translate_slugs'] = '1';
		$this->stubWpFunctionReturn( 'pll_get_post', 99 ); // translation already exists
		$adapter = $this->make_adapter();

		// Live slug, manually curated → keep it.
		$this->stubWpFunctionReturn( 'get_post_field', 'curated-slug' );
		$adapter->create_translation( 5, 'en', array( 'post_title' => 'Hello World', 'overwrite' => true ) );
		$this->assertArrayNotHasKey( 'post_name', $this->post_updates[0] );

		// Slug still the auto-generated stub ("Hallo Welt (en)") → replace it.
		$this->stubWpFunctionReturn( 'get_post_field', 'hallo-welt-en' );
		$adapter->create_translation( 5, 'en', array( 'post_title' => 'Hello World', 'overwrite' => true ) );
		$this->assertSame( 'hello-world', $this->post_updates[1]['post_name'] );
	}

	public function test_missing_terms_are_translated_when_opted_in(): void {
		$this->stored_options['slytranslate_translate_terms'] = '1';
		$adapter = $this->make_adapter();

		$this->stubWpFunctionReturn( 'get_object_taxonomies', array( 'category' ) );
		$this->stubWpFunctionReturn( 'wp_get_object_terms', array( 7 ) );
		$this->stubWpFunctionReturn( 'pll_get_term', 0 );
		$this->stubWpFunction( 'get_term', static function () {
			return new \WP_Term( array( 'term_id' => 7, 'name' => 'Anleitungen', 'taxonomy' => 'category' ) );
		} );
		$this->stubWpFunction( 'wp_insert_term', static function () {
			return array( 'term_id' => 55 );
		} );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () {
				return new class() {
					public function using_system_instruction( string $p ): static { return $this; }
					public function using_temperature( float $t ): static { return $this; }
					public function using_model_preference( string $s ): static { return $this; }
					public function using_max_tokens( int $n ): static { return $this; }
					public function using_max_output_tokens( int $n ): static { return $this; }
					public function generate_text(): string {
						return '<slytranslate-output>Guides</slytranslate-output>';
					}
				};
			}
		);

		$assigned_terms = array();
		$this->stubWpFunction( 'wp_set_object_terms', static function ( $object_id, $terms, $taxonomy ) use ( &$assigned_terms ) {
			$assigned_terms[] = array( $object_id, $terms, $taxonomy );
			return $terms;
		} );

		$adapter->create_translation( 5, 'en', array( 'post_title' => 'Hello World' ) );

		$this->assertSame( array( array( 99, array( 55 ), 'category' ) ), $assigned_terms );
	}

	public function test_missing_terms_are_dropped_when_option_disabled(): void {
		$adapter = $this->make_adapter();

		$this->stubWpFunctionReturn( 'get_object_taxonomies', array( 'category' ) );
		$this->stubWpFunctionReturn( 'wp_get_object_terms', array( 7 ) );
		$this->stubWpFunctionReturn( 'pll_get_term', 0 );

		$assigned_terms = array();
		$this->stubWpFunction( 'wp_set_object_terms', static function ( $object_id, $terms, $taxonomy ) use ( &$assigned_terms ) {
			$assigned_terms[] = array( $object_id, $terms, $taxonomy );
			return $terms;
		} );

		$adapter->create_translation( 5, 'en', array( 'post_title' => 'Hello World' ) );

		// Previous behaviour stays the default: the unmatched term is dropped.
		$this->assertSame( array(), $assigned_terms );
	}
}

class CreateTranslationPolylangDouble extends PolylangAdapter {
	public function is_available(): bool {
		return true;
	}

	public function get_post_language( int $post_id ): ?string {
		return 'de';
	}

	public function set_post_language( int $post_id, string $target_language ) {
		return true;
	}

	public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
		return true;
	}

	public function supports_term_translation(): bool {
		return true;
	}
}
