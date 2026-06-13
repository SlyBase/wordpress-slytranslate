<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AcfOptionsTranslationService;
use SlyTranslate\AI_Translate;
use SlyTranslate\TranslatePressAdapter;
use SlyTranslate\TranslationRuntime;
use SlyTranslate\WpMultilangAdapter;

/**
 * Tests for ACF options-page translation (fields stored in wp_options).
 */
class AcfOptionsTranslationServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		$this->setStaticProperty( TranslationRuntime::class, 'model_profile_cache', array() );
		$this->setStaticProperty( TranslationRuntime::class, 'model_slug_override', null );

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			return $value;
		} );
		$this->stubWpFunctionReturn( 'get_option', '' );
		$this->stubWpFunctionReturn( 'get_current_user_id', 1 );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

	private function stubOptionsPagesWithFields(): void {
		$this->stubWpFunctionReturn( 'acf_get_options_pages', array(
			array( 'menu_slug' => 'site-settings', 'page_title' => 'Site Settings' ),
			array( 'menu_slug' => 'other-page', 'page_title' => 'Other' ),
		) );

		$this->stubWpFunction( 'acf_get_field_groups', static function ( $filter = array() ) {
			return array( array( 'key' => 'group_' . ( $filter['options_page'] ?? '' ) ) );
		} );

		$this->stubWpFunction( 'acf_get_fields', static function ( $group ) {
			if ( 'group_site-settings' === ( $group['key'] ?? '' ) ) {
				return array(
					array( 'key' => 'field_footer', 'name' => 'footer_text', 'label' => 'Footer Text', 'type' => 'text' ),
					array( 'key' => 'field_logo', 'name' => 'logo', 'label' => 'Logo', 'type' => 'image' ),
					array( 'key' => 'field_hidden', 'name' => 'hidden_text', 'label' => 'Hidden', 'type' => 'text', 'slytranslate_exclude' => 1 ),
				);
			}
			if ( 'group_other-page' === ( $group['key'] ?? '' ) ) {
				return array(
					array( 'key' => 'field_cta', 'name' => 'cta_text', 'label' => 'CTA', 'type' => 'textarea' ),
				);
			}
			return array();
		} );
	}

	private function stubAiResponse( string $response_text ): void {
		$this->stubWpFunction(
			'wp_ai_client_prompt',
			static function ( string $text ) use ( $response_text ) {
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

	public function test_missing_target_language_returns_error(): void {
		$result = AcfOptionsTranslationService::translate_options( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_target_language', $result->get_error_code() );
	}

	public function test_same_source_and_target_language_returns_error(): void {
		$result = AcfOptionsTranslationService::translate_options( array(
			'target_language' => 'en',
			'source_language' => 'en',
		) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'same_language', $result->get_error_code() );
	}

	public function test_translatepress_reports_not_required(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', new TranslatePressAdapter() );

		$result = AcfOptionsTranslationService::translate_options( array( 'target_language' => 'de' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'not_required', $result['status'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_collect_filters_translatable_fields_and_respects_page_slug(): void {
		$this->stubOptionsPagesWithFields();

		$all = AcfOptionsTranslationService::collect_translatable_option_fields();
		$this->assertCount( 2, $all );
		$this->assertSame( 'footer_text', $all[0]['field']['name'] );
		$this->assertSame( 'cta_text', $all[1]['field']['name'] );

		$scoped = AcfOptionsTranslationService::collect_translatable_option_fields( 'site-settings' );
		$this->assertCount( 1, $scoped );
		$this->assertSame( 'footer_text', $scoped[0]['field']['name'] );
	}

	public function test_without_language_aware_storage_fields_are_skipped(): void {
		$this->stubOptionsPagesWithFields();
		$this->stubWpFunctionReturn( 'get_field', 'Footer content' );

		$result = AcfOptionsTranslationService::translate_options( array( 'target_language' => 'de' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['skipped'] );
		$this->assertSame( 0, $result['translated'] );
		$this->assertSame( 'requires_options_post_id_filter', $result['results'][0]['reason'] );
	}

	public function test_dry_run_with_post_id_filter_reports_pending_without_writes(): void {
		$this->stubOptionsPagesWithFields();
		$this->stubWpFunctionReturn( 'get_field', 'Footer content' );

		$updates = array();
		$this->stubWpFunction( 'update_field', static function ( ...$args ) use ( &$updates ) {
			$updates[] = $args;
			return true;
		} );

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_acf_options_post_id' === $tag ) {
				return 'options_' . $args[0];
			}
			return $value;
		} );

		$result = AcfOptionsTranslationService::translate_options( array(
			'target_language' => 'de',
			'dry_run'         => true,
		) );

		$this->assertSame( 2, $result['translated'] );
		$this->assertSame( 'pending', $result['results'][0]['status'] );
		$this->assertCount( 0, $updates );
	}

	public function test_polylang_pattern_writes_to_filtered_post_id(): void {
		$this->stubOptionsPagesWithFields();
		$this->stubWpFunctionReturn( 'get_field', 'Footer content' );
		$this->stubAiResponse( 'Footer-Inhalt' );

		$updates = array();
		$this->stubWpFunction( 'update_field', static function ( ...$args ) use ( &$updates ) {
			$updates[] = $args;
			return true;
		} );

		$this->stubWpFunction( 'apply_filters', static function ( string $tag, $value, ...$args ) {
			if ( 'slytranslate_acf_options_post_id' === $tag ) {
				return 'options_' . $args[0];
			}
			return $value;
		} );

		$result = AcfOptionsTranslationService::translate_options( array(
			'target_language' => 'de',
			'page_slug'       => 'site-settings',
		) );

		$this->assertSame( 1, $result['translated'] );
		$this->assertSame( 'translated', $result['results'][0]['status'] );
		$this->assertCount( 1, $updates );
		$this->assertSame( array( 'field_footer', 'Footer-Inhalt', 'options_de' ), $updates[0] );
	}

	public function test_inline_markup_adapter_merges_language_variant(): void {
		$this->stubOptionsPagesWithFields();
		$this->setStaticProperty( AI_Translate::class, 'adapter', new WpMultilangAdapter() );

		$this->stubWpFunctionReturn( 'wpm_get_languages', array(
			'en' => array( 'name' => 'English' ),
			'de' => array( 'name' => 'Deutsch' ),
		) );
		$this->stubWpFunctionReturn( 'wpm_get_default_language', 'en' );
		$this->stubWpFunctionReturn( 'get_field', '[:en]Footer content[:]' );
		$this->stubAiResponse( 'Footer-Inhalt' );

		$updates = array();
		$this->stubWpFunction( 'update_field', static function ( ...$args ) use ( &$updates ) {
			$updates[] = $args;
			return true;
		} );

		$result = AcfOptionsTranslationService::translate_options( array(
			'target_language' => 'de',
			'page_slug'       => 'site-settings',
		) );

		$this->assertSame( 1, $result['translated'] );
		$this->assertCount( 1, $updates );
		$this->assertSame( 'field_footer', $updates[0][0] );
		$this->assertStringContainsString( '[:en]Footer content', $updates[0][1] );
		$this->assertStringContainsString( '[:de]Footer-Inhalt', $updates[0][1] );
		$this->assertSame( 'options', $updates[0][2] );
	}

	public function test_empty_option_values_are_skipped(): void {
		$this->stubOptionsPagesWithFields();
		$this->stubWpFunctionReturn( 'get_field', '' );

		$result = AcfOptionsTranslationService::translate_options( array( 'target_language' => 'de' ) );

		$this->assertSame( 2, $result['skipped'] );
		$this->assertSame( 'empty_value', $result['results'][0]['reason'] );
	}
}
