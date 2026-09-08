<?php
/**
 * PHPUnit bootstrap for the test-only real-runtime harness.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

if ( ! defined( 'GRAVITY_NOTIFY_TEST_NO_SEND' ) ) {
	define( 'GRAVITY_NOTIFY_TEST_NO_SEND', true );
}
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Intentional core network safeguard.
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
}

$gravity_notify_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $gravity_notify_tests_dir ) || '' === $gravity_notify_tests_dir ) {
	$gravity_notify_tests_dir = '/wordpress-phpunit';
}

require_once $gravity_notify_tests_dir . '/includes/functions.php';
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once WP_PLUGIN_DIR . '/gravityforms/gravityforms.php';
		require_once WP_PLUGIN_DIR . '/gravityflow/gravityflow.php';
	}
);
require_once $gravity_notify_tests_dir . '/includes/bootstrap.php';
require_once WP_PLUGIN_DIR . '/gravity-notification-manager-source/vendor/autoload.php';
require_once __DIR__ . '/test-plugin.php';
