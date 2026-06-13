<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\MetaBoxMetaResolver;

/**
 * Tests for the Meta Box (metabox.io) field resolver. Detection runs against
 * rwmb_get_field_settings() because Meta Box stores no reference keys in meta.
 */
class MetaBoxMetaResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			return $value;
		} );

		$this->stubWpFunction( 'rwmb_get_field_settings', static function ( $field_id, $args = array(), $post_id = null ) {
			$fields = array(
				'mb_text'     => array( 'type' => 'text' ),
				'mb_textarea' => array( 'type' => 'textarea' ),
				'mb_wysiwyg'  => array( 'type' => 'wysiwyg' ),
				'mb_number'   => array( 'type' => 'number' ),
				'mb_image'    => array( 'type' => 'image_advanced' ),
			);
			return $fields[ $field_id ] ?? false;
		} );
	}

	public function test_translatable_metabox_fields_are_added(): void {
		$post_meta = array(
			'mb_text'     => array( 'value' ),
			'mb_textarea' => array( 'value' ),
			'mb_wysiwyg'  => array( '<p>value</p>' ),
		);

		$result = MetaBoxMetaResolver::add_metabox_translatable_keys( array(), 42, 'en', 'de', $post_meta );

		$this->assertContains( 'mb_text', $result );
		$this->assertContains( 'mb_textarea', $result );
		$this->assertContains( 'mb_wysiwyg', $result );
	}

	public function test_non_translatable_types_and_unknown_keys_are_skipped(): void {
		$post_meta = array(
			'mb_number'  => array( '5' ),
			'mb_image'   => array( '12' ),
			'random_key' => array( 'value' ),
			'_internal'  => array( 'value' ),
		);

		$result = MetaBoxMetaResolver::add_metabox_translatable_keys( array(), 42, 'en', 'de', $post_meta );

		$this->assertSame( array(), $result );
	}

	public function test_existing_keys_are_not_duplicated(): void {
		$post_meta = array( 'mb_text' => array( 'value' ) );

		$result = MetaBoxMetaResolver::add_metabox_translatable_keys( array( 'mb_text' ), 42, 'en', 'de', $post_meta );

		$this->assertSame( array( 'mb_text' ), $result );
	}

	public function test_types_filter_extends_list(): void {
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_metabox_translatable_field_types' === $tag ) {
				$value   = (array) $value;
				$value[] = 'number';
			}
			return $value;
		} );

		$post_meta = array( 'mb_number' => array( '5' ) );

		$result = MetaBoxMetaResolver::add_metabox_translatable_keys( array(), 42, 'en', 'de', $post_meta );

		$this->assertContains( 'mb_number', $result );
	}

	public function test_empty_post_meta_returns_unchanged(): void {
		$initial = array( 'existing' );
		$this->assertSame( $initial, MetaBoxMetaResolver::add_metabox_translatable_keys( $initial, 42, 'en', 'de', array() ) );
	}
}
