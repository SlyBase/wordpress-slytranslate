<?php
/*
Plugin Name: AI Translate Abilities
Plugin URI: https://wordpress.org/plugins/ai-translate-abilities/
Description: AI translation abilities for WordPress. Translates posts and text using the WordPress AI Client and Abilities API. Based on AI Translate For Polylang by James Low.
Version: 1.0.0
Author: Timon Först
Author URI: https://github.com/SlyBase/
Requires at least: 7.0
Requires PHP: 8.1
License: MIT License
Text Domain: ai-translate-abilities
*/

namespace AI_Translate;

require_once __DIR__ . '/inc/TranslationPluginAdapter.php';
require_once __DIR__ . '/inc/PolylangAdapter.php';

class AI_Translate {

	// Default prompt template.
	public static $PROMPT = 'Translate the content from {FROM_CODE} to {TO_CODE} preserving html, formatting and embedded media. Only return the new content.';

	// Cached meta key lists.
	public static $meta_translate;
	public static $meta_clear;

	// Adapter instance.
	private static $adapter;

	/* ---------------------------------------------------------------
	 * Adapter
	 * ------------------------------------------------------------- */

	/**
	 * Get the active translation plugin adapter.
	 *
	 * @return TranslationPluginAdapter|null
	 */
	public static function get_adapter(): ?TranslationPluginAdapter {
		if ( null === self::$adapter ) {
			$polylang = new PolylangAdapter();
			if ( $polylang->is_available() ) {
				self::$adapter = $polylang;
			}
			// Future adapters (WPML, TranslatePress, …) can be checked here.
		}
		return self::$adapter;
	}

	/* ---------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------- */

	public static function add_hooks() {
		// Abilities API registration (WP 7.0+).
		add_action( 'wp_abilities_api_categories_init', array( static::class, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( static::class, 'register_abilities' ) );

		// Polylang auto-translate hooks (kept for backward compatibility).
		add_filter( 'default_title', array( static::class, 'default_title' ), 10, 2 );
		add_filter( 'default_content', array( static::class, 'default_content' ), 10, 2 );
		add_filter( 'default_excerpt', array( static::class, 'default_excerpt' ), 10, 2 );
		add_filter( 'pll_translate_post_meta', array( static::class, 'pll_translate_post_meta' ), 10, 3 );
	}

	/* ---------------------------------------------------------------
	 * Abilities API – Category
	 * ------------------------------------------------------------- */

	public static function register_ability_category() {
		wp_register_ability_category( 'ai-translation', array(
			'label'       => __( 'AI Translation', 'ai-translate-abilities' ),
			'description' => __( 'AI-powered content translation abilities.', 'ai-translate-abilities' ),
		) );
	}

	/* ---------------------------------------------------------------
	 * Abilities API – Register all abilities
	 * ------------------------------------------------------------- */

	public static function register_abilities() {
		self::register_get_languages_ability();
		self::register_get_translation_status_ability();
		self::register_translate_text_ability();
		self::register_translate_post_ability();
		self::register_translate_posts_ability();
		self::register_configure_ability();
	}

	/* --- get-languages ------------------------------------------- */

	private static function register_get_languages_ability() {
		wp_register_ability( 'ai-translate/get-languages', array(
			'label'               => __( 'Get Languages', 'ai-translate-abilities' ),
			'description'         => __( 'Returns all languages available for translation.', 'ai-translate-abilities' ),
			'category'            => 'ai-translation',
			'input_schema'        => null,
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'code' => array( 'type' => 'string', 'description' => 'Language code' ),
						'name' => array( 'type' => 'string', 'description' => 'Language name' ),
					),
					'required' => array( 'code', 'name' ),
				),
			),
			'execute_callback'    => array( static::class, 'execute_get_languages' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array( 'readonly' => true ),
			),
		) );
	}

	public static function execute_get_languages() {
		$adapter = self::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'ai-translate-abilities' ) );
		}
		$languages = $adapter->get_languages();
		$result    = array();
		foreach ( $languages as $code => $name ) {
			$result[] = array( 'code' => $code, 'name' => $name );
		}
		return $result;
	}

	/* --- get-translation-status ---------------------------------- */

	private static function register_get_translation_status_ability() {
		wp_register_ability( 'ai-translate/get-translation-status', array(
			'label'               => __( 'Get Translation Status', 'ai-translate-abilities' ),
			'description'         => __( 'Returns the translation status for a given post.', 'ai-translate-abilities' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer', 'description' => 'The post ID to check.' ),
				),
				'required' => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'source_language' => array( 'type' => 'string' ),
					'translations'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'lang'    => array( 'type' => 'string' ),
								'post_id' => array( 'type' => 'integer' ),
								'exists'  => array( 'type' => 'boolean' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( static::class, 'execute_get_translation_status' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array( 'readonly' => true ),
			),
		) );
	}

	public static function execute_get_translation_status( $input ) {
		$adapter = self::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'ai-translate-abilities' ) );
		}

		$post_id = $input['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'ai-translate-abilities' ) );
		}

		$source_lang  = $adapter->get_post_language( $post_id );
		$translations = $adapter->get_post_translations( $post_id );
		$languages    = $adapter->get_languages();

		$status = array();
		foreach ( $languages as $code => $name ) {
			if ( $code === $source_lang ) {
				continue;
			}
			$translated_id = $translations[ $code ] ?? 0;
			$exists        = $translated_id > 0 && get_post_status( $translated_id ) !== false;
			$status[]      = array(
				'lang'    => $code,
				'post_id' => $translated_id ?: 0,
				'exists'  => $exists,
			);
		}

		return array(
			'source_language' => $source_lang ?? '',
			'translations'    => $status,
		);
	}

	/* --- translate-text ------------------------------------------ */

	private static function register_translate_text_ability() {
		wp_register_ability( 'ai-translate/translate-text', array(
			'label'               => __( 'Translate Text', 'ai-translate-abilities' ),
			'description'         => __( 'Translates arbitrary text from one language to another using the WordPress AI Client.', 'ai-translate-abilities' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'text'            => array( 'type' => 'string', 'description' => 'The text to translate.', 'minLength' => 1 ),
					'source_language' => array( 'type' => 'string', 'description' => 'Source language code.' ),
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
				),
				'required' => array( 'text', 'source_language', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'translated_text'  => array( 'type' => 'string' ),
					'source_language'  => array( 'type' => 'string' ),
					'target_language'  => array( 'type' => 'string' ),
				),
				'required' => array( 'translated_text' ),
			),
			'execute_callback'    => array( static::class, 'execute_translate_text' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array( 'idempotent' => true ),
			),
		) );
	}

	public static function execute_translate_text( $input ) {
		$translated = self::translate( $input['text'], $input['target_language'], $input['source_language'] );

		if ( is_wp_error( $translated ) ) {
			return $translated;
		}

		return array(
			'translated_text' => $translated,
			'source_language' => $input['source_language'],
			'target_language' => $input['target_language'],
		);
	}

	/* --- translate-post ------------------------------------------ */

	private static function register_translate_post_ability() {
		wp_register_ability( 'ai-translate/translate-post', array(
			'label'               => __( 'Translate Post', 'ai-translate-abilities' ),
			'description'         => __( 'Translates an entire post (title, content, excerpt, meta) and creates or updates the translation.', 'ai-translate-abilities' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'         => array( 'type' => 'integer', 'description' => 'The source post ID.' ),
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'overwrite'       => array( 'type' => 'boolean', 'description' => 'Overwrite existing translation.', 'default' => false ),
				),
				'required' => array( 'post_id', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'translated_post_id' => array( 'type' => 'integer' ),
					'source_post_id'     => array( 'type' => 'integer' ),
					'target_language'    => array( 'type' => 'string' ),
					'title'              => array( 'type' => 'string' ),
				),
				'required' => array( 'translated_post_id', 'source_post_id' ),
			),
			'execute_callback'    => array( static::class, 'execute_translate_post' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' ) && current_user_can( 'publish_posts' );
			},
			'meta'                => array(
				'show_in_rest' => true,
			),
		) );
	}

	public static function execute_translate_post( $input ) {
		$result = self::translate_post(
			$input['post_id'],
			$input['target_language'],
			'publish',
			$input['overwrite'] ?? false
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$translated_post = get_post( $result );

		return array(
			'translated_post_id' => $result,
			'source_post_id'     => $input['post_id'],
			'target_language'    => $input['target_language'],
			'title'              => $translated_post ? $translated_post->post_title : '',
		);
	}

	/* --- translate-posts (bulk) ---------------------------------- */

	private static function register_translate_posts_ability() {
		wp_register_ability( 'ai-translate/translate-posts', array(
			'label'               => __( 'Translate Posts (Bulk)', 'ai-translate-abilities' ),
			'description'         => __( 'Translates multiple posts at once. Continues on individual failures.', 'ai-translate-abilities' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_ids'        => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'minItems' => 1,
						'maxItems' => 50,
						'description' => 'Array of post IDs to translate.',
					),
					'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
					'overwrite'       => array( 'type' => 'boolean', 'description' => 'Overwrite existing translations.', 'default' => false ),
				),
				'required' => array( 'post_ids', 'target_language' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'results'   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'source_post_id'     => array( 'type' => 'integer' ),
								'translated_post_id' => array( 'type' => 'integer' ),
								'status'             => array( 'type' => 'string' ),
								'error'              => array( 'type' => 'string' ),
							),
						),
					),
					'total'     => array( 'type' => 'integer' ),
					'succeeded' => array( 'type' => 'integer' ),
					'failed'    => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => array( static::class, 'execute_translate_posts' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' ) && current_user_can( 'publish_posts' );
			},
			'meta'                => array(
				'show_in_rest' => true,
			),
		) );
	}

	public static function execute_translate_posts( $input ) {
		$results   = array();
		$succeeded = 0;
		$failed    = 0;

		foreach ( $input['post_ids'] as $post_id ) {
			$result = self::translate_post(
				$post_id,
				$input['target_language'],
				'publish',
				$input['overwrite'] ?? false
			);

			if ( is_wp_error( $result ) ) {
				$failed++;
				$results[] = array(
					'source_post_id'     => $post_id,
					'translated_post_id' => 0,
					'status'             => 'failed',
					'error'              => $result->get_error_message(),
				);
			} else {
				$succeeded++;
				$results[] = array(
					'source_post_id'     => $post_id,
					'translated_post_id' => $result,
					'status'             => 'success',
					'error'              => null,
				);
			}
		}

		return array(
			'results'   => $results,
			'total'     => count( $input['post_ids'] ),
			'succeeded' => $succeeded,
			'failed'    => $failed,
		);
	}

	/* --- configure ----------------------------------------------- */

	private static function register_configure_ability() {
		wp_register_ability( 'ai-translate/configure', array(
			'label'               => __( 'Configure AI Translate', 'ai-translate-abilities' ),
			'description'         => __( 'Read or update AI Translate settings (prompt template, meta keys, auto-translate toggle).', 'ai-translate-abilities' ),
			'category'            => 'ai-translation',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'prompt_template'    => array( 'type' => 'string', 'description' => 'Translation prompt template. Use {FROM_CODE} and {TO_CODE} as placeholders.' ),
					'meta_keys_translate' => array( 'type' => 'string', 'description' => 'Whitespace-separated list of meta keys to translate.' ),
					'meta_keys_clear'    => array( 'type' => 'string', 'description' => 'Whitespace-separated list of meta keys to clear.' ),
					'auto_translate_new' => array( 'type' => 'boolean', 'description' => 'Auto-translate new translation posts in Polylang.' ),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'prompt_template'    => array( 'type' => 'string' ),
					'meta_keys_translate' => array( 'type' => 'string' ),
					'meta_keys_clear'    => array( 'type' => 'string' ),
					'auto_translate_new' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => array( static::class, 'execute_configure' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array( 'idempotent' => true ),
			),
		) );
	}

	public static function execute_configure( $input ) {
		if ( isset( $input['prompt_template'] ) ) {
			update_option( 'ai_translate_prompt', sanitize_textarea_field( $input['prompt_template'] ) );
		}
		if ( isset( $input['meta_keys_translate'] ) ) {
			update_option( 'ai_translate_meta_translate', sanitize_textarea_field( $input['meta_keys_translate'] ) );
		}
		if ( isset( $input['meta_keys_clear'] ) ) {
			update_option( 'ai_translate_meta_clear', sanitize_textarea_field( $input['meta_keys_clear'] ) );
		}
		if ( isset( $input['auto_translate_new'] ) ) {
			update_option( 'ai_translate_new_post', $input['auto_translate_new'] ? '1' : '0' );
		}

		// Reset cached meta keys.
		self::$meta_translate = null;
		self::$meta_clear     = null;

		return array(
			'prompt_template'    => get_option( 'ai_translate_prompt', self::$PROMPT ),
			'meta_keys_translate' => get_option( 'ai_translate_meta_translate', '' ),
			'meta_keys_clear'    => get_option( 'ai_translate_meta_clear', '' ),
			'auto_translate_new' => get_option( 'ai_translate_new_post', '1' ) === '1',
		);
	}

	/* ---------------------------------------------------------------
	 * Polylang auto-translate hooks (backward compatibility)
	 * ------------------------------------------------------------- */

	public static function default_title( $title, $post ) {
		$pattern = '/[^\p{L}\p{N}]+$/u';
		return preg_replace( $pattern, '', wp_strip_all_tags( self::translate_field( $title, 'post_title' ) ) );
	}

	public static function default_content( $content, $post ) {
		return self::translate_field( $content, 'post_content' );
	}

	public static function default_excerpt( $excerpt, $post ) {
		return self::translate_field( $excerpt, 'post_excerpt' );
	}

	public static function pll_translate_post_meta( $value, $key, $lang ) {
		if ( in_array( $key, self::meta_clear(), true ) ) {
			$value = '';
		} elseif ( in_array( $key, self::meta_translate(), true ) ) {
			$value = self::translate_field( $value, $key, true );
		}
		return $value;
	}

	/* ---------------------------------------------------------------
	 * Translation core
	 * ------------------------------------------------------------- */

	public static function translate_field( $original, $field = '', $meta = false ) {
		if ( get_option( 'ai_translate_new_post', '0' ) !== '1' ) {
			return $original;
		}
		if ( ! isset( $_GET['new_lang'] ) || ! $_GET['new_lang'] || ! isset( $_GET['from_post'] ) ) {
			return $original;
		}

		$to      = sanitize_key( $_GET['new_lang'] );
		$post_id = absint( $_GET['from_post'] );

		if ( $field ) {
			if ( $meta ) {
				$original = get_post_meta( $post_id, $field, true );
			} else {
				$post     = get_post( $post_id );
				$original = $post->$field;
			}
		}

		$from = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : 'en';

		$translation = self::translate( $original, $to, $from );
		return is_wp_error( $translation ) ? $original : $translation;
	}

	public static function prompt( $to, $from = 'en' ) {
		$template = get_option( 'ai_translate_prompt', self::$PROMPT );
		return str_replace(
			array( '{FROM_CODE}', '{TO_CODE}' ),
			array( $from, $to ),
			$template
		);
	}

	/**
	 * Translate text using the WordPress AI Client.
	 *
	 * @param string $text Text to translate.
	 * @param string $to   Target language code.
	 * @param string $from Source language code.
	 * @return string|\WP_Error Translated text or WP_Error on failure.
	 */
	public static function translate( $text, $to, $from = 'en' ) {
		if ( ! $text || trim( $text ) === '' ) {
			return '';
		}

		$prompt = self::prompt( $to, $from );

		$result = wp_ai_client_prompt( $text )
			->using_system_instruction( $prompt )
			->using_temperature( 0 )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Translate an entire post and create/update the translation via the adapter.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $to        Target language code.
	 * @param string $status    Post status for the translated post.
	 * @param bool   $overwrite Whether to overwrite an existing translation.
	 * @return int|\WP_Error Translated post ID or WP_Error on failure.
	 */
	public static function translate_post( $post_id, $to, $status = 'publish', $overwrite = false ) {
		$adapter = self::get_adapter();
		if ( ! $adapter ) {
			return new \WP_Error( 'no_translation_plugin', __( 'No supported translation plugin is active.', 'ai-translate-abilities' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Source post not found.', 'ai-translate-abilities' ) );
		}

		$from = $adapter->get_post_language( $post_id ) ?? 'en';

		// Translate content.
		$title   = self::translate( $post->post_title, $to, $from );
		$content = self::translate( $post->post_content, $to, $from );
		$excerpt = self::translate( $post->post_excerpt, $to, $from );

		if ( is_wp_error( $title ) ) {
			return $title;
		}
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( is_wp_error( $excerpt ) ) {
			return $excerpt;
		}

		// Process meta fields.
		$meta         = get_post_meta( $post_id );
		$processed_meta = array();
		foreach ( $meta as $key => $values ) {
			$value = maybe_unserialize( $values[0] ?? '' );
			if ( in_array( $key, self::meta_clear(), true ) ) {
				$processed_meta[ $key ] = '';
			} elseif ( in_array( $key, self::meta_translate(), true ) ) {
				$translated_meta = self::translate( $value, $to, $from );
				$processed_meta[ $key ] = is_wp_error( $translated_meta ) ? $value : $translated_meta;
			} else {
				$processed_meta[ $key ] = $value;
			}
		}

		// Create or update via adapter.
		$result = $adapter->create_translation( $post_id, $to, array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'meta'         => $processed_meta,
			'overwrite'    => $overwrite,
		) );

		return $result;
	}

	/* ---------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	private static function meta_keys( $option ) {
		$value = get_option( $option );
		if ( $value ) {
			return preg_split( '/\s+/', $value );
		}
		return array();
	}

	private static function meta_clear() {
		if ( null === self::$meta_clear ) {
			self::$meta_clear = self::meta_keys( 'ai_translate_meta_clear' );
		}
		return self::$meta_clear;
	}

	private static function meta_translate() {
		if ( null === self::$meta_translate ) {
			self::$meta_translate = self::meta_keys( 'ai_translate_meta_translate' );
		}
		return self::$meta_translate;
	}
}

AI_Translate::add_hooks();
