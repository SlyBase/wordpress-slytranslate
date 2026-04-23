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
	// Hard upper bound for chunk size in characters. Sized to let modern
	// hosted LLMs (Groq, OpenAI, Anthropic, Gemini, Mistral, xAI, …) batch
	// translations in far fewer requests so per-minute rate limits stop
	// firing on longer pages, while still leaving enough context-window
	// headroom for prompt overhead and the translated output. Local
	// llama.cpp / ollama endpoints with smaller context windows remain
	// naturally clamped by `get_chunk_char_limit_from_context_window()`
	// (context_window_tokens * SAFE_CHARS_PER_CONTEXT_TOKEN) below this
	// ceiling, so this bump does not affect them.
	//
	// The ceiling is deliberately high enough that for 128K+ context
	// models, a whole medium post fits into a single request (fewer RPM
	// hits on hosted providers like Groq). Output-side headroom is
	// handled separately by `compute_max_output_tokens()` (capped at
	// `MAX_OUTPUT_TOKENS_CEILING`), so raising this value without also
	// raising the output ceiling would truncate the model response.
	private const MAX_TRANSLATION_CHARS         = 48000;
	private const SAFE_CHARS_PER_CONTEXT_TOKEN  = 0.5;
	// Upper bound for `max_tokens` / `max_output_tokens` per request.
	// Scaled to MAX_TRANSLATION_CHARS so a chunk that fills the char
	// ceiling can emit a full-size translation. Formula:
	//   MAX_TRANSLATION_CHARS (48 000) × 0.5 tokens/char ≈ 24 000 input tokens
	//   × 1.35 growth headroom                            ≈ 32 400 output tokens
	// → rounded up to 32 768 (power of 2).
	// Filter: `ai_translate_max_output_tokens_ceiling`.
	private const MAX_OUTPUT_TOKENS_CEILING     = 32768;
	private const MIN_OUTPUT_TOKENS             = 256;
	private const KNOWN_MODEL_CONTEXT_WINDOWS   = array(
		// ── Anthropic Claude ──────────────────────────────────────────────
		// All current Claude models (3.x, 3.5, 3.7, 4.x) share 200 K context.
		'claude'          => 200000,

		// ── Google Gemini ─────────────────────────────────────────────────
		'gemini-2.5'      => 1000000,
		'gemini-2.0'      => 1000000,
		'gemini-1.5'      => 1000000,

		// ── OpenAI GPT ────────────────────────────────────────────────────
		// GPT-5 family (gpt-5, gpt-5.x) – context TBD, 200 K assumed.
		'gpt-5'           => 200000,
		'gpt-4.5'         => 128000,
		// GPT-4.1 / 4.1-mini / 4.1-nano ship with a 1 M (1 047 576) context.
		'gpt-4.1'         => 1048576,
		// gpt-4o-mini must precede gpt-4o so it is matched first.
		'gpt-4o-mini'     => 128000,
		'gpt-4o'          => 128000,
		'gpt-4-turbo'     => 128000,
		'gpt-3.5-turbo'   => 16385,

		// ── OpenAI reasoning models (200 K context) ───────────────────────
		// List specific variants before their generic prefixes.
		'o4-mini'         => 200000,
		'o3-mini'         => 200000,
		'o3'              => 200000,
		'o1-mini'         => 128000,
		'o1'              => 200000,

		// ── xAI Grok ──────────────────────────────────────────────────────
		'grok'            => 131072,

		// ── Mistral AI ────────────────────────────────────────────────────
		'codestral'       => 32768,
		'pixtral'         => 128000,
		'mistral-nemo'    => 131072,
		'mistral-large'   => 131072,  // Mistral Large 2 (2024): 128 K context.
		'mistral-small'   => 32768,

		// ── Perplexity Sonar ──────────────────────────────────────────────
		'sonar'           => 32768,

		// ── Meta Llama ────────────────────────────────────────────────────
		// Substring-based matching covers provider-prefixed slugs such as
		// `meta-llama/Llama-3.3-70b` or `llama-3.3-70b-versatile`.
		'llama-4'         => 131072,
		'llama-3.3'       => 131072,
		'llama-3.2'       => 131072,
		'llama-3.1'       => 131072,
		'llama-3'         => 8192,

		// ── Mistral Mixtral ───────────────────────────────────────────────
		'mixtral'         => 32768,

		// ── Alibaba Qwen ──────────────────────────────────────────────────
		// Both `qwen2.5` and `qwen-2.5` appear in practice.
		'qwen3'           => 131072,
		'qwen2.5'         => 131072,
		'qwen-2.5'        => 131072,
		'qwen'            => 32768,

		// ── DeepSeek ──────────────────────────────────────────────────────
		'deepseek'        => 131072,

		// ── Microsoft Phi ─────────────────────────────────────────────────
		// More specific variants must precede the generic `phi` entry.
		'phi-4-mini'      => 131072,
		'phi-4'           => 16384,
		'phi-3.5'         => 131072,
		'phi-3'           => 131072,
		'phi'             => 16384,

		// ── Zhipu GLM (GLM-4, GLM-Z1, …) ────────────────────────────────
		'glm'             => 131072,

		// ── Moonshot / Kimi ───────────────────────────────────────────────
		'moonshot'        => 131072,
		'kimi'            => 131072,

		// ── 01.AI Yi ──────────────────────────────────────────────────────
		'yi'              => 200000,

		// ── Shanghai AI Lab InternLM ──────────────────────────────────────
		'internlm'        => 1000000,

		// ── Cohere Command ────────────────────────────────────────────────
		// command-r must precede the generic command entry.
		'command-r'       => 128000,
		'command'         => 128000,

		// ── Nous Hermes (popular community fine-tunes) ────────────────────
		'hermes'          => 131072,

		// ── Falcon (TII) ─────────────────────────────────────────────────
		'falcon'          => 8192,

		// ── Baichuan ─────────────────────────────────────────────────────
		'baichuan'        => 131072,

		// ── Google Gemma ─────────────────────────────────────────────────
		// translategemma must precede the generic gemma entries.
		'translategemma'  => 8192,
		'gemma-4'         => 131072,
		'gemma-3'         => 131072,
		'gemma'           => 8192,
	);

	/* ---------------------------------------------------------------
	 * Per-request state
	 * ------------------------------------------------------------- */

	/** Cached runtime context (model slug, direct API URL). */
	private static $context = null;

	/** Cached chunk char limit for the duration of one PHP request. */
	private static ?int $chunk_char_limit_cache = null;

	/** Per-request model slug override set by with_model_slug_override(). */
	private static $model_slug_override = null;

	/** Source / target language codes set inside translate_text() for use by DirectApiTranslationClient. */
	private static $source_lang = null;
	private static $target_lang = null;

	/** Diagnostics from the most recent chunk transport call. */
	private static $last_diagnostics = null;

	/** When true, TranslationValidator skips the HTML tag count and URL count checks. */
	private static $skip_html_tag_validation = false;

	/** Recursion guard for rate-limit (HTTP 429) retries inside a single chunk call. */
	private static int $rate_limit_retry_depth = 0;
	private const RATE_LIMIT_MAX_RETRIES   = 3;
	private const RATE_LIMIT_MAX_SLEEP_SEC = 30;
	private const RATE_LIMIT_MIN_SLEEP_SEC = 1;

	/* ---------------------------------------------------------------
	 * HTML tag validation bypass (used by ContentTranslator)
	 * ------------------------------------------------------------- */

	public static function set_skip_html_tag_validation( bool $skip ): void {
		self::$skip_html_tag_validation = $skip;
	}

	public static function should_skip_html_tag_validation(): bool {
		return self::$skip_html_tag_validation;
	}

	/* ---------------------------------------------------------------
	 * Prompt building
	 * ------------------------------------------------------------- */

	public static function build_prompt( string $to, string $from = 'en', string $additional_prompt = '' ): string {
		$template    = get_option( 'ai_translate_prompt', AI_Translate::get_default_prompt() );
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
			$parts[] = 'Additional style instructions (do NOT translate these lines, apply them to the user-provided content): ' . trim( $additional_prompt );
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

		// Chunk limit depends on model slug (context-window lookup).
		// Clear the cache so the new model gets the correct limit.
		self::$chunk_char_limit_cache = null;

		try {
			return $callback();
		} finally {
			self::$model_slug_override    = $previous;
			self::$chunk_char_limit_cache = null;
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

		$translated_chunks    = array();
		$completed_unit_count = 0;

		foreach ( $chunks as $chunk ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			$translated_chunk = self::translate_chunk( $chunk, $prompt );

			if ( is_wp_error( $translated_chunk ) ) {
				$adjusted = self::maybe_adjust_chunk_limit_from_error( $translated_chunk, $chunk_char_limit );
				if ( $adjusted > 0 && $attempt < 2 ) {
					// Re-attempt with smaller chunk size; do NOT roll back the
					// already-credited progress because the upcoming retry
					// will re-translate the same source text and credit it
					// again \u2014 capping at the phase budget keeps the bar
					// monotonic regardless.
					return self::translate_with_chunk_limit( $text, $prompt, $adjusted, $attempt + 1, count( $chunks ) );
				}
				return $translated_chunk;
			}

			$translated_chunks[] = $translated_chunk;

			$chunk_units = self::char_length( $chunk );
			if ( $chunk_units > 0 ) {
				$active_phase = TranslationProgressTracker::current_phase();
				if ( '' !== $active_phase && 'saving' !== $active_phase && 'done' !== $active_phase ) {
					TranslationProgressTracker::advance_units( $active_phase, $chunk_units );
					$completed_unit_count += $chunk_units;
				}
			}
		}

		return implode( '', $translated_chunks );
	}

	private static function char_length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text, 'UTF-8' );
		}
		return strlen( $text );
	}

	/**
	 * Return a single-line, length-bounded excerpt of $text suitable for
	 * inclusion in error_log lines. Used by validation diagnostics so we
	 * can see what the model actually produced when something fails.
	 */
	private static function truncate_for_log( string $text, int $max = 240 ): string {
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		$text = trim( (string) $text );
		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $text, 'UTF-8' ) > $max ) {
				$text = mb_substr( $text, 0, $max, 'UTF-8' ) . '…';
			}
		} elseif ( strlen( $text ) > $max ) {
			$text = substr( $text, 0, $max ) . '…';
		}
		return $text;
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
				$error_message = __( 'TranslateGemma requires a direct API endpoint. Configure direct_api_url for your llama.cpp server or switch to an instruct model.', 'slytranslate' );
				self::record_transport_diagnostics( array(
					'transport'        => 'blocked',
					'model_slug'       => $model_slug,
					'direct_api_url'   => '',
					'kwargs_supported' => false,
					'fallback_allowed' => false,
					'failure_reason'   => 'direct_api_required',
					'error_code'       => 'translategemma_requires_direct_api',
					'error_message'    => $error_message,
				) );

				return new \WP_Error(
					'translategemma_requires_direct_api',
					$error_message
				);
			}

			if ( ! $kwargs_supported ) {
				$error_message = __( 'TranslateGemma requires chat_template_kwargs support on the configured direct API endpoint. Re-save the direct API settings after the server is reachable, or switch to an instruct model.', 'slytranslate' );
				self::record_transport_diagnostics( array(
					'transport'        => 'blocked',
					'model_slug'       => $model_slug,
					'direct_api_url'   => $direct_api_url,
					'kwargs_supported' => false,
					'fallback_allowed' => false,
					'failure_reason'   => 'kwargs_required',
					'error_code'       => 'translategemma_requires_kwargs',
					'error_message'    => $error_message,
				) );

				return new \WP_Error(
					'translategemma_requires_kwargs',
					$error_message
				);
			}
		}

		$input_chars = self::char_length( $text );
		$max_output_tokens = self::compute_max_output_tokens( $input_chars );

		// Use the direct API only when explicitly opted in: either the model
		// requires it (TranslateGemma via chat_template_kwargs) or the user
		// explicitly set force_direct_api='1'. The model slug must be non-empty
		// because the direct API endpoint requires an explicit model name.
		if ( self::should_use_direct_api( $model_slug, (string) $direct_api_url ) ) {
			$direct_started = TimingLogger::start();
			$result         = DirectApiTranslationClient::translate(
				$text,
				$prompt,
				$model_slug,
				$direct_api_url,
				$kwargs_supported,
				self::$source_lang,
				self::$target_lang,
				$max_output_tokens
			);
			$direct_duration_ms = TimingLogger::stop( $direct_started );
			TimingLogger::increment( 'ai_calls' );

			if ( is_wp_error( $result ) ) {
				$direct_error_code = (string) $result->get_error_code();
				TimingLogger::log( 'ai_call', array(
					'transport'   => 'direct',
					'model'       => $model_slug,
					'input_chars' => $input_chars,
					'output_chars' => 0,
					'duration_ms' => $direct_duration_ms,
					'attempt'     => $validation_attempt,
					'ok'          => false,
					'reason'      => 'direct_api_rate_limited' === $direct_error_code ? 'rate_limited' : 'connection_error',
				) );

				// HTTP 429 from the direct endpoint: the server is healthy,
				// it is just asking us to slow down. Parse the retry-after
				// hint, pause, and recurse. Falling back to the WP AI Client
				// would hit the same provider and trip the same per-minute
				// limit, so the sleep-and-retry path is strictly better.
				if ( 'direct_api_rate_limited' === $direct_error_code ) {
					self::record_transport_diagnostics( array(
						'transport'        => 'direct_api_failed',
						'model_slug'       => $model_slug,
						'direct_api_url'   => $direct_api_url,
						'kwargs_supported' => $kwargs_supported,
						'fallback_allowed' => ! $requires_strict_direct_api,
						'failure_reason'   => $direct_error_code,
						'error_code'       => $direct_error_code,
						'error_message'    => $result->get_error_message(),
					) );
					return self::handle_rate_limit_and_retry( $result, $text, $prompt, $validation_attempt, $model_slug );
				}

				// Strict-direct-API models (e.g. TranslateGemma) cannot fall
				// back to the WP AI Client because they require chat-template
				// kwargs that the generic Client transport does not expose.
				if ( $requires_strict_direct_api ) {
					self::record_transport_diagnostics( array(
						'transport'        => 'direct_api_failed',
						'model_slug'       => $model_slug,
						'direct_api_url'   => $direct_api_url,
						'kwargs_supported' => $kwargs_supported,
						'fallback_allowed' => false,
						'failure_reason'   => 'direct_api_connection_error',
						'error_code'       => $direct_error_code,
						'error_message'    => $result->get_error_message(),
					) );
					return $result;
				}

				// For all other models, a single timeout / connection drop on
				// the direct API must not tear down the entire content phase
				// — the endpoint is typically healthy again on the very next
				// chunk. Fall back to the WP AI Client transport for THIS
				// chunk only and let subsequent chunks try the direct path
				// again.
				TimingLogger::increment( 'fallbacks' );
				TimingLogger::log( 'ai_call_fallback', array(
					'from'   => 'direct',
					'to'     => 'wp_ai_client',
					'reason' => 'direct_api_connection_error',
					'model'  => $model_slug,
				) );
				self::record_transport_diagnostics( array(
					'transport'        => 'direct_api_failed',
					'model_slug'       => $model_slug,
					'direct_api_url'   => $direct_api_url,
					'kwargs_supported' => $kwargs_supported,
					'fallback_allowed' => true,
					'failure_reason'   => 'direct_api_connection_error',
				) );
			} elseif ( is_string( $result ) ) {
				TimingLogger::log( 'ai_call', array(
					'transport'    => 'direct',
					'model'        => $model_slug,
					'input_chars'  => $input_chars,
					'output_chars' => self::char_length( $result ),
					'duration_ms'  => $direct_duration_ms,
					'attempt'      => $validation_attempt,
					'ok'           => true,
				) );
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

			TimingLogger::log( 'ai_call', array(
				'transport'    => 'direct',
				'model'        => $model_slug,
				'input_chars'  => $input_chars,
				'output_chars' => 0,
				'duration_ms'  => $direct_duration_ms,
				'attempt'      => $validation_attempt,
				'ok'           => false,
				'reason'       => 'non_2xx_or_empty',
			) );

			if ( $requires_strict_direct_api ) {
				$error_message = __( 'TranslateGemma direct API request failed. SlyTranslate did not fall back to the WordPress AI Client because TranslateGemma requires chat_template_kwargs for reliable translations.', 'slytranslate' );
				self::record_transport_diagnostics( array(
					'transport'        => 'direct_api_failed',
					'model_slug'       => $model_slug,
					'direct_api_url'   => $direct_api_url,
					'kwargs_supported' => $kwargs_supported,
					'fallback_allowed' => false,
					'failure_reason'   => 'direct_api_failed',
					'error_code'       => 'translategemma_direct_api_failed',
					'error_message'    => $error_message,
				) );

				return new \WP_Error(
					'translategemma_direct_api_failed',
					$error_message
				);
			}

			TimingLogger::increment( 'fallbacks' );
			TimingLogger::log( 'ai_call_fallback', array(
				'from'   => 'direct',
				'to'     => 'wp_ai_client',
				'reason' => 'direct_api_returned_null',
				'model'  => $model_slug,
			) );
		}

		$wp_started = TimingLogger::start();
		$builder = wp_ai_client_prompt( $text )
			->using_system_instruction( $prompt )
			->using_temperature( 0 );

		if ( '' !== $model_slug && is_callable( array( $builder, 'using_model_preference' ) ) ) {
			$builder = $builder->using_model_preference( $model_slug );
		}

		if ( is_callable( array( $builder, 'using_max_tokens' ) ) ) {
			$builder = $builder->using_max_tokens( $max_output_tokens );
		} elseif ( is_callable( array( $builder, 'using_max_output_tokens' ) ) ) {
			$builder = $builder->using_max_output_tokens( $max_output_tokens );
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

		$wp_duration_ms = TimingLogger::stop( $wp_started );
		TimingLogger::increment( 'ai_calls' );

		if ( is_wp_error( $result ) ) {
			self::record_transport_diagnostics( array(
				'transport'        => 'wp_ai_client',
				'model_slug'       => $model_slug,
				'direct_api_url'   => is_string( $direct_api_url ) ? $direct_api_url : '',
				'kwargs_supported' => $kwargs_supported,
				'fallback_allowed' => true,
				'failure_reason'   => $result->get_error_code(),
				'error_code'       => $result->get_error_code(),
				'error_message'    => $result->get_error_message(),
			) );

			TimingLogger::log( 'ai_call', array(
				'transport'    => 'wp_ai_client',
				'model'        => $model_slug,
				'input_chars'  => $input_chars,
				'output_chars' => 0,
				'duration_ms'  => $wp_duration_ms,
				'attempt'      => $validation_attempt,
				'ok'           => false,
				'reason'       => $result->get_error_code(),
			) );

			if ( self::is_rate_limit_error( $result ) ) {
				return self::handle_rate_limit_and_retry( $result, $text, $prompt, $validation_attempt, $model_slug );
			}

			return $result;
		}

		TimingLogger::log( 'ai_call', array(
			'transport'    => 'wp_ai_client',
			'model'        => $model_slug,
			'input_chars'  => $input_chars,
			'output_chars' => is_string( $result ) ? self::char_length( $result ) : 0,
			'duration_ms'  => $wp_duration_ms,
			'attempt'      => $validation_attempt,
			'ok'           => true,
		) );

		return self::finalize_translated_chunk( $text, $result, $model_slug, $prompt, $validation_attempt );
	}

	/**
	 * Unwrap pseudo-XML translations like `<responsible>` or
	 * `<communication-partner>` that small models (notably Phi-4-mini)
	 * occasionally emit when asked to translate single noun-like inputs.
	 * The model intends the inner identifier as the translation but wraps
	 * it in angle brackets, which `wp_strip_all_tags()` then removes,
	 * making the validator see "no plain text" and the entire content
	 * phase abort over a single short word.
	 *
	 * Only triggered when the source itself contained no `<` characters
	 * (so we don't strip legitimate HTML the model echoed back), the
	 * output trims to one bare tag with no attributes, and the tag name
	 * looks like a single word or hyphenated identifier (letters and
	 * single hyphens only). The unwrapped form replaces hyphens with
	 * spaces — `<communication-partner>` becomes `communication partner`.
	 */
	private static function unwrap_pseudo_tag_translation( string $source_text, string $translated_text ): string {
		$trimmed = trim( $translated_text );
		if ( '' === $trimmed ) {
			return $translated_text;
		}

		if ( false !== strpos( $source_text, '<' ) ) {
			return $translated_text;
		}

		if ( ! preg_match( '/^<([A-Za-z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*)>$/', $trimmed, $match ) ) {
			return $translated_text;
		}

		return str_replace( '-', ' ', $match[1] );
	}

	private static function finalize_translated_chunk(
		string $source_text,
		string $translated_text,
		string $model_slug,
		string $prompt,
		int $validation_attempt
	): mixed {
		$translated_text  = self::unwrap_pseudo_tag_translation( $source_text, $translated_text );
		$translated_text  = TranslationValidator::normalize_symbol_notation( $source_text, $translated_text );
		$validation_error = TranslationValidator::validate( $source_text, $translated_text );
		if ( is_wp_error( $validation_error ) ) {
			self::record_validation_failure_diagnostics( $validation_error );

			TimingLogger::log( 'ai_validation_failed', array(
				'model'           => $model_slug,
				'reason'          => $validation_error->get_error_code(),
				'attempt'         => $validation_attempt,
				'source_chars'    => self::char_length( $source_text ),
				'output_chars'    => self::char_length( $translated_text ),
				'source_excerpt'  => self::truncate_for_log( $source_text ),
				'output_excerpt'  => self::truncate_for_log( $translated_text ),
			) );

			if ( 0 === $validation_attempt && self::should_retry_after_validation_failure( $model_slug ) ) {
				TimingLogger::increment( 'retries' );
				TimingLogger::log( 'ai_validation_retry', array(
					'model'  => $model_slug,
					'reason' => $validation_error->get_error_code(),
				) );
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
		if ( null !== self::$chunk_char_limit_cache ) {
			return self::$chunk_char_limit_cache;
		}

		$runtime_context      = self::get_runtime_context();
		$context_window_size  = self::get_effective_context_window_tokens();
		$chunk_char_limit     = self::get_chunk_char_limit_from_context_window( $context_window_size );

		$filtered_limit = apply_filters( 'ai_translate_chunk_char_limit', $chunk_char_limit, $context_window_size, $runtime_context );
		$filtered_limit = absint( $filtered_limit );
		if ( $filtered_limit > 0 ) {
			$chunk_char_limit = $filtered_limit;
		}

		self::$chunk_char_limit_cache = max( self::MIN_TRANSLATION_CHARS, min( self::MAX_TRANSLATION_CHARS, $chunk_char_limit ) );
		return self::$chunk_char_limit_cache;
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

		// Opportunistic: if a direct API endpoint is configured but we have
		// not yet recorded a context window for the active model, probe the
		// endpoint's model list once per day for OpenAI-compatible
		// `context_window` / `meta.n_ctx_train` fields. This lets hosted
		// providers (Groq, OpenRouter, …) and local servers (llama.cpp,
		// llama-swap, vLLM) advertise their actual capacity without the
		// plugin having to ship a hardcoded model-name table.
		if ( $context_window_size < 1
			&& '' !== $runtime_context['direct_api_url']
			&& '' !== $runtime_context['model_slug']
			&& self::maybe_autoprobe_direct_api_context_windows( $runtime_context['direct_api_url'] )
		) {
			$context_window_size = self::get_learned_context_window_tokens();
		}

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

	/**
	 * Run `ConfigurationService::probe_and_remember_direct_api_context_windows()`
	 * at most once per day for a given endpoint. Returns true when the probe
	 * actually ran (so the caller knows to re-read the learned window).
	 */
	private static function maybe_autoprobe_direct_api_context_windows( string $api_url ): bool {
		$last_probed_at = (int) get_option( 'ai_translate_direct_api_models_last_probed_at', 0 );
		if ( $last_probed_at > 0 && ( time() - $last_probed_at ) < DAY_IN_SECONDS ) {
			return false;
		}

		// Record the attempt before probing so a failing endpoint does not
		// get re-hit on every translation request.
		update_option( 'ai_translate_direct_api_models_last_probed_at', time(), false );
		ConfigurationService::probe_and_remember_direct_api_context_windows( $api_url );

		return true;
	}

	public static function get_runtime_context(): array {
		if ( is_array( self::$context ) ) {
			return self::$context;
		}

		$direct_api_url = (string) get_option( 'ai_translate_direct_api_url', '' );
		$model_slug     = (string) get_option( 'ai_translate_model_slug', '' );

		// Final fallback in the model-selection hierarchy:
		//   1. per-call override (with_model_slug_override())
		//   2. plugin DB option ai_translate_model_slug (handled above)
		//   3. AI Client connector default (handled here)
		//
		// The WP AI Client itself does not always pass a connector's default
		// model id down to the OpenAI-compatible HTTP request, which on
		// llama-swap / llama.cpp setups results in an empty `model` field and
		// the endpoint serving whatever model happens to be currently loaded —
		// silently overriding the user's connector choice.
		//
		// To stay connector-agnostic (so we work with Ultimate AI Connector,
		// the official OpenAI connector, Anthropic, Google, or any other
		// plugin that registers itself with the WP AI Client) we resolve the
		// default by asking the AI Client registry for the first model that
		// matches the basic text-generation requirements — the same call
		// EditorBootstrap uses to populate the editor's model dropdown. The
		// `slytranslate_default_model_slug` filter remains available so
		// integrations can override the default explicitly.
		if ( '' === $model_slug ) {
			$model_slug = (string) apply_filters(
				'slytranslate_default_model_slug',
				self::resolve_first_available_model_slug()
			);
		}

		self::$context = array(
			'service_slug'   => '',
			'model_slug'     => $model_slug,
			'direct_api_url' => $direct_api_url,
		);

		return self::$context;
	}

	/**
	 * Return the first model id the WP AI Client registry currently exposes
	 * for text-generation requests, or '' if discovery is not possible. This
	 * mirrors what `wp_ai_client_prompt()->generate_text()` would internally
	 * route to when no explicit model preference is provided, so it is the
	 * closest thing the WP AI Client offers to a "connector default model".
	 */
	private static function resolve_first_available_model_slug(): string {
		if ( ! class_exists( '\\WordPress\\AiClient\\AiClient' )
			|| ! class_exists( '\\WordPress\\AiClient\\Providers\\Models\\DTO\\ModelRequirements' ) ) {
			return '';
		}

		try {
			$registry         = \WordPress\AiClient\AiClient::defaultRegistry();
			$requirements     = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements( array(), array() );
			$provider_results = $registry->findModelsMetadataForSupport( $requirements );
		} catch ( \Throwable $e ) {
			return '';
		}

		foreach ( $provider_results as $provider_models ) {
			foreach ( $provider_models->getModels() as $model_meta ) {
				$id = (string) $model_meta->getId();
				if ( '' !== $id ) {
					return $id;
				}
			}
		}

		return '';
	}

	public static function reset_context(): void {
		self::$context              = null;
		self::$chunk_char_limit_cache = null;
	}

	public static function get_requested_model_slug(): string {
		if ( is_string( self::$model_slug_override ) && '' !== self::$model_slug_override ) {
			return self::$model_slug_override;
		}

		$runtime_context = self::get_runtime_context();
		return (string) ( $runtime_context['model_slug'] ?? '' );
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
		if ( ! is_array( $learned ) ) {
			return 0;
		}

		if ( isset( $learned[ $cache_key ] ) ) {
			return absint( $learned[ $cache_key ] );
		}

		// Fall back to a case-insensitive lookup so values discovered via
		// `GET /v1/models` (stored lower-cased by
		// ConfigurationService::probe_direct_api_context_windows()) are
		// still found when the configured model slug uses mixed case.
		$lower = strtolower( $cache_key );
		if ( isset( $learned[ $lower ] ) ) {
			return absint( $learned[ $lower ] );
		}

		return 0;
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

	/**
	 * Returns true when the direct API should be used for the given model
	 * and endpoint URL.
	 *
	 * The direct API is activated in two cases:
	 *  1. The model requires it (TranslateGemma via chat_template_kwargs).
	 *  2. The user opted in via `ai_translate_force_direct_api = '1'`
	 *     AND an explicit model slug is set (empty slug → HTTP 400 on most
	 *     servers).
	 */
	private static function should_use_direct_api( string $model_slug, string $direct_api_url ): bool {
		if ( '' === $direct_api_url ) {
			return false;
		}
		if ( self::model_requires_strict_direct_api( $model_slug ) ) {
			return true;
		}
		return '' !== $model_slug && '1' === get_option( 'ai_translate_force_direct_api', '0' );
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
		$defaults = array(
			'transport'            => '',
			'model_slug'           => '',
			'requested_model_slug' => '',
			'effective_model_slug' => '',
			'direct_api_url'       => '',
			'kwargs_supported'     => false,
			'fallback_allowed'     => true,
			'failure_reason'       => '',
			'error_code'           => '',
			'error_message'        => '',
		);

		$diagnostics = array_merge( $defaults, $diagnostics );

		if ( '' === $diagnostics['model_slug'] ) {
			$diagnostics['model_slug'] = (string) ( $diagnostics['requested_model_slug'] ?: $diagnostics['effective_model_slug'] );
		}

		if ( '' === $diagnostics['requested_model_slug'] ) {
			$diagnostics['requested_model_slug'] = (string) $diagnostics['model_slug'];
		}

		if ( '' === $diagnostics['effective_model_slug'] ) {
			$diagnostics['effective_model_slug'] = (string) $diagnostics['model_slug'];
		}

		self::$last_diagnostics = $diagnostics;
	}

	public static function get_last_diagnostics(): ?array {
		return self::$last_diagnostics;
	}

	public static function get_last_diagnostics_snapshot(): array {
		return is_array( self::$last_diagnostics ) ? self::$last_diagnostics : array(
			'transport'            => '',
			'model_slug'           => '',
			'requested_model_slug' => '',
			'effective_model_slug' => '',
			'direct_api_url'       => '',
			'kwargs_supported'     => false,
			'fallback_allowed'     => true,
			'failure_reason'       => '',
			'error_code'           => '',
			'error_message'        => '',
		);
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
				'transport'            => 'unknown',
				'model_slug'           => '',
				'requested_model_slug' => '',
				'effective_model_slug' => '',
				'direct_api_url'       => '',
				'kwargs_supported'     => false,
				'fallback_allowed'     => true,
				'error_code'           => '',
				'error_message'        => '',
			);

		$diagnostics['failure_reason'] = $error->get_error_code();
		$diagnostics['error_code']     = $error->get_error_code();
		$diagnostics['error_message']  = $error->get_error_message();
		self::record_transport_diagnostics( $diagnostics );
	}

	/* ---------------------------------------------------------------
	 * Retry / validation helpers
	 * ------------------------------------------------------------- */

	private static function should_retry_after_validation_failure( string $model_slug ): bool {
		return ! self::model_requires_strict_direct_api( $model_slug );
	}

	/**
	 * Compute a conservative max-output-token budget for a single chunk.
	 *
	 * Translations rarely exceed ~2x the source length, so we cap output
	 * generation at roughly 2x the input token estimate (4 chars/token).
	 * This is the primary safeguard against runaway generation that was
	 * observed live: 763 input chars producing 23.000 output chars.
	 *
	 * The ceiling (`MAX_OUTPUT_TOKENS_CEILING`) scales with
	 * `MAX_TRANSLATION_CHARS` so a chunk that fills the char ceiling is
	 * still allowed to emit a same-size translation instead of being
	 * truncated mid-sentence (which previously surfaced as a
	 * length-drift validator failure). Operators can tune both the
	 * floor and the ceiling via the `ai_translate_max_output_tokens`
	 * and `ai_translate_max_output_tokens_ceiling` filters.
	 *
	 * Public for unit testing.
	 */
	public static function compute_max_output_tokens( int $input_chars ): int {
		// 4 chars/token average × 2x growth headroom = input_chars * 0.5 tokens.
		$tokens = (int) ceil( max( 1, $input_chars ) * 0.5 );

		$ceiling = (int) apply_filters( 'ai_translate_max_output_tokens_ceiling', self::MAX_OUTPUT_TOKENS_CEILING, $input_chars );
		if ( $ceiling < self::MIN_OUTPUT_TOKENS ) {
			$ceiling = self::MAX_OUTPUT_TOKENS_CEILING;
		}

		$computed = (int) min( $ceiling, max( self::MIN_OUTPUT_TOKENS, $tokens ) );

		$filtered = (int) apply_filters( 'ai_translate_max_output_tokens', $computed, $input_chars, $ceiling );
		if ( $filtered < self::MIN_OUTPUT_TOKENS ) {
			return $computed;
		}

		return (int) min( $ceiling, $filtered );
	}

	/**
	 * Validation error codes that should trigger an automatic retry even
	 * when the first attempt already used the standard prompt.
	 */
	public static function is_retryable_validation_error_code( string $code ): bool {
		return in_array(
			$code,
			array(
				'invalid_translation_assistant_reply',
				'invalid_translation_length_drift',
				'invalid_translation_runaway_output',
				'invalid_translation_structure_drift',
				'invalid_translation_empty',
				'invalid_translation_plain_text_missing',
			),
			true
		);
	}

	private static function build_retry_prompt( string $prompt ): string {
		return $prompt . "\n\nCRITICAL: Return only the translated content. Preserve HTML tags, Gutenberg block comments, URLs, code fences, and source symbols exactly. Do not rewrite Unicode symbols or math notation as LaTeX or ASCII. Do not add explanations, bullet lists, markdown headings, or commentary. The output length MUST be approximately the same as the input length; do not append extra paragraphs.";
	}

	/* ---------------------------------------------------------------
	 * Rate-limit (HTTP 429) handling
	 * ------------------------------------------------------------- */

	/**
	 * Decide whether the given transport result looks like a provider-side
	 * rate-limit response (HTTP 429 / "too many requests" / "rate limit reached").
	 * Works for both the WP AI Client `WP_Error` path (Groq / OpenAI / Anthropic
	 * surface the message verbatim) and the direct-API `non_2xx_or_empty` path
	 * when a 429 body is logged by the caller.
	 */
	public static function is_rate_limit_error( \WP_Error $error ): bool {
		$code = $error->get_error_code();
		if ( is_string( $code ) && ( false !== stripos( $code, 'rate_limit' ) || false !== stripos( $code, '429' ) ) ) {
			return true;
		}
		foreach ( $error->get_error_messages() as $message ) {
			if ( ! is_string( $message ) ) {
				continue;
			}
			if ( false !== stripos( $message, '(429)' )
				|| false !== stripos( $message, '429 ' )
				|| false !== stripos( $message, 'too many requests' )
				|| false !== stripos( $message, 'rate limit' )
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract the retry-after hint (in seconds) from a 429 error, looking at:
	 *
	 *   - "Please try again in 2s" / "try again in 2.5s" / "try again in 1m30s"
	 *     (Groq / OpenAI-style messages)
	 *   - "Retry-After: N" header echoed into the message body
	 *
	 * Falls back to `$default_seconds` when nothing could be parsed. Always
	 * clamped to the [RATE_LIMIT_MIN_SLEEP_SEC, RATE_LIMIT_MAX_SLEEP_SEC]
	 * range so a misbehaving provider cannot stall a request indefinitely.
	 */
	public static function extract_retry_after_seconds( \WP_Error $error, float $default_seconds = 2.0 ): float {
		$seconds = 0.0;
		foreach ( $error->get_error_messages() as $message ) {
			if ( ! is_string( $message ) ) {
				continue;
			}

			// "try again in 1m30s", "try again in 30s", "try again in 2.5s".
			if ( preg_match( '/try again in\s+(?:(\d+)m)?(\d+(?:\.\d+)?)s/i', $message, $m ) ) {
				$seconds = ( (int) ( $m[1] ?? 0 ) ) * 60.0 + (float) $m[2];
				break;
			}

			// "Retry-After: 2" (header-style, integer seconds).
			if ( preg_match( '/retry-?after["\s:]+(\d+(?:\.\d+)?)/i', $message, $m ) ) {
				$seconds = (float) $m[1];
				break;
			}
		}

		if ( $seconds <= 0.0 ) {
			$seconds = $default_seconds;
		}

		// Add a small jitter so multiple parallel requests do not all wake
		// up in the same millisecond and re-trigger the limit in lockstep.
		$seconds += ( wp_rand( 0, 500 ) / 1000.0 );

		$seconds = max( (float) self::RATE_LIMIT_MIN_SLEEP_SEC, $seconds );
		$seconds = min( (float) self::RATE_LIMIT_MAX_SLEEP_SEC, $seconds );

		return $seconds;
	}

	/**
	 * Sleep for the interval the provider suggested, then re-run
	 * `translate_chunk()` in place. Used by the transport-level 429 guard.
	 * Returns the error unchanged when the retry budget is exhausted so the
	 * caller's existing error path (validation retry / content-phase
	 * fallback cascade) can handle it.
	 */
	private static function handle_rate_limit_and_retry(
		\WP_Error $error,
		string $text,
		string $prompt,
		int $validation_attempt,
		string $model_slug
	): mixed {
		if ( self::$rate_limit_retry_depth >= self::RATE_LIMIT_MAX_RETRIES ) {
			TimingLogger::log( 'ai_rate_limit_exhausted', array(
				'model'   => $model_slug,
				'retries' => self::$rate_limit_retry_depth,
				'reason'  => $error->get_error_code(),
			) );
			return $error;
		}

		$sleep_seconds = self::extract_retry_after_seconds( $error );

		self::$rate_limit_retry_depth++;
		TimingLogger::log( 'ai_rate_limit_wait', array(
			'model'         => $model_slug,
			'attempt'       => self::$rate_limit_retry_depth,
			'sleep_ms'      => (int) round( $sleep_seconds * 1000 ),
			'retry_budget'  => self::RATE_LIMIT_MAX_RETRIES,
		) );

		// `usleep()` takes microseconds; `sleep()` only whole seconds. Use
		// usleep so sub-second hints like "try again in 0.8s" are respected.
		usleep( (int) round( $sleep_seconds * 1_000_000 ) );

		try {
			return self::translate_chunk( $text, $prompt, $validation_attempt );
		} finally {
			self::$rate_limit_retry_depth--;
		}
	}
}
