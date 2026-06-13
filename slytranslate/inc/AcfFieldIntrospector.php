<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Shared ACF field introspection used by AcfMetaResolver, AcfBlockTranslator
 * and AcfOptionsTranslationService.
 *
 * Centralises three concerns:
 *  - which ACF field types hold translatable plain content (filterable via
 *    slytranslate_acf_translatable_field_types),
 *  - resolving a 'field_…' reference to its field definition,
 *  - value specs for structured field types whose values must only be
 *    translated in selected sub-keys (e.g. link → title, never url/target).
 */
class AcfFieldIntrospector {

	private const TRANSLATABLE_TYPES = array(
		'text',
		'textarea',
		'wysiwyg',
	);

	/**
	 * Structured field types and the sub-keys of their array values that are
	 * safe to translate. Everything not listed is copied unchanged.
	 */
	private const VALUE_SPECS = array(
		'link' => array( 'subkeys' => array( 'title' ) ),
	);

	/**
	 * Field types whose string values are translated as a whole.
	 *
	 * @return string[]
	 */
	public static function get_translatable_types(): array {
		$types = apply_filters( 'slytranslate_acf_translatable_field_types', self::TRANSLATABLE_TYPES );
		return is_array( $types ) ? $types : self::TRANSLATABLE_TYPES;
	}

	/**
	 * Resolve a 'field_…' reference to its ACF field definition.
	 *
	 * @return array|null Field array with at least a 'type' entry, or null.
	 */
	public static function get_field_for_ref( ?string $ref ): ?array {
		if ( ! is_string( $ref ) || ! str_starts_with( $ref, 'field_' ) || ! function_exists( 'acf_get_field' ) ) {
			return null;
		}

		$field = acf_get_field( $ref );
		if ( ! is_array( $field ) || ! isset( $field['type'] ) ) {
			return null;
		}

		return $field;
	}

	/**
	 * True when the field carries the per-field opt-out flag saved by the
	 * "Exclude from AI translation" toggle in the ACF field editor.
	 */
	public static function is_field_excluded( array $field ): bool {
		return ! empty( $field['slytranslate_exclude'] );
	}

	/**
	 * True when the field should be translated.
	 *
	 * @param array $field              ACF field definition.
	 * @param bool  $include_structured Also accept structured types that have a
	 *                                  value spec (e.g. link). Callers that can
	 *                                  only handle plain string values must pass
	 *                                  false so structured values never reach a
	 *                                  whole-value translation path.
	 */
	public static function is_translatable_field( array $field, bool $include_structured = true ): bool {
		if ( self::is_field_excluded( $field ) ) {
			return false;
		}

		$type = (string) ( $field['type'] ?? '' );
		if ( in_array( $type, self::get_translatable_types(), true ) ) {
			return true;
		}

		return $include_structured && ! empty( self::get_value_spec( $type ) );
	}

	/**
	 * True when a 'field_…' reference points to a translatable field.
	 */
	public static function is_translatable_ref( ?string $ref, bool $include_structured = true ): bool {
		$field = self::get_field_for_ref( $ref );
		return null !== $field && self::is_translatable_field( $field, $include_structured );
	}

	/**
	 * Sub-key translation spec for a structured field type.
	 *
	 * @return array Empty array when the whole value may be translated, or
	 *               array( 'subkeys' => string[] ) when only listed sub-keys
	 *               of an array value are translatable.
	 */
	public static function get_value_spec( string $type ): array {
		return self::VALUE_SPECS[ $type ] ?? array();
	}
}
