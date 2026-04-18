<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use Brain\Monkey\Functions;

class EditorRestRegistrationTest extends TestCase {

	public function test_register_editor_rest_routes_registers_expected_route_contracts(): void {
		$registered_routes = $this->capture_registered_routes();

		$this->assertCount( 8, $registered_routes );

		$routes_by_path = array();
		foreach ( $registered_routes as $route_definition ) {
			$routes_by_path[ $route_definition['route'] ] = $route_definition;
		}

		$expected_callbacks = array(
			'/ai-translate/get-languages'          => array( AI_Translate::class, 'rest_execute_get_languages' ),
			'/ai-translate/get-translation-status' => array( AI_Translate::class, 'rest_execute_get_translation_status' ),
			'/ai-translate/translation-progress'   => array( AI_Translate::class, 'rest_execute_get_translation_progress' ),
			'/ai-translate/translate-text'         => array( AI_Translate::class, 'rest_execute_translate_text' ),
			'/ai-translate/translate-content'      => array( AI_Translate::class, 'rest_execute_translate_content' ),
			'/ai-translate/translate-post'         => array( AI_Translate::class, 'rest_execute_translate_content' ),
			'/ai-translate/cancel-translation'     => array( AI_Translate::class, 'rest_cancel_translation' ),
			'/ai-translate/user-preference'        => array( AI_Translate::class, 'rest_execute_save_user_preference' ),
		);

		$this->assertSame( array_keys( $expected_callbacks ), array_keys( $routes_by_path ) );
		$this->assertArrayNotHasKey( '/ai-translate/translate-posts', $routes_by_path );

		foreach ( $expected_callbacks as $route => $expected_callback ) {
			$this->assertSame( 'ai-translate/v1', $routes_by_path[ $route ]['namespace'] );
			$this->assertSame( \WP_REST_Server::CREATABLE, $routes_by_path[ $route ]['args']['methods'] );
			$this->assertSame( $expected_callback, $routes_by_path[ $route ]['args']['callback'] );
			$this->assertSame(
				array( AI_Translate::class, 'rest_can_access_translation_abilities' ),
				$routes_by_path[ $route ]['args']['permission_callback']
			);
		}

		$this->assertSame( array(), $routes_by_path['/ai-translate/get-languages']['args']['args'] );
		$this->assertSame( array(), $routes_by_path['/ai-translate/translation-progress']['args']['args'] );
		$this->assertSame( array(), $routes_by_path['/ai-translate/cancel-translation']['args']['args'] );

		$input_routes = array(
			'/ai-translate/get-translation-status',
			'/ai-translate/translate-text',
			'/ai-translate/translate-content',
			'/ai-translate/translate-post',
			'/ai-translate/user-preference',
		);

		foreach ( $input_routes as $route ) {
			$this->assertArrayHasKey( 'input', $routes_by_path[ $route ]['args']['args'] );
			$this->assertTrue( $routes_by_path[ $route ]['args']['args']['input']['required'] );
			$this->assertIsCallable( $routes_by_path[ $route ]['args']['args']['input']['validate_callback'] );
		}
	}

	public function test_translate_text_route_rejects_non_array_input_payloads(): void {
		$registered_routes = $this->capture_registered_routes();
		$route_definition  = array_values(
			array_filter(
				$registered_routes,
				static function ( array $definition ): bool {
					return '/ai-translate/translate-text' === $definition['route'];
				}
			)
		)[0];

		$validate_callback = $route_definition['args']['args']['input']['validate_callback'];

		$this->assertFalse( $validate_callback( 'not-an-array' ) );
		$this->assertTrue( $validate_callback( array( 'text' => 'Hello', 'source_language' => 'en', 'target_language' => 'de' ) ) );
	}

	public function test_rest_can_access_translation_abilities_follows_supported_capability_matrix(): void {
		$granted_capabilities = array( 'publish_pages' );

		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability, ...$args ) use ( &$granted_capabilities ): bool {
				return in_array( $capability, $granted_capabilities, true );
			}
		);

		$request = new \WP_REST_Request();

		$this->assertTrue( AI_Translate::rest_can_access_translation_abilities( $request ) );

		$granted_capabilities = array();

		$this->assertFalse( AI_Translate::rest_can_access_translation_abilities( $request ) );
	}

	/**
	 * @return array<int, array{namespace: string, route: string, args: array<string, mixed>}>
	 */
	private function capture_registered_routes(): array {
		$registered_routes = array();

		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$registered_routes ): void {
				$registered_routes[] = array(
					'namespace' => $namespace,
					'route'     => $route,
					'args'      => $args,
				);
			}
		);

		AI_Translate::register_editor_rest_routes();

		return $registered_routes;
	}
}