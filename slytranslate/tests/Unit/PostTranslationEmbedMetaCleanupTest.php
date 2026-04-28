<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\PostTranslationService;

class PostTranslationEmbedMetaCleanupTest extends TestCase {

	public function test_clear_embed_cache_meta_removes_oembed_keys_only(): void {
		$deleted = array();

		$this->stubWpFunction(
			'get_post_meta',
			static function ( int $post_id, ...$args ) {
				return array(
					'_oembed_abc123'      => array( '<iframe>' ),
					'_oembed_time_abc123' => array( 'https://wordpress.org/' ),
					'_custom_meta'        => array( 'keep' ),
				);
			}
		);
		$this->stubWpFunction(
			'delete_post_meta',
			static function ( int $post_id, string $meta_key, $meta_value = '' ) use ( &$deleted ) {
				$deleted[] = $meta_key;
				return true;
			}
		);

		$this->invokeStatic( PostTranslationService::class, 'clear_embed_cache_meta', array( 1111 ) );

		sort( $deleted );
		$this->assertSame( array( '_oembed_abc123', '_oembed_time_abc123' ), $deleted );
	}
}
