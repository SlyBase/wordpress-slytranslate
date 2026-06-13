<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\AutoPublishTranslationService;
use SlyTranslate\PolylangAdapter;
use SlyTranslate\TranslationQueue;

class AutoPublishTranslationServiceTest extends TestCase {

	/** @var array<int, array{0:string, 1:array}> */
	private array $dispatched = array();

	/** @var array<string, mixed> */
	private array $stored_options = array();

	protected function setUp(): void {
		parent::setUp();

		$this->dispatched     = array();
		$this->stored_options = array( 'slytranslate_new_post' => '1' );

		TranslationQueue::set_scheduler_for_testing( function ( string $hook, array $args ): void {
			$this->dispatched[] = array( $hook, $args );
		} );

		$this->setStaticProperty( AI_Translate::class, 'adapter', new AutoPublishPolylangAdapterDouble() );

		$this->stubWpFunction( 'get_option', function ( $option, $default = false ) {
			return $this->stored_options[ $option ] ?? $default;
		} );
		$this->stubWpFunction( 'update_option', function ( $option, $value ) {
			$this->stored_options[ $option ] = $value;
			return true;
		} );
		$this->stubWpFunctionReturn( 'post_type_exists', true );
		$this->stubWpFunctionReturn( 'get_post_meta', '' );
		$this->stubWpFunctionReturn( 'pll_default_language', 'en' );
	}

	protected function tearDown(): void {
		TranslationQueue::set_scheduler_for_testing( null );
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

	private function publish_post( int $id = 5 ): \WP_Post {
		return new \WP_Post( array( 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish' ) );
	}

	public function test_publish_queues_drafts_for_missing_languages_only(): void {
		AutoPublishTranslationService::handle_transition_post_status( 'publish', 'draft', $this->publish_post() );

		// Languages: en (source), de (existing translation), fr (missing) → one job for fr.
		$this->assertCount( 1, $this->dispatched );
		$this->assertSame( TranslationQueue::WORKER_HOOK, $this->dispatched[0][0] );
		$this->assertSame( 5, $this->dispatched[0][1][1] );

		$job_id = $this->dispatched[0][1][0];
		$status = TranslationQueue::get_job_status( $job_id );
		$this->assertSame( 'fr', $status['target_language'] );

		// Translations are always queued as drafts — never auto-published.
		$job = $this->stored_options[ 'slytranslate_queue_job_' . $job_id ];
		$this->assertSame( 'draft', $job['options']['post_status'] );
	}

	public function test_disabled_option_queues_nothing(): void {
		$this->stored_options['slytranslate_new_post'] = '0';

		AutoPublishTranslationService::handle_transition_post_status( 'publish', 'draft', $this->publish_post() );

		$this->assertSame( array(), $this->dispatched );
	}

	public function test_non_publish_transitions_queue_nothing(): void {
		AutoPublishTranslationService::handle_transition_post_status( 'draft', 'publish', $this->publish_post() );
		AutoPublishTranslationService::handle_transition_post_status( 'publish', 'publish', $this->publish_post() );

		$this->assertSame( array(), $this->dispatched );
	}

	public function test_generated_translations_do_not_retrigger_translation(): void {
		$this->stubWpFunction( 'get_post_meta', static function ( $post_id, $key = '' ) {
			return AutoPublishTranslationService::GENERATED_META_KEY === $key ? '1' : '';
		} );

		AutoPublishTranslationService::handle_transition_post_status( 'publish', 'draft', $this->publish_post() );

		$this->assertSame( array(), $this->dispatched );
	}

	public function test_secondary_language_posts_queue_nothing(): void {
		// Post language en, but the site default is de → not a source post.
		$this->stubWpFunctionReturn( 'pll_default_language', 'de' );

		AutoPublishTranslationService::handle_transition_post_status( 'publish', 'draft', $this->publish_post() );

		$this->assertSame( array(), $this->dispatched );
	}

	public function test_untranslatable_post_type_queues_nothing(): void {
		$this->stubWpFunctionReturn( 'post_type_exists', false );

		AutoPublishTranslationService::handle_transition_post_status( 'publish', 'draft', $this->publish_post() );

		$this->assertSame( array(), $this->dispatched );
	}
}

class AutoPublishPolylangAdapterDouble extends PolylangAdapter {
	public function is_available(): bool {
		return true;
	}

	public function get_languages(): array {
		return array( 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français' );
	}

	public function get_post_language( int $post_id ): ?string {
		return 'en';
	}

	public function get_post_translations( int $post_id ): array {
		return array( 'en' => $post_id, 'de' => 9 );
	}
}
