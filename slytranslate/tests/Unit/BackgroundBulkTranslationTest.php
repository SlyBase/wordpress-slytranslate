<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\PolylangAdapter;
use SlyTranslate\TranslationQueue;

/**
 * Background mode of translate-content-bulk plus the queue extensions of
 * cancel-translation and get-progress.
 */
class BackgroundBulkTranslationTest extends TestCase {

	/** @var array<string, mixed> */
	private array $stored_options = array();

	/** @var array<int, array{0:string, 1:array}> */
	private array $dispatched = array();

	protected function setUp(): void {
		parent::setUp();

		$this->stored_options = array();
		$this->dispatched     = array();

		TranslationQueue::set_scheduler_for_testing( function ( string $hook, array $args ): void {
			$this->dispatched[] = array( $hook, $args );
		} );

		$this->setStaticProperty( AI_Translate::class, 'adapter', new BackgroundBulkPolylangAdapterDouble() );

		$this->stubWpFunction( 'get_option', function ( $option, $default = false ) {
			return $this->stored_options[ $option ] ?? $default;
		} );
		$this->stubWpFunction( 'update_option', function ( $option, $value ) {
			$this->stored_options[ $option ] = $value;
			return true;
		} );
		$this->stubWpFunctionReturn( 'current_user_can', true );
		$this->stubWpFunctionReturn( 'get_current_user_id', 17 );
	}

	protected function tearDown(): void {
		TranslationQueue::set_scheduler_for_testing( null );
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

	public function test_background_bulk_returns_job_id_without_running_translations(): void {
		$this->stubWpFunction( 'wp_ai_client_prompt', static function () {
			throw new \RuntimeException( 'Background mode must not translate synchronously.' );
		} );

		$result = AI_Translate::execute_translate_posts( array(
			'post_ids'        => array( 10, 20 ),
			'target_language' => 'de',
			'background'      => true,
			'post_status'     => 'draft',
		) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['queued'] );
		$this->assertSame( 2, $result['total'] );
		$this->assertNotSame( '', $result['job_id'] );
		$this->assertSame( array(), $result['results'] );

		$this->assertCount( 2, $this->dispatched );
		$this->assertSame( array( $result['job_id'], 10 ), $this->dispatched[0][1] );

		$job = $this->stored_options[ 'slytranslate_queue_job_' . $result['job_id'] ];
		$this->assertSame( 'draft', $job['options']['post_status'] );
		$this->assertSame( 17, $job['user_id'] );
	}

	public function test_get_progress_returns_job_status_for_known_job_id(): void {
		$result = AI_Translate::execute_translate_posts( array(
			'post_ids'        => array( 10 ),
			'target_language' => 'de',
			'background'      => true,
		) );

		$progress = AI_Translate::execute_get_progress( array( 'job_id' => $result['job_id'] ) );

		$this->assertSame( $result['job_id'], $progress['job_id'] );
		$this->assertSame( 'queued', $progress['status'] );
		$this->assertSame( 1, $progress['total'] );
		$this->assertSame( 0, $progress['processed'] );
	}

	public function test_get_progress_falls_back_to_post_progress_for_unknown_job_id(): void {
		$progress = AI_Translate::execute_get_progress( array( 'job_id' => 'unknown', 'post_id' => 0 ) );

		$this->assertArrayHasKey( 'phase', $progress );
		$this->assertArrayNotHasKey( 'job_id', $progress );
	}

	public function test_cancel_translation_cancels_queue_job(): void {
		$result = AI_Translate::execute_translate_posts( array(
			'post_ids'        => array( 10 ),
			'target_language' => 'de',
			'background'      => true,
		) );

		$cancelled = AI_Translate::execute_cancel_translation( array( 'job_id' => $result['job_id'] ) );

		$this->assertTrue( $cancelled['cancelled'] );
		$this->assertTrue( $cancelled['job_cancelled'] );
		$this->assertSame( 'cancelled', TranslationQueue::get_job_status( $result['job_id'] )['status'] );
	}

	public function test_cancel_translation_reports_unknown_job(): void {
		$cancelled = AI_Translate::execute_cancel_translation( array( 'job_id' => 'unknown' ) );

		$this->assertTrue( $cancelled['cancelled'] );
		$this->assertFalse( $cancelled['job_cancelled'] );
	}
}

class BackgroundBulkPolylangAdapterDouble extends PolylangAdapter {
	public function is_available(): bool {
		return true;
	}

	public function get_languages(): array {
		return array( 'en' => 'English', 'de' => 'Deutsch' );
	}

	public function get_post_language( int $post_id ): ?string {
		return 'en';
	}

	public function get_post_translations( int $post_id ): array {
		return array( 'en' => $post_id );
	}
}
