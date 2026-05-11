<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\WpglobusAdapter;

class AbilityInputValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

	public function test_execute_translate_text_rejects_missing_text(): void {
		$deleted_key = null;

		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );
		$this->stubWpFunction(
			'delete_transient',
			static function ( string $transient_key ) use ( &$deleted_key ): bool {
				$deleted_key = $transient_key;
				return true;
			}
		);

		$result = AI_Translate::execute_translate_text(
			array(
				'text'            => array( 'not-a-string' ),
				'source_language' => 'en',
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_text', $result->get_error_code() );
		$this->assertSame( 'slytranslate_cancel_17', $deleted_key );
	}

	public function test_execute_translate_blocks_clears_stale_cancel_flag_before_validating_input(): void {
		$deleted_key = null;

		$this->stubWpFunctionReturn( 'get_current_user_id', 23 );
		$this->stubWpFunction(
			'delete_transient',
			static function ( string $transient_key ) use ( &$deleted_key ): bool {
				$deleted_key = $transient_key;
				return true;
			}
		);

		$result = AI_Translate::execute_translate_blocks(
			array(
				'content'         => array( 'not-a-string' ),
				'source_language' => 'en',
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_content', $result->get_error_code() );
		$this->assertSame( 'slytranslate_cancel_23', $deleted_key );
	}

	public function test_execute_translate_content_rejects_invalid_post_id(): void {
		$result = AI_Translate::execute_translate_content(
			array(
				'post_id'         => 'abc',
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_post_id', $result->get_error_code() );
	}

	public function test_execute_translate_posts_rejects_missing_target_language(): void {
		$result = AI_Translate::execute_translate_posts(
			array(
				'post_ids' => array( 1, 2, 3 ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_target_language', $result->get_error_code() );
	}

	public function test_execute_translate_posts_rejects_missing_post_selection(): void {
		$adapter = new class() implements \SlyTranslate\TranslationPluginAdapter {
			public function is_available(): bool {
				return true;
			}

			public function get_languages(): array {
				return array( 'de' => 'Deutsch' );
			}

			public function get_post_language( int $post_id ): ?string {
				return 'en';
			}

			public function get_post_translations( int $post_id ): array {
				return array();
			}

			public function create_translation( int $source_post_id, string $target_lang, array $data ) {
				return 0;
			}

			public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
				return true;
			}
		};

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );

		$result = AI_Translate::execute_translate_posts(
			array(
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_post_selection', $result->get_error_code() );
	}

	public function test_execute_set_post_language_rejects_invalid_post_id(): void {
		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id'         => 'abc',
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_post_id', $result->get_error_code() );
	}

	public function test_execute_set_post_language_rejects_missing_target_language(): void {
		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id' => 10,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_target_language', $result->get_error_code() );
	}

	public function test_execute_translate_posts_preserves_explicit_source_language_for_single_entry_adapters(): void {
		$recorded_payloads = array();

		$adapter = new class( $recorded_payloads ) extends WpglobusAdapter {
			public array $recorded_payloads;

			public function __construct( array &$recorded_payloads ) {
				$this->recorded_payloads = &$recorded_payloads;
			}

			public function is_available(): bool {
				return true;
			}

			public function get_languages(): array {
				return array(
					'en' => 'English',
					'de' => 'Deutsch',
					'fr' => 'Francais',
				);
			}

			public function get_post_language( int $post_id ): ?string {
				return 'en';
			}

			public function get_post_translations( int $post_id ): array {
				return array();
			}

			public function get_language_variant( string $value, string $language_code ): string {
				return $value . ' [' . $language_code . ']';
			}

			public function create_translation( int $source_post_id, string $target_lang, array $data ) {
				$this->recorded_payloads[] = $data;
				return 91;
			}

			public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
				return true;
			}
		};

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );

		$source_post = new \WP_Post(
			array(
				'ID'           => 45,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'Titel',
				'post_content' => 'Inhalt',
				'post_excerpt' => '',
			)
		);

		$this->stubWpFunctionReturn( 'current_user_can', true );
		$this->stubWpFunctionReturn( 'post_type_exists', true );
		$this->stubWpFunctionReturn( 'is_post_type_viewable', true );
		$this->stubWpFunctionReturn( 'post_type_supports', true );
		$this->stubWpFunctionReturn( 'get_post_meta', array() );
		$this->stubWpFunctionReturn( 'get_post_stati', array( 'draft' ) );
		$this->stubWpFunctionReturn( 'get_post_status_object', (object) array( 'name' => 'draft' ) );
		$this->stubWpFunctionReturn( 'get_transient', false );
		$this->stubWpFunctionReturn( 'set_transient', true );
		$this->stubWpFunctionReturn( 'delete_transient', true );
		$this->stubWpFunction(
			'get_post',
			static function ( $post_id ) use ( $source_post ) {
				return 45 === (int) $post_id ? $source_post : null;
			}
		);
		$this->stubWpFunction(
			'get_post_status',
			static function ( $post = null ) {
				return false === $post ? false : 'draft';
			}
		);
		$this->stubWpFunction(
			'get_edit_post_link',
			static function ( int $post_id ): string {
				return 'edit-' . $post_id;
			}
		);
		$this->stubWpFunction(
			'get_option',
			static function ( string $option, $default = false ) {
				if ( 'slytranslate_direct_api_kwargs_detected' === $option ) {
					return '0';
				}

				return $default;
			}
		);
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function ( string $input_text ) {
				return new class {
					public function using_system_instruction( string $prompt ): static { return $this; }
					public function using_temperature( float $temperature ): static { return $this; }
					public function using_model_preference( string $model_slug ): static { return $this; }
					public function using_max_tokens( int $max_tokens ): static { return $this; }
					public function generate_text(): string { return 'Translated'; }
				};
			}
		);

		$result = AI_Translate::execute_translate_posts(
			array(
				'post_ids'        => array( 45 ),
				'source_language' => 'de',
				'target_language' => 'fr',
				'translate_title' => false,
				'overwrite'       => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['results'][0]['status'] );
		$this->assertSame( 'de', $adapter->recorded_payloads[0]['source_language'] );
	}
}