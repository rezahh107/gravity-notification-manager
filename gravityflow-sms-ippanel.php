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
// Activation / deactivation / uninstall hooks
// ---------------------------------------------------------------------------
register_activation_hook( GFSMS_PLUGIN_FILE, [ '\GFSMS\Lifecycle\Activator', 'activate' ] );
register_deactivation_hook( GFSMS_PLUGIN_FILE, [ '\GFSMS\Lifecycle\Deactivator', 'deactivate' ] );
register_uninstall_hook( GFSMS_PLUGIN_FILE, [ '\GFSMS\Lifecycle\Uninstaller', 'uninstall' ] );

// ---------------------------------------------------------------------------
// Plugin bootstrap
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', static function (): void {
	// 1. PHP version check
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		add_action( 'admin_notices', static function (): void {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: minimum PHP version */
				esc_html__( 'Gravity Flow SMS Notifier requires PHP %s or higher.', 'gfsms' ),
				'8.1'
			);
			echo '</p></div>';
		} );
		return;
	}

	// 2. Required plugins check
	if ( ! class_exists( 'GFForms' ) || ! class_exists( 'Gravity_Flow' ) ) {
		add_action( 'admin_notices', static function (): void {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Gravity Flow SMS Notifier requires Gravity Forms and Gravity Flow to be active.', 'gfsms' );
			echo '</p></div>';
		} );
		return;
	}

	// 3. Bootstrap class existence check (in case autoloader mapping is missing)
	if ( ! class_exists( '\GFSMS\Core\Bootstrap' ) ) {
		add_action( 'admin_notices', static function (): void {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: class name */
				esc_html__( 'Gravity Flow SMS Notifier: missing bootstrap class %s. Make sure all files are installed correctly.', 'gfsms' ),
				'GFSMS\Core\Bootstrap'
			);
			echo '</p></div>';
		} );
		return;
	}

	\GFSMS\Core\Bootstrap::init();
}, 20 );