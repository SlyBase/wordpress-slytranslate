<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\TranslatePressAdapter;
use SlyTranslate\TranslationQueryService;

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
}