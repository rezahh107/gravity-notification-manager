<?php
/**
 * Approved-provider explicit test-send coverage.
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

/** Proves each approved provider can be deliberately tested through its production adapter. */
final class ApprovedProviderTestServiceTest extends TestCase {

	/** Each approved provider test reaches exactly its production adapter and records TEST observability. */
	public function test_all_approved_provider_tests_are_explicit_and_observed(): void {
		$cases = array(
			SmsProviderManager::IPPANEL => HttpResponse::from_http( 200, '{"meta":{"status":true},"data":{"message_outbox_ids":["ip-ref"]}}' ),
			SmsProviderManager::MELIPAYAMAK => HttpResponse::from_http( 200, '{"Value":"12345","RetStatus":1,"StrRetStatus":"Ok"}' ),
			SmsProviderManager::SMSIR => HttpResponse::from_http( 200, '{"status":1,"message":"ok","data":{"packId":"smsir-pack","messageIds":[101],"cost":1.2}}' ),
			SmsProviderManager::FARAZSMS => HttpResponse::from_http( 201, '{"status":"success","data":0}' ),
		);

		foreach ( $cases as $provider => $response ) {
			$http   = new FakeHttpTransport( array( $response ) );
			$store  = new InMemoryOperationalEventStore();
			$result = ( new ProviderTestService( $this->settings(), $http, new OperationalLogger( $store ) ) )->test_sms(
				'+989121234567',
				'GNM provider test',
				$provider
			);

			self::assertSame( AttemptStatus::SUCCESS, $result->status(), $provider );
			self::assertCount( 1, $http->requests(), $provider );
			$events = $store->latest( 'sms' );
			self::assertCount( 1, $events, $provider );
			self::assertSame( OperationalContext::EXECUTION_TEST, $events[0]->get( 'execution_type' ), $provider );
			self::assertSame( $provider, $events[0]->get( 'provider' ), $provider );
			self::assertSame( $result->sender(), $events[0]->get( 'sender' ), $provider );
		}
	}

	/** Test-send code remains structurally outside Feed/Retry/Entry Meta authority. */
	public function test_provider_test_service_has_no_entry_meta_or_retry_dependency(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/ProviderTestService.php' );
		self::assertIsString( $source );
		self::assertStringNotContainsString( 'NotificationFeedProcessor', $source );
		self::assertStringNotContainsString( 'ManualRetryHandler', $source );
		self::assertStringNotContainsString( 'EntryMetaDeliveryStore', $source );
		self::assertStringNotContainsString( 'gform_update_meta', $source );
		self::assertStringNotContainsString( 'GFAPI', $source );
	}

	/**
	 * Return settings with every approved provider configured.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		return array(
			SmsProviderManager::CONFIG_KEY => array(
				SmsProviderManager::IPPANEL => array(
					'enabled' => true,
					'api_key' => 'ip-key',
					'sender'  => '+982100000000',
				),
				SmsProviderManager::MELIPAYAMAK => array(
					'enabled'  => true,
					'username' => 'user',
					'password' => 'pass',
					'sender'   => '50001234',
				),
				SmsProviderManager::SMSIR => array(
					'enabled' => true,
					'api_key' => 'smsir-key',
					'sender'  => '30001234',
				),
				SmsProviderManager::FARAZSMS => array(
					'enabled' => true,
					'api_key' => 'faraz-key',
					'sender'  => '30005678',
				),
			),
		);
	}
}
