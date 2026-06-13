<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\GlossaryService;
use SlyTranslate\TranslationRuntime;

class GlossaryServiceTest extends TestCase {

	private function stubGlossaryOption( array $entries ): void {
		$this->stubWpFunction( 'get_option', static function ( $option, $default = false ) use ( $entries ) {
			return 'slytranslate_glossary' === $option ? $entries : $default;
		} );
	}

	public function test_sanitize_entries_normalizes_modes_and_drops_invalid_rows(): void {
		$sanitized = GlossaryService::sanitize_entries( array(
			array( 'term' => 'SlyBase', 'mode' => 'keep' ),
			array( 'term' => 'Anleitung', 'mode' => 'translate', 'to' => array( 'en' => 'Guide', '' => 'x' ) ),
			array( 'term' => '', 'mode' => 'keep' ),                       // empty term → dropped
			array( 'term' => 'Bogus', 'mode' => 'translate' ),              // translate without map → dropped
			array( 'term' => 'Fallback', 'mode' => 'nonsense' ),            // unknown mode → keep
			'not-an-array',
		) );

		$this->assertSame(
			array(
				array( 'term' => 'SlyBase', 'mode' => 'keep', 'to' => array() ),
				array( 'term' => 'Anleitung', 'mode' => 'translate', 'to' => array( 'en' => 'Guide' ) ),
				array( 'term' => 'Fallback', 'mode' => 'keep', 'to' => array() ),
			),
			$sanitized
		);
	}

	public function test_prompt_block_lists_keep_terms_and_fixed_translations(): void {
		$this->stubGlossaryOption( array(
			array( 'term' => 'SlyBase', 'mode' => 'keep' ),
			array( 'term' => 'Anleitung', 'mode' => 'translate', 'to' => array( 'en' => 'Guide', 'fr' => 'Manuel' ) ),
		) );

		$block = GlossaryService::build_prompt_block( 'en' );

		$this->assertStringContainsString( 'Never translate the following terms, keep them exactly as written: "SlyBase".', $block );
		$this->assertStringContainsString( 'Always translate "Anleitung" as "Guide".', $block );
		$this->assertStringNotContainsString( 'Manuel', $block );
	}

	public function test_prompt_block_is_empty_without_glossary_or_applicable_entries(): void {
		$this->stubGlossaryOption( array() );
		$this->assertSame( '', GlossaryService::build_prompt_block( 'en' ) );

		// A translate-only entry without a mapping for the target language.
		$this->stubGlossaryOption( array(
			array( 'term' => 'Anleitung', 'mode' => 'translate', 'to' => array( 'fr' => 'Manuel' ) ),
		) );
		$this->assertSame( '', GlossaryService::build_prompt_block( 'en' ) );
	}

	public function test_large_glossaries_are_prefiltered_against_source_text(): void {
		$entries = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$entries[] = array( 'term' => 'Begriff' . $i, 'mode' => 'keep' );
		}
		$this->stubGlossaryOption( $entries );

		$block = GlossaryService::build_prompt_block( 'en', 'Der Text erwähnt nur BEGRIFF7 in Großbuchstaben.' );

		$this->assertStringContainsString( '"Begriff7"', $block );
		$this->assertStringNotContainsString( '"Begriff8"', $block );
		$this->assertStringNotContainsString( '"Begriff1",', $block );
	}

	public function test_build_prompt_injects_glossary_block(): void {
		$this->stubGlossaryOption( array(
			array( 'term' => 'SlyBase', 'mode' => 'keep' ),
		) );

		$prompt = TranslationRuntime::build_prompt( 'en', 'de' );

		$this->assertStringContainsString( 'Glossary rules', $prompt );
		$this->assertStringContainsString( '"SlyBase"', $prompt );
	}

	public function test_build_prompt_without_glossary_stays_unchanged(): void {
		$prompt = TranslationRuntime::build_prompt( 'en', 'de' );

		$this->assertStringNotContainsString( 'Glossary rules', $prompt );
	}
}
