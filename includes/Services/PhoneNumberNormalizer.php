<?php
declare(strict_types=1);

namespace GFSMS\Services;

use GFSMS\Infrastructure\ProviderFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes and masks phone numbers for SMS processing.
 *
 * @since 3.0.0
 */
final class PhoneNumberNormalizer {

	/**
	 * @since 3.0.0
	 */
	public function __construct(
		private readonly ProviderFactory $provider_factory
	) {}

	/**
	 * Normalize a raw phone number using the primary provider.
	 *
	 * @since 3.0.0
	 *
	 * @param string $raw Raw input number.
	 * @return string|null Normalized number, or null if invalid.
	 */
	public function normalize( string $raw ): ?string {
		return $this->provider_factory->get_primary()->normalize_number( $raw );
	}

	/**
	 * Mask a mobile number for logging and display.
	 *
	 * Keeps the first 4 and last 2 digits, replacing the middle with asterisks.
	 *
	 * @since 3.0.0
	 *
	 * @param string $mobile The full mobile number.
	 * @return string Masked mobile number.
	 */
	public function mask_mobile( string $mobile ): string {
		$mobile = preg_replace( '/\D+/', '', $mobile );
		$len    = strlen( $mobile );

		if ( 4 >= $len ) {
			return str_repeat( '*', max( 1, $len ) );
		}

		return substr( $mobile, 0, 4 )
			. str_repeat( '*', max( 1, $len - 6 ) )
			. substr( $mobile, -2 );
	}
}
