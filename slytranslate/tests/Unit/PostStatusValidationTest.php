<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use Brain\Monkey\Functions;

/**
 * Tests for AI_Translate::normalize_translation_post_status().
 *
 * Logic:
 *   1. If $requested_status is a valid, non-protected post status → use it.
 *   2. Else fall back to the source post's own status (same validation).
 *   3. Else return 'draft'.
 *
 * Protected statuses that must never be used: auto-draft, inherit, trash.
 */
class PostStatusValidationTest extends TestCase {

	private function normalize( mixed $requested, \WP_Post $post ): string {
		return $this->invokeStatic(
			AI_Translate::class,
			'normalize_translation_post_status',
			[ $requested, $post ]
		);
	}

	public function test_returns_valid_requested_status(): void {
		Functions\when( 'post_status_exists' )->justReturn( true );

		$post   = new \WP_Post( [ 'post_status' => 'draft' ] );
		$result = $this->normalize( 'publish', $post );
		$this->assertSame( 'publish', $result );
	}

	public function test_returns_draft_as_valid_requested_status(): void {
		Functions\when( 'post_status_exists' )->justReturn( true );

		$post   = new \WP_Post( [ 'post_status' => 'publish' ] );
		$result = $this->normalize( 'draft', $post );
		$this->assertSame( 'draft', $result );
	}

	public function test_rejects_trash_falls_back_to_source(): void {
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'post_status_exists' )->justReturn( true );

		$post   = new \WP_Post( [ 'post_status' => 'publish' ] );
		$result = $this->normalize( 'trash', $post );
		$this->assertSame( 'publish', $result );
	}

	public function test_rejects_auto_draft_falls_back_to_source(): void {
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		Functions\when( 'post_status_exists' )->justReturn( true );

		$post   = new \WP_Post( [ 'post_status' => 'draft' ] );
		$result = $this->normalize( 'auto-draft', $post );
		$this->assertSame( 'draft', $result );
	}

	public function test_rejects_inherit_falls_back_to_source(): void {
		Functions\when( 'get_post_status' )->justReturn( 'pending' );
		Functions\when( 'post_status_exists' )->justReturn( true );

		$post   = new \WP_Post( [ 'post_status' => 'pending' ] );
		$result = $this->normalize( 'inherit', $post );
		$this->assertSame( 'pending', $result );
	}

	public function test_null_requested_falls_back_to_source(): void {
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'post_status_exists' )->justReturn( true );

		$post   = new \WP_Post( [ 'post_status' => 'publish' ] );
		$result = $this->normalize( null, $post );
		$this->assertSame( 'publish', $result );
	}

	public function test_source_trash_falls_back_to_draft(): void {
		Functions\when( 'get_post_status' )->justReturn( 'trash' );
		Functions\when( 'post_status_exists' )->justReturn( true );

		$post   = new \WP_Post( [ 'post_status' => 'trash' ] );
		$result = $this->normalize( 'trash', $post );
		$this->assertSame( 'draft', $result );
	}

	public function test_unregistered_requested_status_falls_back_to_source(): void {
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		// 'nonexistent_status' is unknown; 'publish' is valid — use a callback.
		Functions\when( 'post_status_exists' )->alias(
			static function ( string $status ): bool {
				return 'publish' === $status;
			}
		);

		$post   = new \WP_Post( [ 'post_status' => 'publish' ] );
		$result = $this->normalize( 'nonexistent_status', $post );
		$this->assertSame( 'publish', $result );
	}

	public function test_empty_string_requested_falls_back_to_source(): void {
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		Functions\when( 'post_status_exists' )->justReturn( true );

		$post   = new \WP_Post( [ 'post_status' => 'draft' ] );
		$result = $this->normalize( '', $post );
		$this->assertSame( 'draft', $result );
	}
}
