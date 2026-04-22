<?php

declare(strict_types=1);

namespace AI_Translate\Tests\Unit;

use AI_Translate\AI_Translate;
use AI_Translate\ConfigurationService;
use Brain\Monkey\Functions;

/**
 * Tests for URL schema and SSRF validation in ConfigurationService::validate_direct_api_url().
 */
class UrlValidationTest extends TestCase {

	public function test_accepts_https_url(): void {
		Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => \parse_url( $url, $component ) );
		Functions\when( 'apply_filters' )->justReturn( false );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'https://93.184.216.34/v1' ] );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_accepts_http_url_for_homelab_compatibility(): void {
		Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => \parse_url( $url, $component ) );
		Functions\when( 'apply_filters' )->justReturn( true );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'http://192.168.1.10:11434/v1' ] );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_file_scheme(): void {
		Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => \parse_url( $url, $component ) );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'file:///etc/passwd' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_ftp_scheme(): void {
		Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => \parse_url( $url, $component ) );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'ftp://example.com/api' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_javascript_scheme(): void {
		Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => \parse_url( $url, $component ) );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'javascript:alert(1)' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_internal_ip_ssrf(): void {
		Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => \parse_url( $url, $component ) );
		Functions\when( 'apply_filters' )->justReturn( false );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'http://127.0.0.1:11434' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden_direct_api_url', $result->get_error_code() );
	}

	public function test_allows_internal_ip_with_filter(): void {
		Functions\when( 'esc_url_raw' )->alias( fn( $url ) => $url );
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => \parse_url( $url, $component ) );
		Functions\when( 'apply_filters' )->justReturn( true );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'http://127.0.0.1:11434' ] );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}
}
