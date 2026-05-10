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
		$this->setStaticProperty( TranslationRuntime::class, 'context', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );
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

	public function test_string_table_char_limit_prefers_model_profile_limit(): void {
		$this->stubWpFunction( 'get_option', static function ( $option, $default = false ) {
			if ( 'slytranslate_model_slug' === $option ) {
				return 'Ministral-8B-Instruct-2410-Q4_K_M';
			}

			return $default;
		} );

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 1200 );

		$context = ContentTranslator::get_string_table_batch_limit_context();

		$this->assertSame( 1000, $context['limit'] );
		$this->assertSame( 1000, $context['profile_limit'] );
		$this->assertSame( 'ministral', $context['profile_id'] );
		$this->assertSame( 'model_profile', $context['source'] );
	}

	public function test_string_table_char_limit_falls_back_to_runtime_when_profile_has_no_override(): void {
		$this->stubWpFunction( 'get_option', static function ( $option, $default = false ) {
			if ( 'slytranslate_model_slug' === $option ) {
				return 'unknown-model';
			}

			return $default;
		} );

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 1500 );

		$context = ContentTranslator::get_string_table_batch_limit_context();

		$this->assertSame( 1300, $context['limit'] );
		$this->assertSame( 0, $context['profile_limit'] );
		$this->assertSame( 'runtime_fallback', $context['source'] );
	}

	public function test_string_table_char_limit_filter_can_override_and_is_clamped(): void {
		$this->stubWpFunction( 'get_option', static function ( $option, $default = false ) {
			if ( 'slytranslate_model_slug' === $option ) {
				return 'unknown-model';
			}

			return $default;
		} );
		$this->stubWpFunction( 'apply_filters', static function ( $hook_name, $value, ...$args ) {
			if ( 'slytranslate_string_table_batch_char_limit' === $hook_name ) {
				return 200;
			}

			return $value;
		} );

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 1500 );

		$context = ContentTranslator::get_string_table_batch_limit_context();

		$this->assertSame( 400, $context['limit'] );
		$this->assertSame( 'filter', $context['source'] );
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

	public function test_string_batch_retries_empty_item_with_strict_prompt(): void {
		$units = array(
			array(
				'id'          => 'seg_0',
				'source'      => 'gespeicherten Connector.',
				'lookup_keys' => array( 'gespeicherten Connector.' ),
			),
		);

		$responses = array(
			(string) json_encode( array( 'seg_0' => '' ) ),
			(string) json_encode( array( 'seg_0' => 'saved connector.' ) ),
		);
		$call_count = 0;

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpAiClientWithResponseSequence( $responses, $call_count );

		$result = ContentTranslator::translate_string_table_units( $units, 'en', 'de' );

		$this->assertIsArray( $result );
		$this->assertSame( 'saved connector.', $result['gespeicherten Connector.'] );
		$this->assertSame( 2, $call_count );
	}

	public function test_string_batch_splits_after_strict_retry_still_has_empty_item(): void {
		$units = array(
			array(
				'id'          => 'seg_0',
				'source'      => 'Hallo Welt',
				'lookup_keys' => array( 'Hallo Welt' ),
			),
			array(
				'id'          => 'seg_1',
				'source'      => 'gespeicherten Connector.',
				'lookup_keys' => array( 'gespeicherten Connector.' ),
			),
		);

		// New flow: 1 initial batch + 1 targeted retry of [seg_1] (fails) +
		// 1 depth-1 retry of [seg_1] alone (succeeds).
		$responses = array(
			(string) json_encode( array( 'seg_0' => 'Hello world', 'seg_1' => '' ) ),  // initial batch
			(string) json_encode( array( 'seg_0' => 'Hello world', 'seg_1' => '' ) ),  // targeted retry — seg_1 still empty
			(string) json_encode( array( 'seg_1' => 'saved connector.' ) ),             // depth-1 retry for [seg_1]
		);
		$call_count = 0;

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpAiClientWithResponseSequence( $responses, $call_count );

		$result = ContentTranslator::translate_string_table_units( $units, 'en', 'de' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Hello world', $result['Hallo Welt'] );
		$this->assertSame( 'saved connector.', $result['gespeicherten Connector.'] );
		$this->assertSame( 3, $call_count );
	}

	public function test_string_batch_empty_item_unresolved_after_all_retries(): void {
		$units = array(
			array(
				'id'          => 'seg_0',
				'source'      => 'gespeicherten Connector.',
				'lookup_keys' => array( 'gespeicherten Connector.' ),
			),
		);

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpAiClientWithFixedResponse( (string) json_encode( array( 'seg_0' => '' ) ) );

		$result = ContentTranslator::translate_string_table_units( $units, 'en', 'de' );

		$this->assertIsArray( $result );
		// Empty translation must NOT be replaced with source; an empty value is
		// stored so the adapter's language-fallback mechanism can handle display.
		$this->assertSame( '', $result['gespeicherten Connector.'] );
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

	public function test_string_batch_keeps_missing_key_in_source_after_retries(): void {
		$units = array(
			array(
				'id'          => 'seg_0',
				'source'      => 'Hallo',
				'lookup_keys' => array( 'Hallo' ),
			),
			array(
				'id'          => 'seg_1',
				'source'      => 'Welt',
				'lookup_keys' => array( 'Welt' ),
			),
		);

		// Batch returns JSON with seg_0 but misses seg_1 every time.
		$response_json = json_encode( array( 'seg_0' => 'Hello' ) );

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpAiClientWithFixedResponse( $response_json );

		$result = ContentTranslator::translate_string_table_units( $units, 'en', 'de' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Hello', $result['Hallo'] );
		// string_batch_missing_key still uses source as fallback.
		$this->assertSame( 'Welt', $result['Welt'] );
	}

	public function test_string_batch_targeted_retry_resolves_bad_item_without_split(): void {
		$units = array(
			array(
				'id'          => 'seg_0',
				'source'      => 'Hallo Welt',
				'lookup_keys' => array( 'Hallo Welt' ),
			),
			array(
				'id'          => 'seg_1',
				'source'      => 'gespeicherten Connector.',
				'lookup_keys' => array( 'gespeicherten Connector.' ),
			),
		);

		// Call 1: initial batch — seg_1 empty.
		// Call 2: targeted retry of [seg_1] only — succeeds.
		// Good item seg_0 is never re-translated.
		$responses = array(
			(string) json_encode( array( 'seg_0' => 'Hello world', 'seg_1' => '' ) ),
			(string) json_encode( array( 'seg_0' => 'Hello world', 'seg_1' => 'saved connector.' ) ),
		);
		$call_count = 0;

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpAiClientWithResponseSequence( $responses, $call_count );

		$result = ContentTranslator::translate_string_table_units( $units, 'en', 'de' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Hello world', $result['Hallo Welt'] );
		$this->assertSame( 'saved connector.', $result['gespeicherten Connector.'] );
		// Exactly 2 calls: initial + targeted item retry. No split needed.
		$this->assertSame( 2, $call_count );
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

	public function test_string_batch_skips_copy_safe_units_without_sending_them_to_model(): void {
		$units = array(
			array(
				'id'          => 'seg_0',
				'source'      => 'model_slug',
				'lookup_keys' => array( 'model_slug' ),
			),
			array(
				'id'          => 'seg_1',
				'source'      => 'wp_ai_client_prompt()',
				'lookup_keys' => array( 'wp_ai_client_prompt()' ),
			),
			array(
				'id'          => 'seg_2',
				'source'      => 'ai-translate/configure',
				'lookup_keys' => array( 'ai-translate/configure' ),
			),
			array(
				'id'          => 'seg_3',
				'source'      => 'Hallo Welt',
				'lookup_keys' => array( 'Hallo Welt' ),
			),
		);

		$call_inputs = array();

		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function ( string $input_text ) use ( &$call_inputs ) {
				$call_inputs[] = $input_text;

				return new class {
					public function using_system_instruction( string $p ): static { return $this; }
					public function using_temperature( float $t ): static { return $this; }
					public function using_model_preference( string $m ): static { return $this; }
					public function using_max_tokens( int $t ): static { return $this; }
					public function generate_text(): string {
						return '{"seg_3":"Hello world"}';
					}
				};
			}
		);

		$result = ContentTranslator::translate_string_table_units( $units, 'en', 'de' );

		$this->assertIsArray( $result );
		$this->assertSame( 'model_slug', $result['model_slug'] );
		$this->assertSame( 'wp_ai_client_prompt()', $result['wp_ai_client_prompt()'] );
		$this->assertSame( 'ai-translate/configure', $result['ai-translate/configure'] );
		$this->assertSame( 'Hello world', $result['Hallo Welt'] );
		$this->assertCount( 1, $call_inputs );
		$this->assertStringNotContainsString( 'model_slug', $call_inputs[0] );
		$this->assertStringNotContainsString( 'wp_ai_client_prompt()', $call_inputs[0] );
		$this->assertStringNotContainsString( 'ai-translate/configure', $call_inputs[0] );
		$this->assertSame( 3, (int) ( \SlyTranslate\TimingLogger::get_counters()['string_batch_skipped_units'] ?? 0 ) );
	}

	public function test_string_batch_does_not_skip_natural_language_segments_that_look_technical(): void {
		$this->assertSame( '', $this->invokeStatic( ContentTranslator::class, 'get_string_table_copy_safe_reason', array( 'Einstellungen > Verbinder' ) ) );
		$this->assertSame( '', $this->invokeStatic( ContentTranslator::class, 'get_string_table_copy_safe_reason', array( 'Installiere und aktiviere das Plugin.' ) ) );
	}

	// -----------------------------------------------------------------------
	// Context enrichment for short units
	// -----------------------------------------------------------------------

	public function test_enrich_units_with_context_adds_context_to_short_units(): void {
		$units = array(
			array( 'id' => 'seg_0', 'source' => 'Longer source text that exceeds forty characters by a bit.', 'lookup_keys' => array( 'x' ) ),
			array( 'id' => 'seg_1', 'source' => 'short',     'lookup_keys' => array( 'short' ) ),
			array( 'id' => 'seg_2', 'source' => 'also short', 'lookup_keys' => array( 'also short' ) ),
			array( 'id' => 'seg_3', 'source' => 'Another longer source that will exceed forty characters here.', 'lookup_keys' => array( 'y' ) ),
		);

		$enriched = $this->invokeStatic( ContentTranslator::class, 'enrich_units_with_context', array( $units ) );

		// Long unit gets no context.
		$this->assertArrayNotHasKey( 'context_before', $enriched[0] );
		$this->assertArrayNotHasKey( 'context_after',  $enriched[0] );

		// Short unit (seg_1) gets context_after (seg_0 is too long to use as before? no — seg_0 IS its before).
		$this->assertArrayHasKey( 'context_before', $enriched[1] );
		$this->assertSame( $units[0]['source'], $enriched[1]['context_before'] );
		$this->assertArrayHasKey( 'context_after', $enriched[1] );
		$this->assertSame( $units[2]['source'], $enriched[1]['context_after'] );

		// Short unit (seg_2) gets both before and after.
		$this->assertArrayHasKey( 'context_before', $enriched[2] );
		$this->assertSame( $units[1]['source'], $enriched[2]['context_before'] );
		$this->assertArrayHasKey( 'context_after', $enriched[2] );
		$this->assertSame( $units[3]['source'], $enriched[2]['context_after'] );

		// Long unit gets no context.
		$this->assertArrayNotHasKey( 'context_before', $enriched[3] );
		$this->assertArrayNotHasKey( 'context_after',  $enriched[3] );
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
		$call_count = 0;
		$this->stubWpAiClientWithResponseSequence( array( $fixed_response ), $call_count );
	}

	/**
	 * Stub wp_ai_client_prompt to return a sequence of responses.
	 *
	 * @param string[] $responses
	 */
	private function stubWpAiClientWithResponseSequence( array $responses, int &$call_count ): void {
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function () use ( $responses, &$call_count ) {
				$response_index = $call_count;
				$call_count++;
				$response = $responses[ $response_index ] ?? end( $responses );

				return new class( (string) $response ) {
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
