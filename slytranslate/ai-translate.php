<?php
/*
Plugin Name: SlyTranslate - AI Translation Abilities
Plugin URI: https://wordpress.org/plugins/slytranslate/
Description: AI translation abilities for WordPress using WordPress 7 native AI Connectors as a core feature, plus the AI Client and Abilities API for text and content translation.
Version: 1.4.0
Author: Timon Först
Author URI: https://github.com/SlyBase/wordpress-slytranslate
Requires at least: 7.0
Requires PHP: 8.1
License: MIT
Text Domain: slytranslate
Domain Path: /languages
*/

namespace AI_Translate;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/inc/TranslationPluginAdapter.php';
require_once __DIR__ . '/inc/PolylangAdapter.php';
require_once __DIR__ . '/inc/SeoPluginDetector.php';
require_once __DIR__ . '/inc/AbilityRegistrar.php';
require_once __DIR__ . '/inc/EditorBootstrap.php';
require_once __DIR__ . '/inc/ConfigurationService.php';

class AI_Translate {

	// Default prompt template.
	public static $PROMPT = 'Translate the content from {FROM_CODE} to {TO_CODE} preserving html, formatting and embedded media. Only return the new content.';

	private const VERSION              = '1.4.0';
	private const EDITOR_SCRIPT_HANDLE = 'ai-translate-editor';
	private const EDITOR_REST_NAMESPACE = 'ai-translate/v1';
	private const INTERNAL_META_KEYS_TO_SKIP = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_encloseme',
		'_pingme',
	);

	private const DEFAULT_CONTEXT_WINDOW_TOKENS = 8192;
	private const MIN_CONTEXT_WINDOW_TOKENS     = 2048;
	private const MIN_TRANSLATION_CHARS         = 1200;
	private const MAX_TRANSLATION_CHARS         = 8000;
	private const SAFE_CHARS_PER_CONTEXT_TOKEN  = 0.5;
	private const MAX_SHORT_TEXT_RESPONSE_RATIO = 4;
	private const KNOWN_MODEL_CONTEXT_WINDOWS   = array(
		'claude'        => 200000,
		'gemini-2.5'    => 1000000,
		'gemini-2.0'    => 1000000,
		'gemini-1.5'    => 1000000,
		'gpt-4.5'       => 128000,
		'gpt-4.1'       => 128000,
		'gpt-4o'        => 128000,
		'gpt-4-turbo'   => 128000,
		'gpt-3.5-turbo' => 16385,
		'o4-mini'       => 128000,
		'o3'            => 128000,
		'mistral-large'   => 32768,
		'mistral-small'   => 32768,
		'sonar'           => 32768,
		'grok'            => 32768,
		'translategemma'  => 8192,
	);

	// Cached meta key lists.
	public static $meta_translate;
	public static $meta_clear;

	// Adapter instance.
	private static $adapter;
	private static $translation_runtime_context;
	private static $translation_progress_context;
	private static $seo_plugin_config;

	// Per-request model slug override (set by editor REST endpoints).
	private static $model_slug_request_override;

	// Per-request language code context (set during translate() for direct API with chat_template_kwargs).
	private static $translation_source_lang;
	private static $translation_target_lang;
	private static $last_translation_transport_diagnostics;

	/* ---------------------------------------------------------------
	 * Adapter
	 * ------------------------------------------------------------- */

	/**
	 * Get the active translation plugin adapter.
	 *
	 * @return TranslationPluginAdapter|null
	 */
	public static function get_adapter(): ?TranslationPluginAdapter {
		if ( null === self::$adapter ) {
			$polylang = new PolylangAdapter();
			if ( $polylang->is_available() ) {
				self::$adapter = $polylang;
			}
			// Future adapters (WPML, TranslatePress, …) can be checked here.
		}
		return self::$adapter;
	}

	private static function current_user_can_access_translation_abilities(): bool {
		foreach ( array( 'edit_posts', 'edit_pages', 'publish_posts', 'publish_pages', 'manage_options' ) as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------- */

	public static function add_hooks() {
		add_action( 'enqueue_block_editor_assets', array( static::class, 'enqueue_editor_plugin' ) );
		add_action( 'rest_api_init', array( static::class, 'register_editor_rest_routes' ) );

		// Abilities API registration (WP 7.0+).
		add_action( 'wp_abilities_api_categories_init', array( AbilityRegistrar::class, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( AbilityRegistrar::class, 'register_abilities' ) );

		// Polylang auto-translate hooks (kept for backward compatibility).
		add_filter( 'default_title', array( static::class, 'default_title' ), 10, 2 );
		add_filter( 'default_content', array( static::class, 'default_content' ), 10, 2 );
		add_filter( 'default_excerpt', array( static::class, 'default_excerpt' ), 10, 2 );
		add_filter( 'pll_translate_post_meta', array( static::class, 'pll_translate_post_meta' ), 10, 3 );
	}

	public static function enqueue_editor_plugin() {
		EditorBootstrap::enqueue_editor_plugin();
	}

	public static function register_editor_rest_routes() {
		self::register_editor_rest_route( '/ai-translate/get-languages', array( static::class, 'rest_execute_get_languages' ) );
		self::register_editor_rest_route( '/ai-translate/get-translation-status', array( static::class, 'rest_execute_get_translation_status' ) );
		self::register_editor_rest_route( '/ai-translate/translation-progress', array( static::class, 'rest_execute_get_translation_progress' ) );
		self::register_editor_rest_route( '/ai-translate/translate-text', array( static::class, 'rest_execute_translate_text' ) );
		self::register_editor_rest_route( '/ai-translate/translate-content', array( static::class, 'rest_execute_translate_content' ) );
		self::register_editor_rest_route( '/ai-translate/translate-post', array( static::class, 'rest_execute_translate_content' ) );
		self::register_editor_rest_route( '/ai-translate/cancel-translation', array( static::class, 'rest_cancel_translation' ) );

		// User preference endpoint (save last-used additional prompt per user).
		register_rest_route(
			self::EDITOR_REST_NAMESPACE,
			'/ai-translate/user-preference',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( static::class, 'rest_execute_save_user_preference' ),
				'permission_callback' => array( static::class, 'rest_can_access_translation_abilities' ),
			)
		);
	}

	private static function register_editor_rest_route( string $route, callable $callback ): void {
		register_rest_route(
			self::EDITOR_REST_NAMESPACE,
			$route,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => $callback,
				'permission_callback' => array( static::class, 'rest_can_access_translation_abilities' ),
			)
		);
	}

	public static function rest_can_access_translation_abilities( \WP_REST_Request $request ): bool {
		return self::current_user_can_access_translation_abilities();
	}

	private static function get_editor_rest_input( \WP_REST_Request $request ): array {
		$input = $request->get_param( 'input' );

		return is_array( $input ) ? $input : array();
	}

	public static function rest_execute_get_languages( \WP_REST_Request $request ) {
		return self::execute_get_languages();
	}

	public static function rest_execute_get_translation_status( \WP_REST_Request $request ) {
		return self::execute_get_translation_status( self::get_editor_rest_input( $request ) );
	}

	public static function rest_execute_get_translation_progress( \WP_REST_Request $request ) {
		return self::get_translation_progress();
	}

	public static function rest_execute_translate_text( \WP_REST_Request $request ) {
		return self::execute_translate_text( self::get_editor_rest_input( $request ) );
	}

	public static function rest_execute_translate_content( \WP_REST_Request $request ) {
		self::clear_translation_cancelled_flag();
		self::clear_translation_progress();
		return self::execute_translate_content( self::get_editor_rest_input( $request ) );
	}

	public static function rest_cancel_translation( \WP_REST_Request $request ) {
		self::set_translation_cancelled_flag();
		return array( 'cancelled' => true );
	}

	public static function rest_execute_save_user_preference( \WP_REST_Request $request ) {
		$input             = self::get_editor_rest_input( $request );
		$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] )
			? mb_substr( sanitize_textarea_field( $input['additional_prompt'] ), 0, 2000 )
			: '';

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return new \WP_Error( 'not_logged_in', __( 'You must be logged in to save preferences.', 'slytranslate' ) );
		}

		update_user_meta( $user_id, '_ai_translate_last_additional_prompt', $additional_prompt );

		return array( 'additional_prompt' => $additional_prompt );
	}

	/* ---------------------------------------------------------------
	 * Abilities API – Category
	 * ------------------------------------------------------------- */

	public static function register_ability_category() {
		wp_register_ability_category( 'ai-translation', array(
			'label'       => __( 'AI Translation', 'slytranslate' ),
			'description' => __( 'AI-powered content translation abilities.', 'slytranslate' ),
		) );
	}

	/* ---------------------------------------------------------------
	 * Abilities API – Register all abilities
	 * ------------------------------------------------------------- */

	public static function register_abilities() {
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

	/* --- get-languages ------------------------------------------- */

	private static function register_get_languages_ability() {
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
			'execute_callback'    => array( static::class, 'execute_get_languages' ),
			'permission_callback' => function ( $input = null ) {
				return self::current_user_can_access_translation_abilities();
			},
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	public static function execute_get_languages() {
		$adapter = self::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}
		$languages = $adapter->get_languages();
		$result    = array();
		foreach ( $languages as $code => $name ) {
			$result[] = array( 'code' => $code, 'name' => $name );
		}
		return $result;
	}

	/* --- get-translation-status ---------------------------------- */

	private static function register_get_translation_status_ability() {
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
					'source_language' => array( 'type' => 'string' ),
					'translations'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'lang'    => array( 'type' => 'string' ),
								'post_id' => array( 'type' => 'integer' ),
								'exists'  => array( 'type' => 'boolean' ),
								'title'       => array( 'type' => 'string' ),
								'post_status' => array( 'type' => 'string' ),
								'edit_link'   => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( static::class, 'execute_get_translation_status' ),
			'permission_callback' => function ( $input = null ) {
				return self::current_user_can_access_translation_abilities();
			},
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	public static function execute_get_translation_status( $input ) {
		$adapter = self::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'slytranslate' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden_post', __( 'You are not allowed to inspect this content item.', 'slytranslate' ) );
		}
		$post_type_check = self::validate_translatable_post_type( $post->post_type );
		if ( is_wp_error( $post_type_check ) ) {
			return $post_type_check;
		}

		$source_lang  = $adapter->get_post_language( $post_id );
		$translations = $adapter->get_post_translations( $post_id );
		$languages    = $adapter->get_languages();

		$status = array();
		foreach ( $languages as $code => $name ) {
			if ( $code === $source_lang ) {
				continue;
			}
			$status[] = self::build_translation_status_entry( $code, isset( $translations[ $code ] ) ? absint( $translations[ $code ] ) : 0 );
		}

		return array(
			'source_post_id'   => $post_id,
			'source_post_type' => $post->post_type,
			'source_title'     => $post->post_title,
			'source_language' => $source_lang ?? '',
			'translations'    => $status,
		);
	}

	/* --- get-untranslated ---------------------------------------- */

	private static function register_get_untranslated_ability() {
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
			'execute_callback'    => array( static::class, 'execute_get_untranslated' ),
			'permission_callback' => function ( $input = null ) {
				return self::current_user_can_access_translation_abilities();
			},
			'meta'                => self::public_mcp_meta( array( 'readonly' => true ) ),
		) );
	}

	public static function execute_get_untranslated( $input ) {
		$adapter = self::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}

		$target_language = sanitize_key( $input['target_language'] ?? '' );
		if ( '' === $target_language ) {
			return new \WP_Error( 'missing_target_language', __( 'Target language is required.', 'slytranslate' ) );
		}

		$post_type       = sanitize_key( $input['post_type'] ?? 'post' );
		$post_type_check = self::validate_translatable_post_type( $post_type );
		if ( is_wp_error( $post_type_check ) ) {
			return $post_type_check;
		}

		$limit    = self::normalize_bulk_limit( $input['limit'] ?? 20 );
		$post_ids = self::query_post_ids_by_type( $post_type, $limit * 3 );
		$items    = array();

		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$source_language = $adapter->get_post_language( $post_id ) ?? '';
			if ( '' !== $source_language && $source_language === $target_language ) {
				continue;
			}

			if ( self::get_existing_translation_id( $post_id, $target_language, $adapter ) > 0 ) {
				continue;
			}

			$items[] = self::build_source_post_summary( $post, $source_language );
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array(
			'post_type'       => $post_type,
			'target_language' => $target_language,
			'items'           => $items,
			'total'           => count( $items ),
		);
	}

	/* --- translate-text ------------------------------------------ */

	private static function register_translate_text_ability() {
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
					'translated_text'  => array( 'type' => 'string' ),
					'source_language'  => array( 'type' => 'string' ),
					'target_language'  => array( 'type' => 'string' ),
				),
				'required' => array( 'translated_text' ),
			),
			'execute_callback'    => array( static::class, 'execute_translate_text' ),
			'permission_callback' => function ( $input = null ) {
				return self::current_user_can_access_translation_abilities();
			},
			'meta'                => self::public_mcp_meta( array( 'idempotent' => true ) ),
		) );
	}

	public static function execute_translate_text( $input ) {
		$input = is_array( $input ) ? $input : array();

		return self::with_model_slug_override(
			$input,
			function () use ( $input ) {
				$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] ) ? mb_substr( sanitize_textarea_field( $input['additional_prompt'] ), 0, 2000 ) : '';
				$translated = self::translate( $input['text'], $input['target_language'], $input['source_language'], $additional_prompt );

				if ( is_wp_error( $translated ) ) {
					return $translated;
				}

				return array(
					'translated_text' => $translated,
					'source_language' => $input['source_language'],
					'target_language' => $input['target_language'],
				);
			}
		);
	}

	/* --- translate-content --------------------------------------- */

	private static function register_translate_content_ability() {
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
					'translated_post_id' => array( 'type' => 'integer' ),
					'source_post_id'     => array( 'type' => 'integer' ),
					'target_language'    => array( 'type' => 'string' ),
					'title'              => array( 'type' => 'string' ),
					'translated_post_type' => array( 'type' => 'string' ),
					'post_status'          => array( 'type' => 'string' ),
					'edit_link'            => array( 'type' => 'string' ),
				),
				'required' => array( 'translated_post_id', 'source_post_id' ),
			),
			'execute_callback'    => array( static::class, 'execute_translate_content' ),
			'permission_callback' => function ( $input = null ) {
				return self::current_user_can_access_translation_abilities();
			},
			'meta'                => self::public_mcp_meta(),
		) );
	}

	public static function execute_translate_content( $input ) {
		$input = is_array( $input ) ? $input : array();

		return self::with_model_slug_override(
			$input,
			function () use ( $input ) {
				$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] ) ? mb_substr( sanitize_textarea_field( $input['additional_prompt'] ), 0, 2000 ) : '';
				$result = self::translate_post(
					$input['post_id'],
					$input['target_language'],
					$input['post_status'] ?? '',
					$input['overwrite'] ?? false,
					$input['translate_title'] ?? true,
					$additional_prompt
				);

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$translated_post = get_post( $result );

				return array(
					'translated_post_id' => $result,
					'source_post_id'     => $input['post_id'],
					'target_language'    => $input['target_language'],
					'title'              => $translated_post ? $translated_post->post_title : '',
					'translated_post_type' => $translated_post ? $translated_post->post_type : '',
					'post_status'          => $translated_post ? $translated_post->post_status : '',
					'edit_link'            => $translated_post ? (string) get_edit_post_link( $translated_post->ID, 'raw' ) : '',
				);
			}
		);
	}

	/* --- translate-content-bulk ---------------------------------- */

	private static function register_translate_content_bulk_ability() {
		wp_register_ability( 'ai-translate/translate-content-bulk', array(
			'label'               => __( 'Translate Content (Bulk)', 'slytranslate' ),
			'description'         => __( 'Translates multiple posts, pages, or custom post type entries. Continues on individual failures.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_ids'        => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'minItems' => 1,
						'maxItems' => 50,
						'description' => 'Array of post IDs to translate.',
					),
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
			'execute_callback'    => array( static::class, 'execute_translate_posts' ),
			'permission_callback' => function ( $input = null ) {
				return self::current_user_can_access_translation_abilities();
			},
			'meta'                => self::public_mcp_meta(),
		) );
	}

	public static function execute_translate_posts( $input ) {
		$input = is_array( $input ) ? $input : array();

		return self::with_model_slug_override(
			$input,
			function () use ( $input ) {
				$adapter = self::get_adapter();
				if ( ! $adapter ) {
					return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
				}

				$post_ids = self::resolve_bulk_source_post_ids( $input );
				if ( is_wp_error( $post_ids ) ) {
					return $post_ids;
				}

				$results         = array();
				$succeeded       = 0;
				$failed          = 0;
				$skipped         = 0;
				$target_language = sanitize_key( $input['target_language'] ?? '' );
				$overwrite       = ! empty( $input['overwrite'] );

				foreach ( $post_ids as $post_id ) {
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						$failed++;
						$results[] = array(
							'source_post_id'     => $post_id,
							'translated_post_id' => 0,
							'status'             => 'failed',
							'error'              => __( 'You are not allowed to translate this content item.', 'slytranslate' ),
							'edit_link'          => '',
						);
						continue;
					}

					$source_language = $adapter->get_post_language( $post_id ) ?? '';
					if ( '' !== $source_language && $source_language === $target_language ) {
						$skipped++;
						$results[] = array(
							'source_post_id'     => $post_id,
							'translated_post_id' => 0,
							'status'             => 'skipped',
							'error'              => __( 'The source content is already in the requested target language.', 'slytranslate' ),
							'edit_link'          => '',
						);
						continue;
					}

					$existing_translation = self::get_existing_translation_id( $post_id, $target_language, $adapter );
					if ( $existing_translation > 0 && ! $overwrite ) {
						$skipped++;
						$results[] = array(
							'source_post_id'     => $post_id,
							'translated_post_id' => $existing_translation,
							'status'             => 'skipped',
							'error'              => __( 'A translation already exists for the requested language.', 'slytranslate' ),
							'edit_link'          => (string) get_edit_post_link( $existing_translation, 'raw' ),
						);
						continue;
					}

					$result = self::translate_post(
						$post_id,
						$target_language,
						$input['post_status'] ?? '',
						$overwrite,
						$input['translate_title'] ?? true
					);

					if ( is_wp_error( $result ) ) {
						$failed++;
						$results[] = array(
							'source_post_id'     => $post_id,
							'translated_post_id' => 0,
							'status'             => 'failed',
							'error'              => $result->get_error_message(),
							'edit_link'          => '',
						);
					} else {
						$succeeded++;
						$results[] = array(
							'source_post_id'     => $post_id,
							'translated_post_id' => $result,
							'status'             => 'success',
							'error'              => null,
							'edit_link'          => (string) get_edit_post_link( $result, 'raw' ),
						);
					}
				}

				return array(
					'results'   => $results,
					'total'     => count( $post_ids ),
					'succeeded' => $succeeded,
					'failed'    => $failed,
					'skipped'   => $skipped,
				);
			}
		);
	}

	/* --- configure ----------------------------------------------- */

	private static function register_configure_ability() {
		wp_register_ability( 'ai-translate/configure', array(
			'label'               => __( 'Configure AI Translate', 'slytranslate' ),
			'description'         => __( 'Read or update AI Translate settings, including prompt template, meta keys, SEO defaults, and auto-translate behavior.', 'slytranslate' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'prompt_template'    => array( 'type' => 'string', 'description' => 'Translation prompt template. Use {FROM_CODE} and {TO_CODE} as placeholders.' ),
					'prompt_addon'       => array( 'type' => 'string', 'description' => 'Optional site-wide addition always appended after the prompt template. Applied to every translation request.' ),
					'meta_keys_translate' => array( 'type' => 'string', 'description' => 'Whitespace-separated list of meta keys to translate.' ),
					'meta_keys_clear'    => array( 'type' => 'string', 'description' => 'Whitespace-separated list of meta keys to clear.' ),
					'auto_translate_new' => array( 'type' => 'boolean', 'description' => 'Auto-translate new translation posts in Polylang.' ),
					'context_window_tokens' => array( 'type' => 'integer', 'description' => 'Optional override for the model context window in tokens. Use 0 to fall back to auto-detection and learned values.' ),
					'model_slug' => array( 'type' => 'string', 'description' => 'Model slug/identifier to pass to the AI connector (e.g. gemma3:27b). Leave empty to use the connector default.' ),
					'direct_api_url' => array( 'type' => 'string', 'description' => 'Base URL of an OpenAI-compatible API server (e.g. http://192.168.178.42:8080). When set, the plugin sends translation requests directly to this endpoint instead of using the WP AI Client. Works with llama.cpp, ollama, mlx-lm, vLLM, or any OpenAI-compatible server. Leave empty to use the standard AI Client. When saving, the plugin automatically probes whether the server supports chat_template_kwargs for optimized translation.' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'prompt_template'    => array( 'type' => 'string' ),
					'prompt_addon'       => array( 'type' => 'string' ),
					'meta_keys_translate' => array( 'type' => 'string' ),
					'meta_keys_clear'    => array( 'type' => 'string' ),
					'auto_translate_new' => array( 'type' => 'boolean' ),
					'context_window_tokens' => array( 'type' => 'integer' ),
					'model_slug' => array( 'type' => 'string' ),
					'direct_api_url' => array( 'type' => 'string' ),
					'direct_api_kwargs_supported' => array( 'type' => 'boolean', 'description' => 'Auto-detected: whether the server at direct_api_url supports chat_template_kwargs. Re-detected whenever direct_api_url or model_slug is saved.' ),
					'direct_api_kwargs_last_probed_at' => array( 'type' => 'integer', 'description' => 'Unix timestamp of the last chat_template_kwargs capability probe for direct_api_url.' ),
					'translategemma_runtime_ready' => array( 'type' => 'boolean', 'description' => 'Whether the current TranslateGemma configuration is ready for safe translation execution.' ),
					'translategemma_runtime_status' => array( 'type' => 'string', 'description' => 'Diagnostic status for TranslateGemma runtime safety: not-selected, ready, direct-api-required, or kwargs-required.' ),
					'detected_seo_plugin' => array( 'type' => 'string' ),
					'detected_seo_plugin_label' => array( 'type' => 'string' ),
					'seo_meta_keys_translate' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'seo_meta_keys_clear' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'effective_meta_keys_translate' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'effective_meta_keys_clear' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'learned_context_window_tokens' => array( 'type' => 'integer' ),
					'effective_context_window_tokens' => array( 'type' => 'integer' ),
					'effective_chunk_chars' => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( static::class, 'execute_configure' ),
			'permission_callback' => function ( $input = null ) {
				return current_user_can( 'manage_options' );
			},
			'meta'                => self::public_mcp_meta( array( 'idempotent' => true ) ),
		) );
	}

	public static function execute_configure( $input ) {
		$input = is_array( $input ) ? $input : array();
		$config_result = ConfigurationService::save( $input );
		if ( is_wp_error( $config_result ) ) {
			return $config_result;
		}

		// Reset cached meta keys.
		self::$meta_translate = null;
		self::$meta_clear     = null;
		self::$seo_plugin_config = null;
		self::$translation_runtime_context = null;
		self::$last_translation_transport_diagnostics = null;
		EditorBootstrap::clear_available_models_cache();
		self::get_translation_runtime_context();
		$seo_plugin_config    = self::get_active_seo_plugin_config();
		$translategemma_status = self::get_translategemma_runtime_status();

		return array(
			'prompt_template'    => get_option( 'ai_translate_prompt', self::$PROMPT ),
			'prompt_addon'       => get_option( 'ai_translate_prompt_addon', '' ),
			'meta_keys_translate' => get_option( 'ai_translate_meta_translate', '' ),
			'meta_keys_clear'    => get_option( 'ai_translate_meta_clear', '' ),
			'auto_translate_new' => get_option( 'ai_translate_new_post', '0' ) === '1',
			'context_window_tokens' => absint( get_option( 'ai_translate_context_window_tokens', 0 ) ),
			'model_slug' => get_option( 'ai_translate_model_slug', '' ),
			'direct_api_url' => get_option( 'ai_translate_direct_api_url', '' ),
			'direct_api_kwargs_supported' => get_option( 'ai_translate_direct_api_kwargs_detected', '0' ) === '1',
			'direct_api_kwargs_last_probed_at' => absint( get_option( 'ai_translate_direct_api_kwargs_last_probed_at', 0 ) ),
			'translategemma_runtime_ready' => $translategemma_status['ready'],
			'translategemma_runtime_status' => $translategemma_status['status'],
			'detected_seo_plugin' => $seo_plugin_config['key'],
			'detected_seo_plugin_label' => $seo_plugin_config['label'],
			'seo_meta_keys_translate' => $seo_plugin_config['translate'],
			'seo_meta_keys_clear' => $seo_plugin_config['clear'],
			'effective_meta_keys_translate' => self::meta_translate(),
			'effective_meta_keys_clear' => self::meta_clear(),
			'learned_context_window_tokens' => self::get_learned_context_window_tokens(),
			'effective_context_window_tokens' => self::get_effective_context_window_tokens(),
			'effective_chunk_chars' => self::get_translation_chunk_char_limit(),
		);
	}

	/* ---------------------------------------------------------------
	 * Polylang auto-translate hooks (backward compatibility)
	 * ------------------------------------------------------------- */

	public static function default_title( $title, $post ) {
		$pattern = '/[^\p{L}\p{N}]+$/u';
		return preg_replace( $pattern, '', wp_strip_all_tags( self::translate_field( $title, 'post_title' ) ) );
	}

	public static function default_content( $content, $post ) {
		return self::translate_field( $content, 'post_content' );
	}

	public static function default_excerpt( $excerpt, $post ) {
		return self::translate_field( $excerpt, 'post_excerpt' );
	}

	public static function pll_translate_post_meta( $value, $key, $lang ) {
		if ( in_array( $key, self::meta_clear(), true ) ) {
			$value = '';
		} elseif ( in_array( $key, self::meta_translate(), true ) ) {
			$value = self::translate_field( $value, $key, true );
		}
		return $value;
	}

	/* ---------------------------------------------------------------
	 * Translation core
	 * ------------------------------------------------------------- */

	private static function get_new_post_translation_request_context(): ?array {
		static $request_context = null;
		static $is_loaded       = false;

		if ( $is_loaded ) {
			return $request_context;
		}

		$is_loaded = true;

		if ( ! is_admin() || ! isset( $GLOBALS['pagenow'] ) || 'post-new.php' !== $GLOBALS['pagenow'] ) {
			return null;
		}

		if ( ! isset( $_GET['_wpnonce'] ) ) {
			return null;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'new-post-translation' ) ) {
			return null;
		}

		if ( ! isset( $_GET['from_post'], $_GET['new_lang'], $_GET['post_type'] ) ) {
			return null;
		}

		$post_id   = absint( wp_unslash( $_GET['from_post'] ) );
		$to        = sanitize_key( wp_unslash( $_GET['new_lang'] ) );
		$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );

		if ( $post_id < 1 || '' === $to || '' === $post_type ) {
			return null;
		}

		$source_post = get_post( $post_id );
		if ( ! $source_post || $source_post->post_type !== $post_type ) {
			return null;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}

		$from = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : 'en';

		$request_context = array(
			'source_post'     => $source_post,
			'source_post_id'  => $post_id,
			'source_language' => $from ?: 'en',
			'target_language' => $to,
		);

		return $request_context;
	}

	public static function translate_field( $original, $field = '', $meta = false ) {
		if ( get_option( 'ai_translate_new_post', '0' ) !== '1' ) {
			return $original;
		}

		$request_context = self::get_new_post_translation_request_context();
		if ( ! $request_context ) {
			return $original;
		}

		$to      = $request_context['target_language'];
		$post_id = $request_context['source_post_id'];

		if ( $field ) {
			if ( $meta ) {
				$original = get_post_meta( $post_id, $field, true );
			} else {
				$original = $request_context['source_post']->$field ?? $original;
			}
		}

		$source_language = $request_context['source_language'];
		if ( ! $meta && 'post_content' === $field && is_string( $original ) ) {
			$translation = self::translate_post_content( $original, $to, $source_language );
		} else {
			$translation = self::translate( $original, $to, $source_language );
		}

		return is_wp_error( $translation ) ? $original : $translation;
	}

	public static function prompt( $to, $from = 'en', $additional_prompt = '' ) {
		$template    = get_option( 'ai_translate_prompt', self::$PROMPT );
		$base_prompt = str_replace(
			array( '{FROM_CODE}', '{TO_CODE}' ),
			array( $from, $to ),
			$template
		);

		$parts = array( $base_prompt );

		$global_addon = get_option( 'ai_translate_prompt_addon', '' );
		if ( is_string( $global_addon ) && '' !== trim( $global_addon ) ) {
			$parts[] = trim( $global_addon );
		}

		if ( is_string( $additional_prompt ) && '' !== trim( $additional_prompt ) ) {
			$parts[] = trim( $additional_prompt );
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Check whether the current user has requested cancellation.
	 */
	private static function is_translation_cancelled(): bool {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return false;
		}
		return (bool) get_transient( 'ai_translate_cancel_' . $user_id );
	}

	private static function set_translation_cancelled_flag(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			set_transient( 'ai_translate_cancel_' . $user_id, 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	private static function clear_translation_cancelled_flag(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			delete_transient( 'ai_translate_cancel_' . $user_id );
		}
	}

	private static function get_translation_progress_transient_key(): ?string {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return null;
		}

		return 'ai_translate_progress_' . $user_id;
	}

	private static function get_default_translation_progress(): array {
		return array(
			'phase'         => '',
			'current_chunk' => 0,
			'total_chunks'  => 0,
			'percent'       => 0,
		);
	}

	private static function get_translation_progress(): array {
		$transient_key = self::get_translation_progress_transient_key();
		if ( null === $transient_key ) {
			return self::get_default_translation_progress();
		}

		$progress = get_transient( $transient_key );
		if ( ! is_array( $progress ) ) {
			return self::get_default_translation_progress();
		}

		return array(
			'phase'         => isset( $progress['phase'] ) && is_string( $progress['phase'] ) ? $progress['phase'] : '',
			'current_chunk' => absint( $progress['current_chunk'] ?? 0 ),
			'total_chunks'  => absint( $progress['total_chunks'] ?? 0 ),
			'percent'       => min( 100, max( 0, absint( $progress['percent'] ?? 0 ) ) ),
		);
	}

	private static function set_translation_progress( string $phase, int $current_chunk = 0, int $total_chunks = 0 ): void {
		$transient_key = self::get_translation_progress_transient_key();
		if ( null === $transient_key ) {
			return;
		}

		$current_chunk = max( 0, $current_chunk );
		$total_chunks  = max( 0, $total_chunks );

		if ( $total_chunks > 0 ) {
			$current_chunk = min( $current_chunk, $total_chunks );
		} else {
			$current_chunk = 0;
		}

		set_transient(
			$transient_key,
			array(
				'phase'         => $phase,
				'current_chunk' => $current_chunk,
				'total_chunks'  => $total_chunks,
				'percent'       => self::calculate_translation_progress_percent( $phase ),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	private static function clear_translation_progress(): void {
		$transient_key = self::get_translation_progress_transient_key();
		if ( null !== $transient_key ) {
			delete_transient( $transient_key );
		}

		self::clear_translation_progress_context();
	}

	private static function calculate_translation_progress_percent( string $phase ): int {
		if ( 'done' === $phase ) {
			return 100;
		}

		if ( ! is_array( self::$translation_progress_context ) ) {
			return 0;
		}

		$total_steps     = max( 1, absint( self::$translation_progress_context['total_steps'] ?? 0 ) );
		$completed_steps = min( $total_steps, absint( self::$translation_progress_context['completed_steps'] ?? 0 ) );

		return (int) round( ( $completed_steps / $total_steps ) * 100 );
	}

	private static function initialize_translation_progress_context( bool $translate_title, string $content ): void {
		$content_total_chunks = self::count_content_translation_chunks( $content, self::get_translation_chunk_char_limit() );
		$total_steps          = ( $translate_title ? 1 : 0 ) + $content_total_chunks + 3;

		self::$translation_progress_context = array(
			'phase'                  => '',
			'total_steps'            => max( 1, $total_steps ),
			'completed_steps'        => 0,
			'content_total_chunks'   => $content_total_chunks,
			'content_completed_chunks' => 0,
		);
	}

	private static function clear_translation_progress_context(): void {
		self::$translation_progress_context = null;
	}

	private static function has_content_translation_progress(): bool {
		return is_array( self::$translation_progress_context ) && absint( self::$translation_progress_context['content_total_chunks'] ?? 0 ) > 0;
	}

	private static function mark_translation_phase( string $phase ): void {
		$current_chunk = 0;
		$total_chunks  = 0;

		if ( is_array( self::$translation_progress_context ) ) {
			self::$translation_progress_context['phase'] = $phase;

			if ( 'content' === $phase ) {
				$current_chunk = absint( self::$translation_progress_context['content_completed_chunks'] ?? 0 );
				$total_chunks  = absint( self::$translation_progress_context['content_total_chunks'] ?? 0 );
			}
		}

		self::set_translation_progress( $phase, $current_chunk, $total_chunks );
	}

	private static function advance_translation_progress_steps( int $steps = 1 ): void {
		if ( ! is_array( self::$translation_progress_context ) || $steps < 1 ) {
			return;
		}

		$total_steps = max( 1, absint( self::$translation_progress_context['total_steps'] ?? 0 ) );
		self::$translation_progress_context['completed_steps'] = min(
			$total_steps,
			absint( self::$translation_progress_context['completed_steps'] ?? 0 ) + $steps
		);
	}

	private static function advance_content_translation_progress(): int {
		if ( ! is_array( self::$translation_progress_context ) || 'content' !== ( self::$translation_progress_context['phase'] ?? '' ) ) {
			return 0;
		}

		$content_total_chunks = absint( self::$translation_progress_context['content_total_chunks'] ?? 0 );
		if ( $content_total_chunks < 1 ) {
			return 0;
		}

		self::$translation_progress_context['content_completed_chunks'] = min(
			$content_total_chunks,
			absint( self::$translation_progress_context['content_completed_chunks'] ?? 0 ) + 1
		);
		self::advance_translation_progress_steps();
		self::set_translation_progress(
			'content',
			absint( self::$translation_progress_context['content_completed_chunks'] ?? 0 ),
			$content_total_chunks
		);

		return 1;
	}

	private static function rewind_content_translation_progress( int $completed_chunks ): void {
		if ( $completed_chunks < 1 || ! is_array( self::$translation_progress_context ) || 'content' !== ( self::$translation_progress_context['phase'] ?? '' ) ) {
			return;
		}

		self::$translation_progress_context['content_completed_chunks'] = max(
			0,
			absint( self::$translation_progress_context['content_completed_chunks'] ?? 0 ) - $completed_chunks
		);
		self::$translation_progress_context['completed_steps'] = max(
			0,
			absint( self::$translation_progress_context['completed_steps'] ?? 0 ) - $completed_chunks
		);
		self::set_translation_progress(
			'content',
			absint( self::$translation_progress_context['content_completed_chunks'] ?? 0 ),
			absint( self::$translation_progress_context['content_total_chunks'] ?? 0 )
		);
	}

	private static function synchronize_content_translation_chunks( int $chunk_count, ?int $previous_chunk_count = null ): void {
		if ( ! is_array( self::$translation_progress_context ) || 'content' !== ( self::$translation_progress_context['phase'] ?? '' ) ) {
			return;
		}

		$chunk_count = max( 0, $chunk_count );

		if ( null === $previous_chunk_count ) {
			if ( 0 === absint( self::$translation_progress_context['content_total_chunks'] ?? 0 ) ) {
				self::$translation_progress_context['content_total_chunks'] = $chunk_count;
				self::$translation_progress_context['total_steps']          = max( 1, absint( self::$translation_progress_context['total_steps'] ?? 0 ) + $chunk_count );
			}

			return;
		}

		$delta = $chunk_count - max( 0, $previous_chunk_count );
		if ( 0 === $delta ) {
			return;
		}

		self::$translation_progress_context['content_total_chunks'] = max(
			absint( self::$translation_progress_context['content_completed_chunks'] ?? 0 ),
			absint( self::$translation_progress_context['content_total_chunks'] ?? 0 ) + $delta
		);
		self::$translation_progress_context['total_steps'] = max(
			absint( self::$translation_progress_context['completed_steps'] ?? 0 ) + 1,
			absint( self::$translation_progress_context['total_steps'] ?? 0 ) + $delta
		);
	}

	private static function count_translation_chunks( string $text, int $chunk_char_limit ): int {
		return count( self::split_text_for_translation( $text, $chunk_char_limit ) );
	}

	private static function count_serialized_block_chunks( array $blocks, int $chunk_char_limit ): int {
		if ( empty( $blocks ) ) {
			return 0;
		}

		$serialized_blocks = serialize_blocks( $blocks );
		if ( '' === trim( $serialized_blocks ) ) {
			return 0;
		}

		return self::count_translation_chunks( $serialized_blocks, $chunk_char_limit );
	}

	private static function count_content_translation_chunks( string $content, int $chunk_char_limit ): int {
		if ( '' === trim( $content ) ) {
			return 0;
		}

		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return self::count_translation_chunks( $content, $chunk_char_limit );
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || empty( $blocks ) ) {
			return self::count_translation_chunks( $content, $chunk_char_limit );
		}

		$pending_blocks = array();
		$total_chunks   = 0;

		foreach ( $blocks as $block ) {
			if ( self::should_skip_block_translation( $block ) ) {
				$total_chunks  += self::count_serialized_block_chunks( $pending_blocks, $chunk_char_limit );
				$pending_blocks = array();
				continue;
			}

			$pending_blocks[] = $block;
		}

		$total_chunks += self::count_serialized_block_chunks( $pending_blocks, $chunk_char_limit );

		return $total_chunks;
	}

	/**
	 * Translate text using the WordPress AI Client.
	 *
	 * @param string $text Text to translate.
	 * @param string $to   Target language code.
	 * @param string $from Source language code.
	 * @return string|\WP_Error Translated text or WP_Error on failure.
	 */
	public static function translate( $text, $to, $from = 'en', $additional_prompt = '' ) {
		if ( ! $text || trim( $text ) === '' ) {
			return '';
		}

		self::$translation_source_lang = $from;
		self::$translation_target_lang = $to;
		self::$last_translation_transport_diagnostics = null;

		try {
			$prompt = self::prompt( $to, $from, $additional_prompt );
			return self::translate_with_chunk_limit( $text, $prompt, self::get_translation_chunk_char_limit() );
		} finally {
			self::$translation_source_lang = null;
			self::$translation_target_lang = null;
			self::$last_translation_transport_diagnostics = null;
		}
	}

	private static function translate_with_chunk_limit( string $text, string $prompt, int $chunk_char_limit, int $attempt = 0, ?int $previous_chunk_count = null ) {
		$chunks = self::split_text_for_translation( $text, $chunk_char_limit );

		if ( empty( $chunks ) ) {
			return '';
		}

		self::synchronize_content_translation_chunks( count( $chunks ), $previous_chunk_count );

		$translated_chunks = array();
		$completed_chunks  = 0;
		foreach ( $chunks as $chunk ) {
			if ( self::is_translation_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			$translated_chunk = self::translate_chunk( $chunk, $prompt );
			if ( is_wp_error( $translated_chunk ) ) {
				$adjusted_chunk_char_limit = self::maybe_adjust_chunk_limit_from_error( $translated_chunk, $chunk_char_limit );
				if ( $adjusted_chunk_char_limit > 0 && $attempt < 2 ) {
					self::rewind_content_translation_progress( $completed_chunks );
					return self::translate_with_chunk_limit( $text, $prompt, $adjusted_chunk_char_limit, $attempt + 1, count( $chunks ) );
				}

				return $translated_chunk;
			}

			$translated_chunks[] = $translated_chunk;
			$completed_chunks   += self::advance_content_translation_progress();
		}

		return implode( '', $translated_chunks );
	}

	private static function with_model_slug_override( $input, callable $callback ) {
		$previous_override = self::$model_slug_request_override;
		self::$model_slug_request_override = is_array( $input ) && isset( $input['model_slug'] ) && is_string( $input['model_slug'] ) && '' !== $input['model_slug']
			? sanitize_text_field( $input['model_slug'] )
			: null;

		try {
			return $callback();
		} finally {
			self::$model_slug_request_override = $previous_override;
		}
	}

	private static function translate_chunk( string $text, string $prompt, int $validation_attempt = 0 ) {
		$runtime_context = self::get_translation_runtime_context();
		$model_slug      = self::$model_slug_request_override ?? $runtime_context['model_slug'];
		$requires_strict_direct_api = self::model_requires_strict_direct_api( $model_slug );
		$direct_api_url            = $runtime_context['direct_api_url'];
		$kwargs_supported          = self::direct_api_kwargs_supported();

		if ( $requires_strict_direct_api && is_string( $direct_api_url ) && '' !== $direct_api_url && ! $kwargs_supported ) {
			$kwargs_supported = self::refresh_direct_api_kwargs_detection( $direct_api_url, $model_slug );
		}

		if ( $requires_strict_direct_api ) {
			if ( ! is_string( $direct_api_url ) || '' === $direct_api_url ) {
				self::record_translation_transport_diagnostics( array(
					'transport'         => 'blocked',
					'model_slug'        => $model_slug,
					'direct_api_url'    => '',
					'kwargs_supported'  => false,
					'fallback_allowed'  => false,
					'failure_reason'    => 'direct_api_required',
				) );

				return new \WP_Error(
					'translategemma_requires_direct_api',
					__( 'TranslateGemma requires a direct API endpoint. Configure direct_api_url for your llama.cpp server or switch to an instruct model.', 'slytranslate' )
				);
			}

			if ( ! $kwargs_supported ) {
				self::record_translation_transport_diagnostics( array(
					'transport'         => 'blocked',
					'model_slug'        => $model_slug,
					'direct_api_url'    => $direct_api_url,
					'kwargs_supported'  => false,
					'fallback_allowed'  => false,
					'failure_reason'    => 'kwargs_required',
				) );

				return new \WP_Error(
					'translategemma_requires_kwargs',
					__( 'TranslateGemma requires chat_template_kwargs support on the configured direct API endpoint. Re-save the direct API settings after the server is reachable, or switch to an instruct model.', 'slytranslate' )
				);
			}
		}

		if ( is_string( $direct_api_url ) && '' !== $direct_api_url ) {
			$result = self::translate_chunk_direct_api( $text, $prompt, $model_slug, $direct_api_url, $kwargs_supported );
			if ( null !== $result ) {
				self::record_translation_transport_diagnostics( array(
					'transport'         => 'direct_api',
					'model_slug'        => $model_slug,
					'direct_api_url'    => $direct_api_url,
					'kwargs_supported'  => $kwargs_supported,
					'fallback_allowed'  => ! $requires_strict_direct_api,
					'failure_reason'    => '',
				) );
				return $result;
			}

			if ( $requires_strict_direct_api ) {
				self::record_translation_transport_diagnostics( array(
					'transport'         => 'direct_api_failed',
					'model_slug'        => $model_slug,
					'direct_api_url'    => $direct_api_url,
					'kwargs_supported'  => $kwargs_supported,
					'fallback_allowed'  => false,
					'failure_reason'    => 'direct_api_failed',
				) );

				return new \WP_Error(
					'translategemma_direct_api_failed',
					__( 'TranslateGemma direct API request failed. SlyTranslate did not fall back to the WordPress AI Client because TranslateGemma requires chat_template_kwargs for reliable translations.', 'slytranslate' )
				);
			}
		}

		$builder = wp_ai_client_prompt( $text )
			->using_system_instruction( $prompt )
			->using_temperature( 0 );

		if ( '' !== $model_slug && is_callable( array( $builder, 'using_model_preference' ) ) ) {
			$builder = $builder->using_model_preference( $model_slug );
		}

		self::record_translation_transport_diagnostics( array(
			'transport'         => 'wp_ai_client',
			'model_slug'        => $model_slug,
			'direct_api_url'    => is_string( $direct_api_url ) ? $direct_api_url : '',
			'kwargs_supported'  => $kwargs_supported,
			'fallback_allowed'  => true,
			'failure_reason'    => '',
		) );

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$validation_error = self::validate_translated_output( $text, $result );
		if ( is_wp_error( $validation_error ) ) {
			self::record_validation_failure_diagnostics( $validation_error );

			if ( 0 === $validation_attempt && self::should_retry_after_validation_failure( $model_slug ) ) {
				return self::translate_chunk( $text, self::build_retry_prompt( $prompt ), 1 );
			}

			return $validation_error;
		}

		return $result;
	}

	/**
	 * Translate a chunk via a direct OpenAI-compatible API call.
	 *
	 * Always sends a system instruction with the translation prompt. When the
	 * direct_api_kwargs option is enabled, additionally sends chat_template_kwargs
	 * with source_lang_code / target_lang_code (useful for models like TranslateGemma
	 * that use Jinja templates with language parameters).
	 *
	 * Returns null when the request fails so the caller can fall back to the
	 * standard WP AI Client path.
	 *
	 * @param string $text       Text to translate.
	 * @param string $prompt     System instruction prompt.
	 * @param string $model_slug        Model slug.
	 * @param string $api_url           Base URL of the API server.
	 * @param bool   $kwargs_supported  Whether chat_template_kwargs should be attached.
	 * @return string|\WP_Error|null Translated text, WP_Error on failure, or null to signal fallback.
	 */
	private static function translate_chunk_direct_api( string $text, string $prompt, string $model_slug, string $api_url, bool $kwargs_supported ) {
		$endpoint = trailingslashit( $api_url ) . 'v1/chat/completions';

		$body = array(
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $prompt,
				),
				array(
					'role'    => 'user',
					'content' => $text,
				),
			),
			'temperature' => 0,
		);

		if ( '' !== $model_slug ) {
			$body['model'] = $model_slug;
		}

		if ( $kwargs_supported && self::$translation_source_lang && self::$translation_target_lang ) {
			$body['chat_template_kwargs'] = array(
				'source_lang_code' => self::$translation_source_lang,
				'target_lang_code' => self::$translation_target_lang,
			);
		}

		$response = wp_remote_post( $endpoint, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 120,
		) );

		if ( is_wp_error( $response ) ) {
			return null; // Fall back to standard path.
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null; // Fall back to standard path.
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$content = $data['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $content ) ) {
			return null;
		}

		return $content;
	}

	private static function direct_api_kwargs_supported(): bool {
		return get_option( 'ai_translate_direct_api_kwargs_detected', '0' ) === '1';
	}

	private static function refresh_direct_api_kwargs_detection( string $api_url, string $model_slug ): bool {
		$probe_result = ConfigurationService::probe_direct_api_kwargs( $api_url, $model_slug );
		update_option( 'ai_translate_direct_api_kwargs_detected', $probe_result ? '1' : '0' );
		update_option( 'ai_translate_direct_api_kwargs_last_probed_at', time(), false );

		return $probe_result;
	}

	private static function model_requires_strict_direct_api( string $model_slug ): bool {
		return '' !== $model_slug && false !== strpos( strtolower( $model_slug ), 'translategemma' );
	}

	private static function get_translategemma_runtime_status(): array {
		$runtime_context = self::get_translation_runtime_context();
		$model_slug      = self::$model_slug_request_override ?? $runtime_context['model_slug'];

		if ( ! self::model_requires_strict_direct_api( $model_slug ) ) {
			return array(
				'ready'  => true,
				'status' => 'not-selected',
			);
		}

		if ( '' === $runtime_context['direct_api_url'] ) {
			return array(
				'ready'  => false,
				'status' => 'direct-api-required',
			);
		}

		if ( ! self::direct_api_kwargs_supported() ) {
			return array(
				'ready'  => false,
				'status' => 'kwargs-required',
			);
		}

		return array(
			'ready'  => true,
			'status' => 'ready',
		);
	}

	private static function record_translation_transport_diagnostics( array $diagnostics ): void {
		self::$last_translation_transport_diagnostics = $diagnostics;
	}

	private static function record_validation_failure_diagnostics( \WP_Error $error ): void {
		$diagnostics = is_array( self::$last_translation_transport_diagnostics )
			? self::$last_translation_transport_diagnostics
			: array(
				'transport'        => 'unknown',
				'model_slug'       => '',
				'direct_api_url'   => '',
				'kwargs_supported' => false,
				'fallback_allowed' => true,
			);

		$diagnostics['failure_reason'] = $error->get_error_code();
		self::record_translation_transport_diagnostics( $diagnostics );
	}

	private static function should_retry_after_validation_failure( string $model_slug ): bool {
		return ! self::model_requires_strict_direct_api( $model_slug );
	}

	private static function build_retry_prompt( string $prompt ): string {
		return $prompt . "\n\nCRITICAL: Return only the translated content. Preserve HTML tags, Gutenberg block comments, URLs, and code fences exactly. Do not add explanations, bullet lists, markdown headings, or commentary.";
	}

	private static function validate_translated_output( string $source_text, string $translated_text ) {
		$source_text     = (string) $source_text;
		$translated_text = (string) $translated_text;

		if ( '' === trim( $translated_text ) ) {
			return new \WP_Error(
				'invalid_translation_empty',
				__( 'The model returned an empty translation result.', 'slytranslate' )
			);
		}

		$source_plain     = self::normalize_text_for_validation( $source_text );
		$translated_plain = self::normalize_text_for_validation( $translated_text );

		if ( '' === $translated_plain ) {
			return new \WP_Error(
				'invalid_translation_plain_text_missing',
				__( 'The translated output did not contain usable text.', 'slytranslate' )
			);
		}

		if ( self::looks_like_assistant_response( $source_text, $translated_text ) ) {
			return new \WP_Error(
				'invalid_translation_assistant_reply',
				__( 'The model returned explanatory assistant text instead of a clean translation.', 'slytranslate' )
			);
		}

		if ( self::has_excessive_short_text_growth( $source_plain, $translated_plain ) ) {
			return new \WP_Error(
				'invalid_translation_length_drift',
				__( 'The translated output is implausibly long for the source text and looks like a generated explanation rather than a translation.', 'slytranslate' )
			);
		}

		if ( self::has_structural_translation_drift( $source_text, $translated_text ) ) {
			return new \WP_Error(
				'invalid_translation_structure_drift',
				__( 'The translated output lost required structure such as HTML, Gutenberg block comments, URLs, or code fences.', 'slytranslate' )
			);
		}

		return null;
	}

	private static function normalize_text_for_validation( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return is_string( $text ) ? trim( $text ) : '';
	}

	private static function looks_like_assistant_response( string $source_text, string $translated_text ): bool {
		if ( self::looks_like_markdown_assistant_response( $source_text, $translated_text ) ) {
			return true;
		}

		$translated_plain = self::normalize_text_for_validation( $translated_text );
		if ( '' === $translated_plain ) {
			return false;
		}

		$source_plain = self::normalize_text_for_validation( $source_text );
		if ( self::starts_with_assistant_preamble( $translated_plain ) && ! self::starts_with_assistant_preamble( $source_plain ) ) {
			$translated_line_breaks = preg_match_all( '/\n/u', $translated_text );
			if ( $translated_line_breaks >= 2 || self::contains_review_markers( $translated_text ) ) {
				return true;
			}
		}

		return false;
	}

	private static function looks_like_markdown_assistant_response( string $source_text, string $translated_text ): bool {
		if ( self::contains_markdown_structure( $source_text ) ) {
			return false;
		}

		if ( ! self::contains_markdown_structure( $translated_text ) ) {
			return false;
		}

		return self::contains_review_markers( $translated_text ) || self::starts_with_assistant_preamble( self::normalize_text_for_validation( $translated_text ) );
	}

	private static function contains_markdown_structure( string $text ): bool {
		return 1 === preg_match( '/(^|\n)\s{0,3}(?:[-*+]\s+|\d+\.\s+|#{1,6}\s+)|\*\*[^*\n]+\*\*/u', $text );
	}

	private static function contains_review_markers( string $text ): bool {
		return 1 === preg_match( '/strengths\s*:|suggestions(?:\s+for\s+improvement)?\s*:|overall\s*:|key takeaways\s*:|breakdown|great start|vorschl[aä]ge\s*:|st[aä]rken\s*:|zusammenfassung\s*:|wichtige erkenntnisse\s*:/iu', $text );
	}

	private static function starts_with_assistant_preamble( string $text ): bool {
		return 1 === preg_match( '/^(?:okay|ok|sure|certainly|absolutely|of course|here(?: is|\'s)|let(?:\'|’)s|this is|this guide|for example|in short|overall|great|hier ist|klar|nat[üu]rlich|gerne|lassen(?:\s+sie)?\s+uns|insgesamt|zum beispiel)\b/iu', $text );
	}

	private static function has_excessive_short_text_growth( string $source_plain, string $translated_plain ): bool {
		$source_length = self::text_length( $source_plain );
		if ( $source_length < 1 || $source_length > 220 ) {
			return false;
		}

		$translated_length = self::text_length( $translated_plain );
		if ( $translated_length <= max( 260, $source_length * self::MAX_SHORT_TEXT_RESPONSE_RATIO ) ) {
			return false;
		}

		if ( preg_match( '/\n/u', $translated_plain ) ) {
			return true;
		}

		return self::contains_markdown_structure( $translated_plain ) || self::contains_review_markers( $translated_plain );
	}

	private static function has_structural_translation_drift( string $source_text, string $translated_text ): bool {
		$source_block_comment_count     = self::count_pattern_matches( '/<!--\s*\/?wp:[^>]+-->/iu', $source_text );
		$translated_block_comment_count = self::count_pattern_matches( '/<!--\s*\/?wp:[^>]+-->/iu', $translated_text );
		if ( $source_block_comment_count > 0 && $source_block_comment_count !== $translated_block_comment_count ) {
			return true;
		}

		$source_url_count     = self::count_pattern_matches( '/https?:\/\/[^\s"\'<>]+/iu', $source_text );
		$translated_url_count = self::count_pattern_matches( '/https?:\/\/[^\s"\'<>]+/iu', $translated_text );
		if ( $source_url_count > 0 && $translated_url_count < $source_url_count ) {
			return true;
		}

		$source_code_fence_count     = substr_count( $source_text, '```' );
		$translated_code_fence_count = substr_count( $translated_text, '```' );
		if ( $source_code_fence_count !== $translated_code_fence_count ) {
			return true;
		}

		$source_html_tag_count     = self::count_pattern_matches( '/<\/?[a-z][^>]*>/iu', $source_text );
		$translated_html_tag_count = self::count_pattern_matches( '/<\/?[a-z][^>]*>/iu', $translated_text );
		if ( $source_html_tag_count >= 2 && $translated_html_tag_count < (int) ceil( $source_html_tag_count * 0.6 ) ) {
			return true;
		}

		return false;
	}

	private static function count_pattern_matches( string $pattern, string $text ): int {
		$count = preg_match_all( $pattern, $text, $matches );

		return false === $count ? 0 : $count;
	}

	/**
	 * Probe whether a server supports chat_template_kwargs for translation.
	 *
	 * Sends "cat" with chat_template_kwargs {source_lang_code: "en", target_lang_code: "de"}
	 * and NO system instruction. If the response contains "Katze" (the German translation),
	 * the server correctly processes kwargs. If it returns a chatty response instead,
	 * kwargs are being silently ignored.
	 *
	 * @param string $api_url    Base URL of the API server.
	 * @param string $model_slug Model slug (may be empty).
	 * @return bool True if chat_template_kwargs are supported.
	 */
	private static function get_translation_chunk_char_limit(): int {
		$runtime_context     = self::get_translation_runtime_context();
		$context_window_size = self::get_effective_context_window_tokens();
		$chunk_char_limit    = self::get_translation_chunk_char_limit_from_context_window( $context_window_size );

		$filtered_limit = apply_filters( 'ai_translate_chunk_char_limit', $chunk_char_limit, $context_window_size, $runtime_context );
		$filtered_limit = absint( $filtered_limit );
		if ( $filtered_limit > 0 ) {
			$chunk_char_limit = $filtered_limit;
		}

		return max( self::MIN_TRANSLATION_CHARS, min( self::MAX_TRANSLATION_CHARS, $chunk_char_limit ) );
	}

	private static function get_translation_chunk_char_limit_from_context_window( int $context_window_tokens ): int {
		$context_window_tokens = max( self::MIN_CONTEXT_WINDOW_TOKENS, $context_window_tokens );
		$chunk_char_limit      = (int) floor( $context_window_tokens * self::SAFE_CHARS_PER_CONTEXT_TOKEN );

		return max( self::MIN_TRANSLATION_CHARS, min( self::MAX_TRANSLATION_CHARS, $chunk_char_limit ) );
	}

	private static function get_effective_context_window_tokens(): int {
		$configured_context_window_tokens = absint( get_option( 'ai_translate_context_window_tokens', 0 ) );
		if ( $configured_context_window_tokens > 0 ) {
			return max( self::MIN_CONTEXT_WINDOW_TOKENS, $configured_context_window_tokens );
		}

		$runtime_context      = self::get_translation_runtime_context();
		$context_window_size  = self::get_learned_context_window_tokens();

		if ( $context_window_size < 1 ) {
			$context_window_size = self::get_known_context_window_for_model( $runtime_context['model_slug'] );
		}

		if ( $context_window_size < 1 ) {
			$context_window_size = self::DEFAULT_CONTEXT_WINDOW_TOKENS;
		}

		$filtered_context_window_size = apply_filters( 'ai_translate_context_window_tokens', $context_window_size, $runtime_context );
		$filtered_context_window_size = absint( $filtered_context_window_size );
		if ( $filtered_context_window_size > 0 ) {
			$context_window_size = $filtered_context_window_size;
		}

		return max( self::MIN_CONTEXT_WINDOW_TOKENS, $context_window_size );
	}

	private static function get_translation_runtime_context(): array {
		if ( is_array( self::$translation_runtime_context ) ) {
			return self::$translation_runtime_context;
		}

		$runtime_context = array(
			'service_slug'   => '',
			'model_slug'     => get_option( 'ai_translate_model_slug', '' ),
			'direct_api_url' => get_option( 'ai_translate_direct_api_url', '' ),
		);

		self::$translation_runtime_context = $runtime_context;

		return $runtime_context;
	}

	private static function get_translation_runtime_context_cache_key(): string {
		$runtime_context = self::get_translation_runtime_context();

		if ( '' === $runtime_context['model_slug'] ) {
			return '';
		}

		return $runtime_context['model_slug'];
	}

	private static function get_learned_context_window_tokens(): int {
		$cache_key = self::get_translation_runtime_context_cache_key();
		if ( '' === $cache_key ) {
			return 0;
		}

		$learned_context_windows = get_option( 'ai_translate_learned_context_windows', array() );
		if ( ! is_array( $learned_context_windows ) || ! isset( $learned_context_windows[ $cache_key ] ) ) {
			return 0;
		}

		return absint( $learned_context_windows[ $cache_key ] );
	}

	private static function remember_context_window_tokens( int $context_window_tokens ): void {
		$cache_key = self::get_translation_runtime_context_cache_key();
		if ( '' === $cache_key ) {
			return;
		}

		$context_window_tokens = absint( $context_window_tokens );
		if ( $context_window_tokens < self::MIN_CONTEXT_WINDOW_TOKENS ) {
			return;
		}

		$learned_context_windows = get_option( 'ai_translate_learned_context_windows', array() );
		if ( ! is_array( $learned_context_windows ) ) {
			$learned_context_windows = array();
		}

		$learned_context_windows[ $cache_key ] = $context_window_tokens;
		update_option( 'ai_translate_learned_context_windows', $learned_context_windows, false );
	}

	private static function get_known_context_window_for_model( string $model_slug ): int {
		if ( '' === $model_slug ) {
			return 0;
		}

		$model_slug = strtolower( $model_slug );
		foreach ( self::KNOWN_MODEL_CONTEXT_WINDOWS as $needle => $context_window_tokens ) {
			if ( false !== strpos( $model_slug, $needle ) ) {
				return $context_window_tokens;
			}
		}

		return 0;
	}

	private static function maybe_adjust_chunk_limit_from_error( \WP_Error $error, int $chunk_char_limit ): int {
		$context_window_tokens = self::extract_context_window_tokens_from_error( $error );
		if ( $context_window_tokens < 1 ) {
			return 0;
		}

		self::remember_context_window_tokens( $context_window_tokens );

		$adjusted_chunk_char_limit = self::get_translation_chunk_char_limit_from_context_window( $context_window_tokens );
		if ( $adjusted_chunk_char_limit >= $chunk_char_limit ) {
			$adjusted_chunk_char_limit = (int) floor( $chunk_char_limit / 2 );
		}

		$adjusted_chunk_char_limit = max( self::MIN_TRANSLATION_CHARS, min( $chunk_char_limit - 1, $adjusted_chunk_char_limit ) );

		if ( $adjusted_chunk_char_limit >= $chunk_char_limit ) {
			return 0;
		}

		return $adjusted_chunk_char_limit;
	}

	private static function extract_context_window_tokens_from_error( \WP_Error $error ): int {
		foreach ( $error->get_error_messages() as $message ) {
			if ( preg_match( '/context size \((\d+) tokens\)|maximum context length is (\d+) tokens|context window(?: of)? (\d+) tokens/i', $message, $matches ) ) {
				foreach ( array_slice( $matches, 1 ) as $match ) {
					$context_window_tokens = absint( $match );
					if ( $context_window_tokens > 0 ) {
						return $context_window_tokens;
					}
				}
			}
		}

		return 0;
	}

	private static function split_text_for_translation( string $text, int $max_chars ): array {
		$max_chars = max( self::MIN_TRANSLATION_CHARS, $max_chars );

		if ( self::text_length( $text ) <= $max_chars ) {
			return array( $text );
		}

		$segments = preg_split(
			'/(\r?\n\s*\r?\n+|<!--\s*\/?wp:[^>]+-->\s*|<\/(?:p|div|section|article|aside|blockquote|pre|ul|ol|li|h[1-6]|table|thead|tbody|tr|td|th|figure|figcaption|details|summary)>\s*)/iu',
			$text,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( false === $segments || empty( $segments ) ) {
			return self::split_segment_for_translation( $text, $max_chars );
		}

		$chunks  = array();
		$current = '';

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			$segment_chunks = self::split_segment_for_translation( $segment, $max_chars );
			foreach ( $segment_chunks as $segment_chunk ) {
				if ( '' === $current ) {
					$current = $segment_chunk;
					continue;
				}

				if ( self::text_length( $current ) + self::text_length( $segment_chunk ) <= $max_chars ) {
					$current .= $segment_chunk;
					continue;
				}

				$chunks[] = $current;
				$current  = $segment_chunk;
			}
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	private static function split_segment_for_translation( string $segment, int $max_chars ): array {
		$max_chars = max( self::MIN_TRANSLATION_CHARS, $max_chars );

		if ( self::text_length( $segment ) <= $max_chars ) {
			return array( $segment );
		}

		$parts = preg_split( '/(\s+)/u', $segment, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts || empty( $parts ) ) {
			return self::hard_split_text( $segment, $max_chars );
		}

		$chunks  = array();
		$current = '';

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			if ( self::text_length( $part ) > $max_chars ) {
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}

				$chunks = array_merge( $chunks, self::hard_split_text( $part, $max_chars ) );
				continue;
			}

			if ( '' === $current ) {
				$current = $part;
				continue;
			}

			if ( self::text_length( $current ) + self::text_length( $part ) <= $max_chars ) {
				$current .= $part;
				continue;
			}

			$chunks[] = $current;
			$current  = $part;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	private static function hard_split_text( string $text, int $max_chars ): array {
		$max_chars = max( self::MIN_TRANSLATION_CHARS, $max_chars );
		$chunks = array();
		$length = self::text_length( $text );

		for ( $offset = 0; $offset < $length; $offset += $max_chars ) {
			$chunks[] = self::text_substr( $text, $offset, $max_chars );
		}

		return $chunks;
	}

	private static function translate_post_content( string $content, string $to, string $from = 'en', string $additional_prompt = '' ) {
		if ( '' === trim( $content ) ) {
			return '';
		}

		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return self::translate( $content, $to, $from, $additional_prompt );
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || empty( $blocks ) ) {
			return self::translate( $content, $to, $from, $additional_prompt );
		}

		return self::translate_block_sections( $blocks, $to, $from, $additional_prompt );
	}

	private static function translate_block_sections( array $blocks, string $to, string $from = 'en', string $additional_prompt = '' ) {
		$translated_sections = array();
		$pending_blocks      = array();

		foreach ( $blocks as $block ) {
			if ( self::is_translation_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			if ( self::should_skip_block_translation( $block ) ) {
				$translated_section = self::translate_serialized_blocks( $pending_blocks, $to, $from, $additional_prompt );
				if ( is_wp_error( $translated_section ) ) {
					return $translated_section;
				}

				if ( '' !== $translated_section ) {
					$translated_sections[] = $translated_section;
				}

				$pending_blocks         = array();
				$translated_sections[] = serialize_blocks( array( $block ) );
				continue;
			}

			$pending_blocks[] = $block;
		}

		$translated_section = self::translate_serialized_blocks( $pending_blocks, $to, $from, $additional_prompt );
		if ( is_wp_error( $translated_section ) ) {
			return $translated_section;
		}

		if ( '' !== $translated_section ) {
			$translated_sections[] = $translated_section;
		}

		return implode( '', $translated_sections );
	}

	private static function translate_serialized_blocks( array $blocks, string $to, string $from = 'en', string $additional_prompt = '' ) {
		if ( empty( $blocks ) ) {
			return '';
		}

		$serialized_blocks = serialize_blocks( $blocks );
		if ( '' === trim( $serialized_blocks ) ) {
			return $serialized_blocks;
		}

		return self::translate( $serialized_blocks, $to, $from, $additional_prompt );
	}

	private static function should_skip_block_translation( array $block ): bool {
		$block_name = $block['blockName'] ?? '';
		if ( ! is_string( $block_name ) ) {
			$block_name = '';
		}

		$skip_block_names = array(
			'core/code',
			'core/preformatted',
			'core/html',
			'core/shortcode',
			'kevinbatdorf/code-block-pro',
		);

		if ( in_array( $block_name, $skip_block_names, true ) ) {
			return true;
		}

		$attrs = $block['attrs'] ?? array();
		if ( ! is_array( $attrs ) ) {
			return false;
		}

		return isset( $attrs['code'] ) || isset( $attrs['codeHTML'] );
	}

	private static function should_translate_block_fragment( string $fragment ): bool {
		if ( '' === trim( $fragment ) ) {
			return false;
		}

		$text_content = wp_strip_all_tags( $fragment );
		$text_content = html_entity_decode( $text_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text_content = preg_replace( '/\s+/u', ' ', $text_content );

		return is_string( $text_content ) && '' !== trim( $text_content );
	}

	private static function text_length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		return strlen( $text );
	}

	private static function text_substr( string $text, int $offset, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, $offset, $length, 'UTF-8' );
		}

		return substr( $text, $offset, $length );
	}

	private static function build_translation_status_entry( string $language_code, int $translated_post_id ): array {
		$translated_post = $translated_post_id > 0 ? get_post( $translated_post_id ) : null;

		return array(
			'lang'        => $language_code,
			'post_id'     => $translated_post ? $translated_post->ID : 0,
			'exists'      => (bool) $translated_post,
			'title'       => $translated_post ? $translated_post->post_title : '',
			'post_status' => $translated_post ? $translated_post->post_status : '',
			'edit_link'   => $translated_post ? (string) get_edit_post_link( $translated_post->ID, 'raw' ) : '',
		);
	}

	private static function build_source_post_summary( \WP_Post $post, string $source_language = '' ): array {
		return array(
			'post_id'         => $post->ID,
			'title'           => $post->post_title,
			'post_type'       => $post->post_type,
			'post_status'     => $post->post_status,
			'source_language' => $source_language,
			'edit_link'       => (string) get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	private static function get_existing_translation_id( int $post_id, string $target_language, ?TranslationPluginAdapter $adapter = null ): int {
		if ( '' === $target_language ) {
			return 0;
		}

		$adapter = $adapter ?: self::get_adapter();
		if ( ! $adapter ) {
			return 0;
		}

		$translations       = $adapter->get_post_translations( $post_id );
		$translated_post_id = isset( $translations[ $target_language ] ) ? absint( $translations[ $target_language ] ) : 0;

		if ( $translated_post_id < 1 || false === get_post_status( $translated_post_id ) ) {
			return 0;
		}

		return $translated_post_id;
	}

	private static function resolve_bulk_source_post_ids( array $input ) {
		if ( ! empty( $input['post_ids'] ) && is_array( $input['post_ids'] ) ) {
			$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $input['post_ids'] ) ) ) );
			if ( ! empty( $post_ids ) ) {
				return array_slice( $post_ids, 0, 50 );
			}
		}

		$post_type = sanitize_key( $input['post_type'] ?? '' );
		if ( '' === $post_type ) {
			return new \WP_Error( 'missing_post_selection', __( 'Provide either post_ids or a post_type to translate in bulk.', 'slytranslate' ) );
		}

		$post_type_check = self::validate_translatable_post_type( $post_type );
		if ( is_wp_error( $post_type_check ) ) {
			return $post_type_check;
		}

		return self::query_post_ids_by_type( $post_type, self::normalize_bulk_limit( $input['limit'] ?? 20 ) );
	}

	private static function query_post_ids_by_type( string $post_type, int $limit ): array {
		$post_ids = get_posts( array(
			'post_type'              => $post_type,
			'post_status'            => self::get_queryable_source_post_statuses(),
			'posts_per_page'         => max( 1, $limit ),
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'lang'                   => '',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		if ( ! is_array( $post_ids ) ) {
			return array();
		}

		return array_values( array_map( 'absint', $post_ids ) );
	}

	private static function get_queryable_source_post_statuses(): array {
		$post_statuses = apply_filters( 'ai_translate_source_post_statuses', array( 'publish', 'draft', 'future', 'pending', 'private' ) );

		if ( ! is_array( $post_statuses ) ) {
			$post_statuses = array( 'publish', 'draft', 'future', 'pending', 'private' );
		}

		$post_statuses = array_values(
			array_filter(
				array_unique( array_map( 'sanitize_key', $post_statuses ) ),
				static function ( $post_status ): bool {
					return self::is_registered_post_status( $post_status );
				}
			)
		);

		return ! empty( $post_statuses ) ? $post_statuses : array( 'publish' );
	}

	private static function is_registered_post_status( $post_status ): bool {
		if ( ! is_string( $post_status ) ) {
			return false;
		}

		$post_status = sanitize_key( $post_status );
		if ( '' === $post_status ) {
			return false;
		}

		if ( function_exists( 'post_status_exists' ) ) {
			return \post_status_exists( $post_status );
		}

		return null !== get_post_status_object( $post_status );
	}

	private static function normalize_bulk_limit( $limit ): int {
		$limit = absint( $limit );

		if ( $limit < 1 ) {
			$limit = 20;
		}

		return min( 50, $limit );
	}

	private static function validate_translatable_post_type( string $post_type ) {
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'invalid_post_type', __( 'The requested post type does not exist.', 'slytranslate' ) );
		}

		if ( function_exists( 'pll_is_translated_post_type' ) && ! pll_is_translated_post_type( $post_type ) ) {
			return new \WP_Error(
				'post_type_not_translatable',
				sprintf(
					/* translators: %s: post type slug. */
					__( 'The post type "%s" is not enabled for translation in Polylang.', 'slytranslate' ),
					$post_type
				)
			);
		}

		return true;
	}

	private static function normalize_translation_post_status( $requested_status, \WP_Post $post ): string {
		if ( is_string( $requested_status ) ) {
			$requested_status = sanitize_key( $requested_status );
			if ( '' !== $requested_status && self::is_registered_post_status( $requested_status ) && ! in_array( $requested_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
				return $requested_status;
			}
		}

		$source_status = get_post_status( $post );
		if ( is_string( $source_status ) && self::is_registered_post_status( $source_status ) && ! in_array( $source_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return $source_status;
		}

		return 'draft';
	}

	private static function get_active_seo_plugin_config(): array {
		if ( is_array( self::$seo_plugin_config ) ) {
			return self::$seo_plugin_config;
		}

		$seo_plugin_config = SeoPluginDetector::get_active_plugin_config();
		$seo_plugin_key    = $seo_plugin_config['key'];

		$seo_plugin_config['translate'] = apply_filters( 'ai_translate_seo_meta_translate', $seo_plugin_config['translate'], $seo_plugin_key, $seo_plugin_config );
		$seo_plugin_config['clear']     = apply_filters( 'ai_translate_seo_meta_clear', $seo_plugin_config['clear'], $seo_plugin_key, $seo_plugin_config );

		$seo_plugin_config['translate'] = SeoPluginDetector::normalize_meta_keys( $seo_plugin_config['translate'] );
		$seo_plugin_config['clear']     = SeoPluginDetector::normalize_meta_keys( $seo_plugin_config['clear'] );

		self::$seo_plugin_config = $seo_plugin_config;

		return self::$seo_plugin_config;
	}

	private static function translate_meta_value( $value, string $to, string $from = 'en', string $additional_prompt = '' ) {
		if ( is_string( $value ) ) {
			if ( '' === trim( $value ) ) {
				return $value;
			}

			return self::translate( $value, $to, $from, $additional_prompt );
		}

		if ( is_array( $value ) ) {
			$translated_value = array();
			foreach ( $value as $key => $item ) {
				$translated_item = self::translate_meta_value( $item, $to, $from, $additional_prompt );
				if ( is_wp_error( $translated_item ) ) {
					return $translated_item;
				}

				$translated_value[ $key ] = $translated_item;
			}

			return $translated_value;
		}

		return $value;
	}

	private static function translate_meta_value_for_key( string $meta_key, $value, string $to, string $from = 'en', string $additional_prompt = '' ) {
		if ( 'slim_seo' === $meta_key && is_array( $value ) ) {
			$translated_value = $value;

			foreach ( array( 'title', 'description' ) as $field ) {
				if ( ! array_key_exists( $field, $translated_value ) ) {
					continue;
				}

				$translated_field = self::translate_meta_value( $translated_value[ $field ], $to, $from, $additional_prompt );
				if ( is_wp_error( $translated_field ) ) {
					return $translated_field;
				}

				$translated_value[ $field ] = $translated_field;
			}

			return $translated_value;
		}

		return self::translate_meta_value( $value, $to, $from, $additional_prompt );
	}

	/**
	 * Translate an entire post and create/update the translation via the adapter.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $to        Target language code.
	 * @param string $status    Post status for the translated post.
	 * @param bool   $overwrite Whether to overwrite an existing translation.
	 * @param bool   $translate_title Whether to translate the post title.
	 * @return int|\WP_Error Translated post ID or WP_Error on failure.
	 */
	public static function translate_post( $post_id, $to, $status = '', $overwrite = false, $translate_title = true, $additional_prompt = '' ) {
		$additional_prompt = is_string( $additional_prompt ) ? sanitize_textarea_field( $additional_prompt ) : '';

		$adapter = self::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
		}

		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Source post not found.', 'slytranslate' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden_post', __( 'You are not allowed to translate this content item.', 'slytranslate' ) );
		}
		$post_type_check = self::validate_translatable_post_type( $post->post_type );
		if ( is_wp_error( $post_type_check ) ) {
			return $post_type_check;
		}

		$from = $adapter->get_post_language( $post_id ) ?? 'en';
		$to   = sanitize_key( $to );

		if ( '' !== $from && $from === $to ) {
			return new \WP_Error( 'same_language', __( 'Source and target languages must be different.', 'slytranslate' ) );
		}

		$existing_translation = self::get_existing_translation_id( $post_id, $to, $adapter );
		if ( $existing_translation && get_post_status( $existing_translation ) !== false && ! $overwrite ) {
			return new \WP_Error(
				'translation_exists',
				sprintf(
					/* translators: 1: language code, 2: post ID. */
					__( 'A translation for language "%1$s" already exists (post %2$d).', 'slytranslate' ),
					$to,
					$existing_translation
				)
			);
		}
		if ( $existing_translation && ! current_user_can( 'edit_post', $existing_translation ) ) {
			return new \WP_Error( 'forbidden_translation', __( 'You are not allowed to update the existing translation.', 'slytranslate' ) );
		}

		$target_status = self::normalize_translation_post_status( $status, $post );
		$translate_title = ! isset( $translate_title ) || (bool) $translate_title;
		self::initialize_translation_progress_context( $translate_title, $post->post_content );

		try {
			// Translate content.
			if ( $translate_title ) {
				self::mark_translation_phase( 'title' );
				$title = self::translate( $post->post_title, $to, $from, $additional_prompt );
				if ( is_wp_error( $title ) ) {
					return $title;
				}

				self::advance_translation_progress_steps();
			} else {
				$title = $post->post_title;
			}

			if ( self::is_translation_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			if ( self::has_content_translation_progress() ) {
				self::mark_translation_phase( 'content' );
			}

			$content = self::translate_post_content( $post->post_content, $to, $from, $additional_prompt );
			if ( is_wp_error( $content ) ) {
				return $content;
			}

			if ( self::is_translation_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			self::mark_translation_phase( 'excerpt' );
			$excerpt = self::translate( $post->post_excerpt, $to, $from, $additional_prompt );
			if ( is_wp_error( $excerpt ) ) {
				return $excerpt;
			}

			self::advance_translation_progress_steps();
			self::mark_translation_phase( 'meta' );
			$processed_meta = self::prepare_translation_meta( $post_id, $to, $from, $additional_prompt );
			if ( is_wp_error( $processed_meta ) ) {
				return $processed_meta;
			}

			self::advance_translation_progress_steps();
			self::mark_translation_phase( 'saving' );

			// Create or update via adapter.
			$result = $adapter->create_translation( $post_id, $to, array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_status'  => $target_status,
				'meta'         => $processed_meta,
				'overwrite'    => $overwrite,
			) );

			if ( ! is_wp_error( $result ) ) {
				self::advance_translation_progress_steps();
				self::set_translation_progress( 'done' );
			}

			return $result;
		} finally {
			self::clear_translation_progress_context();
		}
	}

	private static function prepare_translation_meta( int $post_id, string $to, string $from, string $additional_prompt ) {
		$meta           = get_post_meta( $post_id );
		$processed_meta = array();

		foreach ( $meta as $key => $values ) {
			if ( self::is_translation_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			if ( in_array( $key, self::INTERNAL_META_KEYS_TO_SKIP, true ) ) {
				continue;
			}

			$value = maybe_unserialize( $values[0] ?? '' );
			if ( in_array( $key, self::meta_clear(), true ) ) {
				$processed_meta[ $key ] = '';
			} elseif ( in_array( $key, self::meta_translate(), true ) ) {
				$translated_meta = self::translate_meta_value_for_key( $key, $value, $to, $from, $additional_prompt );
				$processed_meta[ $key ] = is_wp_error( $translated_meta ) ? $value : $translated_meta;
			} else {
				$processed_meta[ $key ] = $value;
			}
		}

		return $processed_meta;
	}

	/* ---------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	private static function meta_keys( $option ) {
		$value = get_option( $option );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return SeoPluginDetector::normalize_meta_keys( preg_split( '/\s+/', trim( $value ) ) );
		}
		return array();
	}

	private static function merge_meta_keys( array ...$meta_key_sets ): array {
		$merged = array();

		foreach ( $meta_key_sets as $meta_keys ) {
			$merged = array_merge( $merged, SeoPluginDetector::normalize_meta_keys( $meta_keys ) );
		}

		return SeoPluginDetector::normalize_meta_keys( $merged );
	}

	private static function meta_clear() {
		if ( null === self::$meta_clear ) {
			$seo_plugin_config = self::get_active_seo_plugin_config();
			self::$meta_clear  = self::merge_meta_keys( self::meta_keys( 'ai_translate_meta_clear' ), $seo_plugin_config['clear'] );
		}
		return self::$meta_clear;
	}

	private static function meta_translate() {
		if ( null === self::$meta_translate ) {
			$seo_plugin_config    = self::get_active_seo_plugin_config();
			self::$meta_translate = self::merge_meta_keys( self::meta_keys( 'ai_translate_meta_translate' ), $seo_plugin_config['translate'] );
		}
		return self::$meta_translate;
	}
}

AI_Translate::add_hooks();
