<?php
/**
 * Static-analysis-only symbols for external WordPress, Gravity Forms, and Gravity Flow APIs.
 *
 * This file is scanned by PHPStan and is never loaded by the plugin runtime.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

class WP_Error {}

class GFFeedAddOn {
	public function init() {}
}

abstract class Gravity_Flow_Step_Feed_Add_On {}

final class Gravity_Flow_Steps {
	public static function register( $step ): void {}
}

function plugin_dir_path( string $file ): string { return $file; }
function plugin_dir_url( string $file ): string { return $file; }
function plugin_basename( string $file ): string { return $file; }
function add_action( string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool { return true; }
function remove_action( string $hook_name, mixed $callback, int $priority = 10 ): bool { return true; }
function esc_html_e( string $text, string $domain = 'default' ): void {}
function register_activation_hook( string $file, mixed $callback ): void {}
function register_deactivation_hook( string $file, mixed $callback ): void {}
function register_uninstall_hook( string $file, mixed $callback ): void {}
function __( string $text, string $domain = 'default' ): string { return $text; }
function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed { return $value; }
function wp_unslash( mixed $value ): mixed { return $value; }
function get_option( string $option, mixed $default_value = false ): mixed { return $default_value; }
function rest_sanitize_boolean( mixed $value ): bool { return (bool) $value; }
function esc_url_raw( string $url ): string { return $url; }
function sanitize_textarea_field( string $value ): string { return $value; }
function sanitize_text_field( string $value ): string { return $value; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function sanitize_key( string $key ): string { return $key; }
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
function add_option( string $option, mixed $value = '', string $deprecated = '', mixed $autoload = null ): bool { return true; }
function wp_next_scheduled( string $hook, array $args = array() ): int|false { return false; }
function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array(), bool $wp_error = false ): bool|WP_Error { return true; }
function wp_clear_scheduled_hook( string $hook, array $args = array(), bool $wp_error = false ): int|false|WP_Error { return 0; }
function delete_option( string $option ): bool { return true; }
function dbDelta( string|array $queries = '', bool $execute = true ): array { return array(); }
function current_time( string $type, bool $gmt = false ): int|string { return 0; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function esc_url( string $url, ?array $protocols = null, string $_context = 'display' ): string { return $url; }
function admin_url( string $path = '', string $scheme = 'admin' ): string { return $path; }
function settings_fields( string $option_group ): void {}
function esc_attr( mixed $text ): string { return (string) $text; }
function submit_button( mixed ...$args ): void {}
function check_admin_referer( mixed ...$args ): int|false { return 1; }
function add_query_arg( mixed ...$args ): string { return ''; }
function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): bool { return true; }
function esc_html( mixed $text ): string { return (string) $text; }
function wp_nonce_field( mixed ...$args ): string { return ''; }
function wp_remote_post( string $url, array $args = array() ): array|WP_Error { return array(); }
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function wp_remote_retrieve_response_code( array|WP_Error $response ): int|string { return 200; }
function wp_remote_retrieve_body( array|WP_Error $response ): string { return ''; }
function current_user_can( string $capability, mixed ...$args ): bool { return true; }
function wp_die( mixed ...$args ): void {}
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
