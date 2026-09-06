<?php
/**
 * Tests for the automated no-send harness.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit;

use GravityNotify\Support\NoSendGuard;
use GravityNotify\Tests\Support\NoSendTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Validate that automated tests cannot cross real outbound boundaries.
 */
final class NoSendGuardTest extends TestCase {

	/**
	 * Test mode must always start fail closed.
	 *
	 * @return void
	 */
	public function test_no_send_mode_is_enabled(): void {
		$this->assertTrue( NoSendGuard::is_enabled() );
	}

	/**
	 * WordPress HTTP must inherit an external-request block in tests.
	 *
	 * @return void
	 */
	public function test_wordpress_external_http_is_blocked(): void {
		$this->assertTrue( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) );
		$this->assertTrue( WP_HTTP_BLOCK_EXTERNAL );
	}

	/**
	 * Every outbound notification boundary must fail closed.
	 *
	 * @dataProvider outbound_boundary_provider
	 *
	 * @param string $boundary Boundary identifier.
	 * @return void
	 */
	public function test_outbound_boundary_fails_closed( string $boundary ): void {
		$transport = new NoSendTransport();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( $boundary );

		$transport->attempt( $boundary );
	}

	/**
	 * Provide external boundaries covered by the WU-00 harness.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function outbound_boundary_provider(): array {
		return array(
			'http' => array( 'http' ),
			'sms'  => array( 'sms' ),
			'bale' => array( 'bale' ),
		);
	}
}
