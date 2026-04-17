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
