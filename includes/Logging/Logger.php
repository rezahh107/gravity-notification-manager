<?php
/**
 * Logging handler for SMS operations.
 *
 * Provides a singleton access point and methods to write structured log entries
 * into a custom database table.
 *
 * @package GFSMS\Logging
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 *
 * Singleton logger for SMS events.
 *
 * @since 3.0.0
 */
final class Logger {

	/**
	 * Singleton instance.
	 *
	 * @since 3.0.0
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since 3.0.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Returns the fully qualified database table name (with prefix).
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . GFSMS_DB_TABLE_SUFFIX;
	}

	/**
	 * Creates or updates the log database table.
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			step_id bigint(20) unsigned NOT NULL DEFAULT 0,
			workflow_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(50) NOT NULL DEFAULT '',
			recipient varchar(191) NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT '',
			provider varchar(60) NOT NULL DEFAULT '',
			error_code varchar(191) NOT NULL DEFAULT '',
			error_message longtext NULL,
			message longtext NULL,
			raw_payload longtext NULL,
			PRIMARY KEY  (id),
			KEY entry_id (entry_id),
			KEY event_type (event_type),
			KEY recipient (recipient),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Ensures the log table exists (safe to call on every admin page load).
	 *
	 * @since 3.0.0
	 *
	 * @return void
	 */
	public function maybe_setup(): void {
		self::create_table();
	}

	/**
	 * Inserts a new log entry into the database.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $entry_id    Gravity Forms entry ID.
	 * @param int    $step_id     Workflow step ID.
	 * @param int    $workflow_id Workflow ID.
	 * @param string $event_type  Event type slug.
	 * @param string $recipient   Phone number (truncated to 191 chars).
	 * @param string $status      Log status (success, warning, info, etc.).
	 * @param string $message     Human‑readable description.
	 * @param array  $result      Raw response data (will be JSON‑encoded).
	 * @param string $error_code  Optional explicit error code.
	 * @param string $provider    SMS provider identifier.
	 *
	 * @return void
	 */
	public function log_entry(
		int $entry_id,
		int $step_id,
		int $workflow_id,
		string $event_type,
		string $recipient,
		string $status,
		string $message,
		array $result,
		string $error_code = '',
		string $provider = 'ippanel'
	): void {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'    => current_time( 'mysql' ),
				'entry_id'      => $entry_id,
				'step_id'       => $step_id,
				'workflow_id'   => $workflow_id,
				'event_type'    => $event_type,
				'recipient'     => substr( $recipient, 0, 191 ),
				'status'        => $status,
				'provider'      => $provider,
				'error_code'    => $error_code ?: ( $result['error_code'] ?? '' ),
				'error_message' => $result['error_message'] ?? '',
				'message'       => $message,
				'raw_payload'   => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
	}

	/**
	 * Shorthand for logging a warning entry.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $entry_id    Entry ID.
	 * @param int    $step_id     Step ID.
	 * @param int    $workflow_id Workflow ID.
	 * @param string $event_type  Event type.
	 * @param string $code        Warning code.
	 * @param string $message     Description.
	 *
	 * @return void
	 */
	public function warning(
		int $entry_id,
		int $step_id,
		int $workflow_id,
		string $event_type,
		string $code,
		string $message
	): void {
		$this->log_entry(
			$entry_id,
			$step_id,
			$workflow_id,
			$event_type,
			'',
			'warning',
			$message,
			array( 'error_code' => $code ),
			$code
		);
	}

	/**
	 * Shorthand for logging an informational entry.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $entry_id    Entry ID.
	 * @param int    $step_id     Step ID.
	 * @param int    $workflow_id Workflow ID.
	 * @param string $event_type  Event type.
	 * @param string $code        Info code.
	 * @param string $message     Description.
	 *
	 * @return void
	 */
	public function info(
		int $entry_id,
		int $step_id,
		int $workflow_id,
		string $event_type,
		string $code,
		string $message
	): void {
		$this->log_entry(
			$entry_id,
			$step_id,
			$workflow_id,
			$event_type,
			'',
			'info',
			$message,
			array( 'success' => true ),
			$code
		);
	}

	/**
	 * Writes a debug message to the WordPress debug log if WP_DEBUG is enabled.
	 *
	 * @since 3.0.0
	 *
	 * @param string $message The message to log.
	 *
	 * @return void
	 */
	public function debug_message( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[GFSMS] ' . $message );
		}
	}
}
