<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all plugin options with the WordPress Settings API.
 *
 * Called during admin_init so that options are sanitized on save and
 * visible in the REST /wp/v2/settings endpoint for manage_options users.
 */
class Settings {

	/** Option group shared by all AI Translate settings. */
	private const OPTION_GROUP = 'ai_translate_options';

	public static function register(): void {
		$g = self::OPTION_GROUP;

		register_setting( $g, 'ai_translate_prompt',                           array( 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ) );
		register_setting( $g, 'ai_translate_prompt_addon',                     array( 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ) );
		register_setting( $g, 'ai_translate_meta_translate',                   array( 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ) );
		register_setting( $g, 'ai_translate_meta_clear',                       array( 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ) );
		register_setting( $g, 'ai_translate_new_post',                         array( 'sanitize_callback' => 'sanitize_key',            'default' => '0' ) );
		register_setting( $g, 'ai_translate_context_window_tokens',            array( 'sanitize_callback' => 'absint',                  'default' => 0 ) );
		register_setting( $g, 'ai_translate_model_slug',                       array( 'sanitize_callback' => 'sanitize_text_field',     'default' => '' ) );
		register_setting( $g, 'ai_translate_direct_api_url',                   array( 'sanitize_callback' => 'esc_url_raw',             'default' => '' ) );
		register_setting( $g, 'ai_translate_force_direct_api',                 array( 'sanitize_callback' => 'sanitize_key',            'default' => '0' ) );
		register_setting( $g, 'ai_translate_direct_api_kwargs_detected',       array( 'sanitize_callback' => 'sanitize_key',            'default' => '0' ) );
		register_setting( $g, 'ai_translate_direct_api_kwargs_last_probed_at', array( 'sanitize_callback' => 'absint',                  'default' => 0 ) );
		register_setting( $g, 'ai_translate_direct_api_models_last_probed_at', array( 'sanitize_callback' => 'absint',                  'default' => 0 ) );
		register_setting( $g, 'ai_translate_learned_context_windows',          array( 'sanitize_callback' => null,                      'default' => array() ) );
	}
}
