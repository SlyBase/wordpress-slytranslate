<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Meta-key resolution, translation and clearing for translated posts.
 *
 * Manages the effective meta-key sets (user-configured + SEO-plugin defaults),
 * translates or clears individual meta values, and owns the per-request cache
 * for resolved meta-key configurations.
 */
class MetaTranslationService {

	/* ---------------------------------------------------------------
	 * Constants
	 * ------------------------------------------------------------- */

	private const INTERNAL_META_KEYS_TO_SKIP = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_encloseme',
		'_pingme',
	);

	/* ---------------------------------------------------------------
	 * Per-request cache
	 * ------------------------------------------------------------- */

	private static $meta_translate          = null;
	private static $meta_clear              = null;
	private static $seo_plugin_config       = null;
	private static $resolved_meta_key_config = array();

	/* ---------------------------------------------------------------
	 * Public helpers used by AI_Translate and tests
	 * ------------------------------------------------------------- */

	public static function meta_translate( int $post_id = 0 ): array {
		if ( $post_id > 0 ) {
			return self::get_effective_meta_key_config( $post_id )['translate'];
		}

		if ( null === self::$meta_translate ) {
			self::$meta_translate = self::get_effective_meta_key_config()['translate'];
		}
		return self::$meta_translate;
	}

	public static function meta_clear( int $post_id = 0 ): array {
		if ( $post_id > 0 ) {
			return self::get_effective_meta_key_config( $post_id )['clear'];
		}

		if ( null === self::$meta_clear ) {
			self::$meta_clear = self::get_effective_meta_key_config()['clear'];
		}
		return self::$meta_clear;
	}

	public static function reset_cache(): void {
		self::$meta_translate           = null;
		self::$meta_clear               = null;
		self::$seo_plugin_config        = null;
		self::$resolved_meta_key_config = array();
	}

	/* ---------------------------------------------------------------
	 * Meta preparation (called from PostTranslationService)
	 * ------------------------------------------------------------- */

	/**
	 * Translate or clear all relevant meta fields for a post.
	 *
	 * @return array|\WP_Error
	 */
	public static function prepare_translation_meta(
		int $post_id,
		string $to,
		string $from,
		string $additional_prompt
	): mixed {
		$meta            = get_post_meta( $post_id );
		$processed_meta  = array();
		$meta_key_config = self::get_effective_meta_key_config( $post_id, is_array( $meta ) ? $meta : array() );

		foreach ( $meta as $key => $values ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			if ( in_array( $key, self::INTERNAL_META_KEYS_TO_SKIP, true ) ) {
				continue;
			}

			$value = maybe_unserialize( $values[0] ?? '' );

			if ( in_array( $key, $meta_key_config['clear'], true ) ) {
				$processed_meta[ $key ] = '';
			} elseif ( in_array( $key, $meta_key_config['translate'], true ) ) {
				$translated_meta        = self::translate_meta_value_for_key( $key, $value, $to, $from, $additional_prompt );
				$processed_meta[ $key ] = is_wp_error( $translated_meta ) ? $value : $translated_meta;
			} else {
				$processed_meta[ $key ] = $value;
			}
		}

		return $processed_meta;
	}

	/* ---------------------------------------------------------------
	 * Individual meta-value translation
	 * ------------------------------------------------------------- */

	public static function translate_meta_value( $value, string $to, string $from = 'en', string $additional_prompt = '' ): mixed {
		if ( is_string( $value ) ) {
			if ( '' === trim( $value ) ) {
				return $value;
			}
			return TranslationRuntime::translate_text( $value, $to, $from, $additional_prompt );
		}

		if ( is_array( $value ) ) {
			$translated_value = array();
			foreach ( $value as $k => $item ) {
				$translated_item = self::translate_meta_value( $item, $to, $from, $additional_prompt );
				if ( is_wp_error( $translated_item ) ) {
					return $translated_item;
				}
				$translated_value[ $k ] = $translated_item;
			}
			return $translated_value;
		}

		return $value;
	}

	public static function translate_meta_value_for_key(
		string $meta_key,
		$value,
		string $to,
		string $from = 'en',
		string $additional_prompt = ''
	): mixed {
		if ( 'slim_seo' === $meta_key && is_array( $value ) ) {
			$translated_value = $value;
			foreach ( array( 'title', 'description' ) as $field ) {
				if ( ! array_key_exists( $field, $translated_value ) ) {
					continue;
				}
				$translated_field = self::translate_meta_value( $translated_value[ $field ], $to, $from, $additional_prompt );
				if ( is_wp_error( $translated_field ) ) {
					return $translated_field;
				}
				$translated_value[ $field ] = $translated_field;
			}
			return $translated_value;
		}

		return self::translate_meta_value( $value, $to, $from, $additional_prompt );
	}

	/* ---------------------------------------------------------------
	 * Effective meta-key resolution
	 * ------------------------------------------------------------- */

	public static function get_active_seo_plugin_config(): array {
		if ( is_array( self::$seo_plugin_config ) ) {
			return self::$seo_plugin_config;
		}

		self::$seo_plugin_config = SeoPluginDetector::get_active_plugin_config();
		return self::$seo_plugin_config;
	}

	public static function get_effective_meta_key_config( int $post_id = 0, ?array $post_meta = null ): array {
		if ( $post_id > 0 && null === $post_meta && isset( self::$resolved_meta_key_config[ $post_id ] ) ) {
			return self::$resolved_meta_key_config[ $post_id ];
		}

		$seo_plugin_config = self::get_active_seo_plugin_config();

		if ( $post_id > 0 ) {
			if ( ! is_array( $post_meta ) ) {
				$post_meta = get_post_meta( $post_id );
			}

			$seo_plugin_config = SeoPluginDetector::resolve_runtime_plugin_config(
				self::get_runtime_source_meta_keys( is_array( $post_meta ) ? $post_meta : array() ),
				$seo_plugin_config['key']
			);
		}

		$meta_key_config = array(
			'translate' => self::merge_meta_keys( self::meta_keys( 'ai_translate_meta_translate' ), $seo_plugin_config['translate'] ),
			'clear'     => self::merge_meta_keys( self::meta_keys( 'ai_translate_meta_clear' ), $seo_plugin_config['clear'] ),
			'seo'       => $seo_plugin_config,
		);

		if ( $post_id > 0 ) {
			self::$resolved_meta_key_config[ $post_id ] = $meta_key_config;
		}

		return $meta_key_config;
	}

	private static function get_runtime_source_meta_keys( array $post_meta ): array {
		$meta_keys = array();
		foreach ( $post_meta as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) ) {
				continue;
			}
			if ( in_array( $meta_key, self::INTERNAL_META_KEYS_TO_SKIP, true ) ) {
				continue;
			}
			$meta_keys[] = $meta_key;
		}
		return SeoPluginDetector::normalize_meta_keys( $meta_keys );
	}

	/* ---------------------------------------------------------------
	 * Meta-key list helpers
	 * ------------------------------------------------------------- */

	public static function meta_keys( string $option ): array {
		$value = get_option( $option );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return SeoPluginDetector::normalize_meta_keys( preg_split( '/\s+/', trim( $value ) ) );
		}
		return array();
	}

	public static function merge_meta_keys( array ...$meta_key_sets ): array {
		$merged = array();
		foreach ( $meta_key_sets as $meta_keys ) {
			$merged = array_merge( $merged, SeoPluginDetector::normalize_meta_keys( $meta_keys ) );
		}
		return SeoPluginDetector::normalize_meta_keys( $merged );
	}
}
