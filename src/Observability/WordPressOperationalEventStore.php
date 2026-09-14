<?php
/**
 * WordPress database persistence for bounded operational events.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Observability;

use GravityNotify\Delivery\AttemptStatus;
use Throwable;

/**
 * Persists only the greenfield observational model; it has no delivery authority.
 */
final class WordPressOperationalEventStore implements OperationalEventStoreInterface {

	public const DEFAULT_RETENTION_LIMIT = 1000;

	/** @var object WordPress wpdb-compatible object. */
	private object $db;
	private int $retention_limit;

	public function __construct( object $db, int $retention_limit = self::DEFAULT_RETENTION_LIMIT ) {
		$this->db              = $db;
		$this->retention_limit = max( 1, $retention_limit );
	}

	public static function production(): self {
		global $wpdb;
		return new self( $wpdb );
	}

	public function append( OperationalEvent $event ): bool {
		$data = $event->to_array();
		unset( $data['id'] );
		$data['provider_references'] = self::encode_references( $data['provider_references'] ?? array() );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated bounded operational log store.
			$inserted = $this->db->insert( OperationalLogInstaller::table_name( $this->db ), $data );
			if ( false === $inserted ) {
				return false;
			}
			$this->prune();
			return true;
		} catch ( Throwable $exception ) {
			unset( $exception );
			return false;
		}
	}

	public function latest( string $channel, array $filters = array(), int $limit = 100 ): array {
		if ( ! in_array( $channel, array( 'sms', 'bale' ), true ) ) {
			return array();
		}

		$sql    = 'SELECT * FROM %i WHERE channel = %s';
		$params = array( OperationalLogInstaller::table_name( $this->db ), $channel );

		$status = (string) ( $filters['status'] ?? '' );
		if ( in_array( $status, AttemptStatus::all(), true ) ) {
			$sql      .= ' AND status = %s';
			$params[] = $status;
		}

		$execution_type = (string) ( $filters['execution_type'] ?? '' );
		if ( in_array( $execution_type, OperationalContext::execution_types(), true ) ) {
			$sql      .= ' AND execution_type = %s';
			$params[] = $execution_type;
		}

		$trace_id = strtolower( trim( (string) ( $filters['trace_id'] ?? '' ) ) );
		if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $trace_id ) ) {
			$sql      .= ' AND trace_id = %s';
			$params[] = $trace_id;
		}

		$sql      .= ' ORDER BY id DESC LIMIT %d';
		$params[] = min( 100, max( 1, $limit ) );

		try {
			$query = $this->db->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL shape is fixed; values use wpdb placeholders.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Operator log query against dedicated table.
			$rows = $this->db->get_results( $query, ARRAY_A );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return array();
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$events = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			try {
				$events[] = OperationalEvent::from_storage_row( $row );
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}
		return $events;
	}

	/** Keep only the newest deterministic bounded set. */
	private function prune(): void {
		$table  = OperationalLogInstaller::table_name( $this->db );
		$offset = $this->retention_limit - 1;
		try {
			$cutoff_query = $this->db->prepare(
				'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d',
				$table,
				$offset
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed SQL with wpdb placeholders.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention enforcement for dedicated log table.
			$cutoff = $this->db->get_var( $cutoff_query );
			if ( null === $cutoff || ! is_numeric( $cutoff ) ) {
				return;
			}
			$delete_query = $this->db->prepare(
				'DELETE FROM %i WHERE id < %d',
				$table,
				(int) $cutoff
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed SQL with wpdb placeholders.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deterministic bounded retention.
			$this->db->query( $delete_query );
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
	}

	/** @param mixed $references Safe provider references. */
	private static function encode_references( $references ): string {
		$references = is_array( $references ) ? array_values( $references ) : array();
		$encoded    = function_exists( 'wp_json_encode' ) ? wp_json_encode( $references ) : json_encode( $references );
		return is_string( $encoded ) ? $encoded : '[]';
	}
}
