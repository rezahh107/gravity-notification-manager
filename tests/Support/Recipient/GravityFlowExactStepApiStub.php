<?php
/**
 * Gravity Flow exact-Step API test double.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Recipient;

/**
 * Exposes exact-Step lookup plus a tracked legacy current-Step path.
 */
final class GravityFlowExactStepApiStub {

	/**
	 * Exact Step map.
	 *
	 * @var array<int, object>
	 */
	private static array $steps = array();

	/**
	 * Current workflow Step.
	 *
	 * @var object|null
	 */
	private static ?object $current_step = null;

	/**
	 * Last exact Step ID requested.
	 *
	 * @var int|null
	 */
	public static ?int $last_step_id = null;

	/**
	 * Last Entry supplied to exact-Step lookup.
	 *
	 * @var array|null
	 */
	public static ?array $last_entry = null;

	/**
	 * Number of legacy current-Step lookups.
	 *
	 * @var int
	 */
	public static int $current_step_calls = 0;

	/**
	 * Constructor.
	 *
	 * @param int $form_id Form ID.
	 */
	public function __construct( int $form_id ) {
		unset( $form_id );
	}

	/**
	 * Configure exact and current Step fixtures.
	 *
	 * @param array<int, object> $steps        Exact Step map.
	 * @param object|null        $current_step Current workflow Step.
	 * @return void
	 */
	public static function configure( array $steps, ?object $current_step ): void {
		self::$steps              = $steps;
		self::$current_step       = $current_step;
		self::$last_step_id       = null;
		self::$last_entry         = null;
		self::$current_step_calls = 0;
	}

	/**
	 * Return one explicitly selected Step for the current Entry.
	 *
	 * @param int   $step_id Explicit Step ID.
	 * @param array $entry   Entry context.
	 * @return object|null
	 */
	public function get_step( int $step_id, array $entry ): ?object {
		self::$last_step_id = $step_id;
		self::$last_entry   = $entry;
		return self::$steps[ $step_id ] ?? null;
	}

	/**
	 * Track any prohibited current-Step heuristic lookup.
	 *
	 * @param array $entry Entry context.
	 * @return object|null
	 */
	public function get_current_step( array $entry ): ?object {
		unset( $entry );
		++self::$current_step_calls;
		return self::$current_step;
	}
}
