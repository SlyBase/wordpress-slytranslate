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
	 * Translate $text via a direct POST to $api_url/v1/chat/completions.
	 *
	 * @param string      $text              Text to translate.
	 * @param string      $prompt            System instruction prompt.
	 * @param string      $model_slug        Model slug (may be empty).
	 * @param string      $api_url           Base URL of the API server.
	 * @param bool        $kwargs_supported  Whether chat_template_kwargs should be sent.
	 * @param string|null $source_lang       Source language code for kwargs.
	 * @param string|null $target_lang       Target language code for kwargs.
	 * @param int         $max_tokens        Maximum tokens to generate (0 = no explicit limit).
	 * @return string|\WP_Error|null Translated text, WP_Error on connection failure, or null for non-fatal API errors.
	 */
	public static function translate(
		string $text,
		string $prompt,
		string $model_slug,
		string $api_url,
		bool $kwargs_supported,
		?string $source_lang = null,
		?string $target_lang = null,
		int $max_tokens = 0
	): string|\WP_Error|null {
		$endpoint = trailingslashit( $api_url ) . 'v1/chat/completions';

		$attach_chat_template_kwargs = $kwargs_supported && $source_lang && $target_lang;
		$omit_system_message         = $attach_chat_template_kwargs && TranslationRuntime::model_requires_strict_direct_api( $model_slug );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $text,
			),
		);

		if ( ! $omit_system_message ) {
			array_unshift(
				$messages,
				array(
					'role'    => 'system',
					'content' => $prompt,
				)
			);
		}

		$body = array(
			'messages'    => $messages,
			'temperature' => 0,
		);

		if ( '' !== $model_slug ) {
			$body['model'] = $model_slug;
		}

		if ( $max_tokens > 0 ) {
			$body['max_tokens'] = $max_tokens;
		}

		if ( $attach_chat_template_kwargs ) {
			$body['chat_template_kwargs'] = array(
				'source_lang_code' => $source_lang,
				'target_lang_code' => $target_lang,
			);
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
				 * Defaults to 120 because llama.cpp / vLLM endpoints can
				 * legitimately take 60–90 s to generate a 1000-character
				 * response on smaller models, and the previous 30 s default
				 * caused single slow chunks to fail the entire content phase
				 * even though the endpoint was healthy and recovering.
				 *
				 * @param int    $timeout    Timeout in seconds.
				 * @param string $endpoint   Resolved API endpoint URL.
				 * @param string $model_slug Model identifier sent to the endpoint.
				 */
				'timeout' => (int) apply_filters( 'slytranslate_direct_api_timeout', 120, $endpoint, $model_slug ),
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
			// Surface the first slice of the response body for diagnosis.
			// The endpoint is operator-controlled (configured direct_api_url) so
			// the body should not contain end-user PII; we still cap and strip
			// to avoid runaway log growth.
			$body_snippet = is_string( $body_text ) ? $body_text : '';
			if ( '' !== $body_snippet ) {
				$body_snippet = preg_replace( '/\s+/', ' ', $body_snippet );
				$body_snippet = is_string( $body_snippet ) ? $body_snippet : '';
				$body_snippet = function_exists( 'mb_substr' )
					? mb_substr( $body_snippet, 0, 240, 'UTF-8' )
					: substr( $body_snippet, 0, 240 );
			}
			TimingLogger::log( 'direct_api_error_body', array(
				'status'   => (int) $code,
				'endpoint' => $endpoint,
				'model'    => $model_slug,
				'body'     => $body_snippet,
			) );

			// Expose HTTP 429 responses as a structured WP_Error so the
			// runtime's rate-limit guard can parse the provider's
			// "try again in Ns" / Retry-After hint and pause + retry instead
			// of collapsing to a non-actionable null. All other non-2xx
			// responses (400 for wrong model, 404 for missing endpoint, …)
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

				$detail = $body_snippet;
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
