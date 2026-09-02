<?php
/**
 * Handles plugin uninstallation cleanup.
 *
 * Removes all plugin data including database tables and options.
 *
 * @package GFSMS\Lifecycle
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Lifecycle;

use GFSMS\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin uninstallation cleanup.
 *
 * @since 3.0.0
 */
final class Uninstaller {

	/**
	 * Run uninstall routines.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;

		delete_option( GFSMS_SETTINGS_OPTION );
		delete_option( 'gfsms_cached_senders' );
		delete_option( 'gfsms_version' );

		$table_name = Logger::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}
}
