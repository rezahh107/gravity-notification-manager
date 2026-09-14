<?php
/**
 * Wpdb fake for operational event-store tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Observability;

/** OperationalStoreWpdbFake implementation. */
final class OperationalStoreWpdbFake {
	/**
	 * Stored value.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';
		/**
		 * Stored value.
		 *
		 * @var array<int,
		 */
	public array $rows = array();
	/**
	 * Stored value.
	 *
	 * @var int
	 */
	private int $next_id = 1;

		/**
		 * Insert.
		 *
		 * @param string $table Value.
		 * @param array  $data Value.
		 */
	public function insert( string $table, array $data ) {
		unset( $table );
		$data['id']   = $this->next_id++;
		$this->rows[] = $data;
		return 1;
	}

	/**
	 * Prepare.
	 *
	 * @param string $query Value.
	 * @param mixed  ...$args Value.
	 * @return string Return value.
	 */
	public function prepare( string $query, ...$args ): string {
		return (string) json_encode(
			array(
				'query' => $query,
				'args'  => $args,
			)
		);
	}

	/**
	 * Get var.
	 *
	 * @param string $prepared Value.
	 */
	public function get_var( string $prepared ) {
		$data   = json_decode( $prepared, true );
		$offset = (int) ( $data['args'][1] ?? 0 );
		$ids    = array_reverse( array_column( $this->rows, 'id' ) );
		return $ids[ $offset ] ?? null;
	}

	/**
	 * Query.
	 *
	 * @param string $prepared Value.
	 */
	public function query( string $prepared ) {
		$data       = json_decode( $prepared, true );
		$cutoff     = (int) ( $data['args'][1] ?? 0 );
		$this->rows = array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => (int) $row['id'] >= $cutoff
			)
		);
		return 1;
	}
}
