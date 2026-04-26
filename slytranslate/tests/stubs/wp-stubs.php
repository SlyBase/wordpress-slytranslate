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

function rest_url( $path = '', $scheme = 'rest' ): string {
	return (string) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $path = '' ) {
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	} );
}

function wp_create_nonce( $action = -1 ): string {
	return (string) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return 'test-nonce';
	} );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return true;
	} );
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return true;
	} );
}

function plugins_url( $path = '', $plugin = '' ): string {
	return (string) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $path = '' ) {
		$base = 'https://example.test/wp-content/plugins/slytranslate/';
		return $base . ltrim( (string) $path, '/' );
	} );
}

function plugin_dir_path( $file ): string {
	return (string) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $file ) {
		return rtrim( dirname( (string) $file ), '/\\' ) . '/';
	} );
}

function wp_enqueue_script( ...$args ): void {
	slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return null;
	} );
}

function wp_enqueue_style( ...$args ): void {
	slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return null;
	} );
}

function wp_localize_script( ...$args ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return true;
	} );
}

function wp_set_script_translations( ...$args ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return true;
	} );
}

function register_setting( ...$args ): void {
	slytranslate_test_call_override( __FUNCTION__, $args, static function () {
		return null;
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

function esc_html__( $text, $domain = null ): string {
	return (string) __( $text, $domain );
}

function esc_html_x( $text, $context, $domain = null ): string {
	return (string) $text;
}

function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function _n( $single, $plural, $number, $domain = null ): string {
	return (int) $number === 1 ? (string) $single : (string) $plural;
}

function number_format_i18n( $number, $decimals = 0 ): string {
	return number_format( (float) $number, (int) $decimals, '.', ',' );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return stripslashes( (string) $value );
}

function add_query_arg( ...$args ): string {
	return (string) slytranslate_test_call_override( __FUNCTION__, $args, static function () use ( $args ) {
		if ( count( $args ) >= 3 && is_array( $args[0] ) ) {
			$params = $args[0];
			$base   = (string) $args[2];
			$query  = http_build_query( $params );
			return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . $query;
		}

		if ( count( $args ) >= 3 ) {
			$key   = (string) $args[0];
			$value = (string) $args[1];
			$base  = (string) $args[2];
			$query = http_build_query( array( $key => $value ) );
			return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . $query;
		}

		return '';
	} );
}

function admin_url( $path = '', $scheme = 'admin' ): string {
	return (string) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	} );
}

function wp_safe_redirect( $location, int $status = 302, $x_redirect_by = 'WordPress' ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return true;
	} );
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

function wp_rand( int $min = 0, int $max = 4294967295 ): int {
	return (int) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function ( int $min = 0, int $max = 4294967295 ) {
		return random_int( $min, $max );
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

function get_user_meta( int $user_id, string $key = '', bool $single = false ) {
	return slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return '';
	} );
}

function current_user_can( string $capability, ...$args ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return false;
	} );
}

function is_admin(): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return true;
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

function delete_post_meta( int $post_id, string $meta_key, $meta_value = '' ): bool {
	return (bool) slytranslate_test_call_override( __FUNCTION__, func_get_args(), static function () {
		return true;
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
