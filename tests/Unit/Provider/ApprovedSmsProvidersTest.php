<?php
/**
 * Approved SMS provider adapter and fallback tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Provider;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Http\HttpResponse;
use GravityNotify\Delivery\Sms\FarazSmsProvider;
use GravityNotify\Delivery\Sms\IPPanelProvider;
use GravityNotify\Delivery\Sms\MelipayamakProvider;
use GravityNotify\Delivery\Sms\SmsCapability;
use GravityNotify\Delivery\Sms\SmsIrProvider;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\Sms\SmsRequest;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\Tests\Support\WordPress\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

/** Proves provider request shapes, result normalization, and synchronous fallback. */
final class ApprovedSmsProvidersTest extends TestCase {

	/** Melipayamak uses first-party form fields and requires a confirmed RecId for SUCCESS. */
	public function test_melipayamak_plain_send_and_result_normalization(): void {
		$http     = new FakeHttpTransport( array( HttpResponse::from_http( 200, '{"Value":"12345","RetStatus":1,"StrRetStatus":"Ok"}' ) ) );
		$provider = new MelipayamakProvider( 'user', 'pass', '50001234', $http );
		$result   = $provider->send( SmsRequest::plain( SmsCapability::PLAIN, array( '+989121234567' ), '+982100000000', 'hello' ) );

		self::assertSame( AttemptStatus::SUCCESS, $result->status() );
		self::assertSame( array( '12345' ), $result->provider_references() );
		self::assertSame( '50001234', $result->sender() );
		$request = $http->requests()[0];
		self::assertSame( 'POST', $request['method'] );
		self::assertSame( 'https://rest.payamak-panel.com/api/SendSMS/SendSMS', $request['url'] );
		parse_str( (string) $request['args']['body'], $body );
		self::assertSame( '09121234567', $body['to'] );
		self::assertSame( '50001234', $body['from'] );
		self::assertSame( 'user', $body['username'] );
		self::assertSame( 'pass', $body['password'] );
		self::assertSame( 'false', $body['isflash'] );
	}

	/** Melipayamak explicit rejection and missing acceptance evidence do not become SUCCESS. */
	public function test_melipayamak_failure_and_ambiguity_are_truthful(): void {
		$failed = new MelipayamakProvider( 'user', 'pass', '50001234', new FakeHttpTransport( array( HttpResponse::from_http( 200, '{"Value":"-7","RetStatus":0}' ) ) ) );
		self::assertSame( AttemptStatus::FAILED, $failed->send( $this->plain_request() )->status() );

		$ambiguous = new MelipayamakProvider( 'user', 'pass', '50001234', new FakeHttpTransport( array( HttpResponse::from_http( 200, '{"Value":"unknown","RetStatus":1}' ) ) ) );
		self::assertSame( AttemptStatus::AMBIGUOUS, $ambiguous->send( $this->plain_request() )->status() );
	}

	/** SMS.ir uses X-API-KEY, provider line, and documented bulk fields. */
	public function test_smsir_bulk_send_and_result_normalization(): void {
		$http = new FakeHttpTransport(
			array(
				HttpResponse::from_http( 200, '{"status":1,"message":"ok","data":{"packId":"pack-1","messageIds":[101],"cost":1.2}}' ),
			)
		);
		$provider = new SmsIrProvider( 'smsir-key', '30001234', $http );
		$result   = $provider->send( $this->plain_request() );

		self::assertSame( AttemptStatus::SUCCESS, $result->status() );
		self::assertSame( array( 'pack-1', '101' ), $result->provider_references() );
		self::assertSame( '30001234', $result->sender() );
		$request = $http->requests()[0];
		self::assertSame( 'smsir-key', $request['args']['headers']['X-API-KEY'] );
		$body = json_decode( (string) $request['args']['body'], true );
		self::assertIsArray( $body );
		self::assertSame( 30001234, $body['lineNumber'] );
		self::assertSame( array( '+989121234567' ), $body['Mobiles'], 'Do not invent an undocumented SMS.ir recipient reformat.' );
	}

	/** SMS.ir 2xx without a documented pack/message reference remains AMBIGUOUS. */
	public function test_smsir_does_not_invent_acceptance_without_reference(): void {
		$provider = new SmsIrProvider( 'smsir-key', '30001234', new FakeHttpTransport( array( HttpResponse::from_http( 200, '{"status":1,"message":"ok","data":{"cost":1.2}}' ) ) ) );
		self::assertSame( AttemptStatus::AMBIGUOUS, $provider->send( $this->plain_request() )->status() );
	}

	/** FarazSMS simple send uses Api-Key and local Iranian recipients. */
	public function test_farazsms_simple_send_uses_documented_contract(): void {
		$http     = new FakeHttpTransport( array( HttpResponse::from_http( 201, '{"status":"success","data":0,"message":"ok"}' ) ) );
		$provider = new FarazSmsProvider( 'faraz-key', '30005678', $http );
		$result   = $provider->send( $this->plain_request() );

		self::assertSame( AttemptStatus::SUCCESS, $result->status() );
		self::assertSame( array(), $result->provider_references(), 'FarazSMS success does not fabricate a message reference from data=0.' );
		self::assertSame( '30005678', $result->sender() );
		$request = $http->requests()[0];
		self::assertSame( 'faraz-key', $request['args']['headers']['Api-Key'] );
		$body = json_decode( (string) $request['args']['body'], true );
		self::assertIsArray( $body );
		self::assertSame( '30005678', $body['line_number'] );
		self::assertSame( array( '09121234567' ), $body['recipients'] );
		self::assertSame( 'english', $body['number_format'] );
	}

	/** FarazSMS pattern semantics remain pattern semantics rather than auto-converting to plain. */
	public function test_farazsms_pattern_request_is_not_translated_to_plain(): void {
		$http     = new FakeHttpTransport( array( HttpResponse::from_http( 201, '{"status":"success","data":0}' ) ) );
		$provider = new FarazSmsProvider( 'faraz-key', '30005678', $http );
		$request  = SmsRequest::pattern( SmsCapability::PATTERN, array( '+989121234567' ), '+982100000000', 'welcome', array( 'name' => 'Reza' ) );
		self::assertSame( AttemptStatus::SUCCESS, $provider->send( $request )->status() );
		self::assertSame( 'https://api.iranpayamak.com/ws/v1/sms/pattern', $http->requests()[0]['url'] );
	}

	/** Existing dispatcher performs ordered fallback across heterogeneous production adapters. */
	public function test_dispatcher_falls_back_between_approved_providers_without_redesign(): void {
		$http = new FakeHttpTransport(
			array(
				HttpResponse::from_http( 400, '{"meta":{"status":false}}' ),
				HttpResponse::from_http( 200, '{"Value":"777","RetStatus":1,"StrRetStatus":"Ok"}' ),
			)
		);
		$dispatcher = new SynchronousDispatcher(
			new SmsProviderRegistry(
				array(
					new IPPanelProvider( 'ip-key', $http, null, '+982100000000' ),
					new MelipayamakProvider( 'user', 'pass', '50001234', $http ),
				)
			)
		);

		$attempts = $dispatcher->dispatch_sms( $this->plain_request() );
		self::assertCount( 2, $attempts );
		self::assertSame( AttemptStatus::FAILED, $attempts[0]->status() );
		self::assertSame( AttemptStatus::SUCCESS, $attempts[1]->status() );
		self::assertSame( 'ippanel', $attempts[0]->provider_id() );
		self::assertSame( 'melipayamak', $attempts[1]->provider_id() );
		self::assertSame( '+982100000000', $attempts[0]->sender() );
		self::assertSame( '50001234', $attempts[1]->sender() );
	}

	private function plain_request(): SmsRequest {
		return SmsRequest::plain( SmsCapability::PLAIN, array( '+989121234567' ), '+982100000000', 'hello' );
	}
}
