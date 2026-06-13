<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\PostTranslationService;
use SlyTranslate\TranslationFingerprint;

/**
 * Diff-based retranslation: unchanged top-level blocks are copied from the
 * existing translation instead of being sent through the model again.
 */
class DiffRetranslationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->stubWpFunction( 'serialize_blocks', static function ( array $blocks ): string {
			return implode( '', array_map( static fn( array $b ) => (string) ( $b['innerHTML'] ?? '' ), $blocks ) );
		} );
	}

	private static function block( string $html ): array {
		return array( 'blockName' => 'core/paragraph', 'innerHTML' => $html );
	}

	public function test_fully_unchanged_content_is_reassembled_without_ai_calls(): void {
		$this->stubWpFunction( 'wp_ai_client_prompt', static function () {
			throw new \RuntimeException( 'No AI call may happen when every block is reused.' );
		} );

		$blocks = array( self::block( 'Alpha' ), self::block( 'Beta' ) );
		$map    = array(
			hash( 'sha256', 'Alpha' ) => 'Alpha-DE',
			hash( 'sha256', 'Beta' )  => 'Beta-DE',
		);

		$content = $this->invokeStatic(
			PostTranslationService::class,
			'translate_blocks_with_reuse',
			array( $blocks, $map, 'de', 'en', '' )
		);

		$this->assertSame( 'Alpha-DEBeta-DE', $content );
	}

	public function test_changed_blocks_are_translated_and_merged_in_source_order(): void {
		$ai_calls = array();
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function ( string $text ) use ( &$ai_calls ) {
				$ai_calls[] = $text;
				return new class() {
					public function using_system_instruction( string $p ): static { return $this; }
					public function using_temperature( float $t ): static { return $this; }
					public function using_model_preference( string $s ): static { return $this; }
					public function using_max_tokens( int $n ): static { return $this; }
					public function using_max_output_tokens( int $n ): static { return $this; }
					public function generate_text(): string {
						return '<slytranslate-output><p>Geänderter Absatz</p></slytranslate-output>';
					}
				};
			}
		);
		// translate_block_sections re-parses the translated chunk.
		$this->stubWpFunction( 'parse_blocks', static function ( string $content ): array {
			return array( array( 'blockName' => 'core/paragraph', 'innerHTML' => $content, 'innerBlocks' => array(), 'innerContent' => array( $content ) ) );
		} );

		$blocks = array(
			array_merge( self::block( '<p>Unchanged paragraph</p>' ), array( 'innerBlocks' => array(), 'innerContent' => array( '<p>Unchanged paragraph</p>' ) ) ),
			array_merge( self::block( '<p>Changed paragraph!</p>' ), array( 'innerBlocks' => array(), 'innerContent' => array( '<p>Changed paragraph!</p>' ) ) ),
		);
		$map = array(
			hash( 'sha256', '<p>Unchanged paragraph</p>' ) => '<p>Unveränderter Absatz</p>',
		);

		$content = $this->invokeStatic(
			PostTranslationService::class,
			'translate_blocks_with_reuse',
			array( $blocks, $map, 'de', 'en', '' )
		);

		$this->assertIsString( $content );
		$this->assertStringContainsString( '<p>Unveränderter Absatz</p>', $content );
		$this->assertStringContainsString( 'Geänderter Absatz', $content );
		$this->assertStringStartsWith( '<p>Unveränderter Absatz</p>', $content );
		$this->assertNotEmpty( $ai_calls );
		// Only the changed block went to the model.
		foreach ( $ai_calls as $call ) {
			$this->assertStringNotContainsString( 'Unchanged paragraph', $call );
		}
	}

	public function test_unit_hash_round_trip_marks_only_edited_blocks_for_translation(): void {
		// Simulates the overwrite flow: hashes stored on the translation at
		// create time, then one source block is edited.
		$original_blocks = array( self::block( 'Alpha' ), self::block( 'Beta' ) );
		$stored_hashes   = TranslationFingerprint::compute_block_hashes( $original_blocks );

		$this->stubWpFunction( 'get_post_meta', static function ( $post_id, $key = '' ) use ( $stored_hashes ) {
			return TranslationFingerprint::UNIT_HASHES_META_KEY === $key ? $stored_hashes : '';
		} );
		$this->stubWpFunction( 'get_post', static function () {
			return new \WP_Post( array( 'ID' => 9, 'post_content' => 'Alpha-DE Beta-DE' ) );
		} );
		$this->stubWpFunction( 'parse_blocks', static function (): array {
			return array( self::block( 'Alpha-DE' ), self::block( 'Beta-DE' ) );
		} );

		$edited_blocks = array( self::block( 'Alpha' ), self::block( 'Beta edited' ) );
		$map           = TranslationFingerprint::plan_block_reuse( $edited_blocks, 9 );

		$this->assertIsArray( $map );
		$this->assertArrayHasKey( hash( 'sha256', 'Alpha' ), $map );
		$this->assertArrayNotHasKey( hash( 'sha256', 'Beta edited' ), $map );
	}
}
