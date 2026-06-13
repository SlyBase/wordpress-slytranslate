<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\TranslationQueue;

class TranslationQueueTest extends TestCase {

	/** @var array<string, mixed> */
	private array $stored_options = array();

	protected function setUp(): void {
		parent::setUp();
		$this->stored_options = array();
		$this->stubWpFunction( 'get_option', function ( $option, $default = false ) {
			return $this->stored_options[ $option ] ?? $default;
		} );
		$this->stubWpFunction( 'update_option', function ( $option, $value ) {
			$this->stored_options[ $option ] = $value;
			return true;
		} );
	}

	protected function tearDown(): void {
		TranslationQueue::set_scheduler_for_testing( null );
		parent::tearDown();
	}

	public function test_enqueue_bulk_dispatches_one_action_per_post(): void {
		$dispatched = array();
		TranslationQueue::set_scheduler_for_testing( static function ( string $hook, array $args ) use ( &$dispatched ): void {
			$dispatched[] = array( $hook, $args );
		} );
		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );

		$result = TranslationQueue::enqueue_bulk( array( 10, 20, 10, 0 ), 'de', array( 'post_status' => 'draft' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertNotSame( '', $result['job_id'] );

		$this->assertCount( 2, $dispatched );
		$this->assertSame( TranslationQueue::WORKER_HOOK, $dispatched[0][0] );
		$this->assertSame( array( $result['job_id'], 10 ), $dispatched[0][1] );
		$this->assertSame( array( $result['job_id'], 20 ), $dispatched[1][1] );

		$status = TranslationQueue::get_job_status( $result['job_id'] );
		$this->assertSame( 'queued', $status['status'] );
		$this->assertSame( 'de', $status['target_language'] );
		$this->assertSame( 0, $status['processed'] );
	}

	public function test_enqueue_bulk_rejects_empty_input(): void {
		TranslationQueue::set_scheduler_for_testing( static function (): void {} );

		$result = TranslationQueue::enqueue_bulk( array( 0 ), 'de' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'queue_empty', $result->get_error_code() );
	}

	public function test_worker_records_failure_and_completes_job(): void {
		TranslationQueue::set_scheduler_for_testing( static function (): void {} );
		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );
		// No adapter is active in the test environment, so translate_post fails
		// with no_translation_plugin — exactly what this test needs.
		$this->stubWpFunction( 'apply_filters', static fn( $tag, $value ) => $value );

		$restored_users = array();
		$this->stubWpFunction( 'wp_set_current_user', static function ( $user_id ) use ( &$restored_users ) {
			$restored_users[] = $user_id;
			return null;
		} );

		$result = TranslationQueue::enqueue_bulk( array( 10 ), 'de' );
		TranslationQueue::process_queued_translation( $result['job_id'], 10 );

		$this->assertSame( array( 17 ), $restored_users );

		$status = TranslationQueue::get_job_status( $result['job_id'] );
		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( 1, $status['processed'] );
		$this->assertSame( 1, $status['failed'] );
		$this->assertSame( 0, $status['succeeded'] );
		$this->assertSame( 'failed', $status['results'][0]['status'] );
	}

	public function test_worker_skips_cancelled_jobs(): void {
		TranslationQueue::set_scheduler_for_testing( static function (): void {} );

		$result = TranslationQueue::enqueue_bulk( array( 10 ), 'de' );
		TranslationQueue::cancel( $result['job_id'] );
		TranslationQueue::process_queued_translation( $result['job_id'], 10 );

		$status = TranslationQueue::get_job_status( $result['job_id'] );
		$this->assertSame( 'cancelled', $status['status'] );
		$this->assertSame( 0, $status['processed'] );
	}

	public function test_cancel_unschedules_pending_cron_events(): void {
		TranslationQueue::set_scheduler_for_testing( static function (): void {} );

		$cleared = array();
		$this->stubWpFunction( 'wp_clear_scheduled_hook', static function ( $hook, $args = array() ) use ( &$cleared ) {
			$cleared[] = array( $hook, $args );
			return 1;
		} );

		$result = TranslationQueue::enqueue_bulk( array( 10, 20 ), 'de' );

		$this->assertTrue( TranslationQueue::cancel( $result['job_id'] ) );
		$this->assertSame(
			array(
				array( TranslationQueue::WORKER_HOOK, array( $result['job_id'], 10 ) ),
				array( TranslationQueue::WORKER_HOOK, array( $result['job_id'], 20 ) ),
			),
			$cleared
		);
	}

	public function test_cancel_returns_false_for_unknown_job(): void {
		$this->assertFalse( TranslationQueue::cancel( 'unknown' ) );
		$this->assertNull( TranslationQueue::get_job_status( 'unknown' ) );
	}

	public function test_transport_detection_prefers_test_override_then_wp_cron(): void {
		TranslationQueue::set_scheduler_for_testing( static function (): void {} );
		$this->assertSame( 'test', TranslationQueue::get_transport() );

		TranslationQueue::set_scheduler_for_testing( null );
		// Action Scheduler functions are not defined in the test environment,
		// wp_schedule_single_event is stubbed → WP-Cron transport.
		$this->assertSame( 'wp_cron', TranslationQueue::get_transport() );
		$this->assertTrue( TranslationQueue::is_available() );
	}
}
