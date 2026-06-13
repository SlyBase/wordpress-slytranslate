<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page (Settings → SlyTranslate).
 *
 * Thin consumer of the existing configure pipeline: the page is a small
 * wp.element app that reads and writes exclusively through the
 * ai-translate/configure REST bridge (ConfigurationService::save() stays the
 * single source of truth, including the direct-API probe side effects and
 * SSRF validation). No options.php form, no second configuration path.
 */
class SettingsPage {

	public const PAGE_SLUG     = 'slytranslate';
	public const SCRIPT_HANDLE = 'slytranslate-settings';

	public static function add_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function register_settings_page(): void {
		add_options_page(
			__( 'SlyTranslate', 'slytranslate' ),
			__( 'SlyTranslate', 'slytranslate' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	public static function render_page(): void {
		echo '<div class="wrap" id="slytranslate-settings-root">';
		echo '<h1>' . esc_html__( 'SlyTranslate', 'slytranslate' ) . '</h1>';
		echo '<p>' . esc_html__( 'Loading settings …', 'slytranslate' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Enqueue the settings app only on its own admin page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== (string) $hook_suffix ) {
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

		wp_localize_script( self::SCRIPT_HANDLE, 'slyTranslateSettings', self::get_bootstrap_data() );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				self::SCRIPT_HANDLE,
				'slytranslate',
				plugin_dir_path( dirname( __DIR__ ) . '/slytranslate.php' ) . 'languages'
			);
		}
	}

	/**
	 * Static environment facts for the app. Everything editable lives in the
	 * configure payload; this only carries what a page load can know cheaply:
	 * detected plugins, availability flags, and rendering hints.
	 */
	public static function get_bootstrap_data(): array {
		$adapter = AI_Translate::get_adapter();

		return array(
			'abilitiesRunBasePath'       => '/' . Plugin::REST_NAMESPACE . '/',
			'pluginVersion'              => Plugin::VERSION,
			'languagePlugin'             => self::get_language_plugin_label( $adapter ),
			'serverTranslationAvailable' => AI_Translate::is_server_translation_ui_available(),
			'isStringTableAdapter'       => $adapter instanceof StringTableContentAdapter,
			'fieldPlugins'               => self::get_active_field_plugins(),
			'defaultPromptTemplate'      => AI_Translate::get_default_prompt(),
			'strings'                    => self::get_ui_strings(),
		);
	}


	/**
	 * Server-side translated UI strings for the settings app, keyed by their
	 * English source text. Passed via wp_localize_script so the JS needs no
	 * separate translation files (same pattern as the editor bootstrap).
	 *
	 * @return array<string, string>
	 */
	private static function get_ui_strings(): array {
		return array(
			'AI client available' => __( 'AI client available', 'slytranslate' ),
			'AI client missing — install an AI connector for in-WordPress translation' => __( 'AI client missing — install an AI connector for in-WordPress translation', 'slytranslate' ),
			'Add glossary entry' => __( 'Add glossary entry', 'slytranslate' ),
			'Additional instructions (site-wide)' => __( 'Additional instructions (site-wide)', 'slytranslate' ),
			'Additional keys' => __( 'Additional keys', 'slytranslate' ),
			'Advanced' => __( 'Advanced', 'slytranslate' ),
			'Appended to every translation request, e.g. tone or brand wording.' => __( 'Appended to every translation request, e.g. tone or brand wording.', 'slytranslate' ),
			'Automatically translate new posts on publish (as draft)' => __( 'Automatically translate new posts on publish (as draft)', 'slytranslate' ),
			'Automation' => __( 'Automation', 'slytranslate' ),
			'Built-in default' => __( 'Built-in default', 'slytranslate' ),
			'Clear (space-separated meta keys)' => __( 'Clear (space-separated meta keys)', 'slytranslate' ),
			'Cleared on translation' => __( 'Cleared on translation', 'slytranslate' ),
			'Concurrency probe failed.' => __( 'Concurrency probe failed.', 'slytranslate' ),
			'Connector default' => __( 'Connector default', 'slytranslate' ),
			'Context window override (tokens, 0 = automatic)' => __( 'Context window override (tokens, 0 = automatic)', 'slytranslate' ),
			'Default model' => __( 'Default model', 'slytranslate' ),
			'Detected automatically from your SEO and field plugins. Unchecking a field excludes it from translation; the filter API can still override this.' => __( 'Detected automatically from your SEO and field plugins. Unchecking a field excludes it from translation; the filter API can still override this.', 'slytranslate' ),
			'Direct API URL (OpenAI-compatible, optional)' => __( 'Direct API URL (OpenAI-compatible, optional)', 'slytranslate' ),
			'Direct API: chat_template_kwargs not detected' => __( 'Direct API: chat_template_kwargs not detected', 'slytranslate' ),
			'Direct API: chat_template_kwargs supported' => __( 'Direct API: chat_template_kwargs supported', 'slytranslate' ),
			'Effective:' => __( 'Effective:', 'slytranslate' ),
			'Errors' => __( 'Errors', 'slytranslate' ),
			'Failed to load settings.' => __( 'Failed to load settings.', 'slytranslate' ),
			'Field plugins' => __( 'Field plugins', 'slytranslate' ),
			'Filter API' => __( 'Filter API', 'slytranslate' ),
			'Fixed translation' => __( 'Fixed translation', 'slytranslate' ),
			'Glossary / do not translate' => __( 'Glossary / do not translate', 'slytranslate' ),
			'Keep unchanged' => __( 'Keep unchanged', 'slytranslate' ),
			'Level' => __( 'Level', 'slytranslate' ),
			'Load preview' => __( 'Load preview', 'slytranslate' ),
			'Manual key' => __( 'Manual key', 'slytranslate' ),
			'Meta fields' => __( 'Meta fields', 'slytranslate' ),
			'Mode' => __( 'Mode', 'slytranslate' ),
			'Model' => __( 'Model', 'slytranslate' ),
			'No SEO plugin detected' => __( 'No SEO plugin detected', 'slytranslate' ),
			'No language plugin detected' => __( 'No language plugin detected', 'slytranslate' ),
			'No parallel transport available.' => __( 'No parallel transport available.', 'slytranslate' ),
			'No translatable meta fields detected for this context.' => __( 'No translatable meta fields detected for this context.', 'slytranslate' ),
			'Only used by model profiles that require a direct endpoint (e.g. TranslateGemma). Saving runs a capability probe.' => __( 'Only used by model profiles that require a direct endpoint (e.g. TranslateGemma). Saving runs a capability probe.', 'slytranslate' ),
			'Placeholders: {FROM_CODE} and {TO_CODE}.' => __( 'Placeholders: {FROM_CODE} and {TO_CODE}.', 'slytranslate' ),
			'Preview for post ID' => __( 'Preview for post ID', 'slytranslate' ),
			'Probe: chat_template_kwargs not detected.' => __( 'Probe: chat_template_kwargs not detected.', 'slytranslate' ),
			'Probe: chat_template_kwargs supported.' => __( 'Probe: chat_template_kwargs supported.', 'slytranslate' ),
			'Prompt template' => __( 'Prompt template', 'slytranslate' ),
			'Recommended concurrency:' => __( 'Recommended concurrency:', 'slytranslate' ),
			'Refresh model list' => __( 'Refresh model list', 'slytranslate' ),
			'Remove' => __( 'Remove', 'slytranslate' ),
			'Reset to default' => __( 'Reset to default', 'slytranslate' ),
			'SEO plugin' => __( 'SEO plugin', 'slytranslate' ),
			'Save settings' => __( 'Save settings', 'slytranslate' ),
			'Saving failed.' => __( 'Saving failed.', 'slytranslate' ),
			'Settings saved.' => __( 'Settings saved.', 'slytranslate' ),
			'Showing resolution for post' => __( 'Showing resolution for post', 'slytranslate' ),
			'Speedup' => __( 'Speedup', 'slytranslate' ),
			'Status' => __( 'Status', 'slytranslate' ),
			'String-table concurrency (TranslatePress batches)' => __( 'String-table concurrency (TranslatePress batches)', 'slytranslate' ),
			'Term' => __( 'Term', 'slytranslate' ),
			'Test concurrency' => __( 'Test concurrency', 'slytranslate' ),
			'Translate (space-separated meta keys)' => __( 'Translate (space-separated meta keys)', 'slytranslate' ),
			'Translate post slugs' => __( 'Translate post slugs', 'slytranslate' ),
			'Translate taxonomy terms alongside content' => __( 'Translate taxonomy terms alongside content', 'slytranslate' ),
			'Translation' => __( 'Translation', 'slytranslate' ),
			'Translations (en=…, fr=…)' => __( 'Translations (en=…, fr=…)', 'slytranslate' ),
			'Transport diagnostics' => __( 'Transport diagnostics', 'slytranslate' ),
			'Wall time (ms)' => __( 'Wall time (ms)', 'slytranslate' ),
			'chunk size:' => __( 'chunk size:', 'slytranslate' ),
			'detected' => __( 'detected', 'slytranslate' ),
			'learned:' => __( 'learned:', 'slytranslate' ),
			'recommended:' => __( 'recommended:', 'slytranslate' ),
			'tokens' => __( 'tokens', 'slytranslate' ),
		);
	}

	/**
	 * Human-readable name of the active language plugin ('' = none detected).
	 */
	private static function get_language_plugin_label( ?TranslationPluginAdapter $adapter ): string {
		if ( $adapter instanceof PolylangAdapter ) {
			return 'Polylang';
		}
		if ( $adapter instanceof WpMultilangAdapter ) {
			return 'WP Multilang';
		}
		if ( $adapter instanceof WpglobusAdapter ) {
			return 'WPGlobus';
		}
		if ( $adapter instanceof TranslatePressAdapter ) {
			return 'TranslatePress';
		}
		if ( null !== $adapter ) {
			// Third-party adapter registered via slytranslate_adapter_candidates.
			$class_name = get_class( $adapter );
			$short_name = substr( (string) strrchr( '\\' . $class_name, '\\' ), 1 );
			return '' !== $short_name ? $short_name : $class_name;
		}
		return '';
	}

	/**
	 * Active field plugins, mirroring the gates in
	 * Plugin::register_optional_integrations().
	 *
	 * @return string[] Labels of detected field plugins.
	 */
	private static function get_active_field_plugins(): array {
		$plugins = array();
		if ( function_exists( 'acf_get_field' ) ) {
			$plugins[] = 'ACF';
		}
		if ( function_exists( 'rwmb_get_field_settings' ) ) {
			$plugins[] = 'Meta Box';
		}
		if ( function_exists( 'pods_api' ) ) {
			$plugins[] = 'Pods';
		}
		return $plugins;
	}

	private static function get_script_version(): string {
		$script_path  = plugin_dir_path( dirname( __DIR__ ) . '/slytranslate.php' ) . 'assets/settings-page.js';
		$script_mtime = file_exists( $script_path ) ? filemtime( $script_path ) : false;

		if ( false === $script_mtime ) {
			return Plugin::VERSION;
		}

		return Plugin::VERSION . '.' . (string) $script_mtime;
	}
}
