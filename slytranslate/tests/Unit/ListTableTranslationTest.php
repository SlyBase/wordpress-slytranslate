<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\ListTableTranslation;
use SlyTranslate\PolylangAdapter;
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

	/**
	 * Regression test for #20: with a multi-post adapter (Polylang, i.e. NOT
	 * single-entry mode) where EVERY configured target language already has a
	 * translation, the Translate row action used to disappear because
	 * $missing_languages was empty and the early `return` fired before the
	 * action was appended. The action must now stay so the user can open the
	 * picker dialog and enable "Overwrite existing translation".
	 */
	public function test_add_row_actions_keeps_action_when_all_targets_already_translated(): void {
		// PolylangAdapter is a multi-post adapter: NOT one of the single-entry
		// adapters (WpMultilang / Wpglobus / TranslatePress), so
		// $single_entry_mode is false and translated targets land in
		// $existing_languages (never $missing_languages).
		$adapter = new class() extends PolylangAdapter {
			public function is_available(): bool {
				return true;
			}

			public function get_languages(): array {
				return array(
					'de' => 'Deutsch',
					'fr' => 'Français',
				);
			}

			public function get_post_language( int $post_id ): ?string {
				return 'en';
			}

			public function get_post_translations( int $post_id ): array {
				return array(
					'en' => $post_id,
					'de' => $post_id + 1,
					'fr' => $post_id + 2,
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
				'post_title' => 'Source page',
			)
		);

		$actions = ListTableTranslation::add_row_actions( array(), $post );

		// The action must be present even though no target is missing.
		$this->assertArrayHasKey( 'slytranslate', $actions );
		$this->assertStringContainsString( 'class="slytranslate-ajax-translate"', $actions['slytranslate'] );
		$this->assertStringContainsString( 'data-source-lang="en"', $actions['slytranslate'] );
		// No missing targets -> empty data-langs (the dialog relies on
		// data-all-langs / data-existing-langs for the overwrite case).
		$this->assertStringContainsString( 'data-langs="[]"', $actions['slytranslate'] );
		// Both already-translated targets must be exposed for the overwrite gate.
		$this->assertStringContainsString( 'data-existing-langs="[&quot;de&quot;,&quot;fr&quot;]"', $actions['slytranslate'] );
	}

	/**
	 * Guard: when there is genuinely nothing to translate to or update (only the
	 * source language exists, no targets at all), the action must still be
	 * omitted. This keeps the early-return behaviour for the empty case intact.
	 */
	public function test_add_row_actions_hides_action_when_no_target_languages_exist(): void {
		$adapter = new class() extends PolylangAdapter {
			public function is_available(): bool {
				return true;
			}

			public function get_languages(): array {
				// Only the source language is configured: no targets at all.
				return array(
					'en' => 'English',
				);
			}

			public function get_post_language( int $post_id ): ?string {
				return 'en';
			}

			public function get_post_translations( int $post_id ): array {
				return array(
					'en' => $post_id,
				);
			}
		};

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubWpFunctionReturn( 'current_user_can', true );
		$this->stubWpFunctionReturn( 'get_post_status', 'publish' );
		$this->stubWpFunction( 'wp_json_encode', static fn( $value ): string => (string) json_encode( $value ) );

		$post = new \WP_Post(
			array(
				'ID'         => 43,
				'post_title' => 'Solo page',
			)
		);

		$actions = ListTableTranslation::add_row_actions( array(), $post );

		$this->assertArrayNotHasKey( 'slytranslate', $actions );
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