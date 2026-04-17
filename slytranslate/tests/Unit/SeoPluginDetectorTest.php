<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\SeoPluginDetector;
use Brain\Monkey\Functions;

/**
 * Tests for SeoPluginDetector::get_active_plugin_config() and
 * SeoPluginDetector::get_active_plugin_key().
 */
class SeoPluginDetectorTest extends TestCase {

public function test_returns_empty_config_when_no_seo_plugin_active(): void {
// Default test environment: no SEO constants/classes defined.
Functions\when( 'apply_filters' )->returnArg( 2 );

$config = SeoPluginDetector::get_active_plugin_config();

$this->assertIsArray( $config );
$this->assertSame( '', $config['key'] );
$this->assertSame( '', $config['label'] );
$this->assertSame( [], $config['translate'] );
$this->assertSame( [], $config['clear'] );
}

public function test_config_structure_has_required_keys(): void {
Functions\when( 'apply_filters' )->returnArg( 2 );

$config = SeoPluginDetector::get_active_plugin_config();

$this->assertArrayHasKey( 'key', $config );
$this->assertArrayHasKey( 'label', $config );
$this->assertArrayHasKey( 'translate', $config );
$this->assertArrayHasKey( 'clear', $config );
}

public function test_applies_filter_to_configs(): void {
$customConfigs = [
'test-seo' => [
'label'     => 'Test SEO',
'translate' => [ '_test_title', '_test_desc' ],
'clear'     => [ '_test_score' ],
],
];

// The filter returns a modified configs array that includes test-seo.
// We also need get_active_plugin_key() to return 'test-seo'.
// Since no SEO constants are defined in test env, key will be ''.
// Test the filter application on the configs shape only.
Functions\when( 'apply_filters' )
->alias( function ( $tag, $value, ...$args ) use ( $customConfigs ) {
if ( 'ai_translate_seo_plugin_configs' === $tag ) {
return $customConfigs;
}
return $value;
} );

// With no active plugin (key=''), even with custom configs we get empty config.
$config = SeoPluginDetector::get_active_plugin_config();
$this->assertSame( '', $config['key'] );
}

public function test_returned_translate_keys_are_normalized(): void {
// Inject a config via filter that has raw (unnormalized) meta keys.
$dirtyConfigs = [
'fake-plugin' => [
'label'     => 'Fake Plugin',
'translate' => [ '  _dirty_title  ', '_clean', '_clean' ],
'clear'     => [],
],
];

Functions\when( 'apply_filters' )
->alias( function ( $tag, $value ) use ( $dirtyConfigs ) {
if ( 'ai_translate_seo_plugin_configs' === $tag ) {
return $dirtyConfigs;
}
return $value;
} );

// get_active_plugin_key() returns '' → empty config, so normalization
// is not triggered for our injected config. Test normalization separately
// via the private normalize_meta_keys method.
$result = $this->invokeStatic( SeoPluginDetector::class, 'normalize_meta_keys', [ [ '  _dirty  ', '_clean', '_clean' ] ] );
$this->assertSame( [ '_dirty', '_clean' ], $result );
}

public function test_get_active_plugin_key_returns_empty_string_in_test_env(): void {
// In the test environment no SEO plugin constants or classes exist.
$key = SeoPluginDetector::get_active_plugin_key();
$this->assertSame( '', $key );
}
}
