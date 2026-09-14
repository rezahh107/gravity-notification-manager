<?php
/**
 * wpdb-compatible uninstall test double.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Lifecycle;

/**
 * Minimal wpdb-compatible test double with observable table-presence state.
 */
final class OperationalUninstallWpdb {

	/** Database table prefix. */
	public string $prefix = 'wp_';

	/** @var array<string, bool> Existing tables keyed by table name. */
	public array $tables = array();

	/** @var array<int, string> Executed SQL queries. */
	private array $queries = array();

	/**
	 * Substitute a WordPress identifier placeholder.
	 *
	 * @param string $query      SQL template.
	 * @param string $identifier Identifier value.
	 * @return string Prepared SQL.
	 */
	public function prepare( string $query, string $identifier ): string {
		return str_replace( '%i', $identifier, $query );
	}

	/**
	 * Record a query and apply supported DROP TABLE statements to fake state.
	 *
	 * @param string $query SQL query.
	 * @return int Number of affected fake rows/tables.
	 */
	public function query( string $query ): int {
		$this->queries[] = $query;
		if ( 1 === preg_match( '/^DROP TABLE IF EXISTS ([A-Za-z0-9_]+)$/', $query, $matches ) ) {
			unset( $this->tables[ $matches[1] ] );
			return 1;
		}

		return 0;
	}

	/**
	 * Report whether a fake table currently exists.
	 *
	 * @param string $table Table name.
	 * @return bool Whether the table exists.
	 */
	public function table_exists( string $table ): bool {
		return isset( $this->tables[ $table ] );
	}

	/**
	 * Count matching DROP TABLE queries.
	 *
	 * @param string $table Table name.
	 * @return int Drop count.
	 */
	public function drop_count( string $table ): int {
		return count(
			array_filter(
				$this->queries,
				static fn( string $query ): bool => 'DROP TABLE IF EXISTS ' . $table === $query
			)
		);
	}
}
