<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;

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
}