<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\ContentTranslator;

class ContentTranslationPassthroughRecoveryTest extends TestCase {

	public function test_language_passthrough_recovery_translates_simple_wrapper_block(): void {
		$calls = array();

		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );
		$this->stubWpFunction( 'serialize_blocks',
			static function ( array $blocks ): string {
				$block = $blocks[0] ?? array();
				$name  = $block['blockName'] ?? '';

				if ( 'core/paragraph' === $name ) {
					return "<!-- wp:paragraph -->\n<p>Hello world this is source english text for fallback translation.</p>\n<!-- /wp:paragraph -->";
				}

				return '';
			}
		);

		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) use ( &$calls ) {
				$calls[] = $text;

				return new class {
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
						return 'Hallo Welt das ist deutscher Beispieltext fuer die Wiederherstellung.';
					}
				};
			}
		);

		$block = array(
			'blockName'   => 'core/paragraph',
			'attrs'       => array(),
			'innerBlocks' => array(),
			'innerHTML'   => '<p>Hello world this is source english text for fallback translation.</p>',
			'innerContent' => array( '<p>Hello world this is source english text for fallback translation.</p>' ),
		);

		$result = $this->invokeStatic(
			ContentTranslator::class,
			'translate_single_block',
			array(
				$block,
				'de',
				'en',
				'',
				new \WP_Error( 'invalid_translation_language_passthrough', 'passthrough' ),
			)
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Hallo Welt', $result );
		$this->assertStringNotContainsString( 'source english text', $result );
		$this->assertStringContainsString( 'Hello world this is source english text for fallback translation.', $calls[0] );
	}

	public function test_language_passthrough_without_recovery_surface_error(): void {
		$this->stubWpFunction( 'serialize_blocks',
			static function (): string {
				return "<!-- wp:list -->\n<ul>\n<!-- wp:list-item -->\n<li>Hello world</li>\n<!-- /wp:list-item -->\n</ul>\n<!-- /wp:list -->";
			}
		);

		$block = array(
			'blockName'   => 'core/list',
			'attrs'       => array(),
			'innerBlocks' => array(),
			'innerHTML'   => '',
			'innerContent' => array(),
		);

		$result = $this->invokeStatic(
			ContentTranslator::class,
			'translate_single_block',
			array(
				$block,
				'de',
				'en',
				'',
				new \WP_Error( 'invalid_translation_language_passthrough', 'passthrough' ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_translation_language_passthrough', $result->get_error_code() );
	}

	public function test_inline_html_markdown_drift_keeps_source_block_when_strict_retry_still_loses_tags(): void {
		$calls = array();

		$serialized = "<!-- wp:paragraph -->\n<p><strong>KI-Kunde (<code>wp_ai_client_prompt()</code>):</strong>&nbsp;Eine einzelne PHP-Funktion.</p>\n<!-- /wp:paragraph -->";

		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );
		$this->stubWpFunction( 'serialize_blocks',
			static function ( array $blocks ) use ( $serialized ): string {
				$block = $blocks[0] ?? array();
				$name  = $block['blockName'] ?? '';

				if ( 'core/paragraph' === $name ) {
					return $serialized;
				}

				return '';
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) use ( &$calls ) {
				$calls[] = $text;

				return new class {
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
						return '**AI Customer (<code>wp_ai_client_prompt()</code>):** A single PHP function.';
					}
				};
			}
		);

		$block = array(
			'blockName'   => 'core/paragraph',
			'attrs'       => array(),
			'innerBlocks' => array(),
			'innerHTML'   => '<p><strong>KI-Kunde (<code>wp_ai_client_prompt()</code>):</strong>&nbsp;Eine einzelne PHP-Funktion.</p>',
			'innerContent' => array( '<p><strong>KI-Kunde (<code>wp_ai_client_prompt()</code>):</strong>&nbsp;Eine einzelne PHP-Funktion.</p>' ),
		);

		$result = $this->invokeStatic(
			ContentTranslator::class,
			'translate_single_block',
			array(
				$block,
				'en',
				'de',
				'',
			)
		);

		$this->assertSame( $serialized, $result );
		$this->assertCount( 2, $calls );
	}

	public function test_inline_tag_loss_accepts_translation_instead_of_keeping_source_block(): void {
		$calls = array();

		$serialized = "<!-- wp:paragraph -->\n<p>Zum <a href=\"https://example.com/sly\">SlyTranslate</a> Plugin.</p>\n<!-- /wp:paragraph -->";

		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );
		$this->stubWpFunction( 'serialize_blocks',
			static function ( array $blocks ) use ( $serialized ): string {
				$block = $blocks[0] ?? array();
				if ( 'core/paragraph' === ( $block['blockName'] ?? '' ) ) {
					return $serialized;
				}
				return '';
			}
		);
		$this->stubWpFunction( 'wp_ai_client_prompt',
			static function ( string $text ) use ( &$calls ) {
				$calls[] = $text;

				return new class {
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
						// Model translates the text but drops the <a> tag (no markdown used).
						return 'To the SlyTranslate Plugin.';
					}
				};
			}
		);

		$block = array(
			'blockName'   => 'core/paragraph',
			'attrs'       => array(),
			'innerBlocks' => array(),
			'innerHTML'   => '<p>Zum <a href="https://example.com/sly">SlyTranslate</a> Plugin.</p>',
			'innerContent' => array( '<p>Zum <a href="https://example.com/sly">SlyTranslate</a> Plugin.</p>' ),
		);

		// Simulate the block-translation context where translate_block_sections()
		// sets skip_html_tag_validation=true so the validator accepts translations
		// that lose inline HTML tags (which has_inline_formatting_loss guards separately).
		\SlyTranslate\TranslationRuntime::set_skip_html_tag_validation( true );
		try {
			$result = $this->invokeStatic(
				ContentTranslator::class,
				'translate_single_block',
				array( $block, 'en', 'de', '' )
			);
		} finally {
			\SlyTranslate\TranslationRuntime::set_skip_html_tag_validation( false );
		}

		// The block must be translated, not kept in the source language.
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'SlyTranslate Plugin', $result );
		$this->assertStringNotContainsString( 'Zum ', $result );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $result );
		$this->assertNotSame( $serialized, $result );
		// All 4 attempts must have been made (2 unwrap + 2 full inner HTML).
		$this->assertCount( 4, $calls );
	}
}
