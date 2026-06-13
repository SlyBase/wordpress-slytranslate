<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\PodsMetaResolver;

/**
 * Tests for the Pods field resolver. Detection loads the pod definition for
 * the post's type and matches meta keys against registered field names.
 */
class PodsMetaResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			return $value;
		} );
		$this->stubWpFunctionReturn( 'get_post_type', 'book' );

		$this->stubWpFunction( 'pods_api', static function () {
			return new class() {
				public function load_pod( array $params ) {
					if ( 'book' !== ( $params['name'] ?? '' ) ) {
						return false;
					}
					return array(
						'name'   => 'book',
						'fields' => array(
							'summary'    => array( 'type' => 'paragraph' ),
							'subtitle'   => array( 'type' => 'text' ),
							'review'     => array( 'type' => 'wysiwyg' ),
							'page_count' => array( 'type' => 'number' ),
						),
					);
				}
			};
		} );
	}

	public function test_translatable_pods_fields_are_added(): void {
		$post_meta = array(
			'summary'  => array( 'A short summary' ),
			'subtitle' => array( 'A subtitle' ),
			'review'   => array( '<p>Great.</p>' ),
		);

		$result = PodsMetaResolver::add_pods_translatable_keys( array(), 42, 'en', 'de', $post_meta );

		$this->assertContains( 'summary', $result );
		$this->assertContains( 'subtitle', $result );
		$this->assertContains( 'review', $result );
	}

	public function test_non_translatable_and_unregistered_keys_are_skipped(): void {
		$post_meta = array(
			'page_count' => array( '320' ),
			'random_key' => array( 'value' ),
			'_internal'  => array( 'value' ),
		);

		$result = PodsMetaResolver::add_pods_translatable_keys( array(), 42, 'en', 'de', $post_meta );

		$this->assertSame( array(), $result );
	}

	public function test_unknown_post_type_returns_unchanged(): void {
		$this->stubWpFunctionReturn( 'get_post_type', 'page' );

		$post_meta = array( 'summary' => array( 'A short summary' ) );

		$this->assertSame( array(), PodsMetaResolver::add_pods_translatable_keys( array(), 42, 'en', 'de', $post_meta ) );
	}

	public function test_missing_post_context_returns_unchanged(): void {
		$post_meta = array( 'summary' => array( 'A short summary' ) );

		$this->assertSame( array(), PodsMetaResolver::add_pods_translatable_keys( array(), 0, 'en', 'de', $post_meta ) );
	}

	public function test_types_filter_extends_list(): void {
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_pods_translatable_field_types' === $tag ) {
				$value   = (array) $value;
				$value[] = 'number';
			}
			return $value;
		} );

		$post_meta = array( 'page_count' => array( '320' ) );

		$result = PodsMetaResolver::add_pods_translatable_keys( array(), 42, 'en', 'de', $post_meta );

		$this->assertContains( 'page_count', $result );
	}
}
