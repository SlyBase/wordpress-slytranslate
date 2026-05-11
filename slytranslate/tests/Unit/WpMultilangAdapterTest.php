<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\WpMultilangAdapter;

class WpMultilangAdapterTest extends TestCase {

	private WpMultilangAdapter $adapter;

	protected function setUp(): void {
		parent::setUp();
		$this->adapter = new WpMultilangAdapter();
	}

	public function test_is_available_returns_false_without_wp_multilang_constant(): void {
		$this->assertFalse( $this->adapter->is_available() );
	}

	public function test_get_languages_returns_language_map(): void {
		$this->enableWpMultilangEnvironment();

		$this->assertSame(
			array(
				'en' => 'English',
				'de' => 'Deutsch',
			),
			$this->adapter->get_languages()
		);
	}

	public function test_get_post_language_returns_current_language_when_enabled(): void {
		$this->enableWpMultilangEnvironment( current_language: 'de' );

		$this->assertSame( 'de', $this->adapter->get_post_language( 123 ) );
	}

	public function test_get_post_language_falls_back_to_default_when_current_language_is_unknown(): void {
		$this->enableWpMultilangEnvironment( current_language: 'fr' );

		$this->assertSame( 'en', $this->adapter->get_post_language( 123 ) );
	}

	public function test_get_language_variant_returns_plain_value_for_default_language(): void {
		$this->enableWpMultilangEnvironment();

		$this->assertSame( 'Hello world', $this->adapter->get_language_variant( 'Hello world', 'en' ) );
		$this->assertSame( '', $this->adapter->get_language_variant( 'Hello world', 'de' ) );
	}

	public function test_get_language_variant_extracts_marked_segment(): void {
		$this->enableWpMultilangEnvironment();

		$value = '[:en]Hello world[:de]Hallo Welt[:]';

		$this->assertSame( 'Hallo Welt', $this->adapter->get_language_variant( $value, 'de' ) );
		$this->assertSame( '', $this->adapter->get_language_variant( $value, 'fr' ) );
	}

	public function test_get_post_translations_returns_only_non_empty_content_variants(): void {
		$this->enableWpMultilangEnvironment(
			languages: array(
				'en' => array( 'name' => 'English' ),
				'de' => array( 'name' => 'Deutsch' ),
				'fr' => array( 'name' => 'Francais' ),
			)
		);

		$this->stubWpFunction(
			'get_post',
			static fn ( int $post_id ) => new \WP_Post(
				array(
					'ID'           => $post_id,
					'post_content' => '[:en]Hello[:de]Hallo[:fr][:]'
				)
			)
		);

		$this->assertSame(
			array(
				'en' => 55,
				'de' => 55,
			),
			$this->adapter->get_post_translations( 55 )
		);
	}

	public function test_create_translation_returns_error_when_target_translation_exists_without_overwrite(): void {
		$this->enableWpMultilangEnvironment();

		$this->stubWpFunction(
			'get_post',
			static fn ( int $post_id ) => new \WP_Post(
				array(
					'ID'           => $post_id,
					'post_title'   => '[:en]Hello title[:de]Hallo Titel[:]',
					'post_content' => '[:en]Hello content[:de]Hallo Inhalt[:]',
					'post_excerpt' => '[:en]Hello excerpt[:de]Hallo Auszug[:]',
				)
			)
		);

		$result = $this->adapter->create_translation( 123, 'de', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'translation_exists', $result->get_error_code() );
	}

	public function test_create_translation_merges_fields_and_updates_meta(): void {
		$this->enableWpMultilangEnvironment();

		$captured_update = array();
		$meta_updates    = array();

		$this->stubWpFunction(
			'get_post',
			static fn ( int $post_id ) => new \WP_Post(
				array(
					'ID'           => $post_id,
					'post_title'   => 'Hello title',
					'post_content' => 'Hello content',
					'post_excerpt' => 'Hello excerpt',
				)
			)
		);

		$this->stubWpFunction(
			'get_post_meta',
			static function ( int $post_id, string $key = '', bool $single = false ) {
				return match ( $key ) {
					'_languages' => array( 'en' ),
					'seo_title' => 'Hello SEO',
					default => $single ? '' : array(),
				};
			}
		);

		$this->stubWpFunction(
			'wp_update_post',
			static function ( array $postarr, bool $wp_error = false ) use ( &$captured_update ) {
				$captured_update = $postarr;
				return $postarr['ID'] ?? 0;
			}
		);

		$this->stubWpFunction(
			'update_post_meta',
			static function ( int $post_id, string $meta_key, mixed $meta_value ) use ( &$meta_updates ) {
				$meta_updates[ $meta_key ] = $meta_value;
				return true;
			}
		);

		$result = $this->adapter->create_translation(
			123,
			'de',
			array(
				'post_title'      => 'Hallo Titel',
				'post_content'    => 'Hallo Inhalt',
				'post_excerpt'    => 'Hallo Auszug',
				'source_language' => 'en',
				'meta'            => array(
					'seo_title' => 'Hallo SEO',
				),
			)
		);

		$this->assertSame( 123, $result );
		$this->assertSame( '[:en]Hello title[:de]Hallo Titel[:]', $captured_update['post_title'] ?? '' );
		$this->assertSame( '[:en]Hello content[:de]Hallo Inhalt[:]', $captured_update['post_content'] ?? '' );
		$this->assertSame( '[:en]Hello excerpt[:de]Hallo Auszug[:]', $captured_update['post_excerpt'] ?? '' );
		$this->assertSame( '[:en]Hello SEO[:de]Hallo SEO[:]', $meta_updates['seo_title'] ?? '' );
		$this->assertSame( array( 'en', 'de' ), $meta_updates['_languages'] ?? array() );
	}

	public function test_merge_language_value_returns_existing_value_when_target_language_empty(): void {
		$this->enableWpMultilangEnvironment();

		$existing = '[:en]Hello[:]';
		$result   = $this->invokeMethod(
			$this->adapter,
			'merge_language_value',
			array( $existing, 'en', '', 'Hallo', 'Hello' )
		);

		$this->assertSame( $existing, $result );
	}

	private function enableWpMultilangEnvironment(
		?array $languages = null,
		string $default_language = 'en',
		string $current_language = 'en'
	): void {
		if ( ! defined( 'WPM_PLUGIN_FILE' ) ) {
			define( 'WPM_PLUGIN_FILE', '/tmp/wp-multilang/wp-multilang.php' );
		}

		$languages = $languages ?? array(
			'en' => array( 'name' => 'English' ),
			'de' => array( 'name' => 'Deutsch' ),
		);

		$this->stubWpFunctionReturn( 'wpm_get_languages', $languages );
		$this->stubWpFunctionReturn( 'wpm_get_default_language', $default_language );
		$this->stubWpFunctionReturn( 'wpm_get_language', $current_language );
	}

	private function invokeMethod( object $object, string $method, array $args = array() ): mixed {
		$reflection = new \ReflectionMethod( $object, $method );
		return $reflection->invoke( $object, ...$args );
	}
}