<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AcfMetaResolver;
use SlyTranslate\MetaTranslationService;

/**
 * Tests for the slytranslate_meta_keys_exclude option, the priority-5 core
 * callback on slytranslate_translate_meta_key, and describe_effective_meta_keys.
 */
class MetaKeyExclusionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		MetaTranslationService::reset_cache();
		$this->setStaticProperty( MetaTranslationService::class, 'seo_plugin_config', array(
			'key'       => 'yoast',
			'label'     => 'Yoast SEO',
			'translate' => array( '_yoast_wpseo_title' ),
			'clear'     => array(),
		) );

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			return $value;
		} );
		$this->stubWpFunction( 'get_option', static function ( string $option, $default = false ) {
			if ( 'slytranslate_meta_keys_exclude' === $option ) {
				return 'excluded_key hero_text';
			}
			if ( 'slytranslate_meta_translate' === $option ) {
				return 'manual_key excluded_key';
			}
			return $default;
		} );
	}

	protected function tearDown(): void {
		MetaTranslationService::reset_cache();
		parent::tearDown();
	}

	/* ---------------------------------------------------------------
	 * filter_excluded_meta_key
	 * ------------------------------------------------------------- */

	public function test_excluded_key_is_vetoed(): void {
		$this->assertFalse( MetaTranslationService::filter_excluded_meta_key( true, 'excluded_key' ) );
	}

	public function test_non_excluded_key_passes_through(): void {
		$this->assertTrue( MetaTranslationService::filter_excluded_meta_key( true, 'manual_key' ) );
		// An earlier false decision is preserved, not overwritten.
		$this->assertFalse( MetaTranslationService::filter_excluded_meta_key( false, 'manual_key' ) );
	}

	public function test_exclusion_applies_in_effective_config_via_filter_dispatch(): void {
		// Simulate the real filter wiring: the per-key filter dispatches to the
		// priority-5 core callback.
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_translate_meta_key' === $tag ) {
				return MetaTranslationService::filter_excluded_meta_key( $value, (string) $args[0] );
			}
			return $value;
		} );

		$config = $this->invokeStatic( MetaTranslationService::class, 'get_effective_meta_key_config', array( 0 ) );

		$this->assertContains( 'manual_key', $config['translate'] );
		$this->assertNotContains( 'excluded_key', $config['translate'] );
		$this->assertContains( '_yoast_wpseo_title', $config['translate'] );
	}

	/* ---------------------------------------------------------------
	 * describe_effective_meta_keys
	 * ------------------------------------------------------------- */

	public function test_describe_reports_sources_and_exclusions(): void {
		$post_meta = array(
			'hero_text'  => array( 'Big headline' ),
			'_hero_text' => array( 'field_hero' ),
			'intro'      => array( 'Intro copy' ),
			'_intro'     => array( 'field_intro' ),
		);

		$this->stubWpFunctionReturn( 'get_post_meta', $post_meta );
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			$fields = array(
				'field_hero'  => array( 'type' => 'text', 'label' => 'Hero Text' ),
				'field_intro' => array( 'type' => 'wysiwyg', 'label' => 'Intro' ),
			);
			return $fields[ $ref ] ?? false;
		} );

		// Dispatch both runtime filters like production wiring does.
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_meta_keys_translate' === $tag ) {
				return AcfMetaResolver::add_acf_translatable_keys( (array) $value, (int) $args[0], (string) $args[1], (string) $args[2], (array) $args[3] );
			}
			if ( 'slytranslate_translate_meta_key' === $tag ) {
				return MetaTranslationService::filter_excluded_meta_key( $value, (string) $args[0] );
			}
			return $value;
		} );

		$described = MetaTranslationService::describe_effective_meta_keys( 42 );
		$by_key    = array();
		foreach ( $described as $entry ) {
			$by_key[ $entry['key'] ] = $entry;
		}

		// Manual key, not excluded.
		$this->assertSame( 'manual', $by_key['manual_key']['source'] );
		$this->assertFalse( $by_key['manual_key']['excluded'] );

		// SEO key from the detected plugin config.
		$this->assertSame( 'seo', $by_key['_yoast_wpseo_title']['source'] );

		// ACF keys carry label and type; hero_text is on the exclusion list.
		$this->assertSame( 'acf', $by_key['intro']['source'] );
		$this->assertSame( 'Intro', $by_key['intro']['field_label'] );
		$this->assertSame( 'wysiwyg', $by_key['intro']['field_type'] );
		$this->assertFalse( $by_key['intro']['excluded'] );

		$this->assertSame( 'acf', $by_key['hero_text']['source'] );
		$this->assertTrue( $by_key['hero_text']['excluded'] );

		// Excluded manual key still listed, flagged as excluded.
		$this->assertSame( 'manual', $by_key['excluded_key']['source'] );
		$this->assertTrue( $by_key['excluded_key']['excluded'] );
	}

	public function test_describe_marks_unknown_filter_keys_as_filter_source(): void {
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_meta_keys_translate' === $tag ) {
				$value   = (array) $value;
				$value[] = 'third_party_key';
				return $value;
			}
			return $value;
		} );

		$described = MetaTranslationService::describe_effective_meta_keys();
		$by_key    = array();
		foreach ( $described as $entry ) {
			$by_key[ $entry['key'] ] = $entry;
		}

		$this->assertSame( 'filter', $by_key['third_party_key']['source'] );
	}
}
