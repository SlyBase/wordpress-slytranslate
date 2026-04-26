<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\ListTableTranslation;

class ListTableKeepaliveRenderTest extends TestCase {

	public function test_list_table_assets_enqueue_script_with_bootstrap_data(): void {
		$enqueued = array();
		$localized = array();

		$this->stubWpFunction(
			'current_user_can',
			static function ( string $capability ): bool {
				return 'edit_posts' === $capability;
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
		$this->stubWpFunctionReturn( 'get_current_user_id', 123 );
		$this->stubWpFunctionReturn( 'get_user_meta', 'Bitte locker formulieren.' );
		$this->stubWpFunctionReturn( 'wp_create_nonce', 'rest-test-nonce' );

		ListTableTranslation::enqueue_list_table_assets( 'edit.php' );

		$this->assertCount( 1, $enqueued );
		$this->assertSame( 'slytranslate-list-table-dialog', $enqueued[0]['handle'] );
		$this->assertStringContainsString( 'assets/list-table-dialog.js', $enqueued[0]['src'] );
		$this->assertTrue( $enqueued[0]['in_footer'] );

		$this->assertCount( 1, $localized );
		$this->assertSame( 'SlyTranslateListTable', $localized[0]['object_name'] );
		$this->assertSame( 'rest-test-nonce', $localized[0]['data']['restNonce'] );
		$this->assertSame( 'Bitte locker formulieren.', $localized[0]['data']['lastAdditionalPrompt'] );
		$this->assertArrayHasKey( 'pickerTitle', $localized[0]['data']['i18n'] );
	}
}