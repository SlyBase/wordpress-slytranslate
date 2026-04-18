<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use Brain\Monkey\Functions;

class TranslationProgressTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'translation_runtime_context', null );
		$this->setStaticProperty( AI_Translate::class, 'translation_progress_context', null );

		parent::tearDown();
	}

	public function test_get_translation_progress_returns_default_payload_without_saved_state(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 17 );
		Functions\when( 'get_transient' )->justReturn( false );

		$result = $this->invokeStatic( AI_Translate::class, 'get_translation_progress' );

		$this->assertSame(
			array(
				'phase'         => '',
				'current_chunk' => 0,
				'total_chunks'  => 0,
				'percent'       => 0,
			),
			$result
		);
	}

	public function test_translate_with_chunk_limit_updates_progress_after_each_content_chunk(): void {
		$text            = implode( "\n\n", array_fill( 0, 4, str_repeat( 'word ', 80 ) ) );
		$chunk_char_limit = 1200;
		$chunks          = $this->invokeStatic( AI_Translate::class, 'split_text_for_translation', array( $text, $chunk_char_limit ) );
		$chunk_count     = count( $chunks );
		$progress_calls  = array();

		$this->assertGreaterThan( 1, $chunk_count );

		Functions\when( 'get_current_user_id' )->justReturn( 17 );
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$progress_calls ) {
				if ( is_string( $key ) && str_starts_with( $key, 'ai_translate_progress_' ) ) {
					$progress_calls[] = $value;
				}

				return true;
			}
		);
		Functions\when( 'wp_ai_client_prompt' )->alias(
			static function ( $input_text ) {
				return new class( $input_text ) {
					private string $input_text;

					public function __construct( string $input_text ) {
						$this->input_text = $input_text;
					}

					public function using_system_instruction( string $prompt ) {
						return $this;
					}

					public function using_temperature( int $temperature ) {
						return $this;
					}

					public function generate_text(): string {
						return $this->input_text;
					}
				};
			}
		);

		$this->setStaticProperty(
			AI_Translate::class,
			'translation_progress_context',
			array(
				'phase'                    => '',
				'total_steps'              => $chunk_count,
				'completed_steps'          => 0,
				'content_total_chunks'     => $chunk_count,
				'content_completed_chunks' => 0,
			)
		);

		$this->invokeStatic( AI_Translate::class, 'mark_translation_phase', array( 'content' ) );
		$result = $this->invokeStatic( AI_Translate::class, 'translate_with_chunk_limit', array( $text, 'Prompt', $chunk_char_limit ) );

		$this->assertSame( $text, $result );

		$content_progress_calls = array_values(
			array_filter(
				$progress_calls,
				static function ( $progress ) {
					return is_array( $progress )
						&& ( $progress['phase'] ?? '' ) === 'content'
						&& (int) ( $progress['current_chunk'] ?? 0 ) > 0;
				}
			)
		);

		$this->assertCount( $chunk_count, $content_progress_calls );
		$this->assertSame( range( 1, $chunk_count ), array_column( $content_progress_calls, 'current_chunk' ) );
		$this->assertSame( array_fill( 0, $chunk_count, $chunk_count ), array_column( $content_progress_calls, 'total_chunks' ) );
		$this->assertSame(
			array_map(
				static function ( int $chunk_index ) use ( $chunk_count ): int {
					return (int) round( ( $chunk_index / $chunk_count ) * 100 );
				},
				range( 1, $chunk_count )
			),
			array_column( $content_progress_calls, 'percent' )
		);
	}
}