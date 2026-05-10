<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\PostTranslationService;
use SlyTranslate\StringTableContentAdapter;
use SlyTranslate\TimingLogger;
use SlyTranslate\TranslationPluginAdapter;
use SlyTranslate\TranslationRuntime;

class PostTranslationStringTableFastPathTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setStaticProperty( TranslationRuntime::class, 'chunk_char_limit_cache', null );
		$this->setStaticProperty( TranslationRuntime::class, 'context', array(
			'service_slug'   => '',
			'model_slug'     => 'Ministral-8B-Instruct-2410-Q4_K_M',
			'direct_api_url' => '',
		) );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		TimingLogger::reset_counters();
	}

	public function test_translate_post_batches_title_into_string_table_fast_path(): void {
		$payloads    = array();
		$call_inputs = array();
		$adapter     = new class( $payloads ) implements TranslationPluginAdapter, StringTableContentAdapter {
			public array $payloads;

			public function __construct( array &$payloads ) {
				$this->payloads = &$payloads;
			}

			public function is_available(): bool { return true; }
			public function get_languages(): array { return array( 'de' => 'Deutsch' ); }
			public function get_post_language( int $post_id ): ?string { return 'en'; }
			public function get_post_translations( int $post_id ): array { return array(); }
			public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool { return true; }
			public function supports_pretranslated_content_pairs(): bool { return true; }

			public function build_content_translation_units( string $source_content ): array {
				return array(
					array(
						'id'          => 'seg_0',
						'source'      => 'Hello world',
						'lookup_keys' => array( 'Hello world' ),
					),
				);
			}

			public function create_translation( int $source_post_id, string $target_lang, array $data ) {
				$this->payloads[] = $data;
				return 145;
			}
		};

		$this->stubTranslatePostEnvironment( 'Short title', 'Hello world' );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function ( string $input_text ) use ( &$call_inputs ) {
				$call_inputs[] = $input_text;

				return new class {
					public function using_system_instruction( string $prompt ): static { return $this; }
					public function using_temperature( float $temperature ): static { return $this; }
					public function using_model_preference( string $model_slug ): static { return $this; }
					public function using_max_tokens( int $max_tokens ): static { return $this; }
					public function generate_text(): string {
						return '{"__slytranslate_title":"Kurzer Titel","seg_0":"Hallo Welt"}';
					}
				};
			}
		);

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );

		$result = AI_Translate::translate_post( 45, 'de', 'draft', false, true, '', 'en' );

		$this->assertSame( 145, $result );
		$this->assertCount( 1, $call_inputs );
		$this->assertStringContainsString( '__slytranslate_title', $call_inputs[0] );
		$this->assertSame( 'Kurzer Titel', $payloads[0]['post_title'] );
		$this->assertSame( 'Hallo Welt', $payloads[0]['content_string_pairs']['Hello world'] );
		$this->assertSame( 'Kurzer Titel', $payloads[0]['content_string_pairs']['Short title'] );
	}

	public function test_translate_post_falls_back_to_separate_title_when_batched_title_missing(): void {
		$payloads    = array();
		$call_inputs = array();
		$call_count  = 0;
		$adapter     = new class( $payloads ) implements TranslationPluginAdapter, StringTableContentAdapter {
			public array $payloads;

			public function __construct( array &$payloads ) {
				$this->payloads = &$payloads;
			}

			public function is_available(): bool { return true; }
			public function get_languages(): array { return array( 'de' => 'Deutsch' ); }
			public function get_post_language( int $post_id ): ?string { return 'en'; }
			public function get_post_translations( int $post_id ): array { return array(); }
			public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool { return true; }
			public function supports_pretranslated_content_pairs(): bool { return true; }

			public function build_content_translation_units( string $source_content ): array {
				return array(
					array(
						'id'          => 'seg_0',
						'source'      => 'Hello world',
						'lookup_keys' => array( 'Hello world' ),
					),
				);
			}

			public function create_translation( int $source_post_id, string $target_lang, array $data ) {
				$this->payloads[] = $data;
				return 145;
			}
		};

		$this->stubTranslatePostEnvironment( 'Short title', 'Hello world' );
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function ( string $input_text ) use ( &$call_inputs, &$call_count ) {
				$call_inputs[] = $input_text;
				$call_count++;

				return new class( $call_count ) {
					private int $call_count;

					public function __construct( int $call_count ) {
						$this->call_count = $call_count;
					}

					public function using_system_instruction( string $prompt ): static { return $this; }
					public function using_temperature( float $temperature ): static { return $this; }
					public function using_model_preference( string $model_slug ): static { return $this; }
					public function using_max_tokens( int $max_tokens ): static { return $this; }

					public function generate_text(): string {
						if ( $this->call_count <= 3 ) {
							return '{"seg_0":"Hallo Welt"}';
						}

						return 'Kurzer Titel';
					}
				};
			}
		);

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );

		$result = AI_Translate::translate_post( 45, 'de', 'draft', false, true, '', 'en' );

		$this->assertSame( 145, $result );
		$this->assertCount( 4, $call_inputs );
		$this->assertSame( 'Kurzer Titel', $payloads[0]['post_title'] );
		$this->assertSame( 2, (int) ( TimingLogger::get_counters()['fallbacks'] ?? 0 ) );
	}

	private function stubTranslatePostEnvironment( string $title, string $content ): void {
		$source_post = new \WP_Post(
			array(
				'ID'           => 45,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => '',
			)
		);

		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );
		$this->stubWpFunctionReturn( 'current_user_can', true );
		$this->stubWpFunctionReturn( 'post_type_exists', true );
		$this->stubWpFunctionReturn( 'get_post_status_object', (object) array( 'name' => 'draft' ) );
		$this->stubWpFunction( 'get_post_status', static fn( $post = null ) => 'draft' );
		$this->stubWpFunction(
			'get_post',
			static function ( $post_id ) use ( $source_post ) {
				return 45 === (int) $post_id ? $source_post : null;
			}
		);
		$this->stubWpFunctionReturn( 'get_post_meta', array() );
		$this->stubWpFunction(
			'get_option',
			static function ( $option, $default = false ) {
				if ( 'slytranslate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		$this->stubWpFunctionReturn( 'get_transient', false );
		$this->stubWpFunctionReturn( 'set_transient', true );
		$this->stubWpFunctionReturn( 'delete_transient', true );
	}
}