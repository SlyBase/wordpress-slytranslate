<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in resolver that adds Meta Box (metabox.io) field values to the
 * translate list when Meta Box is active.
 *
 * Unlike ACF, Meta Box stores no 'field_…' reference keys in post meta, so
 * detection runs against the plugin's field registry via
 * rwmb_get_field_settings() instead of meta-key siblings.
 *
 * Only text/textarea/wysiwyg fields are added by default; extensible via the
 * slytranslate_metabox_translatable_field_types filter.
 */
class MetaBoxMetaResolver {

	private const TRANSLATABLE_TYPES = array(
		'text',
		'textarea',
		'wysiwyg',
	);

	public static function register(): void {
		add_filter( 'slytranslate_meta_keys_translate', array( self::class, 'add_metabox_translatable_keys' ), 10, 5 );
	}

	/**
	 * @param array  $translate  Current list of keys to translate.
	 * @param int    $post_id    Post ID (0 = no post context).
	 * @param string $from       Source locale (may be empty).
	 * @param string $to         Target locale (may be empty).
	 * @param array  $post_meta  Raw post meta (key => [value]).
	 * @return array Updated translate list.
	 */
	public static function add_metabox_translatable_keys( array $translate, int $post_id, string $from, string $to, array $post_meta ): array {
		if ( empty( $post_meta ) || ! function_exists( 'rwmb_get_field_settings' ) ) {
			return $translate;
		}

		$translatable_types = apply_filters( 'slytranslate_metabox_translatable_field_types', self::TRANSLATABLE_TYPES );
		$translatable_types = is_array( $translatable_types ) ? $translatable_types : self::TRANSLATABLE_TYPES;

		foreach ( $post_meta as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || str_starts_with( $meta_key, '_' ) || in_array( $meta_key, $translate, true ) ) {
				continue;
			}

			$settings = rwmb_get_field_settings( $meta_key, array( 'object_type' => 'post' ), $post_id );
			if ( ! is_array( $settings ) ) {
				continue;
			}

			if ( in_array( (string) ( $settings['type'] ?? '' ), $translatable_types, true ) ) {
				$translate[] = $meta_key;
			}
		}

		return $translate;
	}
}
