<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for all AI_Translate unit tests.
 *
 * Provides lightweight WordPress function stubs and a helper for invoking
 * private static methods via reflection.
 */
abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		\slytranslate_test_reset_function_overrides();
	}

	protected function tearDown(): void {
		\slytranslate_test_reset_function_overrides();
		parent::tearDown();
	}

	protected function stubWpFunction( string $function_name, callable $callback ): void {
		\slytranslate_test_set_function_behavior( $function_name, $callback );
	}

	protected function stubWpFunctionReturn( string $function_name, mixed $value ): void {
		\slytranslate_test_set_function_return( $function_name, $value );
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

	protected function setStaticProperty( string $class, string $property, mixed $value ): void {
		$reflection = new \ReflectionProperty( $class, $property );
		$reflection->setValue( null, $value );
	}

	protected function getStaticProperty( string $class, string $property ): mixed {
		$reflection = new \ReflectionProperty( $class, $property );
		return $reflection->getValue();
	}
}
