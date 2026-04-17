<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\EditorBootstrap;
use Brain\Monkey\Functions;

/**
 * Tests for EditorBootstrap::get_editor_default_source_language().
 *
 * Returns the primary language subtag derived from the WordPress locale.
 * The method uses determine_locale() if available, otherwise falls back to get_locale().
 */
class LocaleTest extends TestCase {

	private function getDefaultLanguage(): string {
		return $this->invokeStatic( EditorBootstrap::class, 'get_editor_default_source_language', [] );
	}

	public function test_english_locale_returns_en(): void {
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		$this->assertSame( 'en', $this->getDefaultLanguage() );
	}

	public function test_german_locale_returns_de(): void {
		Functions\when( 'get_locale' )->justReturn( 'de_DE' );
		$this->assertSame( 'de', $this->getDefaultLanguage() );
	}

	public function test_french_canada_locale_returns_fr(): void {
		Functions\when( 'get_locale' )->justReturn( 'fr_CA' );
		$this->assertSame( 'fr', $this->getDefaultLanguage() );
	}

	public function test_portuguese_brazil_locale_returns_pt(): void {
		Functions\when( 'get_locale' )->justReturn( 'pt_BR' );
		$this->assertSame( 'pt', $this->getDefaultLanguage() );
	}

	public function test_chinese_simplified_locale_returns_zh(): void {
		Functions\when( 'get_locale' )->justReturn( 'zh_CN' );
		$this->assertSame( 'zh', $this->getDefaultLanguage() );
	}

	public function test_empty_locale_returns_en_fallback(): void {
		Functions\when( 'get_locale' )->justReturn( '' );
		$this->assertSame( 'en', $this->getDefaultLanguage() );
	}

	public function test_plain_language_code_without_region(): void {
		Functions\when( 'get_locale' )->justReturn( 'de' );
		$this->assertSame( 'de', $this->getDefaultLanguage() );
	}

	public function test_locale_with_hyphen_separator(): void {
		Functions\when( 'get_locale' )->justReturn( 'en-US' );
		$this->assertSame( 'en', $this->getDefaultLanguage() );
	}
}

