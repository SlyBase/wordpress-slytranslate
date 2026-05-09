<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\ContentTranslator;

/**
 * Tests for ContentTranslator::translate_string_table_units() and the
 * internal chunk_string_table_units helper (accessed via the public wrapper).
 */
class ContentTranslationStringTableBatchTest extends TestCase {

	// -----------------------------------------------------------------------
	// chunk_string_table_units (via public static method)
	// -----------------------------------------------------------------------

	public function test_units_are_chunked_by_item_limit(): void {
		$units = $this->make_units( 5, 10 ); // 5 units, each 10 chars

		$chunks = $this->invokeStatic( ContentTranslator::class, 'chunk_string_table_units', array( $units, 3, 9999 ) );

		// With max_items=3: chunk1=[0,1,2], chunk2=[3,4].
		$this->assertCount( 2, $chunks );
		$this->assertCount( 3, $chunks[0] );
		$this->assertCount( 2, $chunks[1] );
	}

	public function test_units_are_chunked_by_char_limit(): void {
		// 10 units of 300 chars each, max_chars=800 → floor(800/300)=2 per chunk
		$units = $this->make_units( 10, 300 );

		$chunks = $this->invokeStatic( ContentTranslator::class, 'chunk_string_table_units', array( $units, 99, 800 ) );

		// First chunk: 2 units (600 chars), adding a 3rd would exceed 800.
		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 2, count( $chunk ) );
		}
		$this->assertCount( 10, array_merge( ...$chunks ) );
	}

	public function test_empty_units_returns_empty_chunks(): void {
		$chunks = $this->invokeStatic( ContentTranslator::class, 'chunk_string_table_units', array( array(), 24, 2200 ) );
		$this->assertSame( array(), $chunks );
	}

	public function test_single_unit_always_forms_one_chunk(): void {
		$units  = $this->make_units( 1, 50 );
		$chunks = $this->invokeStatic( ContentTranslator::class, 'chunk_string_table_units', array( $units, 24, 2200 ) );
		$this->assertCount( 1, $chunks );
		$this->assertCount( 1, $chunks[0] );
	}

	public function test_191_units_form_8_batches_at_plan_limits(): void {
		// Reproduces the Post-11 scenario: 191 segments, avg 60 chars each.
		$units = $this->make_units( 191, 60 );

		$chunks = $this->invokeStatic( ContentTranslator::class, 'chunk_string_table_units', array( $units, 24, 2200 ) );

		// With 24 items/batch: ceil(191/24) = 8 batches.
		$this->assertCount( 8, $chunks );
		$this->assertCount( 191, array_merge( ...$chunks ) );
	}

	// -----------------------------------------------------------------------
	// translate_string_table_units – happy path
	// -----------------------------------------------------------------------

	public function test_string_table_units_translated_and_mapped_to_lookup_keys(): void {
		$units = array(
			array(
				'id'          => 'seg_0',
				'source'      => 'Hello world',
				'lookup_keys' => array( 'Hello world' ),
			),
			array(
				'id'          => 'seg_1',
				'source'      => 'Second sentence',
				'lookup_keys' => array( 'Second sentence' ),
			),
		);

		$response_json = json_encode( array(
			'seg_0' => 'Hallo Welt',
			'seg_1' => 'Zweiter Satz',
		) );

		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpAiClientWithFixedResponse( $response_json );

		$result = ContentTranslator::translate_string_table_units( $units, 'de', 'en' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Hallo Welt', $result['Hello world'] );
		$this->assertSame( 'Zweiter Satz', $result['Second sentence'] );
	}

	public function test_string_batch_maps_multiple_lookup_keys_to_same_translation(): void {
		$units = array(
			array(
				'id'          => 'seg_0',
				'source'      => ' Hello world ',
				'lookup_keys' => array( ' Hello world ', 'Hello world' ),
			),
		);

		$response_json = json_encode( array( 'seg_0' => 'Hallo Welt' ) );

		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpAiClientWithFixedResponse( $response_json );

		$result = ContentTranslator::translate_string_table_units( $units, 'de', 'en' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Hallo Welt', $result[' Hello world '] );
		$this->assertSame( 'Hallo Welt', $result['Hello world'] );
	}

	public function test_empty_units_returns_empty_pairs(): void {
		$result = ContentTranslator::translate_string_table_units( array(), 'de', 'en' );
		$this->assertSame( array(), $result );
	}

	// -----------------------------------------------------------------------
	// translate_string_table_units – error cases
	// -----------------------------------------------------------------------

	public function test_string_batch_rejects_missing_key(): void {
		$units = array(
			array(
				'id'          => 'seg_0',
				'source'      => 'Hello',
				'lookup_keys' => array( 'Hello' ),
			),
			array(
				'id'          => 'seg_1',
				'source'      => 'World',
				'lookup_keys' => array( 'World' ),
			),
		);

		// Batch returns JSON with seg_0 but misses seg_1.
		$response_json = json_encode( array( 'seg_0' => 'Hallo' ) );

		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpAiClientWithFixedResponse( $response_json );

		$result = ContentTranslator::translate_string_table_units( $units, 'de', 'en' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'string_batch_missing_key', $result->get_error_code() );
	}

	public function test_string_batch_returns_error_on_invalid_json(): void {
		$units = array(
			array(
				'id'          => 'seg_0',
				'source'      => 'Hello',
				'lookup_keys' => array( 'Hello' ),
			),
		);

		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpAiClientWithFixedResponse( 'NOT JSON AT ALL' );

		$result = ContentTranslator::translate_string_table_units( $units, 'de', 'en' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		// Runtime validation catches the non-JSON or the JSON decode step fails.
		$this->assertNotEmpty( $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Build $count synthetic units, each with a source of $source_len chars.
	 *
	 * @return array<int, array{id: string, source: string, lookup_keys: string[]}>
	 */
	private function make_units( int $count, int $source_len ): array {
		$units = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$source  = str_repeat( 'x', $source_len );
			$units[] = array(
				'id'          => 'seg_' . $i,
				'source'      => $source,
				'lookup_keys' => array( $source ),
			);
		}
		return $units;
	}

	/**
	 * Stub wp_ai_client_prompt to return a fixed JSON string.
	 */
	private function stubWpAiClientWithFixedResponse( string $fixed_response ): void {
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () use ( $fixed_response ) {
				return new class( $fixed_response ) {
					private string $resp;

					public function __construct( string $resp ) {
						$this->resp = $resp;
					}

					public function using_system_instruction( string $p ): static {
						return $this;
					}

					public function using_temperature( float $t ): static {
						return $this;
					}

					public function using_model_preference( string $m ): static {
						return $this;
					}

					public function using_max_tokens( int $t ): static {
						return $this;
					}

					public function generate_text(): string {
						return $this->resp;
					}
				};
			}
		);
	}
}
