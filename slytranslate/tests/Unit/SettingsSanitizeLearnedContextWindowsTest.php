<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\Settings;

class SettingsSanitizeLearnedContextWindowsTest extends TestCase {

	public function test_sanitize_learned_context_windows_sanitizes_and_bounds_values(): void {
		$input = array(
			'Model-One'    => '2048',
			'second_model' => '0',
			'third-model'  => '200000',
			'!invalid!'    => '44',
		);

		$result = Settings::sanitize_learned_context_windows( $input );

		$this->assertSame(
			array(
				'model-one'   => 2048,
				'third-model' => 131072,
				'invalid'     => 44,
			),
			$result
		);
	}

	public function test_sanitize_learned_context_windows_returns_empty_array_for_non_array_values(): void {
		$this->assertSame( array(), Settings::sanitize_learned_context_windows( 'invalid' ) );
	}
}
