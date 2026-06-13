<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\MetaTranslationService;
use SlyTranslate\TranslationRuntime;

/**
 * Tests for selective sub-key translation of structured meta values
 * (e.g. ACF link arrays: translate title, never url/target).
 */
class MetaValueSpecTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		MetaTranslationService::reset_cache();
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			return $value;
		} );
		$this->stubWpFunctionReturn( 'get_option', '' );
		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );
		$this->stubWpFunction( 'maybe_unserialize', static fn( $value ) => $value );
	}

	protected function tearDown(): void {
		MetaTranslationService::reset_cache();
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

	public function test_spec_translates_only_listed_subkeys(): void {
		$this->stubAiResponse( 'Hier klicken' );
		MetaTranslationService::set_meta_value_spec( 'cta_link', array( 'subkeys' => array( 'title' ) ), 'link' );

		$value = array(
			'title'  => 'Click here',
			'url'    => 'https://example.com/page',
			'target' => '_blank',
		);

		$result = MetaTranslationService::translate_meta_value_for_key( 'cta_link', $value, 'de', 'en' );

		$this->assertSame( 'Hier klicken', $result['title'] );
		$this->assertSame( 'https://example.com/page', $result['url'] );
		$this->assertSame( '_blank', $result['target'] );
	}

	public function test_spec_with_non_array_value_returns_value_untranslated(): void {
		$calls = array();
		$this->stubAiResponse( 'should not be called', $calls );
		MetaTranslationService::set_meta_value_spec( 'cta_link', array( 'subkeys' => array( 'title' ) ), 'link' );

		$result = MetaTranslationService::translate_meta_value_for_key( 'cta_link', 'https://example.com', 'de', 'en' );

		$this->assertSame( 'https://example.com', $result );
		$this->assertCount( 0, $calls );
	}

	public function test_keys_without_spec_use_generic_translation(): void {
		$this->stubAiResponse( 'Hallo Welt' );

		$result = MetaTranslationService::translate_meta_value_for_key( 'plain_key', 'Hello World', 'de', 'en' );

		$this->assertSame( 'Hallo Welt', $result );
	}

	public function test_spec_filter_can_provide_spec_for_unregistered_key(): void {
		$this->stubAiResponse( 'Etikett' );

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_meta_value_translation_spec' === $tag && 'third_party_key' === ( $args[0] ?? '' ) ) {
				return array( 'subkeys' => array( 'label' ) );
			}
			return $value;
		} );

		$value  = array( 'label' => 'Label', 'icon' => 'star' );
		$result = MetaTranslationService::translate_meta_value_for_key( 'third_party_key', $value, 'de', 'en' );

		$this->assertSame( 'Etikett', $result['label'] );
		$this->assertSame( 'star', $result['icon'] );
	}

	public function test_spec_keys_are_excluded_from_batching(): void {
		MetaTranslationService::set_meta_value_spec( 'cta_link', array( 'subkeys' => array( 'title' ) ), 'link' );

		$meta = array(
			'cta_link'  => array( 'serialized-link-value' ),
			'plain_key' => array( 'Hello' ),
		);

		$count = MetaTranslationService::count_batch_eligible_candidates(
			$meta,
			array( 'translate' => array( 'cta_link', 'plain_key' ), 'clear' => array() )
		);

		$this->assertSame( 1, $count );
	}

	public function test_reset_cache_clears_specs(): void {
		MetaTranslationService::set_meta_value_spec( 'cta_link', array( 'subkeys' => array( 'title' ) ), 'link' );
		$this->assertNotEmpty( MetaTranslationService::get_meta_value_spec( 'cta_link' )['spec'] );

		MetaTranslationService::reset_cache();

		$this->assertSame( array(), MetaTranslationService::get_meta_value_spec( 'cta_link' )['spec'] );
	}
}
