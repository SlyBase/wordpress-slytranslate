<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Translates attachment text fields (alt text, caption, description) when
 * Polylang duplicates media into another language, and back-fills the alt
 * text of the featured image linked to a freshly translated post.
 *
 * Inline <img alt="…"> attributes inside post content are already handled by
 * the regular content pipeline — this service covers the attachment-meta
 * side, which stays in the source language otherwise.
 */
class MediaTranslationService {

	public const ALT_TEXT_META_KEY = '_wp_attachment_image_alt';

	/**
	 * Hook callback for Polylang's `pll_translate_media` action, fired after
	 * Polylang created a media translation.
	 *
	 * @param int    $source_attachment_id Source attachment ID.
	 * @param int    $new_attachment_id    Newly created attachment translation ID.
	 * @param string $target_language      Language of the new attachment.
	 */
	public static function handle_pll_translate_media( $source_attachment_id, $new_attachment_id, $target_language ): void {
		$source_attachment_id = absint( $source_attachment_id );
		$new_attachment_id    = absint( $new_attachment_id );
		$to                   = sanitize_key( (string) $target_language );

		if ( $source_attachment_id < 1 || $new_attachment_id < 1 || '' === $to || $source_attachment_id === $new_attachment_id ) {
			return;
		}

		$from = function_exists( 'pll_get_post_language' ) ? (string) \pll_get_post_language( $source_attachment_id ) : '';
		if ( '' === $from || $from === $to ) {
			return;
		}

		// Alt text.
		$alt = get_post_meta( $source_attachment_id, self::ALT_TEXT_META_KEY, true );
		if ( is_string( $alt ) && '' !== trim( $alt ) ) {
			$translated_alt = TranslationRuntime::translate_text( $alt, $to, $from, self::get_attachment_text_hint() );
			if ( ! is_wp_error( $translated_alt ) && '' !== trim( (string) $translated_alt ) ) {
				update_post_meta( $new_attachment_id, self::ALT_TEXT_META_KEY, trim( (string) $translated_alt ) );
			}
		}

		// Caption (post_excerpt) and description (post_content).
		$attachment = get_post( $new_attachment_id );
		if ( ! $attachment ) {
			return;
		}

		$update = array();
		foreach ( array( 'post_excerpt', 'post_content' ) as $field ) {
			$value = (string) $attachment->$field;
			if ( '' === trim( $value ) ) {
				continue;
			}
			$translated = TranslationRuntime::translate_text( $value, $to, $from, self::get_attachment_text_hint() );
			if ( ! is_wp_error( $translated ) && '' !== trim( (string) $translated ) ) {
				$update[ $field ] = (string) $translated;
			}
		}

		if ( ! empty( $update ) ) {
			$update['ID'] = $new_attachment_id;
			wp_update_post( wp_slash( $update ) );
		}
	}

	/**
	 * After a post translation was saved: translate the alt text of the
	 * translated post's featured image when Polylang media translation is
	 * active and the target attachment's alt is still empty.
	 *
	 * Best effort — failures never break the post translation that already
	 * succeeded.
	 */
	public static function maybe_translate_featured_image_alt( int $source_post_id, int $translated_post_id, string $to, string $from ): void {
		if ( $source_post_id < 1 || $translated_post_id < 1 || $source_post_id === $translated_post_id ) {
			return;
		}
		if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
			return;
		}
		if ( function_exists( 'pll_get_option' ) && ! \pll_get_option( 'media_support' ) ) {
			return;
		}

		$source_thumbnail = absint( get_post_thumbnail_id( $source_post_id ) );
		$target_thumbnail = absint( get_post_thumbnail_id( $translated_post_id ) );
		if ( $source_thumbnail < 1 || $target_thumbnail < 1 || $source_thumbnail === $target_thumbnail ) {
			return;
		}

		$target_alt = get_post_meta( $target_thumbnail, self::ALT_TEXT_META_KEY, true );
		if ( is_string( $target_alt ) && '' !== trim( $target_alt ) ) {
			return;
		}

		$source_alt = get_post_meta( $source_thumbnail, self::ALT_TEXT_META_KEY, true );
		if ( ! is_string( $source_alt ) || '' === trim( $source_alt ) ) {
			return;
		}

		$translated_alt = TranslationRuntime::translate_text( $source_alt, $to, $from, self::get_attachment_text_hint() );
		if ( is_wp_error( $translated_alt ) || '' === trim( (string) $translated_alt ) ) {
			return;
		}

		update_post_meta( $target_thumbnail, self::ALT_TEXT_META_KEY, trim( (string) $translated_alt ) );
	}

	private static function get_attachment_text_hint(): string {
		return 'The input is a short image description (alt text or caption). Translate it concisely and return only the translated text without HTML or quotes.';
	}
}
