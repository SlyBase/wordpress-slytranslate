<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Sends translation requests directly to an OpenAI-compatible HTTP endpoint.
 *
 * Returns null when the request fails so callers can fall back to the standard
 * WordPress AI Client path.
 */
class DirectApiTranslationClient {

	/**
	 * Translate content via a direct POST to $api_url/v1/chat/completions.
	 *
	 * @param string      $user_content      User message content.
	 * @param string      $system_prompt     Optional system instruction prompt.
	 * @param bool        $use_system_prompt Whether the system instruction should be sent.
	 * @param string      $model_slug        Model slug (may be empty).
	 * @param string      $api_url           Base URL of the API server.
	 * @param int         $temperature       Sampling temperature.
	 * @param int         $max_tokens        Maximum tokens to generate (0 = no explicit limit).
	 * @param array       $extra_request_body Additional top-level request keys from the model profile.
	 * @return string|\WP_Error|null Translated text, WP_Error on connection failure, or null for non-fatal API errors.
	 */
	public static function translate(
		string $user_content,
		string $system_prompt,
		bool $use_system_prompt,
		string $model_slug,
		string $api_url,
		int $temperature = 0,
		int $max_tokens = 0,
		array $extra_request_body = array()
	): string|\WP_Error|null {
		$endpoint = trailingslashit( $api_url ) . 'v1/chat/completions';

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $user_content,
			),
		);

		if ( $use_system_prompt && '' !== trim( $system_prompt ) ) {
			array_unshift(
				$messages,
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				)
			);
		}

		$body = array(
			'messages'    => $messages,
			'temperature' => $temperature,
		);

		if ( '' !== $model_slug ) {
			$body['model'] = $model_slug;
		}

		if ( $max_tokens > 0 ) {
			$body['max_tokens'] = $max_tokens;
		}

		foreach ( $extra_request_body as $key => $value ) {
			if ( in_array( (string) $key, array( 'messages', 'model' ), true ) ) {
				continue;
			}

			$body[ $key ] = $value;
		}

		$transport_started = TimingLogger::start();
		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				/**
				 * HTTP timeout (seconds) for direct-API translation calls.
				 *
				 * Defaults to 300 because llama.cpp router endpoints may need to
				 * swap models on demand (unload one model and load another), which
				 * can take 2–4 minutes for large models (≥ 13 GB) on integrated
				 * GPUs. At 120 s the request timed out during the model-load phase,
				 * triggering a WP-AI-Client fallback that also timed out, doubling
				 * the total wait to ~300 s and causing the entire chunk to fail.
				 * Raising the limit to 300 s covers even the slowest model swap
				 * while keeping a finite safety bound on hung connections.
				 *
				 * @param int    $timeout    Timeout in seconds.
				 * @param string $endpoint   Resolved API endpoint URL.
				 * @param string $model_slug Model identifier sent to the endpoint.
				 */
				'timeout' => (int) apply_filters( 'slytranslate_direct_api_timeout', 300, $endpoint, $model_slug ),
			)
		);
		$transport_duration_ms = TimingLogger::stop( $transport_started );

		if ( is_wp_error( $response ) ) {
			TimingLogger::log( 'direct_api', array(
				'duration_ms' => $transport_duration_ms,
				'status'      => 0,
				'bytes'       => 0,
				'ok'          => false,
				'reason'      => $response->get_error_code(),
			) );
			return new \WP_Error(
				'direct_api_connection_error',
				sprintf(
					/* translators: 1: API endpoint URL, 2: error message from HTTP transport. */
					__( 'Could not connect to direct API at %1$s: %2$s', 'slytranslate' ),
					$endpoint,
					$response->get_error_message()
				)
			);
		}

		$code      = wp_remote_retrieve_response_code( $response );
		$body_text = wp_remote_retrieve_body( $response );
		TimingLogger::log( 'direct_api', array(
			'duration_ms' => $transport_duration_ms,
			'status'      => (int) $code,
			'bytes'       => is_string( $body_text ) ? strlen( $body_text ) : 0,
			'ok'          => $code >= 200 && $code < 300,
		) );
		if ( $code < 200 || $code >= 300 ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$body_snippet = is_string( $body_text ) ? wp_strip_all_tags( $body_text ) : '';
				if ( '' !== $body_snippet ) {
					$body_snippet = preg_replace( '/\s+/', ' ', $body_snippet );
					$body_snippet = is_string( $body_snippet )
						? ( function_exists( 'mb_substr' ) ? mb_substr( $body_snippet, 0, 120, 'UTF-8' ) : substr( $body_snippet, 0, 120 ) )
						: '';
				}
				TimingLogger::log( 'direct_api_error_body', array(
					'status'   => (int) $code,
					'endpoint' => $endpoint,
					'model'    => $model_slug,
					'body'     => $body_snippet,
				) );
			}

			// Expose retryable capacity responses as structured WP_Errors so
			// the runtime can back off and retry instead of collapsing to
			// non-actionable null. This currently covers:
			//   - HTTP 429 ("rate limit")
			//   - HTTP 500 router capacity errors like
			//     "model limit reached, try again later" on single-model
			//     llama.cpp router setups (`--models-max 1`).
			// Other non-2xx responses (400 wrong model, 404 missing endpoint, …)
			// still return null so the generic fallback-to-wp_ai_client path
			// takes over.
			if ( 429 === (int) $code ) {
				$retry_after_header = '';
				$headers            = wp_remote_retrieve_headers( $response );
				if ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ) {
					$retry_after_header = (string) $headers['retry-after'];
				} elseif ( is_array( $headers ) && isset( $headers['retry-after'] ) ) {
					$retry_after_header = (string) $headers['retry-after'];
				}

				$rate_limit_body = is_string( $body_text ) ? wp_strip_all_tags( $body_text ) : '';
				if ( '' !== $rate_limit_body ) {
					$rate_limit_body = preg_replace( '/\s+/', ' ', $rate_limit_body );
					$rate_limit_body = is_string( $rate_limit_body )
						? ( function_exists( 'mb_substr' ) ? mb_substr( $rate_limit_body, 0, 120, 'UTF-8' ) : substr( $rate_limit_body, 0, 120 ) )
						: '';
				}
				$detail = $rate_limit_body;
				if ( '' !== $retry_after_header ) {
					$detail .= sprintf( ' [Retry-After: %s]', $retry_after_header );
				}

				return new \WP_Error(
					'direct_api_rate_limited',
					sprintf(
						/* translators: 1: API endpoint URL, 2: response body / retry-after. */
						__( 'Direct API rate limit (429) from %1$s: %2$s', 'slytranslate' ),
						$endpoint,
						$detail
					)
				);
			}

			if ( 500 === (int) $code ) {
				$model_limit_body = is_string( $body_text ) ? wp_strip_all_tags( $body_text ) : '';
				$model_limit_hint = strtolower( $model_limit_body );
				if ( false !== strpos( $model_limit_hint, 'model limit reached' )
					|| ( false !== strpos( $model_limit_hint, 'try again later' ) && false !== strpos( $model_limit_hint, 'limit' ) )
				) {
					if ( '' !== $model_limit_body ) {
						$model_limit_body = preg_replace( '/\s+/', ' ', $model_limit_body );
						$model_limit_body = is_string( $model_limit_body )
							? ( function_exists( 'mb_substr' ) ? mb_substr( $model_limit_body, 0, 120, 'UTF-8' ) : substr( $model_limit_body, 0, 120 ) )
							: '';
					}

					return new \WP_Error(
						'direct_api_model_limit_reached',
						sprintf(
							/* translators: 1: API endpoint URL, 2: response body excerpt. */
							__( 'Direct API model limit (500) from %1$s: %2$s', 'slytranslate' ),
							$endpoint,
							$model_limit_body
						)
					);
				}
			}

			return null;
		}

		$data = json_decode( is_string( $body_text ) ? $body_text : '', true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$content = $data['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $content ) ) {
			return null;
		}

		return $content;
	}
}
