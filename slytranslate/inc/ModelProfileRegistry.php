<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the built-in model-profile definitions used by TranslationRuntime.
 */
final class ModelProfileRegistry {
	private const REQUEST_MODE_SYSTEM_PLUS_USER = 'system_plus_user';
	private const REQUEST_MODE_USER_ONLY        = 'user_only';
	private const PROMPT_STYLE_GENERIC_TEMPLATE = 'generic_template';
	private const PROMPT_STYLE_BILINGUAL_FRAME  = 'bilingual_frame';
	private const CHUNK_STRATEGY_DEFAULT        = 'default';
	private const CHUNK_STRATEGY_TOWER          = 'tower_conservative';

	/**
	 * Shared baseline for all known model families.
	 */
	private static function get_universal_profile_defaults(): array {
		return array(
			'request_mode'               => self::REQUEST_MODE_USER_ONLY,
			'prompt_style'               => self::PROMPT_STYLE_BILINGUAL_FRAME,
			'supports_system_role'       => false,
			'supports_chat_completions'  => true,
			'requires_strict_direct_api' => false,
			'requires_chat_template_kwargs' => false,
			'extra_request_body'         => array(),
			'chunk_strategy'             => self::CHUNK_STRATEGY_DEFAULT,
			'max_chunk_chars'            => 0,
			'temperature'                => 0,
			'retry_profile'              => array(
				'retry_on_validation_failure' => true,
				'retry_on_passthrough_de'     => true,
				'reduce_chunk_on_retry'       => true,
				'retry_chunk_chars'           => 1800,
			),
		);
	}

	/**
	 * Build a concrete profile from universal defaults and sparse overrides.
	 */
	private static function build_profile( string $id, array $matchers, array $overrides = array() ): array {
		$base    = self::get_universal_profile_defaults();
		$profile = array_merge(
			$base,
			array(
				'id'       => $id,
				'matchers' => $matchers,
			),
			$overrides
		);

		if ( isset( $overrides['retry_profile'] ) && is_array( $overrides['retry_profile'] ) ) {
			$profile['retry_profile'] = array_merge( $base['retry_profile'], $overrides['retry_profile'] );
		}

		if ( isset( $overrides['extra_request_body'] ) && is_array( $overrides['extra_request_body'] ) ) {
			$profile['extra_request_body'] = array_merge( $base['extra_request_body'], $overrides['extra_request_body'] );
		}

		return $profile;
	}

	private static function get_conservative_warm_overrides(): array {
		return array(
			'chunk_strategy'  => self::CHUNK_STRATEGY_TOWER,
			'max_chunk_chars' => 1200,
			'temperature'     => 0.2,
			'retry_profile'   => array(
				'retry_chunk_chars' => 1200,
			),
		);
	}

	private static function get_default_thinking_overrides(): array {
		return array(
			'extra_request_body' => array(
				'chat_template_kwargs' => array(
					'enable_thinking' => false,
				),
			),
		);
	}

	/**
	 * Return the built-in model profiles before external filters are applied.
	 */
	public static function get_default_profiles(): array {
		return array(
			self::build_profile(
				'translategemma',
				array( 'translategemma' ),
				array(
					'prompt_style'               => self::PROMPT_STYLE_GENERIC_TEMPLATE,
					'requires_chat_template_kwargs' => true,
					'extra_request_body'         => array(
						'chat_template_kwargs' => array(
							'source_lang_code' => '{source_lang_code}',
							'target_lang_code' => '{target_lang_code}',
						),
					),
					'retry_profile'              => array(
						'retry_on_validation_failure' => false,
						'retry_on_passthrough_de'     => false,
						'reduce_chunk_on_retry'       => false,
					),
				)
			),
			self::build_profile( 'towerinstruct', array( 'towerinstruct' ), self::get_conservative_warm_overrides() ),
			self::build_profile( 'salamandra', array( 'salamandrata', 'salamandra' ), self::get_conservative_warm_overrides() ),
			self::build_profile(
				'madlad',
				array( 'madlad400', 'madlad-400', 'madlad' ),
				array(
					'supports_chat_completions' => false,
					'chunk_strategy'            => self::CHUNK_STRATEGY_TOWER,
					'max_chunk_chars'           => 1400,
					'temperature'               => 0.2,
					'retry_profile'             => array(
						'retry_on_validation_failure' => false,
						'retry_on_passthrough_de'     => false,
						'reduce_chunk_on_retry'       => false,
						'retry_chunk_chars'           => 0,
					),
				)
			),
			self::build_profile(
				'nemotron_system',
				array( 'nvidia/nemotron', 'nvidia-nemotron', 'nemotron' ),
				array(
					'request_mode'       => self::REQUEST_MODE_SYSTEM_PLUS_USER,
					'prompt_style'       => self::PROMPT_STYLE_GENERIC_TEMPLATE,
					'supports_system_role' => true,
					'extra_request_body' => array(
						'chat_template_kwargs' => array(
							'enable_thinking' => false,
						),
					),
					'retry_profile'      => array(
						'retry_chunk_chars' => 140,
					),
				)
			),
			self::build_profile(
				'qwen_thinking_aware',
				array( 'qwen3.5', 'qwen3.6', 'qwen' ),
				array_merge(
					self::get_default_thinking_overrides(),
					array(
						'chunk_strategy'  => self::CHUNK_STRATEGY_TOWER,
						'max_chunk_chars' => 2400,
						'retry_profile'   => array(
							'retry_chunk_chars' => 1400,
						),
					)
				)
			),
			self::build_profile( 'glm_thinking_aware', array( 'glm-4.6v', 'glm' ), self::get_default_thinking_overrides() ),
			self::build_profile( 'gemma4_thinking_aware', array( 'gemma-4-e4b-it', 'gemma-4' ), self::get_default_thinking_overrides() ),
			self::build_profile( 'phi4_thinking_aware', array( 'phi-4-mini', 'phi-4', 'phi4' ), self::get_default_thinking_overrides() ),
			self::build_profile(
				'eurollm',
				array( 'eurollm' ),
				array(
					'chunk_strategy'  => self::CHUNK_STRATEGY_TOWER,
					'max_chunk_chars' => 2600,
					'retry_profile'   => array(
						'retry_chunk_chars' => 1400,
					),
				)
			),
			self::build_profile(
				'llama31',
				array( 'llama-3.1-8b-instruct', 'sauerkrautlm-8b-instruct', 'sauerkrautlm' ),
				array(
					'chunk_strategy'  => self::CHUNK_STRATEGY_TOWER,
					'max_chunk_chars' => 2400,
					'retry_profile'   => array(
						'retry_chunk_chars' => 1400,
					),
				)
			),
			self::build_profile(
				'ministral3',
				array( 'ministral-3-3b', 'ministral-3' ),
				array(
					'chunk_strategy'  => self::CHUNK_STRATEGY_TOWER,
					'max_chunk_chars' => 2400,
					'retry_profile'   => array(
						'retry_chunk_chars' => 1400,
					),
				)
			),
			self::build_profile( 'ministral', array( 'ministral', 'ministral-8b', 'ministral-8b-instruct' ) ),
		);
	}
}
