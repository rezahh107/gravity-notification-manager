<?php
/**
 * Transport-attempt status values.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Delivery;

/**
 * Stable status vocabulary for one synchronous transport attempt.
 */
final class AttemptStatus {

	/**
	 * Provider/channel documented acceptance.
	 */
	public const SUCCESS = 'SUCCESS';

	/**
	 * Clear transport/provider rejection.
	 */
	public const FAILED = 'FAILED';

	/**
	 * Transport completed but acceptance cannot be established safely.
	 */
	public const AMBIGUOUS = 'AMBIGUOUS';

	/**
	 * Attempt was not eligible for execution.
	 */
	public const SKIPPED = 'SKIPPED';

	/**
	 * Return all supported values.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::SUCCESS,
			self::FAILED,
			self::AMBIGUOUS,
			self::SKIPPED,
		);
	}

	/**
	 * Determine whether a status is supported.
	 *
	 * @param string $status Candidate status.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}
}
