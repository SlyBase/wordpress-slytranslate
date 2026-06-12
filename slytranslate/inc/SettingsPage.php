<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page (Settings → SlyTranslate) plus its REST backend.
 *
 * The page is a thin consumer of the existing configuration path: reads and
 * writes go through AI_Translate::execute_configure() so validation and the
 * direct-API probe side effects stay identical to the MCP `configure`
 * ability. No second configuration code path is introduced here.
 */
class SettingsPage {

	public const MENU_SLUG     = 'slytranslate';
	public const SCRIPT_HANDLE = 'slytranslate-settings';

	/* ---------------------------------------------------------------
	 * Admin menu + assets
	 * ------------------------------------------------------------- */

	public static function register_menu(): void {
		add_options_page(
			__( 'SlyTranslate Settings', 'slytranslate' ),
			'SlyTranslate',
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render_page' )
		);
	}

	public static function render_page(): void {
		echo '<div class="wrap"><div id="slytranslate-settings-root"></div></div>';
	}

	/**
	 * Enqueue the settings app only on its own admin page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook_suffix = '' ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/settings-page.js', dirname( __DIR__ ) . '/slytranslate.php' ),
			array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ),
			self::get_script_version(),
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'slyTranslateSettings',
			array(
				'settingsPath'         => '/' . Plugin::REST_NAMESPACE . '/settings',
				'probeConcurrencyPath' => '/' . Plugin::REST_NAMESPACE . '/settings/probe-concurrency',
				'modelsPath'           => '/' . Plugin::REST_NAMESPACE . '/ai-translate/get-available-models/run',
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				self::SCRIPT_HANDLE,
				'slytranslate',
				plugin_dir_path( dirname( __DIR__ ) . '/slytranslate.php' ) . 'languages'
			);
		}
	}

	/* ---------------------------------------------------------------
	 * REST backend
	 * ------------------------------------------------------------- */

	public static function register_rest_routes(): void {
		$admin_permission = static function (): bool {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static function () {
						return self::get_settings_payload();
					},
					'permission_callback' => $admin_permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => static function ( $request ) {
						return self::get_settings_payload( self::extract_input( $request ) );
					},
					'permission_callback' => $admin_permission,
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/settings/probe-concurrency',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( $request ) {
					$input      = self::extract_input( $request );
					$model_slug = isset( $input['model_slug'] ) && is_string( $input['model_slug'] ) ? $input['model_slug'] : '';
					if ( '' === trim( $model_slug ) ) {
						$model_slug = TranslationRuntime::get_requested_model_slug();
					}

					return ConfigurationService::probe_string_table_concurrency( $model_slug );
				},
				'permission_callback' => $admin_permission,
			)
		);
	}

	/**
	 * Save (when $input is non-empty) and return the full settings payload.
	 *
	 * Reuses AI_Translate::execute_configure() — an empty input is a pure
	 * read — and enriches the result with the environment facts the status
	 * line needs (active language plugin, AI client availability) which the
	 * configure payload does not carry.
	 *
	 * @param array $input Settings to persist; empty array reads only.
	 * @return array|\WP_Error
	 */
	public static function get_settings_payload( array $input = array() ) {
		$result = AI_Translate::execute_configure( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$adapter = AI_Translate::get_adapter();

		$result['language_plugin_detected'] = null !== $adapter;
		$result['language_plugin_label']    = null !== $adapter ? self::adapter_label( $adapter ) : '';
		$result['is_string_table_adapter']  = AI_Translate::is_single_entry_translation_mode();
		$result['ai_client_available']      = AI_Translate::is_server_translation_ui_available();
		$result['default_prompt_template']  = AI_Translate::get_default_prompt();

		$learned                             = get_option( 'slytranslate_learned_context_windows', array() );
		$result['learned_context_windows']   = is_array( $learned ) ? $learned : array();

		return $result;
	}

	private static function adapter_label( TranslationPluginAdapter $adapter ): string {
		if ( $adapter instanceof PolylangAdapter ) {
			return 'Polylang';
		}
		if ( $adapter instanceof TranslatePressAdapter ) {
			return 'TranslatePress';
		}
		if ( $adapter instanceof WpglobusAdapter ) {
			return 'WPGlobus';
		}
		if ( $adapter instanceof WpMultilangAdapter ) {
			return 'WP Multilang';
		}

		$class = get_class( $adapter );
		$short = substr( $class, (int) strrpos( $class, '\\' ) );
		return trim( str_replace( array( '\\', 'Adapter' ), '', $short ) );
	}

	/**
	 * Extract the request payload, accepting both a flat JSON body and the
	 * `{ "input": { … } }` envelope used by the ability REST bridge.
	 *
	 * @param mixed $request REST request object.
	 */
	private static function extract_input( $request ): array {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_json_params' ) ) {
			return array();
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return array();
		}

		if ( isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
			return $payload['input'];
		}

		return $payload;
	}

	private static function get_script_version(): string {
		$script_path  = dirname( __DIR__ ) . '/assets/settings-page.js';
		$script_mtime = file_exists( $script_path ) ? filemtime( $script_path ) : false;

		if ( false === $script_mtime ) {
			return Plugin::VERSION;
		}

		return Plugin::VERSION . '.' . (string) $script_mtime;
	}
}
