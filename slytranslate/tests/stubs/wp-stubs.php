<?php

declare(strict_types=1);

/**
 * Minimal WordPress function stubs for unit tests.
 *
 * This file must be required AFTER vendor/autoload.php so that Patchwork
 * can intercept and make these functions patchable per test via Brain Monkey.
 */

// -----------------------------------------------------------------------
// Hooks (called at plugin load time via AI_Translate::add_hooks())
// -----------------------------------------------------------------------

function add_action( ...$args ): void {}
function add_filter( ...$args ): void {}
function do_action( ...$args ): void {}
function register_rest_route( ...$args ): void {}
function register_activation_hook( ...$args ): void {}
function wp_register_ability( ...$args ): void {}
function wp_register_ability_category( ...$args ): void {}

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
return (string) $url;
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
return parse_url( $url, $component );
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
return new WP_Error( 'wp_remote_post_not_mocked', 'wp_remote_post was not mocked in this test.' );
}

function wp_remote_get( $url, $args = [] ) {
return new WP_Error( 'wp_remote_get_not_mocked', 'wp_remote_get was not mocked in this test.' );
}

function wp_remote_retrieve_response_code( $response ): int {
return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ): string {
return isset( $response['body'] ) ? (string) $response['body'] : '';
}

function get_transient( $key ) {
return false;
}

function set_transient( $key, $value, $expiration = 0 ): bool {
return true;
}

function delete_transient( $key ): bool {
return true;
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
return $default;
}

function update_option( $option, $value, $autoload = null ): bool {
return true;
}

function delete_option( $option ): bool {
return true;
}

function apply_filters( $hook_name, $value, ...$args ) {
return $value;
}

function get_locale(): string {
return 'en_US';
}

function get_current_user_id(): int {
return 0;
}

function update_user_meta( int $user_id, string $meta_key, mixed $meta_value ): bool {
return true;
}

function get_post_status( $post = null ) {
if ( $post instanceof WP_Post ) {
return $post->post_status;
}
return 'publish';
}

function post_status_exists( $post_status ): bool {
$known = [ 'publish', 'draft', 'pending', 'private', 'future', 'inherit', 'trash', 'auto-draft' ];
return in_array( (string) $post_status, $known, true );
}

function get_post_status_object( $post_status ): ?\stdClass {
$known = [ 'publish', 'draft', 'pending', 'private', 'future', 'inherit', 'trash', 'auto-draft' ];
if ( in_array( (string) $post_status, $known, true ) ) {
$obj = new \stdClass();
$obj->name = $post_status;
return $obj;
}
return null;
}
