<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use Brain\Monkey\Functions;

/**
 * Tests for post-aware SEO meta resolution in AI_Translate.
 */
class SeoMetaRuntimeResolutionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setStaticProperty( AI_Translate::class, 'meta_translate', null );
		$this->setStaticProperty( AI_Translate::class, 'meta_clear', null );
		$this->setStaticProperty( AI_Translate::class, 'resolved_meta_key_config', array() );
		$this->setStaticProperty(
			AI_Translate::class,
			'seo_plugin_config',
			array(
				'key'       => 'the-seo-framework',
				'label'     => 'The SEO Framework',
				'translate' => array(
					'_tsf_title_no_blogname',
					'_tsf_title',
					'_tsf_description',
					'_tsf_kw_research_personal',
				),
				'clear'     => array(
					'_tsf_counter_page_score',
				),
			)
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value, ...$args ) {
				return $value;
			}
		);
	}

	public function test_post_aware_meta_resolution_merges_active_runtime_and_user_meta_keys(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default = false ) {
				if ( 'ai_translate_meta_translate' === $option ) {
					return '_custom_translate';
				}

				if ( 'ai_translate_meta_clear' === $option ) {
					return '_custom_clear';
				}

				return $default;
			}
		);

		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, ...$args ) {
				return array(
					'_genesis_title'       => array( 'English Genesis title' ),
					'_genesis_description' => array( 'English Genesis description' ),
					'_custom_translate'    => array( 'Custom text value' ),
					'_custom_clear'        => array( 'stale value' ),
				);
			}
		);

		$translate = $this->invokeStatic( AI_Translate::class, 'meta_translate', array( 475 ) );
		$clear     = $this->invokeStatic( AI_Translate::class, 'meta_clear', array( 475 ) );

		$this->assertContains( '_tsf_title', $translate );
		$this->assertContains( '_genesis_title', $translate );
		$this->assertContains( '_genesis_description', $translate );
		$this->assertContains( '_custom_translate', $translate );
		$this->assertContains( '_tsf_counter_page_score', $clear );
		$this->assertContains( '_custom_clear', $clear );
		$this->assertNotContains( '_genesis_robots_noarchive', $translate );
	}
}