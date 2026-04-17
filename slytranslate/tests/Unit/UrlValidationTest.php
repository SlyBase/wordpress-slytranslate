<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use Brain\Monkey\Functions;

/**
 * Tests for URL schema validation in execute_configure() (Fix 1.3).
 *
 * These tests verify that only http:// and https:// URLs are accepted
 * when configuring the Direct API URL. ftp://, file://, and other schemes
 * must be rejected with a WP_Error (SSRF protection).
 *
 * @see Plan Phase 1.3
 */
class UrlValidationTest extends TestCase {

protected function setUp(): void {
parent::setUp();

// These tests require Fix 1.3 to be implemented. Until then, skip.
$this->markTestSkipped(
'URL schema validation (Fix 1.3) has not been implemented yet. ' .
'Implement the http/https schema check in execute_configure() first.'
);
}

public function test_accepts_https_url(): void {
Functions\when( 'current_user_can' )->justReturn( true );
Functions\when( 'check_admin_referer' )->justReturn( true );
Functions\when( 'update_option' )->justReturn( true );
Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );

$result = AI_Translate::execute_configure( [ 'direct_api_url' => 'https://localhost:11434/v1' ] );
$this->assertNotInstanceOf( \WP_Error::class, $result );
}

public function test_accepts_http_url_for_homelab_compatibility(): void {
Functions\when( 'current_user_can' )->justReturn( true );
Functions\when( 'check_admin_referer' )->justReturn( true );
Functions\when( 'update_option' )->justReturn( true );
Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );

$result = AI_Translate::execute_configure( [ 'direct_api_url' => 'http://192.168.1.10:11434/v1' ] );
$this->assertNotInstanceOf( \WP_Error::class, $result );
}

public function test_rejects_file_scheme(): void {
Functions\when( 'current_user_can' )->justReturn( true );
Functions\when( 'check_admin_referer' )->justReturn( true );
Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );

$result = AI_Translate::execute_configure( [ 'direct_api_url' => 'file:///etc/passwd' ] );
$this->assertInstanceOf( \WP_Error::class, $result );
}

public function test_rejects_ftp_scheme(): void {
Functions\when( 'current_user_can' )->justReturn( true );
Functions\when( 'check_admin_referer' )->justReturn( true );
Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );

$result = AI_Translate::execute_configure( [ 'direct_api_url' => 'ftp://example.com/api' ] );
$this->assertInstanceOf( \WP_Error::class, $result );
}

public function test_rejects_javascript_scheme(): void {
Functions\when( 'current_user_can' )->justReturn( true );
Functions\when( 'check_admin_referer' )->justReturn( true );
Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );

$result = AI_Translate::execute_configure( [ 'direct_api_url' => 'javascript:alert(1)' ] );
$this->assertInstanceOf( \WP_Error::class, $result );
}
}
