<?php
/**
 * Deterministic outbound test double.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support;

use GravityNotify\Support\NoSendGuard;

/**
 * Routes every simulated outbound boundary through the fail-closed guard.
 */
final class NoSendTransport {

	/**
	 * Attempt an outbound boundary operation.
	 *
	 * @param string $boundary Boundary identifier such as http, sms, or bale.
	 * @return void
	 */
	public function attempt( string $boundary ): void {
		NoSendGuard::assert_outbound_allowed( $boundary );
	}
}
