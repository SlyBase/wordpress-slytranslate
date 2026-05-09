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

	public function test_simple_list_items_are_translated_in_single_json_batch_call(): void {
		$calls = array();

		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );
		$this->stubWpFunction( 'serialize_blocks',
			static function ( array $blocks ): string {
				$block = $blocks[0] ?? array();
				$name  = $block['blockName'] ?? '';

				if ( 'core/list-item' === $name ) {
					$content = $block['attrs']['__test_content'] ?? 'Item';
					return "<!-- wp:list-item -->\n<li>{$content}</li>\n<!-- /wp:list-item -->";
				}

				if ( 'core/list' === $name ) {
					return "<!-- wp:list -->\n<ul>\n<!-- wp:list-item -->\n<li>First item</li>\n<!-- /wp:list-item -->\n<!-- wp:list-item -->\n<li>Second item</li>\n<!-- /wp:list-item -->\n<!-- wp:list-item -->\n<li>Third item</li>\n<!-- /wp:list-item -->\n</ul>\n<!-- /wp:list -->";
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
						if ( false !== strpos( $this->text, 'item_0' )
							&& false !== strpos( $this->text, 'item_1' )
							&& false !== strpos( $this->text, 'item_2' )
						) {
							return wp_json_encode( array(
								'item_0' => 'Erster Eintrag',
								'item_1' => 'Zweiter Eintrag',
								'item_2' => 'Dritter Eintrag',
							) ) ?: '';
						}

						return 'Fallback';
					}
				};
			}
		);

		$block = array(
			'blockName'   => 'core/list',
			'attrs'       => array(),
			'innerBlocks' => array(
				array(
					'blockName'    => 'core/list-item',
					'attrs'        => array( '__test_content' => 'First item' ),
					'innerBlocks'  => array(),
					'innerHTML'    => '<li>First item</li>',
					'innerContent' => array( '<li>First item</li>' ),
				),
				array(
					'blockName'    => 'core/list-item',
					'attrs'        => array( '__test_content' => 'Second item' ),
					'innerBlocks'  => array(),
					'innerHTML'    => '<li>Second item</li>',
					'innerContent' => array( '<li>Second item</li>' ),
				),
				array(
					'blockName'    => 'core/list-item',
					'attrs'        => array( '__test_content' => 'Third item' ),
					'innerBlocks'  => array(),
					'innerHTML'    => '<li>Third item</li>',
					'innerContent' => array( '<li>Third item</li>' ),
				),
			),
			'innerContent' => array( "<ul>\n", null, "\n", null, "\n", null, "\n</ul>" ),
		);

		$result = $this->invokeStatic(
			ContentTranslator::class,
			'translate_serialized_blocks',
			array( array( $block ), 'de', 'en', '' )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Erster Eintrag', $result );
		$this->assertStringContainsString( 'Zweiter Eintrag', $result );
		$this->assertStringContainsString( 'Dritter Eintrag', $result );
		$this->assertCount( 1, $calls, 'Simple list items should be translated in one JSON batch call.' );
	}

	public function test_extract_list_item_content_parts_accepts_nested_html_content(): void {
		$result = $this->invokeStatic(
			ContentTranslator::class,
			'extract_list_item_content_parts',
			array( '<li><p>First <strong>item</strong></p></li>' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( '<li>', $result['open_tag'] );
		$this->assertSame( '</li>', $result['close_tag'] );
		$this->assertSame( '<p>First <strong>item</strong></p>', $result['content'] );
	}

	public function test_mixed_list_items_batch_flat_items_and_recurse_nested_items(): void {
		$calls = array();

		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );
		$this->stubWpFunction( 'serialize_blocks',
			static function ( array $blocks ): string {
				$block = $blocks[0] ?? array();
				$name  = $block['blockName'] ?? '';

				if ( 'core/list-item' === $name ) {
					$content = $block['attrs']['__test_content'] ?? 'Item';
					if ( ! empty( $block['innerBlocks'] ) ) {
						return "<!-- wp:list-item -->\n<li>{$content}<ul><li>Nested</li></ul></li>\n<!-- /wp:list-item -->";
					}
					return "<!-- wp:list-item -->\n<li>{$content}</li>\n<!-- /wp:list-item -->";
				}

				if ( 'core/list' === $name ) {
					return "<!-- wp:list -->\n<ul>\n<!-- wp:list-item -->\n<li>First item</li>\n<!-- /wp:list-item -->\n<!-- wp:list-item -->\n<li>Nested item<ul><li>Nested</li></ul></li>\n<!-- /wp:list-item -->\n<!-- wp:list-item -->\n<li>Third item</li>\n<!-- /wp:list-item -->\n</ul>\n<!-- /wp:list -->";
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
						if ( false !== strpos( $this->text, 'item_0' ) && false !== strpos( $this->text, 'item_2' ) ) {
							return wp_json_encode( array(
								'item_0' => 'Erster Eintrag',
								'item_2' => 'Dritter Eintrag',
							) ) ?: '';
						}

						if ( false !== strpos( $this->text, 'Nested item' ) || false !== strpos( $this->text, 'Nested child' ) ) {
							return 'Verschachtelter Eintrag';
						}

						return 'Fallback';
					}
				};
			}
		);

		$block = array(
			'blockName'   => 'core/list',
			'attrs'       => array(),
			'innerBlocks' => array(
				array(
					'blockName'    => 'core/list-item',
					'attrs'        => array( '__test_content' => 'First item' ),
					'innerBlocks'  => array(),
					'innerHTML'    => '<li>First item</li>',
					'innerContent' => array( '<li>First item</li>' ),
				),
				array(
					'blockName'    => 'core/list-item',
					'attrs'        => array( '__test_content' => 'Nested item' ),
					'innerBlocks'  => array(
						array(
							'blockName'    => 'core/list-item',
							'attrs'        => array( '__test_content' => 'Nested child' ),
							'innerBlocks'  => array(),
							'innerHTML'    => '<li>Nested child</li>',
							'innerContent' => array( '<li>Nested child</li>' ),
						),
					),
					'innerHTML'    => '<li>Nested item<ul><li>Nested</li></ul></li>',
					'innerContent' => array( '<li>Nested item<ul>', null, '</ul></li>' ),
				),
				array(
					'blockName'    => 'core/list-item',
					'attrs'        => array( '__test_content' => 'Third item' ),
					'innerBlocks'  => array(),
					'innerHTML'    => '<li>Third item</li>',
					'innerContent' => array( '<li>Third item</li>' ),
				),
			),
			'innerContent' => array( "<ul>\n", null, "\n", null, "\n", null, "\n</ul>" ),
		);

		$result = $this->invokeStatic(
			ContentTranslator::class,
			'translate_serialized_blocks',
			array( array( $block ), 'de', 'en', '' )
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Erster Eintrag', $result );
		$this->assertStringContainsString( 'Dritter Eintrag', $result );
		$this->assertGreaterThanOrEqual( 2, count( $calls ), 'Mixed lists should use at least one batch call plus recursive nested-item calls.' );
		$batch_calls = array_values( array_filter( $calls, static fn( string $text ): bool => false !== strpos( $text, 'item_0' ) ) );
		$recursive_calls = array_values( array_filter( $calls, static fn( string $text ): bool => false === strpos( $text, 'item_0' ) ) );
		$this->assertNotEmpty( $batch_calls, 'Expected one JSON batch call for flat list items.' );
		$this->assertNotEmpty( $recursive_calls, 'Expected recursive single-item calls for nested list items.' );
	}

	public function test_translate_pending_blocks_attempts_micro_batch_for_short_non_list_wrappers(): void {
		$calls = array();

		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );
		$this->stubWpFunction( 'serialize_blocks',
			static function ( array $blocks ): string {
				$parts = array();
				foreach ( $blocks as $block ) {
					$name = $block['blockName'] ?? '';
					if ( 'core/paragraph' === $name ) {
						$content = $block['attrs']['__test_content'] ?? 'Text';
						$parts[] = "<!-- wp:paragraph -->\n<p>{$content}</p>\n<!-- /wp:paragraph -->";
					}
				}
				return implode( "\n", $parts );
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
						if ( false !== strpos( $this->text, 'block_0' )
							&& false !== strpos( $this->text, 'block_1' )
							&& false !== strpos( $this->text, 'block_2' )
						) {
							return wp_json_encode( array(
								'block_0' => '<p>Dies ist Absatz eins fuer den Test.</p>',
								'block_1' => '<p>Dies ist Absatz zwei fuer den Test.</p>',
								'block_2' => '<p>Dies ist Absatz drei fuer den Test.</p>',
							) ) ?: '';
						}

						return 'Fallback';
					}
				};
			}
		);

		$pending_blocks = array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array( '__test_content' => 'Paragraph one' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>Paragraph one</p>',
				'innerContent' => array( '<p>Paragraph one</p>' ),
			),
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array( '__test_content' => 'Paragraph two' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>Paragraph two</p>',
				'innerContent' => array( '<p>Paragraph two</p>' ),
			),
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array( '__test_content' => 'Paragraph three' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>Paragraph three</p>',
				'innerContent' => array( '<p>Paragraph three</p>' ),
			),
		);

		$result = $this->invokeStatic(
			ContentTranslator::class,
			'translate_short_non_list_blocks_batch',
			array( $pending_blocks, 'de', 'en', '' )
		);

		$this->assertTrue( is_string( $result ) || null === $result );
		$this->assertGreaterThanOrEqual( 1, count( $calls ), 'Short non-list wrapper blocks should attempt a JSON micro-batch call.' );
		$batch_calls = array_values( array_filter( $calls, static fn( string $text ): bool => false !== strpos( $text, 'block_0' ) ) );
		$this->assertNotEmpty( $batch_calls );
		$this->assertStringContainsString( 'block_1', $batch_calls[0] );
		$this->assertStringContainsString( 'block_2', $batch_calls[0] );
	}
}
