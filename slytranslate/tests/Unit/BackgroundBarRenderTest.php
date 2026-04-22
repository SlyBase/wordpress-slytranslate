<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\ListTableTranslation;

class BackgroundBarRenderTest extends TestCase {

	public function test_background_bar_bridge_renders_without_recent_job(): void {
		$this->stubWpFunction(
			'current_user_can',
			static function ( string $capability ): bool {
				return in_array( $capability, array( 'edit_posts', 'edit_pages', 'publish_posts', 'publish_pages', 'manage_options' ), true );
			}
		);

		ob_start();
		ListTableTranslation::enqueue_global_background_bar();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'window.SlyTranslateBg', $output );
		$this->assertStringContainsString( 'slytranslate_bg_tasks_v1', $output );
	}
}