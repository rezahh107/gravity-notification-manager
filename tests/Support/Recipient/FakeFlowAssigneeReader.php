<?php
/**
 * Deterministic Gravity Flow assignee reader fake.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Recipient;

use GravityNotify\Recipient\FlowAssigneeReader;

/**
 * Returns one configured assignee collection without Gravity Flow runtime access.
 */
final class FakeFlowAssigneeReader implements FlowAssigneeReader {

	/**
	 * Assignee collection.
	 *
	 * @var array<string, mixed>
	 */
	private array $collection;

	/**
	 * Last explicitly selected Step ID.
	 *
	 * @var int|null
	 */
	public ?int $last_step_id = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $collection Assignee collection.
	 */
	public function __construct( array $collection ) {
		$this->collection = $collection;
	}

	/**
	 * Return the configured fake assignee collection.
	 *
	 * @param array $entry   Ignored Entry object.
	 * @param array $form    Ignored Form object.
	 * @param int   $step_id Explicit selected Step ID.
	 * @return array<string, mixed>
	 */
	public function read( array $entry, array $form, int $step_id ): array {
		unset( $entry, $form );
		$this->last_step_id = $step_id;
		return $this->collection;
	}
}
