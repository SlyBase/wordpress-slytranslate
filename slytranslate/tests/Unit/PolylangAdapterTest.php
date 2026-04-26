<?php

declare(strict_types=1);

namespace AI_Translate {

	function pll_set_post_language( int $post_id, string $lang ): bool {
		return \AI_Translate\Tests\Unit\PolylangAdapterTestDouble::invokeSetPostLanguage( $post_id, $lang );
	}
}

namespace AI_Translate\Tests\Unit {

	use AI_Translate\PolylangAdapter;
	use AI_Translate\TranslationMutationAdapter;

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
	}

	class PolylangAdapterTestDouble extends PolylangAdapter {
		/** @var callable|null */
		public static $get_post_language_callback = null;

		/** @var callable|null */
		public static $set_post_language_callback = null;

		public static function resetCallbacks(): void {
			self::$get_post_language_callback = null;
			self::$set_post_language_callback = null;
		}

		public static function invokeSetPostLanguage( int $post_id, string $lang ): bool {
			if ( is_callable( self::$set_post_language_callback ) ) {
				return (bool) call_user_func( self::$set_post_language_callback, $post_id, $lang );
			}

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
