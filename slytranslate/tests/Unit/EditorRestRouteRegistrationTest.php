<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\Plugin;

class EditorRestRouteRegistrationTest extends TestCase {

	public function test_register_editor_rest_routes_registers_expected_plugin_rest_bridge(): void {
		$registered_routes = array();

		$this->stubWpFunction(
			'register_rest_route',
			static function ( string $namespace, string $route, array $args ) use ( &$registered_routes ): void {
				$registered_routes[ $route ] = array(
					'namespace' => $namespace,
					'args'      => $args,
				);
			}
		);

		AI_Translate::register_editor_rest_routes();

		$expected_routes = array(
			'/ai-translate/get-languages/run',
			'/ai-translate/get-translation-status/run',
			'/ai-translate/set-post-language/run',
			'/ai-translate/get-untranslated/run',
			'/ai-translate/translate-text/run',
			'/ai-translate/translate-blocks/run',
			'/ai-translate/translate-content/run',
			'/ai-translate/translate-content-bulk/run',
			'/ai-translate/configure/run',
			'/ai-translate/get-progress/run',
			'/ai-translate/cancel-translation/run',
			'/ai-translate/get-available-models/run',
			'/ai-translate/save-additional-prompt/run',
			'/ai-translate/user-preference/run',
		);

		$this->assertSame( $expected_routes, array_keys( $registered_routes ) );

		foreach ( $registered_routes as $registered_route ) {
			$this->assertSame( Plugin::REST_NAMESPACE, $registered_route['namespace'] );
			$this->assertSame( 'POST', $registered_route['args']['methods'] );
			$this->assertArrayHasKey( 'callback', $registered_route['args'] );
			$this->assertArrayHasKey( 'permission_callback', $registered_route['args'] );
			$this->assertIsCallable( $registered_route['args']['callback'] );
			$this->assertIsCallable( $registered_route['args']['permission_callback'] );
		}
	}
}