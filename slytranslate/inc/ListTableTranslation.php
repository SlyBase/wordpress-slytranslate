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

		// Legacy per-language bulk actions (ai_translate_to_<lang>) are no longer
		// generated since the unified picker dialog was introduced. Gate with a
		// filter so sites that still rely on them can opt back in.
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
		$rest_url   = esc_url_raw( rest_url( 'ai-translate/v1/' ) );
		$rest_nonce = wp_create_nonce( 'wp_rest' );
		$user_id    = get_current_user_id();
		$last_additional_prompt = $user_id > 0 ? (string) get_user_meta( $user_id, '_ai_translate_last_additional_prompt', true ) : '';

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

		?>
		<div id="slytranslate-model-picker" style="display:none;position:fixed;inset:0;z-index:100101;background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
			<div style="background:#fff;border-radius:8px;padding:24px 28px;max-width:520px;width:90%;box-shadow:0 8px 30px rgba(0,0,0,.25);">
				<h3 id="slytranslate-model-picker-title" style="margin:0 0 16px;font-size:15px;"></h3>
				<div style="display:grid;grid-template-columns:minmax(0,1fr) 40px minmax(0,1fr);grid-template-areas:'source-label switch-label target-label' 'source-control switch-control target-control';column-gap:14px;row-gap:6px;align-items:start;margin-bottom:12px;">
					<label for="slytranslate-picker-source" style="grid-area:source-label;display:block;font-size:12px;line-height:1.4;color:#50575e;margin:0;padding:0;"><?php echo esc_html( $strings['pickerSourceLabel'] ); ?></label>
					<span aria-hidden="true" style="grid-area:switch-label;display:block;font-size:12px;line-height:1.4;visibility:hidden;overflow:hidden;white-space:nowrap;margin:0;padding:0;">&nbsp;</span>
					<label for="slytranslate-picker-target" style="grid-area:target-label;display:block;font-size:12px;line-height:1.4;color:#50575e;margin:0;padding:0;"><?php echo esc_html( $strings['pickerTargetLabel'] ); ?></label>
					<select id="slytranslate-picker-source" style="grid-area:source-control;width:100%;min-width:0;max-width:none;height:40px;min-height:40px;"></select>
					<div style="grid-area:switch-control;display:flex;align-items:center;justify-content:center;align-self:center;">
						<button id="slytranslate-picker-swap" type="button" class="button" title="<?php echo esc_attr( $strings['pickerSwapTitle'] ); ?>" style="width:40px;min-width:40px;height:40px;min-height:40px;padding:0;line-height:0;overflow:hidden;text-align:center;"><span class="dashicons dashicons-controls-repeat" style="display:block;margin:10px auto;font-size:20px;width:20px;height:20px;line-height:1;"></span></button>
					</div>
					<select id="slytranslate-picker-target" style="grid-area:target-control;width:100%;min-width:0;max-width:none;height:40px;min-height:40px;"></select>
				</div>
				<label for="slytranslate-model-picker-select" style="display:block;font-size:12px;color:#50575e;margin-bottom:6px;"><?php echo esc_html( $strings['pickerModelLabel'] ); ?></label>
				<div style="display:flex;gap:6px;align-items:center;width:100%;box-sizing:border-box;">
					<select id="slytranslate-model-picker-select" style="flex:1;min-width:0;max-width:none;height:40px;"></select>
					<button id="slytranslate-model-picker-refresh" type="button" class="button" title="<?php echo esc_attr( $strings['pickerRefresh'] ); ?>" style="flex-shrink:0;width:40px;min-width:40px;height:40px;min-height:40px;padding:0;line-height:0;overflow:hidden;text-align:center;"><span class="dashicons dashicons-update" style="display:block;margin:10px auto;font-size:20px;width:20px;height:20px;line-height:1;"></span></button>
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
		// The background-task status bar is rendered globally by
		// enqueue_global_background_bar() so that it stays visible after the user
		// navigates away from this list-table screen.
		?>
		<script>
		(function () {
			var REST_URL   = <?php echo wp_json_encode( $rest_url ); ?>;
			var REST_NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;
			var S          = <?php echo wp_json_encode( $strings ); ?>;
			var LAST_ADDITIONAL_PROMPT = <?php echo wp_json_encode( $last_additional_prompt ); ?>;

			var overlay     = document.getElementById('slytranslate-list-overlay');
			var titleEl     = document.getElementById('slytranslate-list-title');
			var barEl       = document.getElementById('slytranslate-list-bar');
			var labelEl     = document.getElementById('slytranslate-list-label');
			var progressWrap = document.getElementById('slytranslate-list-progress-wrap');
			var resultEl    = document.getElementById('slytranslate-list-result');
			var cancelBtn   = document.getElementById('slytranslate-list-cancel');
			var bgBtn       = document.getElementById('slytranslate-list-bg');
			var closeBtn    = document.getElementById('slytranslate-list-close');

			var pollTimer      = null;
			var abortCtrl      = null;
			var isRunning      = false;
			var isCancelling   = false;
			var movedToBackground = false;
			var currentBgTaskId = null;
			var pollStartedAt   = 0;

			/* --- Background bar bridge --- */
			// The persistent background-task bar is owned by the global script
			// (enqueue_global_background_bar). We hand off completed/failed
			// states through window.SlyTranslateBg so the bar survives page
			// navigation via localStorage persistence.

			function bgApi() {
				return (typeof window !== 'undefined' && window.SlyTranslateBg) || null;
			}

			function addBgTask(postId, postTitle, lang, langName) {
				var api = bgApi();
				if (!api) { return null; }
				return api.addTask({ postId: postId, postTitle: postTitle, lang: lang, langName: langName });
			}

			function finishBgTask(id, status, editLink) {
				var api = bgApi();
				if (api && id) { api.finishTask(id, status, editLink || ''); }
			}

			function hasForegroundTranslationInProgress() {
				return isRunning && !isCancelling && currentPostId > 0 && !!currentLang;
			}

			function handOffRunningTranslationToBackground(options) {
				options = options || {};
				if (!hasForegroundTranslationInProgress() || movedToBackground) {
					return currentBgTaskId;
				}

				var taskId = addBgTask(currentPostId, currentPostTitle, currentLang, currentLangName);
				if (!taskId) {
					return null;
				}

				currentBgTaskId = taskId;
				movedToBackground = true;
				stopPolling();

				if (false !== options.hideOverlay) {
					hideOverlay({ bypassAutoBackground: true });
				}

				return currentBgTaskId;
			}

			/* --- Shared helpers --- */

			function apiPost(endpoint, body, signal) {
				return fetch(REST_URL + endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
					body: JSON.stringify(body),
					signal: signal || undefined,
				}).then(function (r) { return r.json(); });
			}

			function getProgressLabel(p) {
				if (!p || !p.phase) return S.translating;
				switch (p.phase) {
					case 'title':   return S.progressTitle;
					case 'content': return S.progressContent;
					case 'excerpt': return S.progressExcerpt;
					case 'meta':    return S.progressMeta;
					case 'saving':  return S.progressSaving;
					default:        return S.translating;
				}
			}

			/* --- Overlay dialog (foreground) --- */

			function pollProgress() {
				apiPost('ai-translate/get-progress/run', { input: { post_id: currentPostId } }).then(function (p) {
					if (!isRunning) return;
					if (p && p.phase) {
						barEl.style.width = (p.percent || 0) + '%';
						labelEl.textContent = getProgressLabel(p);
					}
				}).catch(function () {});
			}

			function nextPollDelay() {
				var elapsed = pollStartedAt ? (Date.now() - pollStartedAt) : 0;
				if (elapsed < 30000)  { return 1000; }
				if (elapsed < 120000) { return 2000; }
				return 5000;
			}

			function schedulePoll() {
				stopPolling();
				if (!isRunning) { return; }
				pollProgress();
				pollTimer = setTimeout(function () { schedulePoll(); }, nextPollDelay());
			}

			function startPolling() {
				stopPolling();
				pollStartedAt = Date.now();
				schedulePoll();
			}

			function stopPolling() {
				if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
			}

			function showOverlay(langName) {
				titleEl.textContent = S.translatingTo.replace('%s', langName);
				barEl.style.width = '0%';
				labelEl.textContent = S.translating;
				progressWrap.style.display = '';
				resultEl.style.display = 'none';
				cancelBtn.style.display = '';
				bgBtn.style.display = '';
				closeBtn.style.display = 'none';
				overlay.style.display = 'flex';
				movedToBackground = false;
			}

			function showResult(message, type, editLink) {
				stopPolling();
				isRunning = false;
				progressWrap.style.display = 'none';
				cancelBtn.style.display = 'none';
				bgBtn.style.display = 'none';
				closeBtn.style.display = '';
				resultEl.style.display = '';
				resultEl.style.background = type === 'success' ? '#edfaef' : (type === 'warning' ? '#fef8ee' : '#fcecec');
				resultEl.style.color = type === 'success' ? '#1e4620' : (type === 'warning' ? '#614a19' : '#8a1f1f');
				var html = message;
				if (editLink) {
					html += ' <a href="' + editLink + '" style="font-weight:600;">' + S.openTranslation + '</a>';
				}
				resultEl.innerHTML = html;
			}

			function hideOverlay(options) {
				options = options || {};
				if (!options.bypassAutoBackground && hasForegroundTranslationInProgress() && !movedToBackground) {
					handOffRunningTranslationToBackground();
					return;
				}
				overlay.style.display = 'none';
				stopPolling();
				isRunning = false;
			}

			var currentLangName = '';
			var currentPostId = 0;
			var currentPostTitle = '';
			var currentLang = '';
			var currentModelSlug = '';

			function doTranslate(postId, postTitle, lang, langName, modelSlug, additionalPrompt) {
				showOverlay(langName);
				currentLangName = langName;
				currentPostId = postId;
				currentPostTitle = postTitle || '';
				currentLang = lang;
				currentModelSlug = modelSlug || '';
				isRunning = true;
				isCancelling = false;
				abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
				startPolling();

				apiPost('ai-translate/translate-content/run', {
					input: {
						post_id: postId,
						target_language: lang,
						post_status: 'draft',
						overwrite: false,
						translate_title: true,
						// The model is chosen explicitly in the picker dialog
						// before each translation starts, so the list-table
						// flow no longer falls back to a (potentially stale)
						// localStorage value from the editor sidebar.
						model_slug: modelSlug || undefined,
						additional_prompt: additionalPrompt || undefined,
					}
				}, abortCtrl ? abortCtrl.signal : undefined)
					.then(function (resp) {
						if (movedToBackground) {
							// Foreground fetch resolved while user already moved
							// the task to the bg bar. We still want to surface
							// server-side errors (e.g. 500 / WP_Error) on the
							// bg task instead of letting it hang forever, so a
							// failure response must be reflected even here. A
							// successful response is left to the bg bar's own
							// get-translation-status poll which already handles
							// the 'done' transition.
							if (resp && resp.code && currentBgTaskId) {
								finishBgTask(currentBgTaskId, 'error', '');
							}
							return;
						}
						if (resp && resp.code) {
							showResult(S.error.replace('%s', resp.message || resp.code), 'error');
						} else {
							barEl.style.width = '100%';
							var editLink = resp && resp.edit_link ? resp.edit_link : '';
							showResult(S.success, 'success', editLink);
						}
					})
					.catch(function (err) {
						if (movedToBackground) {
							// Network failure / 500 / browser-aborted fetch
							// after handoff: an AbortError from page navigation
							// is fine (the server keeps running thanks to
							// ignore_user_abort + set_time_limit(0)), but a
							// real fetch failure means the upstream actually
							// died (e.g. ingress / FPM timeout) and the bg
							// bar would otherwise poll forever.
							if (err && err.name !== 'AbortError' && currentBgTaskId) {
								finishBgTask(currentBgTaskId, 'error', '');
							}
							return;
						}
						if (err && err.name === 'AbortError') {
							showResult(S.cancelled, 'warning');
						} else {
							showResult(S.error.replace('%s', err && err.message ? err.message : ''), 'error');
						}
					})
					.finally(function () {
						abortCtrl = null;
						isCancelling = false;
					});
			}

			// Cancel button.
			cancelBtn.addEventListener('click', function () {
				isCancelling = true;
				if (abortCtrl) { abortCtrl.abort(); }
				// Pass post_id so the server clears the per-post progress
				// transient on cancel, otherwise the bg-bar polls would still
				// display the cancelled job's last percentage briefly.
				apiPost('ai-translate/cancel-translation/run', { input: { post_id: currentPostId } }).catch(function () {});
			});

			// Background button: hand the running translation off to the global,
			// localStorage-backed status bar so it survives page navigation.
			bgBtn.addEventListener('click', function () {
				handOffRunningTranslationToBackground();
			});

			// If the foreground dialog is dismissed while a translation is still
			// running, treat that the same as an explicit "Continue in background"
			// handoff so the user still sees the persistent bg-bar entry.
			overlay.addEventListener('click', function (e) {
				if (e.target !== overlay || !hasForegroundTranslationInProgress()) {
					return;
				}
				handOffRunningTranslationToBackground();
			});

			document.addEventListener('keydown', function (e) {
				if ('Escape' !== e.key || 'flex' !== overlay.style.display || !hasForegroundTranslationInProgress()) {
					return;
				}
				e.preventDefault();
				handOffRunningTranslationToBackground();
			});

			// The server keeps translating after the browser navigates away
			// (ignore_user_abort + set_time_limit(0)), so persist the running job
			// into the global background bar before the page unloads.
			window.addEventListener('pagehide', function () {
				handOffRunningTranslationToBackground({ hideOverlay: false });
			});

			window.addEventListener('beforeunload', function () {
				handOffRunningTranslationToBackground({ hideOverlay: false });
			});

			// Close button.
			closeBtn.addEventListener('click', function () {
				hideOverlay();
				window.location.reload();
			});

			/* --- Model picker dialog --- */

			var pickerOverlay = document.getElementById('slytranslate-model-picker');
			var pickerTitle   = document.getElementById('slytranslate-model-picker-title');
			var pickerSelect  = document.getElementById('slytranslate-model-picker-select');
			var pickerStatus  = document.getElementById('slytranslate-model-picker-status');
			var pickerStart   = document.getElementById('slytranslate-model-picker-start');
			var pickerCancel  = document.getElementById('slytranslate-model-picker-cancel');
			var pickerRefresh = document.getElementById('slytranslate-model-picker-refresh');
			var pickerSourceSel = document.getElementById('slytranslate-picker-source');
			var pickerTargetSel = document.getElementById('slytranslate-picker-target');
			var pickerSwapBtn   = document.getElementById('slytranslate-picker-swap');
			var pickerPromptEl  = document.getElementById('slytranslate-picker-additional-prompt');
			var pickerOnConfirm = null;
			var pickerLastSlug = '';
			var pickerAllLanguages = []; // [{code,name}]
			var pickerMissingCodes = {}; // {code: true} – preferred targets (no translation yet)
			try { pickerLastSlug = (window.localStorage && window.localStorage.getItem('aiTranslateModelSlug')) || ''; } catch (e) {}

			function readStoredAdditionalPrompt() {
				try {
					var stored = (window.localStorage && window.localStorage.getItem('aiTranslateLastAdditionalPrompt')) || '';
					return stored || LAST_ADDITIONAL_PROMPT || '';
				} catch (e) {
					return LAST_ADDITIONAL_PROMPT || '';
				}
			}
			function storeAdditionalPrompt(value) {
				try { if (window.localStorage) { window.localStorage.setItem('aiTranslateLastAdditionalPrompt', value || ''); } } catch (e) {}
			}
			function readStoredTargetLang() {
				try { return (window.localStorage && window.localStorage.getItem('aiTranslateTargetLanguage')) || ''; } catch (e) { return ''; }
			}
			function storeTargetLang(value) {
				try { if (window.localStorage && value) { window.localStorage.setItem('aiTranslateTargetLanguage', value); } } catch (e) {}
			}

			function fillLanguageSelect(selectEl, languages, selectedCode) {
				selectEl.innerHTML = '';
				languages.forEach(function (l) {
					if (!l || !l.code) { return; }
					var opt = document.createElement('option');
					opt.value = l.code;
					opt.textContent = (l.name || l.code) + ' (' + l.code + ')';
					if (l.code === selectedCode) { opt.selected = true; }
					selectEl.appendChild(opt);
				});
			}

			function refreshTargetOptions(preferredCode) {
				var sourceCode = pickerSourceSel.value;
				var targets = pickerAllLanguages.filter(function (l) { return l && l.code && l.code !== sourceCode; });
				// Preferred ordering: missing first, then existing.
				targets.sort(function (a, b) {
					var aMissing = pickerMissingCodes[a.code] ? 0 : 1;
					var bMissing = pickerMissingCodes[b.code] ? 0 : 1;
					return aMissing - bMissing;
				});
				var chosen = '';
				if (preferredCode && targets.some(function (l) { return l.code === preferredCode; })) {
					chosen = preferredCode;
				} else {
					var stored = readStoredTargetLang();
					if (stored && targets.some(function (l) { return l.code === stored; })) {
						chosen = stored;
					} else if (targets.length) {
						chosen = targets[0].code;
					}
				}
				fillLanguageSelect(pickerTargetSel, targets, chosen);
			}

			function fillPickerSelect(models, defaultSlug) {
				pickerSelect.innerHTML = '';
				var preselected = pickerLastSlug || defaultSlug || '';
				var hasPreselected = false;
				if (Array.isArray(models)) {
					models.forEach(function (m) {
						if (!m || !m.value) { return; }
						var opt = document.createElement('option');
						opt.value = m.value;
						opt.textContent = m.label || m.value;
						if (m.value === preselected) { opt.selected = true; hasPreselected = true; }
						pickerSelect.appendChild(opt);
					});
				}
				// Always offer a "connector default" option so users can fall
				// back to the connector-side default model. Empty value means
				// no model_slug is sent and the AI Client picks for us.
				var autoOpt = document.createElement('option');
				autoOpt.value = '';
				autoOpt.textContent = S.pickerAutoOption;
				if (!hasPreselected) { autoOpt.selected = true; }
				pickerSelect.appendChild(autoOpt);
			}

			function loadPickerModels(forceRefresh) {
				pickerStatus.textContent = S.pickerLoading;
				pickerStart.disabled = true;
				return apiPost('ai-translate/get-available-models/run', { input: { refresh: !!forceRefresh } })
					.then(function (resp) {
						var models = resp && Array.isArray(resp.models) ? resp.models : [];
						var defaultSlug = resp && resp.defaultModelSlug ? resp.defaultModelSlug : '';
						fillPickerSelect(models, defaultSlug);
						if (!models.length) {
							pickerStatus.textContent = S.pickerNoModels;
						} else {
							pickerStatus.textContent = '';
						}
						pickerStart.disabled = false;
					})
					.catch(function () {
						fillPickerSelect([], '');
						pickerStatus.textContent = S.pickerNoModels;
						pickerStart.disabled = false;
					});
			}

			function showPicker(titleText, context, onConfirm) {
				pickerTitle.textContent = titleText;
				pickerOnConfirm = onConfirm;
				context = context || {};
				pickerAllLanguages = Array.isArray(context.allLanguages) && context.allLanguages.length
					? context.allLanguages
					: (Array.isArray(context.languages) ? context.languages : []);
				pickerMissingCodes = {};
				if (Array.isArray(context.languages)) {
					context.languages.forEach(function (l) { if (l && l.code) { pickerMissingCodes[l.code] = true; } });
				}
				var sourceCode = context.sourceLang || (pickerAllLanguages[0] ? pickerAllLanguages[0].code : '');
				fillLanguageSelect(pickerSourceSel, pickerAllLanguages, sourceCode);
				refreshTargetOptions(context.preferredTarget || '');
				pickerPromptEl.value = readStoredAdditionalPrompt();
				pickerOverlay.style.display = 'flex';
				loadPickerModels(false);
			}

			function hidePicker() {
				pickerOverlay.style.display = 'none';
				pickerOnConfirm = null;
			}

			pickerCancel.addEventListener('click', hidePicker);
			pickerRefresh.addEventListener('click', function () { loadPickerModels(true); });
			pickerSourceSel.addEventListener('change', function () {
				var prev = pickerTargetSel.value;
				refreshTargetOptions(prev);
			});
			pickerSwapBtn.addEventListener('click', function () {
				var src = pickerSourceSel.value;
				var tgt = pickerTargetSel.value;
				if (!src || !tgt) { return; }
				// Set the new source first, then re-build target options so
				// the previous source becomes available again as a target.
				fillLanguageSelect(pickerSourceSel, pickerAllLanguages, tgt);
				refreshTargetOptions(src);
			});
			pickerStart.addEventListener('click', function () {
				var slug = pickerSelect.value || '';
				try {
					if (window.localStorage) {
						if (slug) { window.localStorage.setItem('aiTranslateModelSlug', slug); }
					}
				} catch (e) {}
				pickerLastSlug = slug;
				var sourceLang = pickerSourceSel.value || '';
				var targetLang = pickerTargetSel.value || '';
				var targetOpt  = pickerTargetSel.options[pickerTargetSel.selectedIndex];
				var targetName = targetOpt ? (targetOpt.textContent || '').replace(/\s*\([^)]*\)\s*$/, '').trim() : targetLang;
				var additionalPrompt = pickerPromptEl.value || '';
				if (!targetLang) { return; }
				storeTargetLang(targetLang);
				storeAdditionalPrompt(additionalPrompt);
				var cb = pickerOnConfirm;
				hidePicker();
				if (typeof cb === 'function') {
					cb({
						modelSlug: slug,
						sourceLang: sourceLang,
						targetLang: targetLang,
						targetLangName: targetName,
						additionalPrompt: additionalPrompt,
					});
				}
			});

			/* --- Bulk translation runner (one dialog, many posts) --- */

			function getBulkSelectedPostIds() {
				var ids = [];
				var nodes = document.querySelectorAll('input[name="post[]"]:checked, input[name="ids[]"]:checked');
				for (var i = 0; i < nodes.length; i++) {
					var v = parseInt(nodes[i].value, 10);
					if (v > 0) { ids.push(v); }
				}
				return ids;
			}

			function getRowTitle(postId) {
				var row = document.getElementById('post-' + postId);
				if (!row) { return ''; }
				var titleEl = row.querySelector('.row-title');
				return titleEl ? (titleEl.textContent || '').trim() : '';
			}

			function runBulkTranslation(postIds, lang, langName, modelSlug, additionalPrompt) {
				// Each post is added as a background task and translated
				// sequentially via the same translate-content endpoint we use
				// for single-row translations. The bg-bar polls per task and
				// reflects done/error state, so the user gets per-item
				// feedback instead of a blocking overlay.
				var i = 0;
				function next() {
					if (i >= postIds.length) { return; }
					var postId = postIds[i++];
					var title  = getRowTitle(postId);
					var taskId = addBgTask(postId, title, lang, langName);
					apiPost('ai-translate/translate-content/run', {
						input: {
							post_id: postId,
							target_language: lang,
							post_status: 'draft',
							overwrite: false,
							translate_title: true,
							model_slug: modelSlug || undefined,
							additional_prompt: additionalPrompt || undefined,
						}
					}).then(function (resp) {
						if (resp && resp.code) {
							finishBgTask(taskId, 'error', '');
						} else if (resp && resp.edit_link) {
							finishBgTask(taskId, 'done', resp.edit_link);
						}
						// Otherwise leave it to the bg-bar's status poll.
					}).catch(function () {
						finishBgTask(taskId, 'error', '');
					}).then(next);
				}
				next();
			}

			// Intercept the WordPress bulk-action form submission for our
			// unified "Translate" entry. The user picks the target language
			// (and source/model/additional prompt) in the picker dialog.
			document.addEventListener('submit', function (e) {
				var form = e.target;
				if (!form || form.id !== 'posts-filter') { return; }
				var topSel = form.querySelector('select[name="action"]');
				var botSel = form.querySelector('select[name="action2"]');
				var actionTop = topSel ? topSel.value : '';
				var actionBot = botSel ? botSel.value : '';
				var action = (actionTop && actionTop !== '-1') ? actionTop : actionBot;
				if (action !== 'ai_translate_bulk') { return; }

				var ids = getBulkSelectedPostIds();
				if (!ids.length) {
					e.preventDefault();
					window.alert(S.pickerNoSelection);
					return;
				}

				e.preventDefault();

				// Read languages + source from the first selected row's
				// hidden translate row-action link (it carries data-langs and
				// data-source-lang). All rows share the same Polylang config,
				// so we can safely use any of them as a reference.
				var firstRow = document.getElementById('post-' + ids[0]);
				var refLink  = firstRow ? firstRow.querySelector('.slytranslate-ajax-translate') : null;
				var languages = [];
				var allLanguages = [];
				var sourceLang = '';
				if (refLink) {
					try { languages    = JSON.parse(refLink.getAttribute('data-langs') || '[]'); } catch (err) {}
					try { allLanguages = JSON.parse(refLink.getAttribute('data-all-langs') || '[]'); } catch (err) {}
					sourceLang = refLink.getAttribute('data-source-lang') || '';
				}
				if (!allLanguages.length) { allLanguages = languages; }
				if (!allLanguages.length) {
					window.alert(S.pickerNoModels);
					if (topSel) { topSel.value = '-1'; }
					if (botSel) { botSel.value = '-1'; }
					return;
				}

				var titleText = S.pickerTitleBulk.replace('%d', ids.length);
				showPicker(titleText, {
					sourceLang: sourceLang,
					languages: languages,
					allLanguages: allLanguages,
				}, function (result) {
					runBulkTranslation(ids, result.targetLang, result.targetLangName, result.modelSlug, result.additionalPrompt);
					// Reset the bulk-action selects so the page does not show
					// the action as still selected after we have handled it.
					if (topSel) { topSel.value = '-1'; }
					if (botSel) { botSel.value = '-1'; }
				});
			}, true);

			// Intercept translate row-action link clicks → show picker, then
			// run a single foreground translation with the picked options.
			document.addEventListener('click', function (e) {
				var link = e.target.closest('.slytranslate-ajax-translate');
				if (!link) return;
				e.preventDefault();
				var postId    = parseInt(link.getAttribute('data-post-id'), 10);
				var postTitle = link.getAttribute('data-post-title') || '';
				var sourceLang = link.getAttribute('data-source-lang') || '';
				var languages = [];
				var allLanguages = [];
				try { languages    = JSON.parse(link.getAttribute('data-langs') || '[]'); } catch (err) {}
				try { allLanguages = JSON.parse(link.getAttribute('data-all-langs') || '[]'); } catch (err) {}
				if (!allLanguages.length) { allLanguages = languages; }
				if (!postId || !allLanguages.length) { return; }

				showPicker(S.pickerTitle, {
					sourceLang: sourceLang,
					languages: languages,
					allLanguages: allLanguages,
				}, function (result) {
					doTranslate(postId, postTitle, result.targetLang, result.targetLangName, result.modelSlug, result.additionalPrompt);
				});
			});
		})();
		</script>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Global background-task status bar (rendered on every wp-admin screen)
	 * ------------------------------------------------------------- */

	public static function enqueue_global_background_bar(): void {
		// Only render for users that may translate, and only inside wp-admin.
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}
		if ( ! AI_Translate::current_user_can_access_translation_abilities() ) {
			return;
		}
		// Skip rendering entirely when the user has no active or recent job,
		// saving the KB of inline JS for every admin page load.
		if ( ! TranslationProgressTracker::user_has_recent_job() ) {
			return;
		}

		$rest_url   = esc_url_raw( rest_url( 'ai-translate/v1/' ) );
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
		?>
		<script>
		(function () {
			if (window.SlyTranslateBg) { return; }

			var REST_URL   = <?php echo wp_json_encode( $rest_url ); ?>;
			var REST_NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;
			var S          = <?php echo wp_json_encode( $strings ); ?>;
			var STORAGE_KEY = 'slytranslate_bg_tasks_v1';
			var DONE_RETENTION_MS = 5000; // auto-dismiss completed tasks after 5s

			function escHtml(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
			function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

			function slyDebugEnabled() {
				return !!(typeof window !== 'undefined' && window.SLY_TRANSLATE_DEBUG);
			}

			function slyDebug() {
				if (!slyDebugEnabled() || typeof console === 'undefined' || !console.log) { return; }
				var args = ['[SlyTranslate:bg-bar]'];
				for (var i = 0; i < arguments.length; i++) { args.push(arguments[i]); }
				try { console.log.apply(console, args); } catch (e) {}
			}

			function loadTasks() {
				try {
					var raw = window.localStorage.getItem(STORAGE_KEY);
					if (!raw) { return []; }
					var parsed = JSON.parse(raw);
					return Array.isArray(parsed) ? parsed : [];
				} catch (e) { return []; }
			}

			function saveTasks(tasks) {
				try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(tasks)); } catch (e) {}
			}

			function pruneTasks(tasks) {
				var now = Date.now();
				return tasks.filter(function (t) {
					if (t.status === 'running') { return true; }
					return (now - (t.finishedAt || now)) < DONE_RETENTION_MS;
				});
			}

			var tasks = pruneTasks(loadTasks());
			saveTasks(tasks);

			function findTask(id) {
				for (var i = 0; i < tasks.length; i++) { if (tasks[i].id === id) { return tasks[i]; } }
				return null;
			}

			function ensureContainer() {
				var existing = document.getElementById('slytranslate-bg-notice');
				if (existing) { return existing; }

				var notice = document.createElement('div');
				notice.id = 'slytranslate-bg-notice';
				notice.className = 'notice notice-info';
				notice.style.padding = '8px 12px';
				notice.style.margin = '8px 0';

				// Insert after .wp-header-end (where WordPress places admin
				// notices) to make the bar feel native.
				var headerEnd = document.querySelector('.wp-header-end');
				if (headerEnd && headerEnd.parentNode) {
					headerEnd.parentNode.insertBefore(notice, headerEnd.nextSibling);
				} else {
					var wrap = document.querySelector('#wpbody-content');
					if (wrap) { wrap.insertBefore(notice, wrap.firstChild); }
					else { document.body.appendChild(notice); }
				}
				return notice;
			}

			function removeContainer() {
				var existing = document.getElementById('slytranslate-bg-notice');
				if (existing && existing.parentNode) { existing.parentNode.removeChild(existing); }
			}

			function statusLabel(task) {
				var label = task.postTitle ? task.postTitle : ('#' + (task.postId || '?'));
				var lang  = task.langName || task.lang;
				var suffix = '';
				if (task.status === 'done')      { suffix = ' — ' + S.success; }
				if (task.status === 'error')     { suffix = ' — ' + S.error; }
				if (task.status === 'cancelled') { suffix = ' — ' + S.cancelled; }
				return label + ' (' + lang + ')' + suffix;
			}

			function statusClass(task) {
				if (task.status === 'done')      { return 'notice-success'; }
				if (task.status === 'error')     { return 'notice-error'; }
				if (task.status === 'cancelled') { return 'notice-warning'; }
				return 'notice-info';
			}

			function statusBadge(task) {
				var bg = '#dbe7ff', fg = '#1d4ed8';
				if (task.status === 'done')      { bg = '#d1f0d6'; fg = '#1e4620'; }
				if (task.status === 'error')     { bg = '#fbd6d6'; fg = '#8a1f1f'; }
				if (task.status === 'cancelled') { bg = '#fcefc7'; fg = '#614a19'; }
				return '<span style="display:inline-block;width:8px;height:8px;border-radius:999px;background:' + fg + ';margin-right:8px;flex:none;"></span>';
			}

			function isCollapsed() {
				try { return window.localStorage.getItem(STORAGE_KEY + '_collapsed') === '1'; } catch (e) { return false; }
			}

			function setCollapsed(v) {
				try { window.localStorage.setItem(STORAGE_KEY + '_collapsed', v ? '1' : '0'); } catch (e) {}
			}

			function summaryText() {
				var running = 0, done = 0, error = 0;
				tasks.forEach(function (t) {
					if (t.status === 'running')   { running++; }
					if (t.status === 'done')      { done++; }
					if (t.status === 'error')     { error++; }
				});
				var parts = [];
				if (running) { parts.push(running + ' ' + S.summaryRunning); }
				if (done)    { parts.push(done    + ' ' + S.summaryDone); }
				if (error)   { parts.push(error   + ' ' + S.summaryError); }
				return parts.join(' · ');
			}

			function render() {
				if (!tasks.length) { removeContainer(); return; }

				var notice  = ensureContainer();
				notice.className = 'notice ' + statusClass(tasks[0]);
				notice.style.padding = '8px 12px';

				var collapsed = isCollapsed();
				var html = '';

				html += '<div style="display:flex;align-items:center;gap:8px;">';
				html += '<button type="button" class="button-link slytranslate-bg-toggle" aria-expanded="' + (collapsed ? 'false' : 'true') + '" style="padding:0;color:#1d2327;text-decoration:none;font-weight:600;display:flex;align-items:center;gap:6px;">';
				html += '<span class="dashicons dashicons-arrow-' + (collapsed ? 'right' : 'down') + '" style="margin:0;"></span>';
				html += escHtml(S.header);
				html += '</button>';
				html += '<span style="flex:1;color:#50575e;font-size:12px;">' + escHtml(summaryText()) + '</span>';
				html += '<button type="button" class="button-link slytranslate-bg-dismiss-all" style="color:#50575e;font-size:12px;">' + escHtml(S.dismissAll) + '</button>';
				html += '</div>';

				if (collapsed) {
					notice.innerHTML = html;
					return;
				}

				html += '<ul style="list-style:none;margin:6px 0 0;padding:0;">';
				tasks.forEach(function (t) {
					var labelColor = '#1d2327';
					if (t.status === 'done')      { labelColor = '#1e4620'; }
					if (t.status === 'error')     { labelColor = '#8a1f1f'; }
					if (t.status === 'cancelled') { labelColor = '#614a19'; }

					html += '<li style="padding:4px 0;border-top:1px solid #f0f0f1;">';
					html += '<div style="display:flex;align-items:center;gap:8px;">';
					html += statusBadge(t);
					html += '<span style="flex:1;min-width:0;color:' + labelColor + ';overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escAttr(statusLabel(t)) + '">' + escHtml(statusLabel(t));
					if (t.status === 'done' && t.editLink) {
						html += ' <a href="' + escAttr(t.editLink) + '" style="margin-left:6px;">' + escHtml(S.openTranslation) + '</a>';
					}
					html += '</span>';
					if (t.status === 'running') {
						if (typeof t.percent === 'number' && t.percent >= 0) {
							html += '<span style="color:#50575e;font-size:12px;font-variant-numeric:tabular-nums;flex:none;">' + Math.min(100, t.percent) + '%</span>';
						}
						html += '<button type="button" class="button button-small slytranslate-bg-cancel" data-task-id="' + escAttr(t.id) + '">' + escHtml(S.cancel) + '</button>';
					} else {
						html += '<button type="button" class="button-link slytranslate-bg-dismiss" aria-label="' + escAttr(S.dismiss) + '" data-task-id="' + escAttr(t.id) + '" style="color:#50575e;font-size:18px;line-height:1;flex:none;">&times;</button>';
					}
					html += '</div>';

					if (t.status === 'running') {
						var pct = (typeof t.percent === 'number' && t.percent >= 0) ? Math.min(100, t.percent) : 0;
						var phaseLabel = t.phaseLabel ? t.phaseLabel : '';
						html += '<div style="margin-top:4px;display:flex;align-items:center;gap:8px;">';
						html += '<div style="flex:1;height:4px;border-radius:999px;overflow:hidden;background:#dcdcde;">';
						html += '<div style="width:' + pct + '%;height:100%;background:linear-gradient(90deg,#3858e9 0%,#1d4ed8 100%);transition:width .3s ease;"></div>';
						html += '</div>';
						if (phaseLabel) {
							html += '<span style="font-size:11px;color:#50575e;flex:none;max-width:50%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escAttr(phaseLabel) + '">' + escHtml(phaseLabel) + '</span>';
						}
						html += '</div>';
					}

					html += '</li>';
				});
				html += '</ul>';
				notice.innerHTML = html;
			}

			function persistAndRender() {
				tasks = pruneTasks(tasks);
				saveTasks(tasks);
				render();
			}

			function apiPost(endpoint, body) {
				return fetch(REST_URL + endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
					body: JSON.stringify(body || {}),
				}).then(function (r) { return r.json(); });
			}

			function getProgressLabel(p) {
				if (!p || !p.phase) { return S.translating; }
				switch (p.phase) {
					case 'title':   return S.progressTitle;
					case 'content':
						var total = parseInt(p.total_chunks, 10) || 0;
						var curr  = parseInt(p.current_chunk, 10) || 0;
						if (total > 0 && curr >= total) {
							return S.progressContentFinishing;
						}
						return S.progressContent;
					case 'excerpt': return S.progressExcerpt;
					case 'meta':    return S.progressMeta;
					case 'saving':  return S.progressSaving;
					default:        return S.translating;
				}
			}

			function pollTask(task) {
				if (task.status !== 'running') { return; }
				if (!task.postId || !task.lang) { return; }

				apiPost('ai-translate/get-translation-status/run', { input: { post_id: task.postId } })
					.then(function (resp) {
						if (!resp || !Array.isArray(resp.translations)) { return; }
						for (var i = 0; i < resp.translations.length; i++) {
							var entry = resp.translations[i];
							if (entry.lang === task.lang && entry.exists) {
								slyDebug('translation finished (status check)', { taskId: task.id, postId: task.postId, lang: task.lang, editLink: entry.edit_link });
								finishTask(task.id, 'done', entry.edit_link || '');
								return;
							}
						}
					})
					.catch(function (err) { slyDebug('status check FAILED', { taskId: task.id, error: String(err && err.message || err) }); });
			}

			function pollProgress() {
				var runningTasks = tasks.filter(function (t) { return t.status === 'running'; });
				if (!runningTasks.length) { return; }

				runningTasks.forEach(function (t) {
					if (!t.postId) { return; }
					var startedAt = Date.now();
					apiPost('ai-translate/get-progress/run', { input: { post_id: t.postId } })
						.then(function (p) {
							var elapsedMs = Date.now() - startedAt;
							if (slyDebugEnabled()) {
								slyDebug('progress poll', {
									taskId: t.id,
									postId: t.postId,
									postTitle: t.postTitle,
									lang: t.lang,
									runningSec: Math.round((Date.now() - (t.startedAt || Date.now())) / 1000),
									responseMs: elapsedMs,
									response: p,
								});
							}
							if (!p || !p.phase) { return; }
							var percent = Math.max(0, Math.min(100, parseInt(p.percent, 10) || 0));
							// Never let the bar move backwards. Mid-flight
							// total recalculation (when recursive inner-block
							// translation extends the total) would otherwise
							// produce visible regressions like 92% → 89%.
							if (typeof t.percent === 'number' && t.percent > percent) {
								percent = t.percent;
							}
							var phaseLabel = getProgressLabel(p);
							if (t.percent !== percent || t.phaseLabel !== phaseLabel) {
								slyDebug('progress changed', { taskId: t.id, from: { percent: t.percent, label: t.phaseLabel }, to: { percent: percent, label: phaseLabel } });
								t.percent = percent;
								t.phaseLabel = phaseLabel;
								t.lastChangeAt = Date.now();
								persistAndRender();
							} else if (t.lastChangeAt && (Date.now() - t.lastChangeAt) > 90000) {
								// No progress change for 90+ seconds while the
								// transient still claims an active phase. Most
								// likely the upstream PHP worker died (ingress
								// timeout, FPM kill, fatal). Mark as error so
								// the bar doesn't spin forever.
								slyDebug('marking task as error — no progress for >90s', { taskId: t.id, postId: t.postId, lastPhase: p.phase, lastPercent: percent });
								finishTask(t.id, 'error', '');
							} else if (t.lastChangeAt && (Date.now() - t.lastChangeAt) > 30000) {
								slyDebug('progress STALLED — no change for >30s', {
									taskId: t.id,
									postId: t.postId,
									percent: percent,
									phase: p.phase,
									phaseLabel: phaseLabel,
									currentChunk: p.current_chunk,
									totalChunks: p.total_chunks,
									stalledForSec: Math.round((Date.now() - t.lastChangeAt) / 1000),
								});
							}
						})
						.catch(function (err) {
							slyDebug('progress poll FAILED', { taskId: t.id, postId: t.postId, error: String(err && err.message || err) });
						});
				});
			}

			function pollAll() {
				tasks.forEach(pollTask);
				pollProgress();
			}

			var bgPollTimer = null;
			function bgNextPollDelay() {
				// Base backoff on the oldest running task's elapsed time.
				var now = Date.now();
				var minStartedAt = Infinity;
				tasks.forEach(function (t) {
					if (t.status === 'running' && t.startedAt && t.startedAt < minStartedAt) {
						minStartedAt = t.startedAt;
					}
				});
				var elapsed = (minStartedAt < Infinity) ? (now - minStartedAt) : 0;
				if (elapsed < 30000)  { return 2000; }
				if (elapsed < 120000) { return 4000; }
				return 8000;
			}

			function scheduleBgPoll() {
				if (bgPollTimer) { clearTimeout(bgPollTimer); bgPollTimer = null; }
				var running = tasks.filter(function (t) { return t.status === 'running'; });
				if (!running.length) { return; }
				bgPollTimer = setTimeout(function () {
					pollAll();
					scheduleBgPoll();
				}, bgNextPollDelay());
			}

			function addTask(spec) {
				var id = 'sly-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
				var task = {
					id: id,
					postId: parseInt(spec.postId, 10) || 0,
					postTitle: String(spec.postTitle || ''),
					lang: String(spec.lang || ''),
					langName: String(spec.langName || spec.lang || ''),
					status: 'running',
					startedAt: Date.now(),
					lastChangeAt: Date.now(),
					editLink: '',
				};
				slyDebug('addTask', task);
				tasks.push(task);
				persistAndRender();
				// Kick off an immediate poll so a quickly-finishing task is
				// not stuck in "running" until the next scheduler tick.
				setTimeout(function () { pollTask(task); }, 1500);
				// Reset the adaptive scheduler so this new task starts at the
				// fast polling rate rather than inheriting a stale backoff.
				scheduleBgPoll();
				return id;
			}

			function finishTask(id, status, editLink) {
				var task = findTask(id);
				if (!task) { return; }
				slyDebug('finishTask', { taskId: id, status: status, editLink: editLink, totalDurationSec: Math.round((Date.now() - (task.startedAt || Date.now())) / 1000) });
				task.status = status;
				task.editLink = editLink || '';
				task.finishedAt = Date.now();
				persistAndRender();
			}

			function dismissTask(id) {
				tasks = tasks.filter(function (t) { return t.id !== id; });
				persistAndRender();
			}

			function cancelTask(id) {
				// The translation cancellation flag is global per-site; cancel
				// the running translation server-side and mark this task.
				apiPost('ai-translate/cancel-translation/run', {}).catch(function () {});
				finishTask(id, 'cancelled', '');
			}

			document.addEventListener('click', function (e) {
				var cancelEl = e.target.closest('.slytranslate-bg-cancel');
				if (cancelEl) { cancelTask(cancelEl.getAttribute('data-task-id')); return; }
				var dismissEl = e.target.closest('.slytranslate-bg-dismiss');
				if (dismissEl) { dismissTask(dismissEl.getAttribute('data-task-id')); return; }
				var dismissAllEl = e.target.closest('.slytranslate-bg-dismiss-all');
				if (dismissAllEl) {
					tasks = tasks.filter(function (t) { return t.status === 'running'; });
					persistAndRender();
					return;
				}
				var toggleEl = e.target.closest('.slytranslate-bg-toggle');
				if (toggleEl) { setCollapsed(!isCollapsed()); render(); return; }
			});

			// Cross-tab sync: when another wp-admin tab updates tasks, mirror
			// the change locally without losing focus on this page.
			window.addEventListener('storage', function (event) {
				if (event.key !== STORAGE_KEY) { return; }
				tasks = pruneTasks(loadTasks());
				render();
			});

			window.SlyTranslateBg = {
				addTask: addTask,
				finishTask: finishTask,
				dismissTask: dismissTask,
			};

			render();
			pollAll();
			scheduleBgPoll();
		})();
		</script>
		<?php
	}
}
