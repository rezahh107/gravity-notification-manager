<?php
/**
 * Deterministic retention test for the production WordPress store algorithm.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Observability;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Observability\OperationalContext;
use GravityNotify\Observability\OperationalEvent;
use GravityNotify\Observability\WordPressOperationalEventStore;
use PHPUnit\Framework\TestCase;

final class WordPressOperationalEventStoreTest extends TestCase {

	public function test_retention_keeps_only_newest_configured_number_of_rows(): void {
		$db    = new OperationalStoreWpdbFake();
		$store = new WordPressOperationalEventStore( $db, 3 );

		for ( $index = 1; $index <= 5; ++$index ) {
			self::assertTrue( $store->append( $this->event( $index ) ) );
		}

		self::assertSame( array( 3, 4, 5 ), array_column( $db->rows, 'id' ) );
	}

	private function event( int $index ): OperationalEvent {
		return new OperationalEvent(
			array(
				'created_at_utc' => '2026-09-14 12:00:0' . $index,
				'trace_id'       => sprintf( '55555555-5555-4555-8555-%012d', $index ),
				'attempt_index'  => 1,
				'channel'        => 'sms',
				'execution_type' => OperationalContext::EXECUTION_NORMAL,
				'status'         => AttemptStatus::SUCCESS,
				'provider'       => 'ippanel',
				'diagnostic'     => 'accepted',
			)
		);
	}
}

final class OperationalStoreWpdbFake {
	public string $prefix = 'wp_';
	/** @var array<int, array<string, mixed>> */
	public array $rows = array();
	private int $next_id = 1;

	/** @param array<string, mixed> $data */
	public function insert( string $table, array $data ) {
		unset( $table );
		$data['id'] = $this->next_id++;
		$this->rows[] = $data;
		return 1;
	}

	public function prepare( string $query, ...$args ): string {
		return (string) json_encode( array( 'query' => $query, 'args' => $args ) );
	}

	public function get_var( string $prepared ) {
		$data   = json_decode( $prepared, true );
		$offset = (int) ( $data['args'][1] ?? 0 );
		$ids    = array_reverse( array_column( $this->rows, 'id' ) );
		return $ids[ $offset ] ?? null;
	}

	public function query( string $prepared ) {
		$data   = json_decode( $prepared, true );
		$cutoff = (int) ( $data['args'][1] ?? 0 );
		$this->rows = array_values(
			array_filter(
				$this->rows,
				static fn( array $row ): bool => (int) $row['id'] >= $cutoff
			)
		);
		return 1;
	}
}
