<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\TranslationQueryService;
use Brain\Monkey\Functions;

class TranslationStatusAccessTest extends TestCase {

	public function test_build_translation_status_entry_hides_target_details_without_access(): void {
		$translated_post = new \WP_Post(
			array(
				'ID'          => 42,
				'post_title'  => 'German translation',
				'post_status' => 'draft',
			)
		);

		Functions\when( 'get_post' )->alias(
			static function ( int $post_id ) use ( $translated_post ) {
				return 42 === $post_id ? $translated_post : null;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_edit_post_link' )->alias(
			static function (): string {
				throw new \RuntimeException( 'get_edit_post_link must not be called without access.' );
			}
		);

		$result = $this->invokeStatic( TranslationQueryService::class, 'build_translation_status_entry', array( 'de', 42 ) );

		$this->assertSame( 'de', $result['lang'] );
		$this->assertTrue( $result['exists'] );
		$this->assertSame( 0, $result['post_id'] );
		$this->assertSame( '', $result['title'] );
		$this->assertSame( '', $result['post_status'] );
		$this->assertSame( '', $result['edit_link'] );
	}

	public function test_build_translation_status_entry_keeps_details_when_read_access_exists(): void {
		$translated_post = new \WP_Post(
			array(
				'ID'          => 42,
				'post_title'  => 'German translation',
				'post_status' => 'draft',
			)
		);

		Functions\when( 'get_post' )->alias(
			static function ( int $post_id ) use ( $translated_post ) {
				return 42 === $post_id ? $translated_post : null;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ): bool {
				return 'read_post' === $capability;
			}
		);
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://example.com/wp-admin/post.php?post=42&action=edit' );

		$result = $this->invokeStatic( TranslationQueryService::class, 'build_translation_status_entry', array( 'de', 42 ) );

		$this->assertSame( 42, $result['post_id'] );
		$this->assertSame( 'German translation', $result['title'] );
		$this->assertSame( 'draft', $result['post_status'] );
		$this->assertSame( 'https://example.com/wp-admin/post.php?post=42&action=edit', $result['edit_link'] );
	}
}