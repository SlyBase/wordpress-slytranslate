<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\Plugin;
use SlyTranslate\TranslationMutationAdapter;
use SlyTranslate\TranslationPluginAdapter;

class EditorRestRouteRegistrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

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
			'/ai-translate/string-table-worker/run',
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

	public function test_register_editor_rest_routes_omits_set_post_language_when_adapter_cannot_mutate_language(): void {
		$registered_routes = array();

		$this->setStaticProperty(
			AI_Translate::class,
			'adapter',
			new class() implements TranslationPluginAdapter {
				public function is_available(): bool {
					return true;
				}

				public function get_languages(): array {
					return array( 'en' => 'English', 'de' => 'Deutsch' );
				}

				public function get_post_language( int $post_id ): ?string {
					return 'en';
				}

				public function get_post_translations( int $post_id ): array {
					return array( 'en' => $post_id );
				}

				public function create_translation( int $source_post_id, string $target_lang, array $data ) {
					return 0;
				}

				public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
					return true;
				}
			}
		);

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

		$this->assertArrayNotHasKey( '/ai-translate/set-post-language/run', $registered_routes );
	}

	public function test_set_post_language_rest_route_permission_callback_checks_linked_posts_when_relinking(): void {
		$registered_routes = array();

		$this->setStaticProperty(
			AI_Translate::class,
			'adapter',
			new class() implements TranslationPluginAdapter, TranslationMutationAdapter {
				public function is_available(): bool {
					return true;
				}

				public function get_languages(): array {
					return array( 'en' => 'English', 'de' => 'Deutsch' );
				}

				public function get_post_language( int $post_id ): ?string {
					return 'en';
				}

				public function get_post_translations( int $post_id ): array {
					return array( 'en' => $post_id, 'de' => 200 );
				}

				public function create_translation( int $source_post_id, string $target_lang, array $data ) {
					return 0;
				}

				public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
					return true;
				}

				public function supports_mutation_capability( string $capability ): bool {
					return true;
				}

				public function set_post_language( int $post_id, string $target_language ) {
					return true;
				}

				public function relink_post_translations( array $translations ) {
					return true;
				}
			}
		);

		$this->stubWpFunction(
			'get_post',
			static function ( int $post_id ): ?\WP_Post {
				if ( 100 !== $post_id ) {
					return null;
				}

				return new \WP_Post(
					array(
						'ID'        => 100,
						'post_type' => 'post',
					)
				);
			}
		);

		$this->stubWpFunction(
			'get_post_status',
			static function ( $post = null ) {
				return 0 === $post ? false : 'publish';
			}
		);

		$this->stubWpFunction(
			'current_user_can',
			static function ( string $capability, ...$args ): bool {
				if ( 'publish_pages' === $capability ) {
					return true;
				}

				if ( 'edit_post' === $capability ) {
					return isset( $args[0] ) && 100 === $args[0];
				}

				return false;
			}
		);

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

		$request = new class() {
			public function get_json_params(): array {
				return array(
					'input' => array(
						'post_id' => 100,
						'relink'  => true,
					),
				);
			}
		};

		$callback = $registered_routes['/ai-translate/set-post-language/run']['args']['permission_callback'];

		$this->assertFalse( $callback( $request ) );
	}
}
