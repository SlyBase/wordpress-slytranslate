<?php

declare(strict_types=1);

/**
 * @var array<string, callable>
 */
$GLOBALS['slytranslate_test_function_overrides'] = array();

function slytranslate_test_set_function_behavior( string $function_name, callable $callback ): void {
	$GLOBALS['slytranslate_test_function_overrides'][ $function_name ] = $callback;
}

function slytranslate_test_set_function_return( string $function_name, mixed $value ): void {
	slytranslate_test_set_function_behavior(
		$function_name,
		static function () use ( $value ) {
			return $value;
		}
	);
}

function slytranslate_test_reset_function_overrides(): void {
	$GLOBALS['slytranslate_test_function_overrides'] = array();
}

function slytranslate_test_call_override( string $function_name, array $args, callable $fallback ): mixed {
	$overrides = $GLOBALS['slytranslate_test_function_overrides'] ?? array();
	if ( isset( $overrides[ $function_name ] ) ) {
		return $overrides[ $function_name ]( ...$args );
	}

	return $fallback( ...$args );
}

/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Tests can override any stubbed function through slytranslate_test_set_function_behavior()
 * and slytranslate_test_set_function_return().
 */

// -----------------------------------------------------------------------
// Hooks (called at plugin load time via AI_Translate::add_hooks())
// -----------------------------------------------------------------------

function add_action( ...$args ): void {
	slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return null;
	} );
}
function add_filter( ...$args ): void {
	slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return null;
	} );
}
function do_action( ...$args ): void {
	slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return null;
	} );
}
function register_rest_route( ...$args ): void {
	slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return null;
	} );
}
function register_activation_hook( ...$args ): void {
	slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return null;
	} );
}
function wp_register_ability( ...$args ): void {
	slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return null;
	} );
}
function wp_register_ability_category( ...$args ): void {
	slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return null;
	} );
}

// -----------------------------------------------------------------------
// Pure-PHP equivalents of WP utility functions
// -----------------------------------------------------------------------

function absint( $maybeint ): int {
return abs( (int) $maybeint );
}

function sanitize_key( $key ): string {
return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $str ): string {
return trim( (string) $str );
}

function sanitize_textarea_field( $str ): string {
return trim( (string) $str );
}

function esc_url_raw( $url, $protocols = null ): string {
	return (string) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $url ) {
		return (string) $url;
	} );
}

function wp_strip_all_tags( $string, bool $remove_breaks = false ): string {
$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
$string = strip_tags( $string );
if ( $remove_breaks ) {
$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
}
return trim( $string );
}

function is_wp_error( $thing ): bool {
return $thing instanceof WP_Error;
}

function wp_parse_url( $url, $component = -1 ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $url, $component = -1 ) {
		return parse_url( $url, $component );
	} );
}

function __( $text, $domain = null ): string {
return (string) $text;
}

function trailingslashit( $value ): string {
return rtrim( (string) $value, "/\\" ) . '/';
}

function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
return json_encode( $value, $flags, $depth );
}

function wp_remote_post( $url, $args = [] ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return new WP_Error( 'wp_remote_post_not_mocked', 'wp_remote_post was not mocked in this test.' );
	} );
}

function wp_remote_get( $url, $args = [] ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return new WP_Error( 'wp_remote_get_not_mocked', 'wp_remote_get was not mocked in this test.' );
	} );
}

function wp_remote_retrieve_response_code( $response ): int {
return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ): string {
return isset( $response['body'] ) ? (string) $response['body'] : '';
}

function get_transient( $key ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return false;
	} );
}

function set_transient( $key, $value, $expiration = 0 ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return true;
	} );
}

function delete_transient( $key ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return true;
	} );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
define( 'HOUR_IN_SECONDS', 3600 );
}

// -----------------------------------------------------------------------
// Functions with configurable defaults (overridable per-test via Brain Monkey)
// -----------------------------------------------------------------------

function get_option( $option, $default = false ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $option, $default = false ) {
		return $default;
	} );
}

function update_option( $option, $value, $autoload = null ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return true;
	} );
}

function delete_option( $option ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return true;
	} );
}

function apply_filters( $hook_name, $value, ...$args ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $hook_name, $value ) {
		return $value;
	} );
}

function get_locale(): string {
	return (string) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return 'en_US';
	} );
}

function get_current_user_id(): int {
	return (int) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return 0;
	} );
}

function update_user_meta( int $user_id, string $meta_key, mixed $meta_value ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return true;
	} );
}

function current_user_can( string $capability, ...$args ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return false;
	} );
}

function post_type_exists( string $post_type ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return false;
	} );
}

function get_post( $post = null ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return null;
	} );
}

function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return array();
	} );
}

function get_edit_post_link( int $post_id = 0, string $context = 'display' ): string {
	return (string) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return '';
	} );
}

function maybe_unserialize( $data ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $data ) {
		return $data;
	} );
}

function serialize_blocks( array $blocks ): string {
	return (string) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return '';
	} );
}

function wp_ai_client_prompt( string $text ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		throw new RuntimeException( 'wp_ai_client_prompt was not mocked in this test.' );
	} );
}

function get_post_status( $post = null ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $post = null ) {
		if ( $post instanceof WP_Post ) {
			return $post->post_status;
		}
		return 'publish';
	} );
}

function post_status_exists( $post_status ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $post_status ) {
		$known = [ 'publish', 'draft', 'pending', 'private', 'future', 'inherit', 'trash', 'auto-draft' ];
		return in_array( (string) $post_status, $known, true );
	} );
}

function get_post_status_object( $post_status ): ?\stdClass {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $post_status ) {
		$known = [ 'publish', 'draft', 'pending', 'private', 'future', 'inherit', 'trash', 'auto-draft' ];
		if ( in_array( (string) $post_status, $known, true ) ) {
			$obj = new \stdClass();
			$obj->name = $post_status;
			return $obj;
		}
		return null;
	} );
}
