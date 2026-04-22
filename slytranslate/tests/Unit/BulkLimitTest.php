<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\TranslationQueryService;

/**
 * Tests for AI_Translate::normalize_bulk_limit().
 *
 * Boundary rules:
 *   - Result is always in [1, 50].
 *   - Values < 1 (including 0 and negative) default to 20.
 */
class BulkLimitTest extends TestCase {

	public function test_zero_returns_default_twenty(): void {
		$this->assertSame( 20, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ 0 ] ) );
	}

	public function test_negative_value_uses_absolute_value(): void {
		// absint(-5) = 5, which is >= 1, so no default is applied.
		$this->assertSame( 5, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ -5 ] ) );
	}

	public function test_large_negative_is_clamped_to_max(): void {
		// absint(-100) = 100, clamped to 50.
		$this->assertSame( 50, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ -100 ] ) );
	}

	public function test_one_returns_one(): void {
		$this->assertSame( 1, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ 1 ] ) );
	}

	public function test_twenty_returns_twenty(): void {
		$this->assertSame( 20, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ 20 ] ) );
	}

	public function test_fifty_returns_fifty(): void {
		$this->assertSame( 50, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ 50 ] ) );
	}

	public function test_above_fifty_is_clamped_to_fifty(): void {
		$this->assertSame( 50, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ 51 ] ) );
		$this->assertSame( 50, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ 100 ] ) );
		$this->assertSame( 50, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ 9999 ] ) );
	}

	public function test_string_integer_is_cast_correctly(): void {
		$this->assertSame( 25, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ '25' ] ) );
	}

	public function test_float_is_truncated(): void {
		$this->assertSame( 10, $this->invokeStatic( TranslationQueryService::class, 'normalize_limit', [ 10.9 ] ) );
	}
}
