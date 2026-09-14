<?php
/**
 * Provider-boundary Iranian mobile number conversion.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery\Sms;

/** Converts the canonical recipient shape only when a verified provider contract requires it. */
final class IranMobileNumber {

	/**
	 * Convert an Iranian mobile to 09xxxxxxxxx.
	 *
	 * @param string $value Canonical or local mobile number.
	 * @return string|null
	 */
	public static function local( string $value ): ?string {
		$value = trim( $value );
		if ( 1 === preg_match( '/^09[0-9]{9}$/D', $value ) ) {
			return $value;
		}
		if ( 1 === preg_match( '/^\+989[0-9]{9}$/D', $value ) ) {
			return '0' . substr( $value, 3 );
		}
		return null;
	}

	/**
	 * Convert all recipients to the local 09 form.
	 *
	 * @param array<int, string> $recipients Recipients.
	 * @return array<int, string>|null
	 */
	public static function local_recipients( array $recipients ): ?array {
		$normalized = array();
		foreach ( $recipients as $recipient ) {
			$value = self::local( $recipient );
			if ( null === $value ) {
				return null;
			}
			$normalized[] = $value;
		}
		return $normalized;
	}

}
