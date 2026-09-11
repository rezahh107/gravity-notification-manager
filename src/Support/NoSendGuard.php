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

	/** Determine whether automated no-send mode is enabled. */
	public static function is_enabled(): bool {
		return defined( 'GRAVITY_NOTIFY_TEST_NO_SEND' ) && true === GRAVITY_NOTIFY_TEST_NO_SEND;
	}

	/**
	 * Fail closed before an outbound boundary is used in automated tests.
	 *
	 * @param string $boundary Human-readable boundary name, such as http, sms, or bale.
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

	/**
	 * Allow automated HTTP only to an explicit loopback simulator URL.
	 *
	 * @param string $url Destination URL.
	 * @throws RuntimeException When no-send mode is active and URL is not loopback.
	 */
	public static function assert_http_url_allowed( string $url ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( self::is_test_loopback_http_url( $url ) ) {
			return;
		}

		self::assert_outbound_allowed( 'http' );
	}

	/**
	 * Require an explicitly test-only loopback HTTP endpoint.
	 *
	 * @param string $url Candidate simulator URL.
	 * @throws RuntimeException When test mode is off or URL is not loopback HTTP.
	 */
	public static function assert_test_loopback_http_url( string $url ): void {
		if ( self::is_enabled() && self::is_test_loopback_http_url( $url ) ) {
			return;
		}

		throw new RuntimeException( 'Test IPPanel endpoint must be an HTTP loopback URL while no-send mode is active.' );
	}

	/**
	 * Check the narrow loopback URL shape admitted by automated tests.
	 *
	 * @param string $url Candidate URL.
	 */
	private static function is_test_loopback_http_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		return 'http' === ( $parts['scheme'] ?? '' )
			&& '127.0.0.1' === ( $parts['host'] ?? '' )
			&& isset( $parts['port'] )
			&& 0 < (int) $parts['port']
			&& 65536 > (int) $parts['port'];
	}
}
