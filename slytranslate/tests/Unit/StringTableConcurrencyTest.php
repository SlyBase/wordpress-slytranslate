<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\ConfigurationService;
use SlyTranslate\ContentTranslator;
use SlyTranslate\TranslationRuntime;

class StringTableConcurrencyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'Ministral-8B-Instruct-2410-Q4_K_M',
			'direct_api_url' => '',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', 50000 );
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );
	}

	public function test_probe_recommends_one_when_level_two_has_no_speedup(): void {
		$stored_options = array();
		$this->stubWpFunction( 'get_option', static function ( $option, $default = false ) use ( &$stored_options ) {
			return $stored_options[ $option ] ?? $default;
		} );
		$this->stubWpFunction( 'update_option', static function ( $option, $value ) use ( &$stored_options ) {
			$stored_options[ $option ] = $value;
			return true;
		} );
		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );
		$this->stubWpFunctionReturn( 'set_transient', true );
		$this->stubWpFunctionReturn( 'delete_transient', true );
		$this->stubWpFunctionReturn( 'get_transient', array( 'user_id' => 17 ) );
		$this->stubWpFunction(
			'apply_filters',
			static function ( $hook_name, $value, ...$args ) {
				if ( 'slytranslate_string_table_parallel_http_runner' === $hook_name ) {
					return static function ( array $requests, array $options = array() ) {
						usleep( 10000 * count( $requests ) );
						$responses = array();
						foreach ( $requests as $key => $request ) {
							$responses[ $key ] = array(
								'status' => 200,
								'body'   => '{"ok":true}',
							);
						}
						return $responses;
					};
				}

				return $value;
			}
		);

		$result = ConfigurationService::probe_string_table_concurrency( 'Ministral-8B-Instruct-2410-Q4_K_M', 4 );

		$this->assertTrue( $result['supported'] );
		$this->assertSame( 1, $result['recommended'] );
	}

	public function test_probe_recommends_two_when_level_three_errors(): void {
		$stored_options = array();
		$this->stubWpFunction( 'get_option', static function ( $option, $default = false ) use ( &$stored_options ) {
			return $stored_options[ $option ] ?? $default;
		} );
		$this->stubWpFunction( 'update_option', static function ( $option, $value ) use ( &$stored_options ) {
			$stored_options[ $option ] = $value;
			return true;
		} );
		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );
		$this->stubWpFunctionReturn( 'set_transient', true );
		$this->stubWpFunctionReturn( 'delete_transient', true );
		$this->stubWpFunctionReturn( 'get_transient', array( 'user_id' => 17 ) );
		$this->stubWpFunction(
			'apply_filters',
			static function ( $hook_name, $value, ...$args ) {
				if ( 'slytranslate_string_table_parallel_http_runner' === $hook_name ) {
					return static function ( array $requests, array $options = array() ) {
						$count = count( $requests );
						usleep( 10000 );
						$responses = array();
						foreach ( $requests as $key => $request ) {
							$responses[ $key ] = array(
								'status' => $count >= 3 ? 500 : 200,
								'body'   => $count >= 3 ? '{"ok":false,"error_code":"timeout"}' : '{"ok":true}',
							);
						}
						return $responses;
					};
				}

				return $value;
			}
		);

		$result = ConfigurationService::probe_string_table_concurrency( 'Ministral-8B-Instruct-2410-Q4_K_M', 4 );

		$this->assertSame( 2, $result['recommended'] );
	}

	public function test_string_table_worker_rejects_invalid_token(): void {
		$this->stubWpFunctionReturn( 'get_transient', false );

		$result = AI_Translate::execute_string_table_worker( array(
			'action' => 'probe',
			'token'  => 'invalid',
		) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_probe_token', $result->get_error_code() );
	}

	public function test_translate_string_table_units_uses_parallel_windows_when_supported(): void {
		$stored_options = array(
			'slytranslate_string_table_concurrency' => 2,
			'slytranslate_string_table_concurrency_recommendations' => array(
				'ministral-8b-instruct-2410-q4_k_m' => array(
					'recommended' => 2,
					'supported'   => true,
					'transport'   => 'filtered_runner',
					'measured_at' => time(),
					'levels'      => array(),
				),
			),
		);
		$transients = array();

		$this->stubWpFunction( 'get_option', static function ( $option, $default = false ) use ( &$stored_options ) {
			return $stored_options[ $option ] ?? $default;
		} );
		$this->stubWpFunction( 'update_option', static function ( $option, $value ) use ( &$stored_options ) {
			$stored_options[ $option ] = $value;
			return true;
		} );
		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );
		$this->stubWpFunction( 'set_transient', static function ( $key, $value ) use ( &$transients ) {
			$transients[ $key ] = $value;
			return true;
		} );
		$this->stubWpFunction( 'get_transient', static function ( $key ) use ( &$transients ) {
			return $transients[ $key ] ?? false;
		} );
		$this->stubWpFunction( 'delete_transient', static function ( $key ) use ( &$transients ) {
			unset( $transients[ $key ] );
			return true;
		} );
		$this->stubWpFunction( 'wp_json_encode', static fn( $val, $flags = 0 ) => json_encode( $val, $flags ) );
		$this->stubWpFunction(
			'apply_filters',
			static function ( $hook_name, $value, ...$args ) {
				if ( 'slytranslate_string_table_parallel_http_runner' === $hook_name ) {
					return static function ( array $requests, array $options = array() ) {
						$responses = array();
						foreach ( array_reverse( $requests, true ) as $key => $request ) {
							$payload = json_decode( (string) $request['data'], true );
							$result  = array();
							foreach ( $payload['batch'] as $unit ) {
								$result[ $unit['id'] ] = 'EN:' . $unit['source'];
							}
							$responses[ $key ] = array(
								'status' => 200,
								'body'   => json_encode( array(
									'ok'          => true,
									'batch_index' => $payload['batch_index'],
									'duration_ms' => 5,
									'result'      => $result,
								) ),
							);
						}
						return $responses;
					};
				}

				return $value;
			}
		);

		$units = array();
		for ( $index = 0; $index < 30; $index++ ) {
			$units[] = array(
				'id'          => 'seg_' . $index,
				'source'      => 'Hallo ' . $index . ' ' . str_repeat( 'x', 70 ),
				'lookup_keys' => array( 'Hallo ' . $index . ' ' . str_repeat( 'x', 70 ) ),
			);
		}

		$result = ContentTranslator::translate_string_table_units( $units, 'en', 'de' );

		$this->assertIsArray( $result );
		$this->assertSame( 2, (int) ( \SlyTranslate\TimingLogger::get_counters()['string_batch_parallel_windows'] ?? 0 ) );
		$this->assertStringStartsWith( 'EN:Hallo 0', reset( $result ) );
	}
}