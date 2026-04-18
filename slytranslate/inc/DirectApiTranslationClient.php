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
	 * @return string|null Translated text, or null to signal fallback.
	 */
	public static function translate(
		string $text,
		string $prompt,
		string $model_slug,
		string $api_url,
		bool $kwargs_supported,
		?string $source_lang = null,
		?string $target_lang = null
	): ?string {
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

		if ( $attach_chat_template_kwargs ) {
			$body['chat_template_kwargs'] = array(
				'source_lang_code' => $source_lang,
				'target_lang_code' => $target_lang,
			);
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 120,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
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
