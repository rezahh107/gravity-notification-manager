<?php
/**
 * Operational logging model/privacy tests.
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

final class OperationalLoggerTest extends TestCase {

	public function test_fallback_attempts_share_one_trace_and_preserve_truthful_statuses(): void {
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
				new AttemptResult( AttemptStatus::AMBIGUOUS, 'sms', 'primary', 'plain', array(), 'transport_error' ),
				new AttemptResult( AttemptStatus::SUCCESS, 'sms', 'fallback', 'plain', array( 'safe-ref-1' ), 'accepted', 200 ),
			),
			array( '+989121234567' ),
			'+982100000000'
		);

		self::assertCount( 2, $store->events );
		$first  = $store->events[0]->to_array();
		$second = $store->events[1]->to_array();
		self::assertSame( $first['trace_id'], $second['trace_id'] );
		self::assertSame( array( AttemptStatus::AMBIGUOUS, AttemptStatus::SUCCESS ), array( $first['status'], $second['status'] ) );
		self::assertSame( array( 1, 2 ), array( $first['attempt_index'], $second['attempt_index'] ) );
		self::assertSame( OperationalContext::EXECUTION_NORMAL, $first['execution_type'] );
		self::assertSame( 200, $second['http_status'] );
		self::assertSame( '+982100000000', $first['sender'] );
		self::assertNotSame( '+989121234567', $first['destination'] );
		self::assertStringContainsString( '*', (string) $first['destination'] );
	}

	public function test_bale_event_remains_bale_and_has_no_sms_sender(): void {
		$store  = new InMemoryOperationalEventStore();
		$logger = new OperationalLogger( $store );
		$logger->record_attempt(
			new OperationalContext( OperationalContext::EXECUTION_NORMAL, null, null, null, '', '22222222-2222-4222-8222-222222222222' ),
			new AttemptResult( AttemptStatus::FAILED, 'bale', null, null, array(), 'api_rejection', 400 ),
			array( '123456789' )
		);

		$data = $store->events[0]->to_array();
		self::assertSame( 'bale', $data['channel'] );
		self::assertNull( $data['sender'] );
		self::assertSame( AttemptStatus::FAILED, $data['status'] );
	}

	public function test_logging_failure_never_throws_or_changes_attempt_truth(): void {
		$store              = new InMemoryOperationalEventStore();
		$store->fail_writes = true;
		$logger             = new OperationalLogger( $store );
		$result             = new AttemptResult( AttemptStatus::SUCCESS, 'sms', 'ippanel', 'plain', array( 'ref-1' ), 'accepted', 200 );
		$written            = $logger->record_attempt(
			new OperationalContext( OperationalContext::EXECUTION_NORMAL ),
			$result,
			array( '+989121234567' ),
			'+982100000000'
		);

		self::assertFalse( $written );
		self::assertSame( AttemptStatus::SUCCESS, $result->status() );
		self::assertSame( 'accepted', $result->diagnostic() );
	}

	public function test_representative_secret_like_diagnostics_and_references_are_not_persisted(): void {
		$store  = new InMemoryOperationalEventStore();
		$logger = new OperationalLogger( $store );
		$logger->record_attempt(
			new OperationalContext( OperationalContext::EXECUTION_TEST ),
			new AttemptResult( AttemptStatus::FAILED, 'sms', 'ippanel', 'plain', array( 'Authorization:BearerSecret', 'safe-ref-2' ), 'api_key_leaked' ),
			array( '+989121234567' ),
			'+982100000000'
		);

		$data = $store->events[0]->to_array();
		self::assertSame( array( 'safe-ref-2' ), $data['provider_references'] );
		self::assertSame( 'unknown', $data['diagnostic'] );
		self::assertStringNotContainsString( 'BearerSecret', json_encode( $data ) ?: '' );
	}

	public function test_masking_never_persists_full_personal_destination(): void {
		$masked = OperationalLogger::masked_destinations( array( '+989121234567', '@private_channel' ) );
		self::assertIsString( $masked );
		self::assertStringNotContainsString( '+989121234567', $masked );
		self::assertStringNotContainsString( '@private_channel', $masked );
		self::assertStringContainsString( '*', $masked );
	}
}
