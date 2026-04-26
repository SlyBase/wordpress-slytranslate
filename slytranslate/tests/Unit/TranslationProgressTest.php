<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\TextSplitter;
use AI_Translate\MetaTranslationService;
use AI_Translate\TranslationProgressTracker;
use AI_Translate\TranslationPluginAdapter;
use AI_Translate\TranslationRuntime;

class TranslationProgressTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		MetaTranslationService::reset_cache();
		$this->setStaticProperty( TranslationRuntime::class, 'context', null );
		$this->setStaticProperty( TranslationProgressTracker::class, 'context', null );

		parent::tearDown();
	}

	public function test_get_translation_progress_returns_default_payload_without_saved_state(): void {
		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );
		$this->stubWpFunctionReturn( 'get_transient', false );

		$result = $this->invokeStatic( TranslationProgressTracker::class, 'get_progress' );

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
		$chunks          = $this->invokeStatic( TextSplitter::class, 'split_text_for_translation', array( $text, $chunk_char_limit ) );
		$chunk_count     = count( $chunks );
		$progress_calls  = array();

		$this->assertGreaterThan( 1, $chunk_count );

		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );
		$this->stubWpFunction( 'get_option',
			static function ( $option, $default = false ) {
				return $default;
			}
		);
		$this->stubWpFunctionReturn( 'get_transient', false );
		$this->stubWpFunction( 'set_transient',
			static function ( $key, $value, $ttl ) use ( &$progress_calls ) {
				if ( is_string( $key ) && str_starts_with( $key, 'ai_translate_progress_' ) ) {
					$progress_calls[] = $value;
				}

				return true;
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( $input_text ) {
				return new class( $input_text ) {
					private string $input_text;

					public function __construct( string $input_text ) {
						$this->input_text = $input_text;
					}

					public function using_system_instruction( string $prompt ) {
						return $this;
					}

					public function using_temperature( float $temperature ) {
						return $this;
					}

					public function generate_text(): string {
						return $this->input_text;
					}
				};
			}
		);

		// Pre-register a content budget that matches the upcoming work so the
		// percentage maths can verify monotonic, char-weighted progress.
		$content_budget = mb_strlen( $text, 'UTF-8' );
		$this->setStaticProperty(
			TranslationProgressTracker::class,
			'context',
			array(
				'phase'           => '',
				'post_id'         => 0,
				'phase_budgets'   => array( 'content' => $content_budget ),
				'phase_completed' => array(),
				'total_units'     => $content_budget,
				'completed_units' => 0,
			)
		);

		$this->invokeStatic( TranslationProgressTracker::class, 'mark_phase', array( 'content' ) );
		$result = $this->invokeStatic( TranslationRuntime::class, 'translate_with_chunk_limit', array( $text, 'Prompt', $chunk_char_limit ) );

		$this->assertSame( $text, $result );

		$content_progress_calls = array_values(
			array_filter(
				$progress_calls,
				static function ( $progress ) {
					return is_array( $progress )
						&& ( $progress['phase'] ?? '' ) === 'content'
						&& (int) ( $progress['percent'] ?? 0 ) > 0;
				}
			)
		);

		// One progress write per chunk (advance_units triggers set_progress
		// for the active phase).
		$this->assertCount( $chunk_count, $content_progress_calls );

		// Percentages must be strictly monotonic and the final write must be
		// 100 % once every chunk is credited (because the chunk char totals
		// equal the registered budget).
		$percents = array_column( $content_progress_calls, 'percent' );
		$this->assertSame( $percents, array_values( array_unique( $percents ) ) );
		for ( $i = 1; $i < count( $percents ); $i++ ) {
			$this->assertGreaterThan( $percents[ $i - 1 ], $percents[ $i ] );
		}
		$this->assertSame( 100, end( $percents ) );
	}

	public function test_translate_post_clears_per_post_progress_after_success(): void {
		$transients = array();
		$source_post = new \WP_Post(
			array(
				'ID'           => 42,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_content' => '',
				'post_excerpt' => '',
			)
		);

		$adapter = new class implements TranslationPluginAdapter {
			public function is_available(): bool {
				return true;
			}

			public function get_languages(): array {
				return array(
					'en' => 'English',
					'de' => 'Deutsch',
				);
			}

			public function get_post_language( int $post_id ): ?string {
				return 42 === $post_id ? 'en' : null;
			}

			public function get_post_translations( int $post_id ): array {
				return array();
			}

			public function create_translation( int $source_post_id, string $target_lang, array $data ) {
				return 84;
			}

			public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
				return true;
			}
		};

		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );
		$this->stubWpFunctionReturn( 'current_user_can', true );
		$this->stubWpFunctionReturn( 'post_type_exists', true );
		$this->stubWpFunction( 'get_post',
			static function ( $post_id ) use ( $source_post ) {
				return 42 === (int) $post_id ? $source_post : null;
			}
		);
		$this->stubWpFunctionReturn( 'get_post_meta', array() );
		$this->stubWpFunction( 'get_transient',
			static function ( $key ) use ( &$transients ) {
				return $transients[ $key ] ?? false;
			}
		);
		$this->stubWpFunction( 'set_transient',
			static function ( $key, $value ) use ( &$transients ) {
				$transients[ $key ] = $value;
				return true;
			}
		);
		$this->stubWpFunction( 'delete_transient',
			static function ( $key ) use ( &$transients ) {
				unset( $transients[ $key ] );
				return true;
			}
		);

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );

		$result = AI_Translate::translate_post( 42, 'de', 'draft', false, false );

		$this->assertSame( 84, $result );
		$this->assertArrayNotHasKey( 'ai_translate_progress_17_42', $transients );
		$this->assertSame(
			array(
				'phase'         => '',
				'current_chunk' => 0,
				'total_chunks'  => 0,
				'percent'       => 0,
			),
			TranslationProgressTracker::get_progress( 42 )
		);
	}
}