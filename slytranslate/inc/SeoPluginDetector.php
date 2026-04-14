<?php

namespace AI_Translate;

if ( ! defined( 'ABSPATH' ) ) exit;

class SeoPluginDetector {

	private const CONFIGS = array(
		'yoast' => array(
			'label'     => 'Yoast SEO',
			'translate' => array(
				'_yoast_wpseo_title',
				'_yoast_wpseo_metadesc',
				'_yoast_wpseo_focuskw',
				'_yoast_wpseo_opengraph-title',
				'_yoast_wpseo_opengraph-description',
				'_yoast_wpseo_twitter-title',
				'_yoast_wpseo_twitter-description',
			),
			'clear'     => array(
				'_yoast_wpseo_linkdex',
				'_yoast_wpseo_content_score',
				'_yoast_wpseo_estimated-reading-time',
			),
		),
		'rank-math' => array(
			'label'     => 'Rank Math',
			'translate' => array(
				'rank_math_title',
				'rank_math_description',
				'rank_math_focus_keyword',
				'rank_math_og_title',
				'rank_math_og_description',
				'rank_math_twitter_title',
				'rank_math_twitter_description',
			),
			'clear'     => array(
				'rank_math_seo_score',
				'rank_math_readability_score',
			),
		),
		'aioseo' => array(
			'label'     => 'All in One SEO',
			'translate' => array(
				'_aioseo_title',
				'_aioseo_description',
				'_aioseo_keywords',
				'_aioseo_og_title',
				'_aioseo_og_description',
				'_aioseo_twitter_title',
				'_aioseo_twitter_description',
			),
			'clear'     => array(
				'_aioseo_score',
				'_aioseo_readability_score',
			),
		),
		'the-seo-framework' => array(
			'label'     => 'The SEO Framework',
			'translate' => array(
				'_tsf_title_no_blogname',
				'_tsf_title',
				'_tsf_description',
				'_tsf_kw_research_personal',
			),
			'clear'     => array(
				'_tsf_counter_page_score',
			),
		),
		'seopress' => array(
			'label'     => 'SEOpress',
			'translate' => array(
				'_seopress_titles_title',
				'_seopress_titles_desc',
				'_seopress_analysis_target_kw',
				'_seopress_social_fb_title',
				'_seopress_social_fb_desc',
				'_seopress_social_twitter_title',
				'_seopress_social_twitter_desc',
			),
			'clear'     => array(
				'_seopress_content_analysis_api',
				'_seopress_content_analysis_api_in_progress',
				'_seopress_analysis_data',
				'_seopress_analysis_data_oxygen',
			),
		),
		'slim-seo' => array(
			'label'     => 'Slim SEO',
			'translate' => array(
				'slim_seo',
			),
			'clear'     => array(),
		),
	);

	public static function get_active_plugin_config(): array {
		$plugin_key = self::get_active_plugin_key();
		$configs    = apply_filters( 'ai_translate_seo_plugin_configs', self::CONFIGS );

		if ( ! is_array( $configs ) || '' === $plugin_key || ! isset( $configs[ $plugin_key ] ) || ! is_array( $configs[ $plugin_key ] ) ) {
			return self::get_empty_config();
		}

		$config              = $configs[ $plugin_key ];
		$config['key']       = $plugin_key;
		$config['label']     = isset( $config['label'] ) ? (string) $config['label'] : $plugin_key;
		$config['translate'] = self::normalize_meta_keys( $config['translate'] ?? array() );
		$config['clear']     = self::normalize_meta_keys( $config['clear'] ?? array() );

		return $config;
	}

	public static function get_active_plugin_key(): string {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( '\\WPSEO_Meta' ) ) {
			return 'yoast';
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath' ) || class_exists( '\\RankMath\\Helper' ) ) {
			return 'rank-math';
		}

		if ( defined( 'AIOSEO_VERSION' ) || class_exists( '\\AIOSEO\\Plugin\\Common\\Main' ) || function_exists( 'aioseo' ) ) {
			return 'aioseo';
		}

		if ( defined( 'THE_SEO_FRAMEWORK_PRESENT' ) || class_exists( '\\The_SEO_Framework\\Load' ) || function_exists( 'the_seo_framework' ) ) {
			return 'the-seo-framework';
		}

		if ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_get_service' ) || class_exists( '\\SEOPress\\Core\\Kernel' ) ) {
			return 'seopress';
		}

		if ( defined( 'SLIM_SEO_VER' ) || defined( 'SLIM_SEO_DIR' ) || class_exists( '\\SlimSEO\\Container' ) ) {
			return 'slim-seo';
		}

		return '';
	}

	private static function get_empty_config(): array {
		return array(
			'key'       => '',
			'label'     => '',
			'translate' => array(),
			'clear'     => array(),
		);
	}

	private static function normalize_meta_keys( $meta_keys ): array {
		if ( ! is_array( $meta_keys ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $meta_keys as $meta_key ) {
			if ( ! is_string( $meta_key ) ) {
				continue;
			}

			$meta_key = trim( $meta_key );
			if ( '' === $meta_key ) {
				continue;
			}

			$normalized[] = $meta_key;
		}

		return array_values( array_unique( $normalized ) );
	}
}