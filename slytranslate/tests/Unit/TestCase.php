<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Brain\Monkey;

/**
 * Base test case for all AI_Translate unit tests.
 *
 * Provides Brain Monkey setup/teardown and a helper for invoking private
 * static methods via reflection.
 */
abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke a private or protected static method via reflection.
	 *
	 * @param class-string $class  Fully-qualified class name.
	 * @param string       $method Method name.
	 * @param mixed[]      $args   Arguments to pass to the method.
	 * @return mixed
	 */
	protected function invokeStatic( string $class, string $method, array $args = [] ): mixed {
		$reflection = new \ReflectionMethod( $class, $method );
		return $reflection->invoke( null, ...$args );
	}
}
