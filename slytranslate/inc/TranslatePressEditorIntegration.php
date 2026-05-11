<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

class TranslatePressEditorIntegration {
	public const SCRIPT_HANDLE = 'slytranslate-translatepress-editor';
	public const STYLE_HANDLE  = 'slytranslate-translatepress-editor-style';
	private const OBJECT_NAME  = 'SlyTranslateTranslatePressEditor';

	public static function add_hooks(): void {
		add_action( 'trp_translation_manager_footer', array( static::class, 'register_assets' ), 1 );
		add_filter( 'trp-scripts-for-editor', array( static::class, 'include_editor_script' ) );
		add_filter( 'trp-styles-for-editor', array( static::class, 'include_editor_style' ) );
		add_filter( 'trp_editor_data', array( static::class, 'inject_editor_data' ) );
	}

	public static function register_assets(): void {
		if ( ! self::is_supported_context() ) {
			return;
		}

		wp_register_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/translatepress-editor.css', self::plugin_base_file() ),
			array(),
			self::asset_version( 'assets/translatepress-editor.css' )
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/translatepress-editor.js', self::plugin_base_file() ),
			array(),
			self::asset_version( 'assets/translatepress-editor.js' ),
			true
		);

		wp_localize_script( self::SCRIPT_HANDLE, self::OBJECT_NAME, self::get_bootstrap_data() );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				self::SCRIPT_HANDLE,
				'slytranslate',
				plugin_dir_path( self::plugin_base_file() ) . 'languages'
			);
		}
	}

	public static function include_editor_script( array $handles ): array {
		if ( ! self::is_supported_context() ) {
			return $handles;
		}

		if ( ! in_array( self::SCRIPT_HANDLE, $handles, true ) ) {
			$handles[] = self::SCRIPT_HANDLE;
		}

		return $handles;
	}

	public static function include_editor_style( array $handles ): array {
		if ( ! self::is_supported_context() ) {
			return $handles;
		}

		if ( ! in_array( self::STYLE_HANDLE, $handles, true ) ) {
			$handles[] = self::STYLE_HANDLE;
		}

		return $handles;
	}

	public static function inject_editor_data( array $editor_data ): array {
		if ( ! self::is_supported_context() ) {
			return $editor_data;
		}

		$editor_data['slytranslate'] = self::get_bootstrap_data();

		return $editor_data;
	}

	public static function get_bootstrap_data_for_current_url( string $current_url = '' ): array {
		return self::get_bootstrap_data( $current_url );
	}

	private static function get_bootstrap_data( string $current_url = '' ): array {
		$context                = self::resolve_editor_context( $current_url );
		$user_id                = get_current_user_id();
		$last_additional_prompt = $user_id > 0 ? (string) get_user_meta( $user_id, '_slytranslate_last_additional_prompt', true ) : '';
		$source_language        = isset( $context['source_language'] ) ? (string) $context['source_language'] : '';

		return array(
			'enabled'              => ! empty( $context['enabled'] ),
			'disabledReason'       => isset( $context['disabled_reason'] ) ? (string) $context['disabled_reason'] : '',
			'debugLogEnabled'      => self::is_debug_log_enabled(),
			'restUrl'              => esc_url_raw( rest_url( Plugin::REST_NAMESPACE . '/' ) ),
			'restNonce'            => wp_create_nonce( 'wp_rest' ),
			'postId'               => isset( $context['post_id'] ) ? (int) $context['post_id'] : 0,
			'postTitle'            => isset( $context['post_title'] ) ? (string) $context['post_title'] : '',
			'sourceLanguage'       => $source_language,
			'languages'            => self::get_language_options( $source_language ),
			'models'               => EditorBootstrap::get_available_models(),
			'defaultModelSlug'     => self::get_option_string( 'slytranslate_model_slug', '' ),
			'lastAdditionalPrompt' => $last_additional_prompt,
			'i18n'                 => self::get_editor_strings(),
		);
	}

	private static function resolve_editor_context( string $current_url = '' ): array {
		$adapter         = AI_Translate::get_adapter();
		$source_language = $adapter instanceof TranslatePressAdapter ? (string) ( $adapter->get_post_language( 0 ) ?? '' ) : '';
		$post_id = self::resolve_post_id( $current_url );

		if ( $post_id < 1 ) {
			return array(
				'enabled'         => true,
				'disabled_reason' => '',
				'post_id'         => 0,
				'post_title'      => '',
				'source_language' => $source_language,
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array(
				'enabled'         => true,
				'disabled_reason' => '',
				'post_id'         => $post_id,
				'post_title'      => '',
				'source_language' => $source_language,
			);
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return array(
				'enabled'         => true,
				'disabled_reason' => '',
				'post_id'         => $post_id,
				'post_title'      => '',
				'source_language' => $source_language,
			);
		}

		$source_language = $adapter instanceof TranslatePressAdapter ? (string) ( $adapter->get_post_language( $post_id ) ?? '' ) : $source_language;

		return array(
			'enabled'         => true,
			'disabled_reason' => '',
			'post_id'         => $post_id,
			'post_title'      => (string) $post->post_title,
			'source_language' => $source_language,
		);
	}

	private static function resolve_post_id( string $current_url = '' ): int {
		if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'get_queried_object_id' ) ) {
			$post_id = absint( get_queried_object_id() );
			if ( $post_id > 0 ) {
				return $post_id;
			}
		}

		if ( ! function_exists( 'url_to_postid' ) ) {
			return 0;
		}

		if ( '' !== trim( $current_url ) ) {
			$url = self::normalize_current_url_to_site_url( $current_url );
			if ( '' !== $url ) {
				$clean_url = remove_query_arg( array( 'trp-edit-translation', 'trp-view-as', 'trp-view-as-nonce' ), $url );

				return absint( url_to_postid( $clean_url ) );
			}
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri || ! function_exists( 'home_url' ) || ! function_exists( 'remove_query_arg' ) ) {
			return 0;
		}

		$clean_url = remove_query_arg( array( 'trp-edit-translation', 'trp-view-as', 'trp-view-as-nonce' ), home_url( $request_uri ) );

		return absint( url_to_postid( $clean_url ) );
	}

	private static function normalize_current_url_to_site_url( string $current_url ): string {
		$current_url = trim( $current_url );
		if ( '' === $current_url || ! function_exists( 'home_url' ) ) {
			return '';
		}

		$parsed = function_exists( 'wp_parse_url' ) ? wp_parse_url( $current_url ) : false;
		if ( ! is_array( $parsed ) ) {
			return '';
		}

		$path     = isset( $parsed['path'] ) && is_string( $parsed['path'] ) ? $parsed['path'] : '';
		$query    = isset( $parsed['query'] ) && is_string( $parsed['query'] ) ? $parsed['query'] : '';
		$fragment = isset( $parsed['fragment'] ) && is_string( $parsed['fragment'] ) ? $parsed['fragment'] : '';
		$request  = '' !== $path ? $path : '/';

		if ( '' !== $query ) {
			$request .= '?' . $query;
		}

		if ( '' !== $fragment ) {
			$request .= '#' . $fragment;
		}

		return home_url( $request );
	}

	private static function get_language_options( string $source_language ): array {
		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter instanceof TranslatePressAdapter ) {
			return array();
		}

		$languages = $adapter->get_languages();
		$options   = array();

		if ( '' !== $source_language && ! isset( $languages[ $source_language ] ) ) {
			$options[] = array(
				'code' => $source_language,
				'name' => strtoupper( $source_language ),
			);
		}

		foreach ( $languages as $code => $name ) {
			$options[] = array(
				'code' => (string) $code,
				'name' => (string) $name,
			);
		}

		return $options;
	}

	private static function get_editor_strings(): array {
		return array(
			'panelTitle'            => __( 'Translate with SlyTranslate', 'slytranslate' ),
			'modelLabel'            => __( 'AI model', 'slytranslate' ),
			'refreshModelsButton'   => __( 'Refresh', 'slytranslate' ),
			'additionalPromptLabel' => __( 'Additional instructions (optional)', 'slytranslate' ),
			'additionalPromptHelp'  => __( 'Supplements the site-wide translation instructions. Example: Use informal language.', 'slytranslate' ),
			'startButton'           => __( 'Translate', 'slytranslate' ),
			'cancelButton'          => __( 'Cancel translation', 'slytranslate' ),
			'loadingModels'         => __( 'Loading available models...', 'slytranslate' ),
			'noTargetLanguages'     => __( 'No target languages are available for this content item.', 'slytranslate' ),
			'translatingLanguage'   => __( 'Translating {language}...', 'slytranslate' ),
			'progressTitle'         => __( 'Translating title...', 'slytranslate' ),
			'progressContent'       => __( 'Translating content...', 'slytranslate' ),
			'progressContentFinishing' => __( 'Processing translated content...', 'slytranslate' ),
			'progressExcerpt'       => __( 'Translating excerpt...', 'slytranslate' ),
			'progressMeta'          => __( 'Translating metadata...', 'slytranslate' ),
			'progressSaving'        => __( 'Saving translation...', 'slytranslate' ),
			'progressDone'          => __( 'Translation complete.', 'slytranslate' ),
			'successNotice'         => __( 'Translation completed successfully.', 'slytranslate' ),
			'cancelNotice'          => __( 'Translation cancelled.', 'slytranslate' ),
			'fieldMissingError'     => __( 'The visible TranslatePress field could not be detected.', 'slytranslate' ),
			'languagePassthroughError' => __( 'A translated segment still appears to be in the source language instead of English.', 'slytranslate' ),
			'errorPrefix'           => __( 'Translation failed:', 'slytranslate' ),
			'unknownError'          => __( 'An unexpected error occurred.', 'slytranslate' ),
		);
	}

	private static function is_debug_log_enabled(): bool {
		return TimingLogger::is_enabled();
	}

	private static function is_supported_context(): bool {
		$adapter = AI_Translate::get_adapter();

		return $adapter instanceof TranslatePressAdapter;
	}

	private static function plugin_base_file(): string {
		return dirname( __DIR__ ) . '/slytranslate.php';
	}

	private static function asset_version( string $relative_path ): string {
		$asset_path  = dirname( __DIR__ ) . '/' . ltrim( $relative_path, '/' );
		$asset_mtime = file_exists( $asset_path ) ? filemtime( $asset_path ) : false;

		if ( false === $asset_mtime ) {
			return Plugin::VERSION;
		}

		return Plugin::VERSION . '.' . (string) $asset_mtime;
	}

	private static function get_option_string( string $option_name, string $fallback ): string {
		$value = get_option( $option_name, $fallback );

		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return $fallback;
	}
}