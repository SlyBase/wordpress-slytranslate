<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\SeoPluginDetector;

/**
 * Tests for SeoPluginDetector plugin config and runtime resolution helpers.
 */
class SeoPluginDetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->stubWpFunction( 'apply_filters',
			static function ( string $tag, $value, ...$args ) {
				return $value;
			}
		);
	}

	public function test_returns_empty_config_when_no_seo_plugin_active(): void {
		$config = SeoPluginDetector::get_active_plugin_config();

		$this->assertIsArray( $config );
		$this->assertSame( '', $config['key'] );
		$this->assertSame( '', $config['label'] );
		$this->assertSame( array(), $config['translate'] );
		$this->assertSame( array(), $config['clear'] );
		$this->assertSame( array(), $config['matched_keys'] );
	}

	public function test_get_plugin_config_returns_genesis_profile(): void {
		$config = SeoPluginDetector::get_plugin_config( 'genesis' );

		$this->assertSame( 'genesis', $config['key'] );
		$this->assertSame( 'Genesis SEO', $config['label'] );
		$this->assertSame( array( '_genesis_title', '_genesis_description' ), $config['translate'] );
		$this->assertSame( array(), $config['clear'] );
	}

	public function test_filtered_plugin_config_applies_meta_filters(): void {
		$this->stubWpFunction( 'apply_filters',
			static function ( string $tag, $value, ...$args ) {
				if ( 'ai_translate_seo_meta_translate' === $tag && 'genesis' === ( $args[0] ?? '' ) ) {
					return array_merge( $value, array( '_genesis_custom_title' ) );
				}

				if ( 'ai_translate_seo_meta_clear' === $tag && 'genesis' === ( $args[0] ?? '' ) ) {
					return array_merge( $value, array( '_genesis_custom_score' ) );
				}

				return $value;
			}
		);

		$config = SeoPluginDetector::get_filtered_plugin_config( 'genesis' );

		$this->assertContains( '_genesis_custom_title', $config['translate'] );
		$this->assertContains( '_genesis_custom_score', $config['clear'] );
	}

	public function test_runtime_config_merges_active_and_meta_matched_profiles(): void {
		$config = SeoPluginDetector::resolve_runtime_plugin_config(
			array( '_genesis_title', '_genesis_description' ),
			'the-seo-framework'
		);

		$this->assertSame( 'the-seo-framework', $config['key'] );
		$this->assertSame( array( 'the-seo-framework', 'genesis' ), $config['matched_keys'] );
		$this->assertContains( '_tsf_title', $config['translate'] );
		$this->assertContains( '_genesis_title', $config['translate'] );
		$this->assertContains( '_genesis_description', $config['translate'] );
		$this->assertContains( '_tsf_counter_page_score', $config['clear'] );
		$this->assertNotContains( '_genesis_robots_noarchive', $config['translate'] );
	}

	public function test_runtime_config_does_not_match_unknown_genesis_flags(): void {
		$config = SeoPluginDetector::resolve_runtime_plugin_config(
			array( '_genesis_robots_noarchive', '_genesis_canonical_uri' )
		);

		$this->assertSame( '', $config['key'] );
		$this->assertSame( array(), $config['translate'] );
		$this->assertSame( array(), $config['clear'] );
		$this->assertSame( array(), $config['matched_keys'] );
	}

	public function test_normalize_meta_keys_trims_and_deduplicates(): void {
		$result = $this->invokeStatic( SeoPluginDetector::class, 'normalize_meta_keys', array( array( '  _dirty  ', '_clean', '_clean' ) ) );

		$this->assertSame( array( '_dirty', '_clean' ), $result );
	}

	public function test_get_active_plugin_key_returns_empty_string_in_test_env(): void {
		$this->assertSame( '', SeoPluginDetector::get_active_plugin_key() );
	}
}
