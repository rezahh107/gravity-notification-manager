<?php
/**
 * Plugin Name: Gravity Flow SMS Notifier with IPPanel
 * Description: Sends SMS notifications for Gravity Flow workflow events and Gravity Forms submissions through IPPanel.
 * Version: 3.2.0
 * Author: Reza Hashemi Hosseini
 * Text Domain: gfsms
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.0
 */
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'GFSMS_PLUGIN_VERSION' ) ) {
	define( 'GFSMS_PLUGIN_VERSION', '3.2.0' );
}
if ( ! defined( 'GFSMS_PLUGIN_FILE' ) ) {
	define( 'GFSMS_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'GFSMS_PLUGIN_DIR' ) ) {
	define( 'GFSMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'GFSMS_PLUGIN_URL' ) ) {
	define( 'GFSMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'GFSMS_PLUGIN_BASENAME' ) ) {
	define( 'GFSMS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'GFSMS_PLUGIN_SLUG' ) ) {
	define( 'GFSMS_PLUGIN_SLUG', 'gfsms' );
}
if ( ! defined( 'GFSMS_TEXT_DOMAIN' ) ) {
	define( 'GFSMS_TEXT_DOMAIN', 'gfsms' );
}
if ( ! defined( 'GFSMS_CAPABILITY' ) ) {
	define( 'GFSMS_CAPABILITY', 'manage_gfsms' );
}
if ( ! defined( 'GFSMS_DB_TABLE_SUFFIX' ) ) {
	define( 'GFSMS_DB_TABLE_SUFFIX', 'gfsms_logs' );
}
if ( ! defined( 'GFSMS_SETTINGS_OPTION' ) ) {
	define( 'GFSMS_SETTINGS_OPTION', 'gfsms_settings' );
}

// ---------------------------------------------------------------------------
// Composer autoloader
// ---------------------------------------------------------------------------
if ( file_exists( GFSMS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once GFSMS_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	add_action( 'admin_notices', static function (): void {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Gravity Flow SMS Notifier: Composer autoloader not found. Please run `composer install` in the plugin directory.', 'gfsms' );
		echo '</p></div>';
	} );
	return; // stop the plugin if autoloader is missing
}

// ---------------------------------------------------------------------------
// Greenfield admin foundation and notification runtime.
// WU-09 retired the legacy GFSMS sender/queue runtime. Migration state remains
// readable for authorization/history, but no legacy runtime guard or migration
// rollback controller is booted after retirement.
// ---------------------------------------------------------------------------
if ( ! defined( 'GRAVITY_NOTIFY_PLUGIN_URL' ) ) {
	define( 'GRAVITY_NOTIFY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( class_exists( '\\GravityNotify\\Admin\\AdminController' ) ) {
	\GravityNotify\Admin\AdminController::boot();
}
if ( class_exists( '\\GravityNotify\\Migration\\ProductionRuntime' ) ) {
	\GravityNotify\Migration\ProductionRuntime::boot();
}

// ---------------------------------------------------------------------------
// Activation / deactivation / uninstall hooks.
// Historical settings/log lifecycle is retained for WU-10 policy cleanup; it
// is not part of notification delivery and cannot boot the retired sender.
// ---------------------------------------------------------------------------
register_activation_hook( GFSMS_PLUGIN_FILE, [ '\\GFSMS\\Lifecycle\\Activator', 'activate' ] );
register_deactivation_hook( GFSMS_PLUGIN_FILE, [ '\\GFSMS\\Lifecycle\\Deactivator', 'deactivate' ] );
register_uninstall_hook( GFSMS_PLUGIN_FILE, [ '\\GFSMS\\Lifecycle\\Uninstaller', 'uninstall' ] );
