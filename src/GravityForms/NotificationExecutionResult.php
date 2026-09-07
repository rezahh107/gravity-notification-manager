<?php
/**
 * Request-local notification execution outcome.
 *
 * @package GravityNotify
 */

namespace GravityNotify\GravityForms;

use GravityNotify\Delivery\AttemptResult;

/**
 * Carries safe WU-04 execution facts without persistent delivery state.
 */
final class NotificationExecutionResult {

	/**
	 * Transport attempts.
	 *
	 * @var array<int, AttemptResult>
	 */
	private array $attempts;

	/**
	 * Safe resolution/execution skips.
	 *
	 * @var array<int, array{subject:string,reason:string}>
	 */
	private array $skips;

	/**
	 * Whether the logical notification obtained documented delivery acceptance.
	 *
	 * @var bool
	 */
	private bool $delivery_succeeded;

	/**
	 * Constructor.
	 *
	 * @param array<int, AttemptResult>                         $attempts Transport attempts.
	 * @param array<int, array{subject:string,reason:string}>   $skips Safe skip classifications.
	 * @param bool                                              $delivery_succeeded Delivery acceptance.
	 */
	public function __construct( array $attempts, array $skips, bool $delivery_succeeded ) {
		$this->attempts           = array_values( $attempts );
		$this->skips              = array_values( $skips );
		$this->delivery_succeeded = $delivery_succeeded;
	}

	/**
	 * Transport attempts.
	 *
	 * @return array<int, AttemptResult>
	 */
	public function attempts(): array {
		return $this->attempts;
	}

	/**
	 * Safe skip classifications.
	 *
	 * @return array<int, array{subject:string,reason:string}>
	 */
	public function skips(): array {
		return $this->skips;
	}

	/**
	 * Whether delivery obtained documented acceptance.
	 *
	 * This status is intentionally independent from Gravity Flow step completion.
	 *
	 * @return bool
	 */
	public function delivery_succeeded(): bool {
		return $this->delivery_succeeded;
	}
}
