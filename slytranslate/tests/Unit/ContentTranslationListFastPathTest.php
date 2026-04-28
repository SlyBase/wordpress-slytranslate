<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\ContentTranslator;
use SlyTranslate\TranslationProgressTracker;

class ContentTranslationListFastPathTest extends TestCase {

	protected function tearDown(): void {
		$this->setStaticProperty( TranslationProgressTracker::class, 'context', null );
		parent::tearDown();
	}

	public function test_single_list_wrapper_uses_recursive_fast_path_before_group_translation(): void {
		$calls = array();

		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );
		$this->stubWpFunction( 'serialize_blocks',
			static function ( array $blocks ): string {
				$block = $blocks[0] ?? array();
				$name  = $block['blockName'] ?? '';

				if ( 'core/list-item' === $name ) {
					return "<!-- wp:list-item -->\n<li>First item</li>\n<!-- /wp:list-item -->";
				}

				if ( 'core/list' === $name ) {
					return "<!-- wp:list -->\n<ul>\n<!-- wp:list-item -->\n<li>First item</li>\n<!-- /wp:list-item -->\n</ul>\n<!-- /wp:list -->";
				}

				return '';
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) use ( &$calls ) {
				$calls[] = $text;

				return new class( $text ) {
					private string $text;

					public function __construct( string $text ) {
						$this->text = $text;
					}

					public function using_system_instruction( string $prompt ) {
						return $this;
					}

					public function using_temperature( float $temperature ) {
						return $this;
					}

					public function using_model_preference( string $model_slug ) {
						return $this;
					}

					public function using_max_tokens( int $max_tokens ) {
						return $this;
					}

					public function generate_text(): string {
						if ( false !== strpos( $this->text, 'SLYWPC' ) ) {
							return 'Translation without placeholders';
						}

						return 'Erster Eintrag';
					}
				};
			}
		);

		$block = array(
			'blockName'   => 'core/list',
			'attrs'       => array(),
			'innerBlocks' => array(
				array(
					'blockName'   => 'core/list-item',
					'attrs'       => array(),
					'innerBlocks' => array(),
					'innerHTML'   => '<li>First item</li>',
					'innerContent' => array( '<li>First item</li>' ),
				),
			),
			'innerContent' => array( "<ul>\n", null, "\n</ul>" ),
		);

		$result = $this->invokeStatic(
			ContentTranslator::class,
			'translate_serialized_blocks',
			array( array( $block ), 'de', 'en', '' )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Erster Eintrag', $result );
		$this->assertStringContainsString( '<!-- wp:list -->', $result );
		$this->assertStringContainsString( '<!-- /wp:list -->', $result );
		$this->assertStringContainsString( '<!-- wp:list-item -->', $result );
		$this->assertCount( 1, $calls, 'The outer list wrapper should not trigger its own model call before recursion.' );
		$this->assertStringNotContainsString( 'SLYWPC', $calls[0] );
	}
}
