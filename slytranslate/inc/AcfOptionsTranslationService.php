<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Translates ACF fields on options pages (global content: footer texts, CTAs,
 * contact blocks, …) that live in wp_options instead of post meta and are
 * therefore never seen by AcfMetaResolver.
 *
 * Write strategy depends on the active language plugin:
 *  - TranslatePress: the string table already translates frontend output of
 *    option values — nothing to do, the ability reports not_required.
 *  - WPGlobus / WP Multilang: inline language markup is merged into the
 *    option value via the adapters' merge_language_variant() helper.
 *  - Polylang (and others without language-aware option storage): options are
 *    not language-capable. The established pattern is a per-language
 *    'options_{lang}' post_id via acf/settings/current_language; that storage
 *    is opt-in through the slytranslate_acf_options_post_id filter. Without
 *    the filter such fields are reported as skipped, never overwritten.
 */
class AcfOptionsTranslationService {

	public static function is_available(): bool {
		return function_exists( 'acf_get_options_pages' )
			&& function_exists( 'acf_get_field_groups' )
			&& function_exists( 'acf_get_fields' );
	}

	/**
	 * Translate all translatable ACF option fields into one target language.
	 *
	 * @param array $input target_language (required), source_language,
	 *                     page_slug, dry_run.
	 * @return array|\WP_Error
	 */
	public static function translate_options( array $input ): array|\WP_Error {
		if ( ! self::is_available() ) {
			return new \WP_Error( 'acf_options_unavailable', __( 'ACF options pages are not available on this site.', 'slytranslate' ) );
		}

		$to = isset( $input['target_language'] ) ? sanitize_key( (string) $input['target_language'] ) : '';
		if ( '' === $to ) {
			return new \WP_Error( 'missing_target_language', __( 'target_language is required.', 'slytranslate' ) );
		}

		$from = isset( $input['source_language'] ) ? sanitize_key( (string) $input['source_language'] ) : '';
		if ( '' === $from ) {
			$from = 'en';
		}
		if ( $from === $to ) {
			return new \WP_Error( 'same_language', __( 'Source and target languages must be different.', 'slytranslate' ) );
		}

		$page_slug = isset( $input['page_slug'] ) ? sanitize_key( (string) $input['page_slug'] ) : '';
		$dry_run   = ! empty( $input['dry_run'] );
		$adapter   = AI_Translate::get_adapter();

		// TranslatePress translates the rendered frontend output of option
		// values through its string table; writing option values would only
		// duplicate that work.
		if ( $adapter instanceof TranslatePressAdapter ) {
			return array(
				'status'          => 'not_required',
				'message'         => __( 'TranslatePress translates option output via its string table; option values need no separate translation.', 'slytranslate' ),
				'target_language' => $to,
				'source_language' => $from,
				'dry_run'         => $dry_run,
				'results'         => array(),
				'total'           => 0,
				'translated'      => 0,
				'skipped'         => 0,
				'failed'          => 0,
			);
		}

		$option_fields = self::collect_translatable_option_fields( $page_slug );

		$results    = array();
		$translated = 0;
		$skipped    = 0;
		$failed     = 0;

		foreach ( $option_fields as $entry ) {
			if ( TranslationProgressTracker::is_cancelled() ) {
				return new \WP_Error( 'translation_cancelled', 'Translation cancelled.' );
			}

			$field      = $entry['field'];
			$field_name = (string) $field['name'];
			$raw_value  = self::read_option_value( $field_name );

			if ( ! is_string( $raw_value ) || '' === trim( $raw_value ) ) {
				++$skipped;
				$results[] = self::result_entry( $entry, 'skipped', 'empty_value' );
				continue;
			}

			$supports_inline = $adapter instanceof WpglobusAdapter || $adapter instanceof WpMultilangAdapter;
			$source_text     = $supports_inline
				? $adapter->get_language_variant( $raw_value, $from )
				: $raw_value;

			if ( '' === trim( $source_text ) ) {
				++$skipped;
				$results[] = self::result_entry( $entry, 'skipped', 'no_source_variant' );
				continue;
			}

			// Without language-aware option storage the only safe write target
			// is a per-language post_id provided via filter (Polylang pattern:
			// 'options_{lang}' through acf/settings/current_language).
			$target_post_id = 'options';
			if ( ! $supports_inline ) {
				$target_post_id = (string) apply_filters( 'slytranslate_acf_options_post_id', 'options', $to, $field, $adapter );
				if ( 'options' === $target_post_id ) {
					++$skipped;
					$results[] = self::result_entry( $entry, 'skipped', 'requires_options_post_id_filter' );
					continue;
				}
			}

			if ( $dry_run ) {
				++$translated;
				$results[] = self::result_entry( $entry, 'pending' );
				continue;
			}

			$translated_text = TranslationRuntime::translate_text( $source_text, $to, $from, '' );
			if ( is_wp_error( $translated_text ) ) {
				if ( 'translation_cancelled' === $translated_text->get_error_code() ) {
					return $translated_text;
				}
				++$failed;
				$results[] = self::result_entry( $entry, 'failed', $translated_text->get_error_code() );
				continue;
			}

			if ( $supports_inline ) {
				$new_value = $adapter->merge_language_variant( $raw_value, $from, $to, (string) $translated_text );
				self::write_option_value( $field, $new_value, 'options' );
			} else {
				self::write_option_value( $field, (string) $translated_text, $target_post_id );
			}

			++$translated;
			$results[] = self::result_entry( $entry, 'translated' );
		}

		return array(
			'status'          => 'ok',
			'message'         => '',
			'target_language' => $to,
			'source_language' => $from,
			'dry_run'         => $dry_run,
			'results'         => $results,
			'total'           => count( $results ),
			'translated'      => $translated,
			'skipped'         => $skipped,
			'failed'          => $failed,
		);
	}

	/**
	 * Collect translatable fields across all (or one) ACF options pages.
	 *
	 * Only top-level fields with plainly translatable types are returned;
	 * structured types and nested repeater/group sub-fields are out of scope
	 * for the options path.
	 *
	 * @return array<int, array{page: string, field: array}>
	 */
	public static function collect_translatable_option_fields( string $page_slug = '' ): array {
		$pages = acf_get_options_pages();
		if ( ! is_array( $pages ) || empty( $pages ) ) {
			return array();
		}

		$collected = array();
		$seen_keys = array();

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}

			$slug = (string) ( $page['menu_slug'] ?? '' );
			if ( '' === $slug || ( '' !== $page_slug && $slug !== $page_slug ) ) {
				continue;
			}

			$groups = acf_get_field_groups( array( 'options_page' => $slug ) );
			if ( ! is_array( $groups ) ) {
				continue;
			}

			foreach ( $groups as $group ) {
				$fields = acf_get_fields( $group );
				if ( ! is_array( $fields ) ) {
					continue;
				}

				foreach ( $fields as $field ) {
					if ( ! is_array( $field ) || ! isset( $field['name'], $field['type'] ) ) {
						continue;
					}

					$field_key = (string) ( $field['key'] ?? $field['name'] );
					if ( isset( $seen_keys[ $field_key ] ) ) {
						continue;
					}

					if ( ! AcfFieldIntrospector::is_translatable_field( $field, false ) ) {
						continue;
					}

					$seen_keys[ $field_key ] = true;
					$collected[]             = array(
						'page'  => $slug,
						'field' => $field,
					);
				}
			}
		}

		return $collected;
	}

	/**
	 * Raw stored option value for an ACF options field.
	 */
	private static function read_option_value( string $field_name ) {
		if ( function_exists( 'get_field' ) ) {
			return get_field( $field_name, 'options', false );
		}
		return get_option( 'options_' . $field_name, '' );
	}

	/**
	 * Persist a translated option value through ACF when possible (keeps the
	 * field reference keys intact), falling back to the raw option.
	 */
	private static function write_option_value( array $field, string $value, string $post_id ): void {
		if ( function_exists( 'update_field' ) ) {
			update_field( (string) ( $field['key'] ?? $field['name'] ), $value, $post_id );
			return;
		}

		$prefix = 'options' === $post_id ? 'options' : $post_id;
		update_option( $prefix . '_' . (string) $field['name'], $value, false );
	}

	/**
	 * @param array{page: string, field: array} $entry
	 */
	private static function result_entry( array $entry, string $status, string $reason = '' ): array {
		return array(
			'field'       => (string) $entry['field']['name'],
			'field_label' => (string) ( $entry['field']['label'] ?? '' ),
			'field_type'  => (string) ( $entry['field']['type'] ?? '' ),
			'page'        => $entry['page'],
			'status'      => $status,
			'reason'      => $reason,
		);
	}
}
