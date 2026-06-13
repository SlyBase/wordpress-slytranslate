<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AcfFieldIntrospector;

/**
 * Tests for the shared ACF introspection helper.
 */
class AcfFieldIntrospectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			return $value;
		} );

		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			$fields = array(
				'field_text'     => array( 'type' => 'text', 'label' => 'Text' ),
				'field_link'     => array( 'type' => 'link', 'label' => 'CTA' ),
				'field_number'   => array( 'type' => 'number', 'label' => 'Count' ),
				'field_excluded' => array( 'type' => 'text', 'label' => 'Hidden', 'slytranslate_exclude' => 1 ),
			);
			return $fields[ $ref ] ?? false;
		} );
	}

	public function test_default_translatable_types(): void {
		$this->assertSame( array( 'text', 'textarea', 'wysiwyg' ), AcfFieldIntrospector::get_translatable_types() );
	}

	public function test_types_filter_extends_list(): void {
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_acf_translatable_field_types' === $tag ) {
				$value   = (array) $value;
				$value[] = 'url';
			}
			return $value;
		} );

		$this->assertContains( 'url', AcfFieldIntrospector::get_translatable_types() );
	}

	public function test_get_field_for_ref_resolves_valid_refs_only(): void {
		$this->assertNull( AcfFieldIntrospector::get_field_for_ref( null ) );
		$this->assertNull( AcfFieldIntrospector::get_field_for_ref( 'not_a_field_ref' ) );
		$this->assertNull( AcfFieldIntrospector::get_field_for_ref( 'field_unknown' ) );

		$field = AcfFieldIntrospector::get_field_for_ref( 'field_text' );
		$this->assertIsArray( $field );
		$this->assertSame( 'text', $field['type'] );
	}

	public function test_text_ref_is_translatable(): void {
		$this->assertTrue( AcfFieldIntrospector::is_translatable_ref( 'field_text' ) );
		$this->assertTrue( AcfFieldIntrospector::is_translatable_ref( 'field_text', false ) );
	}

	public function test_number_ref_is_not_translatable(): void {
		$this->assertFalse( AcfFieldIntrospector::is_translatable_ref( 'field_number' ) );
	}

	public function test_structured_link_ref_only_translatable_when_structured_included(): void {
		$this->assertTrue( AcfFieldIntrospector::is_translatable_ref( 'field_link', true ) );
		$this->assertFalse( AcfFieldIntrospector::is_translatable_ref( 'field_link', false ) );
	}

	public function test_excluded_field_is_never_translatable(): void {
		$this->assertTrue( AcfFieldIntrospector::is_field_excluded( array( 'type' => 'text', 'slytranslate_exclude' => 1 ) ) );
		$this->assertFalse( AcfFieldIntrospector::is_translatable_ref( 'field_excluded' ) );
	}

	public function test_value_spec_for_link_lists_title_subkey(): void {
		$this->assertSame( array( 'subkeys' => array( 'title' ) ), AcfFieldIntrospector::get_value_spec( 'link' ) );
		$this->assertSame( array(), AcfFieldIntrospector::get_value_spec( 'text' ) );
	}
}
