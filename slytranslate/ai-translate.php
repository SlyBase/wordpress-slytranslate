<?php
/*
Plugin Name: SlyTranslate - AI Translation Abilities
Plugin URI: https://wordpress.org/plugins/slytranslate/
Description: AI translation abilities for WordPress using WordPress 7 native AI Connectors as a core feature, plus the AI Client and Abilities API for text and content translation.
Version: 1.5.0
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
require_once __DIR__ . '/inc/TextSplitter.php';
require_once __DIR__ . '/inc/TranslationValidator.php';
require_once __DIR__ . '/inc/ConfigurationService.php';
require_once __DIR__ . '/inc/EditorBootstrap.php';
require_once __DIR__ . '/inc/TranslationProgressTracker.php';
require_once __DIR__ . '/inc/TimingLogger.php';
require_once __DIR__ . '/inc/DirectApiTranslationClient.php';
require_once __DIR__ . '/inc/TranslationRuntime.php';
require_once __DIR__ . '/inc/ContentTranslator.php';
require_once __DIR__ . '/inc/MetaTranslationService.php';
require_once __DIR__ . '/inc/TranslationQueryService.php';
require_once __DIR__ . '/inc/PostTranslationService.php';
require_once __DIR__ . '/inc/LegacyPolylangBridge.php';
require_once __DIR__ . '/inc/EditorRestController.php';
require_once __DIR__ . '/inc/AbilityRegistrar.php';
require_once __DIR__ . '/inc/ListTableTranslation.php';

class AI_Translate {

	// Default prompt template – referenced by TranslationRuntime::build_prompt().
	public static $PROMPT = 'Translate the content from {FROM_CODE} to {TO_CODE} preserving html, formatting and embedded media. Only return the new content.';

	private const VERSION               = '1.5.0';
	private const EDITOR_SCRIPT_HANDLE  = 'ai-translate-editor';
	private const EDITOR_REST_NAMESPACE = 'ai-translate/v1';

	// Adapter singleton.
	private static $adapter;

	/* ---------------------------------------------------------------
	 * Adapter
	 * ------------------------------------------------------------- */

	public static function get_adapter(): ?TranslationPluginAdapter {
		if ( null === self::$adapter ) {
			$polylang = new PolylangAdapter();
			if ( $polylang->is_available() ) {
				self::$adapter = $polylang;
			}
		}
		return self::$adapter;
	}

	public static function current_user_can_access_translation_abilities(): bool {
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

	public static function add_hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( static::class, 'enqueue_editor_plugin' ) );
		add_action( 'rest_api_init', array( static::class, 'register_editor_rest_routes' ) );
		add_action( 'wp_abilities_api_categories_init', array( AbilityRegistrar::class, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( AbilityRegistrar::class, 'register_abilities' ) );
		add_filter( 'default_title',          array( static::class, 'default_title' ), 10, 2 );
		add_filter( 'default_content',        array( static::class, 'default_content' ), 10, 2 );
		add_filter( 'default_excerpt',        array( static::class, 'default_excerpt' ), 10, 2 );
		add_filter( 'pll_translate_post_meta', array( static::class, 'pll_translate_post_meta' ), 10, 3 );
		ListTableTranslation::add_hooks();
	}

	public static function enqueue_editor_plugin(): void {
		EditorBootstrap::enqueue_editor_plugin();
	}

	public static function register_editor_rest_routes(): void {
		EditorRestController::register_routes();
	}

	public static function rest_can_access_translation_abilities( \WP_REST_Request $request ): bool {
		return self::current_user_can_access_translation_abilities();
	}

	private static function get_editor_rest_input( \WP_REST_Request $request ): array {
		$input = $request->get_param( 'input' );
		return is_array( $input ) ? $input : array();
	}

	/* ---------------------------------------------------------------
	 * REST callbacks (must stay on AI_Translate – EditorRestRegistrationTest contract)
	 * ------------------------------------------------------------- */

	public static function rest_execute_get_languages( \WP_REST_Request $request ) {
		return self::execute_get_languages();
	}

	public static function rest_execute_get_available_models( \WP_REST_Request $request ) {
		$refresh = (bool) $request->get_param( 'refresh' );
		$models  = EditorBootstrap::get_available_models( $refresh );

		return array(
			'models'           => $models,
			'defaultModelSlug' => (string) get_option( 'ai_translate_model_slug', '' ),
			'refreshed'        => $refresh,
		);
	}

	public static function rest_execute_get_translation_status( \WP_REST_Request $request ) {
		return self::execute_get_translation_status( self::get_editor_rest_input( $request ) );
	}

	public static function rest_execute_get_translation_progress( \WP_REST_Request $request ) {
		$input   = self::get_editor_rest_input( $request );
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		return TranslationProgressTracker::get_progress( $post_id );
	}

	public static function rest_execute_translate_text( \WP_REST_Request $request ) {
		return self::execute_translate_text( self::get_editor_rest_input( $request ) );
	}

	public static function rest_execute_translate_blocks( \WP_REST_Request $request ) {
		return self::execute_translate_blocks( self::get_editor_rest_input( $request ) );
	}

	public static function rest_execute_translate_content( \WP_REST_Request $request ) {
		// Allow long-running translations to complete server-side even when the
		// browser navigates away or aborts the fetch (used by the
		// "Continue in background" flow in the post-list dialog).
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		TranslationProgressTracker::clear_cancelled();
		$input   = self::get_editor_rest_input( $request );
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		TranslationProgressTracker::clear_progress( $post_id );
		return self::execute_translate_content( $input );
	}

	public static function rest_cancel_translation( \WP_REST_Request $request ) {
		TranslationProgressTracker::set_cancelled();

		// Clear the per-post progress transient so the next translation start
		// does not briefly show the cancelled job's last percentage. The bg-bar
		// polls every 2s and would otherwise render the stale value until the
		// new job's first set_progress() call lands.
		$input   = self::get_editor_rest_input( $request );
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		TranslationProgressTracker::clear_progress( $post_id );

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
	 * Ability execute callbacks (must stay on AI_Translate – AbilityRegistrationTest contract)
	 * ------------------------------------------------------------- */

	public static function execute_get_languages() {
		return TranslationQueryService::execute_get_languages();
	}

	public static function execute_get_translation_status( $input ) {
		return TranslationQueryService::execute_get_translation_status( $input );
	}

	public static function execute_get_untranslated( $input ) {
		return TranslationQueryService::execute_get_untranslated( $input );
	}

	public static function execute_translate_text( $input ) {
		$input = is_array( $input ) ? $input : array();

		return TranslationRuntime::with_model_slug_override(
			$input,
			function () use ( $input ) {
				$text = self::require_non_empty_string_input( $input, 'text', 'missing_text', __( 'Text to translate is required.', 'slytranslate' ) );
				if ( is_wp_error( $text ) ) { return $text; }

				$source_language = self::require_language_code_input( $input, 'source_language', 'missing_source_language', __( 'Source language is required.', 'slytranslate' ) );
				if ( is_wp_error( $source_language ) ) { return $source_language; }

				$target_language = self::require_language_code_input( $input, 'target_language', 'missing_target_language', __( 'Target language is required.', 'slytranslate' ) );
				if ( is_wp_error( $target_language ) ) { return $target_language; }

				$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] ) ? mb_substr( sanitize_textarea_field( $input['additional_prompt'] ), 0, 2000 ) : '';
				$plain_text_hint  = 'The input is a short plain-text snippet. Translate it and return only the translated text. Do not wrap in HTML tags or add extra paragraphs.';
				$additional_prompt = '' !== trim( $additional_prompt ) ? $additional_prompt . "\n\n" . $plain_text_hint : $plain_text_hint;
				$translated        = self::translate( $text, $target_language, $source_language, $additional_prompt );
				if ( is_wp_error( $translated ) ) { return $translated; }

				return array( 'translated_text' => $translated, 'source_language' => $source_language, 'target_language' => $target_language );
			}
		);
	}

	public static function execute_translate_blocks( $input ) {
		$input = is_array( $input ) ? $input : array();

		return TranslationRuntime::with_model_slug_override(
			$input,
			function () use ( $input ) {
				$content = self::require_non_empty_string_input( $input, 'content', 'missing_content', __( 'Block content to translate is required.', 'slytranslate' ) );
				if ( is_wp_error( $content ) ) { return $content; }

				$source_language = self::require_language_code_input( $input, 'source_language', 'missing_source_language', __( 'Source language is required.', 'slytranslate' ) );
				if ( is_wp_error( $source_language ) ) { return $source_language; }

				$target_language = self::require_language_code_input( $input, 'target_language', 'missing_target_language', __( 'Target language is required.', 'slytranslate' ) );
				if ( is_wp_error( $target_language ) ) { return $target_language; }

				$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] ) ? mb_substr( sanitize_textarea_field( $input['additional_prompt'] ), 0, 2000 ) : '';
				$translated = ContentTranslator::translate_post_content( $content, $target_language, $source_language, $additional_prompt );
				if ( is_wp_error( $translated ) ) { return $translated; }

				return array( 'translated_content' => $translated, 'source_language' => $source_language, 'target_language' => $target_language );
			}
		);
	}

	public static function execute_translate_content( $input ) {
		$input = is_array( $input ) ? $input : array();

		return TranslationRuntime::with_model_slug_override(
			$input,
			function () use ( $input ) {
				$post_id = self::require_positive_int_input( $input, 'post_id', 'invalid_post_id', __( 'A valid source post ID is required.', 'slytranslate' ) );
				if ( is_wp_error( $post_id ) ) { return $post_id; }

				$target_language = self::require_language_code_input( $input, 'target_language', 'missing_target_language', __( 'Target language is required.', 'slytranslate' ) );
				if ( is_wp_error( $target_language ) ) { return $target_language; }

				$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] ) ? mb_substr( sanitize_textarea_field( $input['additional_prompt'] ), 0, 2000 ) : '';
				$result            = self::translate_post( $post_id, $target_language, self::get_optional_sanitized_key_input( $input, 'post_status' ), $input['overwrite'] ?? false, $input['translate_title'] ?? true, $additional_prompt );
				if ( is_wp_error( $result ) ) { return $result; }

				$translated_post = get_post( $result );
				return array(
					'translated_post_id'   => $result,
					'source_post_id'       => $post_id,
					'target_language'      => $target_language,
					'title'                => $translated_post ? $translated_post->post_title : '',
					'translated_post_type' => $translated_post ? $translated_post->post_type : '',
					'post_status'          => $translated_post ? $translated_post->post_status : '',
					'edit_link'            => $translated_post ? (string) get_edit_post_link( $translated_post->ID, 'raw' ) : '',
				);
			}
		);
	}

	public static function execute_translate_posts( $input ) {
		$input = is_array( $input ) ? $input : array();

		return TranslationRuntime::with_model_slug_override(
			$input,
			function () use ( $input ) {
				$target_language = self::require_language_code_input( $input, 'target_language', 'missing_target_language', __( 'Target language is required.', 'slytranslate' ) );
				if ( is_wp_error( $target_language ) ) { return $target_language; }

				$adapter = self::get_adapter();
				if ( ! $adapter ) {
					return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'slytranslate' ) );
				}

				$post_ids = TranslationQueryService::resolve_bulk_source_post_ids( $input );
				if ( is_wp_error( $post_ids ) ) { return $post_ids; }

				$results   = array();
				$succeeded = 0;
				$failed    = 0;
				$skipped   = 0;
				$overwrite = ! empty( $input['overwrite'] );

				foreach ( $post_ids as $post_id ) {
					if ( ! current_user_can( 'edit_post', $post_id ) ) {
						$failed++;
						$results[] = array( 'source_post_id' => $post_id, 'translated_post_id' => 0, 'status' => 'failed', 'error' => __( 'You are not allowed to translate this content item.', 'slytranslate' ), 'edit_link' => '' );
						continue;
					}

					$source_language = $adapter->get_post_language( $post_id ) ?? '';
					if ( '' !== $source_language && $source_language === $target_language ) {
						$skipped++;
						$results[] = array( 'source_post_id' => $post_id, 'translated_post_id' => 0, 'status' => 'skipped', 'error' => __( 'The source content is already in the requested target language.', 'slytranslate' ), 'edit_link' => '' );
						continue;
					}

					$existing_translation = TranslationQueryService::get_existing_translation_id( $post_id, $target_language, $adapter );
					if ( $existing_translation > 0 && ! $overwrite ) {
						$skipped++;
						$results[] = array( 'source_post_id' => $post_id, 'translated_post_id' => $existing_translation, 'status' => 'skipped', 'error' => __( 'A translation already exists for the requested language.', 'slytranslate' ), 'edit_link' => (string) get_edit_post_link( $existing_translation, 'raw' ) );
						continue;
					}

					$result = self::translate_post( $post_id, $target_language, self::get_optional_sanitized_key_input( $input, 'post_status' ), $overwrite, $input['translate_title'] ?? true );
					if ( is_wp_error( $result ) ) {
						$failed++;
						$results[] = array( 'source_post_id' => $post_id, 'translated_post_id' => 0, 'status' => 'failed', 'error' => $result->get_error_message(), 'edit_link' => '' );
					} else {
						$succeeded++;
						$results[] = array( 'source_post_id' => $post_id, 'translated_post_id' => $result, 'status' => 'success', 'error' => null, 'edit_link' => (string) get_edit_post_link( $result, 'raw' ) );
					}
				}

				return array( 'results' => $results, 'total' => count( $post_ids ), 'succeeded' => $succeeded, 'failed' => $failed, 'skipped' => $skipped );
			}
		);
	}

	public static function execute_configure( $input ) {
		$input         = is_array( $input ) ? $input : array();
		$config_result = ConfigurationService::save( $input );
		if ( is_wp_error( $config_result ) ) {
			return $config_result;
		}

		MetaTranslationService::reset_cache();
		TranslationRuntime::reset_context();
		EditorBootstrap::clear_available_models_cache();

		$seo_plugin_config     = MetaTranslationService::get_active_seo_plugin_config();
		$translategemma_status = TranslationRuntime::get_translategemma_runtime_status();

		return array(
			'prompt_template'                  => get_option( 'ai_translate_prompt', self::$PROMPT ),
			'prompt_addon'                     => get_option( 'ai_translate_prompt_addon', '' ),
			'meta_keys_translate'              => get_option( 'ai_translate_meta_translate', '' ),
			'meta_keys_clear'                  => get_option( 'ai_translate_meta_clear', '' ),
			'auto_translate_new'               => get_option( 'ai_translate_new_post', '0' ) === '1',
			'context_window_tokens'            => absint( get_option( 'ai_translate_context_window_tokens', 0 ) ),
			'model_slug'                       => get_option( 'ai_translate_model_slug', '' ),
			'direct_api_url'                   => get_option( 'ai_translate_direct_api_url', '' ),
			'direct_api_kwargs_supported'      => get_option( 'ai_translate_direct_api_kwargs_detected', '0' ) === '1',
			'direct_api_kwargs_last_probed_at' => absint( get_option( 'ai_translate_direct_api_kwargs_last_probed_at', 0 ) ),
			'translategemma_runtime_ready'     => $translategemma_status['ready'],
			'translategemma_runtime_status'    => $translategemma_status['status'],
			'detected_seo_plugin'              => $seo_plugin_config['key'],
			'detected_seo_plugin_label'        => $seo_plugin_config['label'],
			'seo_meta_keys_translate'          => $seo_plugin_config['translate'],
			'seo_meta_keys_clear'              => $seo_plugin_config['clear'],
			'effective_meta_keys_translate'    => MetaTranslationService::meta_translate(),
			'effective_meta_keys_clear'        => MetaTranslationService::meta_clear(),
			'learned_context_window_tokens'    => TranslationRuntime::get_learned_context_window_tokens(),
			'effective_context_window_tokens'  => TranslationRuntime::get_effective_context_window_tokens(),
			'effective_chunk_chars'            => TranslationRuntime::get_chunk_char_limit(),
		);
	}

	/* ---------------------------------------------------------------
	 * Core translation methods – public API (must stay on AI_Translate)
	 * ------------------------------------------------------------- */

	public static function translate_post( $post_id, $to, $status = '', $overwrite = false, $translate_title = true, $additional_prompt = '' ) {
		return PostTranslationService::translate_post( absint( $post_id ), (string) $to, (string) $status, (bool) $overwrite, (bool) $translate_title, (string) $additional_prompt );
	}

	public static function translate( $text, $to, $from = 'en', $additional_prompt = '' ) {
		return TranslationRuntime::translate_text( $text, $to, $from, $additional_prompt );
	}

	public static function prompt( $to, $from = 'en', $additional_prompt = '' ) {
		return TranslationRuntime::build_prompt( $to, $from, $additional_prompt );
	}

	/* ---------------------------------------------------------------
	 * Polylang backward-compat filter callbacks
	 * ------------------------------------------------------------- */

	public static function default_title( $title, $post ) {
		return LegacyPolylangBridge::default_title( $title, $post );
	}

	public static function default_content( $content, $post ) {
		return LegacyPolylangBridge::default_content( $content, $post );
	}

	public static function default_excerpt( $excerpt, $post ) {
		return LegacyPolylangBridge::default_excerpt( $excerpt, $post );
	}

	public static function pll_translate_post_meta( $value, $key, $lang ) {
		return LegacyPolylangBridge::pll_translate_post_meta( $value, $key, $lang );
	}

	/* ---------------------------------------------------------------
	 * Private thin wrappers – referenced by unit tests via invokeStatic()
	 * ------------------------------------------------------------- */

	private static function get_translation_progress(): array {
		return TranslationProgressTracker::get_progress();
	}

	private static function mark_translation_phase( string $phase ): void {
		TranslationProgressTracker::mark_phase( $phase );
	}

	private static function translate_with_chunk_limit( string $text, string $prompt, int $chunk_char_limit, int $attempt = 0, ?int $previous_chunk_count = null ) {
		return TranslationRuntime::translate_with_chunk_limit( $text, $prompt, $chunk_char_limit, $attempt, $previous_chunk_count );
	}

	private static function translate_chunk( string $text, string $prompt, int $validation_attempt = 0 ) {
		return TranslationRuntime::translate_chunk( $text, $prompt, $validation_attempt );
	}

	private static function translate_chunk_direct_api( string $text, string $prompt, string $model_slug, string $api_url, bool $kwargs_supported ) {
		return DirectApiTranslationClient::translate( $text, $prompt, $model_slug, $api_url, $kwargs_supported, TranslationRuntime::get_source_lang(), TranslationRuntime::get_target_lang() );
	}

	private static function get_translation_chunk_char_limit_from_context_window( int $context_window_tokens ): int {
		return TranslationRuntime::get_chunk_char_limit_from_context_window( $context_window_tokens );
	}

	private static function extract_context_window_tokens_from_error( \WP_Error $error ): int {
		return TranslationRuntime::extract_context_window_tokens_from_error( $error );
	}

	private static function validate_translated_output( string $source_text, string $translated_text ) {
		return TranslationValidator::validate( $source_text, $translated_text );
	}

	private static function should_skip_block_translation( array $block ): bool {
		return ContentTranslator::should_skip_block( $block );
	}

	private static function should_translate_block_fragment( string $fragment ): bool {
		return ContentTranslator::should_translate_fragment( $fragment );
	}

	private static function normalize_bulk_limit( $limit ): int {
		return TranslationQueryService::normalize_limit( $limit );
	}

	private static function split_text_for_translation( string $text, int $max_chars ): array {
		return TextSplitter::split_text_for_translation( $text, $max_chars );
	}

	private static function split_segment_for_translation( string $segment, int $max_chars ): array {
		return TextSplitter::split_segment_for_translation( $segment, $max_chars );
	}

	private static function hard_split_text( string $text, int $max_chars ): array {
		return TextSplitter::hard_split_text( $text, $max_chars );
	}

	private static function meta_translate( int $post_id = 0 ) {
		return MetaTranslationService::meta_translate( $post_id );
	}

	private static function meta_clear( int $post_id = 0 ) {
		return MetaTranslationService::meta_clear( $post_id );
	}

	private static function normalize_translation_post_status( $requested_status, \WP_Post $post ): string {
		return PostTranslationService::normalize_post_status( $requested_status, $post );
	}

	private static function build_translation_status_entry( string $language_code, int $translated_post_id ): array {
		return TranslationQueryService::build_translation_status_entry( $language_code, $translated_post_id );
	}

	/* ---------------------------------------------------------------
	 * Input validation helpers (still used internally)
	 * ------------------------------------------------------------- */

	private static function require_positive_int_input( array $input, string $key, string $error_code, string $message ) {
		if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) {
			return new \WP_Error( $error_code, $message );
		}
		$value = absint( $input[ $key ] );
		if ( $value < 1 ) {
			return new \WP_Error( $error_code, $message );
		}
		return $value;
	}

	private static function require_non_empty_string_input( array $input, string $key, string $error_code, string $message ) {
		if ( ! array_key_exists( $key, $input ) || ! is_string( $input[ $key ] ) ) {
			return new \WP_Error( $error_code, $message );
		}
		$value = trim( $input[ $key ] );
		if ( '' === $value ) {
			return new \WP_Error( $error_code, $message );
		}
		return $value;
	}

	private static function require_language_code_input( array $input, string $key, string $error_code, string $message ) {
		$value = self::require_non_empty_string_input( $input, $key, $error_code, $message );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		$language_code = sanitize_key( $value );
		if ( '' === $language_code ) {
			return new \WP_Error( $error_code, $message );
		}
		return $language_code;
	}

	private static function get_optional_sanitized_key_input( array $input, string $key ): string {
		if ( ! array_key_exists( $key, $input ) || ! is_string( $input[ $key ] ) ) {
			return '';
		}
		return sanitize_key( $input[ $key ] );
	}
}

AI_Translate::add_hooks();
