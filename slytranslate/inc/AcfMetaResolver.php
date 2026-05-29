<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in resolver that adds ACF field values to the translate list
 * when ACF is active. Hooks onto slytranslate_meta_keys_translate so
 * third-party callbacks can still run after this resolver.
 *
 * Only text/textarea/wysiwyg fields are added by default. The list is
 * extensible via the slytranslate_acf_translatable_field_types filter.
 */
class AcfMetaResolver {

	private const TRANSLATABLE_TYPES = array(
		'text',
		'textarea',
		'wysiwyg',
	);

	public static function register(): void {
		add_filter( 'slytranslate_meta_keys_translate', array( self::class, 'add_acf_translatable_keys' ), 10, 5 );
	}

	/**
	 * Inspect each post-meta entry for an ACF reference key (_meta_key)
	 * pointing to a translatable field type and add it to the translate list.
	 *
	 * @param array  $translate  Current list of keys to translate.
	 * @param int    $post_id    Post ID (0 = no post context).
	 * @param string $from       Source locale (may be empty).
	 * @param string $to         Target locale (may be empty).
	 * @param array  $post_meta  Raw post meta (key => [value]).
	 * @return array Updated translate list.
	 */
	public static function add_acf_translatable_keys( array $translate, int $post_id, string $from, string $to, array $post_meta ): array {
		if ( empty( $post_meta ) ) {
			return $translate;
		}

		$translatable_types = apply_filters( 'slytranslate_acf_translatable_field_types', self::TRANSLATABLE_TYPES );

		foreach ( $post_meta as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || str_starts_with( $meta_key, '_' ) ) {
				continue;
			}

			$ref = $post_meta[ '_' . $meta_key ][0] ?? null;
			if ( ! is_string( $ref ) || ! str_starts_with( $ref, 'field_' ) ) {
				continue;
			}

			$field = acf_get_field( $ref );
			if ( ! is_array( $field ) || ! isset( $field['type'] ) ) {
				continue;
			}

			if ( in_array( $field['type'], $translatable_types, true ) ) {
				$translate[] = $meta_key;
			}
		}

		return $translate;
	}
}
