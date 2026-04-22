<?php

namespace AI_Translate;

if ( ! defined( 'ABSPATH' ) ) exit;

class EditorBootstrap {

	private const AVAILABLE_MODELS_TRANSIENT = 'ai_translate_available_models';

	public static function enqueue_editor_plugin(): void {
		wp_enqueue_script(
			Plugin::EDITOR_SCRIPT,
			plugins_url( 'assets/editor-plugin.js', dirname( __DIR__ ) . '/ai-translate.php' ),
			array( 'wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-plugins', 'wp-rich-text' ),
			self::get_editor_script_version(),
			true
		);

		wp_localize_script( Plugin::EDITOR_SCRIPT, 'aiTranslateEditor', self::get_bootstrap_data() );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				Plugin::EDITOR_SCRIPT,
				'slytranslate',
				plugin_dir_path( dirname( __DIR__ ) . '/ai-translate.php' ) . 'languages'
			);
		}
	}

	public static function get_bootstrap_data(): array {
		$user_id               = get_current_user_id();
		$last_additional_prompt = $user_id > 0 ? (string) get_user_meta( $user_id, '_ai_translate_last_additional_prompt', true ) : '';

		return array(
			'abilitiesRunBasePath'      => self::get_editor_rest_base_path(),
			'restNonce'                 => wp_create_nonce( 'wp_rest' ),
			'translationPluginAvailable' => null !== AI_Translate::get_adapter(),
			'translationPluginLanguages' => self::get_translation_plugin_languages(),
			'defaultSourceLanguage'     => self::get_editor_default_source_language(),
			'lastAdditionalPrompt'      => $last_additional_prompt,
			'models'                    => self::get_available_models(),
			'defaultModelSlug'          => get_option( 'ai_translate_model_slug', '' ),
			'strings'                   => array(
				'modelLabel'                => __( 'AI model', 'slytranslate' ),
				'refreshModelsButton'       => __( 'Refresh model list', 'slytranslate' ),
				'panelTitle'                => __( 'Translate (SlyTranslate)', 'slytranslate' ),
				'pickerTitle'               => __( 'Translate', 'slytranslate' ),
				'sourceLanguageLabel'       => __( 'Source language', 'slytranslate' ),
				'sourceLanguageManagedHint' => __( 'The source language is managed by your language plugin.', 'slytranslate' ),
				'targetLanguageLabel'       => __( 'Target language', 'slytranslate' ),
				'swapLanguagesButton'       => __( 'Swap source and target language', 'slytranslate' ),
				'overwriteLabel'            => __( 'Overwrite existing translation', 'slytranslate' ),
				'translateTitleLabel'       => __( 'Translate title', 'slytranslate' ),
				'additionalPromptLabel'     => __( 'Additional instructions (optional)', 'slytranslate' ),
				'additionalPromptHelp'      => __( 'Supplements the site-wide translation instructions. Example: Use informal language.', 'slytranslate' ),
				'pickerStartButton'         => __( 'Start translation', 'slytranslate' ),
				'translateButton'           => __( 'Translate now', 'slytranslate' ),
				'cancelTranslationButton'   => __( 'Cancel translation', 'slytranslate' ),
				'progressTitle'             => __( 'Translating title...', 'slytranslate' ),
				'progressContent'           => __( 'Translating content...', 'slytranslate' ),
				'progressContentFinishing'  => __( 'Processing translated content...', 'slytranslate' ),
				'progressExcerpt'           => __( 'Translating excerpt...', 'slytranslate' ),
				'progressMeta'              => __( 'Translating metadata...', 'slytranslate' ),
				'progressSaving'            => __( 'Saving translation...', 'slytranslate' ),
				'progressDone'              => __( 'Translation complete.', 'slytranslate' ),
				'refreshButton'             => __( 'Refresh translation status', 'slytranslate' ),
				'loadingLanguages'          => __( 'Loading available languages...', 'slytranslate' ),
				'loadingStatus'             => __( 'Loading translation status...', 'slytranslate' ),
				'noLanguages'               => __( 'No target languages are available for this content item.', 'slytranslate' ),
				'translationStatusLabel'    => __( 'Translation status', 'slytranslate' ),
				'translationExists'         => __( 'Available', 'slytranslate' ),
				'translationMissing'        => __( 'Not translated yet', 'slytranslate' ),
				'openTranslation'           => __( 'Open translation', 'slytranslate' ),
				'openTranslationShort'      => __( 'Open', 'slytranslate' ),
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

	public static function get_available_models( bool $force_refresh = false ): array {
		if ( $force_refresh ) {
			delete_transient( self::AVAILABLE_MODELS_TRANSIENT );
		} else {
			$cached_models = get_transient( self::AVAILABLE_MODELS_TRANSIENT );
			if ( is_array( $cached_models ) ) {
				return $cached_models;
			}
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return array();
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();

			// When the user requested a refresh, also invalidate the AI Client's
			// own per-provider model metadata cache (default TTL: 24h, persisted
			// via the PSR-16 cache configured through AiClient::setCache()).
			// Without this, changing a connector's endpoint or credentials (or
			// switching from a local llama.cpp server to e.g. Groq) would keep
			// returning the previously fetched model list for up to 24 hours.
			if ( $force_refresh ) {
				// Allow other plugins (e.g. ai-provider-for-llamacpp) to
				// invalidate their own per-connector model-metadata caches.
				do_action( 'slytranslate_refresh_provider_caches' );

				foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
					try {
						$class_name = $registry->getProviderClassName( $provider_id );
						$directory  = $class_name::modelMetadataDirectory();
						if ( method_exists( $directory, 'invalidateCaches' ) ) {
							$directory->invalidateCaches();
						}
					} catch ( \Throwable $e ) {
						// Ignore a single provider failure so the refresh can
						// still clear the remaining providers' caches.
						continue;
					}
				}
			}

			$requirements     = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements( array(), array() );
			$provider_results = $registry->findModelsMetadataForSupport( $requirements );
		} catch ( \Throwable $e ) {
			return array();
		}

		$models = array();
		foreach ( $provider_results as $provider_models ) {
			$provider_meta = $provider_models->getProvider();
			$provider_name = $provider_meta->getName();

			foreach ( $provider_models->getModels() as $model_meta ) {
				$model_id = $model_meta->getId();
				$models[] = array(
					'value' => $model_id,
					'label' => $provider_name . ': ' . $model_id,
				);
			}
		}

		set_transient( self::AVAILABLE_MODELS_TRANSIENT, $models, 5 * MINUTE_IN_SECONDS );

		return $models;
	}

	public static function clear_available_models_cache(): void {
		delete_transient( self::AVAILABLE_MODELS_TRANSIENT );
	}

	private static function get_editor_script_version(): string {
		$script_path  = dirname( __DIR__ ) . '/assets/editor-plugin.js';
		$script_mtime = file_exists( $script_path ) ? filemtime( $script_path ) : false;

		if ( false === $script_mtime ) {
			return Plugin::VERSION;
		}

		return Plugin::VERSION . '.' . (string) $script_mtime;
	}

	private static function get_editor_default_source_language(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		if ( ! is_string( $locale ) || '' === $locale ) {
			return 'en';
		}

		$locale         = strtolower( str_replace( '_', '-', $locale ) );
		$primary_subtag = sanitize_key( strtok( $locale, '-' ) ?: '' );

		return '' !== $primary_subtag ? $primary_subtag : 'en';
	}

	private static function get_editor_rest_base_path(): string {
		return '/' . Plugin::REST_NAMESPACE . '/';
	}

	/**
	 * Languages that the active translation plugin (Polylang) is configured
	 * for. Used to surface those languages first in editor language pickers
	 * so users see the languages they actually translate into before the
	 * generic global fallback list.
	 *
	 * @return array<int,array{code:string,name:string}>
	 */
	private static function get_translation_plugin_languages(): array {
		$adapter = AI_Translate::get_adapter();
		if ( null === $adapter ) {
			return array();
		}

		$languages = $adapter->get_languages();
		if ( ! is_array( $languages ) || empty( $languages ) ) {
			return array();
		}

		$result = array();
		foreach ( $languages as $code => $name ) {
			$code = (string) $code;
			if ( '' === $code ) {
				continue;
			}
			$result[] = array(
				'code' => $code,
				'name' => (string) $name,
			);
		}
		return $result;
	}
}
