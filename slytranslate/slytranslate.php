<?php
/*
Plugin Name: SlyTranslate - AI Translation Abilities
Plugin URI: https://github.com/SlyBase/wordpress-slytranslate/
Description: AI translation abilities for WordPress using native AI Connectors as a core feature, plus the AI Client and Abilities API for text and content translation.
Version: 1.8.0
Author: Timon Först
Author URI: https://slybase.com
Requires at least: 6.9
Requires PHP: 8.1
License: MIT
Text Domain: slytranslate
Domain Path: /languages
*/

namespace SlyTranslate;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/vendor/autoload.php';

class AI_Translate {

	// Default prompt template – referenced by TranslationRuntime::build_prompt().
	private const DEFAULT_PROMPT = 'Translate the content from {FROM_CODE} to {TO_CODE} preserving html, formatting, embedded media, and source symbols. Do not rewrite Unicode symbols or math notation as LaTeX or ASCII. Only return the new content.';

	// Adapter singleton.
	private static $adapter;

	/* ---------------------------------------------------------------
	 * Adapter
	 * ------------------------------------------------------------- */

	public static function get_default_prompt(): string {
		return (string) apply_filters( 'slytranslate_default_prompt_template', self::DEFAULT_PROMPT );
	}

	/**
	 * Return model-profile rules used by TranslationRuntime.
	 *
	 * Integrations can extend or override this registry through the
	 * `slytranslate_model_profiles` filter instead of patching core flows.
	 */
	public static function get_model_profiles(): array {
		$profiles = ModelProfileRegistry::get_default_profiles();

		$filtered = apply_filters( 'slytranslate_model_profiles', $profiles );

		return is_array( $filtered ) ? $filtered : $profiles;
	}

	public static function get_adapter(): ?TranslationPluginAdapter {
		if ( null === self::$adapter ) {
			$candidates = apply_filters(
				'slytranslate_adapter_candidates',
				array(
					new PolylangAdapter(),
					new WpMultilangAdapter(),
					new WpglobusAdapter(),
					new TranslatePressAdapter(),
				)
			);

			if ( is_array( $candidates ) ) {
				foreach ( $candidates as $candidate ) {
					if ( $candidate instanceof TranslationPluginAdapter && $candidate->is_available() ) {
						self::$adapter = $candidate;
						break;
					}
				}
			}
		}
		return self::$adapter;
	}

	public static function is_single_entry_translation_mode(): bool {
		$adapter = self::get_adapter();
		return $adapter instanceof WpMultilangAdapter
			|| $adapter instanceof WpglobusAdapter
			|| $adapter instanceof TranslatePressAdapter;
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
		self::maybe_migrate_legacy_options();
		add_action( 'enqueue_block_editor_assets', array( EditorBootstrap::class, 'enqueue_editor_plugin' ) );
		add_action( 'admin_init',                  array( Settings::class, 'register' ) );
		add_action( 'rest_api_init', array( self::class, 'register_editor_rest_routes' ) );
		add_action( 'wp_abilities_api_categories_init', array( AbilityRegistrar::class, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( AbilityRegistrar::class, 'register_abilities' ) );
		TranslatePressEditorIntegration::add_hooks();
		ListTableTranslation::add_hooks();
	}

	/**
	 * One-time migration from the legacy `ai_translate_*` option prefix to `slytranslate_*`.
	 * Runs once per site and is idempotent afterwards.
	 */
	private static function maybe_migrate_legacy_options(): void {
		if ( get_option( 'slytranslate_prefix_migrated' ) ) {
			return;
		}

		$option_map = array(
			'ai_translate_prompt'                           => 'slytranslate_prompt',
			'ai_translate_prompt_addon'                     => 'slytranslate_prompt_addon',
			'ai_translate_meta_translate'                   => 'slytranslate_meta_translate',
			'ai_translate_meta_clear'                       => 'slytranslate_meta_clear',
			'ai_translate_new_post'                         => 'slytranslate_new_post',
			'ai_translate_context_window_tokens'            => 'slytranslate_context_window_tokens',
			'ai_translate_model_slug'                       => 'slytranslate_model_slug',
			'ai_translate_direct_api_url'                   => 'slytranslate_direct_api_url',
			'ai_translate_force_direct_api'                 => 'slytranslate_force_direct_api',
			'ai_translate_direct_api_kwargs_detected'       => 'slytranslate_direct_api_kwargs_detected',
			'ai_translate_direct_api_kwargs_last_probed_at' => 'slytranslate_direct_api_kwargs_last_probed_at',
			'ai_translate_direct_api_models_last_probed_at' => 'slytranslate_direct_api_models_last_probed_at',
			'ai_translate_learned_context_windows'          => 'slytranslate_learned_context_windows',
		);

		foreach ( $option_map as $old_key => $new_key ) {
			$old_value = get_option( $old_key );
			if ( false !== $old_value && false === get_option( $new_key ) ) {
				add_option( $new_key, $old_value, '', false );
			}
		}

		update_option( 'slytranslate_prefix_migrated', '1', false );
	}

	public static function register_editor_rest_routes(): void {
		$translation_permission = static function (): bool {
			return self::current_user_can_access_translation_abilities();
		};

		$admin_permission = static function (): bool {
			return current_user_can( 'manage_options' );
		};

		$routes = array(
			'/ai-translate/get-languages/run'         => array(
				'callback'            => array( self::class, 'execute_get_languages' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/get-translation-status/run' => array(
				'callback'            => array( self::class, 'execute_get_translation_status' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/set-post-language/run'     => array(
				'callback'            => array( self::class, 'execute_set_post_language' ),
				'permission_callback' => static function ( $request ): bool {
					return LanguageMutationService::set_post_language_permission_callback( self::get_rest_route_input( $request ) );
				},
			),
			'/ai-translate/get-untranslated/run'      => array(
				'callback'            => array( self::class, 'execute_get_untranslated' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/translate-text/run'        => array(
				'callback'            => array( self::class, 'execute_translate_text' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/translate-blocks/run'      => array(
				'callback'            => array( self::class, 'execute_translate_blocks' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/translate-content/run'     => array(
				'callback'            => array( self::class, 'execute_translate_content' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/translate-content-bulk/run' => array(
				'callback'            => array( self::class, 'execute_translate_posts' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/configure/run'             => array(
				'callback'            => array( self::class, 'execute_configure' ),
				'permission_callback' => $admin_permission,
			),
			'/ai-translate/get-progress/run'          => array(
				'callback'            => array( self::class, 'execute_get_progress' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/log-editor-event/run'      => array(
				'callback'            => array( self::class, 'execute_log_editor_event' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/get-existing-translation/run' => array(
				'callback'            => array( self::class, 'execute_get_existing_translation' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/get-editor-context/run' => array(
				'callback'            => array( self::class, 'execute_get_translatepress_editor_context' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/cancel-translation/run'    => array(
				'callback'            => array( self::class, 'execute_cancel_translation' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/get-available-models/run'  => array(
				'callback'            => array( self::class, 'execute_get_available_models' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/string-table-worker/run'   => array(
				'callback'            => array( self::class, 'execute_string_table_worker' ),
				'permission_callback' => static function ( $request ): bool {
					$input = self::get_rest_route_input( $request );
					return ConfigurationService::validate_string_table_probe_token( (string) ( $input['token'] ?? '' ) );
				},
			),
			'/ai-translate/save-additional-prompt/run' => array(
				'callback'            => array( self::class, 'execute_save_additional_prompt' ),
				'permission_callback' => $translation_permission,
			),
			'/ai-translate/user-preference/run'       => array(
				'callback'            => array( self::class, 'execute_save_additional_prompt' ),
				'permission_callback' => $translation_permission,
			),
		);
		if ( ! LanguageMutationService::can_mutate_post_language() ) {
			unset( $routes['/ai-translate/set-post-language/run'] );
		}

		foreach ( $routes as $route => $config ) {
			register_rest_route(
				Plugin::REST_NAMESPACE,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => static function ( $request ) use ( $config ) {
						$input = self::get_rest_route_input( $request );
						return call_user_func( $config['callback'], $input );
					},
					'permission_callback' => $config['permission_callback'],
				)
			);
		}
	}

	private static function get_rest_route_input( $request ): array {
		if ( ! is_object( $request ) ) {
			return array();
		}

		$payload = null;
		if ( method_exists( $request, 'get_json_params' ) ) {
			$payload = $request->get_json_params();
		}

		if ( is_array( $payload ) ) {
			if ( isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
				return $payload['input'];
			}

			return $payload;
		}

		if ( method_exists( $request, 'get_param' ) ) {
			$input = $request->get_param( 'input' );
			if ( is_array( $input ) ) {
				return $input;
			}
		}

		if ( method_exists( $request, 'get_params' ) ) {
			$params = $request->get_params();
			return is_array( $params ) ? $params : array();
		}

		return array();
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

	public static function execute_set_post_language( $input ) {
		return LanguageMutationService::execute_set_post_language( $input );
	}

	public static function execute_get_untranslated( $input ) {
		return TranslationQueryService::execute_get_untranslated( $input );
	}

	public static function execute_translate_text( $input ) {
		$input = is_array( $input ) ? $input : array();
		TranslationProgressTracker::clear_cancelled();

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
				$plain_text_hint  = self::get_plain_text_translation_hint();
				$additional_prompt = '' !== trim( $additional_prompt ) ? $additional_prompt . "\n\n" . $plain_text_hint : $plain_text_hint;
				$translated        = self::translate( $text, $target_language, $source_language, $additional_prompt );
				if ( is_wp_error( $translated ) ) { return $translated; }

				return array( 'translated_text' => $translated, 'source_language' => $source_language, 'target_language' => $target_language );
			}
		);
	}

	private static function get_plain_text_translation_hint(): string {
		return 'The input is a short plain-text snippet. Translate it and return only the translated text. Do not wrap it in HTML tags or add extra paragraphs. Preserve placeholders and template tokens exactly as written, for example {name}, %s, %1$s, {{variable}}, and [shortcode]. If parts of the text are already in the target language, keep those parts unchanged and translate only the remaining source-language fragments.';
	}

	public static function execute_translate_blocks( $input ) {
		$input = is_array( $input ) ? $input : array();
		TranslationProgressTracker::clear_cancelled();

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

		// Allow long-running translations to complete server-side even when the
		// browser navigates away or aborts the fetch (used by the
		// "Continue in background" flow in the post-list dialog).
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			$max_request_seconds = (int) apply_filters( 'slytranslate_max_request_seconds', 0 );
			if ( $max_request_seconds > 0 ) {
				$max_request_seconds = max( 30, min( 300, $max_request_seconds ) );
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- opt-in runtime extension for long-running server-side translations.
				set_time_limit( $max_request_seconds );
			}
		}
		TranslationProgressTracker::clear_cancelled();
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		TranslationProgressTracker::clear_progress( $post_id );

		return TranslationRuntime::with_model_slug_override(
			$input,
			function () use ( $input ) {
				$post_id = self::require_positive_int_input( $input, 'post_id', 'invalid_post_id', __( 'A valid source post ID is required.', 'slytranslate' ) );
				if ( is_wp_error( $post_id ) ) { return $post_id; }

				$target_language = self::require_language_code_input( $input, 'target_language', 'missing_target_language', __( 'Target language is required.', 'slytranslate' ) );
				if ( is_wp_error( $target_language ) ) { return $target_language; }
				$source_language = self::get_optional_sanitized_key_input( $input, 'source_language' );

				$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] ) ? mb_substr( sanitize_textarea_field( $input['additional_prompt'] ), 0, 2000 ) : '';
				if ( ! current_user_can( 'edit_others_posts' ) ) {
					$additional_prompt = '';
				}
				$result            = self::translate_post( $post_id, $target_language, self::get_optional_sanitized_key_input( $input, 'post_status' ), $input['overwrite'] ?? false, $input['translate_title'] ?? true, $additional_prompt, $source_language );
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

	public static function execute_get_existing_translation( $input ) {
		$input = is_array( $input ) ? $input : array();

		$target_language = self::require_language_code_input( $input, 'target_language', 'missing_target_language', __( 'Target language is required.', 'slytranslate' ) );
		if ( is_wp_error( $target_language ) ) { return $target_language; }

		$source_text = isset( $input['source_text'] ) && is_string( $input['source_text'] )
			? trim( wp_strip_all_tags( $input['source_text'], false ) )
			: '';

		if ( '' === $source_text ) {
			return new \WP_Error( 'missing_source_text', __( 'Source text is required.', 'slytranslate' ) );
		}

		$adapter = self::get_adapter();
		if ( ! $adapter instanceof TranslatePressAdapter ) {
			return new \WP_Error( 'translatepress_not_available', __( 'TranslatePress is not active.', 'slytranslate' ) );
		}

		$translated_text = $adapter->get_string_translation( $source_text, $target_language );

		return array(
			'target_language' => $target_language,
			'source_text'     => $source_text,
			'translated_text' => is_string( $translated_text ) ? $translated_text : '',
			'found'           => is_string( $translated_text ) && '' !== trim( $translated_text ),
		);
	}

	public static function execute_get_translatepress_editor_context( $input ) {
		$input = is_array( $input ) ? $input : array();

		$current_url = isset( $input['current_url'] ) && is_string( $input['current_url'] )
			? esc_url_raw( $input['current_url'] )
			: '';

		return TranslatePressEditorIntegration::get_bootstrap_data_for_current_url( $current_url );
	}

	public static function execute_log_editor_event( $input ) {
		$input = is_array( $input ) ? $input : array();

		if ( ! TimingLogger::is_enabled() ) {
			return array( 'logged' => false );
		}

		$event = isset( $input['event'] ) && is_string( $input['event'] )
			? sanitize_key( $input['event'] )
			: '';

		if ( '' === $event ) {
			return new \WP_Error( 'missing_event', __( 'Editor event is required.', 'slytranslate' ) );
		}

		$context         = array();
		$allowed_context = array(
			'post_id',
			'target_count',
			'target_language',
			'reason',
			'phase',
			'percent',
			'model_slug',
			'source_length',
			'has_source_field',
			'has_target_field',
			'source_readonly',
			'target_readonly',
			'source_field_name',
			'target_field_name',
			'source_field_id',
			'target_field_id',
			'source_preview',
			'target_preview',
			'candidate_count',
			'active_scope',
			'source_origin',
			'source_selected_index',
			'source_runtime_preview',
			'is_running',
			'found',
		);

		foreach ( $allowed_context as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];
			if ( is_bool( $value ) || is_numeric( $value ) ) {
				$context[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$context[ $key ] = sanitize_text_field( $value );
			}
		}

		TimingLogger::log( 'translatepress_editor', array_merge( array( 'event' => $event ), $context ) );

		return array( 'logged' => true );
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
				$source_language = self::get_optional_sanitized_key_input( $input, 'source_language' );
				$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] ) ? mb_substr( sanitize_textarea_field( $input['additional_prompt'] ), 0, 2000 ) : '';
				if ( ! current_user_can( 'edit_others_posts' ) ) {
					$additional_prompt = '';
				}

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

					$result = self::translate_post( $post_id, $target_language, self::get_optional_sanitized_key_input( $input, 'post_status' ), $overwrite, $input['translate_title'] ?? true, $additional_prompt, $source_language );
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
			'prompt_template'                  => get_option( 'slytranslate_prompt', self::get_default_prompt() ),
			'prompt_addon'                     => get_option( 'slytranslate_prompt_addon', '' ),
			'meta_keys_translate'              => get_option( 'slytranslate_meta_translate', '' ),
			'meta_keys_clear'                  => get_option( 'slytranslate_meta_clear', '' ),
			'auto_translate_new'               => get_option( 'slytranslate_new_post', '0' ) === '1',
			'context_window_tokens'            => absint( get_option( 'slytranslate_context_window_tokens', 0 ) ),
			'string_table_concurrency'         => ConfigurationService::get_string_table_concurrency_setting(),
			'string_table_concurrency_effective' => ConfigurationService::get_effective_string_table_concurrency()['effective'],
			'string_table_concurrency_recommended' => ConfigurationService::get_effective_string_table_concurrency()['recommended'],
			'string_table_concurrency_supported' => ConfigurationService::get_effective_string_table_concurrency()['supported'],
			'string_table_concurrency_transport' => ConfigurationService::get_effective_string_table_concurrency()['transport'],
			'model_slug'                       => get_option( 'slytranslate_model_slug', '' ),
			'direct_api_url'                   => get_option( 'slytranslate_direct_api_url', '' ),
			'force_direct_api'                 => get_option( 'slytranslate_force_direct_api', '0' ) === '1',
			'direct_api_kwargs_supported'      => get_option( 'slytranslate_direct_api_kwargs_detected', '0' ) === '1',
			'direct_api_kwargs_last_probed_at' => absint( get_option( 'slytranslate_direct_api_kwargs_last_probed_at', 0 ) ),
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
			'last_transport_diagnostics'       => TranslationRuntime::get_last_diagnostics_snapshot(),
		);
	}

	public static function execute_string_table_worker( $input ) {
		$input = is_array( $input ) ? $input : array();
		$token = isset( $input['token'] ) && is_string( $input['token'] ) ? $input['token'] : '';
		if ( ! ConfigurationService::validate_string_table_probe_token( $token ) ) {
			return new \WP_Error( 'invalid_probe_token', __( 'The string-table worker token is invalid or expired.', 'slytranslate' ) );
		}

		$action     = isset( $input['action'] ) && is_string( $input['action'] ) ? sanitize_key( $input['action'] ) : '';
		$model_slug = isset( $input['model_slug'] ) && is_string( $input['model_slug'] ) ? $input['model_slug'] : '';

		return TranslationRuntime::with_model_slug_override(
			array( 'model_slug' => $model_slug ),
			static function () use ( $input, $action ) {
				$started = TimingLogger::start();

				if ( 'probe' === $action ) {
					$text            = isset( $input['text'] ) && is_string( $input['text'] ) ? $input['text'] : '';
					$source_language = isset( $input['source_language'] ) && is_string( $input['source_language'] ) ? sanitize_key( $input['source_language'] ) : 'de';
					$target_language = isset( $input['target_language'] ) && is_string( $input['target_language'] ) ? sanitize_key( $input['target_language'] ) : 'en';
					$result          = TranslationRuntime::translate_text( $text, $target_language, $source_language, 'Return only the translation.' );

					if ( is_wp_error( $result ) ) {
						return array(
							'ok'         => false,
							'error_code' => $result->get_error_code(),
							'message'    => $result->get_error_message(),
							'duration_ms' => TimingLogger::stop( $started ),
						);
					}

					return array(
						'ok'          => true,
						'duration_ms' => TimingLogger::stop( $started ),
						'translated'  => (string) $result,
					);
				}

				if ( 'translate_string_table_batch' === $action ) {
					$batch             = isset( $input['batch'] ) && is_array( $input['batch'] ) ? $input['batch'] : array();
					$target_language   = isset( $input['target_language'] ) && is_string( $input['target_language'] ) ? sanitize_key( $input['target_language'] ) : '';
					$source_language   = isset( $input['source_language'] ) && is_string( $input['source_language'] ) ? sanitize_key( $input['source_language'] ) : '';
					$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] ) ? sanitize_textarea_field( $input['additional_prompt'] ) : '';
					$batch_index       = absint( $input['batch_index'] ?? 0 );
					$result            = ContentTranslator::translate_string_table_batch_worker( $batch, $target_language, $source_language, $additional_prompt, $batch_index );

					if ( is_wp_error( $result ) ) {
						return array(
							'ok'          => false,
							'error_code'  => $result->get_error_code(),
							'message'     => $result->get_error_message(),
							'batch_index' => $batch_index,
							'duration_ms' => TimingLogger::stop( $started ),
						);
					}

					return array(
						'ok'          => true,
						'batch_index' => $batch_index,
						'duration_ms' => TimingLogger::stop( $started ),
						'result'      => $result,
					);
				}

				return new \WP_Error( 'invalid_string_table_worker_action', __( 'Unknown string-table worker action.', 'slytranslate' ) );
			}
		);
	}

	public static function execute_get_progress( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		return TranslationProgressTracker::get_progress( $post_id );
	}

	public static function execute_cancel_translation( $input ): array {
		TranslationProgressTracker::set_cancelled();
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		TranslationProgressTracker::clear_progress( $post_id );
		return array( 'cancelled' => true );
	}

	public static function execute_get_available_models( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$refresh = ! empty( $input['refresh'] );
		$models  = EditorBootstrap::get_available_models( $refresh );
		return array(
			'models'           => $models,
			'defaultModelSlug' => (string) get_option( 'slytranslate_model_slug', '' ),
			'refreshed'        => $refresh,
		);
	}

	public static function execute_save_additional_prompt( $input ) {
		$input             = is_array( $input ) ? $input : array();
		$additional_prompt = isset( $input['additional_prompt'] ) && is_string( $input['additional_prompt'] )
			? mb_substr( sanitize_textarea_field( $input['additional_prompt'] ), 0, 2000 )
			: '';

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return new \WP_Error( 'not_logged_in', __( 'You must be logged in to save preferences.', 'slytranslate' ) );
		}

		update_user_meta( $user_id, '_slytranslate_last_additional_prompt', $additional_prompt );
		return array( 'additional_prompt' => $additional_prompt );
	}

	/* ---------------------------------------------------------------
	 * Core translation methods – public API (must stay on AI_Translate)
	 * ------------------------------------------------------------- */

	public static function translate_post( $post_id, $to, $status = '', $overwrite = false, $translate_title = true, $additional_prompt = '', $source_language = '' ) {
		return PostTranslationService::translate_post( absint( $post_id ), (string) $to, (string) $status, (bool) $overwrite, (bool) $translate_title, (string) $additional_prompt, (string) $source_language );
	}

	public static function translate( $text, $to, $from = 'en', $additional_prompt = '' ) {
		return TranslationRuntime::translate_text( $text, $to, $from, $additional_prompt );
	}

	public static function prompt( $to, $from = 'en', $additional_prompt = '' ) {
		return TranslationRuntime::build_prompt( $to, $from, $additional_prompt );
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

	public static function on_activate(): void {
		// Migrate existing options to autoload=false so they don't bloat every
		// page load. The Settings API will continue to handle them from this point.
		$options = array(
			'slytranslate_prompt',
			'slytranslate_prompt_addon',
			'slytranslate_meta_translate',
			'slytranslate_meta_clear',
			'slytranslate_new_post',
			'slytranslate_context_window_tokens',
			'slytranslate_model_slug',
			'slytranslate_direct_api_url',
			'slytranslate_force_direct_api',
			'slytranslate_direct_api_kwargs_detected',
			'slytranslate_direct_api_kwargs_last_probed_at',
			'slytranslate_learned_context_window_tokens',
			'slytranslate_last_kwarg_probe_result',
			'slytranslate_string_table_concurrency',
			'slytranslate_string_table_concurrency_recommendations',
		);

		foreach ( $options as $option ) {
			$value = get_option( $option, null );
			if ( null !== $value ) {
				delete_option( $option );
				add_option( $option, $value, '', false );
			}
		}
	}
}

add_action( 'plugins_loaded', static function () {
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return;
	}
	AI_Translate::add_hooks();
} );

register_activation_hook( __FILE__, array( AI_Translate::class, 'on_activate' ) );
