<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\ListTableTranslation;

class BackgroundBarRenderTest extends TestCase {

	public function test_background_bar_assets_enqueue_without_recent_job(): void {
		$enqueued = array();
		$localized = array();

		$this->stubWpFunction(
			'current_user_can',
			static function ( string $capability ): bool {
				return in_array( $capability, array( 'edit_posts', 'edit_pages', 'publish_posts', 'publish_pages', 'manage_options' ), true );
			}
		);

		$this->stubWpFunction(
			'wp_enqueue_script',
			static function ( string $handle, string $src, array $deps, string $ver, bool $in_footer ) use ( &$enqueued ): void {
				$enqueued[] = array(
					'handle'    => $handle,
					'src'       => $src,
					'deps'      => $deps,
					'version'   => $ver,
					'in_footer' => $in_footer,
				);
			}
		);

		$this->stubWpFunction(
			'wp_localize_script',
			static function ( string $handle, string $object_name, array $data ) use ( &$localized ): bool {
				$localized[] = array(
					'handle'      => $handle,
					'object_name' => $object_name,
					'data'        => $data,
				);
				return true;
			}
		);

		$this->stubWpFunctionReturn( 'wp_set_script_translations', true );
		$this->stubWpFunctionReturn( 'wp_create_nonce', 'bg-test-nonce' );

		ListTableTranslation::enqueue_global_background_bar();

		$this->assertCount( 1, $enqueued );
		$this->assertSame( 'slytranslate-background-bar', $enqueued[0]['handle'] );
		$this->assertStringContainsString( 'assets/background-bar.js', $enqueued[0]['src'] );
		$this->assertTrue( $enqueued[0]['in_footer'] );

		$this->assertCount( 1, $localized );
		$this->assertSame( 'SlyTranslateBgBar', $localized[0]['object_name'] );
		$this->assertSame( 'bg-test-nonce', $localized[0]['data']['restNonce'] );
		$this->assertArrayHasKey( 'header', $localized[0]['data']['i18n'] );
	}
}