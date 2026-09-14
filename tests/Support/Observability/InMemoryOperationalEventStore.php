<?php
/**
 * In-memory observational store for unit tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Observability;

use GravityNotify\Observability\OperationalEvent;
use GravityNotify\Observability\OperationalEventStoreInterface;

final class InMemoryOperationalEventStore implements OperationalEventStoreInterface {

	/** @var array<int, OperationalEvent> */
	public array $events = array();
	public bool $fail_writes = false;

	public function append( OperationalEvent $event ): bool {
		if ( $this->fail_writes ) {
			return false;
		}
		$this->events[] = $event;
		return true;
	}

	public function latest( string $channel, array $filters = array(), int $limit = 100 ): array {
		$events = array_values(
			array_filter(
				$this->events,
				static function ( OperationalEvent $event ) use ( $channel, $filters ): bool {
					$data = $event->to_array();
					if ( $channel !== $data['channel'] ) {
						return false;
					}
					foreach ( array( 'status', 'execution_type', 'trace_id' ) as $key ) {
						if ( ! empty( $filters[ $key ] ) && $filters[ $key ] !== $data[ $key ] ) {
							return false;
						}
					}
					return true;
				}
			)
		);
		return array_slice( array_reverse( $events ), 0, max( 1, $limit ) );
	}
}
