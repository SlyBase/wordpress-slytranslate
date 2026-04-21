<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove all plugin options.
$options = array(
	'ai_translate_prompt',
	'ai_translate_prompt_addon',
	'ai_translate_meta_translate',
	'ai_translate_meta_clear',
	'ai_translate_new_post',
	'ai_translate_context_window_tokens',
	'ai_translate_model_slug',
	'ai_translate_direct_api_url',
	'ai_translate_force_direct_api',
	'ai_translate_direct_api_kwargs_detected',
	'ai_translate_direct_api_kwargs_last_probed_at',
	'ai_translate_direct_api_models_last_probed_at',
	'ai_translate_learned_context_windows',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove per-user additional-prompt preferences.
delete_metadata( 'user', 0, '_ai_translate_last_additional_prompt', '', true );

// Remove transients.
delete_transient( 'ai_translate_available_models' );
