<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in resolver that adds Pods field values to the translate list when
 * Pods is active.
 *
 * Pods stores no 'field_…' reference keys in post meta; detection loads the
 * pod definition for the post type via pods_api()->load_pod() and matches
 * meta keys against the registered field names.
 *
 * Only text/paragraph/wysiwyg fields are added by default; extensible via the
 * slytranslate_pods_translatable_field_types filter.
 */
class PodsMetaResolver {

	private const TRANSLATABLE_TYPES = array(
		'text',
		'paragraph',
		'wysiwyg',
	);

	public static function register(): void {
		add_filter( 'slytranslate_meta_keys_translate', array( self::class, 'add_pods_translatable_keys' ), 10, 5 );
	}

	/**
	 * @param array  $translate  Current list of keys to translate.
	 * @param int    $post_id    Post ID (0 = no post context).
	 * @param string $from       Source locale (may be empty).
	 * @param string $to         Target locale (may be empty).
	 * @param array  $post_meta  Raw post meta (key => [value]).
	 * @return array Updated translate list.
	 */
	public static function add_pods_translatable_keys( array $translate, int $post_id, string $from, string $to, array $post_meta ): array {
		if ( empty( $post_meta ) || $post_id < 1 || ! function_exists( 'pods_api' ) ) {
			return $translate;
		}

		$pod_fields = self::get_pod_field_types( $post_id );
		if ( empty( $pod_fields ) ) {
			return $translate;
		}

		$translatable_types = apply_filters( 'slytranslate_pods_translatable_field_types', self::TRANSLATABLE_TYPES );
		$translatable_types = is_array( $translatable_types ) ? $translatable_types : self::TRANSLATABLE_TYPES;

		foreach ( $post_meta as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || str_starts_with( $meta_key, '_' ) || in_array( $meta_key, $translate, true ) ) {
				continue;
			}

			if ( isset( $pod_fields[ $meta_key ] ) && in_array( $pod_fields[ $meta_key ], $translatable_types, true ) ) {
				$translate[] = $meta_key;
			}
		}

		return $translate;
	}

	/**
	 * Field name => field type map for the pod matching the post's type.
	 *
	 * Supports both the legacy array shape of load_pod() and newer Pod/Field
	 * objects (which implement ArrayAccess).
	 *
	 * @return array<string, string>
	 */
	private static function get_pod_field_types( int $post_id ): array {
		$post_type = function_exists( 'get_post_type' ) ? (string) get_post_type( $post_id ) : '';
		if ( '' === $post_type ) {
			return array();
		}

		$api = pods_api();
		if ( ! is_object( $api ) || ! method_exists( $api, 'load_pod' ) ) {
			return array();
		}

		$pod = $api->load_pod( array( 'name' => $post_type ) );
		if ( empty( $pod ) ) {
			return array();
		}

		$fields = null;
		if ( is_array( $pod ) && isset( $pod['fields'] ) ) {
			$fields = $pod['fields'];
		} elseif ( is_object( $pod ) && method_exists( $pod, 'get_fields' ) ) {
			$fields = $pod->get_fields();
		}

		if ( ! is_array( $fields ) ) {
			return array();
		}

		$field_types = array();
		foreach ( $fields as $name => $field ) {
			$type = '';
			if ( is_array( $field ) || $field instanceof \ArrayAccess ) {
				$type = (string) ( $field['type'] ?? '' );
			}
			if ( '' !== $type && is_string( $name ) ) {
				$field_types[ $name ] = $type;
			}
		}

		return $field_types;
	}
}
