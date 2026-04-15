<?php
/*
Plugin Name: SlyTranslate - AI Translation Abilities
Plugin URI: https://wordpress.org/plugins/slytranslate/
Description: AI translation abilities for WordPress using WordPress 7 native AI Connectors as a core feature, plus the AI Client and Abilities API for text and content translation.
Version: 1.1.1
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

class AI_Translate {

	// Default prompt template.
	public static $PROMPT = 'Translate the content from {FROM_CODE} to {TO_CODE} preserving html, formatting and embedded media. Only return the new content.';

	private const VERSION              = '1.0.0';
	private const EDITOR_SCRIPT_HANDLE = 'ai-translate-editor';
	private const EDITOR_REST_NAMESPACE = 'ai-translate/v1';

	private const DEFAULT_CONTEXT_WINDOW_TOKENS = 8192;
	private const MIN_CONTEXT_WINDOW_TOKENS     = 2048;
	private const MIN_TRANSLATION_CHARS         = 1200;
	private const MAX_TRANSLATION_CHARS         = 8000;
	private const SAFE_CHARS_PER_CONTEXT_TOKEN  = 0.5;
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
		'mistral-large' => 32768,
		'mistral-small' => 32768,
		'sonar'         => 32768,
		'grok'          => 32768,
	);

	// Cached meta key lists.
	public static $meta_translate;
	public static $meta_clear;

	// Adapter instance.
	private static $adapter;
	private static $translation_runtime_context;
	private static $seo_plugin_config;

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
		add_action( 'wp_abilities_api_categories_init', array( static::class, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( static::class, 'register_abilities' ) );

		// Polylang auto-translate hooks (kept for backward compatibility).
		add_filter( 'default_title', array( static::class, 'default_title' ), 10, 2 );
		add_filter( 'default_content', array( static::class, 'default_content' ), 10, 2 );
		add_filter( 'default_excerpt', array( static::class, 'default_excerpt' ), 10, 2 );
		add_filter( 'pll_translate_post_meta', array( static::class, 'pll_translate_post_meta' ), 10, 3 );
	}

	public static function enqueue_editor_plugin() {
		wp_enqueue_script(
			self::EDITOR_SCRIPT_HANDLE,
			plugins_url( 'assets/editor-plugin.js', __FILE__ ),
			array( 'wp-api-fetch', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-plugins', 'wp-rich-text' ),
			self::get_editor_script_version(),
			true
		);

		wp_localize_script( self::EDITOR_SCRIPT_HANDLE, 'aiTranslateEditor', self::get_editor_bootstrap_data() );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				self::EDITOR_SCRIPT_HANDLE,
				'slytranslate',
				plugin_dir_path( __FILE__ ) . 'languages'
			);
		}
	}

	private static function get_editor_script_version(): string {
		$script_path  = __DIR__ . '/assets/editor-plugin.js';
		$script_mtime = file_exists( $script_path ) ? filemtime( $script_path ) : false;

		if ( false === $script_mtime ) {
			return self::VERSION;
		}

		return self::VERSION . '.' . (string) $script_mtime;
	}

	private static function get_editor_default_source_language(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		if ( ! is_string( $locale ) || '' === $locale ) {
			return 'en';
		}

		$locale          = strtolower( str_replace( '_', '-', $locale ) );
		$primary_subtag  = sanitize_key( strtok( $locale, '-' ) ?: '' );

		return '' !== $primary_subtag ? $primary_subtag : 'en';
	}

	private static function get_editor_bootstrap_data(): array {
		$user_id              = get_current_user_id();
		$last_additional_prompt = $user_id > 0
			? (string) get_user_meta( $user_id, '_ai_translate_last_additional_prompt', true )
			: '';

		return array(
			'abilitiesRunBasePath' => self::get_editor_rest_base_path(),
			'restNonce'            => wp_create_nonce( 'wp_rest' ),
			'translationPluginAvailable' => null !== self::get_adapter(),
			'defaultSourceLanguage' => self::get_editor_default_source_language(),
			'lastAdditionalPrompt' => $last_additional_prompt,
			'strings'              => array(
				'panelTitle'                => __( 'AI Translation with SlyTranslate', 'slytranslate' ),
				'sourceLanguageLabel'       => __( 'Source language', 'slytranslate' ),
				'targetLanguageLabel'       => __( 'Target language', 'slytranslate' ),
				'overwriteLabel'            => __( 'Overwrite existing translation', 'slytranslate' ),
				'translateTitleLabel'       => __( 'Translate title', 'slytranslate' ),
				'additionalPromptLabel'     => __( 'Additional instructions (optional)', 'slytranslate' ),
				'additionalPromptHelp'      => __( 'Supplements the site-wide translation instructions. Example: Use informal language.', 'slytranslate' ),
				'translateButton'           => __( 'Translate now', 'slytranslate' ),
				'cancelTranslationButton'   => __( 'Cancel translation', 'slytranslate' ),
				'refreshButton'             => __( 'Refresh translation status', 'slytranslate' ),
				'loadingLanguages'          => __( 'Loading available languages...', 'slytranslate' ),
				'loadingStatus'             => __( 'Loading translation status...', 'slytranslate' ),
				'noLanguages'               => __( 'No target languages are available for this content item.', 'slytranslate' ),
				'translationStatusLabel'    => __( 'Translation status', 'slytranslate' ),
				'translationExists'         => __( 'Available', 'slytranslate' ),
				'translationMissing'        => __( 'Not translated yet', 'slytranslate' ),
				'openTranslation'           => __( 'Open translation', 'slytranslate' ),
				'saveFirstNotice'           => __( 'Save the content before creating a translation.', 'slytranslate' ),
				'saveChangesNotice'         => __( 'Save your latest changes before translating so the translation uses the current content.', 'slytranslate' ),
				'translationCreatedNotice'  => __( 'Translation created successfully.', 'slytranslate' ),
				'translationUpdatedNotice'  => __( 'Translation updated successfully.', 'slytranslate' ),
				'existingTranslationNotice' => __( 'A translation already exists for the selected language. Enable overwrite to update it.', 'slytranslate' ),
				'translateSelectionButton'  => __( 'Translate (SlyTranslate)', 'slytranslate' ),
				'translateSelectionTitle'   => __( 'Translate selected text with SlyTranslate', 'slytranslate' ),
				'translateSelectionTextLabel' => __( 'Selected text', 'slytranslate' ),
				'translateSelectionMissingSelection' => __( 'Select text in a paragraph, heading, or another text field first.', 'slytranslate' ),
				'translateSelectionUnavailable' => __( 'No target languages are available for the selected text.', 'slytranslate' ),
				'cancelButton'              => __( 'Cancel', 'slytranslate' ),
				'unknownError'              => __( 'An unexpected error occurred.', 'slytranslate' ),
			),
		);
	}

	private static function get_editor_rest_base_path(): string {
		return '/' . self::EDITOR_REST_NAMESPACE . '/';
	}

	public static function register_editor_rest_routes() {
		self::register_editor_rest_route( '/ai-translate/get-languages', array( static::class, 'rest_execute_get_languages' ) );
		self::register_editor_rest_route( '/ai-translate/get-translation-status', array( static::class, 'rest_execute_get_translation_status' ) );
		self::register_editor_rest_route( '/ai-translate/translate-text', array( static::class, 'rest_execute_translate_text' ) );
		self::register_editor_rest_route( '/ai-translate/translate-content', array( static::class, 'rest_execute_translate_content' ) );
		self::register_editor_rest_route( '/ai-translate/translate-post', array( static::class, 'rest_execute_translate_content' ) );

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

	public static function rest_execute_translate_text( \WP_REST_Request $request ) {
		return self::execute_translate_text( self::get_editor_rest_input( $request ) );
	}

	public static function rest_execute_translate_content( \WP_REST_Request $request ) {
		return self::execute_translate_content( self::get_editor_rest_input( $request ) );
	}

	public static function rest_execute_save_user_preference( \WP_REST_Request $request ) {
		$input             = self::get_editor_rest_input( $request );
		$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] )
			? sanitize_textarea_field( $input['additional_prompt'] )
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
		$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] ) ? $input['additional_prompt'] : '';
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
		$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] ) ? $input['additional_prompt'] : '';
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
		if ( isset( $input['prompt_template'] ) ) {
			update_option( 'ai_translate_prompt', sanitize_textarea_field( $input['prompt_template'] ) );
		}
		if ( array_key_exists( 'prompt_addon', $input ) ) {
			$addon_value = is_string( $input['prompt_addon'] ) ? sanitize_textarea_field( $input['prompt_addon'] ) : '';
			if ( '' === $addon_value ) {
				delete_option( 'ai_translate_prompt_addon' );
			} else {
				update_option( 'ai_translate_prompt_addon', $addon_value );
			}
		}
		if ( isset( $input['meta_keys_translate'] ) ) {
			update_option( 'ai_translate_meta_translate', sanitize_textarea_field( $input['meta_keys_translate'] ) );
		}
		if ( isset( $input['meta_keys_clear'] ) ) {
			update_option( 'ai_translate_meta_clear', sanitize_textarea_field( $input['meta_keys_clear'] ) );
		}
		if ( isset( $input['auto_translate_new'] ) ) {
			update_option( 'ai_translate_new_post', $input['auto_translate_new'] ? '1' : '0' );
		}
		if ( isset( $input['context_window_tokens'] ) ) {
			$context_window_tokens = absint( $input['context_window_tokens'] );
			if ( $context_window_tokens > 0 ) {
				update_option( 'ai_translate_context_window_tokens', (string) $context_window_tokens );
			} else {
				delete_option( 'ai_translate_context_window_tokens' );
			}
		}
		if ( array_key_exists( 'model_slug', $input ) ) {
			$model_slug_value = is_string( $input['model_slug'] ) ? sanitize_text_field( $input['model_slug'] ) : '';
			if ( '' === $model_slug_value ) {
				delete_option( 'ai_translate_model_slug' );
			} else {
				update_option( 'ai_translate_model_slug', $model_slug_value );
			}
		}

		// Reset cached meta keys.
		self::$meta_translate = null;
		self::$meta_clear     = null;
		self::$seo_plugin_config = null;
		self::$translation_runtime_context = null;
		$runtime_context      = self::get_translation_runtime_context();
		$seo_plugin_config    = self::get_active_seo_plugin_config();

		return array(
			'prompt_template'    => get_option( 'ai_translate_prompt', self::$PROMPT ),
			'prompt_addon'       => get_option( 'ai_translate_prompt_addon', '' ),
			'meta_keys_translate' => get_option( 'ai_translate_meta_translate', '' ),
			'meta_keys_clear'    => get_option( 'ai_translate_meta_clear', '' ),
			'auto_translate_new' => get_option( 'ai_translate_new_post', '1' ) === '1',
			'context_window_tokens' => absint( get_option( 'ai_translate_context_window_tokens', 0 ) ),
			'model_slug' => get_option( 'ai_translate_model_slug', '' ),
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

		$prompt = self::prompt( $to, $from, $additional_prompt );
		return self::translate_with_chunk_limit( $text, $prompt, self::get_translation_chunk_char_limit() );
	}

	private static function translate_with_chunk_limit( string $text, string $prompt, int $chunk_char_limit, int $attempt = 0 ) {
		$chunks = self::split_text_for_translation( $text, $chunk_char_limit );

		if ( empty( $chunks ) ) {
			return '';
		}

		if ( 1 === count( $chunks ) ) {
			return self::translate_chunk( $chunks[0], $prompt );
		}

		$translated_chunks = array();
		foreach ( $chunks as $chunk ) {
			$translated_chunk = self::translate_chunk( $chunk, $prompt );
			if ( is_wp_error( $translated_chunk ) ) {
				$adjusted_chunk_char_limit = self::maybe_adjust_chunk_limit_from_error( $translated_chunk, $chunk_char_limit );
				if ( $adjusted_chunk_char_limit > 0 && $attempt < 2 ) {
					return self::translate_with_chunk_limit( $text, $prompt, $adjusted_chunk_char_limit, $attempt + 1 );
				}

				return $translated_chunk;
			}

			$translated_chunks[] = $translated_chunk;
		}

		return implode( '', $translated_chunks );
	}

	private static function translate_chunk( string $text, string $prompt ) {
		$runtime_context = self::get_translation_runtime_context();
		$builder         = wp_ai_client_prompt( $text )
			->using_system_instruction( $prompt )
			->using_temperature( 0 );

		if ( '' !== $runtime_context['model_slug'] && method_exists( $builder, 'using_model_preference' ) ) {
			$builder = $builder->using_model_preference( $runtime_context['model_slug'] );
		}

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

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
			'service_slug' => '',
			'model_slug'   => get_option( 'ai_translate_model_slug', '' ),
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

	private static function translate_parsed_blocks( array $blocks, string $to, string $from = 'en', string $additional_prompt = '' ) {
		$translated_blocks = array();

		foreach ( $blocks as $block ) {
			$translated_block = self::translate_parsed_block( $block, $to, $from, $additional_prompt );
			if ( is_wp_error( $translated_block ) ) {
				return $translated_block;
			}

			$translated_blocks[] = $translated_block;
		}

		return $translated_blocks;
	}

	private static function translate_parsed_block( array $block, string $to, string $from = 'en', string $additional_prompt = '' ) {
		if ( self::should_skip_block_translation( $block ) ) {
			return $block;
		}

		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
			$translated_inner_blocks = self::translate_parsed_blocks( $block['innerBlocks'], $to, $from, $additional_prompt );
			if ( is_wp_error( $translated_inner_blocks ) ) {
				return $translated_inner_blocks;
			}

			$block['innerBlocks'] = $translated_inner_blocks;
		}

		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $index => $fragment ) {
				if ( ! is_string( $fragment ) || ! self::should_translate_block_fragment( $fragment ) ) {
					continue;
				}

				$translated_fragment = self::translate( $fragment, $to, $from, $additional_prompt );
				if ( is_wp_error( $translated_fragment ) ) {
					return $translated_fragment;
				}

				$block['innerContent'][ $index ] = $translated_fragment;
			}
		}

		return $block;
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

		$seo_plugin_config['translate'] = self::normalize_meta_keys( $seo_plugin_config['translate'] );
		$seo_plugin_config['clear']     = self::normalize_meta_keys( $seo_plugin_config['clear'] );

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

		// Translate content.
		$title   = $translate_title ? self::translate( $post->post_title, $to, $from, $additional_prompt ) : $post->post_title;
		$content = self::translate_post_content( $post->post_content, $to, $from, $additional_prompt );
		$excerpt = self::translate( $post->post_excerpt, $to, $from, $additional_prompt );

		if ( is_wp_error( $title ) ) {
			return $title;
		}
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( is_wp_error( $excerpt ) ) {
			return $excerpt;
		}

		// Process meta fields.
		$meta           = get_post_meta( $post_id );
		$processed_meta = array();
		foreach ( $meta as $key => $values ) {
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

		// Create or update via adapter.
		$result = $adapter->create_translation( $post_id, $to, array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $target_status,
			'meta'         => $processed_meta,
			'overwrite'    => $overwrite,
		) );

		return $result;
	}

	/* ---------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	private static function meta_keys( $option ) {
		$value = get_option( $option );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return self::normalize_meta_keys( preg_split( '/\s+/', trim( $value ) ) );
		}
		return array();
	}

	private static function normalize_meta_keys( $meta_keys ): array {
		if ( ! is_array( $meta_keys ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $meta_keys as $meta_key ) {
			if ( ! is_string( $meta_key ) ) {
				continue;
			}

			$meta_key = trim( $meta_key );
			if ( '' === $meta_key ) {
				continue;
			}

			$normalized[] = $meta_key;
		}

		return array_values( array_unique( $normalized ) );
	}

	private static function merge_meta_keys( array ...$meta_key_sets ): array {
		$merged = array();

		foreach ( $meta_key_sets as $meta_keys ) {
			$merged = array_merge( $merged, self::normalize_meta_keys( $meta_keys ) );
		}

		return self::normalize_meta_keys( $merged );
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
