<?php
/**
 * Schema lifecycle for the greenfield operational evidence table.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Observability;

/**
 * Installs/upgrades a dedicated observational table without activating legacy delivery runtime.
 */
final class OperationalLogInstaller {

	public const SCHEMA_VERSION = '1';
	public const VERSION_OPTION = 'gravity_notify_operational_log_schema_version';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted || ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'plugins_loaded', array( self::class, 'maybe_upgrade' ), 1 );
		self::$booted = true;
	}

	/** Activation hook; ordinary upgrades are covered by maybe_upgrade(). */
	public static function activate(): void {
		self::install();
	}

	/** Install on version mismatch because plugin updates do not rerun activation hooks. */
	public static function maybe_upgrade(): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		if ( self::SCHEMA_VERSION !== (string) get_option( self::VERSION_OPTION, '' ) ) {
			self::install();
		}
	}

	/**
	 * Create/update the dedicated table using WordPress dbDelta.
	 *
	 * @return bool Whether the expected table is available after migration.
	 */
	public static function install(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! defined( 'ABSPATH' ) ) {
			return false;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			return false;
		}

		$table           = self::table_name( $wpdb );
		$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? (string) $wpdb->get_charset_collate() : '';
		$sql             = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		created_at_utc datetime NOT NULL,
		trace_id char(36) NOT NULL,
		attempt_index smallint(5) unsigned NOT NULL DEFAULT 1,
		channel varchar(16) NOT NULL,
		execution_type varchar(16) NOT NULL,
		status varchar(16) NOT NULL,
		provider varchar(64) NULL,
		form_id bigint(20) unsigned NULL,
		feed_id bigint(20) unsigned NULL,
		entry_id bigint(20) unsigned NULL,
		feed_name varchar(191) NULL,
		sender varchar(64) NULL,
		destination varchar(191) NULL,
		provider_references text NULL,
		diagnostic varchar(128) NOT NULL,
		http_status smallint(5) unsigned NULL,
		plugin_version varchar(32) NULL,
		wp_version varchar(32) NULL,
		php_version varchar(32) NULL,
		PRIMARY KEY  (id),
		KEY channel_created (channel,created_at_utc),
		KEY trace_id (trace_id),
		KEY status (status),
		KEY execution_type (execution_type)
	) {$charset_collate};";

		dbDelta( $sql );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema verification after dbDelta.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $found ) {
			return false;
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
		}
		return true;
	}

	/** Remove the operational schema owned by this installer during canonical plugin uninstall. */
	public static function uninstall(): void {
		global $wpdb;

		delete_option( self::VERSION_OPTION );

		if ( ! is_object( $wpdb ) ) {
			return;
		}

		$table = self::table_name( $wpdb );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Canonical uninstall schema cleanup.
		$wpdb->query(
			$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/** @param object $db wpdb-compatible object. */
	public static function table_name( object $db ): string {
		$prefix = isset( $db->prefix ) && is_string( $db->prefix ) ? $db->prefix : '';
		return $prefix . 'gravity_notify_operational_events';
	}
}
