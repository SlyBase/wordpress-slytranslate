<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Handles Polylang auto-translate hooks for backward compatibility.
 *
 * When a user creates a new translation in Polylang (post-new.php?from_post=…),
 * this bridge intercepts the default_title / default_content / default_excerpt
 * filters and translates the fields using the active runtime.
 */
class LegacyPolylangBridge {

	/** Cached request context (static per PHP request). */
	private static $request_context = null;
	private static $context_loaded  = false;

	/* ---------------------------------------------------------------
	 * WordPress filter hooks
	 * ------------------------------------------------------------- */

	public static function default_title( $title, $post ): string {
		$pattern = '/[^\p{L}\p{N}]+$/u';
		return (string) preg_replace( $pattern, '', wp_strip_all_tags( self::translate_field( $title, 'post_title' ) ) );
	}

	public static function default_content( $content, $post ): mixed {
		return self::translate_field( $content, 'post_content' );
	}

	public static function default_excerpt( $excerpt, $post ): mixed {
		return self::translate_field( $excerpt, 'post_excerpt' );
	}

	public static function pll_translate_post_meta( $value, $key, $lang ): mixed {
		$meta_key_config = MetaTranslationService::get_effective_meta_key_config( self::get_polylang_source_post_id() );

		if ( in_array( $key, $meta_key_config['clear'], true ) ) {
			$value = '';
		} elseif ( in_array( $key, $meta_key_config['translate'], true ) ) {
			$value = self::translate_field( $value, $key, true );
		}

		return $value;
	}

	/* ---------------------------------------------------------------
	 * Field translation
	 * ------------------------------------------------------------- */

	public static function translate_field( $original, string $field = '', bool $meta = false ): mixed {
		if ( get_option( 'ai_translate_new_post', '0' ) !== '1' ) {
			return $original;
		}

		$request_context = self::get_new_post_translation_request_context();
		if ( ! $request_context ) {
			return $original;
		}

		$to      = $request_context['target_language'];
		$post_id = $request_context['source_post_id'];

		if ( $field ) {
			if ( $meta ) {
				$original = get_post_meta( $post_id, $field, true );
			} else {
				$original = $request_context['source_post']->$field ?? $original;
			}
		}

		$source_language = $request_context['source_language'];

		if ( ! $meta && 'post_content' === $field && is_string( $original ) ) {
			$translation = ContentTranslator::translate_post_content( $original, $to, $source_language );
		} else {
			$translation = TranslationRuntime::translate_text( $original, $to, $source_language );
		}

		return is_wp_error( $translation ) ? $original : $translation;
	}

	/* ---------------------------------------------------------------
	 * Request context
	 * ------------------------------------------------------------- */

	public static function get_new_post_translation_request_context(): ?array {
		if ( self::$context_loaded ) {
			return self::$request_context;
		}

		self::$context_loaded = true;

		if ( ! is_admin() || ! isset( $GLOBALS['pagenow'] ) || 'post-new.php' !== $GLOBALS['pagenow'] ) {
			return null;
		}

		if ( ! isset( $_GET['_wpnonce'] ) ) {
			return null;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'new-post-translation' ) ) {
			return null;
		}

		if ( ! isset( $_GET['from_post'], $_GET['new_lang'], $_GET['post_type'] ) ) {
			return null;
		}

		$post_id   = absint( wp_unslash( $_GET['from_post'] ) );
		$to        = sanitize_key( wp_unslash( $_GET['new_lang'] ) );
		$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );

		if ( $post_id < 1 || '' === $to || '' === $post_type ) {
			return null;
		}

		$source_post = get_post( $post_id );
		if ( ! $source_post || $source_post->post_type !== $post_type ) {
			return null;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}

		$from = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : 'en';

		self::$request_context = array(
			'source_post'     => $source_post,
			'source_post_id'  => $post_id,
			'source_language' => $from ?: 'en',
			'target_language' => $to,
		);

		return self::$request_context;
	}

	public static function get_polylang_source_post_id(): int {
		$request_context = self::get_new_post_translation_request_context();
		if ( ! is_array( $request_context ) ) {
			return 0;
		}
		return absint( $request_context['source_post_id'] ?? 0 );
	}
}
