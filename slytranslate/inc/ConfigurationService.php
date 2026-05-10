<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

class ConfigurationService {
	private const MIN_STRING_TABLE_CONCURRENCY = 1;
	private const MAX_STRING_TABLE_CONCURRENCY = 4;
	private const STRING_TABLE_PROBE_TOKEN_TTL = 300;

	public static function save( array $input ): ?\WP_Error {
		$validated_direct_api_url = null;
		if ( array_key_exists( 'direct_api_url', $input ) ) {
			$validated_direct_api_url = self::validate_direct_api_url( $input['direct_api_url'] );
			if ( is_wp_error( $validated_direct_api_url ) ) {
				return $validated_direct_api_url;
			}
		}

		if ( isset( $input['prompt_template'] ) ) {
			update_option( 'slytranslate_prompt', sanitize_textarea_field( $input['prompt_template'] ), false );
		}
		if ( array_key_exists( 'prompt_addon', $input ) ) {
			$addon_value = is_string( $input['prompt_addon'] ) ? sanitize_textarea_field( $input['prompt_addon'] ) : '';
			if ( '' === $addon_value ) {
				delete_option( 'slytranslate_prompt_addon' );
			} else {
				update_option( 'slytranslate_prompt_addon', $addon_value, false );
			}
		}
		if ( isset( $input['meta_keys_translate'] ) ) {
			update_option( 'slytranslate_meta_translate', sanitize_textarea_field( $input['meta_keys_translate'] ), false );
		}
		if ( isset( $input['meta_keys_clear'] ) ) {
			update_option( 'slytranslate_meta_clear', sanitize_textarea_field( $input['meta_keys_clear'] ), false );
		}
		if ( isset( $input['auto_translate_new'] ) ) {
			update_option( 'slytranslate_new_post', $input['auto_translate_new'] ? '1' : '0', false );
		}
		if ( isset( $input['context_window_tokens'] ) ) {
			$context_window_tokens = min( 4000000, absint( $input['context_window_tokens'] ) );
			if ( $context_window_tokens > 0 ) {
				update_option( 'slytranslate_context_window_tokens', (string) $context_window_tokens, false );
			} else {
				delete_option( 'slytranslate_context_window_tokens' );
			}
		}
		if ( array_key_exists( 'model_slug', $input ) ) {
			$model_slug_value = TranslationRuntime::normalize_requested_model_slug( $input['model_slug'] ?? '' );
			if ( '' === $model_slug_value ) {
				delete_option( 'slytranslate_model_slug' );
			} else {
				update_option( 'slytranslate_model_slug', $model_slug_value, false );
			}
		}
		if ( isset( $input['force_direct_api'] ) ) {
			update_option( 'slytranslate_force_direct_api', $input['force_direct_api'] ? '1' : '0', false );
		}
		if ( array_key_exists( 'string_table_concurrency', $input ) ) {
			update_option(
				'slytranslate_string_table_concurrency',
				self::clamp_string_table_concurrency( absint( $input['string_table_concurrency'] ) ),
				false
			);
		}

		$should_reprobe_kwargs = false;
		if ( array_key_exists( 'direct_api_url', $input ) ) {
			if ( '' === $validated_direct_api_url ) {
				delete_option( 'slytranslate_direct_api_url' );
				delete_option( 'slytranslate_direct_api_kwargs_detected' );
				delete_option( 'slytranslate_direct_api_kwargs_last_probed_at' );
			} else {
				update_option( 'slytranslate_direct_api_url', $validated_direct_api_url, false );
				$should_reprobe_kwargs = true;
			}
		}

		if ( array_key_exists( 'model_slug', $input ) ) {
			$direct_url = get_option( 'slytranslate_direct_api_url', '' );
			if ( '' !== $direct_url ) {
				$should_reprobe_kwargs = true;
			}
		}

		if ( $should_reprobe_kwargs ) {
			$probe_result = self::probe_direct_api_kwargs(
				get_option( 'slytranslate_direct_api_url', '' ),
				get_option( 'slytranslate_model_slug', '' )
			);
			update_option( 'slytranslate_direct_api_kwargs_detected', $probe_result ? '1' : '0', false );
			update_option( 'slytranslate_direct_api_kwargs_last_probed_at', time(), false );

			// Also probe the endpoint's model list for OpenAI-compatible
			// `context_window` / `meta.n_ctx_train` fields. This lets hosted
			// providers (Groq, Together, OpenRouter, …) and local servers
			// (llama.cpp, llama-swap, vLLM) advertise their actual context
			// window per model, so `get_chunk_char_limit()` can derive a
			// realistic chunk size without us having to ship a hardcoded
			// model-name table for every new release. Findings are merged
			// into the existing `ai_translate_learned_context_windows`
			// option and are overridden only by the per-model value the
			// plugin already learned from a previous request-time error.
			self::probe_and_remember_direct_api_context_windows(
				get_option( 'slytranslate_direct_api_url', '' )
			);
		}

		return null;
	}

	public static function get_string_table_concurrency_setting( string $model_slug = '' ): int {
		if ( '' === $model_slug ) {
			$model_slug = TranslationRuntime::get_requested_model_slug();
		}

		$configured = self::clamp_string_table_concurrency( absint( get_option( 'slytranslate_string_table_concurrency', 1 ) ) );
		$filtered   = apply_filters( 'slytranslate_string_table_concurrency', $configured, $model_slug );

		return self::clamp_string_table_concurrency( absint( $filtered ) );
	}

	public static function get_parallel_string_table_transport_context(): array {
		$runner = apply_filters( 'slytranslate_string_table_parallel_http_runner', null );
		if ( is_callable( $runner ) ) {
			return array(
				'supported' => true,
				'transport' => 'filtered_runner',
				'runner'    => $runner,
			);
		}

		if ( class_exists( '\\WpOrg\\Requests\\Requests' ) && method_exists( '\\WpOrg\\Requests\\Requests', 'request_multiple' ) ) {
			return array(
				'supported' => true,
				'transport' => 'wporg_requests',
				'runner'    => static function ( array $requests, array $options = array() ) {
					return \WpOrg\Requests\Requests::request_multiple( $requests, $options );
				},
			);
		}

		if ( class_exists( '\\Requests' ) && method_exists( '\\Requests', 'request_multiple' ) ) {
			return array(
				'supported' => true,
				'transport' => 'legacy_requests',
				'runner'    => static function ( array $requests, array $options = array() ) {
					return \Requests::request_multiple( $requests, $options );
				},
			);
		}

		return array(
			'supported' => false,
			'transport' => 'none',
			'runner'    => null,
			'reason'    => 'no_parallel_transport',
		);
	}

	public static function get_string_table_concurrency_recommendation( string $model_slug ): array {
		$model_slug       = strtolower( TranslationRuntime::normalize_requested_model_slug( $model_slug ) );
		$recommendations  = get_option( 'slytranslate_string_table_concurrency_recommendations', array() );
		$recommendations  = is_array( $recommendations ) ? $recommendations : array();
		$recommendation   = $recommendations[ $model_slug ] ?? array();

		return array(
			'recommended' => self::clamp_string_table_concurrency( absint( $recommendation['recommended'] ?? 1 ) ),
			'supported'   => ! empty( $recommendation['supported'] ),
			'transport'   => (string) ( $recommendation['transport'] ?? '' ),
			'measured_at' => absint( $recommendation['measured_at'] ?? 0 ),
			'levels'      => is_array( $recommendation['levels'] ?? null ) ? $recommendation['levels'] : array(),
		);
	}

	public static function get_effective_string_table_concurrency( string $model_slug = '' ): array {
		if ( '' === $model_slug ) {
			$model_slug = TranslationRuntime::get_requested_model_slug();
		}

		$configured      = self::get_string_table_concurrency_setting( $model_slug );
		$recommendation  = self::get_string_table_concurrency_recommendation( $model_slug );
		$transport       = self::get_parallel_string_table_transport_context();
		$recommended     = ! empty( $recommendation['supported'] ) ? $recommendation['recommended'] : 1;
		$effective       = 1;

		if ( ! empty( $transport['supported'] ) && $configured > 1 && $recommended > 1 ) {
			$effective = min( $configured, $recommended );
		}

		return array(
			'model_slug'   => $model_slug,
			'configured'   => $configured,
			'recommended'  => $recommended,
			'effective'    => self::clamp_string_table_concurrency( $effective ),
			'supported'    => ! empty( $transport['supported'] ),
			'transport'    => (string) ( $transport['transport'] ?? 'none' ),
			'reason'       => (string) ( $transport['reason'] ?? '' ),
			'measured_at'  => $recommendation['measured_at'],
		);
	}

	public static function create_string_table_probe_token(): string {
		$token = function_exists( 'wp_generate_password' )
			? (string) wp_generate_password( 32, false, false )
			: bin2hex( random_bytes( 16 ) );

		set_transient(
			self::get_string_table_probe_token_key( $token ),
			array(
				'user_id' => get_current_user_id(),
				'created' => time(),
			),
			self::STRING_TABLE_PROBE_TOKEN_TTL
		);

		return $token;
	}

	public static function validate_string_table_probe_token( string $token ): bool {
		if ( '' === trim( $token ) ) {
			return false;
		}

		return false !== get_transient( self::get_string_table_probe_token_key( $token ) );
	}

	public static function delete_string_table_probe_token( string $token ): void {
		if ( '' === trim( $token ) ) {
			return;
		}

		delete_transient( self::get_string_table_probe_token_key( $token ) );
	}

	public static function run_parallel_http_requests( array $requests, array $options = array() ): array|\WP_Error {
		$transport = self::get_parallel_string_table_transport_context();
		if ( empty( $transport['supported'] ) || ! is_callable( $transport['runner'] ) ) {
			return new \WP_Error( 'string_table_parallel_transport_unavailable', __( 'No parallel HTTP transport is available.', 'slytranslate' ) );
		}

		$responses = call_user_func( $transport['runner'], $requests, $options );
		if ( ! is_array( $responses ) ) {
			return new \WP_Error( 'string_table_parallel_transport_failed', __( 'Parallel HTTP transport returned an invalid response.', 'slytranslate' ) );
		}

		return $responses;
	}

	public static function run_string_table_parallel_batches( array $jobs, string $model_slug ): array|\WP_Error {
		$token    = self::create_string_table_probe_token();
		$requests = array();

		foreach ( $jobs as $index => $job ) {
			$requests[ 'job_' . $index ] = array(
				'url'     => self::get_string_table_worker_url(),
				'type'    => 'POST',
				'headers' => array( 'Content-Type' => 'application/json' ),
				'data'    => wp_json_encode( array(
					'action'            => 'translate_string_table_batch',
					'token'             => $token,
					'model_slug'        => $model_slug,
					'batch'             => $job['batch'],
					'target_language'   => $job['to'],
					'source_language'   => $job['from'],
					'additional_prompt' => $job['additional_prompt'],
					'batch_index'       => $job['batch_index'],
				) ),
			);
		}

		try {
			$responses = self::run_parallel_http_requests( $requests, array( 'timeout' => 45 ) );
			if ( is_wp_error( $responses ) ) {
				return $responses;
			}

			$results = array();
			foreach ( $responses as $response ) {
				$normalized = self::normalize_parallel_response( $response );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}

				if ( empty( $normalized['ok'] ) ) {
					return new \WP_Error(
						(string) ( $normalized['error_code'] ?? 'string_table_parallel_worker_failed' ),
						(string) ( $normalized['message'] ?? __( 'String-table worker request failed.', 'slytranslate' ) )
					);
				}

				$batch_index = absint( $normalized['batch_index'] ?? 0 );
				$results[ $batch_index ] = $normalized;
			}

			ksort( $results );
			return $results;
		} finally {
			self::delete_string_table_probe_token( $token );
		}
	}

	public static function probe_string_table_concurrency( string $model_slug, int $max = self::MAX_STRING_TABLE_CONCURRENCY ): array {
		$model_slug = TranslationRuntime::normalize_requested_model_slug( $model_slug );
		$transport  = self::get_parallel_string_table_transport_context();
		$max        = max( self::MIN_STRING_TABLE_CONCURRENCY, min( self::MAX_STRING_TABLE_CONCURRENCY, $max ) );

		if ( empty( $transport['supported'] ) ) {
			$result = array(
				'model_slug'   => $model_slug,
				'supported'    => false,
				'recommended'  => 1,
				'transport'    => 'none',
				'max'          => $max,
				'levels'       => array(),
				'reason'       => 'no_parallel_transport',
			);

			self::store_string_table_concurrency_recommendation( $model_slug, $result );
			return $result;
		}

		$baseline_wall_ms = 0;
		$recommended      = 1;
		$levels           = array();
		$token            = self::create_string_table_probe_token();

		try {
			for ( $level = 1; $level <= $max; $level++ ) {
				$requests = array();
				for ( $index = 0; $index < $level; $index++ ) {
					$requests[ 'probe_' . $level . '_' . $index ] = array(
						'url'     => self::get_string_table_worker_url(),
						'type'    => 'POST',
						'headers' => array( 'Content-Type' => 'application/json' ),
						'data'    => wp_json_encode( array(
							'action'          => 'probe',
							'token'           => $token,
							'model_slug'      => $model_slug,
							'source_language' => 'de',
							'target_language' => 'en',
							'text'            => 'Hallo Welt',
						) ),
					);
				}

				$started   = TimingLogger::start();
				$responses = self::run_parallel_http_requests( $requests, array( 'timeout' => 45 ) );
				$wall_ms   = max( 1, TimingLogger::stop( $started ) );

				$errors = 0;
				if ( is_wp_error( $responses ) ) {
					$errors = $level;
				} else {
					foreach ( $responses as $response ) {
						$normalized = self::normalize_parallel_response( $response );
						if ( is_wp_error( $normalized ) || empty( $normalized['ok'] ) ) {
							$errors++;
						}
					}
				}

				if ( 1 === $level ) {
					$baseline_wall_ms = $wall_ms;
				}

				$estimated_sequential_ms = max( 1, $baseline_wall_ms * $level );
				$speedup                = round( $estimated_sequential_ms / $wall_ms, 2 );
				$levels[]               = array(
					'level'                   => $level,
					'wall_ms'                 => $wall_ms,
					'estimated_sequential_ms' => $estimated_sequential_ms,
					'speedup'                 => $speedup,
					'errors'                  => $errors,
				);

				TimingLogger::log( 'content_string_batch_concurrency_probe', array(
					'level'                   => $level,
					'wall_ms'                 => $wall_ms,
					'estimated_sequential_ms' => $estimated_sequential_ms,
					'speedup'                 => $speedup,
					'errors'                  => $errors,
				) );

				if ( $errors > 0 ) {
					$recommended = max( 1, $level - 1 );
					break;
				}

				if ( 2 === $level && $speedup < 1.35 ) {
					$recommended = 1;
					break;
				}

				if ( $speedup >= ( $level * 0.65 ) ) {
					$recommended = $level;
				} else {
					break;
				}
			}
		} finally {
			self::delete_string_table_probe_token( $token );
		}

		$result = array(
			'model_slug'   => $model_slug,
			'supported'    => true,
			'recommended'  => self::clamp_string_table_concurrency( $recommended ),
			'transport'    => (string) $transport['transport'],
			'max'          => $max,
			'levels'       => $levels,
		);

		self::store_string_table_concurrency_recommendation( $model_slug, $result );

		return $result;
	}

	private static function clamp_string_table_concurrency( int $value ): int {
		return max( self::MIN_STRING_TABLE_CONCURRENCY, min( self::MAX_STRING_TABLE_CONCURRENCY, $value ) );
	}

	private static function get_string_table_probe_token_key( string $token ): string {
		return 'slytranslate_string_table_probe_' . hash( 'sha256', $token );
	}

	private static function get_string_table_worker_url(): string {
		return rest_url( Plugin::REST_NAMESPACE . '/ai-translate/string-table-worker/run' );
	}

	private static function normalize_parallel_response( $response ): array|\WP_Error {
		if ( is_array( $response ) && isset( $response['body'] ) ) {
			$status_code = isset( $response['status'] ) ? (int) $response['status'] : wp_remote_retrieve_response_code( $response );
			$body        = is_string( $response['body'] ) ? $response['body'] : wp_remote_retrieve_body( $response );
		} elseif ( is_object( $response ) && isset( $response->body ) ) {
			$status_code = isset( $response->status_code ) ? (int) $response->status_code : 0;
			$body        = (string) $response->body;
		} else {
			return new \WP_Error( 'string_table_parallel_invalid_response', __( 'Parallel worker returned an invalid response object.', 'slytranslate' ) );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new \WP_Error( 'string_table_parallel_http_error', __( 'Parallel worker request failed.', 'slytranslate' ) );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'string_table_parallel_invalid_json', __( 'Parallel worker returned invalid JSON.', 'slytranslate' ) );
		}

		return $decoded;
	}

	private static function store_string_table_concurrency_recommendation( string $model_slug, array $result ): void {
		$model_slug       = strtolower( $model_slug );
		$recommendations  = get_option( 'slytranslate_string_table_concurrency_recommendations', array() );
		$recommendations  = is_array( $recommendations ) ? $recommendations : array();
		$recommendations[ $model_slug ] = array(
			'recommended' => self::clamp_string_table_concurrency( absint( $result['recommended'] ?? 1 ) ),
			'supported'   => ! empty( $result['supported'] ),
			'transport'   => (string) ( $result['transport'] ?? '' ),
			'levels'      => is_array( $result['levels'] ?? null ) ? $result['levels'] : array(),
			'measured_at' => time(),
		);

		update_option( 'slytranslate_string_table_concurrency_recommendations', $recommendations, false );
	}

	private static function validate_direct_api_url( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$url = esc_url_raw( trim( $value ) );
		if ( '' === $url ) {
			return '';
		}

		$parts  = wp_parse_url( $url );
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host   = strtolower( $parts['host']   ?? '' );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return new \WP_Error( 'invalid_direct_api_url', __( 'The direct API URL must be a valid http(s) URL.', 'slytranslate' ) );
		}

		if ( self::host_is_internal( $host ) && ! apply_filters( 'slytranslate_allow_internal_direct_api', false, $host, $url ) ) {
			return new \WP_Error(
				'forbidden_direct_api_url',
				__( 'The direct API URL points to a private or loopback address. Set the slytranslate_allow_internal_direct_api filter to true if this is intentional.', 'slytranslate' )
			);
		}

		return $url;
	}

	private static function host_is_internal( string $host ): bool {
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : (array) gethostbynamel( $host );
		if ( empty( $ips ) ) {
			return true;
		}

		foreach ( $ips as $ip ) {
			if ( ! filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			) ) {
				return true;
			}
			if ( in_array( $ip, array( '169.254.169.254', 'fd00:ec2::254' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function build_safe_remote_request_args( array $args ): array {
		return array_merge(
			array(
				'timeout'            => 15,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
			),
			$args
		);
	}

	public static function probe_direct_api_kwargs( string $api_url, string $model_slug ): bool {
		if ( '' === $api_url ) {
			return false;
		}

		$endpoint = trailingslashit( $api_url ) . 'v1/chat/completions';

		$body = array(
			'messages' => array(
				array( 'role' => 'user', 'content' => 'cat' ),
			),
			'chat_template_kwargs' => array(
				'source_lang_code' => 'en',
				'target_lang_code' => 'de',
			),
			'temperature' => 0,
			'max_tokens'  => 20,
		);

		if ( '' !== $model_slug ) {
			$body['model'] = $model_slug;
		}

		$response = wp_remote_post(
			$endpoint,
			self::build_safe_remote_request_args(
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( ! isset( $data['choices'][0]['message'] ) || ! is_array( $data['choices'][0]['message'] ) ) {
			return false;
		}

		$message = $data['choices'][0]['message'];
		$content = $message['content'] ?? null;
		if ( is_string( $content ) ) {
			return true;
		}

		$reasoning = $message['reasoning_content'] ?? null;
		return is_string( $reasoning );
	}

	/**
	 * Probe the configured direct API's `GET /v1/models` endpoint and remember
	 * any advertised context-window sizes per model id.
	 *
	 * Recognises three common response shapes:
	 *
	 *   - Groq       → `{ data: [{ id, context_window, … }] }`
	 *   - OpenRouter → `{ data: [{ id, context_length, … }] }`
	 *   - llama.cpp  → `{ data: [{ id, meta: { n_ctx_train, … } }] }`
	 *
	 * Values are merged into the existing `ai_translate_learned_context_windows`
	 * option (keyed by lower-cased model id) so subsequent translation requests
	 * that resolve to that model get a realistic `chunk_char_limit`. Models that
	 * the plugin has already learned a value for from a live request-time error
	 * are left untouched.
	 *
	 * Returns the number of models for which a context window was stored.
	 */
	public static function probe_and_remember_direct_api_context_windows( string $api_url ): int {
		if ( '' === $api_url ) {
			return 0;
		}

		$discovered = self::probe_direct_api_context_windows( $api_url );
		if ( empty( $discovered ) ) {
			return 0;
		}

		$learned = get_option( 'slytranslate_learned_context_windows', array() );
		if ( ! is_array( $learned ) ) {
			$learned = array();
		}

		$changed = false;
		foreach ( $discovered as $model_id => $tokens ) {
			if ( $tokens < 1 ) {
				continue;
			}
			// Preserve per-model values learned from live 4xx context-overflow
			// responses — those reflect what the server actually enforces at
			// request time and should always win over the advertised metadata.
			if ( isset( $learned[ $model_id ] ) && absint( $learned[ $model_id ] ) > 0 ) {
				continue;
			}
			$learned[ $model_id ] = $tokens;
			$changed              = true;
		}

		if ( $changed ) {
			update_option( 'slytranslate_learned_context_windows', $learned, false );
		}

		return count( $discovered );
	}

	/**
	 * Call `GET {api_url}/v1/models` and return discovered context-window
	 * sizes keyed by lower-cased model id. Returns an empty array on any
	 * transport / parsing failure.
	 *
	 * @return array<string,int>
	 */
	public static function probe_direct_api_context_windows( string $api_url ): array {
		if ( '' === $api_url ) {
			return array();
		}

		$endpoint = trailingslashit( $api_url ) . 'v1/models';
		$response = wp_remote_get(
			$endpoint,
			self::build_safe_remote_request_args(
				array(
					'headers' => array( 'Accept' => 'application/json' ),
				)
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$models = $data['data'] ?? ( is_array( $data[0] ?? null ) ? $data : array() );
		if ( ! is_array( $models ) ) {
			return array();
		}

		$result = array();
		foreach ( $models as $model ) {
			if ( ! is_array( $model ) ) {
				continue;
			}
			$id = isset( $model['id'] ) && is_string( $model['id'] ) ? strtolower( trim( $model['id'] ) ) : '';
			if ( '' === $id ) {
				continue;
			}

			$tokens = 0;
			// Groq: `context_window`.
			if ( isset( $model['context_window'] ) ) {
				$tokens = absint( $model['context_window'] );
			}
			// OpenRouter / Together: `context_length`.
			if ( $tokens < 1 && isset( $model['context_length'] ) ) {
				$tokens = absint( $model['context_length'] );
			}
			// llama.cpp / llama-swap: `meta.n_ctx_train` (training window) or
			// `meta.n_ctx` (server-side runtime window, usually smaller).
			if ( $tokens < 1 && isset( $model['meta'] ) && is_array( $model['meta'] ) ) {
				$meta = $model['meta'];
				if ( isset( $meta['n_ctx_train'] ) ) {
					$tokens = absint( $meta['n_ctx_train'] );
				}
				if ( $tokens < 1 && isset( $meta['n_ctx'] ) ) {
					$tokens = absint( $meta['n_ctx'] );
				}
			}

			if ( $tokens > 0 ) {
				$result[ $id ] = $tokens;
			}
		}

		return $result;
	}
}
