<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in resolver that adds ACF field values to the translate list
 * when ACF is active. Hooks onto slytranslate_meta_keys_translate so
 * third-party callbacks can still run after this resolver.
 *
 * Field-type knowledge lives in AcfFieldIntrospector; this class only walks
 * post meta, matches '_meta_key' => 'field_…' references and records what it
 * resolved so describe_effective_meta_keys() can report per-key sources.
 *
 * Only text/textarea/wysiwyg fields are added by default. The list is
 * extensible via the slytranslate_acf_translatable_field_types filter.
 * Structured types with a value spec (e.g. link) are added too; their values
 * are translated selectively via MetaTranslationService value specs.
 */
class AcfMetaResolver {

	/**
	 * Per-request cache of resolved ACF keys, keyed by post ID:
	 * post_id => meta_key => array( field_type, field_label ).
	 *
	 * @var array<int, array<string, array{field_type: string, field_label: string}>>
	 */
	private static $resolved_field_info = array();

	public static function register(): void {
		add_filter( 'slytranslate_meta_keys_translate', array( self::class, 'add_acf_translatable_keys' ), 10, 5 );
		add_action( 'acf/render_field_settings', array( self::class, 'render_field_exclude_setting' ) );
	}

	public static function reset_cache(): void {
		self::$resolved_field_info = array();
	}

	/**
	 * Field info recorded while resolving keys for a post.
	 *
	 * @return array<string, array{field_type: string, field_label: string}>
	 */
	public static function get_resolved_field_info( int $post_id ): array {
		return self::$resolved_field_info[ $post_id ] ?? array();
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

		foreach ( $post_meta as $meta_key => $values ) {
			if ( ! is_string( $meta_key ) || str_starts_with( $meta_key, '_' ) ) {
				continue;
			}

			$ref   = $post_meta[ '_' . $meta_key ][0] ?? null;
			$field = AcfFieldIntrospector::get_field_for_ref( is_string( $ref ) ? $ref : null );
			if ( null === $field || ! AcfFieldIntrospector::is_translatable_field( $field ) ) {
				continue;
			}

			$field_type = (string) $field['type'];
			$spec       = AcfFieldIntrospector::get_value_spec( $field_type );
			if ( ! empty( $spec ) ) {
				MetaTranslationService::set_meta_value_spec( $meta_key, $spec, $field_type );
			}

			self::$resolved_field_info[ $post_id ][ $meta_key ] = array(
				'field_type'  => $field_type,
				'field_label' => (string) ( $field['label'] ?? '' ),
			);

			$translate[] = $meta_key;
		}

		return $translate;
	}

	/**
	 * Render the "Exclude from AI translation" toggle in the ACF field editor
	 * (WPML-style per-field opt-out). The flag is stored in the field array as
	 * slytranslate_exclude and honoured by AcfFieldIntrospector.
	 *
	 * @param array $field The field definition being edited.
	 */
	public static function render_field_exclude_setting( $field ): void {
		if ( ! function_exists( 'acf_render_field_setting' ) || ! is_array( $field ) ) {
			return;
		}

		acf_render_field_setting(
			$field,
			array(
				'label'        => __( 'Exclude from AI translation', 'slytranslate' ),
				'instructions' => __( 'When enabled, SlyTranslate skips this field during automatic translation.', 'slytranslate' ),
				'name'         => 'slytranslate_exclude',
				'type'         => 'true_false',
				'ui'           => 1,
			),
			true
		);
	}
}
