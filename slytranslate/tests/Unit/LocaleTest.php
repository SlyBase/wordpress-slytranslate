<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\EditorBootstrap;

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
		$this->stubWpFunctionReturn( 'get_locale', 'en_US' );
		$this->assertSame( 'en', $this->getDefaultLanguage() );
	}

	public function test_german_locale_returns_de(): void {
		$this->stubWpFunctionReturn( 'get_locale', 'de_DE' );
		$this->assertSame( 'de', $this->getDefaultLanguage() );
	}

	public function test_french_canada_locale_returns_fr(): void {
		$this->stubWpFunctionReturn( 'get_locale', 'fr_CA' );
		$this->assertSame( 'fr', $this->getDefaultLanguage() );
	}

	public function test_portuguese_brazil_locale_returns_pt(): void {
		$this->stubWpFunctionReturn( 'get_locale', 'pt_BR' );
		$this->assertSame( 'pt', $this->getDefaultLanguage() );
	}

	public function test_chinese_simplified_locale_returns_zh(): void {
		$this->stubWpFunctionReturn( 'get_locale', 'zh_CN' );
		$this->assertSame( 'zh', $this->getDefaultLanguage() );
	}

	public function test_empty_locale_returns_en_fallback(): void {
		$this->stubWpFunctionReturn( 'get_locale', '' );
		$this->assertSame( 'en', $this->getDefaultLanguage() );
	}

	public function test_plain_language_code_without_region(): void {
		$this->stubWpFunctionReturn( 'get_locale', 'de' );
		$this->assertSame( 'de', $this->getDefaultLanguage() );
	}

	public function test_locale_with_hyphen_separator(): void {
		$this->stubWpFunctionReturn( 'get_locale', 'en-US' );
		$this->assertSame( 'en', $this->getDefaultLanguage() );
	}
}

