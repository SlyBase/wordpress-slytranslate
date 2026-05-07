<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\TranslatePressAdapter;

/**
 * Testable subclass that exposes `get_trp_query()` as injectable seam
 * and overrides `is_available()` so tests can run without TRP installed.
 */
class TestablePressAdapter extends TranslatePressAdapter {

	public ?object $mock_query    = null;
	public bool    $force_available = false;

	public function is_available(): bool {
		return $this->force_available || parent::is_available();
	}

	protected function get_trp_query(): ?object {
		return $this->mock_query;
	}
}

/**
 * Simple TRP_Query spy – records calls and allows pre-configuring return values.
 */
class SpyTrpQuery {

	/** @var array<int, array{rows: array<int, array<string, mixed>>, lang: string}> */
	public array $update_calls = array();

	/** @var array<int, array{strings: string[], lang: string}> */
	public array $insert_calls = array();

	/** @var array<int, object> Rows returned by get_existing_translations. */
	public array $existing_result = array();

	/** @var array<int, object> Rows returned by get_string_ids. */
	public array $string_ids_result = array();

	/** @return array<int, object> */
	public function get_existing_translations( array $strings, string $lang ): array {
		return $this->existing_result;
	}

	/** @return array<int, object> */
	public function get_string_ids( array $strings, string $lang ): array {
		return $this->string_ids_result;
	}

	public function insert_strings( array $strings, string $lang ): void {
		$this->insert_calls[] = array( 'strings' => $strings, 'lang' => $lang );
	}

	public function update_strings( array $rows, string $lang ): void {
		$this->update_calls[] = array( 'rows' => $rows, 'lang' => $lang );
	}
}

class TranslatePressAdapterTest extends TestCase {

	private TestablePressAdapter $adapter;

	protected function setUp(): void {
		parent::setUp();
		$this->adapter = new TestablePressAdapter();
	}

	// -----------------------------------------------------------------------
	// is_available
	// -----------------------------------------------------------------------

	public function test_is_available_returns_false_without_trp(): void {
		// TRP_Translate_Press class is not defined in this test environment.
		$this->assertFalse( $this->adapter->is_available() );
	}

	public function test_is_available_returns_true_when_forced(): void {
		$this->adapter->force_available = true;
		$this->assertTrue( $this->adapter->is_available() );
	}

	// -----------------------------------------------------------------------
	// get_post_language
	// -----------------------------------------------------------------------

	public function test_get_post_language_returns_default_locale_as_iso2(): void {
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$result = $this->adapter->get_post_language( 1 );
		$this->assertSame( 'de', $result );
	}

	public function test_get_post_language_returns_null_when_no_default(): void {
		$this->stubWpFunctionReturn( 'get_option', array() );
		$this->assertNull( $this->adapter->get_post_language( 1 ) );
	}

	public function test_get_post_language_handles_en_us_locale(): void {
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'en_US',
			'translation-languages' => array( 'en_US', 'de_DE' ),
		) );

		$this->assertSame( 'en', $this->adapter->get_post_language( 1 ) );
	}

	// -----------------------------------------------------------------------
	// locale_to_iso2 (via reflection)
	// -----------------------------------------------------------------------

	public function test_locale_to_iso2_de_de(): void {
		$result = $this->invokeMethod( $this->adapter, 'locale_to_iso2', array( 'de_DE' ) );
		$this->assertSame( 'de', $result );
	}

	public function test_locale_to_iso2_en_us(): void {
		$result = $this->invokeMethod( $this->adapter, 'locale_to_iso2', array( 'en_US' ) );
		$this->assertSame( 'en', $result );
	}

	public function test_locale_to_iso2_fr_fr(): void {
		$result = $this->invokeMethod( $this->adapter, 'locale_to_iso2', array( 'fr_FR' ) );
		$this->assertSame( 'fr', $result );
	}

	public function test_locale_to_iso2_pt_br(): void {
		$result = $this->invokeMethod( $this->adapter, 'locale_to_iso2', array( 'pt_BR' ) );
		$this->assertSame( 'pt', $result );
	}

	// -----------------------------------------------------------------------
	// iso2_to_locale (via reflection)
	// -----------------------------------------------------------------------

	public function test_iso2_to_locale_finds_first_match(): void {
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$result = $this->invokeMethod( $this->adapter, 'iso2_to_locale', array( 'de' ) );
		$this->assertSame( 'de_DE', $result );
	}

	public function test_iso2_to_locale_en(): void {
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$result = $this->invokeMethod( $this->adapter, 'iso2_to_locale', array( 'en' ) );
		$this->assertSame( 'en_US', $result );
	}

	public function test_iso2_to_locale_returns_null_for_unknown(): void {
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$result = $this->invokeMethod( $this->adapter, 'iso2_to_locale', array( 'fr' ) );
		$this->assertNull( $result );
	}

	// -----------------------------------------------------------------------
	// get_languages
	// -----------------------------------------------------------------------

	public function test_get_languages_excludes_default_language(): void {
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$languages = $this->adapter->get_languages();
		$this->assertArrayNotHasKey( 'de', $languages );
		$this->assertArrayHasKey( 'en', $languages );
	}

	public function test_get_languages_returns_empty_when_no_settings(): void {
		$this->stubWpFunctionReturn( 'get_option', array() );
		$this->assertSame( array(), $this->adapter->get_languages() );
	}

	public function test_get_languages_uses_static_label_map(): void {
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$languages = $this->adapter->get_languages();
		$this->assertSame( 'English (US)', $languages['en'] );
	}

	// -----------------------------------------------------------------------
	// extract_string_segments (via reflection)
	// -----------------------------------------------------------------------

	public function test_extract_string_segments_plain_paragraph(): void {
		$result = $this->invokeMethod( $this->adapter, 'extract_string_segments', array( '<p>Text</p>' ) );
		$this->assertSame( array( 'Text' ), $result );
	}

	public function test_extract_string_segments_paragraph_with_link(): void {
		$result = $this->invokeMethod( $this->adapter, 'extract_string_segments', array( '<p>Vor <a>Link</a> nach</p>' ) );
		$this->assertSame( array( 'Vor ', 'Link', ' nach' ), $result );
	}

	public function test_extract_string_segments_paragraph_with_code(): void {
		$result = $this->invokeMethod( $this->adapter, 'extract_string_segments', array( '<p>AI (<code>fn()</code>)</p>' ) );
		$this->assertSame( array( 'AI (', 'fn()', ')' ), $result );
	}

	public function test_extract_string_segments_heading(): void {
		$result = $this->invokeMethod( $this->adapter, 'extract_string_segments', array( '<h2>Heading</h2>' ) );
		$this->assertSame( array( 'Heading' ), $result );
	}

	public function test_extract_string_segments_preserves_order(): void {
		$html   = '<p>First</p><p>Second</p><p>Third</p>';
		$result = $this->invokeMethod( $this->adapter, 'extract_string_segments', array( $html ) );
		$this->assertSame( array( 'First', 'Second', 'Third' ), $result );
	}

	public function test_extract_string_segments_filters_whitespace_only(): void {
		$html   = "<p>Content</p>\n   \n<p>More</p>";
		$result = $this->invokeMethod( $this->adapter, 'extract_string_segments', array( $html ) );
		$this->assertSame( array( 'Content', 'More' ), $result );
	}

	public function test_extract_string_segments_returns_empty_for_empty_html(): void {
		$result = $this->invokeMethod( $this->adapter, 'extract_string_segments', array( '' ) );
		$this->assertSame( array(), $result );
	}

	public function test_extract_string_segments_returns_empty_for_whitespace_only_html(): void {
		$result = $this->invokeMethod( $this->adapter, 'extract_string_segments', array( '   ' ) );
		$this->assertSame( array(), $result );
	}

	public function test_extract_string_segments_strong_element(): void {
		$result = $this->invokeMethod( $this->adapter, 'extract_string_segments', array( '<p>Hello <strong>world</strong>!</p>' ) );
		$this->assertSame( array( 'Hello ', 'world', '!' ), $result );
	}

	// -----------------------------------------------------------------------
	// create_translation – error cases
	// -----------------------------------------------------------------------

	public function test_create_translation_returns_error_when_not_available(): void {
		$result = $this->adapter->create_translation( 1, 'en', array( 'post_title' => 'Title' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'translatepress_not_available', $result->get_error_code() );
	}

	public function test_create_translation_returns_error_for_missing_post(): void {
		$this->adapter->force_available = true;
		$this->stubWpFunctionReturn( 'get_post', null );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$result = $this->adapter->create_translation( 999, 'en', array( 'post_title' => 'Title' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'source_post_not_found', $result->get_error_code() );
	}

	public function test_create_translation_returns_error_for_invalid_language(): void {
		$this->adapter->force_available = true;

		$post            = new \WP_Post();
		$post->ID        = 1;
		$post->post_title   = 'Titel';
		$post->post_content = '';
		$post->post_excerpt = '';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$result = $this->adapter->create_translation( 1, 'fr', array( 'post_title' => 'Title' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_target_language', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// create_translation – success path
	// -----------------------------------------------------------------------

	public function test_create_translation_returns_source_post_id(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		// Pre-configure string_ids_result so save_string_pairs builds update rows.
		$id_row              = new \stdClass();
		$id_row->original    = 'Titel';
		$id_row->id          = 42;
		$id_row->original_id = 10;
		$spy->string_ids_result = array( $id_row );

		$post              = new \WP_Post();
		$post->ID          = 7;
		$post->post_title  = 'Titel';
		$post->post_content = '';
		$post->post_excerpt = '';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$result = $this->adapter->create_translation( 7, 'en', array( 'post_title' => 'Title', 'overwrite' => true ) );
		$this->assertSame( 7, $result );
	}

	public function test_create_translation_saves_string_pairs_with_status_2(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		$id_row              = new \stdClass();
		$id_row->original    = 'Titel';
		$id_row->id          = 42;
		$id_row->original_id = 10;
		$spy->string_ids_result = array( $id_row );

		$post               = new \WP_Post();
		$post->ID           = 7;
		$post->post_title   = 'Titel';
		$post->post_content = '';
		$post->post_excerpt = '';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$this->adapter->create_translation( 7, 'en', array( 'post_title' => 'Title', 'overwrite' => true ) );

		$this->assertCount( 1, $spy->update_calls );
		$row = $spy->update_calls[0]['rows'][0];
		$this->assertSame( 2, $row['status'] );
		$this->assertSame( 'Title', $row['translated'] );
		$this->assertSame( 'Titel', $row['original'] );
	}

	public function test_create_translation_inserts_missing_strings(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		// No existing translations → insert should be triggered.
		$spy->existing_result = array();

		// After insert, return IDs for the update step.
		$id_row              = new \stdClass();
		$id_row->original    = 'Titel';
		$id_row->id          = 1;
		$id_row->original_id = 1;
		$spy->string_ids_result = array( $id_row );

		$post               = new \WP_Post();
		$post->ID           = 5;
		$post->post_title   = 'Titel';
		$post->post_content = '';
		$post->post_excerpt = '';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$this->adapter->create_translation( 5, 'en', array( 'post_title' => 'Title', 'overwrite' => true ) );

		$this->assertCount( 1, $spy->insert_calls );
		$this->assertContains( 'Titel', $spy->insert_calls[0]['strings'] );
		$this->assertSame( 'en_US', $spy->insert_calls[0]['lang'] );
	}

	public function test_create_translation_inserts_trimmed_lookup_variants_for_link_boundary_segments(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		foreach (
			array(
				'In diesem ersten Teil zeige ich, wie die Einrichtung aussieht: von dem WordPress Connector, der über ',
				'In diesem ersten Teil zeige ich, wie die Einrichtung aussieht: von dem WordPress Connector, der über',
				' die erste echte Übersetzung eines Beitrags durchführt.',
				'die erste echte Übersetzung eines Beitrags durchführt.',
			) as $index => $original
		) {
			$id_row              = new \stdClass();
			$id_row->original    = $original;
			$id_row->id          = $index + 1;
			$id_row->original_id = $index + 100;
			$spy->string_ids_result[] = $id_row;
		}

		$post               = new \WP_Post();
		$post->ID           = 15;
		$post->post_title   = 'Titel';
		$post->post_excerpt = '';
		$post->post_content = '<p>In diesem ersten Teil zeige ich, wie die Einrichtung aussieht: von dem WordPress Connector, der über&nbsp;<a href="https://github.com/SlyBase/wordpress-slytranslate">SlyTranslate</a>&nbsp;die erste echte Übersetzung eines Beitrags durchführt.</p>';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$this->adapter->create_translation(
			15,
			'en',
			array(
				'post_content' => '<p>In this first part, I show what the setup looks like: from the WordPress Connector, which performs the first real translation of a post via <a href="https://github.com/SlyBase/wordpress-slytranslate">SlyTranslate</a>.</p>',
				'overwrite'    => true,
			)
		);

		$this->assertCount( 1, $spy->insert_calls );
		$this->assertContains(
			'In diesem ersten Teil zeige ich, wie die Einrichtung aussieht: von dem WordPress Connector, der über ',
			$spy->insert_calls[0]['strings']
		);
		$this->assertContains(
			'In diesem ersten Teil zeige ich, wie die Einrichtung aussieht: von dem WordPress Connector, der über',
			$spy->insert_calls[0]['strings']
		);
		$this->assertContains(
			' die erste echte Übersetzung eines Beitrags durchführt.',
			$spy->insert_calls[0]['strings']
		);
		$this->assertContains(
			'die erste echte Übersetzung eines Beitrags durchführt.',
			$spy->insert_calls[0]['strings']
		);

		$this->assertCount( 1, $spy->update_calls );
		$updated_originals = array_column( $spy->update_calls[0]['rows'], 'original' );
		$this->assertContains(
			'In diesem ersten Teil zeige ich, wie die Einrichtung aussieht: von dem WordPress Connector, der über ',
			$updated_originals
		);
		$this->assertContains(
			'In diesem ersten Teil zeige ich, wie die Einrichtung aussieht: von dem WordPress Connector, der über',
			$updated_originals
		);
		$this->assertContains(
			' die erste echte Übersetzung eines Beitrags durchführt.',
			$updated_originals
		);
		$this->assertContains(
			'die erste echte Übersetzung eines Beitrags durchführt.',
			$updated_originals
		);
	}

	// -----------------------------------------------------------------------
	// link_translation
	// -----------------------------------------------------------------------

	public function test_link_translation_is_noop_and_returns_true(): void {
		$result = $this->adapter->link_translation( 1, 2, 'en' );
		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function invokeMethod( object $object, string $method, array $args = array() ): mixed {
		$reflection = new \ReflectionMethod( $object, $method );
		return $reflection->invoke( $object, ...$args );
	}
}
