<?php

declare(strict_types=1);

namespace SlyTranslate\Tests\Unit;

use SlyTranslate\AI_Translate;
use SlyTranslate\ConfigurationService;

/**
 * Tests for URL schema and SSRF validation in ConfigurationService::validate_direct_api_url().
 */
class UrlValidationTest extends TestCase {

	public function test_accepts_https_url(): void {
		$this->stubWpFunction( 'esc_url_raw', fn( $url ) => $url );
		$this->stubWpFunction( 'wp_parse_url', fn( $url, $component = -1 ) => \parse_url( $url, $component ) );
		$this->stubWpFunctionReturn( 'apply_filters', false );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'https://93.184.216.34/v1' ] );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_accepts_http_url_for_homelab_compatibility(): void {
		$this->stubWpFunction( 'esc_url_raw', fn( $url ) => $url );
		$this->stubWpFunction( 'wp_parse_url', fn( $url, $component = -1 ) => \parse_url( $url, $component ) );
		$this->stubWpFunctionReturn( 'apply_filters', true );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'http://192.168.1.10:11434/v1' ] );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_file_scheme(): void {
		$this->stubWpFunction( 'esc_url_raw', fn( $url ) => $url );
		$this->stubWpFunction( 'wp_parse_url', fn( $url, $component = -1 ) => \parse_url( $url, $component ) );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'file:///etc/passwd' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_ftp_scheme(): void {
		$this->stubWpFunction( 'esc_url_raw', fn( $url ) => $url );
		$this->stubWpFunction( 'wp_parse_url', fn( $url, $component = -1 ) => \parse_url( $url, $component ) );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'ftp://example.com/api' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_javascript_scheme(): void {
		$this->stubWpFunction( 'esc_url_raw', fn( $url ) => $url );
		$this->stubWpFunction( 'wp_parse_url', fn( $url, $component = -1 ) => \parse_url( $url, $component ) );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'javascript:alert(1)' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_rejects_internal_ip_ssrf(): void {
		$this->stubWpFunction( 'esc_url_raw', fn( $url ) => $url );
		$this->stubWpFunction( 'wp_parse_url', fn( $url, $component = -1 ) => \parse_url( $url, $component ) );
		$this->stubWpFunctionReturn( 'apply_filters', false );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'http://127.0.0.1:11434' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'forbidden_direct_api_url', $result->get_error_code() );
	}

	public function test_allows_internal_ip_with_filter(): void {
		$this->stubWpFunction( 'esc_url_raw', fn( $url ) => $url );
		$this->stubWpFunction( 'wp_parse_url', fn( $url, $component = -1 ) => \parse_url( $url, $component ) );
		$this->stubWpFunctionReturn( 'apply_filters', true );

		$result = $this->invokeStatic( ConfigurationService::class, 'validate_direct_api_url', [ 'http://127.0.0.1:11434' ] );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_probe_direct_api_kwargs_disables_redirects_and_rejects_unsafe_urls(): void {
		$captured_args = null;

		$this->stubWpFunction(
			'wp_remote_post',
			static function ( string $url, array $args ) use ( &$captured_args ): array {
				$captured_args = $args;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"choices":[{"message":{"content":"cat"}}]}',
				);
			}
		);

		$result = ConfigurationService::probe_direct_api_kwargs( 'https://api.example.test', 'model/test' );

		$this->assertTrue( $result );
		$this->assertIsArray( $captured_args );
		$this->assertSame( 0, $captured_args['redirection'] ?? null );
		$this->assertTrue( $captured_args['reject_unsafe_urls'] ?? false );
	}

	public function test_probe_direct_api_context_windows_disables_redirects_and_rejects_unsafe_urls(): void {
		$captured_args = null;

		$this->stubWpFunction(
			'wp_remote_get',
			static function ( string $url, array $args ) use ( &$captured_args ): array {
				$captured_args = $args;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"data":[{"id":"model/test","context_window":8192}]}',
				);
			}
		);

		$result = ConfigurationService::probe_direct_api_context_windows( 'https://api.example.test' );

		$this->assertSame( array( 'model/test' => 8192 ), $result );
		$this->assertIsArray( $captured_args );
		$this->assertSame( 0, $captured_args['redirection'] ?? null );
		$this->assertTrue( $captured_args['reject_unsafe_urls'] ?? false );
	}
}
