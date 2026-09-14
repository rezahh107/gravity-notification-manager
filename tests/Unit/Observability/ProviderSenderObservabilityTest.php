<?php
/**
 * Provider-specific sender observability regression coverage.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Observability;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Observability\OperationalContext;
use GravityNotify\Observability\OperationalLogger;
use GravityNotify\Tests\Support\Observability\InMemoryOperationalEventStore;
use PHPUnit\Framework\TestCase;

/** Proves heterogeneous provider senders remain truthful in the existing operational log. */
final class ProviderSenderObservabilityTest extends TestCase {

	/** Actual adapter sender overrides the request-envelope sender per attempt. */
	public function test_attempt_sender_is_recorded_for_heterogeneous_fallback(): void {
		$store   = new InMemoryOperationalEventStore();
		$logger  = new OperationalLogger( $store );
		$context = new OperationalContext(
			OperationalContext::EXECUTION_NORMAL,
			5,
			7,
			10,
			'Case update',
			'11111111-1111-4111-8111-111111111111'
		);

		$logger->record_attempts(
			$context,
			array(
				new AttemptResult( AttemptStatus::FAILED, 'sms', 'ippanel', 'plain', array(), 'provider_rejection', 400, '+982100000000' ),
				new AttemptResult( AttemptStatus::SUCCESS, 'sms', 'melipayamak', 'plain', array( '123' ), 'accepted', 200, '50001234' ),
			),
			array( '+989121234567' ),
			'+989999999999'
		);

		self::assertCount( 2, $store->events );
		self::assertSame( '+982100000000', $store->events[0]->get( 'sender' ) );
		self::assertSame( '50001234', $store->events[1]->get( 'sender' ) );
		self::assertSame( $store->events[0]->get( 'trace_id' ), $store->events[1]->get( 'trace_id' ) );
	}
}
