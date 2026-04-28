<?php

namespace SlyTranslate;

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
		if ( LanguageMutationService::can_mutate_post_language() ) {
			self::register_set_post_language_ability();
		}
		self::register_get_untranslated_ability();
		self::register_translate_text_ability();
		self::register_translate_blocks_ability();
		self::register_translate_content_ability();
		self::register_translate_content_bulk_ability();
		self::register_get_progress_ability();
		self::register_cancel_translation_ability();
		self::register_get_available_models_ability();
		self::register_save_additional_prompt_ability();
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
			'description'         => __( 'Returns all languages available for translation. Call this before a translation when the target language code is unknown.', 'slytranslate' ),
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
			'description'         => __( 'Returns translation status for one content item, including source_language and whether the site runs in single_entry_mode. Call this before translate-content to choose overwrite safely and avoid source-language mismatches.', 'slytranslate' ),
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
					'single_entry_mode' => array(
						'type'        => 'boolean',
						'description' => 'True when the active language plugin stores all language variants in one post (WP Multilang).',
					),
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

	/* --- set-post-language ---------------------------------------- */

	private static function register_set_post_language_ability(): void {
		wp_register_ability( 'ai-translate/set-post-language', array(
			'label'               => __( 'Set Post Language', 'slytranslate' ),
			'description'         => __( 'Changes the language assignment of a post, page, or custom post type entry. This ability is exposed only when the active language plugin supports post-language mutation (for example Polylang). Use force to override a target-language conflict and relink=true to rewrite translation relations.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'         => array( 'type' => 'integer', 'description' => 'The content item ID whose language should be changed.' ),
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code to assign to the content item.' ),
					'relink'          => array( 'type' => 'boolean', 'description' => 'When true, the translation relation map is rewritten so the current post is linked under target_language.' ),
					'force'           => array( 'type' => 'boolean', 'description' => 'When true, allows overriding an existing target-language conflict.', 'default' => false ),
				),
				'required' => array( 'post_id', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
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
				'required' => array( 'post_id', 'source_language', 'target_language', 'translations', 'changed' ),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_set_post_language' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta(),
		) );
	}

	/* --- get-untranslated ---------------------------------------- */

	private static function register_get_untranslated_ability(): void {
		wp_register_ability( 'ai-translate/get-untranslated', array(
			'label'               => __( 'Get Untranslated Content', 'slytranslate' ),
			'description'         => __( 'Lists posts, pages, or custom post types that do not yet have a translation in the requested language. Use this to collect candidates before calling translate-content-bulk.', 'slytranslate' ),
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
			'description'         => __( 'Translates arbitrary text from one language to another using the configured translation runtime. This does not create or update WordPress posts.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'text'              => array( 'type' => 'string', 'description' => 'The text to translate.', 'minLength' => 1 ),
					'source_language'   => array( 'type' => 'string', 'description' => 'Source language code.' ),
					'target_language'   => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'additional_prompt' => array( 'type' => 'string', 'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on.', 'maxLength' => 2000 ),
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
			'description'         => __( 'Translates one post, page, or custom post type entry and creates or updates exactly one target translation post. Call get-translation-status first, then pass source_language only when you intentionally pin the source variant.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'           => array( 'type' => 'integer', 'description' => 'The source content item ID.' ),
					'source_language'   => array( 'type' => 'string', 'description' => 'Optional source language code. Omit when unsure. In single_entry_mode, pass only get-translation-status.source_language or another explicit variant from get-languages.' ),
					'target_language'   => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'post_status'       => array( 'type' => 'string', 'description' => 'Optional post status for the translated item. Defaults to the source status when possible.' ),
					'translate_title'   => array( 'type' => 'boolean', 'description' => 'Whether the post title should be translated.', 'default' => true ),
					'overwrite'         => array( 'type' => 'boolean', 'description' => 'Overwrite existing translation.', 'default' => false ),
					'additional_prompt' => array( 'type' => 'string', 'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on.', 'maxLength' => 2000 ),
					'model_slug'        => array( 'type' => 'string', 'description' => 'Model slug/identifier to use for this translation. Overrides the site-wide default.' ),
				),
				'required' => array( 'post_id', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'translated_post_id'   => array( 'type' => 'integer', 'description' => 'WordPress post ID of the created or updated translation.' ),
					'source_post_id'       => array( 'type' => 'integer', 'description' => 'WordPress post ID of the source content item.' ),
					'target_language'      => array( 'type' => 'string', 'description' => 'Language code of the created translation.' ),
					'title'                => array( 'type' => 'string', 'description' => 'Translated post title.' ),
					'translated_post_type' => array( 'type' => 'string', 'description' => 'WordPress post type of the translated item (e.g. post, page).' ),
					'post_status'          => array( 'type' => 'string', 'description' => 'WordPress post status of the translated item (e.g. publish, draft).' ),
					'edit_link'            => array( 'type' => 'string', 'description' => 'Admin URL to edit the translated post directly.' ),
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
			'description'         => __( 'Translates multiple posts, pages, or custom post type entries. Choose exactly one source selector: pass post_ids for explicit items, or pass post_type with an optional limit to query candidates. If both are provided, post_ids take precedence. Continues on individual failures.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_ids'        => array( 'type' => 'array', 'description' => 'Array of post IDs to translate. Use this when the exact source posts are already known.', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => 50 ),
					'post_type'       => array( 'type' => 'string', 'description' => 'Optional post type used to discover source posts when post_ids are not provided.' ),
					'limit'           => array( 'type' => 'integer', 'description' => 'Maximum number of items to fetch when post_type is used.', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ),
					'source_language' => array( 'type' => 'string', 'description' => 'Optional source language code applied to each item. For WP Multilang this selects which language variant is used as source.' ),
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'post_status'     => array( 'type' => 'string', 'description' => 'Optional post status for the translated items. Defaults to the source status when possible.' ),
					'translate_title' => array( 'type' => 'boolean', 'description' => 'Whether the post title should be translated.', 'default' => true ),
					'overwrite'       => array( 'type' => 'boolean', 'description' => 'Overwrite existing translations.', 'default' => false ),
					'additional_prompt' => array( 'type' => 'string', 'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on for every item in the batch.', 'maxLength' => 2000 ),
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
								'source_post_id'     => array( 'type' => 'integer', 'description' => 'WordPress post ID of the source item.' ),
								'translated_post_id' => array( 'type' => 'integer', 'description' => 'WordPress post ID of the created or updated translation. 0 when status is failed.' ),
								'status'             => array( 'type' => 'string', 'description' => 'Outcome for this item.', 'enum' => array( 'success', 'skipped', 'failed' ) ),
								'error'              => array( 'type' => 'string', 'description' => 'Human-readable reason when status is skipped or failed. Null on success.' ),
								'edit_link'          => array( 'type' => 'string', 'description' => 'Admin edit URL for the translated post. Empty string when no post was created.' ),
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

	/* --- translate-blocks ---------------------------------------- */

	private static function register_translate_blocks_ability(): void {
		wp_register_ability( 'ai-translate/translate-blocks', array(
			'label'               => __( 'Translate Blocks', 'slytranslate' ),
			'description'         => __( 'Translates serialised Gutenberg block content from one language to another while preserving block markup. Use this for raw block content, not for creating translated posts.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'content'           => array( 'type' => 'string', 'description' => 'Serialised Gutenberg block content to translate.', 'minLength' => 1 ),
					'source_language'   => array( 'type' => 'string', 'description' => 'Source language code.' ),
					'target_language'   => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'additional_prompt' => array( 'type' => 'string', 'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on.', 'maxLength' => 2000 ),
					'model_slug'        => array( 'type' => 'string', 'description' => 'Model slug/identifier to use for this translation. Overrides the site-wide default.' ),
				),
				'required' => array( 'content', 'source_language', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'translated_content' => array( 'type' => 'string' ),
					'source_language'    => array( 'type' => 'string' ),
					'target_language'    => array( 'type' => 'string' ),
				),
				'required' => array( 'translated_content' ),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_translate_blocks' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'idempotent' => true ) ),
		) );
	}

	/* --- get-progress -------------------------------------------- */

	private static function register_get_progress_ability(): void {
		wp_register_ability( 'ai-translate/get-progress', array(
			'label'               => __( 'Get Translation Progress', 'slytranslate' ),
			'description'         => __( 'Returns the current translation progress for a running translation job. Pass post_id when you want the progress state for a specific content item.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'The post ID whose translation progress to retrieve.' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'phase'         => array( 'type' => 'string', 'description' => 'Current translation phase. One of: title, content, excerpt, meta, saving. Empty string means no translation is running.' ),
					'percent'       => array( 'type' => 'integer', 'description' => 'Overall completion percentage, 0–100.', 'minimum' => 0, 'maximum' => 100 ),
					'current_chunk' => array( 'type' => 'integer', 'description' => 'Number of character units processed in the current phase.' ),
					'total_chunks'  => array( 'type' => 'integer', 'description' => 'Total character units in the current phase. When 0, progress within the phase is indeterminate.' ),
				),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_get_progress' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	/* --- cancel-translation -------------------------------------- */

	private static function register_cancel_translation_ability(): void {
		wp_register_ability( 'ai-translate/cancel-translation', array(
			'label'               => __( 'Cancel Translation', 'slytranslate' ),
			'description'         => __( 'Signals the running translation job to stop and clears the progress indicator. Pass post_id when you also want that post-specific progress state cleared immediately.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'Optional post ID whose progress transient should be cleared.' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'cancelled' => array( 'type' => 'boolean' ),
				),
				'required' => array( 'cancelled' ),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_cancel_translation' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta(),
		) );
	}

	/* --- get-available-models ------------------------------------ */

	private static function register_get_available_models_ability(): void {
		wp_register_ability( 'ai-translate/get-available-models', array(
			'label'               => __( 'Get Available Models', 'slytranslate' ),
			'description'         => __( 'Returns the list of AI models available for translation through the configured connectors. Call this before setting model_slug when the available model identifiers are unknown. Each model object contains a value field (use this as model_slug in translation calls) and a label field (human-readable display name).', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'refresh' => array( 'type' => 'boolean', 'description' => 'When true, bypasses the transient cache and re-fetches the model list from all connectors.' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'models'           => array(
						'type'        => 'array',
						'description' => 'Available models. Use the value field of each entry as model_slug in translation calls.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'value' => array( 'type' => 'string', 'description' => 'Model slug to pass as model_slug in translate-text, translate-content, translate-blocks, and translate-content-bulk.' ),
								'label' => array( 'type' => 'string', 'description' => 'Human-readable display name in the format "ConnectorName: model-id".' ),
							),
							'required' => array( 'value', 'label' ),
						),
					),
					'defaultModelSlug' => array( 'type' => 'string', 'description' => 'The site-wide default model slug configured in AI Translate settings. Empty string means the connector default is used.' ),
					'refreshed'        => array( 'type' => 'boolean', 'description' => 'True when the model list was re-fetched from all connectors instead of served from cache.' ),
				),
				'required' => array( 'models' ),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_get_available_models' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	/* --- save-additional-prompt ---------------------------------- */

	private static function register_save_additional_prompt_ability(): void {
		wp_register_ability( 'ai-translate/save-additional-prompt', array(
			'label'               => __( 'Save Additional Prompt', 'slytranslate' ),
			'description'         => __( 'Persists the per-user additional translation instructions so they are pre-filled on the next visit. This stores a UI preference and does not start a translation by itself.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'additional_prompt' => array( 'type' => 'string', 'description' => 'The additional instructions to save for the current user.', 'maxLength' => 2000 ),
				),
				'required' => array( 'additional_prompt' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'additional_prompt' => array( 'type' => 'string' ),
				),
				'required' => array( 'additional_prompt' ),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_save_additional_prompt' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'idempotent' => true ) ),
		) );
	}

	/* --- configure ----------------------------------------------- */

	private static function register_configure_ability(): void {
		wp_register_ability( 'ai-translate/configure', array(
			'label'               => __( 'Configure AI Translate', 'slytranslate' ),
			'description'         => __( 'Reads or updates site-wide AI Translate settings. Call with an empty object to inspect current defaults. Use translate-* abilities for per-request overrides and configure only for persistent site-wide changes.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'prompt_template'       => array( 'type' => 'string', 'description' => 'Translation prompt template. Use {FROM_CODE} and {TO_CODE} as placeholders.' ),
					'prompt_addon'          => array( 'type' => 'string', 'description' => 'Optional site-wide addition always appended after the prompt template. Applied to every translation request.' ),
					'meta_keys_translate'   => array( 'type' => 'string', 'description' => 'Whitespace-separated list of meta keys to translate. Use a plain string, not an array.' ),
					'meta_keys_clear'       => array( 'type' => 'string', 'description' => 'Whitespace-separated list of meta keys to clear. Use a plain string, not an array.' ),
					'auto_translate_new'    => array( 'type' => 'boolean', 'description' => 'Auto-translate new translation posts in the active language plugin.' ),
					'context_window_tokens' => array( 'type' => 'integer', 'description' => 'Optional override for the model context window in tokens. Use 0 to fall back to auto-detection and learned values.', 'minimum' => 0, 'maximum' => 4000000 ),
					'model_slug'            => array( 'type' => 'string', 'description' => 'Model slug/identifier to pass to the AI connector (e.g. gemma3:27b). Leave empty to use the connector default.' ),
					'direct_api_url'        => array( 'type' => 'string', 'description' => 'Base URL of an OpenAI-compatible API server (e.g. http://192.168.178.42:8080). Normal translations use the WordPress AI Client transport; this endpoint is used for model profiles that explicitly require direct API handling (for example TranslateGemma). When saving, the plugin probes whether the endpoint supports chat_template_kwargs.' ),
					'force_direct_api'      => array( 'type' => 'boolean', 'description' => 'Deprecated compatibility flag. Normal translations use the WordPress AI Client transport. Direct API remains reserved for models that explicitly require it (for example TranslateGemma).' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'prompt_template'                  => array( 'type' => 'string', 'description' => 'Active translation prompt template. Contains {FROM_CODE} and {TO_CODE} placeholders.' ),
					'prompt_addon'                     => array( 'type' => 'string', 'description' => 'Site-wide additional instructions appended after the prompt template for every request.' ),
					'meta_keys_translate'              => array( 'type' => 'string', 'description' => 'Whitespace-separated list of custom meta keys to translate (user-configured).' ),
					'meta_keys_clear'                  => array( 'type' => 'string', 'description' => 'Whitespace-separated list of custom meta keys to clear on translation (user-configured).' ),
					'auto_translate_new'               => array( 'type' => 'boolean', 'description' => 'When true, new translation stubs created by the active language plugin are translated automatically.' ),
					'context_window_tokens'            => array( 'type' => 'integer', 'description' => 'Manual override for the model context window in tokens. 0 means auto-detection is active.' ),
					'model_slug'                       => array( 'type' => 'string', 'description' => 'Site-wide default model slug. Empty string means the connector default is used.' ),
					'direct_api_url'                   => array( 'type' => 'string', 'description' => 'Base URL of the optional OpenAI-compatible direct API server used by models that require it (e.g. TranslateGemma).' ),
					'force_direct_api'                 => array( 'type' => 'boolean', 'description' => 'Deprecated. Kept for backward compatibility. Do not set.' ),
					'direct_api_kwargs_supported'      => array( 'type' => 'boolean', 'description' => 'Whether the direct API endpoint was found to support chat_template_kwargs at the last probe.' ),
					'direct_api_kwargs_last_probed_at' => array( 'type' => 'integer', 'description' => 'Unix timestamp of the last chat_template_kwargs capability probe against the direct API endpoint.' ),
					'translategemma_runtime_ready'     => array( 'type' => 'boolean', 'description' => 'Whether the TranslateGemma model is loaded and ready at the configured direct API endpoint.' ),
					'translategemma_runtime_status'    => array( 'type' => 'string', 'description' => 'Human-readable TranslateGemma runtime status message.' ),
					'detected_seo_plugin'              => array( 'type' => 'string', 'description' => 'Slug of the detected SEO plugin (e.g. yoast-seo, rank-math, seopress). Empty string if none is active.' ),
					'detected_seo_plugin_label'        => array( 'type' => 'string', 'description' => 'Human-readable name of the detected SEO plugin.' ),
					'seo_meta_keys_translate'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Meta keys automatically added for translation by the detected SEO plugin.' ),
					'seo_meta_keys_clear'              => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Meta keys automatically cleared by the detected SEO plugin.' ),
					'effective_meta_keys_translate'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Final resolved list of meta keys to translate (user config + SEO plugin additions).' ),
					'effective_meta_keys_clear'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Final resolved list of meta keys to clear (user config + SEO plugin additions).' ),
					'learned_context_window_tokens'    => array( 'type' => 'integer', 'description' => 'Context window size (tokens) auto-learned from successful translations. 0 if not yet learned for the active model.' ),
					'effective_context_window_tokens'  => array( 'type' => 'integer', 'description' => 'Context window actually used: manual override when set, otherwise the learned value or connector default.' ),
					'effective_chunk_chars'            => array( 'type' => 'integer', 'description' => 'Maximum characters per translation chunk derived from the effective context window. Determines how long texts are split.' ),
					'last_transport_diagnostics'       => array(
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
			'execute_callback'    => array( AI_Translate::class, 'execute_configure' ),
			'permission_callback' => static function ( $input = null ) {
				return current_user_can( 'manage_options' );
			},
			'meta'                => self::public_mcp_meta( array( 'idempotent' => true ) ),
		) );
	}
}
