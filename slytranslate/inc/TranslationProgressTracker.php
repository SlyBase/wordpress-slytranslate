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

	public static function get_progress(): array {
		$transient_key = self::get_transient_key();
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
		$transient_key = self::get_transient_key();
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

		set_transient(
			$transient_key,
			array(
				'phase'         => $phase,
				'current_chunk' => $current_chunk,
				'total_chunks'  => $total_chunks,
				'percent'       => self::calculate_percent( $phase ),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	public static function clear_progress(): void {
		$transient_key = self::get_transient_key();
		if ( null !== $transient_key ) {
			delete_transient( $transient_key );
		}

		self::clear_context();
	}

	/* ---------------------------------------------------------------
	 * In-memory context
	 * ------------------------------------------------------------- */

	/**
	 * Initialise the in-memory context for a new translation job.
	 *
	 * @param bool $translate_title       Whether the post title will be translated.
	 * @param int  $content_total_chunks  Pre-computed number of content chunks.
	 */
	public static function initialize_context( bool $translate_title, int $content_total_chunks ): void {
		$total_steps = ( $translate_title ? 1 : 0 ) + $content_total_chunks + 3;

		self::$context = array(
			'phase'                    => '',
			'total_steps'              => max( 1, $total_steps ),
			'completed_steps'          => 0,
			'content_total_chunks'     => $content_total_chunks,
			'content_completed_chunks' => 0,
		);
	}

	public static function clear_context(): void {
		self::$context = null;
	}

	public static function has_content_progress(): bool {
		return is_array( self::$context ) && absint( self::$context['content_total_chunks'] ?? 0 ) > 0;
	}

	/* ---------------------------------------------------------------
	 * Phase and step tracking
	 * ------------------------------------------------------------- */

	public static function mark_phase( string $phase ): void {
		$current_chunk = 0;
		$total_chunks  = 0;

		if ( is_array( self::$context ) ) {
			self::$context['phase'] = $phase;

			if ( 'content' === $phase ) {
				$current_chunk = absint( self::$context['content_completed_chunks'] ?? 0 );
				$total_chunks  = absint( self::$context['content_total_chunks'] ?? 0 );
			}
		}

		self::set_progress( $phase, $current_chunk, $total_chunks );
	}

	public static function advance_steps( int $steps = 1 ): void {
		if ( ! is_array( self::$context ) || $steps < 1 ) {
			return;
		}

		$total_steps = max( 1, absint( self::$context['total_steps'] ?? 0 ) );
		self::$context['completed_steps'] = min(
			$total_steps,
			absint( self::$context['completed_steps'] ?? 0 ) + $steps
		);
	}

	public static function advance_content_chunk(): int {
		if ( ! is_array( self::$context ) || 'content' !== ( self::$context['phase'] ?? '' ) ) {
			return 0;
		}

		$content_total_chunks = absint( self::$context['content_total_chunks'] ?? 0 );
		if ( $content_total_chunks < 1 ) {
			return 0;
		}

		self::$context['content_completed_chunks'] = min(
			$content_total_chunks,
			absint( self::$context['content_completed_chunks'] ?? 0 ) + 1
		);
		self::advance_steps();
		self::set_progress(
			'content',
			absint( self::$context['content_completed_chunks'] ?? 0 ),
			$content_total_chunks
		);

		return 1;
	}

	public static function rewind_content_chunks( int $completed_chunks ): void {
		if ( $completed_chunks < 1 || ! is_array( self::$context ) || 'content' !== ( self::$context['phase'] ?? '' ) ) {
			return;
		}

		self::$context['content_completed_chunks'] = max(
			0,
			absint( self::$context['content_completed_chunks'] ?? 0 ) - $completed_chunks
		);
		self::$context['completed_steps'] = max(
			0,
			absint( self::$context['completed_steps'] ?? 0 ) - $completed_chunks
		);
		self::set_progress(
			'content',
			absint( self::$context['content_completed_chunks'] ?? 0 ),
			absint( self::$context['content_total_chunks'] ?? 0 )
		);
	}

	public static function synchronize_content_chunks( int $chunk_count, ?int $previous_chunk_count = null ): void {
		if ( ! is_array( self::$context ) || 'content' !== ( self::$context['phase'] ?? '' ) ) {
			return;
		}

		$chunk_count = max( 0, $chunk_count );

		if ( null === $previous_chunk_count ) {
			if ( 0 === absint( self::$context['content_total_chunks'] ?? 0 ) ) {
				self::$context['content_total_chunks'] = $chunk_count;
				self::$context['total_steps']          = max( 1, absint( self::$context['total_steps'] ?? 0 ) + $chunk_count );
			}
			return;
		}

		$delta = $chunk_count - max( 0, $previous_chunk_count );
		if ( 0 === $delta ) {
			return;
		}

		self::$context['content_total_chunks'] = max(
			absint( self::$context['content_completed_chunks'] ?? 0 ),
			absint( self::$context['content_total_chunks'] ?? 0 ) + $delta
		);
		self::$context['total_steps'] = max(
			absint( self::$context['completed_steps'] ?? 0 ) + 1,
			absint( self::$context['total_steps'] ?? 0 ) + $delta
		);
	}

	/* ---------------------------------------------------------------
	 * Private helpers
	 * ------------------------------------------------------------- */

	private static function get_transient_key(): ?string {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return null;
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

		$total_steps     = max( 1, absint( self::$context['total_steps'] ?? 0 ) );
		$completed_steps = min( $total_steps, absint( self::$context['completed_steps'] ?? 0 ) );

		return (int) round( ( $completed_steps / $total_steps ) * 100 );
	}
}
