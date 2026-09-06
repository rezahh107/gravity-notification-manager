<?php
/**
 * IPPanel provider-address validation tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Delivery;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Sms\IPPanelProvider;
use GravityNotify\Delivery\Sms\SmsCapability;
use GravityNotify\Delivery\Sms\SmsRequest;
use GravityNotify\Tests\Support\WordPress\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Proves documented E.164 invariants fail before HTTP I/O.
 */
final class IPPanelAddressValidationTest extends TestCase {

	/**
	 * Reject a non-E.164 recipient before touching the transport seam.
	 *
	 * @return void
	 */
	public function test_non_e164_recipient_fails_before_network(): void {
		$http     = new FakeHttpTransport( array() );
		$provider = new IPPanelProvider( implode( '-', array( 'unit', 'test', 'credential' ) ), $http );
		$sender   = implode( '', array( '+', '999', str_repeat( '1', 6 ) ) );
		$request  = SmsRequest::plain(
			SmsCapability::PLAIN,
			array( 'not-an-e164-address' ),
			$sender,
			'address validation'
		);

		$result = $provider->send( $request );

		self::assertSame( AttemptStatus::FAILED, $result->status() );
		self::assertSame( 'invalid_address_format', $result->diagnostic() );
		self::assertSame( array(), $http->requests() );
	}
}
