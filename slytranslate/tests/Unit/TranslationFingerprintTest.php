<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\TranslationFingerprint;

class TranslationFingerprintTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// serialize_blocks: deterministic serialization over innerHTML.
		$this->stubWpFunction( 'serialize_blocks', static function ( array $blocks ): string {
			return implode( '', array_map( static fn( array $b ) => (string) ( $b['innerHTML'] ?? '' ), $blocks ) );
		} );
	}

	private static function block( string $html ): array {
		return array( 'blockName' => 'core/paragraph', 'innerHTML' => $html );
	}

	public function test_compute_block_hashes_hashes_each_top_level_block(): void {
		$hashes = TranslationFingerprint::compute_block_hashes( array( self::block( 'Alpha' ), self::block( 'Beta' ) ) );

		$this->assertSame(
			array( hash( 'sha256', 'Alpha' ), hash( 'sha256', 'Beta' ) ),
			$hashes
		);
	}

	public function test_plan_block_reuse_maps_unchanged_blocks_by_content_hash(): void {
		$source_blocks = array( self::block( 'Alpha' ), self::block( 'Changed!' ) );

		$this->stubWpFunction( 'get_post_meta', static function ( $post_id, $key = '' ) {
			return TranslationFingerprint::UNIT_HASHES_META_KEY === $key
				? array( hash( 'sha256', 'Alpha' ), hash( 'sha256', 'Beta' ) )
				: '';
		} );
		$this->stubWpFunction( 'get_post', static function () {
			return new \WP_Post( array( 'ID' => 9, 'post_content' => 'Alpha-DE Beta-DE' ) );
		} );
		$this->stubWpFunction( 'parse_blocks', static function (): array {
			return array( self::block( 'Alpha-DE' ), self::block( 'Beta-DE' ) );
		} );

		$map = TranslationFingerprint::plan_block_reuse( $source_blocks, 9 );

		$this->assertIsArray( $map );
		$this->assertSame( 'Alpha-DE', $map[ hash( 'sha256', 'Alpha' ) ] );
		$this->assertSame( 'Beta-DE', $map[ hash( 'sha256', 'Beta' ) ] );
	}

	public function test_plan_block_reuse_falls_back_when_translation_block_count_diverged(): void {
		$this->stubWpFunction( 'get_post_meta', static function ( $post_id, $key = '' ) {
			return TranslationFingerprint::UNIT_HASHES_META_KEY === $key
				? array( hash( 'sha256', 'Alpha' ), hash( 'sha256', 'Beta' ) )
				: '';
		} );
		$this->stubWpFunction( 'get_post', static function () {
			return new \WP_Post( array( 'ID' => 9, 'post_content' => 'Alpha-DE' ) );
		} );
		// Translation only has one block left although two hashes were stored.
		$this->stubWpFunction( 'parse_blocks', static function (): array {
			return array( self::block( 'Alpha-DE' ) );
		} );

		$this->assertNull( TranslationFingerprint::plan_block_reuse( array( self::block( 'Alpha' ) ), 9 ) );
	}

	public function test_plan_block_reuse_returns_null_without_stored_hashes_or_matches(): void {
		$this->stubWpFunctionReturn( 'get_post_meta', '' );
		$this->assertNull( TranslationFingerprint::plan_block_reuse( array( self::block( 'Alpha' ) ), 9 ) );

		// Stored hashes exist but nothing in the new source matches.
		$this->stubWpFunction( 'get_post_meta', static function ( $post_id, $key = '' ) {
			return TranslationFingerprint::UNIT_HASHES_META_KEY === $key
				? array( hash( 'sha256', 'Old content' ) )
				: '';
		} );
		$this->stubWpFunction( 'get_post', static function () {
			return new \WP_Post( array( 'ID' => 9, 'post_content' => 'Old-DE' ) );
		} );
		$this->stubWpFunction( 'parse_blocks', static function (): array {
			return array( self::block( 'Old-DE' ) );
		} );

		$this->assertNull( TranslationFingerprint::plan_block_reuse( array( self::block( 'All new' ) ), 9 ) );
	}

	public function test_get_unchanged_meta_keys_compares_stored_hashes(): void {
		$all_meta = array(
			'subtitle'    => array( 'Unchanged value' ),
			'description' => array( 'Edited value' ),
		);

		$this->stubWpFunction( 'get_post_meta', static function ( $post_id, $key = '' ) {
			return TranslationFingerprint::META_HASHES_META_KEY === $key
				? array(
					'subtitle'    => hash( 'sha256', 'Unchanged value' ),
					'description' => hash( 'sha256', 'Original value' ),
				)
				: '';
		} );

		$unchanged = TranslationFingerprint::get_unchanged_meta_keys( 9, $all_meta, array( 'subtitle', 'description' ) );

		$this->assertSame( array( 'subtitle' ), $unchanged );
	}

	public function test_store_fingerprints_persists_block_and_meta_hashes(): void {
		$updates = array();
		$this->stubWpFunction( 'update_post_meta', static function ( $post_id, $key, $value ) use ( &$updates ) {
			$updates[ $key ] = array( $post_id, $value );
			return true;
		} );

		TranslationFingerprint::store_fingerprints(
			9,
			array( self::block( 'Alpha' ) ),
			array( 'subtitle' => array( 'Value' ) ),
			array( 'subtitle', 'missing_key' )
		);

		$this->assertSame(
			array( 9, array( hash( 'sha256', 'Alpha' ) ) ),
			$updates[ TranslationFingerprint::UNIT_HASHES_META_KEY ]
		);
		$this->assertSame(
			array( 9, array( 'subtitle' => hash( 'sha256', 'Value' ) ) ),
			$updates[ TranslationFingerprint::META_HASHES_META_KEY ]
		);
	}

	public function test_post_fingerprint_stays_md5_compatible_with_client_workflow(): void {
		$post = new \WP_Post( array( 'post_title' => 'T', 'post_content' => 'C', 'post_excerpt' => 'E' ) );

		$this->assertSame( md5( "T\x00C\x00E" ), TranslationFingerprint::compute_post_fingerprint( $post ) );
	}
}
