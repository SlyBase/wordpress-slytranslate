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
			update_option( 'ai_translate_prompt', sanitize_textarea_field( $input['prompt_template'] ) );
		}
		if ( array_key_exists( 'prompt_addon', $input ) ) {
			$addon_value = is_string( $input['prompt_addon'] ) ? sanitize_textarea_field( $input['prompt_addon'] ) : '';
			if ( '' === $addon_value ) {
				delete_option( 'ai_translate_prompt_addon' );
			} else {
				update_option( 'ai_translate_prompt_addon', $addon_value );
			}
		}
		if ( isset( $input['meta_keys_translate'] ) ) {
			update_option( 'ai_translate_meta_translate', sanitize_textarea_field( $input['meta_keys_translate'] ) );
		}
		if ( isset( $input['meta_keys_clear'] ) ) {
			update_option( 'ai_translate_meta_clear', sanitize_textarea_field( $input['meta_keys_clear'] ) );
		}
		if ( isset( $input['auto_translate_new'] ) ) {
			update_option( 'ai_translate_new_post', $input['auto_translate_new'] ? '1' : '0' );
		}
		if ( isset( $input['context_window_tokens'] ) ) {
			$context_window_tokens = min( 4000000, absint( $input['context_window_tokens'] ) );
			if ( $context_window_tokens > 0 ) {
				update_option( 'ai_translate_context_window_tokens', (string) $context_window_tokens );
			} else {
				delete_option( 'ai_translate_context_window_tokens' );
			}
		}
		if ( array_key_exists( 'model_slug', $input ) ) {
			$model_slug_value = is_string( $input['model_slug'] ) ? sanitize_text_field( $input['model_slug'] ) : '';
			if ( '' === $model_slug_value ) {
				delete_option( 'ai_translate_model_slug' );
			} else {
				update_option( 'ai_translate_model_slug', $model_slug_value );
			}
		}

		$should_reprobe_kwargs = false;
		if ( array_key_exists( 'direct_api_url', $input ) ) {
			if ( '' === $validated_direct_api_url ) {
				delete_option( 'ai_translate_direct_api_url' );
				delete_option( 'ai_translate_direct_api_kwargs_detected' );
				delete_option( 'ai_translate_direct_api_kwargs_last_probed_at' );
			} else {
				update_option( 'ai_translate_direct_api_url', $validated_direct_api_url );
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
			update_option( 'ai_translate_direct_api_kwargs_detected', $probe_result ? '1' : '0' );
			update_option( 'ai_translate_direct_api_kwargs_last_probed_at', time(), false );
		}

		return null;
	}

	private static function validate_direct_api_url( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$url_value = esc_url_raw( $value );
		if ( '' === $url_value ) {
			return '';
		}

		$scheme = strtolower( (string) wp_parse_url( $url_value, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'invalid_direct_api_url', __( 'The direct API URL must use http or https.', 'slytranslate' ) );
		}

		return $url_value;
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

		$content = $data['choices'][0]['message']['content'] ?? '';
		if ( ! is_string( $content ) ) {
			return false;
		}

		return false !== stripos( $content, 'Katze' );
	}
}
