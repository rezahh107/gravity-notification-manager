<?php
/**
 * PHPUnit bootstrap for the explicitly authorized live IPPanel PR validation.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

if ( defined( 'GRAVITY_NOTIFY_TEST_NO_SEND' ) ) {
	throw new RuntimeException( 'Live validation must not inherit deterministic no-send mode.' );
}

$gravity_notify_config_path = dirname( __DIR__, 3 ) . '/.wp-env.runtime/live-config.json';
if ( ! is_readable( $gravity_notify_config_path ) ) {
	throw new RuntimeException( 'Live validation configuration is unavailable.' );
}
$gravity_notify_config_raw = file_get_contents( $gravity_notify_config_path );
$gravity_notify_live_config = is_string( $gravity_notify_config_raw ) ? json_decode( $gravity_notify_config_raw, true ) : null;
if ( ! is_array( $gravity_notify_live_config ) ) {
	throw new RuntimeException( 'Live validation configuration is malformed.' );
}
foreach ( array( 'api_key', 'from', 'to', 'run_id', 'head' ) as $gravity_notify_required_key ) {
	if ( ! isset( $gravity_notify_live_config[ $gravity_notify_required_key ] ) || ! is_string( $gravity_notify_live_config[ $gravity_notify_required_key ] ) || '' === $gravity_notify_live_config[ $gravity_notify_required_key ] ) {
		throw new RuntimeException( 'Live validation configuration is incomplete.' );
	}
}

define( 'GRAVITY_NOTIFY_LIVE_IPPANEL_API_KEY', $gravity_notify_live_config['api_key'] );
define( 'GRAVITY_NOTIFY_LIVE_SMS_FROM', $gravity_notify_live_config['from'] );
define( 'GRAVITY_NOTIFY_LIVE_SMS_TO', $gravity_notify_live_config['to'] );
define( 'GRAVITY_NOTIFY_LIVE_RUN_ID', $gravity_notify_live_config['run_id'] );
define( 'GRAVITY_NOTIFY_LIVE_HEAD', $gravity_notify_live_config['head'] );
if ( ! defined( 'GFSMS_SETTINGS_OPTION' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exact immutable legacy bootstrap prerequisite for sender-reachability observation.
	define( 'GFSMS_SETTINGS_OPTION', 'gfsms_settings' );
}

unset( $gravity_notify_config_raw, $gravity_notify_live_config );

$gravity_notify_polyfills_path = dirname( __DIR__, 3 ) . '/.wp-env.runtime/phpunit-polyfills';
if ( ! is_dir( $gravity_notify_polyfills_path ) ) {
	throw new RuntimeException( 'Pinned PHPUnit Polyfills runtime is missing.' );
}
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Required by WordPress test bootstrap.
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $gravity_notify_polyfills_path );
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
require_once __DIR__ . '/SafeIPPanelDiagnostics.php';

if ( ! class_exists( 'GFFeedAddOn' ) || ! class_exists( 'Gravity_Flow_Step_Feed_Add_On' ) ) {
	throw new RuntimeException( 'Real Gravity Forms and Gravity Flow must load before live validation.' );
}

\GFAddOn::register( \GravityNotify\GravityForms\NotificationFeedAddOn::class );
\GravityNotify\GravityForms\NotificationFeedAddOn::get_instance()->init();
\GravityNotify\Tests\Integration\LiveRuntime\SafeIPPanelDiagnostics::boot();
