<?php
/**
 * Explicit provider-test observability coverage.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\ProviderTestService;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Observability\OperationalContext;
use GravityNotify\Observability\OperationalLogger;
use GravityNotify\Provider\SmsProviderManager;
use GravityNotify\Tests\Support\Observability\InMemoryOperationalEventStore;
use GravityNotify\Tests\Support\WordPress\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

final class ProviderTestObservabilityTest extends TestCase {

	public function test_ippanel_and_bale_tests_are_logged_as_test_without_delivery_state_dependencies(): void {
		$events  = new InMemoryOperationalEventStore();
		$http    = new FakeHttpTransport(
			array(
				HttpResponse::from_http( 200, '{"meta":{"status":true},"data":{"message_outbox_ids":["sms-test-ref"]}}' ),
				HttpResponse::from_http( 200, '{"ok":true,"result":{"message_id":"bale-test-ref"}}' ),
			)
		);
		$service = new ProviderTestService( $this->settings(), $http, new OperationalLogger( $events ) );

		$sms  = $service->test_sms( '+989121234567', 'GNM test' );
		$bale = $service->test_bale( '123456789', 'GNM test' );

		self::assertSame( AttemptStatus::SUCCESS, $sms->status() );
		self::assertSame( AttemptStatus::SUCCESS, $bale->status() );
		self::assertCount( 2, $events->events );
		self::assertSame( array( 'sms', 'bale' ), array( $events->events[0]->get( 'channel' ), $events->events[1]->get( 'channel' ) ) );
		self::assertSame( OperationalContext::EXECUTION_TEST, $events->events[0]->get( 'execution_type' ) );
		self::assertSame( OperationalContext::EXECUTION_TEST, $events->events[1]->get( 'execution_type' ) );
		self::assertNull( $events->events[0]->get( 'entry_id' ) );
		self::assertNull( $events->events[1]->get( 'entry_id' ) );
		self::assertNotSame( '+989121234567', $events->events[0]->get( 'destination' ) );
		self::assertNotSame( '123456789', $events->events[1]->get( 'destination' ) );
	}

	public function test_test_logging_failure_does_not_change_provider_result(): void {
		$events              = new InMemoryOperationalEventStore();
		$events->fail_writes = true;
		$http                = new FakeHttpTransport(
			array( HttpResponse::from_http( 200, '{"meta":{"status":true},"data":{"message_outbox_ids":["safe-ref"]}}' ) )
		);
		$service             = new ProviderTestService( $this->settings(), $http, new OperationalLogger( $events ) );
		$result              = $service->test_sms( '+989121234567', 'GNM test' );

		self::assertSame( AttemptStatus::SUCCESS, $result->status() );
		self::assertSame( array( 'safe-ref' ), $result->provider_references() );
		self::assertCount( 1, $http->requests() );
	}

	/** @return array<string, mixed> */
	private function settings(): array {
		return array(
			SmsProviderManager::CONFIG_KEY => array(
				SmsProviderManager::IPPANEL => array(
					'enabled' => true,
					'api_key' => 'test-api-key',
					'sender'  => '+989000000000',
				),
			),
			'bale_bot_token'               => 'test-bale-token',
		);
	}
}
