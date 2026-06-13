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
		if ( AcfOptionsTranslationService::is_available() ) {
			self::register_translate_options_ability();
		}
		if ( TermTranslationService::is_term_translation_supported() ) {
			self::register_translate_terms_ability();
		}
		self::register_get_translatable_fields_ability();
		self::register_get_progress_ability();
		self::register_cancel_translation_ability();
		self::register_get_available_models_ability();
		self::register_save_additional_prompt_ability();
		self::register_configure_ability();
		self::register_prepare_client_translation_ability();
		self::register_apply_client_translation_ability();
		self::register_prepare_client_translation_bulk_ability();
		self::register_apply_client_translation_bulk_ability();
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
			'description'         => __( 'Inspect one content item before translating. Returns source_language, single_entry_mode, and per-language existence data. In single-entry mode a later translate-content call can keep source_post_id and translated_post_id identical; in multi-post mode target languages use sibling post IDs.', 'slytranslate' ),
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
					'source_post_id'   => array( 'type' => 'integer', 'description' => 'Source WordPress post ID. In single-entry mode this is also the post that stores translated variants.' ),
					'source_post_type' => array( 'type' => 'string', 'description' => 'Source WordPress post type.' ),
					'source_title'     => array( 'type' => 'string', 'description' => 'Source title for the resolved source_language.' ),
					'source_language'  => array( 'type' => 'string', 'description' => 'Canonical source language resolved by the active adapter. Reuse this as translate-content.source_language when you intentionally pin the source variant.' ),
					'single_entry_mode' => array(
						'type'        => 'boolean',
						'description' => 'True when translated variants stay on the same WordPress post ID (WP Multilang, WPGlobus, TranslatePress). False when each target language uses a sibling post, for example Polylang.',
					),
					'translations'     => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
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
			'execute_callback'    => array( AI_Translate::class, 'execute_get_translation_status' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	/* --- set-post-language ---------------------------------------- */

	private static function register_set_post_language_ability(): void {
		wp_register_ability( 'ai-translate/set-post-language', array(
			'label'               => __( 'Set Post Language', 'slytranslate' ),
			'description'         => __( 'Changes the language assignment of an existing content item without running translation. Exposed only when the active adapter supports post-language mutation, currently multi-post adapters such as Polylang. Use relink=true only when translation relations must be rewritten, and force=true only to replace an existing target-language assignment.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'         => array( 'type' => 'integer', 'description' => 'The content item ID whose language should be changed.' ),
					'target_language' => array( 'type' => 'string', 'description' => 'Language code to assign to the existing content item.' ),
					'relink'          => array( 'type' => 'boolean', 'description' => 'When true, also rewrites the translation relation map so this post occupies target_language in the group.' ),
					'force'           => array( 'type' => 'boolean', 'description' => 'When true, allows taking over a target language that is already assigned elsewhere in the translation group.', 'default' => false ),
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
			'permission_callback' => array( LanguageMutationService::class, 'set_post_language_permission_callback' ),
			'meta'                => self::public_mcp_meta(),
		) );
	}

	/* --- get-untranslated ---------------------------------------- */

	private static function register_get_untranslated_ability(): void {
		wp_register_ability( 'ai-translate/get-untranslated', array(
			'label'               => __( 'Get Untranslated Content', 'slytranslate' ),
			'description'         => __( 'Lists candidate source items that are still missing the requested target language according to the active adapter. In single-entry mode the target variant is missing on the same post; in multi-post mode no sibling translation post exists. Use this before translate-content-bulk.', 'slytranslate' ),
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
			'description'         => __( 'Translates one content item into one target language. Single-entry adapters update the same post ID, while multi-post adapters create or update a sibling translated post. Call get-translation-status first, omit source_language unless you intentionally pin a source variant, and set overwrite=true only when the target language already exists.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'           => array( 'type' => 'integer', 'description' => 'The source content item ID.' ),
					'source_language'   => array( 'type' => 'string', 'description' => 'Optional source language to pin. Omit unless you intentionally select a specific source variant. For single-entry adapters, reuse get-translation-status.source_language or a confirmed language code from get-languages.' ),
					'target_language'   => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'post_status'       => array( 'type' => 'string', 'description' => 'Optional post status for the translated item. Defaults to the source status when possible.' ),
					'translate_title'   => array( 'type' => 'boolean', 'description' => 'Whether the post title should be translated.', 'default' => true ),
					'overwrite'         => array( 'type' => 'boolean', 'description' => 'When true, update an existing target translation instead of returning translation_exists.', 'default' => false ),
					'additional_prompt' => array( 'type' => 'string', 'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on.', 'maxLength' => 2000 ),
					'model_slug'        => array( 'type' => 'string', 'description' => 'Model slug/identifier to use for this translation. Overrides the site-wide default.' ),
				),
				'required' => array( 'post_id', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'translated_post_id'   => array( 'type' => 'integer', 'description' => 'Translated WordPress post ID. May equal source_post_id in single-entry mode; otherwise it is the sibling target post ID.' ),
					'source_post_id'       => array( 'type' => 'integer', 'description' => 'Source WordPress post ID.' ),
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
			'description'         => __( 'Translates multiple content items into one target language. Choose exactly one source selector: post_ids for explicit items, or post_type with optional limit for discovery. Single-entry adapters can return the same source_post_id and translated_post_id; multi-post adapters return sibling target post IDs. Pass source_language only when you intentionally pin the same source variant for every item, and set overwrite=true only when existing translations should be updated instead of skipped.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_ids'        => array( 'type' => 'array', 'description' => 'Explicit source post IDs to translate. When present, these take precedence over post_type and limit.', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => 50 ),
					'post_type'       => array( 'type' => 'string', 'description' => 'Post type used to discover source items only when post_ids are omitted.' ),
					'limit'           => array( 'type' => 'integer', 'description' => 'Maximum number of discovered items when post_type is used.', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ),
					'source_language' => array( 'type' => 'string', 'description' => 'Optional source language override applied only for adapters that support picking a source variant inside one post, such as WP Multilang or WPGlobus.' ),
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'post_status'     => array( 'type' => 'string', 'description' => 'Optional post status for the translated items. Defaults to the source status when possible.' ),
					'translate_title' => array( 'type' => 'boolean', 'description' => 'Whether the post title should be translated.', 'default' => true ),
					'overwrite'       => array( 'type' => 'boolean', 'description' => 'When true, update existing target translations instead of returning them as skipped.', 'default' => false ),
					'additional_prompt' => array( 'type' => 'string', 'description' => 'Optional extra instructions appended after the global prompt template and the site-wide prompt add-on for every item in the batch.', 'maxLength' => 2000 ),
					'model_slug'      => array( 'type' => 'string', 'description' => 'Model slug/identifier to use for this translation batch. Overrides the site-wide default.' ),
					'background'      => array( 'type' => 'boolean', 'description' => 'When true and a background transport (Action Scheduler or WP-Cron) is available, the batch is queued with one action per post and the call returns immediately with a job_id. Poll get-progress with that job_id and cancel via cancel-translation. Falls back to synchronous processing when no transport exists.', 'default' => false ),
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
								'translated_post_id' => array( 'type' => 'integer', 'description' => 'Translated WordPress post ID for this item. May equal source_post_id in single-entry mode. 0 when status is failed.' ),
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
					'queued'    => array( 'type' => 'boolean', 'description' => 'True when the batch was queued for background processing. Per-post results are then reported by get-progress with job_id instead of the results array.' ),
					'job_id'    => array( 'type' => 'string', 'description' => 'Background job identifier. Present only when queued is true.' ),
				),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_translate_posts' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta(),
		) );
	}

	/* --- translate-options ---------------------------------------- */

	private static function register_translate_options_ability(): void {
		wp_register_ability( 'ai-translate/translate-options', array(
			'label'               => __( 'Translate ACF Options', 'slytranslate' ),
			'description'         => __( 'Translates ACF fields on options pages (global content such as footer texts or CTAs) into one target language. Registered only when ACF options pages exist. With TranslatePress this returns not_required because its string table already covers option output. Use dry_run=true to preview which fields would be translated.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'source_language' => array( 'type' => 'string', 'description' => 'Source language code of the stored option values. Defaults to en.' ),
					'page_slug'       => array( 'type' => 'string', 'description' => 'Optional menu slug of one options page. Omit to process all options pages.' ),
					'dry_run'         => array( 'type' => 'boolean', 'description' => 'When true, only report which fields would be translated without calling the model or writing options.', 'default' => false ),
				),
				'required' => array( 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'status'          => array( 'type' => 'string', 'description' => 'ok, or not_required when the active language plugin already covers option output.' ),
					'message'         => array( 'type' => 'string' ),
					'target_language' => array( 'type' => 'string' ),
					'source_language' => array( 'type' => 'string' ),
					'dry_run'         => array( 'type' => 'boolean' ),
					'results'         => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'field'       => array( 'type' => 'string', 'description' => 'ACF field name.' ),
								'field_label' => array( 'type' => 'string' ),
								'field_type'  => array( 'type' => 'string' ),
								'page'        => array( 'type' => 'string', 'description' => 'Options page menu slug.' ),
								'status'      => array( 'type' => 'string', 'enum' => array( 'translated', 'pending', 'skipped', 'failed' ) ),
								'reason'      => array( 'type' => 'string', 'description' => 'Machine-readable reason when skipped or failed. requires_options_post_id_filter means the active language plugin has no language-aware option storage; provide the slytranslate_acf_options_post_id filter to opt in.' ),
							),
						),
					),
					'total'           => array( 'type' => 'integer' ),
					'translated'      => array( 'type' => 'integer' ),
					'skipped'         => array( 'type' => 'integer' ),
					'failed'          => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_translate_options' ),
			'permission_callback' => static function ( $input = null ) {
				return current_user_can( 'manage_options' );
			},
			'meta'                => self::public_mcp_meta(),
		) );
	}

	/* --- translate-terms ------------------------------------------ */

	private static function register_translate_terms_ability(): void {
		wp_register_ability( 'ai-translate/translate-terms', array(
			'label'               => __( 'Translate Taxonomy Terms', 'slytranslate' ),
			'description'         => __( 'Bulk-translates taxonomy terms (categories, tags, custom taxonomies) into one target language and links them as translations. Registered only when the active language plugin (Polylang) supports term translation. Use dry_run=true to preview which terms would be translated. Terms that already have a translation are skipped.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy'        => array( 'type' => 'string', 'description' => 'Taxonomy to process, for example category or post_tag.' ),
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'source_language' => array( 'type' => 'string', 'description' => 'Source language code. Defaults to the language plugin default language.' ),
					'term_ids'        => array( 'type' => 'array', 'description' => 'Explicit term IDs to translate. Omit to process all terms of the taxonomy in the source language.', 'items' => array( 'type' => 'integer' ) ),
					'dry_run'         => array( 'type' => 'boolean', 'description' => 'When true, only report which terms would be translated without calling the model or writing terms.', 'default' => false ),
					'model_slug'      => array( 'type' => 'string', 'description' => 'Model slug/identifier to use for this batch. Overrides the site-wide default.' ),
				),
				'required' => array( 'taxonomy', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'results'   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'term_id'            => array( 'type' => 'integer', 'description' => 'Source term ID.' ),
								'translated_term_id' => array( 'type' => 'integer', 'description' => 'Target-language term ID. 0 when status is failed or would_translate.' ),
								'status'             => array( 'type' => 'string', 'enum' => array( 'success', 'skipped', 'failed', 'would_translate' ) ),
								'error'              => array( 'type' => 'string', 'description' => 'Human-readable reason when status is failed. Null otherwise.' ),
							),
						),
					),
					'total'     => array( 'type' => 'integer' ),
					'succeeded' => array( 'type' => 'integer' ),
					'failed'    => array( 'type' => 'integer' ),
					'skipped'   => array( 'type' => 'integer' ),
					'dry_run'   => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_translate_terms' ),
			'permission_callback' => static function ( $input = null ) {
				return current_user_can( 'manage_categories' );
			},
			'meta'                => self::public_mcp_meta(),
		) );
	}

	/* --- get-translatable-fields ----------------------------------- */

	private static function register_get_translatable_fields_ability(): void {
		wp_register_ability( 'ai-translate/get-translatable-fields', array(
			'label'               => __( 'Get Translatable Fields', 'slytranslate' ),
			'description'         => __( 'Introspects which meta fields a translate-content call would translate or clear, including where each key comes from (manual config, SEO plugin, ACF/field-plugin resolution, default, or filter). Pass post_id for the post-specific view including runtime-resolved ACF fields; omit it for the global view. Call this before translate-content when you need to know what will happen to custom fields.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'Optional post ID for the post-specific field resolution. 0 or omitted returns the global view without post context.' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'          => array( 'type' => 'integer', 'description' => 'Echoed post context. 0 for the global view.' ),
					'fields'           => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'key'         => array( 'type' => 'string', 'description' => 'Meta key.' ),
								'action'      => array( 'type' => 'string', 'enum' => array( 'translate', 'clear' ), 'description' => 'What translate-content does with this key.' ),
								'source'      => array( 'type' => 'string', 'enum' => array( 'manual', 'seo', 'acf', 'default', 'filter' ), 'description' => 'Where the key comes from: manual settings, the detected SEO plugin, ACF/field-plugin resolution, the plugin default list, or a third-party filter.' ),
								'field_type'  => array( 'type' => 'string', 'description' => 'Field type when resolved from a field plugin (for example text, wysiwyg, link). Empty otherwise.' ),
								'field_label' => array( 'type' => 'string', 'description' => 'Human-readable field label when resolved from a field plugin. Empty otherwise.' ),
								'excluded'    => array( 'type' => 'boolean', 'description' => 'True when the key is on the exclusion list and therefore not processed despite being listed.' ),
							),
							'required' => array( 'key', 'action', 'source' ),
						),
					),
					'seo_plugin'       => array( 'type' => 'string', 'description' => 'Slug of the detected SEO plugin. Empty string when none is active.' ),
					'seo_plugin_label' => array( 'type' => 'string', 'description' => 'Human-readable name of the detected SEO plugin.' ),
					'active_resolvers' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Active field-plugin resolvers: acf, metabox, pods.' ),
				),
				'required' => array( 'fields' ),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_get_translatable_fields' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
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
					'job_id'  => array( 'type' => 'string', 'description' => 'Background queue job ID returned by translate-content-bulk with background=true. When given and known, the job status is returned instead of the per-post phase progress.' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'phase'         => array( 'type' => 'string', 'description' => 'Current translation phase. One of: title, content, excerpt, meta, saving. Empty string means no translation is running.' ),
					'percent'       => array( 'type' => 'integer', 'description' => 'Overall completion percentage, 0–100.', 'minimum' => 0, 'maximum' => 100 ),
					'current_chunk' => array( 'type' => 'integer', 'description' => 'Number of character units processed in the current phase.' ),
					'total_chunks'  => array( 'type' => 'integer', 'description' => 'Total character units in the current phase. When 0, progress within the phase is indeterminate.' ),
					'job_id'        => array( 'type' => 'string', 'description' => 'Echoed background job ID. Present only for queue-job lookups.' ),
					'status'        => array( 'type' => 'string', 'description' => 'Queue job status: queued, running, completed, or cancelled. Present only for queue-job lookups.' ),
					'total'         => array( 'type' => 'integer', 'description' => 'Total posts in the queue job. Present only for queue-job lookups.' ),
					'processed'     => array( 'type' => 'integer', 'description' => 'Posts processed so far in the queue job. Present only for queue-job lookups.' ),
					'succeeded'     => array( 'type' => 'integer', 'description' => 'Successful posts in the queue job. Present only for queue-job lookups.' ),
					'failed'        => array( 'type' => 'integer', 'description' => 'Failed posts in the queue job. Present only for queue-job lookups.' ),
					'skipped'       => array( 'type' => 'integer', 'description' => 'Skipped posts in the queue job. Present only for queue-job lookups.' ),
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
					'job_id'  => array( 'type' => 'string', 'description' => 'Optional background queue job ID to cancel. Pending scheduled actions of the job are unscheduled.' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'cancelled'     => array( 'type' => 'boolean' ),
					'job_cancelled' => array( 'type' => 'boolean', 'description' => 'True when a known background queue job was cancelled.' ),
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
			'description'         => __( 'Reads or updates persistent site-wide AI Translate settings. Call with an empty object to inspect current values. Input properties are persistent settings only; runtime diagnostics such as effective concurrency, SEO detection, and transport status are returned in output as inspect-only fields.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'prompt_template'       => array( 'type' => 'string', 'description' => 'Persistent setting: translation prompt template. Use {FROM_CODE} and {TO_CODE} as placeholders.' ),
					'prompt_addon'          => array( 'type' => 'string', 'description' => 'Persistent setting: optional site-wide instructions appended after the prompt template for every translation request.' ),
					'meta_keys_translate'   => array( 'type' => 'string', 'description' => 'Persistent setting: whitespace-separated list of meta keys to translate. Use a plain string, not an array.' ),
					'meta_keys_clear'       => array( 'type' => 'string', 'description' => 'Persistent setting: whitespace-separated list of meta keys to clear. Use a plain string, not an array.' ),
					'meta_keys_exclude'     => array( 'type' => 'string', 'description' => 'Persistent setting: whitespace-separated list of meta keys to exclude from translation. Applies to manual, SEO-detected, and auto-resolved field-plugin keys alike.' ),
					'auto_translate_new'    => array( 'type' => 'boolean', 'description' => 'Persistent setting: queue draft translations for all missing target languages when a source-language post is published. Requires a background transport (Action Scheduler or WP-Cron).' ),
					'translate_terms'       => array( 'type' => 'boolean', 'description' => 'Persistent setting: when a translated post references taxonomy terms without a target-language translation, create and link AI-translated terms instead of dropping them. Polylang only.' ),
					'translate_slugs'       => array( 'type' => 'boolean', 'description' => 'Persistent setting: derive the slug of newly created translations from the translated title instead of keeping the source-language slug. Existing translation slugs are never changed.' ),
					'glossary'              => array(
						'type'        => 'array',
						'description' => 'Persistent setting: glossary entries. mode=keep terms are never translated; mode=translate terms use the fixed translation from the per-language map.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'term' => array( 'type' => 'string', 'description' => 'The source term.' ),
								'mode' => array( 'type' => 'string', 'enum' => array( 'keep', 'translate' ), 'description' => 'keep = never translate; translate = always use the fixed translation.' ),
								'to'   => array( 'type' => 'object', 'description' => 'Fixed translations keyed by target language code. Required for mode=translate.', 'additionalProperties' => array( 'type' => 'string' ) ),
							),
							'required' => array( 'term', 'mode' ),
						),
					),
					'context_window_tokens' => array( 'type' => 'integer', 'description' => 'Persistent setting: manual model context-window override in tokens. Use 0 to fall back to auto-detection and learned values.', 'minimum' => 0, 'maximum' => 4000000 ),
					'string_table_concurrency' => array( 'type' => 'integer', 'description' => 'Persistent setting: opt-in maximum concurrency for TranslatePress-style string-table batches. Values above 1 only activate when a successful concurrency probe recommends parallel execution for the active model.', 'minimum' => 1, 'maximum' => 4 ),
					'model_slug'            => array( 'type' => 'string', 'description' => 'Persistent setting: site-wide default model slug/identifier passed to the AI connector. Leave empty to use the connector default.' ),
					'direct_api_url'        => array( 'type' => 'string', 'description' => 'Persistent setting: base URL of an optional OpenAI-compatible API server (e.g. http://192.168.178.42:8080). Normal translations use the WordPress AI Client transport; this endpoint is used only for model profiles that explicitly require direct API handling, for example TranslateGemma. When saving, the plugin probes whether the endpoint supports chat_template_kwargs.' ),
					'force_direct_api'      => array( 'type' => 'boolean', 'description' => 'Persistent setting, deprecated: compatibility flag. Normal translations use the WordPress AI Client transport. Direct API remains reserved for models that explicitly require it, for example TranslateGemma.' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'prompt_template'                  => array( 'type' => 'string', 'description' => 'Persistent setting: active translation prompt template. Contains {FROM_CODE} and {TO_CODE} placeholders.' ),
					'prompt_addon'                     => array( 'type' => 'string', 'description' => 'Persistent setting: site-wide additional instructions appended after the prompt template for every request.' ),
					'meta_keys_translate'              => array( 'type' => 'string', 'description' => 'Persistent setting: whitespace-separated list of custom meta keys to translate.' ),
					'meta_keys_clear'                  => array( 'type' => 'string', 'description' => 'Persistent setting: whitespace-separated list of custom meta keys to clear on translation.' ),
					'meta_keys_exclude'                => array( 'type' => 'string', 'description' => 'Persistent setting: whitespace-separated list of meta keys excluded from translation.' ),
					'auto_translate_new'               => array( 'type' => 'boolean', 'description' => 'Persistent setting: whether publishing a source-language post queues draft translations for all missing target languages.' ),
					'translate_terms'                  => array( 'type' => 'boolean', 'description' => 'Persistent setting: whether missing taxonomy term translations are created automatically during post translation (Polylang only).' ),
					'translate_slugs'                  => array( 'type' => 'boolean', 'description' => 'Persistent setting: whether slugs of newly created translations are derived from the translated title.' ),
					'glossary'                         => array(
						'type'        => 'array',
						'description' => 'Persistent setting: glossary entries applied to every translation prompt.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'term' => array( 'type' => 'string' ),
								'mode' => array( 'type' => 'string', 'enum' => array( 'keep', 'translate' ) ),
								'to'   => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ) ),
							),
						),
					),
					'context_window_tokens'            => array( 'type' => 'integer', 'description' => 'Persistent setting: manual override for the model context window in tokens. 0 means auto-detection is active.' ),
					'string_table_concurrency'         => array( 'type' => 'integer', 'description' => 'Persistent setting: configured opt-in maximum concurrency for string-table batch translation.' ),
					'string_table_concurrency_effective' => array( 'type' => 'integer', 'description' => 'Inspect-only diagnostic: effective string-table concurrency after applying the probe recommendation and available transport.' ),
					'string_table_concurrency_recommended' => array( 'type' => 'integer', 'description' => 'Inspect-only diagnostic: most recent recommended string-table concurrency for the active model based on the last probe result.' ),
					'string_table_concurrency_supported' => array( 'type' => 'boolean', 'description' => 'Inspect-only diagnostic: whether a parallel HTTP transport is currently available for string-table concurrency.' ),
					'string_table_concurrency_transport' => array( 'type' => 'string', 'description' => 'Inspect-only diagnostic: transport used for string-table concurrency, for example filtered_runner or wporg_requests.' ),
					'model_slug'                       => array( 'type' => 'string', 'description' => 'Persistent setting: site-wide default model slug. Empty string means the connector default is used.' ),
					'direct_api_url'                   => array( 'type' => 'string', 'description' => 'Persistent setting: base URL of the optional OpenAI-compatible direct API server used by models that require it, for example TranslateGemma.' ),
					'force_direct_api'                 => array( 'type' => 'boolean', 'description' => 'Persistent setting, deprecated: kept for backward compatibility. Do not set.' ),
					'direct_api_kwargs_supported'      => array( 'type' => 'boolean', 'description' => 'Inspect-only diagnostic: whether the direct API endpoint was found to support chat_template_kwargs at the last probe.' ),
					'direct_api_kwargs_last_probed_at' => array( 'type' => 'integer', 'description' => 'Inspect-only diagnostic: Unix timestamp of the last chat_template_kwargs capability probe against the direct API endpoint.' ),
					'translategemma_runtime_ready'     => array( 'type' => 'boolean', 'description' => 'Inspect-only diagnostic: whether the TranslateGemma model is loaded and ready at the configured direct API endpoint.' ),
					'translategemma_runtime_status'    => array( 'type' => 'string', 'description' => 'Inspect-only diagnostic: human-readable TranslateGemma runtime status message.' ),
					'detected_seo_plugin'              => array( 'type' => 'string', 'description' => 'Inspect-only diagnostic: slug of the detected SEO plugin. Empty string if none is active.' ),
					'detected_seo_plugin_label'        => array( 'type' => 'string', 'description' => 'Inspect-only diagnostic: human-readable name of the detected SEO plugin.' ),
					'seo_meta_keys_translate'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Inspect-only diagnostic: meta keys automatically added for translation by the detected SEO plugin.' ),
					'seo_meta_keys_clear'              => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Inspect-only diagnostic: meta keys automatically cleared by the detected SEO plugin.' ),
					'effective_meta_keys_translate'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Inspect-only diagnostic: final resolved list of meta keys to translate (user config plus SEO plugin additions).' ),
					'effective_meta_keys_clear'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Inspect-only diagnostic: final resolved list of meta keys to clear (user config plus SEO plugin additions).' ),
					'learned_context_window_tokens'    => array( 'type' => 'integer', 'description' => 'Inspect-only diagnostic: context window size auto-learned from successful translations. 0 if not yet learned for the active model.' ),
					'effective_context_window_tokens'  => array( 'type' => 'integer', 'description' => 'Inspect-only diagnostic: context window actually used after applying manual override, learned value, or connector default.' ),
					'effective_chunk_chars'            => array( 'type' => 'integer', 'description' => 'Inspect-only diagnostic: maximum characters per translation chunk derived from the effective context window.' ),
					'last_transport_diagnostics'       => array(
						'type'       => 'object',
						'description' => 'Inspect-only diagnostic: snapshot of the most recent transport decision and failure data.',
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

	/* --- prepare-client-translation ----------------------------- */

	private static function register_prepare_client_translation_ability(): void {
		wp_register_ability( 'ai-translate/prepare-client-translation', array(
			'label'               => __( 'Prepare Client Translation', 'slytranslate' ),
			'description'         => __( 'Decompose one source post into labelled translation units for client-side LLM translation. Returns a structured unit list. Call ai-translate/apply-client-translation once all units are translated.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'source_post_id'  => array( 'type' => 'integer', 'description' => 'The source post ID to prepare.' ),
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'source_language' => array( 'type' => 'string', 'description' => 'Optional source language override for single-entry adapters.' ),
					'overwrite'       => array( 'type' => 'boolean', 'description' => 'When true, prepare even if a translation already exists.' ),
					'post_status'     => array( 'type' => 'string', 'description' => 'Optional target post status.' ),
				),
				'required' => array( 'source_post_id', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'source_post_id'          => array( 'type' => 'integer' ),
					'source_language'         => array( 'type' => 'string' ),
					'target_language'         => array( 'type' => 'string' ),
					'single_entry_mode'       => array( 'type' => 'boolean' ),
					'overwrite'               => array( 'type' => 'boolean' ),
					'post_status'             => array( 'type' => 'string' ),
					'existing_translation_id' => array( 'type' => 'integer' ),
					'source_hash'             => array( 'type' => 'string' ),
					'units'                   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
									'id'          => array( 'type' => 'string', 'description' => 'Unit identifier. Use this as id in the translations array when calling apply-client-translation.' ),
									'field'       => array( 'type' => 'string', 'description' => 'Post field this unit belongs to, e.g. title, content, excerpt, or a meta key.' ),
									'source'      => array( 'type' => 'string', 'description' => 'Source text to translate.' ),
									'format'      => array( 'type' => 'string', 'description' => 'Content format: html or plain.' ),
									'lookup_keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Alternative lookup variants for string-table adapters like TranslatePress. Not relevant for field-based adapters.' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_prepare_client_translation' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	/* --- apply-client-translation -------------------------------- */

	private static function register_apply_client_translation_ability(): void {
		wp_register_ability( 'ai-translate/apply-client-translation', array(
			'label'               => __( 'Apply Client Translation', 'slytranslate' ),
			'description'         => __( 'Persist translated units for one post that were prepared with prepare-client-translation. Pass translations as [{id, translated}] where each id matches a unit id from the prepare step.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'source_post_id'     => array( 'type' => 'integer', 'description' => 'The source post ID.' ),
					'target_language'    => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'source_language'    => array( 'type' => 'string', 'description' => 'Optional source language override.' ),
					'overwrite'          => array( 'type' => 'boolean' ),
					'post_status'        => array( 'type' => 'string' ),
					'source_hash'        => array( 'type' => 'string', 'description' => 'Source hash from prepare step. Checked for staleness unless allow_stale_source is true.' ),
					'allow_stale_source' => array( 'type' => 'boolean', 'description' => 'Skip source-staleness check.' ),
					'translations'       => array(
						'type'        => 'array',
						'description' => 'Array of translated units: [{id, translated}].',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'id'         => array( 'type' => 'string' ),
								'translated' => array( 'type' => 'string' ),
							),
							'required'   => array( 'id', 'translated' ),
						),
					),
				),
				'required' => array( 'source_post_id', 'target_language', 'translations' ),
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
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_apply_client_translation' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta(),
		) );
	}

	/* --- prepare-client-translation-bulk ------------------------- */

	private static function register_prepare_client_translation_bulk_ability(): void {
		wp_register_ability( 'ai-translate/prepare-client-translation-bulk', array(
			'label'               => __( 'Prepare Client Translation (Bulk)', 'slytranslate' ),
			'description'         => __( 'Decompose multiple source posts into translation-unit packages for client-side LLM translation. Returns one package per post. Call ai-translate/apply-client-translation-bulk once all packages are translated.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_ids'        => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 50,
						'items'    => array( 'type' => 'integer' ),
						'description' => 'Explicit source post IDs.',
					),
					'post_type'       => array( 'type' => 'string', 'description' => 'Post type for discovery when post_ids are omitted.' ),
					'limit'           => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
					'target_language' => array( 'type' => 'string' ),
					'source_language' => array( 'type' => 'string' ),
					'overwrite'       => array( 'type' => 'boolean' ),
					'post_status'     => array( 'type' => 'string' ),
				),
				'required' => array( 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'target_language' => array( 'type' => 'string' ),
					'total'           => array( 'type' => 'integer' ),
					'packages'        => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				),
			),
			'execute_callback'    => array( AI_Translate::class, 'execute_prepare_client_translation_bulk' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	/* --- apply-client-translation-bulk --------------------------- */

	private static function register_apply_client_translation_bulk_ability(): void {
		wp_register_ability( 'ai-translate/apply-client-translation-bulk', array(
			'label'               => __( 'Apply Client Translation (Bulk)', 'slytranslate' ),
			'description'         => __( 'Persist translated unit packages for multiple posts prepared with prepare-client-translation-bulk. Each package must include source_post_id, target_language, and translations as [{id, translated}] where each id matches a unit from the prepare-bulk response.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'packages' => array(
						'type'        => 'array',
						'description' => 'Array of packages, each an apply-single input with source_post_id, target_language, and translations as [{id, translated}] where each id matches a unit from the prepare-bulk response.',
						'items'       => array( 'type' => 'object' ),
						'minItems'    => 1,
					),
				),
				'required' => array( 'packages' ),
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
			'execute_callback'    => array( AI_Translate::class, 'execute_apply_client_translation_bulk' ),
			'permission_callback' => array( static::class, 'permission_callback' ),
			'meta'                => self::public_mcp_meta(),
		) );
	}
}
