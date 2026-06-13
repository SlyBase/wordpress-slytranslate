<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\MetaTranslationService;

class GetTranslatableFieldsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setStaticProperty( MetaTranslationService::class, 'meta_translate', null );
		$this->setStaticProperty( MetaTranslationService::class, 'meta_clear', null );
		$this->setStaticProperty( MetaTranslationService::class, 'resolved_meta_key_config', array() );
		$this->setStaticProperty(
			MetaTranslationService::class,
			'seo_plugin_config',
			array(
				'key'       => 'yoast',
				'label'     => 'Yoast SEO',
				'translate' => array( '_yoast_wpseo_title' ),
				'clear'     => array( '_yoast_wpseo_focuskw' ),
			)
		);
	}

	protected function tearDown(): void {
		$this->setStaticProperty( MetaTranslationService::class, 'seo_plugin_config', null );
		$this->setStaticProperty( MetaTranslationService::class, 'meta_translate', null );
		$this->setStaticProperty( MetaTranslationService::class, 'meta_clear', null );
		parent::tearDown();
	}

	private function field_by_key( array $report, string $key, string $action ): ?array {
		foreach ( $report['fields'] as $field ) {
			if ( $field['key'] === $key && $field['action'] === $action ) {
				return $field;
			}
		}
		return null;
	}

	public function test_report_attributes_sources_and_actions(): void {
		$this->stubWpFunction( 'get_option', static function ( $option, $default = false ) {
			if ( 'slytranslate_meta_translate' === $option ) {
				return 'subtitle';
			}
			if ( 'slytranslate_meta_keys_exclude' === $option ) {
				return '_yoast_wpseo_title';
			}
			return $default;
		} );

		$report = AI_Translate::execute_get_translatable_fields( array() );

		$this->assertSame( 0, $report['post_id'] );
		$this->assertSame( 'yoast', $report['seo_plugin'] );
		$this->assertSame( 'Yoast SEO', $report['seo_plugin_label'] );
		$this->assertContains( 'acf', $report['active_resolvers'] );

		$manual = $this->field_by_key( $report, 'subtitle', 'translate' );
		$this->assertSame( 'manual', $manual['source'] );
		$this->assertFalse( $manual['excluded'] );

		$default_key = $this->field_by_key( $report, '_wp_attachment_image_alt', 'translate' );
		$this->assertSame( 'default', $default_key['source'] );

		// Excluded SEO key is still reported, flagged as excluded.
		$excluded = $this->field_by_key( $report, '_yoast_wpseo_title', 'translate' );
		$this->assertSame( 'seo', $excluded['source'] );
		$this->assertTrue( $excluded['excluded'] );

		$clear = $this->field_by_key( $report, '_yoast_wpseo_focuskw', 'clear' );
		$this->assertSame( 'seo', $clear['source'] );
	}

	public function test_report_passes_post_context_through(): void {
		$this->stubWpFunctionReturn( 'get_post_meta', array() );

		$report = AI_Translate::execute_get_translatable_fields( array( 'post_id' => 42 ) );

		$this->assertSame( 42, $report['post_id'] );
	}
}
