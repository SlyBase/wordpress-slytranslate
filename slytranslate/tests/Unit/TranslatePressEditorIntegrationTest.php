<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\TranslatePressAdapter;
use SlyTranslate\TranslatePressEditorIntegration;

class TranslatePressEditorIntegrationTest extends TestCase {

	public function test_register_assets_registers_script_style_and_localized_bootstrap_data(): void {
		$registered_scripts = array();
		$registered_styles  = array();
		$localized          = array();

		$this->setAiTranslateAdapter( new TranslatePressAdapter() );
		$this->stubWpFunctionReturn( 'is_singular', true );
		$this->stubWpFunctionReturn( 'get_queried_object_id', 42 );
		$this->stubWpFunction(
			'current_user_can',
			static function ( string $capability, int $post_id = 0 ): bool {
				return 'edit_post' === $capability && 42 === $post_id;
			}
		);
		$this->stubWpFunctionReturn( 'get_current_user_id', 7 );
		$this->stubWpFunctionReturn( 'get_user_meta', 'Locker, direkt, freundlich.' );
		$this->stubWpFunction(
			'get_option',
			static function ( string $option_name, $default = false ) {
				if ( 'trp_settings' === $option_name ) {
					return array(
						'default-language'      => 'de_DE',
						'translation-languages' => array( 'de_DE', 'en_US' ),
					);
				}

				if ( 'slytranslate_model_slug' === $option_name ) {
					return 'model-alpha';
				}

				return $default;
			}
		);
		$this->stubWpFunctionReturn( 'wp_create_nonce', 'test-rest-nonce' );
		$this->stubWpFunctionReturn( 'wp_set_script_translations', true );
		$this->stubWpFunction(
			'get_post',
			static function ( int $post_id ): \WP_Post {
				return new \WP_Post(
					array(
						'ID'         => $post_id,
						'post_title' => 'Smoke Test Post',
					)
				);
			}
		);
		$this->stubWpFunction(
			'wp_register_script',
			static function ( string $handle, string $src, array $deps, string $ver, bool $in_footer ) use ( &$registered_scripts ): void {
				$registered_scripts[] = compact( 'handle', 'src', 'deps', 'ver', 'in_footer' );
			}
		);
		$this->stubWpFunction(
			'wp_register_style',
			static function ( string $handle, string $src, array $deps, string $ver ) use ( &$registered_styles ): void {
				$registered_styles[] = compact( 'handle', 'src', 'deps', 'ver' );
			}
		);
		$this->stubWpFunction(
			'wp_localize_script',
			static function ( string $handle, string $object_name, array $data ) use ( &$localized ): bool {
				$localized[] = compact( 'handle', 'object_name', 'data' );
				return true;
			}
		);

		TranslatePressEditorIntegration::register_assets();

		$this->assertCount( 1, $registered_scripts );
		$this->assertSame( TranslatePressEditorIntegration::SCRIPT_HANDLE, $registered_scripts[0]['handle'] );
		$this->assertStringContainsString( 'assets/translatepress-editor.js', $registered_scripts[0]['src'] );
		$this->assertCount( 1, $registered_styles );
		$this->assertSame( TranslatePressEditorIntegration::STYLE_HANDLE, $registered_styles[0]['handle'] );
		$this->assertStringContainsString( 'assets/translatepress-editor.css', $registered_styles[0]['src'] );
		$this->assertCount( 1, $localized );
		$this->assertSame( 'SlyTranslateTranslatePressEditor', $localized[0]['object_name'] );
		$this->assertTrue( $localized[0]['data']['enabled'] );
		$this->assertArrayHasKey( 'debugLogEnabled', $localized[0]['data'] );
		$this->assertFalse( $localized[0]['data']['debugLogEnabled'] );
		$this->assertSame( 42, $localized[0]['data']['postId'] );
		$this->assertSame( 'Smoke Test Post', $localized[0]['data']['postTitle'] );
		$this->assertSame( 'de', $localized[0]['data']['sourceLanguage'] );
		$this->assertSame( 'test-rest-nonce', $localized[0]['data']['restNonce'] );
		$this->assertSame( 'Locker, direkt, freundlich.', $localized[0]['data']['lastAdditionalPrompt'] );
		$this->assertSame(
			array(
				array( 'code' => 'de', 'name' => 'DE' ),
				array( 'code' => 'en', 'name' => 'English (US)' ),
			),
			$localized[0]['data']['languages']
		);
	}

	public function test_include_editor_script_adds_handle_for_translatepress_adapter(): void {
		$this->setAiTranslateAdapter( new TranslatePressAdapter() );

		$this->assertSame(
			array( 'existing-script', TranslatePressEditorIntegration::SCRIPT_HANDLE ),
			TranslatePressEditorIntegration::include_editor_script( array( 'existing-script' ) )
		);
	}

	public function test_include_editor_script_leaves_handles_unchanged_without_translatepress_adapter(): void {
		$this->setAiTranslateAdapter( null );

		$this->assertSame(
			array( 'existing-script' ),
			TranslatePressEditorIntegration::include_editor_script( array( 'existing-script' ) )
		);
	}

	public function test_inject_editor_data_adds_slytranslate_flag_for_translatepress_adapter(): void {
		$this->setAiTranslateAdapter( new TranslatePressAdapter() );
		$this->stubWpFunctionReturn( 'is_singular', false );
		$this->stubWpFunction(
			'get_option',
			static function ( string $option_name, $default = false ) {
				if ( 'trp_settings' === $option_name ) {
					return array(
						'default-language'      => 'de_DE',
						'translation-languages' => array( 'de_DE', 'en_US' ),
					);
				}

				return $default;
			}
		);

		$result = TranslatePressEditorIntegration::inject_editor_data( array( 'editor' => 'data' ) );

		$this->assertSame( 'data', $result['editor'] );
		$this->assertTrue( $result['slytranslate']['enabled'] );
		$this->assertSame( '', $result['slytranslate']['disabledReason'] );
		$this->assertSame( 'de', $result['slytranslate']['sourceLanguage'] );
	}

	public function test_get_bootstrap_data_for_current_url_resolves_post_after_client_side_navigation(): void {
		$this->setAiTranslateAdapter( new TranslatePressAdapter() );
		$this->stubWpFunctionReturn( 'is_singular', false );
		$this->stubWpFunction(
			'current_user_can',
			static function ( string $capability, int $post_id = 0 ): bool {
				return 'edit_post' === $capability && 42 === $post_id;
			}
		);
		$this->stubWpFunctionReturn( 'get_current_user_id', 0 );
		$this->stubWpFunction(
			'get_option',
			static function ( string $option_name, $default = false ) {
				if ( 'trp_settings' === $option_name ) {
					return array(
						'default-language'      => 'de_DE',
						'translation-languages' => array( 'de_DE', 'en_US' ),
					);
				}

				if ( 'slytranslate_model_slug' === $option_name ) {
					return 'model-alpha';
				}

				return $default;
			}
		);
		$this->stubWpFunctionReturn( 'wp_create_nonce', 'test-rest-nonce' );
		$this->stubWpFunction(
			'get_post',
			static function ( int $post_id ): \WP_Post {
				return new \WP_Post(
					array(
						'ID'         => $post_id,
						'post_title' => 'Navigated Post',
					)
				);
			}
		);
		$this->stubWpFunction(
			'home_url',
			static function ( string $path = '' ): string {
				return 'https://example.com' . $path;
			}
		);
		$this->stubWpFunction(
			'remove_query_arg',
			static function ( array $keys, string $url ): string {
				return 'https://example.com/example-post/';
			}
		);
		$this->stubWpFunction(
			'url_to_postid',
			static function ( string $url ): int {
				return 'https://example.com/example-post/' === $url ? 42 : 0;
			}
		);

		$result = TranslatePressEditorIntegration::get_bootstrap_data_for_current_url( 'https://example.com/example-post/?trp-edit-translation=1' );

		$this->assertTrue( $result['enabled'] );
		$this->assertSame( 42, $result['postId'] );
		$this->assertSame( 'Navigated Post', $result['postTitle'] );
		$this->assertSame( 'de', $result['sourceLanguage'] );
	}

	protected function tearDown(): void {
		$this->setAiTranslateAdapter( null );
		parent::tearDown();
	}

	private function setAiTranslateAdapter( ?object $adapter ): void {
		$reflection = new \ReflectionClass( AI_Translate::class );
		$property   = $reflection->getProperty( 'adapter' );
		$property->setValue( null, $adapter );
	}
}