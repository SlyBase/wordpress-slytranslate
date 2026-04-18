<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;

class AbilityInputValidationTest extends TestCase {

	public function test_execute_translate_text_rejects_missing_text(): void {
		$result = AI_Translate::execute_translate_text(
			array(
				'text'            => array( 'not-a-string' ),
				'source_language' => 'en',
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_text', $result->get_error_code() );
	}

	public function test_execute_translate_content_rejects_invalid_post_id(): void {
		$result = AI_Translate::execute_translate_content(
			array(
				'post_id'         => 'abc',
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_post_id', $result->get_error_code() );
	}

	public function test_execute_translate_posts_rejects_missing_target_language(): void {
		$result = AI_Translate::execute_translate_posts(
			array(
				'post_ids' => array( 1, 2, 3 ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_target_language', $result->get_error_code() );
	}
}