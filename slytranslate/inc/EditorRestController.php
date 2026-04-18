<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all editor-facing REST routes.
 *
 * Callbacks remain on AI_Translate so that no test or external contract
 * needs to change; this class owns only the registration logic.
 */
class EditorRestController {

	private const REST_NAMESPACE = 'ai-translate/v1';

	public static function register_routes(): void {
		self::register_route( '/ai-translate/get-languages',          array( AI_Translate::class, 'rest_execute_get_languages' ) );
		self::register_route( '/ai-translate/get-translation-status', array( AI_Translate::class, 'rest_execute_get_translation_status' ) );
		self::register_route( '/ai-translate/translation-progress',   array( AI_Translate::class, 'rest_execute_get_translation_progress' ) );
		self::register_route( '/ai-translate/translate-text',         array( AI_Translate::class, 'rest_execute_translate_text' ) );
		self::register_route( '/ai-translate/translate-content',      array( AI_Translate::class, 'rest_execute_translate_content' ) );
		self::register_route( '/ai-translate/translate-post',         array( AI_Translate::class, 'rest_execute_translate_content' ) );
		self::register_route( '/ai-translate/cancel-translation',     array( AI_Translate::class, 'rest_cancel_translation' ) );

		// User preference endpoint (save last-used additional prompt per user).
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai-translate/user-preference',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( AI_Translate::class, 'rest_execute_save_user_preference' ),
				'permission_callback' => array( AI_Translate::class, 'rest_can_access_translation_abilities' ),
				'args'                => self::get_route_args( '/ai-translate/user-preference' ),
			)
		);
	}

	private static function register_route( string $route, callable $callback ): void {
		register_rest_route(
			self::REST_NAMESPACE,
			$route,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => $callback,
				'permission_callback' => array( AI_Translate::class, 'rest_can_access_translation_abilities' ),
				'args'                => self::get_route_args( $route ),
			)
		);
	}

	private static function get_route_args( string $route ): array {
		if ( in_array( $route, array( '/ai-translate/get-languages', '/ai-translate/translation-progress', '/ai-translate/cancel-translation' ), true ) ) {
			return array();
		}

		return array(
			'input' => array(
				'required'          => true,
				'validate_callback' => static function ( $value ): bool {
					return is_array( $value );
				},
				'sanitize_callback' => static function ( $value ): array {
					return is_array( $value ) ? $value : array();
				},
			),
		);
	}
}
