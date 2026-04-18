<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Core translation engine: prompt building, chunked transport, context-window
 * heuristics, retry logic, and diagnostics.
 */
class TranslationRuntime {

	/* ---------------------------------------------------------------
	 * Constants
	 * ------------------------------------------------------------- */

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
		'mistral-large'   => 32768,
		'mistral-small'   => 32768,
		'sonar'           => 32768,
		'grok'            => 32768,
		'translategemma'  => 8192,
	);

	/* ---------------------------------------------------------------
	 * Per-request state
	 * ------------------------------------------------------------- */

	/** Cached runtime context (model slug, direct API URL). */
	private static $context = null;

	/** Per-request model slug override set by with_model_slug_override(). */
	private static $model_slug_override = null;

	/** Source / target language codes set inside translate_text() for use by DirectApiTranslationClient. */
	private static $source_lang = null;
	private static $target_lang = null;

	/** Diagnostics from the most recent chunk transport call. */
	private static $last_diagnostics = null;

	/* ---------------------------------------------------------------
	 * Prompt building
	 * ------------------------------------------------------------- */

	public static function build_prompt( string $to, string $from = 'en', string $additional_prompt = '' ): string {
		$template    = get_option( 'ai_translate_prompt', AI_Translate::$PROMPT );
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

	/* ---------------------------------------------------------------
	 * Model slug override (per-ability-call scope)
	 * ------------------------------------------------------------- */

	public static function with_model_slug_override( $input, callable $callback ): mixed {
		$previous                    = self::$model_slug_override;
		self::$model_slug_override   = is_array( $input )
			&& isset( $input['model_slug'] )
			&& is_string( $input['model_slug'] )
			&& '' !== $input['model_slug']
				? sanitize_text_field( $input['model_slug'] )
				: null;

		try {
			return $callback();
		} finally {
			self::$model_slug_override = $previous;
		}
	}

	/* ---------------------------------------------------------------
	 * Main entry point
	 * ------------------------------------------------------------- */

	/**
	 * Translate text using the WordPress AI Client (or direct API).
	 *
	 * @return string|\WP_Error
	 */
	public static function translate_text( $text, string $to, string $from = 'en', string $additional_prompt = '' ): mixed {
		if ( ! $text || trim( $text ) === '' ) {
			return '';
		}

		self::$source_lang    = $from;
		self::$target_lang    = $to;
		self::$last_diagnostics = null;

		try {
			$prompt = self::build_prompt( $to, $from, $additional_prompt );
			return self::translate_with_chunk_limit( $text, $prompt, self::get_chunk_char_limit() );
		} finally {
			self::$source_lang    = null;
			self::$target_lang    = null;
			self::$last_diagnostics = null;
		}
	}

	/* ---------------------------------------------------------------
	 * Chunked translation
	 * ------------------------------------------------------------- */

	public static function translate_with_chunk_limit(
		string $text,
		string $prompt,
		int $chunk_char_limit,
		int $attempt = 0,
		?int $previous_chunk_count = null
	): mixed {
		$chunks = TextSplitter::split_text_for_translation( $text, $chunk_char_limit );

		if ( empty( $chunks ) ) {
			return '';
		}

		TranslationProgressTracker::synchronize_content_chunks( count( $chunks ), $previous_chunk_count );

		$translated_chunks = array();
		$completed_chunks  = 0;

		foreach ( $chunks as $chunk ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			$translated_chunk = self::translate_chunk( $chunk, $prompt );

			if ( is_wp_error( $translated_chunk ) ) {
				$adjusted = self::maybe_adjust_chunk_limit_from_error( $translated_chunk, $chunk_char_limit );
				if ( $adjusted > 0 && $attempt < 2 ) {
					TranslationProgressTracker::rewind_content_chunks( $completed_chunks );
					return self::translate_with_chunk_limit( $text, $prompt, $adjusted, $attempt + 1, count( $chunks ) );
				}
				return $translated_chunk;
			}

			$translated_chunks[] = $translated_chunk;
			$completed_chunks   += TranslationProgressTracker::advance_content_chunk();
		}

		return implode( '', $translated_chunks );
	}

	public static function translate_chunk( string $text, string $prompt, int $validation_attempt = 0 ): mixed {
		$runtime_context           = self::get_runtime_context();
		$model_slug                = self::$model_slug_override ?? $runtime_context['model_slug'];
		$requires_strict_direct_api = self::model_requires_strict_direct_api( $model_slug );
		$direct_api_url            = $runtime_context['direct_api_url'];
		$kwargs_supported          = self::direct_api_kwargs_supported();

		if ( $requires_strict_direct_api && is_string( $direct_api_url ) && '' !== $direct_api_url && ! $kwargs_supported ) {
			$kwargs_supported = self::refresh_direct_api_kwargs_detection( $direct_api_url, $model_slug );
		}

		if ( $requires_strict_direct_api ) {
			if ( ! is_string( $direct_api_url ) || '' === $direct_api_url ) {
				self::record_transport_diagnostics( array(
					'transport'        => 'blocked',
					'model_slug'       => $model_slug,
					'direct_api_url'   => '',
					'kwargs_supported' => false,
					'fallback_allowed' => false,
					'failure_reason'   => 'direct_api_required',
				) );

				return new \WP_Error(
					'translategemma_requires_direct_api',
					__( 'TranslateGemma requires a direct API endpoint. Configure direct_api_url for your llama.cpp server or switch to an instruct model.', 'slytranslate' )
				);
			}

			if ( ! $kwargs_supported ) {
				self::record_transport_diagnostics( array(
					'transport'        => 'blocked',
					'model_slug'       => $model_slug,
					'direct_api_url'   => $direct_api_url,
					'kwargs_supported' => false,
					'fallback_allowed' => false,
					'failure_reason'   => 'kwargs_required',
				) );

				return new \WP_Error(
					'translategemma_requires_kwargs',
					__( 'TranslateGemma requires chat_template_kwargs support on the configured direct API endpoint. Re-save the direct API settings after the server is reachable, or switch to an instruct model.', 'slytranslate' )
				);
			}
		}

		if ( is_string( $direct_api_url ) && '' !== $direct_api_url ) {
			$result = DirectApiTranslationClient::translate(
				$text,
				$prompt,
				$model_slug,
				$direct_api_url,
				$kwargs_supported,
				self::$source_lang,
				self::$target_lang
			);

			if ( null !== $result ) {
				self::record_transport_diagnostics( array(
					'transport'        => 'direct_api',
					'model_slug'       => $model_slug,
					'direct_api_url'   => $direct_api_url,
					'kwargs_supported' => $kwargs_supported,
					'fallback_allowed' => ! $requires_strict_direct_api,
					'failure_reason'   => '',
				) );
				return self::finalize_translated_chunk( $text, $result, $model_slug, $prompt, $validation_attempt );
			}

			if ( $requires_strict_direct_api ) {
				self::record_transport_diagnostics( array(
					'transport'        => 'direct_api_failed',
					'model_slug'       => $model_slug,
					'direct_api_url'   => $direct_api_url,
					'kwargs_supported' => $kwargs_supported,
					'fallback_allowed' => false,
					'failure_reason'   => 'direct_api_failed',
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

		self::record_transport_diagnostics( array(
			'transport'        => 'wp_ai_client',
			'model_slug'       => $model_slug,
			'direct_api_url'   => is_string( $direct_api_url ) ? $direct_api_url : '',
			'kwargs_supported' => $kwargs_supported,
			'fallback_allowed' => true,
			'failure_reason'   => '',
		) );

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::finalize_translated_chunk( $text, $result, $model_slug, $prompt, $validation_attempt );
	}

	private static function finalize_translated_chunk(
		string $source_text,
		string $translated_text,
		string $model_slug,
		string $prompt,
		int $validation_attempt
	): mixed {
		$validation_error = TranslationValidator::validate( $source_text, $translated_text );
		if ( is_wp_error( $validation_error ) ) {
			self::record_validation_failure_diagnostics( $validation_error );

			if ( 0 === $validation_attempt && self::should_retry_after_validation_failure( $model_slug ) ) {
				return self::translate_chunk( $source_text, self::build_retry_prompt( $prompt ), 1 );
			}

			return $validation_error;
		}

		return $translated_text;
	}

	/* ---------------------------------------------------------------
	 * Context-window heuristics
	 * ------------------------------------------------------------- */

	public static function get_chunk_char_limit(): int {
		$runtime_context      = self::get_runtime_context();
		$context_window_size  = self::get_effective_context_window_tokens();
		$chunk_char_limit     = self::get_chunk_char_limit_from_context_window( $context_window_size );

		$filtered_limit = apply_filters( 'ai_translate_chunk_char_limit', $chunk_char_limit, $context_window_size, $runtime_context );
		$filtered_limit = absint( $filtered_limit );
		if ( $filtered_limit > 0 ) {
			$chunk_char_limit = $filtered_limit;
		}

		return max( self::MIN_TRANSLATION_CHARS, min( self::MAX_TRANSLATION_CHARS, $chunk_char_limit ) );
	}

	public static function get_chunk_char_limit_from_context_window( int $context_window_tokens ): int {
		$context_window_tokens = max( self::MIN_CONTEXT_WINDOW_TOKENS, $context_window_tokens );
		$chunk_char_limit      = (int) floor( $context_window_tokens * self::SAFE_CHARS_PER_CONTEXT_TOKEN );

		return max( self::MIN_TRANSLATION_CHARS, min( self::MAX_TRANSLATION_CHARS, $chunk_char_limit ) );
	}

	public static function get_effective_context_window_tokens(): int {
		$configured = absint( get_option( 'ai_translate_context_window_tokens', 0 ) );
		if ( $configured > 0 ) {
			return max( self::MIN_CONTEXT_WINDOW_TOKENS, $configured );
		}

		$runtime_context     = self::get_runtime_context();
		$context_window_size = self::get_learned_context_window_tokens();

		if ( $context_window_size < 1 ) {
			$context_window_size = self::get_known_context_window_for_model( $runtime_context['model_slug'] );
		}

		if ( $context_window_size < 1 ) {
			$context_window_size = self::DEFAULT_CONTEXT_WINDOW_TOKENS;
		}

		$filtered = apply_filters( 'ai_translate_context_window_tokens', $context_window_size, $runtime_context );
		$filtered = absint( $filtered );
		if ( $filtered > 0 ) {
			$context_window_size = $filtered;
		}

		return max( self::MIN_CONTEXT_WINDOW_TOKENS, $context_window_size );
	}

	public static function get_runtime_context(): array {
		if ( is_array( self::$context ) ) {
			return self::$context;
		}

		self::$context = array(
			'service_slug'   => '',
			'model_slug'     => get_option( 'ai_translate_model_slug', '' ),
			'direct_api_url' => get_option( 'ai_translate_direct_api_url', '' ),
		);

		return self::$context;
	}

	public static function reset_context(): void {
		self::$context = null;
	}

	private static function get_runtime_context_cache_key(): string {
		$runtime_context = self::get_runtime_context();
		return '' === $runtime_context['model_slug'] ? '' : $runtime_context['model_slug'];
	}

	public static function get_learned_context_window_tokens(): int {
		$cache_key = self::get_runtime_context_cache_key();
		if ( '' === $cache_key ) {
			return 0;
		}

		$learned = get_option( 'ai_translate_learned_context_windows', array() );
		if ( ! is_array( $learned ) || ! isset( $learned[ $cache_key ] ) ) {
			return 0;
		}

		return absint( $learned[ $cache_key ] );
	}

	private static function remember_context_window_tokens( int $context_window_tokens ): void {
		$cache_key = self::get_runtime_context_cache_key();
		if ( '' === $cache_key ) {
			return;
		}

		$context_window_tokens = absint( $context_window_tokens );
		if ( $context_window_tokens < self::MIN_CONTEXT_WINDOW_TOKENS ) {
			return;
		}

		$learned = get_option( 'ai_translate_learned_context_windows', array() );
		if ( ! is_array( $learned ) ) {
			$learned = array();
		}
		$learned[ $cache_key ] = $context_window_tokens;
		update_option( 'ai_translate_learned_context_windows', $learned, false );
	}

	public static function get_known_context_window_for_model( string $model_slug ): int {
		if ( '' === $model_slug ) {
			return 0;
		}

		$model_slug = strtolower( $model_slug );
		foreach ( self::KNOWN_MODEL_CONTEXT_WINDOWS as $needle => $tokens ) {
			if ( false !== strpos( $model_slug, $needle ) ) {
				return $tokens;
			}
		}

		return 0;
	}

	public static function maybe_adjust_chunk_limit_from_error( \WP_Error $error, int $chunk_char_limit ): int {
		$context_window_tokens = self::extract_context_window_tokens_from_error( $error );
		if ( $context_window_tokens < 1 ) {
			return 0;
		}

		self::remember_context_window_tokens( $context_window_tokens );

		$adjusted = self::get_chunk_char_limit_from_context_window( $context_window_tokens );
		if ( $adjusted >= $chunk_char_limit ) {
			$adjusted = (int) floor( $chunk_char_limit / 2 );
		}

		$adjusted = max( self::MIN_TRANSLATION_CHARS, min( $chunk_char_limit - 1, $adjusted ) );

		return $adjusted >= $chunk_char_limit ? 0 : $adjusted;
	}

	public static function extract_context_window_tokens_from_error( \WP_Error $error ): int {
		foreach ( $error->get_error_messages() as $message ) {
			if ( preg_match( '/context size \((\d+) tokens\)|maximum context length is (\d+) tokens|context window(?: of)? (\d+) tokens/i', $message, $matches ) ) {
				foreach ( array_slice( $matches, 1 ) as $match ) {
					$tokens = absint( $match );
					if ( $tokens > 0 ) {
						return $tokens;
					}
				}
			}
		}
		return 0;
	}

	/* ---------------------------------------------------------------
	 * Model / direct-API detection
	 * ------------------------------------------------------------- */

	public static function model_requires_strict_direct_api( string $model_slug ): bool {
		return '' !== $model_slug && false !== strpos( strtolower( $model_slug ), 'translategemma' );
	}

	public static function direct_api_kwargs_supported(): bool {
		return get_option( 'ai_translate_direct_api_kwargs_detected', '0' ) === '1';
	}

	public static function refresh_direct_api_kwargs_detection( string $api_url, string $model_slug ): bool {
		$probe_result = ConfigurationService::probe_direct_api_kwargs( $api_url, $model_slug );
		update_option( 'ai_translate_direct_api_kwargs_detected', $probe_result ? '1' : '0' );
		update_option( 'ai_translate_direct_api_kwargs_last_probed_at', time(), false );
		return $probe_result;
	}

	public static function get_translategemma_runtime_status(): array {
		$runtime_context = self::get_runtime_context();
		$model_slug      = self::$model_slug_override ?? $runtime_context['model_slug'];

		if ( ! self::model_requires_strict_direct_api( $model_slug ) ) {
			return array( 'ready' => true, 'status' => 'not-selected' );
		}

		if ( '' === $runtime_context['direct_api_url'] ) {
			return array( 'ready' => false, 'status' => 'direct-api-required' );
		}

		if ( ! self::direct_api_kwargs_supported() ) {
			return array( 'ready' => false, 'status' => 'kwargs-required' );
		}

		return array( 'ready' => true, 'status' => 'ready' );
	}

	/* ---------------------------------------------------------------
	 * Diagnostics
	 * ------------------------------------------------------------- */

	public static function record_transport_diagnostics( array $diagnostics ): void {
		self::$last_diagnostics = $diagnostics;
	}

	public static function get_last_diagnostics(): ?array {
		return self::$last_diagnostics;
	}

	public static function get_source_lang(): ?string {
		return self::$source_lang;
	}

	public static function get_target_lang(): ?string {
		return self::$target_lang;
	}

	private static function record_validation_failure_diagnostics( \WP_Error $error ): void {
		$diagnostics = is_array( self::$last_diagnostics )
			? self::$last_diagnostics
			: array(
				'transport'        => 'unknown',
				'model_slug'       => '',
				'direct_api_url'   => '',
				'kwargs_supported' => false,
				'fallback_allowed' => true,
			);

		$diagnostics['failure_reason'] = $error->get_error_code();
		self::record_transport_diagnostics( $diagnostics );
	}

	/* ---------------------------------------------------------------
	 * Retry / validation helpers
	 * ------------------------------------------------------------- */

	private static function should_retry_after_validation_failure( string $model_slug ): bool {
		return ! self::model_requires_strict_direct_api( $model_slug );
	}

	private static function build_retry_prompt( string $prompt ): string {
		return $prompt . "\n\nCRITICAL: Return only the translated content. Preserve HTML tags, Gutenberg block comments, URLs, and code fences exactly. Do not add explanations, bullet lists, markdown headings, or commentary.";
	}
}
