<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Translate → [Language]" row actions and bulk-action entries to the
 * post/page list tables for every Polylang-registered post type.
 */
class ListTableTranslation {

	private const LIST_TABLE_SCRIPT_HANDLE     = 'slytranslate-list-table-dialog';
	private const LIST_TABLE_STYLE_HANDLE      = 'slytranslate-list-table-dialog-style';
	private const BACKGROUND_BAR_SCRIPT_HANDLE = 'slytranslate-background-bar';

	/* ---------------------------------------------------------------
	 * Hook registration
	 * ------------------------------------------------------------- */

	public static function add_hooks(): void {
		add_action( 'current_screen', array( static::class, 'register_list_table_hooks' ) );
		add_action( 'admin_post_slytranslate_single', array( static::class, 'handle_single_translate' ) );
		add_action( 'admin_notices', array( static::class, 'show_admin_notice' ) );
		add_action( 'admin_enqueue_scripts', array( static::class, 'enqueue_list_table_assets' ) );
		// The background-task bar must render on every wp-admin screen so that a
		// "Continue in background" translation stays visible after the user
		// navigates away from the list-table view.
		add_action( 'admin_enqueue_scripts', array( static::class, 'enqueue_global_background_bar' ) );
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
		// Render dialog markup near the footer. JS is loaded via admin_enqueue_scripts.
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
		$single_entry_mode = $adapter instanceof WpMultilangAdapter;

		// Build the list of still-missing target languages. The picker dialog
		// rendered by the inline JS reads these via data-langs and lets the user
		// choose any of them (or change the source language) at translation time.
		$missing_languages = array();
		$existing_languages = array();
		foreach ( $languages as $code => $name ) {
			if ( $code === $source_lang ) {
				continue;
			}

			if ( isset( $translations[ $code ] ) ) {
				$tid = absint( $translations[ $code ] );
				if ( $tid > 0 && false !== get_post_status( $tid ) ) {
					$existing_languages[] = (string) $code;
					if ( ! $single_entry_mode ) {
						continue;
					}
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

		$actions['slytranslate'] = sprintf(
			'<a href="#" class="slytranslate-ajax-translate" data-post-id="%d" data-post-title="%s" data-source-lang="%s" data-langs="%s" data-all-langs="%s" data-existing-langs="%s">%s</a>',
			$post->ID,
			esc_attr( $post->post_title ),
			esc_attr( (string) $source_lang ),
			esc_attr( wp_json_encode( $missing_languages ) ),
			esc_attr( wp_json_encode( $all_languages ) ),
			esc_attr( wp_json_encode( $existing_languages ) ),
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

		$actions['slytranslate_bulk'] = esc_html__( 'Translate', 'slytranslate' );

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
		if ( 'slytranslate_bulk' === $action ) {
			return $redirect_url;
		}

		if ( ! str_starts_with( $action, 'slytranslate_to_' ) ) {
			return $redirect_url;
		}

		// Legacy per-language bulk actions (ai_translate_to_<lang>) are no longer
		// generated since the unified picker dialog was introduced. Gate with a
		// filter so sites that still rely on them can opt back in.
		if ( ! apply_filters( 'slytranslate_legacy_bulk_actions_enabled', false ) ) {
			return $redirect_url;
		}

		$lang = sanitize_key( substr( $action, strlen( 'slytranslate_to_' ) ) );
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
				'slytranslate_bulk_ok'      => $ok,
				'slytranslate_bulk_skipped' => $skipped,
				'slytranslate_bulk_errors'  => $errors,
				'slytranslate_notice_nonce' => wp_create_nonce( 'slytranslate_bulk_notice' ),
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

		check_admin_referer( 'slytranslate_single_' . $post_id . '_' . $lang );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to translate this content item.', 'slytranslate' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Post not found.', 'slytranslate' ) );
		}

		$result   = PostTranslationService::translate_post( $post_id, $lang );
		$redirect = admin_url( 'edit.php?post_type=' . rawurlencode( $post->post_type ) );
		$redirect = add_query_arg( 'slytranslate_notice_nonce', wp_create_nonce( 'slytranslate_notice' ), $redirect );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'slytranslate_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'slytranslate_done', '1', $redirect );
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
		$single_notice_requested = isset( $_GET['slytranslate_done'] ) || isset( $_GET['slytranslate_error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below
		if ( $single_notice_requested ) {
			$single_notice_nonce = sanitize_text_field( wp_unslash( $_GET['slytranslate_notice_nonce'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated immediately
			if ( '' !== $single_notice_nonce && wp_verify_nonce( $single_notice_nonce, 'slytranslate_notice' ) ) {
				if ( isset( $_GET['slytranslate_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- protected by nonce above
					echo '<div class="notice notice-success is-dismissible"><p>'
						. esc_html__( 'Translation completed successfully.', 'slytranslate' )
						. '</p></div>';
				}

				if ( isset( $_GET['slytranslate_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- protected by nonce above
					$message = sanitize_text_field( wp_unslash( $_GET['slytranslate_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- protected by nonce above
					echo '<div class="notice notice-error is-dismissible"><p>'
						. esc_html(
							/* translators: %s: error message */
							sprintf( __( 'Translation failed: %s', 'slytranslate' ), $message )
						)
						. '</p></div>';
				}
			}
		}

		$bulk_ok = isset( $_GET['slytranslate_bulk_ok'] ) ? absint( wp_unslash( $_GET['slytranslate_bulk_ok'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated below
		if ( null === $bulk_ok ) {
			return;
		}

		$notice_nonce = sanitize_text_field( wp_unslash( $_GET['slytranslate_notice_nonce'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated immediately
		if ( '' === $notice_nonce || ! wp_verify_nonce( $notice_nonce, 'slytranslate_bulk_notice' ) ) {
			return;
		}

		$bulk_skipped = absint( wp_unslash( $_GET['slytranslate_bulk_skipped'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- protected by nonce above
		$bulk_errors  = absint( wp_unslash( $_GET['slytranslate_bulk_errors']  ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- protected by nonce above

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
	 * List table assets + dialog markup
	 * ------------------------------------------------------------- */

	public static function enqueue_list_table_assets( string $hook_suffix = '' ): void {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}

		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}

		if ( ! AI_Translate::current_user_can_access_translation_abilities() ) {
			return;
		}

		wp_enqueue_style(
			self::LIST_TABLE_STYLE_HANDLE,
			plugins_url( 'assets/list-table-dialog.css', self::plugin_base_file() ),
			array(),
			self::asset_version( 'assets/list-table-dialog.css' )
		);

		wp_enqueue_script(
			self::LIST_TABLE_SCRIPT_HANDLE,
			plugins_url( 'assets/list-table-dialog.js', self::plugin_base_file() ),
			array(),
			self::asset_version( 'assets/list-table-dialog.js' ),
			true
		);

		wp_localize_script( self::LIST_TABLE_SCRIPT_HANDLE, 'SlyTranslateListTable', self::get_list_table_bootstrap_data() );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				self::LIST_TABLE_SCRIPT_HANDLE,
				'slytranslate',
				plugin_dir_path( self::plugin_base_file() ) . 'languages'
			);
		}
	}

	private static function get_list_table_bootstrap_data(): array {
		$user_id = get_current_user_id();
		$last_additional_prompt = $user_id > 0 ? (string) get_user_meta( $user_id, '_slytranslate_last_additional_prompt', true ) : '';

		return array(
			'restUrl'               => esc_url_raw( rest_url( Plugin::REST_NAMESPACE . '/' ) ),
			'restNonce'             => wp_create_nonce( 'wp_rest' ),
			'lastAdditionalPrompt'  => $last_additional_prompt,
			'i18n'                  => self::get_list_table_strings(),
		);
	}

	private static function get_list_table_strings(): array {
		return array(
			'translating'      => esc_html__( 'Translating...', 'slytranslate' ),
			/* translators: %s: target language name */
			'translatingTo'    => esc_html__( 'Translating to %s...', 'slytranslate' ),
			'cancel'           => esc_html__( 'Cancel translation', 'slytranslate' ),
			'background'       => esc_html__( 'Continue in background', 'slytranslate' ),
			'close'            => esc_html__( 'Close', 'slytranslate' ),
			'success'          => esc_html__( 'Translation completed successfully.', 'slytranslate' ),
			/* translators: %s: error message */
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
			/* translators: %d: number of selected items */
			'pickerTitleBulk'  => esc_html__( 'Translate %d items', 'slytranslate' ),
			'pickerModelLabel' => esc_html__( 'AI model', 'slytranslate' ),
			'pickerSourceLabel' => esc_html__( 'Source language', 'slytranslate' ),
			'pickerTargetLabel' => esc_html__( 'Target language', 'slytranslate' ),
			'pickerSwapTitle'   => esc_html__( 'Swap source and target language', 'slytranslate' ),
			'pickerAdditionalPromptLabel' => esc_html__( 'Additional instructions (optional)', 'slytranslate' ),
			'pickerAdditionalPromptHelp'  => esc_html__( 'Supplements the site-wide translation instructions. Example: Use informal language.', 'slytranslate' ),
			'pickerOverwriteLabel'        => esc_html__( 'Overwrite existing translation', 'slytranslate' ),
			'pickerExistingTranslationNotice' => esc_html__( 'A translation already exists for the selected language. Enable overwrite to update it.', 'slytranslate' ),
			'pickerOverwriteWarning'      => esc_html__( 'This will overwrite already translated posts/pages in the selected language. Continue?', 'slytranslate' ),
			'pickerStart'      => esc_html__( 'Start translation', 'slytranslate' ),
			'pickerCancel'     => esc_html__( 'Cancel', 'slytranslate' ),
			'pickerRefresh'    => esc_html__( 'Refresh model list', 'slytranslate' ),
			'pickerLoading'    => esc_html__( 'Loading available models...', 'slytranslate' ),
			'pickerNoModels'   => esc_html__( 'No AI models are available. Configure a connector under Settings → Connectors first.', 'slytranslate' ),
			'pickerAutoOption' => esc_html__( 'Connector default', 'slytranslate' ),
			'pickerNoSelection' => esc_html__( 'Please select at least one item before translating.', 'slytranslate' ),
			/* translators: 1: current item number, 2: total item count */
			'bulkProgress'     => esc_html__( 'Translating item %1$d of %2$d...', 'slytranslate' ),
			/* translators: 1: translated item count, 2: skipped item count, 3: failed item count */
			'bulkDone'         => esc_html__( 'Bulk translation complete: %1$d translated, %2$d skipped, %3$d failed.', 'slytranslate' ),
		);
	}

	private static function get_background_bar_strings(): array {
		return array(
			/* translators: %s: target language name */
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
	}

	private static function get_background_bar_bootstrap_data(): array {
		return array(
			'restUrl'   => esc_url_raw( rest_url( Plugin::REST_NAMESPACE . '/' ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'i18n'      => self::get_background_bar_strings(),
		);
	}

	private static function plugin_base_file(): string {
		return dirname( __DIR__ ) . '/slytranslate.php';
	}

	private static function asset_version( string $asset_relative_path ): string {
		$asset_path  = dirname( __DIR__ ) . '/' . ltrim( $asset_relative_path, '/' );
		$asset_mtime = file_exists( $asset_path ) ? filemtime( $asset_path ) : false;

		if ( false === $asset_mtime ) {
			return Plugin::VERSION;
		}

		return Plugin::VERSION . '.' . (string) $asset_mtime;
	}

	public static function enqueue_list_table_script(): void {
		$strings = self::get_list_table_strings();

		?>
		<div id="slytranslate-model-picker" class="slytranslate-modal-overlay slytranslate-model-picker-overlay">
			<div class="slytranslate-modal-dialog slytranslate-model-picker-dialog">
				<h3 id="slytranslate-model-picker-title" class="slytranslate-modal-title"></h3>
				<div class="slytranslate-picker-grid">
					<label for="slytranslate-picker-source" class="slytranslate-picker-label slytranslate-picker-label-source"><?php echo esc_html( $strings['pickerSourceLabel'] ); ?></label>
					<span aria-hidden="true" class="slytranslate-picker-switch-label">&nbsp;</span>
					<label for="slytranslate-picker-target" class="slytranslate-picker-label slytranslate-picker-label-target"><?php echo esc_html( $strings['pickerTargetLabel'] ); ?></label>
					<select id="slytranslate-picker-source" class="slytranslate-picker-source"></select>
					<div class="slytranslate-picker-switch-wrap">
						<button id="slytranslate-picker-swap" type="button" class="button slytranslate-picker-icon-button" title="<?php echo esc_attr( $strings['pickerSwapTitle'] ); ?>"><span class="dashicons dashicons-controls-repeat slytranslate-picker-icon"></span></button>
					</div>
					<select id="slytranslate-picker-target" class="slytranslate-picker-target"></select>
				</div>
				<label for="slytranslate-model-picker-select" class="slytranslate-picker-label slytranslate-picker-model-label"><?php echo esc_html( $strings['pickerModelLabel'] ); ?></label>
				<div class="slytranslate-picker-model-row">
					<select id="slytranslate-model-picker-select" class="slytranslate-picker-model-select"></select>
					<button id="slytranslate-model-picker-refresh" type="button" class="button slytranslate-picker-icon-button" title="<?php echo esc_attr( $strings['pickerRefresh'] ); ?>"><span class="dashicons dashicons-update slytranslate-picker-icon"></span></button>
				</div>
				<div id="slytranslate-model-picker-status" class="slytranslate-picker-status"></div>
				<label for="slytranslate-picker-additional-prompt" class="slytranslate-picker-label slytranslate-picker-prompt-label"><?php echo esc_html( $strings['pickerAdditionalPromptLabel'] ); ?></label>
				<textarea id="slytranslate-picker-additional-prompt" rows="3" class="slytranslate-picker-prompt"></textarea>
				<div class="slytranslate-picker-prompt-help"><?php echo esc_html( $strings['pickerAdditionalPromptHelp'] ); ?></div>
				<label for="slytranslate-picker-overwrite" class="slytranslate-picker-overwrite-row">
					<input id="slytranslate-picker-overwrite" type="checkbox" />
					<span><?php echo esc_html( $strings['pickerOverwriteLabel'] ); ?></span>
				</label>
				<div class="slytranslate-picker-actions">
					<button id="slytranslate-model-picker-cancel" type="button" class="button button-secondary"><?php echo esc_html( $strings['pickerCancel'] ); ?></button>
					<button id="slytranslate-model-picker-start"  type="button" class="button button-primary"><?php echo esc_html( $strings['pickerStart'] ); ?></button>
				</div>
			</div>
		</div>
		<div id="slytranslate-list-overlay" class="slytranslate-modal-overlay slytranslate-list-overlay">
			<div class="slytranslate-modal-dialog slytranslate-list-dialog">
				<h3 id="slytranslate-list-title" class="slytranslate-modal-title"></h3>
				<div id="slytranslate-list-progress-wrap" class="slytranslate-list-progress-wrap">
					<div class="slytranslate-list-progress-track">
						<div id="slytranslate-list-bar" class="slytranslate-list-progress-bar"></div>
					</div>
					<div id="slytranslate-list-label" class="slytranslate-list-progress-label"></div>
				</div>
				<div id="slytranslate-list-result" class="slytranslate-list-result"></div>
				<div class="slytranslate-list-actions">
					<button id="slytranslate-list-bg" type="button" class="button"><?php echo esc_html( $strings['background'] ); ?></button>
					<button id="slytranslate-list-cancel" type="button" class="button button-secondary"><?php echo esc_html( $strings['cancel'] ); ?></button>
					<button id="slytranslate-list-close" type="button" class="button button-primary"><?php echo esc_html( $strings['close'] ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Global background-task status bar (rendered on every wp-admin screen)
	 * ------------------------------------------------------------- */

	public static function enqueue_global_background_bar( string $hook_suffix = '' ): void {
		// Only render for users that may translate, and only inside wp-admin.
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}
		if ( ! AI_Translate::current_user_can_access_translation_abilities() ) {
			return;
		}

		wp_enqueue_script(
			self::BACKGROUND_BAR_SCRIPT_HANDLE,
			plugins_url( 'assets/background-bar.js', self::plugin_base_file() ),
			array(),
			self::asset_version( 'assets/background-bar.js' ),
			true
		);

		wp_localize_script( self::BACKGROUND_BAR_SCRIPT_HANDLE, 'SlyTranslateBgBar', self::get_background_bar_bootstrap_data() );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				self::BACKGROUND_BAR_SCRIPT_HANDLE,
				'slytranslate',
				plugin_dir_path( self::plugin_base_file() ) . 'languages'
			);
		}
	}
}
