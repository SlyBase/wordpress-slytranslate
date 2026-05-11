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

	/** @var array<int, array{strings: string[], lang: string}> */
	public array $existing_calls = array();

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
		$this->existing_calls[] = array( 'strings' => $strings, 'lang' => $lang );
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

class FakeWpdb {
	public string $prefix = 'wp_';

	/** @var array<int, object> */
	public array $original_rows = array();

	/** @var array<int, object> */
	public array $dictionary_rows = array();

	/** @var array<int, array<string, mixed>> */
	public array $update_calls = array();

	/** @var array<int, array<string, mixed>> */
	public array $insert_calls = array();

	public function prepare( string $query, ...$args ): array {
		while ( false !== strpos( $query, '%i' ) && ! empty( $args ) ) {
			$query = preg_replace( '/%i/', (string) array_shift( $args ), $query, 1 ) ?? $query;
		}

		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	/** @return array<int, object> */
	public function get_results( $prepared ): array {
		$query = is_array( $prepared ) ? (string) ( $prepared['query'] ?? '' ) : (string) $prepared;
		$args  = is_array( $prepared ) ? (array) ( $prepared['args'] ?? array() ) : array();

		if ( false !== strpos( $query, 'trp_original_strings' ) ) {
			$wanted = array_fill_keys( array_map( 'strval', $args ), true );
			return array_values( array_filter( $this->original_rows, static function ( $row ) use ( $wanted ) {
				return isset( $row->original ) && isset( $wanted[ (string) $row->original ] );
			} ) );
		}

		if ( false !== strpos( $query, 'trp_dictionary_' ) ) {
			$wanted = array_fill_keys( array_map( 'intval', $args ), true );
			return array_values( array_filter( $this->dictionary_rows, static function ( $row ) use ( $wanted ) {
				return isset( $row->original_id ) && isset( $wanted[ (int) $row->original_id ] );
			} ) );
		}

		return array();
	}

	public function update( string $table, array $data, array $where ): int {
		$this->update_calls[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);

		return 1;
	}

	public function insert( string $table, array $data ): int {
		$this->insert_calls[] = array(
			'table' => $table,
			'data'  => $data,
		);

		return 1;
	}
}

class TranslatePressAdapterTest extends TestCase {

	private TestablePressAdapter $adapter;
	private mixed $previous_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		$this->adapter = new TestablePressAdapter();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}

		parent::tearDown();
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

	public function test_get_post_translation_for_language_queries_only_requested_locale(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		$existing             = new \stdClass();
		$existing->translated = 'Title';
		$existing->status     = 2;
		$spy->existing_result = array( $existing );

		$post               = new \WP_Post();
		$post->ID           = 7;
		$post->post_title   = 'Titel';
		$post->post_content = '';
		$post->post_excerpt = '';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US', 'fr_FR' ),
		) );

		$result = $this->adapter->get_post_translation_for_language( 7, 'en' );

		$this->assertSame( 7, $result );
		$this->assertCount( 1, $spy->existing_calls );
		$this->assertSame( array( 'Titel' ), $spy->existing_calls[0]['strings'] );
		$this->assertSame( 'en_US', $spy->existing_calls[0]['lang'] );
	}

	public function test_get_post_translation_for_language_uses_request_cache(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		$existing             = new \stdClass();
		$existing->translated = 'Title';
		$existing->status     = 2;
		$spy->existing_result = array( $existing );

		$post               = new \WP_Post();
		$post->ID           = 7;
		$post->post_title   = 'Titel';
		$post->post_content = '';
		$post->post_excerpt = '';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US', 'fr_FR' ),
		) );

		$this->assertSame( 7, $this->adapter->get_post_translation_for_language( 7, 'en' ) );
		$this->assertSame( 7, $this->adapter->get_post_translation_for_language( 7, 'en' ) );

		$this->assertCount( 1, $spy->existing_calls );
	}

	public function test_get_string_translation_prefers_exact_lookup_key_match(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		$exact             = new \stdClass();
		$exact->original   = 'Wähle Text → "Übersetzen".';
		$exact->translated = 'Select text -> "Translate".';
		$exact->status     = 2;

		$texturized             = new \stdClass();
		$texturized->original   = 'Wähle Text → &#8222;Übersetzen&#8220;.';
		$texturized->translated = 'Select text -> "Translate".';
		$texturized->status     = 2;

		$spy->existing_result = array( $texturized, $exact );

		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$this->assertSame( 'Select text -> "Translate".', $this->adapter->get_string_translation( 'Wähle Text → "Übersetzen".', 'en' ) );
		$this->assertSame( 'en_US', $spy->existing_calls[0]['lang'] );
		$this->assertContains( 'Wähle Text → "Übersetzen".', $spy->existing_calls[0]['strings'] );
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

	public function test_create_translation_existing_check_queries_only_target_locale(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		$existing             = new \stdClass();
		$existing->translated = 'Title';
		$existing->status     = 2;
		$spy->existing_result = array( $existing );

		$post               = new \WP_Post();
		$post->ID           = 7;
		$post->post_title   = 'Titel';
		$post->post_content = '';
		$post->post_excerpt = '';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US', 'fr_FR' ),
		) );

		$result = $this->adapter->create_translation( 7, 'en', array( 'post_title' => 'Title' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'translation_exists', $result->get_error_code() );
		$this->assertCount( 1, $spy->existing_calls );
		$this->assertSame( 'en_US', $spy->existing_calls[0]['lang'] );
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

	public function test_create_translation_persists_real_original_string_ids_via_tables(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;
		$wpdb                           = new FakeWpdb();
		$GLOBALS['wpdb']                = $wpdb;

		$id_row           = new \stdClass();
		$id_row->original = 'Titel';
		$id_row->id       = 42;
		$spy->string_ids_result = array( $id_row );

		$original_row           = new \stdClass();
		$original_row->id       = 10;
		$original_row->original = 'Titel';
		$wpdb->original_rows    = array( $original_row );

		$dictionary_row              = new \stdClass();
		$dictionary_row->id          = 99;
		$dictionary_row->original_id = 10;
		$wpdb->dictionary_rows       = array( $dictionary_row );

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

		$this->assertCount( 0, $spy->update_calls );
		$this->assertCount( 1, $wpdb->update_calls );
		$this->assertSame( 'wp_trp_dictionary_de_de_en_us', $wpdb->update_calls[0]['table'] );
		$this->assertSame( array( 'id' => 99 ), $wpdb->update_calls[0]['where'] );
		$this->assertSame( 10, $wpdb->update_calls[0]['data']['original_id'] );
	}

	public function test_create_translation_inserts_new_dictionary_row_with_real_original_string_id(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;
		$wpdb                           = new FakeWpdb();
		$GLOBALS['wpdb']                = $wpdb;

		$id_row           = new \stdClass();
		$id_row->original = 'Titel';
		$id_row->id       = 42;
		$spy->string_ids_result = array( $id_row );

		$original_row           = new \stdClass();
		$original_row->id       = 10;
		$original_row->original = 'Titel';
		$wpdb->original_rows    = array( $original_row );

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

		$this->assertCount( 1, $wpdb->insert_calls );
		$this->assertSame( 'wp_trp_dictionary_de_de_en_us', $wpdb->insert_calls[0]['table'] );
		$this->assertSame( 10, $wpdb->insert_calls[0]['data']['original_id'] );
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
	// build_segment_lookup_keys – wptexturize render-aware variants
	// -----------------------------------------------------------------------

	public function test_build_segment_lookup_keys_includes_wptexturize_variant(): void {
		// Simulate wptexturize converting straight double-quotes to entity form.
		$this->stubWpFunctionReturn(
			'wptexturize',
			'Wähle Text → Werkzeugleiste → &#8222;Übersetzen&#8220;. Das funktioniert.'
		);

		$keys = $this->invokeMethod(
			$this->adapter,
			'build_segment_lookup_keys',
			array( 'Wähle Text → Werkzeugleiste → "Übersetzen". Das funktioniert.' )
		);

		$this->assertContains(
			'Wähle Text → Werkzeugleiste → &#8222;Übersetzen&#8220;. Das funktioniert.',
			$keys,
			'Expected entity-encoded texturized variant in lookup keys'
		);
	}

	public function test_build_segment_lookup_keys_includes_utf8_texturize_variant_and_entity_form(): void {
		// Simulate wptexturize returning UTF-8 typographic characters (not entities).
		// „ = U+201E DOUBLE LOW-9 QUOTATION MARK, " = U+201C LEFT DOUBLE QUOTATION MARK
		$this->stubWpFunctionReturn(
			'wptexturize',
			"Wähle Text → \u{201E}Übersetzen\u{201C}. Das funktioniert."
		);

		$keys = $this->invokeMethod(
			$this->adapter,
			'build_segment_lookup_keys',
			array( 'Wähle Text → "Übersetzen". Das funktioniert.' )
		);

		// The UTF-8 typographic form must be present.
		$this->assertContains(
			"Wähle Text → \u{201E}Übersetzen\u{201C}. Das funktioniert.",
			$keys,
			'Expected UTF-8 typographic variant in lookup keys'
		);
		// The entity-encoded equivalent must also be present.
		$this->assertContains(
			'Wähle Text → &#8222;Übersetzen&#8220;. Das funktioniert.',
			$keys,
			'Expected entity-encoded form of typographic variant in lookup keys'
		);
	}

	public function test_build_segment_lookup_keys_with_leading_whitespace_and_texturize(): void {
		// Segment has a leading space (from a link boundary) and straight quotes.
		$this->stubWpFunctionReturn(
			'wptexturize',
			'Wähle Text → &#8222;Übersetzen&#8220;. Funktioniert.'
		);

		$keys = $this->invokeMethod(
			$this->adapter,
			'build_segment_lookup_keys',
			array( ' Wähle Text → "Übersetzen". Funktioniert.' )
		);

		// Raw variants (with and without leading space).
		$this->assertContains( ' Wähle Text → "Übersetzen". Funktioniert.', $keys );
		$this->assertContains( 'Wähle Text → "Übersetzen". Funktioniert.', $keys );
		// Texturized trimmed form (without leading space).
		$this->assertContains( 'Wähle Text → &#8222;Übersetzen&#8220;. Funktioniert.', $keys );
		// Texturized form with original whitespace prefix.
		$this->assertContains( ' Wähle Text → &#8222;Übersetzen&#8220;. Funktioniert.', $keys );
	}

	public function test_build_segment_lookup_keys_no_extra_keys_when_wptexturize_unchanged(): void {
		// wptexturize returns the same text (e.g. for URLs or plain text without quotes).
		$this->stubWpFunctionReturn( 'wptexturize', 'https://example.com/path' );

		$keys = $this->invokeMethod(
			$this->adapter,
			'build_segment_lookup_keys',
			array( 'https://example.com/path' )
		);

		// Only the raw key; no texturize variants added.
		$this->assertCount( 1, $keys );
		$this->assertContains( 'https://example.com/path', $keys );
	}

	public function test_build_segment_lookup_keys_capped_at_eight(): void {
		// Simulate wptexturize producing a different result (to trigger variant generation)
		// for a segment that already has two raw keys (normalized + trimmed).
		$this->stubWpFunctionReturn(
			'wptexturize',
			'&#8220;A&#8221; &#8216;B&#8217; &#8211; &#8212; &#8230;'
		);

		// Leading space triggers additional whitespace variants, so many keys could be generated.
		$keys = $this->invokeMethod(
			$this->adapter,
			'build_segment_lookup_keys',
			array( ' "A" \'B\' -- --- ...' )
		);

		$this->assertLessThanOrEqual( 8, count( $keys ), 'Lookup key count must not exceed 8' );
	}

	public function test_build_segment_lookup_keys_empty_returns_empty(): void {
		$keys = $this->invokeMethod( $this->adapter, 'build_segment_lookup_keys', array( '' ) );
		$this->assertSame( array(), $keys );
	}

	public function test_build_segment_lookup_keys_whitespace_only_returns_empty(): void {
		$keys = $this->invokeMethod( $this->adapter, 'build_segment_lookup_keys', array( '   ' ) );
		$this->assertSame( array(), $keys );
	}

	// -----------------------------------------------------------------------
	// encode_typographic_chars (via reflection)
	// -----------------------------------------------------------------------

	public function test_encode_typographic_chars_left_right_double_quotes(): void {
		$result = $this->invokeMethod(
			$this->adapter,
			'encode_typographic_chars',
			array( "\u{201C}Übersetzen\u{201D}" ) // "Übersetzen"
		);
		$this->assertSame( '&#8220;Übersetzen&#8221;', $result );
	}

	public function test_encode_typographic_chars_german_low_nine(): void {
		$result = $this->invokeMethod(
			$this->adapter,
			'encode_typographic_chars',
			array( "\u{201E}Übersetzen\u{201C}" ) // „Übersetzen"
		);
		$this->assertSame( '&#8222;Übersetzen&#8220;', $result );
	}

	public function test_encode_typographic_chars_leaves_ascii_and_arrows_unchanged(): void {
		$result = $this->invokeMethod(
			$this->adapter,
			'encode_typographic_chars',
			array( 'Wähle Text → Werkzeugleiste → Übersetzen.' )
		);
		$this->assertSame( 'Wähle Text → Werkzeugleiste → Übersetzen.', $result );
	}

	public function test_encode_typographic_chars_en_dash_and_ellipsis(): void {
		$result = $this->invokeMethod(
			$this->adapter,
			'encode_typographic_chars',
			array( "Punkt\u{2026} Strich\u{2013}Ende" ) // …–
		);
		$this->assertSame( 'Punkt&#8230; Strich&#8211;Ende', $result );
	}

	// -----------------------------------------------------------------------
	// create_translation – texturize variants are saved with same translation
	// -----------------------------------------------------------------------

	public function test_create_translation_saves_texturize_variant_with_same_translation(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		// Simulate wptexturize returning entity-encoded quotes for the segment.
		$this->stubWpFunction( 'wptexturize', static function ( string $text ): string {
			return str_replace( '"', '&#8220;', str_replace( '"', '&#8222;', $text ) );
		} );

		// ID rows for both the raw and the texturized variants.
		$raw_row              = new \stdClass();
		$raw_row->original    = 'Wähle Text → "Übersetzen".';
		$raw_row->id          = 10;
		$raw_row->original_id = 10;

		$tx_row              = new \stdClass();
		$tx_row->original    = 'Wähle Text → &#8222;Übersetzen&#8220;.';
		$tx_row->id          = 11;
		$tx_row->original_id = 11;

		$spy->string_ids_result = array( $raw_row, $tx_row );

		$post               = new \WP_Post();
		$post->ID           = 20;
		$post->post_title   = 'Titel';
		$post->post_excerpt = '';
		$post->post_content = '<p>Wähle Text → "Übersetzen".</p>';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$content_pairs = array(
			'Wähle Text → "Übersetzen".'           => 'Select Text → "Translate".',
			'Wähle Text → &#8222;Übersetzen&#8220;.' => 'Select Text → "Translate".',
		);

		$result = $this->adapter->create_translation( 20, 'en', array(
			'content_string_pairs' => $content_pairs,
			'overwrite'            => true,
		) );

		$this->assertSame( 20, $result );

		$updated_originals = array_column( $spy->update_calls[0]['rows'] ?? array(), 'original' );
		$updated_translations = array_column( $spy->update_calls[0]['rows'] ?? array(), 'translated' );

		$this->assertContains( 'Wähle Text → "Übersetzen".', $updated_originals );
		$this->assertContains( 'Wähle Text → &#8222;Übersetzen&#8220;.', $updated_originals );

		// Both variants must have the same translation.
		foreach ( $spy->update_calls[0]['rows'] ?? array() as $row ) {
			$this->assertSame( 'Select Text → "Translate".', $row['translated'] );
		}
	}

	// -----------------------------------------------------------------------
	// save_string_pairs – diagnostics logging
	// -----------------------------------------------------------------------

	public function test_save_string_pairs_logs_diagnostics(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		$log_calls = array();
		$this->stubWpFunction( 'error_log', static function ( string $msg ) use ( &$log_calls ): void {
			$log_calls[] = $msg;
		} );

		$id_row              = new \stdClass();
		$id_row->original    = 'Hallo';
		$id_row->id          = 5;
		$id_row->original_id = 5;
		$spy->string_ids_result = array( $id_row );

		$post               = new \WP_Post();
		$post->ID           = 9;
		$post->post_title   = 'Hallo';
		$post->post_content = '';
		$post->post_excerpt = '';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$this->adapter->create_translation( 9, 'en', array(
			'post_title' => 'Hello',
			'overwrite'  => true,
		) );

		// save_string_pairs must have been reached (update called).
		$this->assertCount( 1, $spy->update_calls );
		$this->assertSame( 2, $spy->update_calls[0]['rows'][0]['status'] );
	}

	// -----------------------------------------------------------------------
	// link_translation
	// -----------------------------------------------------------------------

	public function test_link_translation_is_noop_and_returns_true(): void {
		$result = $this->adapter->link_translation( 1, 2, 'en' );
		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------------	// build_content_translation_units
	// -------------------------------------------------------------------------

	public function test_build_content_translation_units_returns_lookup_variants(): void {
		// A segment that touches a link boundary so extract_string_segments emits
		// both a trimmed and an untrimmed variant.
		$html = '<p>Text <a href="#">mit Link</a> danach</p>';

		$units = $this->adapter->build_content_translation_units( $html );

		$this->assertNotEmpty( $units );
		foreach ( $units as $unit ) {
			$this->assertArrayHasKey( 'id', $unit );
			$this->assertArrayHasKey( 'source', $unit );
			$this->assertArrayHasKey( 'lookup_keys', $unit );
			$this->assertNotEmpty( $unit['lookup_keys'] );
		}

		// At least one unit must contain lookup key variants that differ in whitespace.
		$multi_key_unit = null;
		foreach ( $units as $unit ) {
			if ( count( $unit['lookup_keys'] ) > 1 ) {
				$multi_key_unit = $unit;
				break;
			}
		}
		$this->assertNotNull( $multi_key_unit, 'Expected at least one unit with multiple lookup keys' );
	}

	public function test_build_content_translation_units_empty_content(): void {
		$units = $this->adapter->build_content_translation_units( '' );
		$this->assertSame( array(), $units );
	}

	// -------------------------------------------------------------------------
	// create_translation – content_string_pairs fast path
	// -------------------------------------------------------------------------

	public function test_create_translation_uses_pretranslated_content_pairs(): void {
		$this->adapter->force_available = true;
		$spy                            = new SpyTrpQuery();
		$this->adapter->mock_query      = $spy;

		$post               = new \WP_Post();
		$post->ID           = 7;
		$post->post_title   = 'Titel';
		$post->post_content = '<p>Erster Satz</p><p>Zweiter Satz</p>';
		$post->post_excerpt = '';

		$this->stubWpFunctionReturn( 'get_post', $post );
		$this->stubWpFunctionReturn( 'get_option', array(
			'default-language'      => 'de_DE',
			'translation-languages' => array( 'de_DE', 'en_US' ),
		) );

		$content_pairs = array(
			'Erster Satz'  => 'First sentence',
			'Zweiter Satz' => 'Second sentence',
		);

		$result = $this->adapter->create_translation( 7, 'en', array(
			'post_title'           => 'Title',
			'post_content'         => $post->post_content,
			'content_string_pairs' => $content_pairs,
			'overwrite'            => true,
		) );

		$this->assertSame( 7, $result );

		// Verify the string pairs were passed to the TRP spy.
		$all_inserted = array();
		foreach ( $spy->insert_calls as $call ) {
			$all_inserted = array_merge( $all_inserted, $call['strings'] );
		}
		$all_updated_originals = array();
		foreach ( $spy->update_calls as $call ) {
			foreach ( $call['rows'] as $row ) {
				$all_updated_originals[] = $row['original'] ?? '';
			}
		}
		$all_strings = array_merge( $all_inserted, $all_updated_originals );

		// Both content pair originals must have been processed.
		$this->assertContains( 'Erster Satz', $all_strings );
		$this->assertContains( 'Zweiter Satz', $all_strings );
	}

	// -------------------------------------------------------------------------	// Helpers
	// -----------------------------------------------------------------------

	private function invokeMethod( object $object, string $method, array $args = array() ): mixed {
		$reflection = new \ReflectionMethod( $object, $method );
		return $reflection->invoke( $object, ...$args );
	}
}
