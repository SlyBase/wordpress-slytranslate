<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Manages cancel flags and in-progress state for content translation jobs.
 *
 * All progress is keyed to the current WordPress user via transients so that
 * multiple users can translate in parallel without interfering with each other.
 */
class TranslationProgressTracker {

	/**
	 * Lifetime of the progress transient. Long enough to outlast a slow page
	 * translation (large hero pages with deep block trees can take 15+ minutes
	 * on small models) so the polling endpoint never returns stale defaults
	 * mid-job.
	 */
	private const PROGRESS_TTL_SECONDS = 1800;

	/** Known progress phases, in execution order. */
	public const PHASES = array( 'title', 'content', 'excerpt', 'meta', 'saving' );

	/**
	 * Minimum budget per registered phase. Avoids zero-weight phases that
	 * would otherwise contribute nothing to the percentage even though they
	 * take observable wall-clock time (e.g. saving, empty excerpt).
	 */
	private const MIN_PHASE_UNITS = 32;

	/** In-memory context for the current translate_post() call. */
	private static $context = null;

	/* ---------------------------------------------------------------
	 * Cancel flag (transient-backed)
	 * ------------------------------------------------------------- */

	public static function is_cancelled(): bool {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return false;
		}
		return (bool) get_transient( 'ai_translate_cancel_' . $user_id );
	}

	public static function set_cancelled(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			set_transient( 'ai_translate_cancel_' . $user_id, 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	public static function clear_cancelled(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			delete_transient( 'ai_translate_cancel_' . $user_id );
		}
	}

	/* ---------------------------------------------------------------
	 * Progress read / write (transient-backed)
	 * ------------------------------------------------------------- */

	/**
	 * Whether the current user has started a translation job recently enough
	 * that the background-task bar should be rendered on the admin screen.
	 * Returns true if the transient set by initialize_context() is still alive.
	 */
	public static function user_has_recent_job(): bool {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return false;
		}
		return false !== get_transient( 'ai_translate_bg_user_' . $user_id );
	}

	public static function get_progress( int $post_id = 0 ): array {
		$transient_key = self::get_transient_key( $post_id );
		if ( null === $transient_key ) {
			return self::default_progress();
		}

		$progress = get_transient( $transient_key );
		if ( ! is_array( $progress ) ) {
			return self::default_progress();
		}

		return array(
			'phase'         => isset( $progress['phase'] ) && is_string( $progress['phase'] ) ? $progress['phase'] : '',
			'current_chunk' => absint( $progress['current_chunk'] ?? 0 ),
			'total_chunks'  => absint( $progress['total_chunks'] ?? 0 ),
			'percent'       => min( 100, max( 0, absint( $progress['percent'] ?? 0 ) ) ),
		);
	}

	public static function set_progress( string $phase, int $current_chunk = 0, int $total_chunks = 0 ): void {
		$post_id       = is_array( self::$context ) ? absint( self::$context['post_id'] ?? 0 ) : 0;
		$transient_key = self::get_transient_key( $post_id );
		if ( null === $transient_key ) {
			return;
		}

		$current_chunk = max( 0, $current_chunk );
		$total_chunks  = max( 0, $total_chunks );

		if ( $total_chunks > 0 ) {
			$current_chunk = min( $current_chunk, $total_chunks );
		} else {
			$current_chunk = 0;
		}

		// If no chunk hint was passed, fall back to the current phase's
		// completed/budget character counts so the existing JS label
		// ("Processing translated content...") still triggers when the phase
		// is fully consumed.
		if ( 0 === $total_chunks && is_array( self::$context ) ) {
			$total_chunks  = self::phase_budget( $phase );
			$current_chunk = self::phase_completed( $phase );
			if ( $total_chunks > 0 ) {
				$current_chunk = min( $current_chunk, $total_chunks );
			}
		}

		set_transient(
			$transient_key,
			array(
				'phase'         => $phase,
				'current_chunk' => $current_chunk,
				'total_chunks'  => $total_chunks,
				'percent'       => self::calculate_percent( $phase ),
			),
			self::PROGRESS_TTL_SECONDS
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[SlyTranslate progress] post=%d phase=%s chunk=%d/%d percent=%d%% units=%d/%d',
				$post_id,
				$phase,
				$current_chunk,
				$total_chunks,
				self::calculate_percent( $phase ),
				is_array( self::$context ) ? absint( self::$context['completed_units'] ?? 0 ) : 0,
				is_array( self::$context ) ? absint( self::$context['total_units'] ?? 0 ) : 0
			) );
		}
	}

	public static function clear_progress( int $post_id = 0 ): void {
		$ctx_post_id = is_array( self::$context ) ? absint( self::$context['post_id'] ?? 0 ) : 0;
		if ( $post_id < 1 && $ctx_post_id > 0 ) {
			$post_id = $ctx_post_id;
		}

		$transient_key = self::get_transient_key( $post_id );
		if ( null !== $transient_key ) {
			delete_transient( $transient_key );
		}

		self::clear_context();
	}

	/* ---------------------------------------------------------------
	 * In-memory context
	 * ------------------------------------------------------------- */

	/**
	 * Initialise the in-memory context for a new translation job. Phase
	 * budgets are registered later via register_phase_units() once the source
	 * lengths are known.
	 */
	public static function initialize_context( int $post_id = 0 ): void {
		self::$context = array(
			'phase'           => '',
			'post_id'         => max( 0, $post_id ),
			'phase_budgets'   => array(),
			'phase_completed' => array(),
			'total_units'     => 0,
			'completed_units' => 0,
		);

		// Mark that this user has a recent job so the background bar can be
		// conditionally rendered on subsequent admin screens.
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			set_transient( 'ai_translate_bg_user_' . $user_id, 1, self::PROGRESS_TTL_SECONDS );
		}
	}

	public static function clear_context(): void {
		self::$context = null;
	}

	/**
	 * Register (or extend) the budget for a phase, expressed as the number of
	 * source characters that will be translated in that phase. Each call adds
	 * to any previously registered budget for the same phase, which lets
	 * recursive code paths announce additional work mid-flight.
	 */
	public static function register_phase_units( string $phase, int $units ): void {
		if ( ! is_array( self::$context ) || $units < 1 ) {
			return;
		}

		$units = max( $units, self::MIN_PHASE_UNITS );

		$current  = absint( self::$context['phase_budgets'][ $phase ] ?? 0 );
		$new      = $current + $units;

		self::$context['phase_budgets'][ $phase ] = $new;
		self::$context['total_units']             = absint( self::$context['total_units'] ?? 0 ) + $units;
	}

	/**
	 * Credit translated source characters to a phase. Capped at the phase
	 * budget so wildly long translations cannot push percentages > 100.
	 */
	public static function advance_units( string $phase, int $units ): void {
		if ( ! is_array( self::$context ) || $units < 1 ) {
			return;
		}

		$budget    = absint( self::$context['phase_budgets'][ $phase ] ?? 0 );
		$completed = absint( self::$context['phase_completed'][ $phase ] ?? 0 );

		if ( $budget < 1 ) {
			// Phase wasn't pre-registered; register on the fly so the work
			// counts toward overall progress instead of being silently dropped.
			self::register_phase_units( $phase, $units );
			$budget = absint( self::$context['phase_budgets'][ $phase ] ?? 0 );
		}

		$delta = min( $units, max( 0, $budget - $completed ) );
		if ( $delta < 1 ) {
			return;
		}

		self::$context['phase_completed'][ $phase ] = $completed + $delta;
		self::$context['completed_units']           = absint( self::$context['completed_units'] ?? 0 ) + $delta;

		if ( ( self::$context['phase'] ?? '' ) === $phase ) {
			self::set_progress( $phase );
		}
	}

	/**
	 * Mark a phase as fully done — fills any remaining budget so the bar
	 * never freezes when individual sub-paths skipped their advance_units()
	 * call (e.g. recursive block fallback or oversized chunks that returned
	 * early on validation drift).
	 */
	public static function complete_phase( string $phase ): void {
		if ( ! is_array( self::$context ) ) {
			return;
		}

		$budget    = absint( self::$context['phase_budgets'][ $phase ] ?? 0 );
		$completed = absint( self::$context['phase_completed'][ $phase ] ?? 0 );

		if ( $budget > $completed ) {
			self::advance_units( $phase, $budget - $completed );
		}
	}

	public static function get_phase_budget( string $phase ): int {
		return self::phase_budget( $phase );
	}

	public static function get_phase_completed( string $phase ): int {
		return self::phase_completed( $phase );
	}

	/**
	 * Return the phase that mark_phase() most recently activated, or '' when
	 * no context is currently initialised. Used by TranslationRuntime to route
	 * per-chunk progress credit to whichever phase is running.
	 */
	public static function current_phase(): string {
		if ( ! is_array( self::$context ) ) {
			return '';
		}
		$phase = self::$context['phase'] ?? '';
		return is_string( $phase ) ? $phase : '';
	}

	public static function has_content_progress(): bool {
		return self::phase_budget( 'content' ) > 0;
	}

	/* ---------------------------------------------------------------
	 * Phase tracking
	 * ------------------------------------------------------------- */

	public static function mark_phase( string $phase ): void {
		if ( is_array( self::$context ) ) {
			self::$context['phase'] = $phase;
		}

		self::set_progress( $phase );
	}

	/* ---------------------------------------------------------------
	 * Private helpers
	 * ------------------------------------------------------------- */

	private static function get_transient_key( int $post_id = 0 ): ?string {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return null;
		}
		if ( $post_id > 0 ) {
			return 'ai_translate_progress_' . $user_id . '_' . $post_id;
		}
		return 'ai_translate_progress_' . $user_id;
	}

	private static function default_progress(): array {
		return array(
			'phase'         => '',
			'current_chunk' => 0,
			'total_chunks'  => 0,
			'percent'       => 0,
		);
	}

	private static function calculate_percent( string $phase ): int {
		if ( 'done' === $phase ) {
			return 100;
		}

		if ( ! is_array( self::$context ) ) {
			return 0;
		}

		$total_units     = max( 1, absint( self::$context['total_units'] ?? 0 ) );
		$completed_units = min( $total_units, absint( self::$context['completed_units'] ?? 0 ) );

		return (int) round( ( $completed_units / $total_units ) * 100 );
	}

	private static function phase_budget( string $phase ): int {
		if ( ! is_array( self::$context ) ) {
			return 0;
		}

		return absint( self::$context['phase_budgets'][ $phase ] ?? 0 );
	}

	private static function phase_completed( string $phase ): int {
		if ( ! is_array( self::$context ) ) {
			return 0;
		}

		return absint( self::$context['phase_completed'][ $phase ] ?? 0 );
	}
}
