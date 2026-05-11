<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\TranslatePressAdapter;
use SlyTranslate\TranslationQueryService;
use SlyTranslate\WpglobusAdapter;

class SpyTranslatePressLookupAdapter extends TranslatePressAdapter {

	public int $single_lookup_calls = 0;
	public int $full_lookup_calls   = 0;

	public function is_available(): bool {
		return true;
	}

	public function get_languages(): array {
		return array( 'en' => 'English' );
	}

	public function get_post_language( int $post_id ): ?string {
		return 'de';
	}

	public function get_post_translations( int $post_id ): array {
		++$this->full_lookup_calls;
		return array( 'en' => $post_id );
	}

	public function get_post_translation_for_language( int $post_id, string $target_lang ): int {
		++$this->single_lookup_calls;
		return 'en' === $target_lang ? $post_id : 0;
	}

	public function create_translation( int $source_post_id, string $target_lang, array $data ) {
		return $source_post_id;
	}

	public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
		return true;
	}
}

class TranslationQueryServiceTest extends TestCase {

	public function test_get_existing_translation_id_uses_translatepress_single_language_lookup(): void {
		$adapter = new SpyTranslatePressLookupAdapter();

		$this->stubWpFunctionReturn( 'get_post_status', 'publish' );

		$result = TranslationQueryService::get_existing_translation_id( 7, 'en', $adapter );

		$this->assertSame( 7, $result );
		$this->assertSame( 1, $adapter->single_lookup_calls );
		$this->assertSame( 0, $adapter->full_lookup_calls );
	}

	public function test_execute_get_translation_status_uses_wpglobus_language_hint_without_leaking_request_state(): void {
		$_REQUEST = array();

		$this->setStaticProperty( AI_Translate::class, 'adapter', new WpglobusAdapter() );

		$this->stubWpFunctionReturn( 'wpglobus_languages_list', array( 'en', 'de' ) );
		$this->stubWpFunctionReturn( 'wpglobus_default_language', 'en' );
		$this->stubWpFunctionReturn(
			'get_post',
			(object) array(
				'ID'           => 123,
				'post_type'    => 'post',
				'post_title'   => '{:en}Hello{:}{:de}Hallo{:}',
				'post_content' => '{:en}Body{:}{:de}Inhalt{:}',
				'post_excerpt' => '',
			)
		);
		$this->stubWpFunctionReturn( 'current_user_can', true );
		$this->stubWpFunctionReturn( 'post_type_exists', true );
		$this->stubWpFunctionReturn( 'is_post_type_viewable', true );
		$this->stubWpFunctionReturn( 'post_type_supports', true );
		$this->stubWpFunctionReturn( 'get_post_stati', array( 'publish' ) );
		$this->stubWpFunctionReturn( 'get_post_status', 'publish' );

		$result = TranslationQueryService::execute_get_translation_status(
			array(
				'post_id'           => 123,
				'wpglobus_language' => 'de',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'de', $result['source_language'] );
		$this->assertSame( 'Hallo', $result['source_title'] );
		$this->assertArrayNotHasKey( 'wpglobus_language', $_REQUEST );
	}

	public function test_execute_get_translation_status_marks_translatepress_as_single_entry_mode(): void {
		$adapter = new SpyTranslatePressLookupAdapter();

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubWpFunctionReturn(
			'get_post',
			(object) array(
				'ID'           => 7,
				'post_type'    => 'post',
				'post_title'   => 'Titel',
				'post_content' => 'Inhalt',
				'post_excerpt' => '',
			)
		);
		$this->stubWpFunctionReturn( 'current_user_can', true );
		$this->stubWpFunctionReturn( 'post_type_exists', true );
		$this->stubWpFunctionReturn( 'is_post_type_viewable', true );
		$this->stubWpFunctionReturn( 'post_type_supports', true );
		$this->stubWpFunctionReturn( 'get_post_stati', array( 'publish' ) );
		$this->stubWpFunctionReturn( 'get_post_status', 'publish' );

		$result = TranslationQueryService::execute_get_translation_status(
			array(
				'post_id' => 7,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['single_entry_mode'] );
		$this->assertTrue( $result['translations'][0]['exists'] );
		$this->assertSame( 0, $result['translations'][0]['post_id'] );
	}
}