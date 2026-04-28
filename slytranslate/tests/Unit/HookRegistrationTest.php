<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\AbilityRegistrar;
use SlyTranslate\EditorBootstrap;
use SlyTranslate\ListTableTranslation;
use SlyTranslate\Settings;

class HookRegistrationTest extends TestCase {

	public function test_add_hooks_registers_expected_editor_and_ability_hooks(): void {
		$registered_actions = array();

		$this->stubWpFunction(
			'add_action',
			static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$registered_actions ): void {
				$registered_actions[] = array(
					'hook'          => $hook,
					'callback'      => $callback,
					'priority'      => $priority,
					'accepted_args' => $accepted_args,
				);
			}
		);

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
					'hook'          => 'rest_api_init',
					'callback'      => array( AI_Translate::class, 'register_editor_rest_routes' ),
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
					'hook'          => 'admin_post_slytranslate_single',
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
					'hook'          => 'admin_enqueue_scripts',
					'callback'      => array( ListTableTranslation::class, 'enqueue_list_table_assets' ),
					'priority'      => 10,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'admin_enqueue_scripts',
					'callback'      => array( ListTableTranslation::class, 'enqueue_global_background_bar' ),
					'priority'      => 10,
					'accepted_args' => 1,
				),
			),
			$registered_actions
		);

		$this->assertNotContains( 'plugins_loaded', array_column( $registered_actions, 'hook' ) );
	}
}
