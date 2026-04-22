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
		// The background-task bar must render on every wp-admin screen so that a
		// "Continue in background" translation stays visible after the user
		// navigates away from the list-table view.
		add_action( 'admin_footer', array( static::class, 'enqueue_global_background_bar' ) );
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
		add_action( 'admin_footer', array( static::class, 'enqueue_list_table_script' ) );
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

		// Build the list of still-missing target languages. The picker dialog
		// rendered by the inline JS reads these via data-langs and lets the user
		// choose any of them (or change the source language) at translation time.
		$missing_languages = array();
		foreach ( $languages as $code => $name ) {
			if ( $code === $source_lang ) {
				continue;
			}
			if ( isset( $translations[ $code ] ) ) {
				$tid = absint( $translations[ $code ] );
				if ( $tid > 0 && false !== get_post_status( $tid ) ) {
					continue;
				}
			}
			$missing_languages[] = array( 'code' => (string) $code, 'name' => (string) $name );
		}

		if ( empty( $missing_languages ) ) {
			return $actions;
		}

		// Encode all languages (including the source) so the picker can offer a
		// "swap source/target" experience and let the user override Polylang's
		// detected source language if needed.
		$all_languages = array();
		foreach ( $languages as $code => $name ) {
			$all_languages[] = array( 'code' => (string) $code, 'name' => (string) $name );
		}

		$actions['ai_translate'] = sprintf(
			'<a href="#" class="slytranslate-ajax-translate" data-post-id="%d" data-post-title="%s" data-source-lang="%s" data-langs="%s" data-all-langs="%s">%s</a>',
			$post->ID,
			esc_attr( $post->post_title ),
			esc_attr( (string) $source_lang ),
			esc_attr( wp_json_encode( $missing_languages ) ),
			esc_attr( wp_json_encode( $all_languages ) ),
			esc_html__( 'Translate', 'slytranslate' )
		);

		return $actions;
	}

	/* ---------------------------------------------------------------
	 * Bulk-action entries
	 * ------------------------------------------------------------- */

	/**
	 * Adds a single "Translate" entry to the bulk-action dropdown. The actual
	 * source/target/model/additional-prompt selection happens client-side in
	 * the picker dialog after the user submits the bulk action.
	 *
	 * @param array $actions Existing bulk actions.
	 */
	public static function add_bulk_actions( array $actions ): array {
		$adapter = AI_Translate::get_adapter();
		if ( ! $adapter ) {
			return $actions;
		}

		$actions['ai_translate_bulk'] = esc_html__( 'Translate', 'slytranslate' );

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
		// JS intercepts the new unified "Translate" bulk action and runs the
		// translations client-side via the picker dialog. Without JS, there is
		// no target language to operate on — return unchanged.
		if ( 'ai_translate_bulk' === $action ) {
			return $redirect_url;
		}

		if ( ! str_starts_with( $action, 'ai_translate_to_' ) ) {
			return $redirect_url;
		}

		if ( ! apply_filters( 'slytranslate_legacy_bulk_actions_enabled', false ) ) {
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
		$post_id = absint( wp_unslash( $_GET['post_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified below
		$lang    = sanitize_key( wp_unslash( $_GET['lang']    ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified below

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

	/* ---------------------------------------------------------------
	 * List table inline script (AJAX translation dialog)
	 * ------------------------------------------------------------- */

	public static function enqueue_list_table_script(): void {
		$rest_url   = esc_url_raw( rest_url( 'wp/v2/abilities/' ) );
		$rest_nonce = wp_create_nonce( 'wp_rest' );

		$strings = array(
			'translating'      => esc_html__( 'Translating...', 'slytranslate' ),
			'translatingTo'    => esc_html__( 'Translating to %s...', 'slytranslate' ),
			'cancel'           => esc_html__( 'Cancel translation', 'slytranslate' ),
			'background'       => esc_html__( 'Continue in background', 'slytranslate' ),
			'close'            => esc_html__( 'Close', 'slytranslate' ),
			'success'          => esc_html__( 'Translation completed successfully.', 'slytranslate' ),
			'error'            => esc_html__( 'Translation failed: %s', 'slytranslate' ),
			'cancelled'        => esc_html__( 'Translation cancelled.', 'slytranslate' ),
			'backgroundNotice' => esc_html__( 'Translation continues in the background. You can navigate away.', 'slytranslate' ),
			'progressTitle'    => esc_html__( 'Translating title...', 'slytranslate' ),
			'progressContent'  => esc_html__( 'Translating content...', 'slytranslate' ),
			'progressExcerpt'  => esc_html__( 'Translating excerpt...', 'slytranslate' ),
			'progressMeta'     => esc_html__( 'Translating metadata...', 'slytranslate' ),
			'progressSaving'   => esc_html__( 'Saving translation...', 'slytranslate' ),
			'openTranslation'  => esc_html__( 'Open translation', 'slytranslate' ),
			'done'             => esc_html__( 'Done', 'slytranslate' ),
			'failed'           => esc_html__( 'Failed', 'slytranslate' ),
			'pickerTitle'      => esc_html__( 'Translate', 'slytranslate' ),
			'pickerTitleBulk'  => esc_html__( 'Translate %d items', 'slytranslate' ),
			'pickerModelLabel' => esc_html__( 'AI model', 'slytranslate' ),
			'pickerSourceLabel' => esc_html__( 'Source language', 'slytranslate' ),
			'pickerTargetLabel' => esc_html__( 'Target language', 'slytranslate' ),
			'pickerSwapTitle'   => esc_html__( 'Swap source and target language', 'slytranslate' ),
			'pickerAdditionalPromptLabel' => esc_html__( 'Additional instructions (optional)', 'slytranslate' ),
			'pickerAdditionalPromptHelp'  => esc_html__( 'Supplements the site-wide translation instructions. Example: Use informal language.', 'slytranslate' ),
			'pickerStart'      => esc_html__( 'Start translation', 'slytranslate' ),
			'pickerCancel'     => esc_html__( 'Cancel', 'slytranslate' ),
			'pickerRefresh'    => esc_html__( 'Refresh model list', 'slytranslate' ),
			'pickerLoading'    => esc_html__( 'Loading available models...', 'slytranslate' ),
			'pickerNoModels'   => esc_html__( 'No AI models are available. Configure a connector under Settings → Connectors first.', 'slytranslate' ),
			'pickerAutoOption' => esc_html__( 'Connector default', 'slytranslate' ),
			'pickerNoSelection' => esc_html__( 'Please select at least one item before translating.', 'slytranslate' ),
			'bulkProgress'     => esc_html__( 'Translating item %1$d of %2$d...', 'slytranslate' ),
			'bulkDone'         => esc_html__( 'Bulk translation complete: %1$d translated, %2$d skipped, %3$d failed.', 'slytranslate' ),
		);

		wp_enqueue_script(
			'slytranslate-list-table',
			plugins_url( 'assets/list-table-dialog.js', dirname( __DIR__ ) . '/ai-translate.php' ),
			array(),
			Plugin::VERSION,
			true
		);
		wp_localize_script( 'slytranslate-list-table', 'SlyTranslateListTable', array(
			'restUrl'   => $rest_url,
			'restNonce' => $rest_nonce,
			'i18n'      => $strings,
		) );

		?>
		<div id="slytranslate-model-picker" style="display:none;position:fixed;inset:0;z-index:100101;background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
			<div style="background:#fff;border-radius:8px;padding:24px 28px;max-width:520px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,.25);">
				<h3 id="slytranslate-model-picker-title" style="margin:0 0 16px;font-size:15px;"></h3>
				<div style="display:grid;grid-template-columns:1fr auto 1fr;gap:8px;align-items:end;margin-bottom:12px;">
					<div>
						<label for="slytranslate-picker-source" style="display:block;font-size:12px;color:#50575e;margin-bottom:6px;"><?php echo esc_html( $strings['pickerSourceLabel'] ); ?></label>
						<select id="slytranslate-picker-source" style="width:100%;"></select>
					</div>
					<button id="slytranslate-picker-swap" type="button" class="button" title="<?php echo esc_attr( $strings['pickerSwapTitle'] ); ?>" style="margin-bottom:1px;"><span class="dashicons dashicons-controls-repeat" style="margin:3px 0 0 0;"></span></button>
					<div>
						<label for="slytranslate-picker-target" style="display:block;font-size:12px;color:#50575e;margin-bottom:6px;"><?php echo esc_html( $strings['pickerTargetLabel'] ); ?></label>
						<select id="slytranslate-picker-target" style="width:100%;"></select>
					</div>
				</div>
				<label for="slytranslate-model-picker-select" style="display:block;font-size:12px;color:#50575e;margin-bottom:6px;"><?php echo esc_html( $strings['pickerModelLabel'] ); ?></label>
				<div style="display:flex;gap:6px;align-items:center;">
					<select id="slytranslate-model-picker-select" style="flex:1;min-width:0;"></select>
					<button id="slytranslate-model-picker-refresh" type="button" class="button" title="<?php echo esc_attr( $strings['pickerRefresh'] ); ?>"><span class="dashicons dashicons-update" style="margin:3px 0 0 0;"></span></button>
				</div>
				<div id="slytranslate-model-picker-status" style="margin-top:8px;font-size:12px;color:#50575e;min-height:18px;"></div>
				<label for="slytranslate-picker-additional-prompt" style="display:block;font-size:12px;color:#50575e;margin:12px 0 6px;"><?php echo esc_html( $strings['pickerAdditionalPromptLabel'] ); ?></label>
				<textarea id="slytranslate-picker-additional-prompt" rows="3" style="width:100%;resize:vertical;"></textarea>
				<div style="margin-top:4px;font-size:11px;color:#50575e;"><?php echo esc_html( $strings['pickerAdditionalPromptHelp'] ); ?></div>
				<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
					<button id="slytranslate-model-picker-cancel" type="button" class="button button-secondary"><?php echo esc_html( $strings['pickerCancel'] ); ?></button>
					<button id="slytranslate-model-picker-start"  type="button" class="button button-primary"><?php echo esc_html( $strings['pickerStart'] ); ?></button>
				</div>
			</div>
		</div>
		<div id="slytranslate-list-overlay" style="display:none;position:fixed;inset:0;z-index:100100;background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
			<div style="background:#fff;border-radius:8px;padding:24px 28px;max-width:420px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,.25);">
				<h3 id="slytranslate-list-title" style="margin:0 0 16px;font-size:15px;"></h3>
				<div id="slytranslate-list-progress-wrap" style="margin-bottom:12px;">
					<div style="height:8px;border-radius:999px;overflow:hidden;background:#dcdcde;">
						<div id="slytranslate-list-bar" style="width:0%;height:100%;background:linear-gradient(90deg,#3858e9 0%,#1d4ed8 100%);transition:width .3s ease;"></div>
					</div>
					<div id="slytranslate-list-label" style="font-size:12px;color:#50575e;margin-top:6px;"></div>
				</div>
				<div id="slytranslate-list-result" style="display:none;margin-bottom:12px;padding:10px 12px;border-radius:4px;font-size:13px;"></div>
				<div style="display:flex;gap:8px;justify-content:flex-end;">
					<button id="slytranslate-list-bg" type="button" class="button" style="display:none;"><?php echo esc_html( $strings['background'] ); ?></button>
					<button id="slytranslate-list-cancel" type="button" class="button button-secondary" style="display:none;"><?php echo esc_html( $strings['cancel'] ); ?></button>
					<button id="slytranslate-list-close" type="button" class="button button-primary" style="display:none;"><?php echo esc_html( $strings['close'] ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Global background-task status bar (rendered on every wp-admin screen)
	 * ------------------------------------------------------------- */

	private static function user_has_active_or_recent_jobs(): bool {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return false;
		}
		global $wpdb;
		$pattern = $wpdb->esc_like( '_transient_ai_translate_progress_' ) . '%';
		$count   = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern )
		);
		return $count > 0;
	}

	public static function enqueue_global_background_bar(): void {
		// Only render for users that may translate, and only inside wp-admin.
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}
		if ( ! AI_Translate::current_user_can_access_translation_abilities() ) {
			return;
		}
		if ( ! self::user_has_active_or_recent_jobs() ) {
			return;
		}

		$rest_url   = esc_url_raw( rest_url( 'wp/v2/abilities/' ) );
		$rest_nonce = wp_create_nonce( 'wp_rest' );

		$strings = array(
			'translatingTo'   => esc_html__( 'Translating to %s...', 'slytranslate' ),
			'cancel'          => esc_html__( 'Cancel translation', 'slytranslate' ),
			'success'         => esc_html__( 'Translation completed successfully.', 'slytranslate' ),
			'error'           => esc_html__( 'Translation failed.', 'slytranslate' ),
			'cancelled'       => esc_html__( 'Translation cancelled.', 'slytranslate' ),
			'openTranslation' => esc_html__( 'Open translation', 'slytranslate' ),
			'dismiss'         => esc_html__( 'Dismiss', 'slytranslate' ),
			'header'          => esc_html__( 'SlyTranslate background tasks', 'slytranslate' ),
			'dismissAll'      => esc_html__( 'Clear finished', 'slytranslate' ),
			'summaryRunning'  => esc_html_x( 'running', 'background bar status summary', 'slytranslate' ),
			'summaryDone'     => esc_html_x( 'done', 'background bar status summary', 'slytranslate' ),
			'summaryError'    => esc_html_x( 'failed', 'background bar status summary', 'slytranslate' ),
			'progressTitle'   => esc_html__( 'Translating title...', 'slytranslate' ),
			'progressContent' => esc_html__( 'Translating content...', 'slytranslate' ),
			'progressContentFinishing' => esc_html__( 'Processing translated content...', 'slytranslate' ),
			'progressExcerpt' => esc_html__( 'Translating excerpt...', 'slytranslate' ),
			'progressMeta'    => esc_html__( 'Translating metadata...', 'slytranslate' ),
			'progressSaving'  => esc_html__( 'Saving translation...', 'slytranslate' ),
			'translating'     => esc_html__( 'Translating...', 'slytranslate' ),
		);

		wp_enqueue_script(
			'slytranslate-bg-bar',
			plugins_url( 'assets/background-bar.js', dirname( __DIR__ ) . '/ai-translate.php' ),
			array(),
			Plugin::VERSION,
			true
		);
		wp_localize_script( 'slytranslate-bg-bar', 'SlyTranslateBgBar', array(
			'restUrl'   => $rest_url,
			'restNonce' => $rest_nonce,
			'i18n'      => $strings,
		) );
	}
}
