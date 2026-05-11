<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\AbilityRegistrar;
use SlyTranslate\TranslationMutationAdapter;
use SlyTranslate\TranslationPluginAdapter;

class AbilityRegistrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

	public function test_register_ability_category_registers_expected_category_contract(): void {
		$registered_categories = array();

		$this->stubWpFunction( 'wp_register_ability_category',
			static function ( string $slug, array $args ) use ( &$registered_categories ): void {
				$registered_categories[] = array(
					'slug' => $slug,
					'args' => $args,
				);
			}
		);

		AbilityRegistrar::register_ability_category();

		$this->assertSame(
			array(
				array(
					'slug' => 'ai-translation',
					'args' => array(
						'label'       => 'AI Translation',
						'description' => 'AI-powered content translation abilities.',
					),
				),
			),
			$registered_categories
		);
		$this->assertNotSame( 'translation', $registered_categories[0]['slug'] );
	}

	public function test_register_abilities_registers_expected_ability_contracts(): void {
		$registered_abilities = $this->capture_registered_abilities();

		$this->assertCount( 13, $registered_abilities );
		$this->assertSame( array_keys( $this->expected_ability_contracts() ), array_keys( $registered_abilities ) );
		$this->assertArrayNotHasKey( 'ai-translate/translate-post', $registered_abilities );

		foreach ( $this->expected_ability_contracts() as $slug => $expected_contract ) {
			$ability_definition = $registered_abilities[ $slug ];

			$this->assertSame( 'ai-translation', $ability_definition['category'] );
			if ( isset( $expected_contract['description'] ) ) {
				$this->assertSame( $expected_contract['description'], $ability_definition['description'] );
			}
			$this->assertSame( $expected_contract['execute_callback'], $ability_definition['execute_callback'] );
			$this->assertIsCallable( $ability_definition['permission_callback'] );
			$this->assertSchemaContract( $ability_definition['input_schema'], $expected_contract['input_schema'] );
			$this->assertSchemaContract( $ability_definition['output_schema'], $expected_contract['output_schema'] );
			$this->assertSame( $expected_contract['meta'], $ability_definition['meta'] );
		}
	}

	public function test_register_abilities_omits_set_post_language_when_adapter_cannot_mutate_language(): void {
		$adapter = new class() implements TranslationPluginAdapter {
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
		};

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$registered_abilities = $this->capture_registered_abilities();

		$this->assertArrayNotHasKey( 'ai-translate/set-post-language', $registered_abilities );
	}

	public function test_ability_permission_callbacks_follow_translation_and_admin_capabilities(): void {
		$granted_capabilities = array();

		$this->stubWpFunction( 'current_user_can',
			static function ( string $capability, ...$args ) use ( &$granted_capabilities ): bool {
				return in_array( $capability, $granted_capabilities, true );
			}
		);

		$registered_abilities = $this->capture_registered_abilities();

		$granted_capabilities = array( 'publish_pages' );
		foreach ( array(
			'ai-translate/get-languages',
			'ai-translate/get-translation-status',
			'ai-translate/set-post-language',
			'ai-translate/get-untranslated',
			'ai-translate/translate-text',
			'ai-translate/translate-blocks',
			'ai-translate/translate-content',
			'ai-translate/translate-content-bulk',
			'ai-translate/get-progress',
			'ai-translate/cancel-translation',
			'ai-translate/get-available-models',
			'ai-translate/save-additional-prompt',
		) as $slug ) {
			$this->assertTrue( $registered_abilities[ $slug ]['permission_callback']( null ) );
		}
		$this->assertFalse( $registered_abilities['ai-translate/configure']['permission_callback']( null ) );

		$granted_capabilities = array();
		foreach ( $registered_abilities as $ability_definition ) {
			$this->assertFalse( $ability_definition['permission_callback']( null ) );
		}

		$granted_capabilities = array( 'manage_options' );
		foreach ( $registered_abilities as $ability_definition ) {
			$this->assertTrue( $ability_definition['permission_callback']( null ) );
		}
	}

	public function test_set_post_language_ability_permission_callback_requires_access_to_linked_posts_when_relinking(): void {
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

		$registered_abilities = $this->capture_registered_abilities();
		$callback             = $registered_abilities['ai-translate/set-post-language']['permission_callback'];

		$this->assertFalse( $callback( array( 'post_id' => 100, 'relink' => true ) ) );
		$this->assertTrue( $callback( array( 'post_id' => 100, 'relink' => false ) ) );
	}

	/**
	 * @param array<string, mixed> $actual_schema
	 * @param array<string, mixed> $expected_contract
	 */
	private function assertSchemaContract( array $actual_schema, array $expected_contract, string $path = 'schema' ): void {
		foreach ( $expected_contract as $key => $expected_value ) {
			if ( 'property_keys' === $key ) {
				$this->assertArrayHasKey( 'properties', $actual_schema, $path . '.properties is missing.' );
				$actual_property_keys   = array_keys( $actual_schema['properties'] );
				$expected_property_keys = $expected_value;

				sort( $actual_property_keys );
				sort( $expected_property_keys );

				$this->assertSame( $expected_property_keys, $actual_property_keys, $path . '.properties keys mismatch.' );
				continue;
			}

			$this->assertArrayHasKey( $key, $actual_schema, $path . '.' . $key . ' is missing.' );

			if ( is_array( $expected_value ) ) {
				$this->assertIsArray( $actual_schema[ $key ], $path . '.' . $key . ' must be an array.' );

				if ( 'properties' === $key ) {
					foreach ( $expected_value as $property_name => $property_contract ) {
						$this->assertArrayHasKey( $property_name, $actual_schema['properties'], $path . '.properties.' . $property_name . ' is missing.' );
						$this->assertSchemaContract(
							$actual_schema['properties'][ $property_name ],
							$property_contract,
							$path . '.properties.' . $property_name
						);
					}
					continue;
				}

				$this->assertSchemaContract( $actual_schema[ $key ], $expected_value, $path . '.' . $key );
				continue;
			}

			$this->assertSame( $expected_value, $actual_schema[ $key ], $path . '.' . $key . ' mismatch.' );
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function capture_registered_abilities(): array {
		$registered_abilities = array();

		$this->stubWpFunction( 'wp_register_ability',
			static function ( string $slug, array $args ) use ( &$registered_abilities ): void {
				$registered_abilities[ $slug ] = $args;
			}
		);

		AbilityRegistrar::register_abilities();

		return $registered_abilities;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function expected_ability_contracts(): array {
		return array(
			'ai-translate/get-languages' => array(
				'execute_callback' => array( AI_Translate::class, 'execute_get_languages' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array(),
					'properties'    => array(),
				),
				'output_schema'    => array(
					'type'  => 'array',
					'items' => array(
						'type'          => 'object',
						'property_keys' => array( 'code', 'name' ),
						'required'      => array( 'code', 'name' ),
						'properties'    => array(
							'code' => array(
								'type'        => 'string',
								'description' => 'Language code',
							),
							'name' => array(
								'type'        => 'string',
								'description' => 'Language name',
							),
						),
					),
				),
				'meta'             => $this->expected_public_mcp_meta( array( 'readonly' => true ) ),
			),
			'ai-translate/get-translation-status' => array(
				'description'      => 'Inspect one content item before translating. Returns source_language, single_entry_mode, and per-language existence data. In single-entry mode a later translate-content call can keep source_post_id and translated_post_id identical; in multi-post mode target languages use sibling post IDs.',
				'execute_callback' => array( AI_Translate::class, 'execute_get_translation_status' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'post_id' ),
					'required'      => array( 'post_id' ),
					'properties'    => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The content item ID to check.',
						),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array( 'source_post_id', 'source_post_type', 'source_title', 'source_language', 'single_entry_mode', 'translations' ),
					'properties'    => array(
						'source_post_id'   => array( 'type' => 'integer', 'description' => 'Source WordPress post ID. In single-entry mode this is also the post that stores translated variants.' ),
						'source_post_type' => array( 'type' => 'string', 'description' => 'Source WordPress post type.' ),
						'source_title'     => array( 'type' => 'string', 'description' => 'Source title for the resolved source_language.' ),
						'source_language'  => array( 'type' => 'string', 'description' => 'Canonical source language resolved by the active adapter. Reuse this as translate-content.source_language when you intentionally pin the source variant.' ),
						'single_entry_mode' => array( 'type' => 'boolean', 'description' => 'True when translated variants stay on the same WordPress post ID (WP Multilang, WPGlobus, TranslatePress). False when each target language uses a sibling post, for example Polylang.' ),
						'translations'     => array(
							'type'  => 'array',
							'items' => array(
								'type'          => 'object',
								'property_keys' => array( 'lang', 'post_id', 'exists', 'title', 'post_status', 'edit_link' ),
								'properties'    => array(
									'lang'        => array( 'type' => 'string', 'description' => 'Target language code.' ),
									'post_id'     => array( 'type' => 'integer', 'description' => 'Translated sibling post ID in multi-post mode. 0 in single-entry mode status responses, even when the target variant already exists on the source post.' ),
									'exists'      => array( 'type' => 'boolean', 'description' => 'Whether the requested language already exists for this content item.' ),
									'title'       => array( 'type' => 'string', 'description' => 'Translated post title when a separate translated post exists and is accessible.' ),
									'post_status' => array( 'type' => 'string', 'description' => 'Translated post status when a separate translated post exists and is accessible.' ),
									'edit_link'   => array( 'type' => 'string', 'description' => 'Admin edit URL for the translated sibling post. Empty in single-entry mode.' ),
								),
							),
						),
					),
				),
				'meta'             => $this->expected_public_mcp_meta( array( 'readonly' => true ) ),
			),
				'ai-translate/set-post-language' => array(
					'description'      => 'Changes the language assignment of an existing content item without running translation. Exposed only when the active adapter supports post-language mutation, currently multi-post adapters such as Polylang. Use relink=true only when translation relations must be rewritten, and force=true only to replace an existing target-language assignment.',
					'execute_callback' => array( AI_Translate::class, 'execute_set_post_language' ),
					'input_schema'     => array(
						'type'          => 'object',
						'property_keys' => array( 'post_id', 'target_language', 'relink', 'force' ),
						'required'      => array( 'post_id', 'target_language' ),
						'properties'    => array(
							'post_id' => array(
								'type'        => 'integer',
								'description' => 'The content item ID whose language should be changed.',
							),
							'target_language' => array(
								'type'        => 'string',
								'description' => 'Language code to assign to the existing content item.',
							),
							'relink' => array(
								'type'        => 'boolean',
								'description' => 'When true, also rewrites the translation relation map so this post occupies target_language in the group.',
							),
							'force' => array(
								'type'        => 'boolean',
								'description' => 'When true, allows taking over a target language that is already assigned elsewhere in the translation group.',
								'default'     => false,
							),
						),
					),
					'output_schema'    => array(
						'type'          => 'object',
						'property_keys' => array( 'post_id', 'source_language', 'target_language', 'translations', 'changed', 'edit_link' ),
						'required'      => array( 'post_id', 'source_language', 'target_language', 'translations', 'changed' ),
						'properties'    => array(
							'post_id'         => array( 'type' => 'integer' ),
							'source_language' => array( 'type' => 'string' ),
							'target_language' => array( 'type' => 'string' ),
							'translations'    => array(
								'type'                 => 'object',
								'additionalProperties' => array( 'type' => 'integer' ),
							),
							'changed'         => array( 'type' => 'boolean' ),
							'edit_link'       => array( 'type' => 'string' ),
						),
					),
					'meta'             => $this->expected_public_mcp_meta(),
				),
			'ai-translate/get-untranslated' => array(
				'description'      => 'Lists candidate source items that are still missing the requested target language according to the active adapter. In single-entry mode the target variant is missing on the same post; in multi-post mode no sibling translation post exists. Use this before translate-content-bulk.',
				'execute_callback' => array( AI_Translate::class, 'execute_get_untranslated' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'post_type', 'target_language', 'limit' ),
					'required'      => array( 'target_language' ),
					'properties'    => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Post type to inspect. Defaults to post.',
							'default'     => 'post',
						),
						'target_language' => array(
							'type'        => 'string',
							'description' => 'Target language code.',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Maximum number of untranslated items to return.',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array( 'post_type', 'target_language', 'items', 'total' ),
					'properties'    => array(
						'post_type'       => array( 'type' => 'string' ),
						'target_language' => array( 'type' => 'string' ),
						'items'           => array(
							'type'  => 'array',
							'items' => array(
								'type'          => 'object',
								'property_keys' => array( 'post_id', 'title', 'post_type', 'post_status', 'source_language', 'edit_link' ),
								'required'      => array( 'post_id', 'title', 'post_type' ),
								'properties'    => array(
									'post_id'         => array( 'type' => 'integer' ),
									'title'           => array( 'type' => 'string' ),
									'post_type'       => array( 'type' => 'string' ),
									'post_status'     => array( 'type' => 'string' ),
									'source_language' => array( 'type' => 'string' ),
									'edit_link'       => array( 'type' => 'string' ),
								),
							),
						),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'meta'             => $this->expected_public_mcp_meta( array( 'readonly' => true ) ),
			),
			'ai-translate/translate-text' => array(
				'execute_callback' => array( AI_Translate::class, 'execute_translate_text' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'text', 'source_language', 'target_language', 'additional_prompt', 'model_slug' ),
					'required'      => array( 'text', 'source_language', 'target_language' ),
					'properties'    => array(
						'text' => array(
							'type'        => 'string',
							'description' => 'The text to translate.',
							'minLength'   => 1,
						),
						'source_language' => array(
							'type'        => 'string',
							'description' => 'Source language code.',
						),
						'target_language' => array(
							'type'        => 'string',
							'description' => 'Target language code.',
						),
						'additional_prompt' => array(
							'type'        => 'string',
							'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on.',
							'maxLength'   => 2000,
						),
						'model_slug' => array(
							'type'        => 'string',
							'description' => 'Model slug/identifier to use for this translation. Overrides the site-wide default.',
						),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array( 'translated_text', 'source_language', 'target_language' ),
					'required'      => array( 'translated_text' ),
					'properties'    => array(
						'translated_text' => array( 'type' => 'string' ),
						'source_language' => array( 'type' => 'string' ),
						'target_language' => array( 'type' => 'string' ),
					),
				),
				'meta'             => $this->expected_public_mcp_meta( array( 'idempotent' => true ) ),
			),
			'ai-translate/translate-blocks' => array(
				'execute_callback' => array( AI_Translate::class, 'execute_translate_blocks' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'content', 'source_language', 'target_language', 'additional_prompt', 'model_slug' ),
					'required'      => array( 'content', 'source_language', 'target_language' ),
					'properties'    => array(
						'content'           => array( 'type' => 'string', 'minLength' => 1 ),
						'source_language'   => array( 'type' => 'string' ),
						'target_language'   => array( 'type' => 'string' ),
						'additional_prompt' => array( 'type' => 'string', 'maxLength' => 2000 ),
						'model_slug'        => array( 'type' => 'string' ),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array( 'translated_content', 'source_language', 'target_language' ),
					'required'      => array( 'translated_content' ),
					'properties'    => array(
						'translated_content' => array( 'type' => 'string' ),
						'source_language'    => array( 'type' => 'string' ),
						'target_language'    => array( 'type' => 'string' ),
					),
				),
				'meta'             => $this->expected_public_mcp_meta( array( 'idempotent' => true ) ),
			),
			'ai-translate/translate-content' => array(
				'description'      => 'Translates one content item into one target language. Single-entry adapters update the same post ID, while multi-post adapters create or update a sibling translated post. Call get-translation-status first, omit source_language unless you intentionally pin a source variant, and set overwrite=true only when the target language already exists.',
				'execute_callback' => array( AI_Translate::class, 'execute_translate_content' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'post_id', 'source_language', 'target_language', 'post_status', 'translate_title', 'overwrite', 'additional_prompt', 'model_slug' ),
					'required'      => array( 'post_id', 'target_language' ),
					'properties'    => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The source content item ID.',
						),
						'source_language' => array(
							'type'        => 'string',
							'description' => 'Optional source language to pin. Omit unless you intentionally select a specific source variant. For single-entry adapters, reuse get-translation-status.source_language or a confirmed language code from get-languages.',
						),
						'target_language' => array(
							'type'        => 'string',
							'description' => 'Target language code.',
						),
						'post_status' => array(
							'type'        => 'string',
							'description' => 'Optional post status for the translated item. Defaults to the source status when possible.',
						),
						'translate_title' => array(
							'type'        => 'boolean',
							'description' => 'Whether the post title should be translated.',
							'default'     => true,
						),
						'overwrite' => array(
							'type'        => 'boolean',
							'description' => 'When true, update an existing target translation instead of returning translation_exists.',
							'default'     => false,
						),
						'additional_prompt' => array(
							'type'        => 'string',
							'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on.',
							'maxLength'   => 2000,
						),
						'model_slug' => array(
							'type'        => 'string',
							'description' => 'Model slug/identifier to use for this translation. Overrides the site-wide default.',
						),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array( 'translated_post_id', 'source_post_id', 'target_language', 'title', 'translated_post_type', 'post_status', 'edit_link' ),
					'required'      => array( 'translated_post_id', 'source_post_id' ),
					'properties'    => array(
						'translated_post_id'   => array( 'type' => 'integer', 'description' => 'Translated WordPress post ID. May equal source_post_id in single-entry mode; otherwise it is the sibling target post ID.' ),
						'source_post_id'       => array( 'type' => 'integer', 'description' => 'Source WordPress post ID.' ),
						'target_language'      => array( 'type' => 'string' ),
						'title'                => array( 'type' => 'string' ),
						'translated_post_type' => array( 'type' => 'string' ),
						'post_status'          => array( 'type' => 'string' ),
						'edit_link'            => array( 'type' => 'string' ),
					),
				),
				'meta'             => $this->expected_public_mcp_meta(),
			),
			'ai-translate/translate-content-bulk' => array(
				'description'      => 'Translates multiple content items into one target language. Choose exactly one source selector: post_ids for explicit items, or post_type with optional limit for discovery. Single-entry adapters can return the same source_post_id and translated_post_id; multi-post adapters return sibling target post IDs. Pass source_language only when you intentionally pin the same source variant for every item, and set overwrite=true only when existing translations should be updated instead of skipped.',
				'execute_callback' => array( AI_Translate::class, 'execute_translate_posts' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'post_ids', 'post_type', 'limit', 'source_language', 'target_language', 'post_status', 'translate_title', 'overwrite', 'additional_prompt', 'model_slug' ),
					'required'      => array( 'target_language' ),
					'properties'    => array(
						'post_ids' => array(
							'type'        => 'array',
							'description' => 'Explicit source post IDs to translate. When present, these take precedence over post_type and limit.',
							'minItems'    => 1,
							'maxItems'    => 50,
							'items'       => array( 'type' => 'integer' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Post type used to discover source items only when post_ids are omitted.',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Maximum number of discovered items when post_type is used.',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
						),
						'source_language' => array(
							'type'        => 'string',
							'description' => 'Optional source language override applied only for adapters that support picking a source variant inside one post, such as WP Multilang or WPGlobus.',
						),
						'target_language' => array(
							'type'        => 'string',
							'description' => 'Target language code.',
						),
						'post_status' => array(
							'type'        => 'string',
							'description' => 'Optional post status for the translated items. Defaults to the source status when possible.',
						),
						'translate_title' => array(
							'type'        => 'boolean',
							'description' => 'Whether the post title should be translated.',
							'default'     => true,
						),
						'overwrite' => array(
							'type'        => 'boolean',
							'description' => 'When true, update existing target translations instead of returning them as skipped.',
							'default'     => false,
						),
						'additional_prompt' => array(
							'type'        => 'string',
							'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on for every item in the batch.',
							'maxLength'   => 2000,
						),
						'model_slug' => array(
							'type'        => 'string',
							'description' => 'Model slug/identifier to use for this translation batch. Overrides the site-wide default.',
						),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array( 'results', 'total', 'succeeded', 'failed', 'skipped' ),
					'properties'    => array(
						'results' => array(
							'type'  => 'array',
							'items' => array(
								'type'          => 'object',
								'property_keys' => array( 'source_post_id', 'translated_post_id', 'status', 'error', 'edit_link' ),
								'properties'    => array(
									'source_post_id'     => array( 'type' => 'integer' ),
									'translated_post_id' => array( 'type' => 'integer', 'description' => 'Translated WordPress post ID for this item. May equal source_post_id in single-entry mode. 0 when status is failed.' ),
									'status'             => array( 'type' => 'string' ),
									'error'              => array( 'type' => 'string' ),
									'edit_link'          => array( 'type' => 'string' ),
								),
							),
						),
						'total'     => array( 'type' => 'integer' ),
						'succeeded' => array( 'type' => 'integer' ),
						'failed'    => array( 'type' => 'integer' ),
						'skipped'   => array( 'type' => 'integer' ),
					),
				),
				'meta'             => $this->expected_public_mcp_meta(),
			),
			'ai-translate/get-progress' => array(
				'execute_callback' => array( AI_Translate::class, 'execute_get_progress' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'post_id' ),
					'properties'    => array(
						'post_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array( 'phase', 'percent', 'current_chunk', 'total_chunks' ),
					'properties'    => array(
						'phase'         => array( 'type' => 'string' ),
						'percent'       => array( 'type' => 'integer' ),
						'current_chunk' => array( 'type' => 'integer' ),
						'total_chunks'  => array( 'type' => 'integer' ),
					),
				),
				'meta'             => $this->expected_public_mcp_meta( array( 'readonly' => true ) ),
			),
			'ai-translate/cancel-translation' => array(
				'execute_callback' => array( AI_Translate::class, 'execute_cancel_translation' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'post_id' ),
					'properties'    => array(
						'post_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array( 'cancelled' ),
					'required'      => array( 'cancelled' ),
					'properties'    => array(
						'cancelled' => array( 'type' => 'boolean' ),
					),
				),
				'meta'             => $this->expected_public_mcp_meta(),
			),
			'ai-translate/get-available-models' => array(
				'execute_callback' => array( AI_Translate::class, 'execute_get_available_models' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'refresh' ),
					'properties'    => array(
						'refresh' => array( 'type' => 'boolean' ),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array( 'models', 'defaultModelSlug', 'refreshed' ),
					'required'      => array( 'models' ),
					'properties'    => array(
						'models'           => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'defaultModelSlug' => array( 'type' => 'string' ),
						'refreshed'        => array( 'type' => 'boolean' ),
					),
				),
				'meta'             => $this->expected_public_mcp_meta( array( 'readonly' => true ) ),
			),
			'ai-translate/save-additional-prompt' => array(
				'execute_callback' => array( AI_Translate::class, 'execute_save_additional_prompt' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'additional_prompt' ),
					'required'      => array( 'additional_prompt' ),
					'properties'    => array(
						'additional_prompt' => array( 'type' => 'string', 'maxLength' => 2000 ),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array( 'additional_prompt' ),
					'required'      => array( 'additional_prompt' ),
					'properties'    => array(
						'additional_prompt' => array( 'type' => 'string' ),
					),
				),
				'meta'             => $this->expected_public_mcp_meta( array( 'idempotent' => true ) ),
			),
			'ai-translate/configure' => array(
				'description'      => 'Reads or updates persistent site-wide AI Translate settings. Call with an empty object to inspect current values. Input properties are persistent settings only; runtime diagnostics such as effective concurrency, SEO detection, and transport status are returned in output as inspect-only fields.',
				'execute_callback' => array( AI_Translate::class, 'execute_configure' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'prompt_template', 'prompt_addon', 'meta_keys_translate', 'meta_keys_clear', 'auto_translate_new', 'context_window_tokens', 'string_table_concurrency', 'model_slug', 'direct_api_url', 'force_direct_api' ),
					'properties'    => array(
						'prompt_template' => array(
							'type'        => 'string',
							'description' => 'Persistent setting: translation prompt template. Use {FROM_CODE} and {TO_CODE} as placeholders.',
						),
						'prompt_addon' => array(
							'type'        => 'string',
							'description' => 'Persistent setting: optional site-wide instructions appended after the prompt template for every translation request.',
						),
						'meta_keys_translate' => array(
							'type'        => 'string',
							'description' => 'Persistent setting: whitespace-separated list of meta keys to translate. Use a plain string, not an array.',
						),
						'meta_keys_clear' => array(
							'type'        => 'string',
							'description' => 'Persistent setting: whitespace-separated list of meta keys to clear. Use a plain string, not an array.',
						),
						'auto_translate_new' => array(
							'type'        => 'boolean',
							'description' => 'Persistent setting: auto-translate new translation posts created by the active language plugin.',
						),
						'context_window_tokens' => array(
							'type'        => 'integer',
							'description' => 'Persistent setting: manual model context-window override in tokens. Use 0 to fall back to auto-detection and learned values.',
							'minimum'     => 0,
							'maximum'     => 4000000,
						),
						'string_table_concurrency' => array(
							'type'        => 'integer',
							'description' => 'Persistent setting: opt-in maximum concurrency for TranslatePress-style string-table batches. Values above 1 only activate when a successful concurrency probe recommends parallel execution for the active model.',
							'minimum'     => 1,
							'maximum'     => 4,
						),
						'model_slug' => array(
							'type'        => 'string',
							'description' => 'Persistent setting: site-wide default model slug/identifier passed to the AI connector. Leave empty to use the connector default.',
						),
						'direct_api_url' => array(
							'type'        => 'string',
							'description' => 'Persistent setting: base URL of an optional OpenAI-compatible API server (e.g. http://192.168.178.42:8080). Normal translations use the WordPress AI Client transport; this endpoint is used only for model profiles that explicitly require direct API handling, for example TranslateGemma. When saving, the plugin probes whether the endpoint supports chat_template_kwargs.',
						),
						'force_direct_api' => array(
							'type'        => 'boolean',
							'description' => 'Persistent setting, deprecated: compatibility flag. Normal translations use the WordPress AI Client transport. Direct API remains reserved for models that explicitly require it, for example TranslateGemma.',
						),
					),
				),
				'output_schema'    => array(
					'type'          => 'object',
					'property_keys' => array(
						'prompt_template',
						'prompt_addon',
						'meta_keys_translate',
						'meta_keys_clear',
						'auto_translate_new',
						'context_window_tokens',
						'string_table_concurrency',
						'string_table_concurrency_effective',
						'string_table_concurrency_recommended',
						'string_table_concurrency_supported',
						'string_table_concurrency_transport',
						'model_slug',
						'direct_api_url',
						'force_direct_api',
						'direct_api_kwargs_supported',
						'direct_api_kwargs_last_probed_at',
						'translategemma_runtime_ready',
						'translategemma_runtime_status',
						'detected_seo_plugin',
						'detected_seo_plugin_label',
						'seo_meta_keys_translate',
						'seo_meta_keys_clear',
						'effective_meta_keys_translate',
						'effective_meta_keys_clear',
						'learned_context_window_tokens',
						'effective_context_window_tokens',
						'effective_chunk_chars',
						'last_transport_diagnostics',
					),
					'properties'    => array(
						'prompt_template'              => array( 'type' => 'string' ),
						'prompt_addon'                 => array( 'type' => 'string' ),
						'meta_keys_translate'          => array( 'type' => 'string' ),
						'meta_keys_clear'              => array( 'type' => 'string' ),
						'auto_translate_new'           => array( 'type' => 'boolean' ),
						'context_window_tokens'        => array( 'type' => 'integer' ),
						'string_table_concurrency'     => array( 'type' => 'integer' ),
						'string_table_concurrency_effective' => array( 'type' => 'integer' ),
						'string_table_concurrency_recommended' => array( 'type' => 'integer' ),
						'string_table_concurrency_supported' => array( 'type' => 'boolean' ),
						'string_table_concurrency_transport' => array( 'type' => 'string' ),
						'model_slug'                   => array( 'type' => 'string' ),
						'direct_api_url'               => array( 'type' => 'string' ),
						'force_direct_api'             => array( 'type' => 'boolean' ),
						'direct_api_kwargs_supported'  => array( 'type' => 'boolean' ),
						'direct_api_kwargs_last_probed_at' => array( 'type' => 'integer' ),
						'translategemma_runtime_ready' => array( 'type' => 'boolean' ),
						'translategemma_runtime_status' => array( 'type' => 'string' ),
						'detected_seo_plugin'          => array( 'type' => 'string' ),
						'detected_seo_plugin_label'    => array( 'type' => 'string' ),
						'seo_meta_keys_translate'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'seo_meta_keys_clear'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'effective_meta_keys_translate' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'effective_meta_keys_clear'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'learned_context_window_tokens' => array( 'type' => 'integer' ),
						'effective_context_window_tokens' => array( 'type' => 'integer' ),
						'effective_chunk_chars'        => array( 'type' => 'integer' ),
						'last_transport_diagnostics'   => array(
							'type'       => 'object',
							'properties' => array(
								'transport'            => array( 'type' => 'string' ),
								'model_slug'           => array( 'type' => 'string' ),
								'requested_model_slug' => array( 'type' => 'string' ),
								'effective_model_slug' => array( 'type' => 'string' ),
								'direct_api_url'       => array( 'type' => 'string' ),
								'kwargs_supported'     => array( 'type' => 'boolean' ),
								'fallback_allowed'     => array( 'type' => 'boolean' ),
								'failure_reason'       => array( 'type' => 'string' ),
								'error_code'           => array( 'type' => 'string' ),
								'error_message'        => array( 'type' => 'string' ),
							),
						),
					),
				),
				'meta'             => $this->expected_public_mcp_meta( array( 'idempotent' => true ) ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $annotations
	 * @return array<string, mixed>
	 */
	private function expected_public_mcp_meta( array $annotations = array() ): array {
		$meta = array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
		);

		if ( ! empty( $annotations ) ) {
			$meta['annotations'] = $annotations;
		}

		return $meta;
	}
}
