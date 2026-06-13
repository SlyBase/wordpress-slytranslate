<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\MetaTranslationService;

/**
 * Tests for the runtime filter API in MetaTranslationService::get_effective_meta_key_config().
 *
 * Three filters enable third-party code (like AcfMetaResolver) to dynamically add or
 * remove meta keys from translation/clear lists:
 *
 * 1. slytranslate_meta_keys_translate - List-level filter (runs once per call)
 * 2. slytranslate_meta_keys_clear - List-level filter (runs once per call)
 * 3. slytranslate_translate_meta_key - Per-key filter (runs for each key in translate list)
 */
class MetaKeyRuntimeFilterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// Reset all static caches
		$this->setStaticProperty( MetaTranslationService::class, 'meta_translate', null );
		$this->setStaticProperty( MetaTranslationService::class, 'meta_clear', null );
		$this->setStaticProperty( MetaTranslationService::class, 'resolved_meta_key_config', array() );
		$this->setStaticProperty( MetaTranslationService::class, 'seo_plugin_config', array(
			'key'       => 'none',
			'label'     => 'None',
			'translate' => array(),
			'clear'     => array(),
		) );

		// Default: apply_filters returns value unchanged
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			return $value;
		} );

		// get_option returns nothing by default
		$this->stubWpFunction( 'get_option', static function ( string $option, $default = false ) {
			return $default;
		} );
	}

	/**
	 * Test that slytranslate_meta_keys_translate filter can add dynamic keys.
	 */
	public function test_translate_list_filter_adds_dynamic_key(): void {
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_meta_keys_translate' === $tag ) {
				$translated = (array) $value;
				$translated[] = 'page_rep_0_text';
				return $translated;
			}
			return $value;
		} );

		$config = $this->invokeStatic( MetaTranslationService::class, 'get_effective_meta_key_config', array( 0 ) );

		$this->assertContains( 'page_rep_0_text', $config['translate'] );
	}

	/**
	 * Test that slytranslate_meta_keys_translate filter receives correct arguments.
	 */
	public function test_filter_receives_correct_args(): void {
		$captured = array();

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) use ( &$captured ) {
			if ( 'slytranslate_meta_keys_translate' === $tag ) {
				$captured = array(
					'value'     => $value,
					'post_id'   => $args[0] ?? null,
					'from'      => $args[1] ?? null,
					'to'        => $args[2] ?? null,
					'post_meta' => $args[3] ?? null,
				);
			}
			return $value;
		} );

		$post_meta = array( 'some_key' => array( 'some_value' ), '_internal' => array( 'test' ) );
		$this->invokeStatic( MetaTranslationService::class, 'get_effective_meta_key_config', array( 42, $post_meta, 'de', 'en' ) );

		$this->assertSame( 42, $captured['post_id'] );
		$this->assertSame( 'de', $captured['from'] );
		$this->assertSame( 'en', $captured['to'] );
		$this->assertIsArray( $captured['post_meta'] );
		$this->assertArrayHasKey( 'some_key', $captured['post_meta'] );
	}

	/**
	 * Test that slytranslate_meta_keys_clear filter can add dynamic keys.
	 */
	public function test_clear_list_filter_adds_key(): void {
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_meta_keys_clear' === $tag ) {
				$cleared = (array) $value;
				$cleared[] = '_custom_score_cache';
				return $cleared;
			}
			return $value;
		} );

		$config = $this->invokeStatic( MetaTranslationService::class, 'get_effective_meta_key_config', array( 0 ) );

		$this->assertContains( '_custom_score_cache', $config['clear'] );
	}

	/**
	 * Test that per-key filter slytranslate_translate_meta_key can remove a key from translate list.
	 */
	public function test_per_key_filter_false_removes_key(): void {
		// First, add a key via the list filter
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_meta_keys_translate' === $tag ) {
				$translated = (array) $value;
				$translated[] = 'page_content';
				return $translated;
			}

			// Per-key filter: deny page_content
			if ( 'slytranslate_translate_meta_key' === $tag ) {
				$meta_key = $args[0] ?? null;
				if ( 'page_content' === $meta_key ) {
					return false;
				}
			}

			return $value;
		} );

		$config = $this->invokeStatic( MetaTranslationService::class, 'get_effective_meta_key_config', array( 0 ) );

		$this->assertNotContains( 'page_content', $config['translate'] );
	}

	/**
	 * Test that per-key filter returns true for keys in list.
	 */
	public function test_per_key_filter_true_keeps_key(): void {
		// Add key via list filter
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_meta_keys_translate' === $tag ) {
				$translated = (array) $value;
				$translated[] = 'acf_field_1';
				return $translated;
			}

			// Per-key filter: allow acf_field_1
			if ( 'slytranslate_translate_meta_key' === $tag ) {
				$meta_key = $args[0] ?? null;
				if ( 'acf_field_1' === $meta_key ) {
					return true;
				}
			}

			return $value;
		} );

		$config = $this->invokeStatic( MetaTranslationService::class, 'get_effective_meta_key_config', array( 0 ) );

		$this->assertContains( 'acf_field_1', $config['translate'] );
	}

	/**
	 * Test backward compatibility: without filters and without SEO plugin or
	 * user config, only the plugin default keys (image alt text) remain.
	 */
	public function test_no_filter_backward_compatible(): void {
		$config = $this->invokeStatic( MetaTranslationService::class, 'get_effective_meta_key_config', array( 0 ) );

		$this->assertIsArray( $config );
		$this->assertSame( MetaTranslationService::DEFAULT_TRANSLATE_META_KEYS, $config['translate'] );
		$this->assertSame( array(), $config['clear'] );
	}

	/**
	 * Test that per-key filter receives all correct arguments.
	 */
	public function test_per_key_filter_receives_all_args(): void {
		$captured_args = array();

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) use ( &$captured_args ) {
			if ( 'slytranslate_meta_keys_translate' === $tag ) {
				$translated = (array) $value;
				$translated[] = 'test_key';
				return $translated;
			}

			if ( 'slytranslate_translate_meta_key' === $tag ) {
				$meta_key = $args[0] ?? null;
				if ( 'test_key' === $meta_key ) {
					$captured_args = array(
						'default'   => $value,
						'meta_key'  => $args[0] ?? null,
						'post_id'   => $args[1] ?? null,
						'from'      => $args[2] ?? null,
						'to'        => $args[3] ?? null,
						'post_meta' => $args[4] ?? null,
					);
				}
			}

			return $value;
		} );

		$post_meta = array( 'test_key' => array( 'test_value' ) );
		$this->invokeStatic( MetaTranslationService::class, 'get_effective_meta_key_config', array( 99, $post_meta, 'fr', 'pt' ) );

		$this->assertSame( true, $captured_args['default'] );
		$this->assertSame( 'test_key', $captured_args['meta_key'] );
		$this->assertSame( 99, $captured_args['post_id'] );
		$this->assertSame( 'fr', $captured_args['from'] );
		$this->assertSame( 'pt', $captured_args['to'] );
		$this->assertIsArray( $captured_args['post_meta'] );
	}

	/**
	 * Test that internal meta keys are still filtered out by per-key filter.
	 */
	public function test_internal_keys_filtered_by_per_key_check(): void {
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_meta_keys_translate' === $tag ) {
				// Try to add internal keys
				$translated = (array) $value;
				$translated[] = '_edit_lock';  // Should be filtered by should_skip_meta_key
				$translated[] = 'custom_key';
				return $translated;
			}

			return $value;
		} );

		$config = $this->invokeStatic( MetaTranslationService::class, 'get_effective_meta_key_config', array( 0 ) );

		// _edit_lock should be filtered by should_skip_meta_key before per-key filter is called
		$this->assertNotContains( '_edit_lock', $config['translate'] );
		$this->assertContains( 'custom_key', $config['translate'] );
	}
}
