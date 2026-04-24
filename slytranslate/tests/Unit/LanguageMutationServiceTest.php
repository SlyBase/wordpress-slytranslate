<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\TranslationMutationAdapter;
use AI_Translate\TranslationPluginAdapter;

class LanguageMutationServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
	}

	protected function tearDown(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );
		parent::tearDown();
	}

	public function test_execute_set_post_language_happy_path_with_relink(): void {
		$adapter = $this->createMutationAdapter(
			array( 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Francais' ),
			array( 100 => 'en' ),
			array( 'en' => 100, 'fr' => 200 ),
			true
		);

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubMutationPreconditions();

		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id'         => 100,
				'target_language' => 'de',
				'relink'          => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'en', $result['source_language'] );
		$this->assertSame( 'de', $result['target_language'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( array( 'fr' => 200, 'de' => 100 ), $result['translations'] );
		$this->assertSame( 1, $adapter->setCalls );
		$this->assertSame( 1, $adapter->relinkCalls );
		$this->assertSame( array( 'fr' => 200, 'de' => 100 ), $adapter->lastRelinkMap );
	}

	public function test_execute_set_post_language_rejects_same_language(): void {
		$adapter = $this->createMutationAdapter(
			array( 'en' => 'English', 'de' => 'Deutsch' ),
			array( 100 => 'de' ),
			array( 'de' => 100 ),
			true
		);

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubMutationPreconditions();

		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id'         => 100,
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'language_already_set', $result->get_error_code() );
		$this->assertSame( 0, $adapter->setCalls );
	}

	public function test_execute_set_post_language_rejects_conflict_without_force(): void {
		$adapter = $this->createMutationAdapter(
			array( 'en' => 'English', 'de' => 'Deutsch' ),
			array( 100 => 'en' ),
			array( 'en' => 100, 'de' => 555 ),
			true
		);

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubMutationPreconditions();

		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id'         => 100,
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'language_conflict', $result->get_error_code() );
		$this->assertSame( 0, $adapter->setCalls );
	}

	public function test_execute_set_post_language_rejects_invalid_target_language(): void {
		$adapter = $this->createMutationAdapter(
			array( 'en' => 'English', 'de' => 'Deutsch' ),
			array( 100 => 'en' ),
			array( 'en' => 100 ),
			true
		);

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubMutationPreconditions();

		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id'         => 100,
				'target_language' => 'it',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_target_language', $result->get_error_code() );
		$this->assertSame( 0, $adapter->setCalls );
	}

	public function test_execute_set_post_language_allows_conflict_with_force_opt_in(): void {
		$adapter = $this->createMutationAdapter(
			array( 'en' => 'English', 'de' => 'Deutsch' ),
			array( 100 => 'en' ),
			array( 'en' => 100, 'de' => 555 ),
			true
		);

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubMutationPreconditions();

		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id'         => 100,
				'target_language' => 'de',
				'force'           => true,
				'relink'          => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'de', $result['target_language'] );
		$this->assertSame( array( 'de' => 100 ), $result['translations'] );
		$this->assertSame( 1, $adapter->setCalls );
		$this->assertSame( 1, $adapter->relinkCalls );
	}

	public function test_execute_set_post_language_rejects_forbidden_post(): void {
		$adapter = $this->createMutationAdapter(
			array( 'en' => 'English', 'de' => 'Deutsch' ),
			array( 100 => 'en' ),
			array( 'en' => 100 ),
			true
		);

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubMutationPreconditions( false );

		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id'         => 100,
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden_post', $result->get_error_code() );
	}

	public function test_execute_set_post_language_rejects_when_no_translation_plugin_is_active(): void {
		$this->setStaticProperty( AI_Translate::class, 'adapter', null );

		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id'         => 100,
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_translation_plugin', $result->get_error_code() );
	}

	public function test_execute_set_post_language_returns_unsupported_when_mutation_adapter_is_missing(): void {
		$adapter = new class() implements TranslationPluginAdapter {
			public function is_available(): bool {
				return true;
			}

			public function get_languages(): array {
				return array( 'en' => 'English', 'de' => 'Deutsch' );
			}

			public function get_post_language( int $post_id ): ?string {
				return 'en';
			}

			public function get_post_translations( int $post_id ): array {
				return array( 'en' => $post_id );
			}

			public function create_translation( int $source_post_id, string $target_lang, array $data ) {
				return 0;
			}

			public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
				return true;
			}
		};

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubMutationPreconditions();

		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id'         => 100,
				'target_language' => 'de',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unsupported_language_mutation', $result->get_error_code() );
	}

	public function test_execute_set_post_language_keeps_relations_when_relink_is_disabled(): void {
		$adapter = $this->createMutationAdapter(
			array( 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Francais' ),
			array( 100 => 'en' ),
			array( 'en' => 100, 'fr' => 200 ),
			false
		);

		$this->setStaticProperty( AI_Translate::class, 'adapter', $adapter );
		$this->stubMutationPreconditions();

		$result = AI_Translate::execute_set_post_language(
			array(
				'post_id'         => 100,
				'target_language' => 'de',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'en' => 100, 'fr' => 200 ), $result['translations'] );
		$this->assertSame( 1, $adapter->setCalls );
		$this->assertSame( 0, $adapter->relinkCalls );
	}

	private function stubMutationPreconditions( bool $can_edit = true ): void {
		$this->stubWpFunction(
			'get_post',
			static function ( int $post_id ): ?\WP_Post {
				if ( 100 !== $post_id ) {
					return null;
				}

				return new \WP_Post(
					array(
						'ID'        => 100,
						'post_type' => 'post',
						'post_title' => 'Demo Post',
					)
				);
			}
		);

		$this->stubWpFunction(
			'current_user_can',
			static function ( string $capability, ...$args ) use ( $can_edit ): bool {
				if ( 'edit_post' === $capability ) {
					return $can_edit;
				}

				return false;
			}
		);

		$this->stubWpFunction(
			'post_type_exists',
			static function ( string $post_type ): bool {
				return 'post' === $post_type;
			}
		);

		$this->stubWpFunction(
			'get_post_status',
			static function ( $post = null ) {
				if ( 0 === $post ) {
					return false;
				}

				return 'publish';
			}
		);

		$this->stubWpFunction(
			'get_edit_post_link',
			static function ( int $post_id, string $context = 'display' ): string {
				return 'https://example.test/wp-admin/post.php?post=' . $post_id;
			}
		);
	}

	private function createMutationAdapter(
		array $languages,
		array $post_languages,
		array $translations,
		bool $supports_relink
	): TranslationPluginAdapter {
		return new class( $languages, $post_languages, $translations, $supports_relink ) implements TranslationPluginAdapter, TranslationMutationAdapter {
			/** @var array<string, string> */
			private array $languages;

			/** @var array<int, string> */
			private array $postLanguages;

			/** @var array<string, int> */
			private array $translations;

			public bool $supportsSet = true;
			public bool $supportsRelink;
			public mixed $setResult = true;
			public mixed $relinkResult = true;
			public int $setCalls = 0;
			public int $relinkCalls = 0;

			/** @var array<string, int> */
			public array $lastRelinkMap = array();

			/**
			 * @param array<string, string> $languages
			 * @param array<int, string>    $post_languages
			 * @param array<string, int>    $translations
			 */
			public function __construct( array $languages, array $post_languages, array $translations, bool $supports_relink ) {
				$this->languages      = $languages;
				$this->postLanguages  = $post_languages;
				$this->translations   = $translations;
				$this->supportsRelink = $supports_relink;
			}

			public function is_available(): bool {
				return true;
			}

			public function get_languages(): array {
				return $this->languages;
			}

			public function get_post_language( int $post_id ): ?string {
				return $this->postLanguages[ $post_id ] ?? null;
			}

			public function get_post_translations( int $post_id ): array {
				return $this->translations;
			}

			public function create_translation( int $source_post_id, string $target_lang, array $data ) {
				return 0;
			}

			public function link_translation( int $source_post_id, int $translated_post_id, string $target_lang ): bool {
				return true;
			}

			public function supports_mutation_capability( string $capability ): bool {
				if ( TranslationMutationAdapter::CAPABILITY_SET_POST_LANGUAGE === $capability ) {
					return $this->supportsSet;
				}

				if ( TranslationMutationAdapter::CAPABILITY_RELINK_TRANSLATION === $capability ) {
					return $this->supportsRelink;
				}

				return false;
			}

			public function set_post_language( int $post_id, string $target_language ) {
				$this->setCalls++;
				if ( $this->setResult instanceof \WP_Error ) {
					return $this->setResult;
				}

				if ( false === $this->setResult ) {
					return false;
				}

				$this->postLanguages[ $post_id ] = $target_language;

				return true;
			}

			public function relink_post_translations( array $translations ) {
				$this->relinkCalls++;
				$this->lastRelinkMap = $translations;

				if ( $this->relinkResult instanceof \WP_Error ) {
					return $this->relinkResult;
				}

				if ( false === $this->relinkResult ) {
					return false;
				}

				$this->translations = $translations;

				return true;
			}
		};
	}
}
