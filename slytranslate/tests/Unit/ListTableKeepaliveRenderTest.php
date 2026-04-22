<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\ListTableTranslation;

class ListTableKeepaliveRenderTest extends TestCase {

	public function test_list_table_script_marks_translation_requests_as_keepalive(): void {
		$this->stubWpFunctionReturn( 'get_current_user_id', 123 );
		$this->stubWpFunctionReturn( 'get_user_meta', '' );

		ob_start();
		ListTableTranslation::enqueue_list_table_script();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'keepalive: !!options.keepalive', $output );
		$this->assertSame( 2, substr_count( $output, 'keepalive: true' ) );
	}
}