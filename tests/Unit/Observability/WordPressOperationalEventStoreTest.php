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
use GravityNotify\Tests\Support\Observability\OperationalStoreWpdbFake;
use PHPUnit\Framework\TestCase;

/** WordPressOperationalEventStoreTest implementation. */
final class WordPressOperationalEventStoreTest extends TestCase {

	/**
	 * Test retention keeps only newest configured number of rows.
	 */
	public function test_retention_keeps_only_newest_configured_number_of_rows(): void {
		$db    = new OperationalStoreWpdbFake();
		$store = new WordPressOperationalEventStore( $db, 3 );

		for ( $index = 1; $index <= 5; ++$index ) {
			self::assertTrue( $store->append( $this->event( $index ) ) );
		}

		self::assertSame( array( 3, 4, 5 ), array_column( $db->rows, 'id' ) );
	}

	/**
	 * Event.
	 *
	 * @param int $index Value.
	 * @return OperationalEvent Return value.
	 */
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
