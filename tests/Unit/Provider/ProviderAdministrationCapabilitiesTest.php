<?php
/**
 * Explicit provider connection/discovery contract tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Provider;

use GravityNotify\Admin\ProviderConnectionService;
use GravityNotify\Admin\ProviderDiscoveryService;
use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Provider\SmsProviderManager;
use GravityNotify\Tests\Support\WordPress\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

/** Proves explicit no-live-I/O administration behavior and truthful discovery support. */
final class ProviderAdministrationCapabilitiesTest extends TestCase {

	/** Every approved provider validates credentials through its verified first-party contract. */
	public function test_explicit_connection_validation_for_all_approved_providers(): void {
		$cases = array(
			SmsProviderManager::IPPANEL => array( HttpResponse::from_http( 200, '{"meta":{"status":true},"data":{}}' ), 'GET', 'https://edge.ippanel.com/v1/api/acl/auth/check_token' ),
			SmsProviderManager::MELIPAYAMAK => array( HttpResponse::from_http( 200, '{"Value":"1000","RetStatus":1}' ), 'POST', 'https://rest.payamak-panel.com/api/SendSMS/GetCredit' ),
			SmsProviderManager::SMSIR => array( HttpResponse::from_http( 200, '{"status":1,"message":"ok","data":1000.5}' ), 'GET', 'https://api.sms.ir/v1/credit' ),
			SmsProviderManager::FARAZSMS => array( HttpResponse::from_http( 200, '{"status":"success","data":1000}' ), 'GET', 'https://api.iranpayamak.com/ws/v1/account/balance' ),
		);

		foreach ( $cases as $provider => $case ) {
			$http   = new FakeHttpTransport( array( $case[0] ) );
			$result = ( new ProviderConnectionService( $this->settings(), $http ) )->check( $provider );
			self::assertTrue( $result->successful(), $provider );
			self::assertSame( 'connection_validated', $result->diagnostic(), $provider );
			self::assertSame( $case[1], $http->requests()[0]['method'], $provider );
			self::assertSame( $case[2], $http->requests()[0]['url'], $provider );
		}
	}

	/** Authentication failures remain safe failures and do not expose provider bodies. */
	public function test_connection_authentication_failure_is_truthful(): void {
		$http   = new FakeHttpTransport( array( HttpResponse::from_http( 401, '{"token":"must-not-surface"}' ) ) );
		$result = ( new ProviderConnectionService( $this->settings(), $http ) )->check( SmsProviderManager::FARAZSMS );
		self::assertFalse( $result->successful() );
		self::assertSame( 'authentication_failed', $result->diagnostic() );
		self::assertSame( 401, $result->http_status() );
	}

	/** SMS.ir sender discovery handles zero, one, and multiple documented line values. */
	public function test_smsir_sender_discovery_zero_one_and_multiple_lines(): void {
		foreach ( array( array(), array( 30001 ), array( 30001, 30002 ) ) as $lines ) {
			$http = new FakeHttpTransport(
				array(
					HttpResponse::from_http(
						200,
						(string) json_encode(
							array(
								'status'  => 1,
								'message' => 'ok',
								'data'    => $lines,
							)
						),
					),
				)
			);
			$result = ( new ProviderDiscoveryService( $this->settings(), $http ) )->discover( SmsProviderManager::SMSIR );
			self::assertTrue( $result->successful() );
			self::assertSame( array_map( 'strval', $lines ), $result->lines() );
			self::assertSame( 'GET', $http->requests()[0]['method'] );
			self::assertSame( 'https://api.sms.ir/v1/line', $http->requests()[0]['url'] );
		}
	}

	/** SMS.ir discovery authentication, malformed, and partial responses fail without usable lines. */
	public function test_smsir_discovery_failure_modes_are_safe(): void {
		$responses = array(
			HttpResponse::from_http( 401, '{"status":0,"message":"unauthorized"}' ),
			HttpResponse::from_http( 200, 'not-json' ),
			HttpResponse::from_http( 200, '{"status":1,"message":"ok"}' ),
			HttpResponse::from_http( 200, '{"status":1,"data":[30001,"bad-line"]}' ),
		);
		foreach ( $responses as $response ) {
			$result = ( new ProviderDiscoveryService( $this->settings(), new FakeHttpTransport( array( $response ) ) ) )->discover( SmsProviderManager::SMSIR );
			self::assertFalse( $result->successful() );
			self::assertSame( array(), $result->lines() );
		}
	}

	/** Providers without a fully verified enumeration parser stay in explicit manual-fallback state. */
	public function test_unverified_sender_discovery_contracts_are_manual_fallback_without_network(): void {
		foreach ( array( SmsProviderManager::IPPANEL, SmsProviderManager::MELIPAYAMAK, SmsProviderManager::FARAZSMS ) as $provider ) {
			$http   = new FakeHttpTransport( array() );
			$result = ( new ProviderDiscoveryService( $this->settings(), $http ) )->discover( $provider );
			self::assertFalse( $result->successful(), $provider );
			self::assertSame( 'discovery_unavailable', $result->diagnostic(), $provider );
			self::assertTrue( SmsProviderManager::manual_sender_allowed( $provider ), $provider );
			self::assertSame( array(), $http->requests(), $provider );
		}
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
