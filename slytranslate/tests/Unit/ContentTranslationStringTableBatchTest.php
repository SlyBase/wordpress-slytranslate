<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\ContentTranslator;
use SlyTranslate\TranslationRuntime;

/**
 * Tests for ContentTranslator::translate_string_table_units() and the
 * internal chunk_string_table_units helper (accessed via the public wrapper).
 */
class ContentTranslationStringTableBatchTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Reset cached chunk limit so each test starts with a clean state.
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', null );
	}

	// -----------------------------------------------------------------------
	// chunk_string_table_units (via public static method)
	// -----------------------------------------------------------------------

	public function test_units_are_chunked_by_item_limit(): void {
		$units = $this->make_units( 5, 10 ); // 5 units, each 10 chars

		// Use a very large cache value so the char limit never triggers.
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$chunks = $this->invokeStatic( ContentTranslator::class, 'chunk_string_table_units', array( $units, 3 ) );

		// With max_items=3: chunk1=[0,1,2], chunk2=[3,4].
		$this->assertCount( 2, $chunks );
		$this->assertCount( 3, $chunks[0] );
		$this->assertCount( 2, $chunks[1] );
	}

	public function test_units_are_chunked_by_encoded_char_limit(): void {
		// Set runtime limit to 1200 → safe limit = 1000.
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 1200 );

		// 10 units of 200 chars each.
		$units    = $this->make_units( 10, 200 );
		$chunks   = $this->invokeStatic( ContentTranslator::class, 'chunk_string_table_units', array( $units, 99 ) );
		$safe     = ContentTranslator::get_string_table_batch_char_limit();

		// Every chunk must have an encoded JSON length within the safe limit.
		foreach ( $chunks as $chunk ) {
			$encoded = $this->invokeStatic( ContentTranslator::class, 'encoded_string_batch_length', array( $chunk ) );
			$this->assertLessThanOrEqual( $safe, $encoded, 'Batch encoded length exceeds safe limit.' );
		}
		// All units must be preserved.
		$this->assertCount( 10, array_merge( ...$chunks ) );
	}

	public function test_empty_units_returns_empty_chunks(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$chunks = $this->invokeStatic( ContentTranslator::class, 'chunk_string_table_units', array( array(), 24 ) );
		$this->assertSame( array(), $chunks );
	}

	public function test_single_unit_always_forms_one_chunk(): void {
		$units  = $this->make_units( 1, 50 );
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$chunks = $this->invokeStatic( ContentTranslator::class, 'chunk_string_table_units', array( $units, 24 ) );
		$this->assertCount( 1, $chunks );
		$this->assertCount( 1, $chunks[0] );
	}

	public function test_191_units_chunked_respecting_encoded_limit(): void {
		// Reproduces the Post-11 scenario with the new encoded-length-aware chunking.
		// Set runtime limit to 1200 → safe limit = 1000.
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 1200 );

		$units  = $this->make_units( 191, 60 );
		$chunks = $this->invokeStatic( ContentTranslator::class, 'chunk_string_table_units', array( $units, 24 ) );
		$safe   = ContentTranslator::get_string_table_batch_char_limit();

		// All 191 units are present.
		$this->assertCount( 191, array_merge( ...$chunks ) );

		// Every batch respects the encoded JSON limit.
		foreach ( $chunks as $chunk ) {
			$encoded = $this->invokeStatic( ContentTranslator::class, 'encoded_string_batch_length', array( $chunk ) );
			$this->assertLessThanOrEqual( $safe, $encoded, 'Batch encoded length exceeds safe limit.' );
		}

		// With a safe limit of 1000 and 60-char sources we expect more than 8 batches.
		$this->assertGreaterThan( 8, count( $chunks ) );
	}

	public function test_string_table_char_limit_is_below_runtime_chunk_limit(): void {
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 1200 );

		$safe    = ContentTranslator::get_string_table_batch_char_limit();
		$runtime = TranslationRuntime::get_chunk_char_limit();

		$this->assertLessThanOrEqual( $runtime, $safe );
		$this->assertSame( 1000, $safe );
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

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
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

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
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
	// JSON decode diagnostics and split-retry
	// -----------------------------------------------------------------------

	public function test_decode_string_table_json_accepts_valid_json(): void {
		$raw     = '{"seg_0":"Hallo Welt"}';
		$decoded = $this->invokeStatic( ContentTranslator::class, 'decode_string_table_json', array( $raw, 0, 22 ) );

		$this->assertIsArray( $decoded );
		$this->assertSame( 'Hallo Welt', $decoded['seg_0'] );
	}

	public function test_decode_string_table_json_strips_code_fences(): void {
		$raw     = "```json\n{\"seg_0\":\"Hallo\"}\n```";
		$decoded = $this->invokeStatic( ContentTranslator::class, 'decode_string_table_json', array( $raw, 0, 30 ) );

		$this->assertIsArray( $decoded );
		$this->assertSame( 'Hallo', $decoded['seg_0'] );
	}

	public function test_decode_string_table_json_returns_error_for_double_objects(): void {
		// Two concatenated JSON objects (the original bug scenario).
		$raw    = '{"seg_0":"Hello"}{"seg_1":"World"}';
		$result = $this->invokeStatic( ContentTranslator::class, 'decode_string_table_json', array( $raw, 0, strlen( $raw ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'string_batch_json_decode_failed', $result->get_error_code() );
	}

	public function test_split_retry_succeeds_after_double_json_object(): void {
		// First call returns two concatenated objects; subsequent calls return
		// one valid object per half-batch.
		$call_count = 0;
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () use ( &$call_count ) {
				$call_count++;
				$response = match ( $call_count ) {
					1       => '{"seg_0":"Hallo"}{"seg_1":"Welt"}', // bad – triggers retry
					2       => '{"seg_0":"Hallo"}',
					default => '{"seg_1":"Welt"}',
				};

				return new class( $response ) {
					private string $resp;

					public function __construct( string $resp ) { $this->resp = $resp; }

					public function using_system_instruction( string $p ): static { return $this; }

					public function using_temperature( float $t ): static { return $this; }

					public function using_model_preference( string $m ): static { return $this; }

					public function using_max_tokens( int $t ): static { return $this; }

					public function generate_text(): string { return $this->resp; }
				};
			}
		);

		$units = array(
			array( 'id' => 'seg_0', 'source' => 'Hello', 'lookup_keys' => array( 'Hello' ) ),
			array( 'id' => 'seg_1', 'source' => 'World', 'lookup_keys' => array( 'World' ) ),
		);

		$result = ContentTranslator::translate_string_table_units( $units, 'de', 'en' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Hallo', $result['Hello'] );
		$this->assertSame( 'Welt', $result['World'] );
		// Exactly 3 AI calls: 1 initial failure + 2 half-batch retries.
		$this->assertSame( 3, $call_count );
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

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
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

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
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
