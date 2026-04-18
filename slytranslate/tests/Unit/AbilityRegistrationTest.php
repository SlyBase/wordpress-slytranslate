<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\AbilityRegistrar;
use Brain\Monkey\Functions;

class AbilityRegistrationTest extends TestCase {

	public function test_register_ability_category_registers_expected_category_contract(): void {
		$registered_categories = array();

		Functions\when( 'wp_register_ability_category' )->alias(
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

		$this->assertCount( 7, $registered_abilities );
		$this->assertSame( array_keys( $this->expected_ability_contracts() ), array_keys( $registered_abilities ) );
		$this->assertArrayNotHasKey( 'ai-translate/translate-post', $registered_abilities );

		foreach ( $this->expected_ability_contracts() as $slug => $expected_contract ) {
			$ability_definition = $registered_abilities[ $slug ];

			$this->assertSame( 'ai-translation', $ability_definition['category'] );
			$this->assertSame( $expected_contract['execute_callback'], $ability_definition['execute_callback'] );
			$this->assertIsCallable( $ability_definition['permission_callback'] );
			$this->assertSchemaContract( $ability_definition['input_schema'], $expected_contract['input_schema'] );
			$this->assertSchemaContract( $ability_definition['output_schema'], $expected_contract['output_schema'] );
			$this->assertSame( $expected_contract['meta'], $ability_definition['meta'] );
		}
	}

	public function test_ability_permission_callbacks_follow_translation_and_admin_capabilities(): void {
		$granted_capabilities = array();

		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability, ...$args ) use ( &$granted_capabilities ): bool {
				return in_array( $capability, $granted_capabilities, true );
			}
		);

		$registered_abilities = $this->capture_registered_abilities();

		$granted_capabilities = array( 'publish_pages' );
		foreach ( array(
			'ai-translate/get-languages',
			'ai-translate/get-translation-status',
			'ai-translate/get-untranslated',
			'ai-translate/translate-text',
			'ai-translate/translate-content',
			'ai-translate/translate-content-bulk',
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

		Functions\when( 'wp_register_ability' )->alias(
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
					'property_keys' => array( 'source_post_id', 'source_post_type', 'source_title', 'source_language', 'translations' ),
					'properties'    => array(
						'source_post_id'   => array( 'type' => 'integer' ),
						'source_post_type' => array( 'type' => 'string' ),
						'source_title'     => array( 'type' => 'string' ),
						'source_language'  => array( 'type' => 'string' ),
						'translations'     => array(
							'type'  => 'array',
							'items' => array(
								'type'          => 'object',
								'property_keys' => array( 'lang', 'post_id', 'exists', 'title', 'post_status', 'edit_link' ),
								'properties'    => array(
									'lang'        => array( 'type' => 'string' ),
									'post_id'     => array( 'type' => 'integer' ),
									'exists'      => array( 'type' => 'boolean' ),
									'title'       => array( 'type' => 'string' ),
									'post_status' => array( 'type' => 'string' ),
									'edit_link'   => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'meta'             => $this->expected_public_mcp_meta( array( 'readonly' => true ) ),
			),
			'ai-translate/get-untranslated' => array(
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
			'ai-translate/translate-content' => array(
				'execute_callback' => array( AI_Translate::class, 'execute_translate_content' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'post_id', 'target_language', 'post_status', 'translate_title', 'overwrite', 'additional_prompt', 'model_slug' ),
					'required'      => array( 'post_id', 'target_language' ),
					'properties'    => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The source content item ID.',
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
							'description' => 'Overwrite existing translation.',
							'default'     => false,
						),
						'additional_prompt' => array(
							'type'        => 'string',
							'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on.',
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
						'translated_post_id'   => array( 'type' => 'integer' ),
						'source_post_id'       => array( 'type' => 'integer' ),
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
				'execute_callback' => array( AI_Translate::class, 'execute_translate_posts' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'post_ids', 'post_type', 'limit', 'target_language', 'post_status', 'translate_title', 'overwrite', 'model_slug' ),
					'required'      => array( 'target_language' ),
					'properties'    => array(
						'post_ids' => array(
							'type'        => 'array',
							'description' => 'Array of post IDs to translate.',
							'minItems'    => 1,
							'maxItems'    => 50,
							'items'       => array( 'type' => 'integer' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Optional post type to translate in bulk when post_ids are not provided.',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Maximum number of items to fetch when post_type is used.',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 50,
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
							'description' => 'Overwrite existing translations.',
							'default'     => false,
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
									'translated_post_id' => array( 'type' => 'integer' ),
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
			'ai-translate/configure' => array(
				'execute_callback' => array( AI_Translate::class, 'execute_configure' ),
				'input_schema'     => array(
					'type'          => 'object',
					'property_keys' => array( 'prompt_template', 'prompt_addon', 'meta_keys_translate', 'meta_keys_clear', 'auto_translate_new', 'context_window_tokens', 'model_slug', 'direct_api_url' ),
					'properties'    => array(
						'prompt_template' => array(
							'type'        => 'string',
							'description' => 'Translation prompt template. Use {FROM_CODE} and {TO_CODE} as placeholders.',
						),
						'prompt_addon' => array(
							'type'        => 'string',
							'description' => 'Optional site-wide addition always appended after the prompt template. Applied to every translation request.',
						),
						'meta_keys_translate' => array(
							'type'        => 'string',
							'description' => 'Whitespace-separated list of meta keys to translate.',
						),
						'meta_keys_clear' => array(
							'type'        => 'string',
							'description' => 'Whitespace-separated list of meta keys to clear.',
						),
						'auto_translate_new' => array(
							'type'        => 'boolean',
							'description' => 'Auto-translate new translation posts in Polylang.',
						),
						'context_window_tokens' => array(
							'type'        => 'integer',
							'description' => 'Optional override for the model context window in tokens. Use 0 to fall back to auto-detection and learned values.',
						),
						'model_slug' => array(
							'type'        => 'string',
							'description' => 'Model slug/identifier to pass to the AI connector (e.g. gemma3:27b). Leave empty to use the connector default.',
						),
						'direct_api_url' => array(
							'type'        => 'string',
							'description' => 'Base URL of an OpenAI-compatible API server (e.g. http://192.168.178.42:8080). When set, the plugin sends translation requests directly to this endpoint instead of using the WP AI Client. Works with llama.cpp, ollama, mlx-lm, vLLM, or any OpenAI-compatible server. Leave empty to use the standard AI Client. When saving, the plugin automatically probes whether the server supports chat_template_kwargs for optimized translation.',
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
						'model_slug',
						'direct_api_url',
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
					),
					'properties'    => array(
						'prompt_template'              => array( 'type' => 'string' ),
						'prompt_addon'                 => array( 'type' => 'string' ),
						'meta_keys_translate'          => array( 'type' => 'string' ),
						'meta_keys_clear'              => array( 'type' => 'string' ),
						'auto_translate_new'           => array( 'type' => 'boolean' ),
						'context_window_tokens'        => array( 'type' => 'integer' ),
						'model_slug'                   => array( 'type' => 'string' ),
						'direct_api_url'               => array( 'type' => 'string' ),
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