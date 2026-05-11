<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\WpglobusAdapter;

class WpglobusAdapterTest extends TestCase {

	private WpglobusAdapter $adapter;

	protected function setUp(): void {
		parent::setUp();
		$this->adapter = new WpglobusAdapter();
	}

	// -----------------------------------------------------------------------
	// is_available
	// -----------------------------------------------------------------------

	public function test_is_available_returns_false_without_wpglobus(): void {
		// In the test environment neither WPGlobus class nor wpglobus_current_language() exists.
		$this->assertFalse( $this->adapter->is_available() );
	}

	// -----------------------------------------------------------------------
	// get_languages
	// -----------------------------------------------------------------------

	public function test_get_languages_returns_empty_when_no_languages_configured(): void {
		// wpglobus_languages_list() returns empty array by default.
		$this->assertSame( array(), $this->adapter->get_languages() );
	}

	public function test_get_languages_returns_language_map(): void {
		$this->stubWpFunctionReturn( 'wpglobus_languages_list', array( 'en', 'de' ) );
		$languages = $this->adapter->get_languages();
		$this->assertArrayHasKey( 'en', $languages );
		$this->assertArrayHasKey( 'de', $languages );
	}

	// -----------------------------------------------------------------------
	// get_post_language
	// -----------------------------------------------------------------------

	public function test_get_post_language_returns_current_wpglobus_language_when_available(): void {
		$this->stubWpFunctionReturn( 'wpglobus_languages_list', array( 'en', 'de' ) );
		$this->stubWpFunctionReturn( 'get_query_var', 'de' );
		$this->stubWpFunctionReturn( 'wpglobus_default_language', 'en' );

		$this->assertSame( 'de', $this->adapter->get_post_language( 123 ) );
	}

	public function test_get_post_language_falls_back_to_default_language(): void {
		$this->stubWpFunctionReturn( 'wpglobus_languages_list', array( 'en', 'de' ) );
		$this->stubWpFunctionReturn( 'get_query_var', 'fr' );
		$this->stubWpFunctionReturn( 'wpglobus_default_language', 'en' );

		$this->assertSame( 'en', $this->adapter->get_post_language( 123 ) );
	}

	// -----------------------------------------------------------------------
	// get_language_variant – plain value (no markup)
	// -----------------------------------------------------------------------

	public function test_get_language_variant_returns_value_for_default_language(): void {
		$this->stubWpFunctionReturn( 'wpglobus_default_language', 'en' );

		$result = $this->adapter->get_language_variant( 'Hello world', 'en' );
		$this->assertSame( 'Hello world', $result );
	}

	public function test_get_language_variant_returns_empty_for_non_default_language_plain_value(): void {
		$this->stubWpFunctionReturn( 'wpglobus_default_language', 'en' );

		$result = $this->adapter->get_language_variant( 'Hello world', 'de' );
		$this->assertSame( '', $result );
	}

	// -----------------------------------------------------------------------
	// get_language_variant – WPGlobus markup
	// -----------------------------------------------------------------------

	public function test_get_language_variant_extracts_en_segment(): void {
		$value  = '{:en}Hello world{:}{:de}Hallo Welt{:}';
		$result = $this->adapter->get_language_variant( $value, 'en' );
		$this->assertSame( 'Hello world', $result );
	}

	public function test_get_language_variant_extracts_de_segment(): void {
		$value  = '{:en}Hello world{:}{:de}Hallo Welt{:}';
		$result = $this->adapter->get_language_variant( $value, 'de' );
		$this->assertSame( 'Hallo Welt', $result );
	}

	public function test_get_language_variant_returns_empty_for_missing_language(): void {
		$value  = '{:en}Hello world{:}{:de}Hallo Welt{:}';
		$result = $this->adapter->get_language_variant( $value, 'fr' );
		$this->assertSame( '', $result );
	}

	public function test_get_language_variant_handles_multiline_content(): void {
		$value  = "{:en}Line one\nLine two{:}{:de}Zeile eins\nZeile zwei{:}";
		$result = $this->adapter->get_language_variant( $value, 'de' );
		$this->assertSame( "Zeile eins\nZeile zwei", $result );
	}

	public function test_get_language_variant_returns_empty_for_empty_value(): void {
		$result = $this->adapter->get_language_variant( '', 'en' );
		$this->assertSame( '', $result );
	}

	public function test_get_language_variant_returns_empty_for_empty_language_code(): void {
		$value  = '{:en}Hello{:}{:de}Hallo{:}';
		$result = $this->adapter->get_language_variant( $value, '' );
		$this->assertSame( '', $result );
	}

	// -----------------------------------------------------------------------
	// merge_language_value – plain value
	// -----------------------------------------------------------------------

	public function test_merge_language_value_wraps_source_and_target_for_plain_value(): void {
		$result = $this->invokeMethod(
			$this->adapter,
			'merge_language_value',
			array( 'Hello world', 'en', 'de', 'Hallo Welt', 'Hello world' )
		);
		$this->assertSame( '{:en}Hello world{:}{:de}Hallo Welt{:}', $result );
	}

	public function test_merge_language_value_plain_value_without_source_fallback(): void {
		$result = $this->invokeMethod(
			$this->adapter,
			'merge_language_value',
			array( 'Hello world', '', 'de', 'Hallo Welt', '' )
		);
		$this->assertSame( '{:de}Hallo Welt{:}', $result );
	}

	// -----------------------------------------------------------------------
	// merge_language_value – existing WPGlobus markup
	// -----------------------------------------------------------------------

	public function test_merge_language_value_replaces_existing_target_segment(): void {
		$existing = '{:en}Hello world{:}{:de}Alter Text{:}';
		$result   = $this->invokeMethod(
			$this->adapter,
			'merge_language_value',
			array( $existing, 'en', 'de', 'Hallo Welt', 'Hello world' )
		);
		$this->assertSame( '{:en}Hello world{:}{:de}Hallo Welt{:}', $result );
	}

	public function test_merge_language_value_appends_new_target_segment(): void {
		$existing = '{:en}Hello world{:}';
		$result   = $this->invokeMethod(
			$this->adapter,
			'merge_language_value',
			array( $existing, 'en', 'de', 'Hallo Welt', 'Hello world' )
		);
		$this->assertStringContainsString( '{:de}Hallo Welt{:}', $result );
		$this->assertStringContainsString( '{:en}Hello world{:}', $result );
	}

	public function test_merge_language_value_adds_missing_source_segment(): void {
		$existing = '{:fr}Bonjour{:}';
		$result   = $this->invokeMethod(
			$this->adapter,
			'merge_language_value',
			array( $existing, 'en', 'de', 'Hallo', 'Hello' )
		);
		$this->assertStringContainsString( '{:en}Hello{:}', $result );
		$this->assertStringContainsString( '{:de}Hallo{:}', $result );
	}

	public function test_merge_language_value_returns_existing_when_target_language_empty(): void {
		$existing = '{:en}Hello{:}';
		$result   = $this->invokeMethod(
			$this->adapter,
			'merge_language_value',
			array( $existing, 'en', '', 'Hallo', 'Hello' )
		);
		$this->assertSame( $existing, $result );
	}

	// -----------------------------------------------------------------------
	// has_wpglobus_markup
	// -----------------------------------------------------------------------

	public function test_has_wpglobus_markup_detects_opening_tag(): void {
		$result = $this->invokeMethod( $this->adapter, 'has_wpglobus_markup', array( '{:en}Hello{:}' ) );
		$this->assertTrue( $result );
	}

	public function test_has_wpglobus_markup_returns_false_for_plain_value(): void {
		$result = $this->invokeMethod( $this->adapter, 'has_wpglobus_markup', array( 'Hello world' ) );
		$this->assertFalse( $result );
	}

	public function test_has_wpglobus_markup_returns_false_for_wp_multilang_style(): void {
		// WP Multilang uses [:en] with square brackets, not curly braces
		$result = $this->invokeMethod( $this->adapter, 'has_wpglobus_markup', array( '[:en]Hello[:de]Hallo[:]' ) );
		$this->assertFalse( $result );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function invokeMethod( object $object, string $method, array $args = [] ): mixed {
		$reflection = new \ReflectionMethod( $object, $method );
		return $reflection->invoke( $object, ...$args );
	}
}
