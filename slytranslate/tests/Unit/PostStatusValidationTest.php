<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\PostTranslationService;

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
			PostTranslationService::class,
			'normalize_post_status',
			[ $requested, $post ]
		);
	}

	public function test_returns_valid_requested_status(): void {
		$this->stubWpFunctionReturn( 'post_status_exists', true );

		$post   = new \WP_Post( [ 'post_status' => 'draft' ] );
		$result = $this->normalize( 'publish', $post );
		$this->assertSame( 'publish', $result );
	}

	public function test_returns_draft_as_valid_requested_status(): void {
		$this->stubWpFunctionReturn( 'post_status_exists', true );

		$post   = new \WP_Post( [ 'post_status' => 'publish' ] );
		$result = $this->normalize( 'draft', $post );
		$this->assertSame( 'draft', $result );
	}

	public function test_rejects_trash_falls_back_to_source(): void {
		$this->stubWpFunctionReturn( 'get_post_status', 'publish' );
		$this->stubWpFunctionReturn( 'post_status_exists', true );

		$post   = new \WP_Post( [ 'post_status' => 'publish' ] );
		$result = $this->normalize( 'trash', $post );
		$this->assertSame( 'publish', $result );
	}

	public function test_rejects_auto_draft_falls_back_to_source(): void {
		$this->stubWpFunctionReturn( 'get_post_status', 'draft' );
		$this->stubWpFunctionReturn( 'post_status_exists', true );

		$post   = new \WP_Post( [ 'post_status' => 'draft' ] );
		$result = $this->normalize( 'auto-draft', $post );
		$this->assertSame( 'draft', $result );
	}

	public function test_rejects_inherit_falls_back_to_source(): void {
		$this->stubWpFunctionReturn( 'get_post_status', 'pending' );
		$this->stubWpFunctionReturn( 'post_status_exists', true );

		$post   = new \WP_Post( [ 'post_status' => 'pending' ] );
		$result = $this->normalize( 'inherit', $post );
		$this->assertSame( 'pending', $result );
	}

	public function test_null_requested_falls_back_to_source(): void {
		$this->stubWpFunctionReturn( 'get_post_status', 'publish' );
		$this->stubWpFunctionReturn( 'post_status_exists', true );

		$post   = new \WP_Post( [ 'post_status' => 'publish' ] );
		$result = $this->normalize( null, $post );
		$this->assertSame( 'publish', $result );
	}

	public function test_source_trash_falls_back_to_draft(): void {
		$this->stubWpFunctionReturn( 'get_post_status', 'trash' );
		$this->stubWpFunctionReturn( 'post_status_exists', true );

		$post   = new \WP_Post( [ 'post_status' => 'trash' ] );
		$result = $this->normalize( 'trash', $post );
		$this->assertSame( 'draft', $result );
	}

	public function test_unregistered_requested_status_falls_back_to_source(): void {
		$this->stubWpFunctionReturn( 'get_post_status', 'publish' );
		// 'nonexistent_status' is unknown; 'publish' is valid — use a callback.
		$this->stubWpFunction( 'post_status_exists',
			static function ( string $status ): bool {
				return 'publish' === $status;
			}
		);

		$post   = new \WP_Post( [ 'post_status' => 'publish' ] );
		$result = $this->normalize( 'nonexistent_status', $post );
		$this->assertSame( 'publish', $result );
	}

	public function test_empty_string_requested_falls_back_to_source(): void {
		$this->stubWpFunctionReturn( 'get_post_status', 'draft' );
		$this->stubWpFunctionReturn( 'post_status_exists', true );

		$post   = new \WP_Post( [ 'post_status' => 'draft' ] );
		$result = $this->normalize( '', $post );
		$this->assertSame( 'draft', $result );
	}
}
