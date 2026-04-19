<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Translate → [Language]" row actions and bulk-action entries to the
 * post/page list tables for every Polylang-registered post type.
 */
class ListTableTranslation {

	/* ---------------------------------------------------------------
	 * Hook registration
	 * ------------------------------------------------------------- */

	public static function add_hooks(): void {
		add_action( 'current_screen', array( static::class, 'register_list_table_hooks' ) );
		add_action( 'admin_post_ai_translate_single', array( static::class, 'handle_single_translate' ) );
		add_action( 'admin_notices', array( static::class, 'show_admin_notice' ) );
	}

	/**
	 * Fired on current_screen – only registers hooks when we are actually on an
	 * edit.php list-table screen.
	 */
	public static function register_list_table_hooks( \WP_Screen $screen ): void {
		if ( 'edit' !== $screen->base ) {
			return;
		}

		$post_type  = $screen->post_type;
		$row_filter = is_post_type_hierarchical( $post_type ) ? 'page_row_actions' : 'post_row_actions';

		add_filter( $row_filter, array( static::class, 'add_row_actions' ), 10, 2 );
		add_filter( "bulk_actions-edit-{$post_type}", array( static::class, 'add_bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$post_type}", array( static::class, 'handle_bulk_translate' ), 10, 3 );
	}

	/* ---------------------------------------------------------------
	 * Row-action links
	 * ------------------------------------------------------------- */

	/**
	 * Appends "Translate → [Language]" links for every language that does not
	 * yet have a translation of the given post.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    Current post object.
	 */
	public static function add_row_actions( array $actions, \WP_Post $post ): array {
		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$languages    = $adapter->get_languages();
		$translations = $adapter->get_post_translations( $post->ID );
		$source_lang  = $adapter->get_post_language( $post->ID );

		foreach ( $languages as $code => $name ) {
			if ( $code === $source_lang ) {
				continue;
			}

			// Skip languages that already have an existing translation post.
			if ( isset( $translations[ $code ] ) ) {
				$tid = absint( $translations[ $code ] );
				if ( $tid > 0 && false !== get_post_status( $tid ) ) {
					continue;
				}
			}

			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=ai_translate_single&post_id=' . $post->ID . '&lang=' . rawurlencode( $code ) ),
				'ai_translate_single_' . $post->ID . '_' . $code
			);

			/* translators: %s: target language name */
			$actions[ 'ai_translate_' . $code ] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				sprintf( esc_html__( 'Translate → %s', 'slytranslate' ), esc_html( $name ) )
			);
		}

		return $actions;
	}

	/* ---------------------------------------------------------------
	 * Bulk-action entries
	 * ------------------------------------------------------------- */

	/**
	 * Adds "Translate with AI → [Language]" entries to the bulk-action dropdown.
	 *
	 * @param array $actions Existing bulk actions.
	 */
	public static function add_bulk_actions( array $actions ): array {
		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return $actions;
		}

		foreach ( $adapter->get_languages() as $code => $name ) {
			/* translators: %s: target language name */
			$actions[ 'ai_translate_to_' . $code ] = sprintf(
				esc_html__( 'Translate with AI → %s', 'slytranslate' ),
				$name
			);
		}

		return $actions;
	}

	/* ---------------------------------------------------------------
	 * Bulk-action handler
	 * ------------------------------------------------------------- */

	/**
	 * Processes the "Translate with AI → [Language]" bulk action.
	 *
	 * @param string   $redirect_url The current redirect URL.
	 * @param string   $action       The bulk action being processed.
	 * @param int[]    $post_ids     Array of selected post IDs.
	 * @return string Modified redirect URL with result counters.
	 */
	public static function handle_bulk_translate( string $redirect_url, string $action, array $post_ids ): string {
		if ( ! str_starts_with( $action, 'ai_translate_to_' ) ) {
			return $redirect_url;
		}

		$lang = sanitize_key( substr( $action, strlen( 'ai_translate_to_' ) ) );
		if ( '' === $lang ) {
			return $redirect_url;
		}

		$ok = $skipped = $errors = 0;

		foreach ( $post_ids as $raw_id ) {
			$post_id = absint( $raw_id );
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				++$errors;
				continue;
			}

			$result = PostTranslationService::translate_post( $post_id, $lang );

			if ( is_wp_error( $result ) ) {
				if ( 'translation_exists' === $result->get_error_code() ) {
					++$skipped;
				} else {
					++$errors;
				}
			} else {
				++$ok;
			}
		}

		return add_query_arg(
			array(
				'ai_translate_bulk_ok'      => $ok,
				'ai_translate_bulk_skipped' => $skipped,
				'ai_translate_bulk_errors'  => $errors,
			),
			$redirect_url
		);
	}

	/* ---------------------------------------------------------------
	 * Single-translate admin-post handler
	 * ------------------------------------------------------------- */

	/**
	 * Handles the admin-post.php request for a single row-action translation.
	 * Validates the nonce and capability, runs the translation, then redirects
	 * back to the list table with a success or error indicator.
	 */
	public static function handle_single_translate(): void {
		$post_id = absint( $_GET['post_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- verified below
		$lang    = sanitize_key( $_GET['lang']    ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- verified below

		if ( ! $post_id || ! $lang ) {
			wp_die( esc_html__( 'Invalid request.', 'slytranslate' ) );
		}

		check_admin_referer( 'ai_translate_single_' . $post_id . '_' . $lang );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to translate this content item.', 'slytranslate' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Post not found.', 'slytranslate' ) );
		}

		$result   = PostTranslationService::translate_post( $post_id, $lang );
		$redirect = admin_url( 'edit.php?post_type=' . rawurlencode( $post->post_type ) );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'ai_translate_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'ai_translate_done', '1', $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/* ---------------------------------------------------------------
	 * Admin notices
	 * ------------------------------------------------------------- */

	/**
	 * Renders success/error admin notices after a single or bulk translation.
	 */
	public static function show_admin_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice display
		if ( isset( $_GET['ai_translate_done'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Translation completed successfully.', 'slytranslate' )
				. '</p></div>';
		}

		if ( isset( $_GET['ai_translate_error'] ) ) {
			$message = sanitize_text_field( wp_unslash( $_GET['ai_translate_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html(
					/* translators: %s: error message */
					sprintf( __( 'Translation failed: %s', 'slytranslate' ), $message )
				)
				. '</p></div>';
		}

		$bulk_ok = isset( $_GET['ai_translate_bulk_ok'] ) ? absint( $_GET['ai_translate_bulk_ok'] ) : null;
		if ( null === $bulk_ok ) {
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			return;
		}

		$bulk_skipped = absint( $_GET['ai_translate_bulk_skipped'] ?? 0 );
		$bulk_errors  = absint( $_GET['ai_translate_bulk_errors']  ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$parts = array();

		if ( $bulk_ok > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of translations created */
				_n( '%d translation created.', '%d translations created.', $bulk_ok, 'slytranslate' ),
				$bulk_ok
			);
		}

		if ( $bulk_skipped > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of skipped items */
				_n( '%d skipped (translation already exists).', '%d skipped (translation already exists).', $bulk_skipped, 'slytranslate' ),
				$bulk_skipped
			);
		}

		if ( $bulk_errors > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of errors */
				_n( '%d error.', '%d errors.', $bulk_errors, 'slytranslate' ),
				$bulk_errors
			);
		}

		if ( ! empty( $parts ) ) {
			$type = $bulk_errors > 0 ? 'error' : ( $bulk_ok > 0 ? 'success' : 'warning' );
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>'
				. esc_html( implode( ' ', $parts ) )
				. '</p></div>';
		}
	}
}
