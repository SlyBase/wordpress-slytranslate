<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AcfMetaResolver;

/**
 * Tests for AcfMetaResolver::add_acf_translatable_keys().
 *
 * Verifies that ACF field meta keys are correctly identified as translatable
 * based on their field type (text, textarea, wysiwyg are translatable by default).
 * The resolver inspects post meta for ACF field reference keys and adds the
 * corresponding meta keys to the translate list.
 */
class AcfMetaResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Default: apply_filters returns value unchanged (for custom field types filter)
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			return $value;
		} );

		// Default: acf_get_field returns false (field not found)
		$this->stubWpFunction( 'acf_get_field', static function ( $field ) {
			return false;
		} );
	}

	/**
	 * Test that a text field is added to the translate list.
	 */
	public function test_text_field_is_added_to_translate(): void {
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_abc123' === $ref ) {
				return array( 'type' => 'text' );
			}
			return false;
		} );

		$post_meta = array(
			'page_rep_0_text'  => array( 'some text' ),
			'_page_rep_0_text' => array( 'field_abc123' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		$this->assertContains( 'page_rep_0_text', $result );
	}

	/**
	 * Test that textarea fields are added to the translate list.
	 */
	public function test_textarea_field_is_added_to_translate(): void {
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_textarea' === $ref ) {
				return array( 'type' => 'textarea' );
			}
			return false;
		} );

		$post_meta = array(
			'description'  => array( 'text content' ),
			'_description' => array( 'field_textarea' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		$this->assertContains( 'description', $result );
	}

	/**
	 * Test that wysiwyg fields are added to the translate list.
	 */
	public function test_wysiwyg_field_is_added_to_translate(): void {
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_content_editor' === $ref ) {
				return array( 'type' => 'wysiwyg' );
			}
			return false;
		} );

		$post_meta = array(
			'content'  => array( '<p>Rich text content</p>' ),
			'_content' => array( 'field_content_editor' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		$this->assertContains( 'content', $result );
	}

	/**
	 * Test that number fields are NOT added to the translate list.
	 */
	public function test_number_field_is_not_added(): void {
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_number' === $ref ) {
				return array( 'type' => 'number' );
			}
			return false;
		} );

		$post_meta = array(
			'counter'  => array( '42' ),
			'_counter' => array( 'field_number' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		$this->assertNotContains( 'counter', $result );
	}

	/**
	 * Test that image and select fields are NOT added to the translate list.
	 */
	public function test_image_and_select_not_added(): void {
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_image' === $ref ) {
				return array( 'type' => 'image' );
			}
			if ( 'field_select' === $ref ) {
				return array( 'type' => 'select' );
			}
			return false;
		} );

		$post_meta = array(
			'featured_image' => array( '123' ),
			'_featured_image' => array( 'field_image' ),
			'category' => array( 'news' ),
			'_category' => array( 'field_select' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		$this->assertNotContains( 'featured_image', $result );
		$this->assertNotContains( 'category', $result );
	}

	/**
	 * Test that missing ACF reference key is gracefully ignored.
	 */
	public function test_missing_acf_ref_key_is_ignored(): void {
		$post_meta = array(
			'custom_field' => array( 'value' ),
			// No _custom_field entry, so no ACF reference
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		// Should not crash, custom_field should not be added
		$this->assertNotContains( 'custom_field', $result );
	}

	/**
	 * Test that acf_get_field returning false is handled gracefully.
	 */
	public function test_acf_get_field_false_is_handled(): void {
		// acf_get_field is already stubbed to return false by default
		$post_meta = array(
			'field_with_no_acf' => array( 'value' ),
			'_field_with_no_acf' => array( 'field_unknown' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		// Should not crash, field should not be added
		$this->assertNotContains( 'field_with_no_acf', $result );
	}

	/**
	 * Test that custom field types can be added via filter.
	 */
	public function test_custom_types_filter_extends_list(): void {
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_acf_translatable_field_types' === $tag ) {
				$types = (array) $value;
				$types[] = 'url';
				return $types;
			}
			return $value;
		} );

		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_url' === $ref ) {
				return array( 'type' => 'url' );
			}
			return false;
		} );

		$post_meta = array(
			'website'  => array( 'https://example.com' ),
			'_website' => array( 'field_url' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		$this->assertContains( 'website', $result );
	}

	/**
	 * Test that empty post_meta returns the translate list unchanged.
	 */
	public function test_empty_post_meta_returns_unchanged(): void {
		$initial_translate = array( 'existing_key' );
		$result = AcfMetaResolver::add_acf_translatable_keys( $initial_translate, 42, 'de', 'en', array() );

		$this->assertSame( $initial_translate, $result );
	}

	/**
	 * Test that keys starting with underscore are skipped.
	 */
	public function test_keys_starting_with_underscore_are_skipped(): void {
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_internal' === $ref ) {
				return array( 'type' => 'text' );
			}
			return false;
		} );

		$post_meta = array(
			'_internal_field'  => array( 'value' ),
			'__internal_field' => array( 'field_internal' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		// _internal_field should be skipped (starts with underscore)
		$this->assertNotContains( '_internal_field', $result );
		// __internal_field should also be skipped
		$this->assertNotContains( '__internal_field', $result );
	}

	/**
	 * Test that non-string ACF reference values are handled safely.
	 */
	public function test_non_string_acf_reference_is_ignored(): void {
		$post_meta = array(
			'my_field'  => array( 'value' ),
			'_my_field' => array( 123 ), // Not a string
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		// Should not crash, should not be added (ref is not string or doesn't start with field_)
		$this->assertNotContains( 'my_field', $result );
	}

	/**
	 * Test that ACF references not starting with 'field_' are ignored.
	 */
	public function test_acf_ref_not_starting_with_field_prefix_is_ignored(): void {
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'other_ref_format' === $ref ) {
				return array( 'type' => 'text' );
			}
			return false;
		} );

		$post_meta = array(
			'my_field'  => array( 'value' ),
			'_my_field' => array( 'other_ref_format' ), // Does not start with field_
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		// Should not be added because ref doesn't start with 'field_'
		$this->assertNotContains( 'my_field', $result );
	}

	/**
	 * Test multiple translatable fields in one post_meta.
	 */
	public function test_multiple_translatable_fields(): void {
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			$fields = array(
				'field_title' => array( 'type' => 'text' ),
				'field_desc' => array( 'type' => 'textarea' ),
				'field_content' => array( 'type' => 'wysiwyg' ),
				'field_count' => array( 'type' => 'number' ),
			);
			return $fields[ $ref ] ?? false;
		} );

		$post_meta = array(
			'title'   => array( 'My Title' ),
			'_title'  => array( 'field_title' ),
			'desc'    => array( 'Short description' ),
			'_desc'   => array( 'field_desc' ),
			'content' => array( '<p>Content</p>' ),
			'_content' => array( 'field_content' ),
			'count'   => array( '5' ),
			'_count'  => array( 'field_count' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		$this->assertContains( 'title', $result );
		$this->assertContains( 'desc', $result );
		$this->assertContains( 'content', $result );
		$this->assertNotContains( 'count', $result );
	}

	/**
	 * Test that fields flagged with the editor opt-out are skipped.
	 */
	public function test_field_with_exclude_flag_is_skipped(): void {
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_hidden' === $ref ) {
				return array( 'type' => 'text', 'slytranslate_exclude' => 1 );
			}
			return false;
		} );

		$post_meta = array(
			'hidden_text'  => array( 'value' ),
			'_hidden_text' => array( 'field_hidden' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		$this->assertNotContains( 'hidden_text', $result );
	}

	/**
	 * Test that structured link fields are added and register a value spec.
	 */
	public function test_link_field_is_added_with_value_spec(): void {
		\SlyTranslate\MetaTranslationService::reset_cache();

		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_link' === $ref ) {
				return array( 'type' => 'link', 'label' => 'CTA Link' );
			}
			return false;
		} );

		$post_meta = array(
			'cta'  => array( 'a:3:{...}' ),
			'_cta' => array( 'field_link' ),
		);

		$result = AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		$this->assertContains( 'cta', $result );
		$this->assertSame(
			array( 'subkeys' => array( 'title' ) ),
			\SlyTranslate\MetaTranslationService::get_meta_value_spec( 'cta' )['spec']
		);

		\SlyTranslate\MetaTranslationService::reset_cache();
	}

	/**
	 * Test that resolved field info (label/type) is recorded per post.
	 */
	public function test_resolved_field_info_is_recorded(): void {
		AcfMetaResolver::reset_cache();

		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_hero' === $ref ) {
				return array( 'type' => 'text', 'label' => 'Hero Text' );
			}
			return false;
		} );

		$post_meta = array(
			'hero'  => array( 'Big headline' ),
			'_hero' => array( 'field_hero' ),
		);

		AcfMetaResolver::add_acf_translatable_keys( array(), 42, 'de', 'en', $post_meta );

		$info = AcfMetaResolver::get_resolved_field_info( 42 );
		$this->assertSame( 'text', $info['hero']['field_type'] );
		$this->assertSame( 'Hero Text', $info['hero']['field_label'] );
		$this->assertSame( array(), AcfMetaResolver::get_resolved_field_info( 99 ) );

		AcfMetaResolver::reset_cache();
	}

	/**
	 * Test that existing keys in translate list are preserved.
	 */
	public function test_existing_translate_keys_are_preserved(): void {
		$this->stubWpFunction( 'acf_get_field', static function ( $ref ) {
			if ( 'field_text' === $ref ) {
				return array( 'type' => 'text' );
			}
			return false;
		} );

		$post_meta = array(
			'acf_field'  => array( 'text value' ),
			'_acf_field' => array( 'field_text' ),
		);

		$existing_translate = array( '_custom_field', '_seo_title' );
		$result = AcfMetaResolver::add_acf_translatable_keys( $existing_translate, 42, 'de', 'en', $post_meta );

		// Existing keys should still be present
		$this->assertContains( '_custom_field', $result );
		$this->assertContains( '_seo_title', $result );
		// New ACF field should be added
		$this->assertContains( 'acf_field', $result );
	}
}
