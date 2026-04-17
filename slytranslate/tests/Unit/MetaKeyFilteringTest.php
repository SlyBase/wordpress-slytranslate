<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for internal WordPress meta key filtering in translate_post() (Fix 1.2).
 *
 * Internal WP meta keys (_edit_lock, _edit_last, _wp_old_slug, etc.) must
 * NOT be copied to the translated post, even when they appear in get_post_meta().
 *
 * @see Plan Phase 1.2
 */
class MetaKeyFilteringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// These tests require Fix 1.2 to be implemented. Until then, skip.
		$this->markTestSkipped(
			'Meta denylist (Fix 1.2) has not been implemented yet. ' .
			'Add the denylist for internal WP keys in translate_post() first.'
		);
	}

	#[DataProvider( 'provideInternalMetaKeys' )]
	public function test_internal_meta_keys_are_excluded( string $key ): void {
		Functions\when( 'get_post_meta' )->justReturn( [ $key => [ 'some_value' ] ] );
		Functions\when( 'maybe_unserialize' )->alias( fn( $v ) => $v );

		$result = $this->invokeStatic( AI_Translate::class, 'filter_translate_post_meta', [ [ $key => 'some_value' ] ] );
		$this->assertArrayNotHasKey( $key, $result );
	}

/** @return array<string, array{string}> */
public static function provideInternalMetaKeys(): array {
return [
'_edit_lock'             => [ '_edit_lock' ],
'_edit_last'             => [ '_edit_last' ],
'_wp_old_slug'           => [ '_wp_old_slug' ],
'_wp_trash_meta_status'  => [ '_wp_trash_meta_status' ],
'_wp_trash_meta_time'    => [ '_wp_trash_meta_time' ],
'_encloseme'             => [ '_encloseme' ],
'_pingme'                => [ '_pingme' ],
];
}

public function test_non_internal_custom_meta_is_kept(): void {
$result = $this->invokeStatic(
AI_Translate::class,
'filter_translate_post_meta',
[ [ '_custom_field' => 'custom_value' ] ]
);
$this->assertArrayHasKey( '_custom_field', $result );
}
}
