<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\MediaTranslationService;

class MediaTranslationServiceTest extends TestCase {

	private function stubAiResponse( string $response_text, array &$calls = array() ): void {
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function ( string $text ) use ( $response_text, &$calls ) {
				$calls[] = $text;
				return new class( $response_text ) {
					private string $response;

					public function __construct( string $response ) {
						$this->response = $response;
					}

					public function using_system_instruction( string $p ): static { return $this; }
					public function using_temperature( float $t ): static { return $this; }
					public function using_model_preference( string $s ): static { return $this; }
					public function using_max_tokens( int $n ): static { return $this; }
					public function using_max_output_tokens( int $n ): static { return $this; }

					public function generate_text(): string {
						return '<slytranslate-output>' . $this->response . '</slytranslate-output>';
					}
				};
			}
		);
	}

	public function test_media_duplication_translates_alt_text_and_caption(): void {
		$this->stubAiResponse( 'A brown dog' );
		$this->stubWpFunctionReturn( 'pll_get_post_language', 'de' );
		$this->stubWpFunction( 'get_post_meta', static function ( $post_id, $key = '', $single = false ) {
			return 11 === $post_id && MediaTranslationService::ALT_TEXT_META_KEY === $key ? 'Ein brauner Hund' : '';
		} );
		$this->stubWpFunction( 'get_post', static function () {
			return new \WP_Post( array(
				'ID'           => 12,
				'post_excerpt' => 'Bildunterschrift Hund',
				'post_content' => '',
			) );
		} );

		$meta_updates = array();
		$post_updates = array();
		$this->stubWpFunction( 'update_post_meta', static function ( $post_id, $key, $value ) use ( &$meta_updates ) {
			$meta_updates[] = array( $post_id, $key, $value );
			return true;
		} );
		$this->stubWpFunction( 'wp_update_post', static function ( $postarr ) use ( &$post_updates ) {
			$post_updates[] = $postarr;
			return $postarr['ID'] ?? 0;
		} );

		MediaTranslationService::handle_pll_translate_media( 11, 12, 'en' );

		$this->assertSame( array( array( 12, MediaTranslationService::ALT_TEXT_META_KEY, 'A brown dog' ) ), $meta_updates );
		$this->assertCount( 1, $post_updates );
		$this->assertSame( 12, $post_updates[0]['ID'] );
		$this->assertSame( 'A brown dog', $post_updates[0]['post_excerpt'] );
		$this->assertArrayNotHasKey( 'post_content', $post_updates[0] );
	}

	public function test_media_duplication_skips_same_language_and_invalid_ids(): void {
		$this->stubWpFunction( 'wp_ai_client_prompt', static function () {
			throw new \RuntimeException( 'AI must not be called.' );
		} );

		// Same language.
		$this->stubWpFunctionReturn( 'pll_get_post_language', 'en' );
		MediaTranslationService::handle_pll_translate_media( 11, 12, 'en' );

		// Identical IDs.
		$this->stubWpFunctionReturn( 'pll_get_post_language', 'de' );
		MediaTranslationService::handle_pll_translate_media( 11, 11, 'en' );

		// Missing source language.
		$this->stubWpFunctionReturn( 'pll_get_post_language', false );
		MediaTranslationService::handle_pll_translate_media( 11, 12, 'en' );

		$this->addToAssertionCount( 1 ); // No exception means no AI call happened.
	}

	public function test_featured_image_alt_is_backfilled_on_translated_post(): void {
		$this->stubAiResponse( 'A brown dog' );
		$this->stubWpFunction( 'get_post_thumbnail_id', static function ( $post_id ) {
			return 5 === $post_id ? 21 : 22;
		} );
		$this->stubWpFunction( 'get_post_meta', static function ( $post_id, $key = '', $single = false ) {
			// Source attachment alt is set, target alt is still empty.
			return 21 === $post_id ? 'Ein brauner Hund' : '';
		} );

		$meta_updates = array();
		$this->stubWpFunction( 'update_post_meta', static function ( $post_id, $key, $value ) use ( &$meta_updates ) {
			$meta_updates[] = array( $post_id, $key, $value );
			return true;
		} );

		MediaTranslationService::maybe_translate_featured_image_alt( 5, 9, 'en', 'de' );

		$this->assertSame( array( array( 22, MediaTranslationService::ALT_TEXT_META_KEY, 'A brown dog' ) ), $meta_updates );
	}

	public function test_featured_image_alt_respects_polylang_media_support_gate(): void {
		$this->stubWpFunctionReturn( 'pll_get_option', false );
		$this->stubWpFunction( 'get_post_thumbnail_id', static function () {
			throw new \RuntimeException( 'Thumbnail lookup must not happen when media translation is disabled.' );
		} );

		MediaTranslationService::maybe_translate_featured_image_alt( 5, 9, 'en', 'de' );

		$this->addToAssertionCount( 1 );
	}

	public function test_featured_image_alt_keeps_existing_target_alt(): void {
		$this->stubWpFunction( 'wp_ai_client_prompt', static function () {
			throw new \RuntimeException( 'AI must not be called when the target alt already exists.' );
		} );
		$this->stubWpFunction( 'get_post_thumbnail_id', static function ( $post_id ) {
			return 5 === $post_id ? 21 : 22;
		} );
		$this->stubWpFunction( 'get_post_meta', static function ( $post_id ) {
			return 22 === $post_id ? 'Existing alt' : 'Ein brauner Hund';
		} );

		MediaTranslationService::maybe_translate_featured_image_alt( 5, 9, 'en', 'de' );

		$this->addToAssertionCount( 1 );
	}
}
