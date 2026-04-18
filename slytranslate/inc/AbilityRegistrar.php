<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all AI Translation abilities with the WordPress Abilities API.
 */
class AbilityRegistrar {

	public static function register_ability_category(): void {
		wp_register_ability_category( 'ai-translation', array(
			'label'       => __( 'AI Translation', 'slytranslate' ),
			'description' => __( 'AI-powered content translation abilities.', 'slytranslate' ),
		) );
	}

	public static function register_abilities(): void {
		self::register_get_languages_ability();
		self::register_get_translation_status_ability();
		self::register_get_untranslated_ability();
		self::register_translate_text_ability();
		self::register_translate_content_ability();
		self::register_translate_content_bulk_ability();
		self::register_configure_ability();
	}

	private static function public_mcp_meta( array $annotations = array() ): array {
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

	public static function permission_callback(): bool {
		return AI_Translate::current_user_can_access_translation_abilities();
	}

	/* --- get-languages ------------------------------------------- */

	private static function register_get_languages_ability(): void {
		wp_register_ability( 'ai-translate/get-languages', array(
			'label'               => __( 'Get Languages', 'slytranslate' ),
			'description'         => __( 'Returns all languages available for translation.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'code' => array( 'type' => 'string', 'description' => 'Language code' ),
						'name' => array( 'type' => 'string', 'description' => 'Language name' ),
					),
					'required' => array( 'code', 'name' ),
				),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_get_languages' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	/* --- get-translation-status ---------------------------------- */

	private static function register_get_translation_status_ability(): void {
		wp_register_ability( 'ai-translate/get-translation-status', array(
			'label'               => __( 'Get Translation Status', 'slytranslate' ),
			'description'         => __( 'Returns the translation status for a post, page, or custom post type entry.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'The content item ID to check.' ),
				),
				'required' => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'source_post_id'   => array( 'type' => 'integer' ),
					'source_post_type' => array( 'type' => 'string' ),
					'source_title'     => array( 'type' => 'string' ),
					'source_language'  => array( 'type' => 'string' ),
					'translations'     => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
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
			'execute_callback'    => array( AI_Translate::class, 'execute_get_translation_status' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	/* --- get-untranslated ---------------------------------------- */

	private static function register_get_untranslated_ability(): void {
		wp_register_ability( 'ai-translate/get-untranslated', array(
			'label'               => __( 'Get Untranslated Content', 'slytranslate' ),
			'description'         => __( 'Lists posts, pages, or custom post types that do not yet have a translation in the requested language.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_type'       => array( 'type' => 'string', 'description' => 'Post type to inspect. Defaults to post.', 'default' => 'post' ),
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'limit'           => array( 'type' => 'integer', 'description' => 'Maximum number of untranslated items to return.', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ),
				),
				'required' => array( 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_type'       => array( 'type' => 'string' ),
					'target_language' => array( 'type' => 'string' ),
					'items'           => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'post_id'         => array( 'type' => 'integer' ),
								'title'           => array( 'type' => 'string' ),
								'post_type'       => array( 'type' => 'string' ),
								'post_status'     => array( 'type' => 'string' ),
								'source_language' => array( 'type' => 'string' ),
								'edit_link'       => array( 'type' => 'string' ),
							),
							'required' => array( 'post_id', 'title', 'post_type' ),
						),
					),
					'total'           => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_get_untranslated' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	/* --- translate-text ------------------------------------------- */

	private static function register_translate_text_ability(): void {
		wp_register_ability( 'ai-translate/translate-text', array(
			'label'               => __( 'Translate Text', 'slytranslate' ),
			'description'         => __( 'Translates arbitrary text from one language to another using the WordPress AI Client.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'text'              => array( 'type' => 'string', 'description' => 'The text to translate.', 'minLength' => 1 ),
					'source_language'   => array( 'type' => 'string', 'description' => 'Source language code.' ),
					'target_language'   => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'additional_prompt' => array( 'type' => 'string', 'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on.' ),
					'model_slug'        => array( 'type' => 'string', 'description' => 'Model slug/identifier to use for this translation. Overrides the site-wide default.' ),
				),
				'required' => array( 'text', 'source_language', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'translated_text' => array( 'type' => 'string' ),
					'source_language' => array( 'type' => 'string' ),
					'target_language' => array( 'type' => 'string' ),
				),
				'required' => array( 'translated_text' ),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_translate_text' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'idempotent' => true ) ),
		) );
	}

	/* --- translate-content --------------------------------------- */

	private static function register_translate_content_ability(): void {
		wp_register_ability( 'ai-translate/translate-content', array(
			'label'               => __( 'Translate Content', 'slytranslate' ),
			'description'         => __( 'Translates a post, page, or custom post type entry and creates or updates the translation.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'           => array( 'type' => 'integer', 'description' => 'The source content item ID.' ),
					'target_language'   => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'post_status'       => array( 'type' => 'string', 'description' => 'Optional post status for the translated item. Defaults to the source status when possible.' ),
					'translate_title'   => array( 'type' => 'boolean', 'description' => 'Whether the post title should be translated.', 'default' => true ),
					'overwrite'         => array( 'type' => 'boolean', 'description' => 'Overwrite existing translation.', 'default' => false ),
					'additional_prompt' => array( 'type' => 'string', 'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on.' ),
					'model_slug'        => array( 'type' => 'string', 'description' => 'Model slug/identifier to use for this translation. Overrides the site-wide default.' ),
				),
				'required' => array( 'post_id', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'translated_post_id'   => array( 'type' => 'integer' ),
					'source_post_id'       => array( 'type' => 'integer' ),
					'target_language'      => array( 'type' => 'string' ),
					'title'                => array( 'type' => 'string' ),
					'translated_post_type' => array( 'type' => 'string' ),
					'post_status'          => array( 'type' => 'string' ),
					'edit_link'            => array( 'type' => 'string' ),
				),
				'required' => array( 'translated_post_id', 'source_post_id' ),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_translate_content' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta(),
		) );
	}

	/* --- translate-content-bulk ---------------------------------- */

	private static function register_translate_content_bulk_ability(): void {
		wp_register_ability( 'ai-translate/translate-content-bulk', array(
			'label'               => __( 'Translate Content (Bulk)', 'slytranslate' ),
			'description'         => __( 'Translates multiple posts, pages, or custom post type entries. Continues on individual failures.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_ids'        => array( 'type' => 'array', 'description' => 'Array of post IDs to translate.', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => 50 ),
					'post_type'       => array( 'type' => 'string', 'description' => 'Optional post type to translate in bulk when post_ids are not provided.' ),
					'limit'           => array( 'type' => 'integer', 'description' => 'Maximum number of items to fetch when post_type is used.', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ),
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'post_status'     => array( 'type' => 'string', 'description' => 'Optional post status for the translated items. Defaults to the source status when possible.' ),
					'translate_title' => array( 'type' => 'boolean', 'description' => 'Whether the post title should be translated.', 'default' => true ),
					'overwrite'       => array( 'type' => 'boolean', 'description' => 'Overwrite existing translations.', 'default' => false ),
					'model_slug'      => array( 'type' => 'string', 'description' => 'Model slug/identifier to use for this translation batch. Overrides the site-wide default.' ),
				),
				'required' => array( 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'results'   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
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
			'execute_callback'    => array( AI_Translate::class, 'execute_translate_posts' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta(),
		) );
	}

	/* --- configure ----------------------------------------------- */

	private static function register_configure_ability(): void {
		wp_register_ability( 'ai-translate/configure', array(
			'label'               => __( 'Configure AI Translate', 'slytranslate' ),
			'description'         => __( 'Read or update AI Translate settings, including prompt template, meta keys, SEO defaults, and auto-translate behavior.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'prompt_template'       => array( 'type' => 'string', 'description' => 'Translation prompt template. Use {FROM_CODE} and {TO_CODE} as placeholders.' ),
					'prompt_addon'          => array( 'type' => 'string', 'description' => 'Optional site-wide addition always appended after the prompt template. Applied to every translation request.' ),
					'meta_keys_translate'   => array( 'type' => 'string', 'description' => 'Whitespace-separated list of meta keys to translate.' ),
					'meta_keys_clear'       => array( 'type' => 'string', 'description' => 'Whitespace-separated list of meta keys to clear.' ),
					'auto_translate_new'    => array( 'type' => 'boolean', 'description' => 'Auto-translate new translation posts in Polylang.' ),
					'context_window_tokens' => array( 'type' => 'integer', 'description' => 'Optional override for the model context window in tokens. Use 0 to fall back to auto-detection and learned values.' ),
					'model_slug'            => array( 'type' => 'string', 'description' => 'Model slug/identifier to pass to the AI connector (e.g. gemma3:27b). Leave empty to use the connector default.' ),
					'direct_api_url'        => array( 'type' => 'string', 'description' => 'Base URL of an OpenAI-compatible API server (e.g. http://192.168.178.42:8080). When set, the plugin sends translation requests directly to this endpoint instead of using the WP AI Client. Works with llama.cpp, ollama, mlx-lm, vLLM, or any OpenAI-compatible server. Leave empty to use the standard AI Client. When saving, the plugin automatically probes whether the server supports chat_template_kwargs for optimized translation.' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'prompt_template'                  => array( 'type' => 'string' ),
					'prompt_addon'                     => array( 'type' => 'string' ),
					'meta_keys_translate'              => array( 'type' => 'string' ),
					'meta_keys_clear'                  => array( 'type' => 'string' ),
					'auto_translate_new'               => array( 'type' => 'boolean' ),
					'context_window_tokens'            => array( 'type' => 'integer' ),
					'model_slug'                       => array( 'type' => 'string' ),
					'direct_api_url'                   => array( 'type' => 'string' ),
					'direct_api_kwargs_supported'      => array( 'type' => 'boolean' ),
					'direct_api_kwargs_last_probed_at' => array( 'type' => 'integer' ),
					'translategemma_runtime_ready'     => array( 'type' => 'boolean' ),
					'translategemma_runtime_status'    => array( 'type' => 'string' ),
					'detected_seo_plugin'              => array( 'type' => 'string' ),
					'detected_seo_plugin_label'        => array( 'type' => 'string' ),
					'seo_meta_keys_translate'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'seo_meta_keys_clear'              => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'effective_meta_keys_translate'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'effective_meta_keys_clear'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'learned_context_window_tokens'    => array( 'type' => 'integer' ),
					'effective_context_window_tokens'  => array( 'type' => 'integer' ),
					'effective_chunk_chars'            => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_configure' ),
			'permission_callback' => static function ( $input = null ) {
				return current_user_can( 'manage_options' );
			},
			'meta'                => self::public_mcp_meta( array( 'idempotent' => true ) ),
		) );
	}
}
