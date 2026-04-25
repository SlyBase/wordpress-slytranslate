<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\MetaTranslationService;

class MetaTranslationInternalKeySkipTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setStaticProperty( MetaTranslationService::class, 'meta_translate', null );
		$this->setStaticProperty( MetaTranslationService::class, 'meta_clear', null );
		$this->setStaticProperty( MetaTranslationService::class, 'resolved_meta_key_config', array() );
		$this->setStaticProperty(
			MetaTranslationService::class,
			'seo_plugin_config',
			array(
				'key'       => '',
				'label'     => '',
				'translate' => array(),
				'clear'     => array(),
			)
		);

		$this->stubWpFunction(
			'apply_filters',
			static function ( string $tag, $value, ...$args ) {
				return $value;
			}
		);
		$this->stubWpFunctionReturn( 'get_option', '' );
		$this->stubWpFunction( 'maybe_unserialize', static fn( $value ) => $value );
	}

	public function test_runtime_source_meta_keys_skip_dynamic_oembed_cache_keys(): void {
		$keys = $this->invokeStatic(
			MetaTranslationService::class,
			'get_runtime_source_meta_keys',
			array(
				array(
					'_oembed_8f4e2a'      => array( '<iframe ...>' ),
					'_oembed_time_8f4e2a' => array( '1714066301' ),
					'_edit_lock'          => array( '1714066100:1' ),
					'_custom_meta'        => array( 'custom value' ),
				),
			)
		);

		$this->assertSame( array( '_custom_meta' ), $keys );
	}

	public function test_prepare_translation_meta_omits_oembed_cache_entries(): void {
		$processed = MetaTranslationService::prepare_translation_meta(
			17,
			'de',
			'en',
			'',
			array(
				'_oembed_8f4e2a'      => array( '<iframe ...>' ),
				'_oembed_time_8f4e2a' => array( 'https://wordpress.org/' ),
				'_custom_meta'        => array( 'custom value' ),
			)
		);

		$this->assertIsArray( $processed );
		$this->assertArrayNotHasKey( '_oembed_8f4e2a', $processed );
		$this->assertArrayNotHasKey( '_oembed_time_8f4e2a', $processed );
		$this->assertSame( 'custom value', $processed['_custom_meta'] );
	}
}
