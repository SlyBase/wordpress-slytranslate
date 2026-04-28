<?php

namespace SlyTranslate;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight timing instrumentation for the translation pipeline.
 *
 * All log lines share the prefix "[SlyTranslate timing]" and are emitted via
 * error_log() only when WP_DEBUG is true. The logger also keeps per-job
 * counters (ai_calls / retries / fallbacks) that PostTranslationService reads
 * to summarise the job.
 *
 * Stoppuhr usage:
 *
 *     $timer = TimingLogger::start();
 *     // ...work...
 *     $duration_ms = TimingLogger::stop( $timer );
 *
 * The timer value is just a microtime float; if logging is disabled the helper
 * still returns a usable duration so call sites stay symmetric.
 */
class TimingLogger {

	private const PREFIX = '[SlyTranslate timing]';

	/** @var array<string, int> */
	private static $counters = array(
		'ai_calls'  => 0,
		'retries'   => 0,
		'fallbacks' => 0,
	);

	/* ---------------------------------------------------------------
	 * Activation
	 * ------------------------------------------------------------- */

	public static function is_enabled(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/* ---------------------------------------------------------------
	 * Stoppuhr
	 * ------------------------------------------------------------- */

	public static function start(): float {
		return microtime( true );
	}

	public static function stop( float $started_at ): int {
		return (int) round( ( microtime( true ) - $started_at ) * 1000 );
	}

	/* ---------------------------------------------------------------
	 * Counters
	 * ------------------------------------------------------------- */

	public static function reset_counters(): void {
		self::$counters = array(
			'ai_calls'  => 0,
			'retries'   => 0,
			'fallbacks' => 0,
		);
	}

	public static function increment( string $name, int $by = 1 ): void {
		if ( ! isset( self::$counters[ $name ] ) ) {
			self::$counters[ $name ] = 0;
		}
		self::$counters[ $name ] += $by;
	}

	/** @return array<string, int> */
	public static function get_counters(): array {
		return self::$counters;
	}

	/* ---------------------------------------------------------------
	 * Logging
	 * ------------------------------------------------------------- */

	/**
	 * Emit a single timing event.
	 *
	 * @param string               $event    Short event name, e.g. "job_start", "ai_call".
	 * @param array<string, mixed> $context  Flat key=value context payload.
	 */
	public static function log( string $event, array $context = array() ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$parts = array( self::PREFIX, $event );
		foreach ( $context as $key => $value ) {
			$parts[] = $key . '=' . self::format_value( $value );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only timing trace.
		error_log( implode( ' ', $parts ) );
	}

	private static function format_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );
			return is_string( $encoded ) ? $encoded : '';
		}
		$value = (string) $value;
		// Replace whitespace so the line stays grep-friendly.
		$value = preg_replace( '/\s+/', '_', $value ) ?? $value;
		return $value;
	}
}
