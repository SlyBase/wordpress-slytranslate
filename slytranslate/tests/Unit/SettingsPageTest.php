<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\Plugin;
use SlyTranslate\PolylangAdapter;
use SlyTranslate\SettingsPage;
use SlyTranslate\TranslatePressAdapter;

/**
 * Tests for the admin settings page registration and its bootstrap data.
 */
class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			return $value;
		} );
		$this->stubWpFunctionReturn( 'get_option', '' );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

	public function test_add_hooks_registers_menu_and_enqueue_hooks(): void {
		$registered = array();
		$this->stubWpFunction( 'add_action', static function ( string $hook, $callback ) use ( &$registered ): void {
			$registered[ $hook ] = $callback;
		} );

		SettingsPage::add_hooks();

		$this->assertSame( array( SettingsPage::class, 'register_settings_page' ), $registered['admin_menu'] );
		$this->assertSame( array( SettingsPage::class, 'enqueue_assets' ), $registered['admin_enqueue_scripts'] );
	}

	public function test_register_settings_page_uses_manage_options_and_slug(): void {
		$captured = array();
		$this->stubWpFunction( 'add_options_page', static function ( ...$args ) use ( &$captured ) {
			$captured = $args;
			return 'settings_page_slytranslate';
		} );

		SettingsPage::register_settings_page();

		$this->assertSame( 'manage_options', $captured[2] );
		$this->assertSame( 'slytranslate', $captured[3] );
		$this->assertSame( array( SettingsPage::class, 'render_page' ), $captured[4] );
	}

	public function test_enqueue_assets_skips_other_admin_pages(): void {
		$enqueued = array();
		$this->stubWpFunction( 'wp_enqueue_script', static function ( ...$args ) use ( &$enqueued ): void {
			$enqueued[] = $args;
		} );

		SettingsPage::enqueue_assets( 'edit.php' );
		SettingsPage::enqueue_assets( 'settings_page_other' );

		$this->assertSame( array(), $enqueued );
	}

	public function test_enqueue_assets_loads_app_on_own_page_with_bootstrap_data(): void {
		$enqueued  = array();
		$localized = array();
		$this->stubWpFunction( 'wp_enqueue_script', static function ( ...$args ) use ( &$enqueued ): void {
			$enqueued[] = $args;
		} );
		$this->stubWpFunction( 'wp_localize_script', static function ( string $handle, string $object_name, array $data ) use ( &$localized ): bool {
			$localized = array( 'handle' => $handle, 'object' => $object_name, 'data' => $data );
			return true;
		} );

		SettingsPage::enqueue_assets( 'settings_page_slytranslate' );

		$this->assertCount( 1, $enqueued );
		$this->assertSame( SettingsPage::SCRIPT_HANDLE, $enqueued[0][0] );
		$this->assertStringContainsString( 'assets/settings-page.js', $enqueued[0][1] );
		$this->assertContains( 'wp-api-fetch', $enqueued[0][2] );
		$this->assertContains( 'wp-components', $enqueued[0][2] );
		$this->assertContains( 'wp-element', $enqueued[0][2] );

		$this->assertSame( SettingsPage::SCRIPT_HANDLE, $localized['handle'] );
		$this->assertSame( 'slyTranslateSettings', $localized['object'] );
		$this->assertSame( '/' . Plugin::REST_NAMESPACE . '/', $localized['data']['abilitiesRunBasePath'] );
		$this->assertArrayHasKey( 'languagePlugin', $localized['data'] );
		$this->assertArrayHasKey( 'serverTranslationAvailable', $localized['data'] );
		$this->assertArrayHasKey( 'isStringTableAdapter', $localized['data'] );
		$this->assertArrayHasKey( 'defaultPromptTemplate', $localized['data'] );

		// Translated UI strings travel with the bootstrap (no JS i18n files).
		$this->assertArrayHasKey( 'strings', $localized['data'] );
		$this->assertSame( 'Save settings', $localized['data']['strings']['Save settings'] );
	}

	public function test_bootstrap_data_reports_polylang_adapter(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', new PolylangAdapter() );

		$data = SettingsPage::get_bootstrap_data();

		$this->assertSame( 'Polylang', $data['languagePlugin'] );
		$this->assertFalse( $data['isStringTableAdapter'] );
	}

	public function test_bootstrap_data_flags_string_table_adapter(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', new TranslatePressAdapter() );

		$data = SettingsPage::get_bootstrap_data();

		$this->assertSame( 'TranslatePress', $data['languagePlugin'] );
		$this->assertTrue( $data['isStringTableAdapter'] );
	}

	public function test_render_page_outputs_app_root(): void {
		ob_start();
		SettingsPage::render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="slytranslate-settings-root"', $output );
	}

	public function test_probe_executor_returns_probe_result_for_model(): void {
		// No parallel transport in the test environment → unsupported result.
		$result = AI_Translate::execute_probe_string_table_concurrency( array( 'model_slug' => 'test-model' ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['supported'] );
		$this->assertSame( 1, $result['recommended'] );
		$this->assertSame( 'test-model', $result['model_slug'] );
	}
}
