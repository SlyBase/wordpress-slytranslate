<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for SeoPluginDetector::normalize_meta_keys().
 *
 * The method is the canonical implementation shared across the plugin
 * (AI_Translate delegates to it for all meta-key normalization).
 */
class NormalizeMetaKeysTest extends TestCase {

	#[DataProvider( 'provideClasses' )]
	public function test_returns_empty_array_for_non_array_input( string $class ): void {
		$this->assertSame( [], $this->invokeStatic( $class, 'normalize_meta_keys', [ 'string' ] ) );
		$this->assertSame( [], $this->invokeStatic( $class, 'normalize_meta_keys', [ null ] ) );
		$this->assertSame( [], $this->invokeStatic( $class, 'normalize_meta_keys', [ 42 ] ) );
		$this->assertSame( [], $this->invokeStatic( $class, 'normalize_meta_keys', [ false ] ) );
	}

	#[DataProvider( 'provideClasses' )]
	public function test_returns_empty_array_for_empty_array( string $class ): void {
		$this->assertSame( [], $this->invokeStatic( $class, 'normalize_meta_keys', [ [] ] ) );
	}

	#[DataProvider( 'provideClasses' )]
	public function test_returns_valid_string_keys( string $class ): void {
		$input    = [ '_yoast_title', 'rank_math_desc', '_aioseo_keywords' ];
		$expected = [ '_yoast_title', 'rank_math_desc', '_aioseo_keywords' ];
		$this->assertSame( $expected, $this->invokeStatic( $class, 'normalize_meta_keys', [ $input ] ) );
	}

	#[DataProvider( 'provideClasses' )]
	public function test_trims_whitespace_from_keys( string $class ): void {
		$input    = [ '  _yoast_title  ', "\t_desc\n", ' _kw ' ];
		$expected = [ '_yoast_title', '_desc', '_kw' ];
		$this->assertSame( $expected, $this->invokeStatic( $class, 'normalize_meta_keys', [ $input ] ) );
	}

	#[DataProvider( 'provideClasses' )]
	public function test_removes_empty_strings_after_trim( string $class ): void {
		$input    = [ '_valid_key', '', '   ', "\t\n" ];
		$expected = [ '_valid_key' ];
		$this->assertSame( $expected, $this->invokeStatic( $class, 'normalize_meta_keys', [ $input ] ) );
	}

	#[DataProvider( 'provideClasses' )]
	public function test_deduplicates_keys( string $class ): void {
		$input    = [ '_yoast_title', '_yoast_title', '_desc', '_desc' ];
		$expected = [ '_yoast_title', '_desc' ];
		$this->assertSame( $expected, $this->invokeStatic( $class, 'normalize_meta_keys', [ $input ] ) );
	}

	#[DataProvider( 'provideClasses' )]
	public function test_skips_non_string_values_in_array( string $class ): void {
		$input    = [ '_valid', 42, null, true, [], '_also_valid' ];
		$expected = [ '_valid', '_also_valid' ];
		$this->assertSame( $expected, $this->invokeStatic( $class, 'normalize_meta_keys', [ $input ] ) );
	}

	#[DataProvider( 'provideClasses' )]
	public function test_reindexes_output_array( string $class ): void {
		$input  = [ 0 => '_first', 5 => '_second', 99 => '_third' ];
		$result = $this->invokeStatic( $class, 'normalize_meta_keys', [ $input ] );
		$this->assertSame( [ '_first', '_second', '_third' ], $result );
		$this->assertArrayHasKey( 0, $result );
		$this->assertArrayHasKey( 1, $result );
		$this->assertArrayHasKey( 2, $result );
	}

	/** @return array<string, array{string}> */
	public static function provideClasses(): array {
		return [
			'SeoPluginDetector' => [ \SlyTranslate\SeoPluginDetector::class ],
		];
	}
}
