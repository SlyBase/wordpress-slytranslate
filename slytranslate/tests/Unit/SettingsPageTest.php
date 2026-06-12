<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\Plugin;
use SlyTranslate\SettingsPage;
use SlyTranslate\TranslationRuntime;

class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		$this->setStaticProperty( TranslationRuntime::class, 'context', null );
		parent::tearDown();
	}

	/* --- menu ------------------------------------------------------ */

	public function test_register_menu_registers_options_page_for_admins(): void {
		$captured = null;

		$this->stubWpFunction(
			'add_options_page',
			static function ( ...$args ) use ( &$captured ): string {
				$captured = $args;
				return 'settings_page_slytranslate';
			}
		);

		SettingsPage::register_menu();

		$this->assertIsArray( $captured );
		$this->assertSame( 'manage_options', $captured[2] );
		$this->assertSame( SettingsPage::MENU_SLUG, $captured[3] );
		$this->assertIsCallable( $captured[4] );
	}

	/* --- assets ---------------------------------------------------- */

	public function test_enqueue_assets_skips_other_admin_pages(): void {
		$enqueued = array();

		$this->stubWpFunction(
			'wp_enqueue_script',
			static function ( ...$args ) use ( &$enqueued ): void {
				$enqueued[] = $args[0];
			}
		);

		SettingsPage::enqueue_assets( 'index.php' );
		SettingsPage::enqueue_assets( 'settings_page_other-plugin' );

		$this->assertSame( array(), $enqueued );
	}

	public function test_enqueue_assets_enqueues_settings_app_on_own_page(): void {
		$scripts   = array();
		$localized = null;

		$this->stubWpFunction(
			'wp_enqueue_script',
			static function ( ...$args ) use ( &$scripts ): void {
				$scripts[ $args[0] ] = $args;
			}
		);
		$this->stubWpFunction(
			'wp_localize_script',
			static function ( string $handle, string $object_name, array $data ) use ( &$localized ): void {
				$localized = array( $handle, $object_name, $data );
			}
		);

		SettingsPage::enqueue_assets( 'settings_page_' . SettingsPage::MENU_SLUG );

		$this->assertArrayHasKey( SettingsPage::SCRIPT_HANDLE, $scripts );

		$dependencies = $scripts[ SettingsPage::SCRIPT_HANDLE ][2];
		foreach ( array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ) as $dependency ) {
			$this->assertContains( $dependency, $dependencies );
		}

		$this->assertSame( SettingsPage::SCRIPT_HANDLE, $localized[0] );
		$this->assertSame( 'slyTranslateSettings', $localized[1] );
		$this->assertSame( '/' . Plugin::REST_NAMESPACE . '/settings', $localized[2]['settingsPath'] );
		$this->assertSame( '/' . Plugin::REST_NAMESPACE . '/settings/probe-concurrency', $localized[2]['probeConcurrencyPath'] );
	}

	/* --- REST routes ------------------------------------------------ */

	public function test_register_rest_routes_registers_settings_routes_with_admin_permission(): void {
		$registered = array();

		$this->stubWpFunction(
			'register_rest_route',
			static function ( string $namespace, string $route, array $args ) use ( &$registered ): void {
				$registered[ $route ] = array(
					'namespace' => $namespace,
					'args'      => $args,
				);
			}
		);

		SettingsPage::register_rest_routes();

		$this->assertSame(
			array( '/settings', '/settings/probe-concurrency' ),
			array_keys( $registered )
		);

		foreach ( $registered as $route ) {
			$this->assertSame( Plugin::REST_NAMESPACE, $route['namespace'] );
		}

		$settings_methods = array_column( $registered['/settings']['args'], 'methods' );
		$this->assertSame( array( 'GET', 'POST' ), $settings_methods );
		$this->assertSame( 'POST', $registered['/settings/probe-concurrency']['args']['methods'] );

		// Permission gate: manage_options decides, for every route config.
		$permission_callbacks = array_merge(
			array_column( $registered['/settings']['args'], 'permission_callback' ),
			array( $registered['/settings/probe-concurrency']['args']['permission_callback'] )
		);

		$this->stubWpFunctionReturn( 'current_user_can', false );
		foreach ( $permission_callbacks as $permission_callback ) {
			$this->assertFalse( $permission_callback() );
		}

		$this->stubWpFunction(
			'current_user_can',
			static function ( string $capability ): bool {
				return 'manage_options' === $capability;
			}
		);
		foreach ( $permission_callbacks as $permission_callback ) {
			$this->assertTrue( $permission_callback() );
		}
	}

	/* --- payload ----------------------------------------------------- */

	public function test_get_settings_payload_enriches_configure_output_with_environment_facts(): void {
		$this->stubWpFunction(
			'get_option',
			static function ( $option, $default = false ) {
				if ( 'slytranslate_learned_context_windows' === $option ) {
					return array( 'lfm2.5-8b' => 32768 );
				}

				return $default;
			}
		);

		$result = SettingsPage::get_settings_payload();

		$this->assertIsArray( $result );

		// Configure payload comes through untouched …
		$this->assertArrayHasKey( 'prompt_template', $result );
		$this->assertArrayHasKey( 'model_slug', $result );
		$this->assertArrayHasKey( 'last_transport_diagnostics', $result );

		// … plus the status-line enrichment.
		$this->assertFalse( $result['language_plugin_detected'] );
		$this->assertSame( '', $result['language_plugin_label'] );
		$this->assertFalse( $result['is_string_table_adapter'] );
		$this->assertTrue( $result['ai_client_available'] );
		$this->assertSame( AI_Translate::get_default_prompt(), $result['default_prompt_template'] );
		$this->assertSame( array( 'lfm2.5-8b' => 32768 ), $result['learned_context_windows'] );
	}

	public function test_settings_post_route_accepts_flat_and_enveloped_payloads(): void {
		$registered = array();

		$this->stubWpFunction(
			'register_rest_route',
			static function ( string $namespace, string $route, array $args ) use ( &$registered ): void {
				$registered[ $route ] = $args;
			}
		);

		$saved_options = array();
		$this->stubWpFunction(
			'update_option',
			static function ( string $option, $value ) use ( &$saved_options ): bool {
				$saved_options[ $option ] = $value;
				return true;
			}
		);

		SettingsPage::register_rest_routes();
		$post_callback = $registered['/settings'][1]['callback'];

		$flat_request = new class() {
			public function get_json_params(): array {
				return array( 'prompt_addon' => 'Use informal language.' );
			}
		};
		$result = $post_callback( $flat_request );
		$this->assertIsArray( $result );
		$this->assertSame( 'Use informal language.', $saved_options['slytranslate_prompt_addon'] );

		$enveloped_request = new class() {
			public function get_json_params(): array {
				return array( 'input' => array( 'prompt_addon' => 'Formal tone.' ) );
			}
		};
		$post_callback( $enveloped_request );
		$this->assertSame( 'Formal tone.', $saved_options['slytranslate_prompt_addon'] );
	}
}
