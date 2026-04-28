<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

class SeoPluginDetector {

	private const CONFIGS = array(
		'genesis' => array(
			'label'     => 'Genesis SEO',
			'translate' => array(
				'_genesis_title',
				'_genesis_description',
			),
			'clear'     => array(),
		),
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

	public static function get_plugin_configs(): array {
		$configs = apply_filters( 'slytranslate_seo_plugin_configs', self::CONFIGS );

		if ( ! is_array( $configs ) ) {
			return array();
		}

		$normalized_configs = array();

		foreach ( $configs as $plugin_key => $config ) {
			if ( ! is_string( $plugin_key ) || '' === trim( $plugin_key ) || ! is_array( $config ) ) {
				continue;
			}

			$normalized_configs[ $plugin_key ] = self::normalize_plugin_config( $plugin_key, $config );
		}

		return $normalized_configs;
	}

	public static function get_plugin_config( string $plugin_key ): array {
		$configs = self::get_plugin_configs();

		if ( '' === $plugin_key || ! isset( $configs[ $plugin_key ] ) ) {
			return self::get_empty_config();
		}

		return $configs[ $plugin_key ];
	}

	public static function get_filtered_plugin_config( string $plugin_key ): array {
		$config = self::get_plugin_config( $plugin_key );

		if ( '' === $config['key'] ) {
			return $config;
		}

		$config['translate'] = apply_filters( 'slytranslate_seo_meta_translate', $config['translate'], $plugin_key, $config );
		$config['clear']     = apply_filters( 'slytranslate_seo_meta_clear', $config['clear'], $plugin_key, $config );

		$config['translate'] = self::normalize_meta_keys( $config['translate'] );
		$config['clear']     = self::normalize_meta_keys( $config['clear'] );

		return $config;
	}

	public static function get_active_plugin_config(): array {
		return self::get_filtered_plugin_config( self::get_active_plugin_key() );
	}

	public static function resolve_runtime_plugin_config( array $meta_keys, string $active_plugin_key = '' ): array {
		$meta_keys   = self::normalize_meta_keys( $meta_keys );
		$plugin_keys = array();

		if ( '' !== $active_plugin_key && '' !== self::get_plugin_config( $active_plugin_key )['key'] ) {
			$plugin_keys[] = $active_plugin_key;
		}

		foreach ( self::get_plugin_configs() as $plugin_key => $config ) {
			if ( self::config_matches_meta_keys( $config, $meta_keys ) ) {
				$plugin_keys[] = $plugin_key;
			}
		}

		$plugin_keys = array_values( array_unique( $plugin_keys ) );

		if ( array() === $plugin_keys ) {
			return self::get_empty_config();
		}

		$labels    = array();
		$translate = array();
		$clear     = array();

		foreach ( $plugin_keys as $plugin_key ) {
			$config = self::get_filtered_plugin_config( $plugin_key );

			if ( '' === $config['key'] ) {
				continue;
			}

			$labels[]   = $config['label'];
			$translate  = array_merge( $translate, $config['translate'] );
			$clear      = array_merge( $clear, $config['clear'] );
		}

		if ( array() === $labels ) {
			return self::get_empty_config();
		}

		return array(
			'key'          => '' !== $active_plugin_key ? $active_plugin_key : ( 1 === count( $plugin_keys ) ? $plugin_keys[0] : '' ),
			'label'        => implode( ', ', array_values( array_unique( $labels ) ) ),
			'translate'    => self::normalize_meta_keys( $translate ),
			'clear'        => self::normalize_meta_keys( $clear ),
			'matched_keys' => $plugin_keys,
		);
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

	private static function normalize_plugin_config( string $plugin_key, array $config ): array {
		return array(
			'key'          => $plugin_key,
			'label'        => isset( $config['label'] ) ? (string) $config['label'] : $plugin_key,
			'translate'    => self::normalize_meta_keys( $config['translate'] ?? array() ),
			'clear'        => self::normalize_meta_keys( $config['clear'] ?? array() ),
			'matched_keys' => array( $plugin_key ),
		);
	}

	private static function config_matches_meta_keys( array $config, array $meta_keys ): bool {
		if ( array() === $meta_keys ) {
			return false;
		}

		$supported_meta_keys = array_merge( $config['translate'] ?? array(), $config['clear'] ?? array() );

		return array() !== array_intersect( $meta_keys, $supported_meta_keys );
	}

	private static function get_empty_config(): array {
		return array(
			'key'          => '',
			'label'        => '',
			'translate'    => array(),
			'clear'        => array(),
			'matched_keys' => array(),
		);
	}

	public static function normalize_meta_keys( $meta_keys ): array {
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
