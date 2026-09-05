<?php
/**
 * Fail-closed outbound guard for automated tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Support;

use RuntimeException;

/**
 * Prevent real outbound notification/network activity while tests are active.
 */
final class NoSendGuard {

	/**
	 * Determine whether automated no-send mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return defined( 'GRAVITY_NOTIFY_TEST_NO_SEND' ) && true === GRAVITY_NOTIFY_TEST_NO_SEND;
	}

	/**
	 * Fail closed before an outbound boundary is used in automated tests.
	 *
	 * @param string $boundary Human-readable boundary name, such as http, sms, or bale.
	 * @return void
	 * @throws RuntimeException When no-send mode is active.
	 */
	public static function assert_outbound_allowed( string $boundary ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'Outbound %s activity is blocked by the Gravity Notification Manager test harness.',
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered output.
				$boundary
			)
		);
	}
}
