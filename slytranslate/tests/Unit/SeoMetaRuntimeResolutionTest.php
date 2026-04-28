<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\MetaTranslationService;

/**
 * Tests for post-aware SEO meta resolution in AI_Translate.
 */
class SeoMetaRuntimeResolutionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setStaticProperty( MetaTranslationService::class, 'meta_translate', null );
		$this->setStaticProperty( MetaTranslationService::class, 'meta_clear', null );
		$this->setStaticProperty( MetaTranslationService::class, 'resolved_meta_key_config', array() );
		$this->setStaticProperty(
			MetaTranslationService::class,
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

		$this->stubWpFunction( 'apply_filters',
			static function ( string $tag, $value, ...$args ) {
				return $value;
			}
		);
	}

	public function test_post_aware_meta_resolution_merges_active_runtime_and_user_meta_keys(): void {
		$this->stubWpFunction( 'get_option',
			static function ( string $option, $default = false ) {
				if ( 'slytranslate_meta_translate' === $option ) {
					return '_custom_translate';
				}

				if ( 'slytranslate_meta_clear' === $option ) {
					return '_custom_clear';
				}

				return $default;
			}
		);

		$this->stubWpFunction( 'get_post_meta',
			static function ( int $post_id, ...$args ) {
				return array(
					'_genesis_title'       => array( 'English Genesis title' ),
					'_genesis_description' => array( 'English Genesis description' ),
					'_custom_translate'    => array( 'Custom text value' ),
					'_custom_clear'        => array( 'stale value' ),
				);
			}
		);

		$translate = $this->invokeStatic( MetaTranslationService::class, 'meta_translate', array( 475 ) );
		$clear     = $this->invokeStatic( MetaTranslationService::class, 'meta_clear', array( 475 ) );

		$this->assertContains( '_tsf_title', $translate );
		$this->assertContains( '_genesis_title', $translate );
		$this->assertContains( '_genesis_description', $translate );
		$this->assertContains( '_custom_translate', $translate );
		$this->assertContains( '_tsf_counter_page_score', $clear );
		$this->assertContains( '_custom_clear', $clear );
		$this->assertNotContains( '_genesis_robots_noarchive', $translate );
	}
}