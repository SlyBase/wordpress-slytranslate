<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\TranslationProgressTracker;

/**
 * Verifies the character-budget progress model: phase budgets registered
 * upfront, advance_units() crediting per phase, complete_phase() topping up,
 * and overall percent computed across all phases.
 */
class TranslationProgressCharBudgetTest extends TestCase {

	/** @var array<string, mixed> Captured transient writes used as the read-back store. */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		$this->transients = array();
		$this->stubWpFunctionReturn( 'get_current_user_id', 42 );
		$this->stubWpFunction( 'get_transient',
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		$this->stubWpFunction( 'set_transient',
			function ( $key, $value, $ttl = 0 ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationProgressTracker::class, 'context', null );
		parent::tearDown();
	}

	public function test_register_phase_units_sums_into_total_budget(): void {
		TranslationProgressTracker::initialize_context( 7 );
		TranslationProgressTracker::register_phase_units( 'title', 100 );
		TranslationProgressTracker::register_phase_units( 'content', 1500 );
		TranslationProgressTracker::register_phase_units( 'excerpt', 200 );
		TranslationProgressTracker::register_phase_units( 'meta', 250 );
		TranslationProgressTracker::register_phase_units( 'saving', 1 );

		$this->assertSame( 100, TranslationProgressTracker::get_phase_budget( 'title' ) );
		$this->assertSame( 1500, TranslationProgressTracker::get_phase_budget( 'content' ) );
		$this->assertSame( 200, TranslationProgressTracker::get_phase_budget( 'excerpt' ) );
		$this->assertSame( 250, TranslationProgressTracker::get_phase_budget( 'meta' ) );
		// 'saving' is below MIN_PHASE_UNITS=32 → bumped to 32.
		$this->assertSame( 32, TranslationProgressTracker::get_phase_budget( 'saving' ) );
	}

	public function test_advance_units_credits_only_active_phase_and_caps_at_budget(): void {
		TranslationProgressTracker::initialize_context();
		TranslationProgressTracker::register_phase_units( 'content', 1000 );

		TranslationProgressTracker::mark_phase( 'content' );
		TranslationProgressTracker::advance_units( 'content', 400 );
		$this->assertSame( 400, TranslationProgressTracker::get_phase_completed( 'content' ) );
		$this->assertSame( 40, TranslationProgressTracker::get_progress( 0 )['percent'] );

		// Over-credit: capped at the registered budget.
		TranslationProgressTracker::advance_units( 'content', 10_000 );
		$this->assertSame( 1000, TranslationProgressTracker::get_phase_completed( 'content' ) );
		$this->assertSame( 100, TranslationProgressTracker::get_progress( 0 )['percent'] );
	}

	public function test_complete_phase_fills_remaining_budget(): void {
		TranslationProgressTracker::initialize_context();
		TranslationProgressTracker::register_phase_units( 'title', 100 );
		TranslationProgressTracker::register_phase_units( 'content', 900 );

		TranslationProgressTracker::mark_phase( 'title' );
		// No advance_units call — title finished without crediting (mirrors
		// the legacy code path that simply trusted phase markers).
		TranslationProgressTracker::complete_phase( 'title' );

		$this->assertSame( 100, TranslationProgressTracker::get_phase_completed( 'title' ) );
		$this->assertSame( 10, TranslationProgressTracker::get_progress( 0 )['percent'] );
	}

	public function test_overall_percent_is_monotonic_across_phases(): void {
		TranslationProgressTracker::initialize_context();
		TranslationProgressTracker::register_phase_units( 'title', 50 );
		TranslationProgressTracker::register_phase_units( 'content', 800 );
		TranslationProgressTracker::register_phase_units( 'excerpt', 50 );
		TranslationProgressTracker::register_phase_units( 'meta', 100 );

		$percents = array();

		TranslationProgressTracker::mark_phase( 'title' );
		TranslationProgressTracker::complete_phase( 'title' );
		$percents[] = TranslationProgressTracker::get_progress( 0 )['percent'];

		TranslationProgressTracker::mark_phase( 'content' );
		foreach ( array( 200, 200, 200, 200 ) as $delta ) {
			TranslationProgressTracker::advance_units( 'content', $delta );
			$percents[] = TranslationProgressTracker::get_progress( 0 )['percent'];
		}

		TranslationProgressTracker::mark_phase( 'excerpt' );
		TranslationProgressTracker::complete_phase( 'excerpt' );
		$percents[] = TranslationProgressTracker::get_progress( 0 )['percent'];

		TranslationProgressTracker::mark_phase( 'meta' );
		TranslationProgressTracker::complete_phase( 'meta' );
		$percents[] = TranslationProgressTracker::get_progress( 0 )['percent'];

		// Monotonic non-decreasing across the whole job.
		for ( $i = 1; $i < count( $percents ); $i++ ) {
			$this->assertGreaterThanOrEqual( $percents[ $i - 1 ], $percents[ $i ] );
		}
		// Final value reaches 100 % once every phase is fully credited.
		$this->assertSame( 100, end( $percents ) );
	}

	public function test_recursive_inner_block_credit_does_not_overshoot_budget(): void {
		// Simulates the recursive block fallback: the outer translate_text()
		// call would credit the full block, then recursive inner calls credit
		// each inner block individually. advance_units() must cap at budget.
		TranslationProgressTracker::initialize_context();
		TranslationProgressTracker::register_phase_units( 'content', 600 );
		TranslationProgressTracker::mark_phase( 'content' );

		// Outer credit (would happen if outer translation succeeded).
		TranslationProgressTracker::advance_units( 'content', 600 );
		// Recursive fallback also credits each inner block (would otherwise
		// double-count and push past 100 %).
		TranslationProgressTracker::advance_units( 'content', 200 );
		TranslationProgressTracker::advance_units( 'content', 200 );

		$this->assertSame( 600, TranslationProgressTracker::get_phase_completed( 'content' ) );
		$this->assertSame( 100, TranslationProgressTracker::get_progress( 0 )['percent'] );
	}

	public function test_get_progress_returns_phase_budget_in_chunk_fields(): void {
		// Existing JS uses current_chunk/total_chunks to detect "phase nearly
		// done" for the "Processing translated content..." label. With
		// char-units those fields now mirror the active phase's char budget.
		TranslationProgressTracker::initialize_context();
		TranslationProgressTracker::register_phase_units( 'content', 1000 );
		TranslationProgressTracker::mark_phase( 'content' );
		TranslationProgressTracker::advance_units( 'content', 250 );

		$payload = TranslationProgressTracker::get_progress( 0 );

		$this->assertSame( 'content', $payload['phase'] );
		$this->assertSame( 1000, $payload['total_chunks'] );
		$this->assertSame( 250, $payload['current_chunk'] );
	}
}
