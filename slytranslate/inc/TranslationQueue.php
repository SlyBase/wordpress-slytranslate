<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Background processing for bulk translation jobs.
 *
 * Prefers Action Scheduler when present (bundled by WooCommerce and others),
 * falls back to single WP-Cron events. Granularity is one post per action so
 * jobs survive PHP timeouts and individual posts can fail without killing the
 * whole run. The browser-driven REST path stays available as a fallback for
 * sites without a working cron.
 */
class TranslationQueue {

	public const WORKER_HOOK = 'slytranslate_process_queued_translation';

	private const JOB_OPTION_PREFIX = 'slytranslate_queue_job_';

	/** @var callable|null Test seam: receives (string $hook, array $args). */
	private static $scheduler_override = null;

	public static function set_scheduler_for_testing( ?callable $scheduler ): void {
		self::$scheduler_override = $scheduler;
	}

	/* ---------------------------------------------------------------
	 * Transport detection
	 * ------------------------------------------------------------- */

	public static function get_transport(): string {
		if ( null !== self::$scheduler_override ) {
			return 'test';
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return 'action_scheduler';
		}
		if ( function_exists( 'wp_schedule_single_event' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			return 'wp_cron';
		}

		return 'none';
	}

	public static function is_available(): bool {
		return 'none' !== self::get_transport();
	}

	/* ---------------------------------------------------------------
	 * Enqueueing
	 * ------------------------------------------------------------- */

	/**
	 * Queue one translation job: one scheduled action per source post.
	 *
	 * Supported $options keys: post_status, overwrite, translate_title,
	 * additional_prompt, source_language, model_slug.
	 *
	 * @param int[]  $post_ids        Source post IDs.
	 * @param string $target_language Target language code.
	 * @param array  $options         Per-post translate_post() options.
	 * @return array{job_id:string,total:int}|\WP_Error
	 */
	public static function enqueue_bulk( array $post_ids, string $target_language, array $options = array() ): array|\WP_Error {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		if ( empty( $post_ids ) ) {
			return new \WP_Error( 'queue_empty', __( 'No posts to translate.', 'slytranslate' ) );
		}

		$target_language = sanitize_key( $target_language );
		if ( '' === $target_language ) {
			return new \WP_Error( 'missing_target_language', __( 'Target language is required.', 'slytranslate' ) );
		}

		if ( ! self::is_available() ) {
			return new \WP_Error( 'queue_unavailable', __( 'No background processing transport is available (Action Scheduler missing and WP-Cron disabled).', 'slytranslate' ) );
		}

		$job_id = self::generate_job_id();
		$job    = array(
			'id'              => $job_id,
			'status'          => 'queued',
			'target_language' => $target_language,
			'post_ids'        => $post_ids,
			'options'         => $options,
			'user_id'         => get_current_user_id(),
			'total'           => count( $post_ids ),
			'processed'       => 0,
			'succeeded'       => 0,
			'failed'          => 0,
			'skipped'         => 0,
			'results'         => array(),
			'created_at'      => time(),
		);
		self::save_job( $job );

		foreach ( $post_ids as $post_id ) {
			self::dispatch( array( $job_id, $post_id ) );
		}

		return array(
			'job_id' => $job_id,
			'total'  => count( $post_ids ),
		);
	}

	private static function dispatch( array $args ): void {
		if ( null !== self::$scheduler_override ) {
			call_user_func( self::$scheduler_override, self::WORKER_HOOK, $args );
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::WORKER_HOOK, $args, 'slytranslate' );
			return;
		}

		wp_schedule_single_event( time(), self::WORKER_HOOK, $args );
	}

	private static function generate_job_id(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return strtolower( (string) wp_generate_password( 12, false, false ) );
		}

		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
	}

	/* ---------------------------------------------------------------
	 * Worker
	 * ------------------------------------------------------------- */

	/**
	 * Process one queued post translation. Hooked on WORKER_HOOK.
	 */
	public static function process_queued_translation( $job_id, $post_id ): void {
		$job_id  = is_string( $job_id ) ? $job_id : '';
		$post_id = absint( $post_id );
		$job     = self::get_job( $job_id );

		if ( null === $job || $post_id < 1 ) {
			return;
		}
		if ( in_array( $job['status'], array( 'cancelled', 'completed' ), true ) ) {
			return;
		}

		// Cron/Action Scheduler runs have no authenticated user — restore the
		// user who started the job so capability checks behave like the
		// original request.
		$user_id = absint( $job['user_id'] ?? 0 );
		if ( $user_id > 0 && function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $user_id );
		}

		$options = is_array( $job['options'] ?? null ) ? $job['options'] : array();
		$run     = static function () use ( $post_id, $job, $options ) {
			return AI_Translate::translate_post(
				$post_id,
				(string) $job['target_language'],
				(string) ( $options['post_status'] ?? '' ),
				! empty( $options['overwrite'] ),
				array_key_exists( 'translate_title', $options ) ? (bool) $options['translate_title'] : true,
				(string) ( $options['additional_prompt'] ?? '' ),
				(string) ( $options['source_language'] ?? '' )
			);
		};

		$model_slug = (string) ( $options['model_slug'] ?? '' );
		$result     = '' !== $model_slug
			? TranslationRuntime::with_model_slug_override( array( 'model_slug' => $model_slug ), $run )
			: $run();

		// Re-read the job: parallel workers or a cancel may have updated it.
		$job = self::get_job( $job_id );
		if ( null === $job ) {
			return;
		}

		$job['processed']++;
		if ( is_wp_error( $result ) ) {
			$skip_codes = array( 'translation_exists', 'same_language' );
			$is_skip    = in_array( $result->get_error_code(), $skip_codes, true );
			$job[ $is_skip ? 'skipped' : 'failed' ]++;
			$job['results'][] = array(
				'source_post_id'     => $post_id,
				'translated_post_id' => 0,
				'status'             => $is_skip ? 'skipped' : 'failed',
				'error'              => $result->get_error_message(),
			);
		} else {
			$job['succeeded']++;
			$job['results'][] = array(
				'source_post_id'     => $post_id,
				'translated_post_id' => absint( $result ),
				'status'             => 'success',
				'error'              => null,
			);
		}

		if ( 'cancelled' !== $job['status'] ) {
			$job['status'] = $job['processed'] >= $job['total'] ? 'completed' : 'running';
		}
		self::save_job( $job );

		TimingLogger::log( 'queue_post_done', array(
			'job'       => $job_id,
			'post'      => $post_id,
			'status'    => $job['status'],
			'processed' => $job['processed'],
			'total'     => $job['total'],
		) );
	}

	/* ---------------------------------------------------------------
	 * Status / cancellation
	 * ------------------------------------------------------------- */

	/**
	 * Public job status for get-progress.
	 *
	 * @return array|null Null when the job is unknown.
	 */
	public static function get_job_status( string $job_id ): ?array {
		$job = self::get_job( $job_id );
		if ( null === $job ) {
			return null;
		}

		return array(
			'job_id'          => $job['id'],
			'status'          => $job['status'],
			'target_language' => $job['target_language'],
			'total'           => $job['total'],
			'processed'       => $job['processed'],
			'succeeded'       => $job['succeeded'],
			'failed'          => $job['failed'],
			'skipped'         => $job['skipped'],
			'results'         => $job['results'],
		);
	}

	/**
	 * Cancel a job: mark it cancelled and unschedule all pending actions.
	 */
	public static function cancel( string $job_id ): bool {
		$job = self::get_job( $job_id );
		if ( null === $job ) {
			return false;
		}

		$job['status'] = 'cancelled';
		self::save_job( $job );

		foreach ( $job['post_ids'] as $post_id ) {
			$args = array( $job['id'], absint( $post_id ) );
			if ( function_exists( 'as_unschedule_action' ) ) {
				as_unschedule_action( self::WORKER_HOOK, $args, 'slytranslate' );
			}
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( self::WORKER_HOOK, $args );
			}
		}

		return true;
	}

	/* ---------------------------------------------------------------
	 * Job storage
	 * ------------------------------------------------------------- */

	private static function get_job( string $job_id ): ?array {
		if ( '' === $job_id ) {
			return null;
		}

		$job = get_option( self::JOB_OPTION_PREFIX . sanitize_key( $job_id ), null );

		return is_array( $job ) ? $job : null;
	}

	private static function save_job( array $job ): void {
		update_option( self::JOB_OPTION_PREFIX . sanitize_key( (string) $job['id'] ), $job, false );
	}
}
