<?php

namespace AI_Translate;

if ( ! defined( 'ABSPATH' ) ) exit;

class ConfigurationService {

	public static function save( array $input ): ?\WP_Error {
		$validated_direct_api_url = null;
		if ( array_key_exists( 'direct_api_url', $input ) ) {
			$validated_direct_api_url = self::validate_direct_api_url( $input['direct_api_url'] );
			if ( is_wp_error( $validated_direct_api_url ) ) {
				return $validated_direct_api_url;
			}
		}

		if ( isset( $input['prompt_template'] ) ) {
			update_option( 'ai_translate_prompt', sanitize_textarea_field( $input['prompt_template'] ), false );
		}
		if ( array_key_exists( 'prompt_addon', $input ) ) {
			$addon_value = is_string( $input['prompt_addon'] ) ? sanitize_textarea_field( $input['prompt_addon'] ) : '';
			if ( '' === $addon_value ) {
				delete_option( 'ai_translate_prompt_addon' );
			} else {
				update_option( 'ai_translate_prompt_addon', $addon_value, false );
			}
		}
		if ( isset( $input['meta_keys_translate'] ) ) {
			update_option( 'ai_translate_meta_translate', sanitize_textarea_field( $input['meta_keys_translate'] ), false );
		}
		if ( isset( $input['meta_keys_clear'] ) ) {
			update_option( 'ai_translate_meta_clear', sanitize_textarea_field( $input['meta_keys_clear'] ), false );
		}
		if ( isset( $input['auto_translate_new'] ) ) {
			update_option( 'ai_translate_new_post', $input['auto_translate_new'] ? '1' : '0', false );
		}
		if ( isset( $input['context_window_tokens'] ) ) {
			$context_window_tokens = min( 4000000, absint( $input['context_window_tokens'] ) );
			if ( $context_window_tokens > 0 ) {
				update_option( 'ai_translate_context_window_tokens', (string) $context_window_tokens, false );
			} else {
				delete_option( 'ai_translate_context_window_tokens' );
			}
		}
		if ( array_key_exists( 'model_slug', $input ) ) {
			$model_slug_value = TranslationRuntime::normalize_requested_model_slug( $input['model_slug'] ?? '' );
			if ( '' === $model_slug_value ) {
				delete_option( 'ai_translate_model_slug' );
			} else {
				update_option( 'ai_translate_model_slug', $model_slug_value, false );
			}
		}
		if ( isset( $input['force_direct_api'] ) ) {
			update_option( 'ai_translate_force_direct_api', $input['force_direct_api'] ? '1' : '0', false );
		}

		$should_reprobe_kwargs = false;
		if ( array_key_exists( 'direct_api_url', $input ) ) {
			if ( '' === $validated_direct_api_url ) {
				delete_option( 'ai_translate_direct_api_url' );
				delete_option( 'ai_translate_direct_api_kwargs_detected' );
				delete_option( 'ai_translate_direct_api_kwargs_last_probed_at' );
			} else {
				update_option( 'ai_translate_direct_api_url', $validated_direct_api_url, false );
				$should_reprobe_kwargs = true;
			}
		}

		if ( array_key_exists( 'model_slug', $input ) ) {
			$direct_url = get_option( 'ai_translate_direct_api_url', '' );
			if ( '' !== $direct_url ) {
				$should_reprobe_kwargs = true;
			}
		}

		if ( $should_reprobe_kwargs ) {
			$probe_result = self::probe_direct_api_kwargs(
				get_option( 'ai_translate_direct_api_url', '' ),
				get_option( 'ai_translate_model_slug', '' )
			);
			update_option( 'ai_translate_direct_api_kwargs_detected', $probe_result ? '1' : '0', false );
			update_option( 'ai_translate_direct_api_kwargs_last_probed_at', time(), false );

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
				get_option( 'ai_translate_direct_api_url', '' )
			);
		}

		return null;
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
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : (array) @gethostbynamel( $host );
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

		$response = wp_remote_post( $endpoint, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 15,
		) );

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

		$learned = get_option( 'ai_translate_learned_context_windows', array() );
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
			update_option( 'ai_translate_learned_context_windows', $learned, false );
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
		$response = wp_remote_get( $endpoint, array(
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/json' ),
		) );

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
