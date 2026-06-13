<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AcfBlockTranslator;
use SlyTranslate\TranslationRuntime;

/**
 * Tests for ACF block field data translation (attrs.data of acf/* blocks).
 */
class AcfBlockTranslatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			return $value;
		} );
		$this->stubWpFunctionReturn( 'get_option', '' );
		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );

		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			$fields = array(
				'field_headline' => array( 'type' => 'text' ),
				'field_body'     => array( 'type' => 'textarea' ),
				'field_caption'  => array( 'type' => 'text' ),
				'field_image'    => array( 'type' => 'image' ),
				'field_link'     => array( 'type' => 'link' ),
			);
			return $fields[ $ref ] ?? false;
		} );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );
		parent::tearDown();
	}

	/**
	 * Stub wp_ai_client_prompt with a fixed response text.
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
	 * collect_translatable_entries
	 * ------------------------------------------------------------- */

	public function test_collects_only_referenced_translatable_string_values(): void {
		$data = array(
			'headline'   => 'Hello',
			'_headline'  => 'field_headline',
			'image'      => '42',
			'_image'     => 'field_image',
			'empty'      => '   ',
			'_empty'     => 'field_body',
			'unref'      => 'No reference key',
			'mode'       => 'preview',
		);

		$entries = AcfBlockTranslator::collect_translatable_entries( $data );

		$this->assertSame( array( 'headline' => 'Hello' ), $entries );
	}

	public function test_repeater_sub_keys_are_collected_via_their_own_reference(): void {
		$data = array(
			'slides_0_caption'  => 'First slide',
			'_slides_0_caption' => 'field_caption',
			'slides_1_caption'  => 'Second slide',
			'_slides_1_caption' => 'field_caption',
			'slides'            => '2',
			'_slides'           => 'field_image',
		);

		$entries = AcfBlockTranslator::collect_translatable_entries( $data );

		$this->assertSame(
			array(
				'slides_0_caption' => 'First slide',
				'slides_1_caption' => 'Second slide',
			),
			$entries
		);
	}

	public function test_structured_link_values_are_not_collected(): void {
		$data = array(
			'cta'  => 'https://example.com',
			'_cta' => 'field_link',
		);

		$this->assertSame( array(), AcfBlockTranslator::collect_translatable_entries( $data ) );
	}

	/* ---------------------------------------------------------------
	 * translate_block_data / translate_blocks_data
	 * ------------------------------------------------------------- */

	public function test_translate_block_data_batches_multiple_values_in_one_call(): void {
		$calls = array();
		$this->stubAiResponse( '{"headline":"Hallo","body":"Willkommen auf unserer Seite"}', $calls );

		$data = array(
			'headline'  => 'Hello',
			'_headline' => 'field_headline',
			'body'      => 'Welcome to our site',
			'_body'     => 'field_body',
			'mode'      => 'preview',
			'id'        => 'block_abc',
		);

		$result = AcfBlockTranslator::translate_block_data( $data, 'de', 'en' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Hallo', $result['headline'] );
		$this->assertSame( 'Willkommen auf unserer Seite', $result['body'] );
		// References and non-translatable entries stay untouched.
		$this->assertSame( 'field_headline', $result['_headline'] );
		$this->assertSame( 'preview', $result['mode'] );
		$this->assertSame( 'block_abc', $result['id'] );
		// One single batched AI call.
		$this->assertCount( 1, $calls );
	}

	public function test_translate_block_data_single_value_uses_individual_call(): void {
		$this->stubAiResponse( 'Hallo' );

		$data = array(
			'headline'  => 'Hello',
			'_headline' => 'field_headline',
		);

		$result = AcfBlockTranslator::translate_block_data( $data, 'de', 'en' );

		$this->assertSame( 'Hallo', $result['headline'] );
	}

	public function test_translate_block_data_without_translatable_entries_makes_no_ai_call(): void {
		$calls = array();
		$this->stubAiResponse( 'should not be used', $calls );

		$data = array(
			'image'  => '42',
			'_image' => 'field_image',
		);

		$result = AcfBlockTranslator::translate_block_data( $data, 'de', 'en' );

		$this->assertSame( $data, $result );
		$this->assertCount( 0, $calls );
	}

	public function test_translate_blocks_data_recurses_into_inner_blocks(): void {
		$this->stubAiResponse( 'Hallo' );

		$blocks = array(
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerBlocks' => array(
					array(
						'blockName'   => 'acf/hero',
						'attrs'       => array(
							'name' => 'acf/hero',
							'data' => array(
								'headline'  => 'Hello',
								'_headline' => 'field_headline',
							),
							'mode' => 'preview',
						),
						'innerBlocks' => array(),
					),
				),
			),
			array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array(),
				'innerBlocks' => array(),
			),
		);

		$result = AcfBlockTranslator::translate_blocks_data( $blocks, 'de', 'en' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Hallo', $result[0]['innerBlocks'][0]['attrs']['data']['headline'] );
		$this->assertSame( 'field_headline', $result[0]['innerBlocks'][0]['attrs']['data']['_headline'] );
		$this->assertSame( 'preview', $result[0]['innerBlocks'][0]['attrs']['mode'] );
		// Non-ACF blocks pass through untouched.
		$this->assertSame( 'core/paragraph', $result[1]['blockName'] );
	}

	/* ---------------------------------------------------------------
	 * Client workflow units (round trip)
	 * ------------------------------------------------------------- */

	private function sample_parsed_blocks(): array {
		return array(
			array(
				'blockName'   => 'acf/hero',
				'attrs'       => array(
					'name' => 'acf/hero',
					'data' => array(
						'headline'  => 'Hello',
						'_headline' => 'field_headline',
					),
					'mode' => 'preview',
				),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerBlocks' => array(
					array(
						'blockName'   => 'acf/slide',
						'attrs'       => array(
							'name' => 'acf/slide',
							'data' => array(
								'caption'  => 'A caption',
								'_caption' => 'field_caption',
							),
						),
						'innerBlocks' => array(),
					),
				),
			),
		);
	}

	public function test_build_block_units_encodes_block_paths(): void {
		$this->stubWpFunctionReturn( 'parse_blocks', $this->sample_parsed_blocks() );

		$units = AcfBlockTranslator::build_block_units( '<!-- wp:acf/hero /-->' );

		$this->assertCount( 2, $units );
		$this->assertSame( 'acf_block:0:headline', $units[0]['id'] );
		$this->assertSame( 'Hello', $units[0]['source'] );
		$this->assertSame( 'acf_block', $units[0]['field'] );
		$this->assertSame( 'acf_block:1.0:caption', $units[1]['id'] );
		$this->assertSame( 'A caption', $units[1]['source'] );
	}

	public function test_apply_block_unit_translations_round_trip(): void {
		$this->stubWpFunctionReturn( 'parse_blocks', $this->sample_parsed_blocks() );
		$this->stubWpFunction( 'serialize_blocks', static function ( array $blocks ): string {
			return (string) json_encode( $blocks );
		} );

		$result = AcfBlockTranslator::apply_block_unit_translations(
			'<!-- wp:acf/hero /-->',
			array(
				'acf_block:0:headline'  => 'Hallo',
				'acf_block:1.0:caption' => 'Eine Bildunterschrift',
				'title'                 => 'ignored non-acf unit',
			)
		);

		$decoded = json_decode( $result, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'Hallo', $decoded[0]['attrs']['data']['headline'] );
		$this->assertSame( 'field_headline', $decoded[0]['attrs']['data']['_headline'] );
		$this->assertSame( 'Eine Bildunterschrift', $decoded[1]['innerBlocks'][0]['attrs']['data']['caption'] );
	}

	public function test_apply_block_unit_translations_skips_mismatched_paths(): void {
		$this->stubWpFunctionReturn( 'parse_blocks', $this->sample_parsed_blocks() );
		$this->stubWpFunction( 'serialize_blocks', static function ( array $blocks ): string {
			return (string) json_encode( $blocks );
		} );

		$content = '<!-- wp:acf/hero /-->';
		$result  = AcfBlockTranslator::apply_block_unit_translations(
			$content,
			array(
				'acf_block:9:headline'    => 'wrong index',
				'acf_block:0:nonexistent' => 'wrong key',
				'acf_block:1:caption'     => 'wrong depth (block 1 is core/group)',
			)
		);

		// Nothing applied → original content returned unchanged.
		$this->assertSame( $content, $result );
	}

	public function test_apply_block_unit_translations_without_acf_units_returns_content(): void {
		$content = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';

		$this->assertSame(
			$content,
			AcfBlockTranslator::apply_block_unit_translations( $content, array( 'title' => 'Hallo' ) )
		);
	}
}
