<?php
/**
 * Deterministic Gravity Flow step registry test double.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\GravityFlow;

/**
 * Captures public step registration without Gravity Flow runtime.
 */
final class GravityFlowStepsStub {

	/**
	 * Registered step instances.
	 *
	 * @var array<int, object>
	 */
	private static array $steps = array();

	/**
	 * Register a step.
	 *
	 * @param object $step Step.
	 * @return void
	 */
	public static function register( $step ): void {
		self::$steps[] = $step;
	}

	/**
	 * Registered steps.
	 *
	 * @return array<int, object>
	 */
	public static function steps(): array {
		return self::$steps;
	}
}
