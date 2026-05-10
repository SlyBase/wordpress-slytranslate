<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\ListTableTranslation;
use SlyTranslate\TranslatePressAdapter;

class ListTableTranslationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

	public function test_add_row_actions_keeps_translatepress_targets_available_for_overwrite(): void {
		$adapter = new class() extends TranslatePressAdapter {
			public function is_available(): bool {
				return true;
			}

			public function get_languages(): array {
				return array(
					'de' => 'Deutsch',
				);
			}

			public function get_post_language( int $post_id ): ?string {
				return 'en';
			}

			public function get_post_translations( int $post_id ): array {
				return array(
					'en' => $post_id,
					'de' => $post_id,
				);
			}
		};

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubWpFunctionReturn( 'current_user_can', true );
		$this->stubWpFunctionReturn( 'get_post_status', 'publish' );
		$this->stubWpFunction( 'wp_json_encode', static fn( $value ): string => (string) json_encode( $value ) );

		$post = new \WP_Post(
			array(
				'ID'         => 42,
				'post_title' => 'Testseite',
			)
		);

		$actions = ListTableTranslation::add_row_actions( array(), $post );

		$this->assertArrayHasKey( 'slytranslate', $actions );
		$this->assertStringContainsString( 'class="slytranslate-ajax-translate"', $actions['slytranslate'] );
		$this->assertStringContainsString( 'data-source-lang="en"', $actions['slytranslate'] );
		$this->assertStringContainsString( 'data-all-langs="[{&quot;code&quot;:&quot;en&quot;,&quot;name&quot;:&quot;EN&quot;},{&quot;code&quot;:&quot;de&quot;,&quot;name&quot;:&quot;Deutsch&quot;}]"', $actions['slytranslate'] );
		$this->assertStringContainsString( 'data-existing-langs="[&quot;de&quot;]"', $actions['slytranslate'] );
		$this->assertStringContainsString( 'data-langs="[{&quot;code&quot;:&quot;de&quot;,&quot;name&quot;:&quot;Deutsch&quot;}]"', $actions['slytranslate'] );
	}
}