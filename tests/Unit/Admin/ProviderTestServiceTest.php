<?php
/**
 * Provider/channel test service regression coverage.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\ProviderTestService;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Provider\SmsProviderManager;
use GravityNotify\Tests\Support\WordPress\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Proves explicit tests use production provider composition without delivery-state side effects.
 */
final class ProviderTestServiceTest extends TestCase {

	/** Missing provider configuration fails before any outbound request. */
	public function test_missing_configuration_sends_nothing(): void {
		$http    = new FakeHttpTransport( array() );
		$service = new ProviderTestService( array(), $http );

		$sms  = $service->test_sms( '+989121234567', 'GNM test' );
		$bale = $service->test_bale( '123456789', 'GNM test' );

		self::assertSame( AttemptStatus::FAILED, $sms->status() );
		self::assertSame( 'provider_not_configured', $sms->diagnostic() );
		self::assertSame( AttemptStatus::FAILED, $bale->status() );
		self::assertSame( 'provider_not_configured', $bale->diagnostic() );
		self::assertSame( array(), $http->requests() );
	}

	/** Disabled IPPanel test fails locally and never invokes the transport. */
	public function test_disabled_ippanel_sends_nothing(): void {
		$settings = $this->settings();
		$settings[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ]['enabled'] = false;
		$http    = new FakeHttpTransport( array() );
		$service = new ProviderTestService( $settings, $http );
		$result  = $service->test_sms( '+989121234567', 'GNM test' );

		self::assertSame( AttemptStatus::FAILED, $result->status() );
		self::assertSame( 'provider_not_configured', $result->diagnostic() );
		self::assertSame( array(), $http->requests() );
	}

	/** Invalid destinations fail closed before provider invocation. */
	public function test_invalid_destinations_send_nothing(): void {
		$http    = new FakeHttpTransport( array() );
		$service = new ProviderTestService( $this->settings(), $http );

		$sms  = $service->test_sms( '09121234567', 'GNM test' );
		$bale = $service->test_bale( 'not a chat target', 'GNM test' );

		self::assertSame( AttemptStatus::FAILED, $sms->status() );
		self::assertSame( 'invalid_destination', $sms->diagnostic() );
		self::assertSame( AttemptStatus::FAILED, $bale->status() );
		self::assertSame( 'invalid_destination', $bale->diagnostic() );
		self::assertSame( array(), $http->requests() );
	}

	/** One valid SMS POST invokes the existing IPPanel provider exactly once. */
	public function test_valid_ippanel_test_invokes_exactly_one_provider_send(): void {
		$http    = new FakeHttpTransport(
			array(
				HttpResponse::from_http(
					200,
					'{"meta":{"status":true},"data":{"message_outbox_ids":["safe-ref-101"]}}'
				),
			)
		);
		$service = new ProviderTestService( $this->settings(), $http );
		$result  = $service->test_sms( '+989121234567', 'GNM provider test' );

		self::assertSame( AttemptStatus::SUCCESS, $result->status() );
		self::assertSame( array( 'safe-ref-101' ), $result->provider_references() );
		self::assertCount( 1, $http->requests() );

		$request = $http->requests()[0];
		self::assertSame( 'https://edge.ippanel.com/v1/api/send', $request['url'] );
		self::assertSame( 'test-api-key', $request['args']['headers']['Authorization'] );
		$payload = json_decode( (string) $request['args']['body'], true );
		self::assertIsArray( $payload );
		self::assertSame( 'webservice', $payload['sending_type'] );
		self::assertSame( '+989000000000', $payload['from_number'] );
		self::assertSame( array( '+989121234567' ), $payload['params']['recipients'] );
	}

	/** One valid Bale POST invokes the existing Bale client exactly once. */
	public function test_valid_bale_test_invokes_exactly_one_provider_send(): void {
		$http    = new FakeHttpTransport(
			array(
				HttpResponse::from_http( 200, '{"ok":true,"result":{"message_id":"safe-bale-202"}}' ),
			)
		);
		$service = new ProviderTestService( $this->settings(), $http );
		$result  = $service->test_bale( '123456789', 'GNM provider test' );

		self::assertSame( AttemptStatus::SUCCESS, $result->status() );
		self::assertSame( array( 'safe-bale-202' ), $result->provider_references() );
		self::assertCount( 1, $http->requests() );

		$request = $http->requests()[0];
		self::assertSame( 'https://tapi.bale.ai/bottest-bale-token/sendMessage', $request['url'] );
		$payload = json_decode( (string) $request['args']['body'], true );
		self::assertIsArray( $payload );
		self::assertSame( '123456789', $payload['chat_id'] );
	}

	/** Existing provider result semantics stay truthful for clear failure and ambiguity. */
	public function test_failed_and_ambiguous_results_are_preserved(): void {
		$failed_http = new FakeHttpTransport(
			array(
				HttpResponse::from_http( 400, '{"secret":"raw-provider-body-must-not-surface"}' ),
				HttpResponse::from_http( 403, '{"secret":"raw-bale-body-must-not-surface"}' ),
			)
		);
		$service     = new ProviderTestService( $this->settings(), $failed_http );

		self::assertSame( AttemptStatus::FAILED, $service->test_sms( '+989121234567', 'GNM test' )->status() );
		self::assertSame( AttemptStatus::FAILED, $service->test_bale( '@gnm_test', 'GNM test' )->status() );

		$ambiguous_http = new FakeHttpTransport(
			array(
				HttpResponse::from_transport_error( 'simulated_network_error' ),
				HttpResponse::from_http( 200, '{"ok":true}' ),
			)
		);
		$service        = new ProviderTestService( $this->settings(), $ambiguous_http );

		self::assertSame( AttemptStatus::AMBIGUOUS, $service->test_sms( '+989121234567', 'GNM test' )->status() );
		self::assertSame( AttemptStatus::AMBIGUOUS, $service->test_bale( '@gnm_test', 'GNM test' )->status() );
	}

	/** Test-send service remains structurally isolated from Feed/Retry/Entry Meta state. */
	public function test_service_has_no_feed_retry_or_entry_meta_dependency(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/ProviderTestService.php' );
		self::assertIsString( $source );
		self::assertStringNotContainsString( 'SynchronousDispatcher', $source );
		self::assertStringNotContainsString( 'NotificationFeedProcessor', $source );
		self::assertStringNotContainsString( 'ManualRetryHandler', $source );
		self::assertStringNotContainsString( 'EntryMetaDeliveryStore', $source );
		self::assertStringNotContainsString( 'gform_update_meta', $source );
		self::assertStringNotContainsString( 'GFAPI', $source );
	}

	/**
	 * Build one configured provider/channel settings fixture.
	 *
	 * @return array<string, mixed>
	 */
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
