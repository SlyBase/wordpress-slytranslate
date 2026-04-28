<?php

declare(strict_types=1);

namespace SlyTranslate {

	function pll_set_post_language( int $post_id, string $lang ): bool {
		return \SlyTranslate\Tests\Unit\PolylangAdapterTestDouble::invokeSetPostLanguage( $post_id, $lang );
	}

	function pll_get_post_language( int $post_id ) {
		return \SlyTranslate\Tests\Unit\PolylangAdapterTestDouble::invokeGetPostLanguage( $post_id );
	}

	function pll_save_post_translations( array $translations ): bool {
		return \SlyTranslate\Tests\Unit\PolylangAdapterTestDouble::invokeSavePostTranslations( $translations );
	}
}

namespace SlyTranslate\Tests\Unit {

	use SlyTranslate\PolylangAdapter;
	use SlyTranslate\TranslationMutationAdapter;

	class PolylangAdapterTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			PolylangAdapterTestDouble::resetCallbacks();
		}

		protected function tearDown(): void {
			PolylangAdapterTestDouble::resetCallbacks();
			parent::tearDown();
		}

		public function test_set_post_language_returns_true_for_no_op_target_language(): void {
			$adapter   = new PolylangAdapterTestDouble();
			$set_calls = 0;

			PolylangAdapterTestDouble::$get_post_language_callback = static function ( int $post_id ): string {
				return 'de';
			};
			PolylangAdapterTestDouble::$set_post_language_callback = static function ( int $post_id, string $lang ) use ( &$set_calls ): bool {
				$set_calls++;
				return false;
			};

			$result = $adapter->set_post_language( 1283, 'de' );

			$this->assertTrue( $result );
			$this->assertSame( 0, $set_calls );
		}

		public function test_set_post_language_accepts_polylang_false_when_language_was_still_changed(): void {
			$adapter  = new PolylangAdapterTestDouble();
			$language = 'en';

			PolylangAdapterTestDouble::$get_post_language_callback = static function ( int $post_id ) use ( &$language ): string {
				return $language;
			};
			PolylangAdapterTestDouble::$set_post_language_callback = static function ( int $post_id, string $lang ) use ( &$language ): bool {
				$language = $lang;
				return false;
			};

			$result = $adapter->set_post_language( 1283, 'de' );

			$this->assertTrue( $result );
			$this->assertSame( 'de', $language );
		}

		public function test_set_post_language_returns_error_when_polylang_does_not_change_language(): void {
			$adapter  = new PolylangAdapterTestDouble();
			$language = 'en';

			PolylangAdapterTestDouble::$get_post_language_callback = static function ( int $post_id ) use ( &$language ): string {
				return $language;
			};
			PolylangAdapterTestDouble::$set_post_language_callback = static function ( int $post_id, string $lang ): bool {
				return false;
			};

			$result = $adapter->set_post_language( 1283, 'de' );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'polylang_update_failed', $result->get_error_code() );
		}

		public function test_link_translation_returns_false_when_polylang_relink_fails(): void {
			$adapter   = new PolylangAdapterTestDouble();
			$last_map  = array();

			PolylangAdapterTestDouble::$get_post_language_callback = static function ( int $post_id ): string {
				return 'en';
			};
			PolylangAdapterTestDouble::$save_post_translations_callback = static function ( array $translations ) use ( &$last_map ): bool {
				$last_map = $translations;
				return false;
			};

			$result = $adapter->link_translation( 100, 200, 'de' );

			$this->assertFalse( $result );
			$this->assertSame( array( 'en' => 100, 'de' => 200 ), $last_map );
		}
	}

	class PolylangAdapterTestDouble extends PolylangAdapter {
		/** @var callable|null */
		public static $get_post_language_callback = null;

		/** @var callable|null */
		public static $set_post_language_callback = null;

		/** @var callable|null */
		public static $save_post_translations_callback = null;

		public static function resetCallbacks(): void {
			self::$get_post_language_callback = null;
			self::$set_post_language_callback = null;
			self::$save_post_translations_callback = null;
		}

		public static function invokeSetPostLanguage( int $post_id, string $lang ): bool {
			if ( is_callable( self::$set_post_language_callback ) ) {
				return (bool) call_user_func( self::$set_post_language_callback, $post_id, $lang );
			}

			return true;
		}

		public static function invokeGetPostLanguage( int $post_id ) {
			if ( is_callable( self::$get_post_language_callback ) ) {
				return call_user_func( self::$get_post_language_callback, $post_id );
			}

			return false;
		}

		public static function invokeSavePostTranslations( array $translations ): bool {
			if ( is_callable( self::$save_post_translations_callback ) ) {
				return (bool) call_user_func( self::$save_post_translations_callback, $translations );
			}

			return true;
		}

		public function is_available(): bool {
			return true;
		}

		public function supports_mutation_capability( string $capability ): bool {
			return TranslationMutationAdapter::CAPABILITY_SET_POST_LANGUAGE === $capability;
		}

		public function get_post_language( int $post_id ): ?string {
			if ( ! is_callable( self::$get_post_language_callback ) ) {
				return null;
			}

			$language = call_user_func( self::$get_post_language_callback, $post_id );
			if ( ! is_string( $language ) || '' === $language ) {
				return null;
			}

			return $language;
		}
	}
}
