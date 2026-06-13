<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Central plugin-wide constants.
 *
 * All other classes must reference Plugin::VERSION, Plugin::REST_NAMESPACE,
 * and Plugin::EDITOR_SCRIPT instead of duplicating the strings.
 */
final class Plugin {
	public const VERSION        = '1.11.0';
	public const REST_NAMESPACE = 'ai-translate/v1';
	public const EDITOR_SCRIPT  = 'ai-translate-editor';

	public static function register_optional_integrations(): void {
		if ( function_exists( 'acf_get_field' ) ) {
			AcfMetaResolver::register();
		}
		if ( function_exists( 'rwmb_get_field_settings' ) ) {
			MetaBoxMetaResolver::register();
		}
		if ( function_exists( 'pods_api' ) ) {
			PodsMetaResolver::register();
		}
	}
}
