<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\AbilityRegistrar;
use AI_Translate\EditorBootstrap;
use AI_Translate\LegacyPolylangBridge;
use AI_Translate\ListTableTranslation;
use AI_Translate\Settings;
use Brain\Monkey\Functions;

class HookRegistrationTest extends TestCase {

	public function test_add_hooks_registers_expected_editor_and_ability_hooks(): void {
		$registered_actions = array();
		$registered_filters = array();

		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$registered_actions ): void {
				$registered_actions[] = array(
					'hook'          => $hook,
					'callback'      => $callback,
					'priority'      => $priority,
					'accepted_args' => $accepted_args,
				);
			}
		);
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$registered_filters ): void {
				$registered_filters[] = array(
					'hook'          => $hook,
					'callback'      => $callback,
					'priority'      => $priority,
					'accepted_args' => $accepted_args,
				);
			}
		);
		Functions\when( 'get_option' )->justReturn( '1' );

		AI_Translate::add_hooks();

		$this->assertSame(
			array(
				array(
					'hook'          => 'enqueue_block_editor_assets',
					'callback'      => array( EditorBootstrap::class, 'enqueue_editor_plugin' ),
					'priority'      => 10,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'admin_init',
					'callback'      => array( Settings::class, 'register' ),
					'priority'      => 10,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'wp_abilities_api_categories_init',
					'callback'      => array( AbilityRegistrar::class, 'register_ability_category' ),
					'priority'      => 10,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'wp_abilities_api_init',
					'callback'      => array( AbilityRegistrar::class, 'register_abilities' ),
					'priority'      => 10,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'current_screen',
					'callback'      => array( ListTableTranslation::class, 'register_list_table_hooks' ),
					'priority'      => 10,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'admin_post_ai_translate_single',
					'callback'      => array( ListTableTranslation::class, 'handle_single_translate' ),
					'priority'      => 10,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'admin_notices',
					'callback'      => array( ListTableTranslation::class, 'show_admin_notice' ),
					'priority'      => 10,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'admin_footer',
					'callback'      => array( ListTableTranslation::class, 'enqueue_global_background_bar' ),
					'priority'      => 10,
					'accepted_args' => 1,
				),
			),
			$registered_actions
		);
		$this->assertSame(
			array(
				array(
					'hook'          => 'default_title',
					'callback'      => array( LegacyPolylangBridge::class, 'default_title' ),
					'priority'      => 10,
					'accepted_args' => 2,
				),
				array(
					'hook'          => 'default_content',
					'callback'      => array( LegacyPolylangBridge::class, 'default_content' ),
					'priority'      => 10,
					'accepted_args' => 2,
				),
				array(
					'hook'          => 'default_excerpt',
					'callback'      => array( LegacyPolylangBridge::class, 'default_excerpt' ),
					'priority'      => 10,
					'accepted_args' => 2,
				),
				array(
					'hook'          => 'pll_translate_post_meta',
					'callback'      => array( LegacyPolylangBridge::class, 'pll_translate_post_meta' ),
					'priority'      => 10,
					'accepted_args' => 3,
				),
			),
			$registered_filters
		);

		$this->assertNotContains( 'plugins_loaded', array_column( $registered_actions, 'hook' ) );
		$this->assertNotContains( 'the_content', array_column( $registered_filters, 'hook' ) );
	}

	public function test_polylang_hooks_not_registered_when_feature_disabled(): void {
		$registered_filters = array();

		Functions\when( 'add_action' )->alias( static function() {} );
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$registered_filters ): void {
				$registered_filters[] = array( 'hook' => $hook, 'callback' => $callback );
			}
		);
		Functions\when( 'get_option' )->justReturn( '0' );

		AI_Translate::add_hooks();

		$filter_hooks = array_column( $registered_filters, 'hook' );
		$this->assertNotContains( 'default_title', $filter_hooks );
		$this->assertNotContains( 'default_content', $filter_hooks );
		$this->assertNotContains( 'default_excerpt', $filter_hooks );
		$this->assertNotContains( 'pll_translate_post_meta', $filter_hooks );
	}
}
