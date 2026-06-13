<?php

namespace SlyTranslate;

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

	private const INTERNAL_META_PREFIXES_TO_SKIP = array(
		'_oembed_',
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
	 * @param array $extra_candidates Pre-validated key→value pairs to include in the
	 *                                 batch alongside regular meta (e.g. post title and
	 *                                 excerpt). Keys must be unique pseudo-keys that do
	 *                                 not exist in real post meta. Successful batch
	 *                                 translations are returned as part of the result so
	 *                                 the caller can extract and clean them up.
	 * @return array|\WP_Error
	 */
	public static function prepare_translation_meta(
		int $post_id,
		string $to,
		string $from,
		string $additional_prompt,
		array $all_meta = array(),
		array $extra_candidates = array()
	): array|\WP_Error {
		$meta            = ! empty( $all_meta ) ? $all_meta : get_post_meta( $post_id );
		$processed_meta  = array();
		$meta_key_config = self::get_effective_meta_key_config( $post_id, is_array( $meta ) ? $meta : array(), $from, $to );

		$meta_calls_before = (int) ( TimingLogger::get_counters()['ai_calls'] ?? 0 );
		TimingLogger::log( 'meta_start', array(
			'post'           => $post_id,
			'key_count'      => is_array( $meta ) ? count( $meta ) : 0,
			'translate_keys' => count( $meta_key_config['translate'] ),
			'clear_keys'     => count( $meta_key_config['clear'] ),
		) );
		$meta_started_at = TimingLogger::start();

		// Attempt to translate eligible short string meta values in one
		// batched AI call. Falls back to individual calls on any failure.
		$batch_results = self::try_batch_translate_eligible_meta(
			is_array( $meta ) ? $meta : array(),
			$meta_key_config,
			$to,
			$from,
			$additional_prompt,
			$extra_candidates
		);

		foreach ( $meta as $key => $values ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			if ( self::should_skip_meta_key( (string) $key ) ) {
				continue;
			}

			$value = maybe_unserialize( $values[0] ?? '' );

			if ( in_array( $key, $meta_key_config['clear'], true ) ) {
				$processed_meta[ $key ] = '';
			} elseif ( in_array( $key, $meta_key_config['translate'], true ) ) {
				// Use the batch result when available for this key.
				if ( is_array( $batch_results ) && array_key_exists( $key, $batch_results ) ) {
					$processed_meta[ $key ] = $batch_results[ $key ];
					TimingLogger::log( 'meta_key_done', array(
						'key'         => $key,
						'subcalls'    => 0,
						'duration_ms' => 0,
						'chars'       => self::sum_value_chars( $value ),
						'ok'          => true,
						'batch'       => true,
					) );
					continue;
				}

				// Individual translation (also handles array values and slim_seo).
				$key_started     = TimingLogger::start();
				$calls_before    = (int) ( TimingLogger::get_counters()['ai_calls'] ?? 0 );
				$translated_meta = self::translate_meta_value_for_key( $key, $value, $to, $from, $additional_prompt );
				$calls_after     = (int) ( TimingLogger::get_counters()['ai_calls'] ?? 0 );
				$ok              = ! is_wp_error( $translated_meta );
				TimingLogger::log( 'meta_key_done', array(
					'key'         => $key,
					'subcalls'    => $calls_after - $calls_before,
					'duration_ms' => TimingLogger::stop( $key_started ),
					'chars'       => self::sum_value_chars( $value ),
					'ok'          => $ok,
					'reason'      => $ok ? '' : $translated_meta->get_error_code(),
				) );
				$processed_meta[ $key ] = $ok ? $translated_meta : $value;
			} else {
				$processed_meta[ $key ] = $value;
			}
		}

		// Write extra_candidates batch results (e.g. _slytranslate_title) into
		// $processed_meta so the caller can extract and clean them up. Keys
		// that were not returned by the batch are omitted so the caller knows
		// to fall back to an individual translation call.
		if ( is_array( $batch_results ) && ! empty( $extra_candidates ) ) {
			foreach ( array_keys( $extra_candidates ) as $extra_key ) {
				if ( array_key_exists( $extra_key, $batch_results ) ) {
					$processed_meta[ $extra_key ] = $batch_results[ $extra_key ];
				}
			}
		}

		$meta_calls_after = (int) ( TimingLogger::get_counters()['ai_calls'] ?? 0 );
		TimingLogger::log( 'meta_end', array(
			'post'        => $post_id,
			'duration_ms' => TimingLogger::stop( $meta_started_at ),
			'total_calls' => $meta_calls_after - $meta_calls_before,
		) );

		return $processed_meta;
	}

	/**
	 * Count how many values are currently eligible for one meta batch call.
	 *
	 * Includes regular translatable meta values and optional extra candidates
	 * (e.g. deferred title/excerpt pseudo-keys).
	 */
	public static function count_batch_eligible_candidates(
		array $meta,
		array $meta_key_config,
		array $extra_candidates = array()
	): int {
		return count( self::collect_batch_candidates( $meta, $meta_key_config, $extra_candidates ) );
	}

	/**
	 * Try to translate all eligible short string meta values in a single AI call.
	 *
	 * "Eligible" means: the meta key is in the translate list, the value is a
	 * non-empty string, it is short enough to fit in a single batch JSON payload
	 * (<= 1 000 chars), and it does not need the special slim_seo array handling.
	 *
	 * Extra candidates (pre-validated key→value pairs such as post title or
	 * excerpt) are merged in before the meta loop. They count toward the
	 * minimum-two threshold and are validated in the response like any other key.
	 *
	 * On any failure (AI error, JSON parse error, missing keys) the method
	 * returns null and the caller falls back to individual per-key translation.
	 *
	 * @return array<string,string>|null Translated values indexed by meta key, or null on failure.
	 */
	private static function try_batch_translate_eligible_meta(
		array $meta,
		array $meta_key_config,
		string $to,
		string $from,
		string $additional_prompt,
		array $extra_candidates = array()
	): ?array {
		$candidates = self::collect_batch_candidates( $meta, $meta_key_config, $extra_candidates );

		// Batching only pays off when there are at least two values.
		if ( count( $candidates ) < 2 ) {
			return null;
		}

		$json_input = wp_json_encode( $candidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json_input ) {
			return null;
		}

		// A concise additional instruction that clarifies the JSON contract
		// without overriding the user's own style instructions.
		$json_hint    = 'The input is a JSON object. Translate only the string values, not the keys. Return a valid JSON object with the identical keys and translated values. No explanations, no markdown wrappers — only the JSON.';
		$batch_prompt = '' !== trim( $additional_prompt )
			? $additional_prompt . "\n\n" . $json_hint
			: $json_hint;

		$calls_before = (int) ( TimingLogger::get_counters()['ai_calls'] ?? 0 );
		$result       = TranslationRuntime::translate_text( $json_input, $to, $from, $batch_prompt );
		$calls_after  = (int) ( TimingLogger::get_counters()['ai_calls'] ?? 0 );

		$ok = ! is_wp_error( $result );
		TimingLogger::log( 'meta_batch', array(
			'keys'     => array_keys( $candidates ),
			'subcalls' => $calls_after - $calls_before,
			'ok'       => $ok,
		) );

		if ( ! $ok ) {
			return null;
		}

		// Strip optional markdown code fences that some models emit around JSON.
		$result_str = trim( (string) $result );
		$result_str = (string) preg_replace( '/^```(?:json)?\s*/i', '', $result_str );
		$result_str = (string) preg_replace( '/\s*```\s*$/i', '', $result_str );
		$result_str = trim( $result_str );

		$decoded = json_decode( $result_str, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		// All original keys must be present with string values; otherwise fall back.
		foreach ( array_keys( $candidates ) as $key ) {
			if ( ! array_key_exists( $key, $decoded ) || ! is_string( $decoded[ $key ] ) ) {
				return null;
			}

			$validation = TranslationValidator::validate( $candidates[ $key ], $decoded[ $key ], $to );
			if ( is_wp_error( $validation ) ) {
				return null;
			}
		}

		return $decoded;
	}

	/**
	 * Build the candidate map for batched meta translation.
	 *
	 * @return array<string,string>
	 */
	private static function collect_batch_candidates(
		array $meta,
		array $meta_key_config,
		array $extra_candidates = array()
	): array {
		$candidates = array();

		// Pre-validated extra entries (e.g. title, excerpt) from the caller.
		foreach ( $extra_candidates as $key => $value ) {
			if ( is_string( $key ) && '' !== $key && is_string( $value ) && '' !== trim( $value ) ) {
				$candidates[ $key ] = $value;
			}
		}

		foreach ( $meta as $key => $values ) {
			if ( self::should_skip_meta_key( (string) $key ) ) {
				continue;
			}
			if ( ! in_array( $key, $meta_key_config['translate'], true ) ) {
				continue;
			}
			// slim_seo is an array with custom key-level handling — skip from batching.
			if ( 'slim_seo' === $key ) {
				continue;
			}

			$value = maybe_unserialize( $values[0] ?? '' );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}
			// Only batch short values; long strings get their own AI call.
			if ( self::sum_value_chars( $value ) > 1000 ) {
				continue;
			}

			$candidates[ $key ] = $value;
		}

		return $candidates;
	}

	private static function sum_value_chars( $value ): int {
		if ( is_string( $value ) ) {
			return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		}
		if ( is_array( $value ) ) {
			$total = 0;
			foreach ( $value as $item ) {
				$total += self::sum_value_chars( $item );
			}
			return $total;
		}
		return 0;
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

	public static function get_effective_meta_key_config( int $post_id = 0, ?array $post_meta = null, string $from = '', string $to = '' ): array {
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
			'translate' => self::merge_meta_keys( self::meta_keys( 'slytranslate_meta_translate' ), $seo_plugin_config['translate'] ),
			'clear'     => self::merge_meta_keys( self::meta_keys( 'slytranslate_meta_clear' ), $seo_plugin_config['clear'] ),
			'seo'       => $seo_plugin_config,
		);

		// Apply runtime filters so third-party code (e.g. AcfMetaResolver) can
		// add or remove keys without touching plugin settings.
		$post_meta_for_filter = is_array( $post_meta ) ? $post_meta : array();

		$translate = apply_filters( 'slytranslate_meta_keys_translate', $meta_key_config['translate'], $post_id, $from, $to, $post_meta_for_filter );
		$clear     = apply_filters( 'slytranslate_meta_keys_clear',     $meta_key_config['clear'],     $post_id, $from, $to, $post_meta_for_filter );

		$translate = SeoPluginDetector::normalize_meta_keys( is_array( $translate ) ? $translate : array() );
		$clear     = SeoPluginDetector::normalize_meta_keys( is_array( $clear ) ? $clear : array() );

		// Per-key filter: allows fine-grained control over individual keys.
		$filtered_translate = array();
		foreach ( $translate as $meta_key ) {
			if ( self::should_skip_meta_key( $meta_key ) ) {
				continue;
			}
			$include = apply_filters( 'slytranslate_translate_meta_key', true, $meta_key, $post_id, $from, $to, $post_meta_for_filter );
			if ( $include ) {
				$filtered_translate[] = $meta_key;
			}
		}

		$meta_key_config['translate'] = $filtered_translate;
		$meta_key_config['clear']     = $clear;

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
			if ( self::should_skip_meta_key( $meta_key ) ) {
				continue;
			}
			$meta_keys[] = $meta_key;
		}
		return SeoPluginDetector::normalize_meta_keys( $meta_keys );
	}

	private static function should_skip_meta_key( string $meta_key ): bool {
		if ( '' === $meta_key ) {
			return true;
		}

		if ( in_array( $meta_key, self::INTERNAL_META_KEYS_TO_SKIP, true ) ) {
			return true;
		}

		foreach ( self::INTERNAL_META_PREFIXES_TO_SKIP as $prefix ) {
			if ( str_starts_with( $meta_key, $prefix ) ) {
				return true;
			}
		}

		return false;
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

	/* ---------------------------------------------------------------
	 * Client-translation workflow helpers
	 * ------------------------------------------------------------- */

	/**
	 * Build translation units for all translatable meta fields of a post.
	 *
	 * Each returned unit has:
	 *   id          => "meta:{$key}"
	 *   field       => "meta"
	 *   meta_key    => raw meta key
	 *   source      => first scalar string value
	 *   format      => "plain_text"
	 *   lookup_keys => []
	 *
	 * Only simple (non-array) string values ≤ 1000 chars that are not in the
	 * skip list are included.  The caller is responsible for enforcing any
	 * total-payload budget.
	 *
	 * @param int   $post_id  Source post ID.
	 * @param array $all_meta Raw result of get_post_meta( $post_id ), i.e. values
	 *                        are single-element arrays.  Pass an empty array to skip
	 *                        meta entirely.
	 * @return array<array{id:string,field:string,meta_key:string,source:string,format:string,lookup_keys:array}>
	 */
	public static function build_meta_units( int $post_id, array $all_meta, string $from = '', string $to = '' ): array {
		if ( empty( $all_meta ) ) {
			return array();
		}

		$meta_key_config = self::get_effective_meta_key_config( $post_id, $all_meta, $from, $to );
		$translate_keys  = $meta_key_config['translate'];

		$units = array();
		foreach ( $translate_keys as $meta_key ) {
			if ( self::should_skip_meta_key( $meta_key ) ) {
				continue;
			}

			if ( ! isset( $all_meta[ $meta_key ] ) ) {
				continue;
			}

			// get_post_meta() wraps values in single-element arrays.
			$raw = $all_meta[ $meta_key ];
			if ( is_array( $raw ) ) {
				$raw = reset( $raw );
			}

			if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 1000 ) {
				continue;
			}

			$units[] = array(
				'id'          => 'meta:' . $meta_key,
				'field'       => 'meta',
				'meta_key'    => $meta_key,
				'source'      => $raw,
				'format'      => 'plain_text',
				'lookup_keys' => array(),
			);
		}

		return $units;
	}

	/**
	 * Merge translated meta units back into the $data array that is passed to
	 * TranslationPluginAdapter::create_translation().
	 *
	 * @param array $data         Existing $data['meta'] array (key => translated value).
	 * @param array $translations Flat map of unit id => translated string from the
	 *                            apply-client-translation payload.
	 * @return array Updated $data['meta'] map.
	 */
	public static function merge_translated_meta_units( array $data_meta, array $translations ): array {
		foreach ( $translations as $unit_id => $translated ) {
			if ( ! str_starts_with( $unit_id, 'meta:' ) ) {
				continue;
			}
			$meta_key = substr( $unit_id, strlen( 'meta:' ) );
			if ( '' === $meta_key || ! is_string( $translated ) ) {
				continue;
			}
			$data_meta[ $meta_key ] = $translated;
		}
		return $data_meta;
	}
}
