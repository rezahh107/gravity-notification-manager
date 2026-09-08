<?php
/**
 * Gravity Flow assignee Step test double.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Recipient;

/**
 * Returns one configured assignee collection from a selected Step.
 */
final class GravityFlowAssigneeStepStub {

	/**
	 * Configured assignee collection.
	 *
	 * @var mixed
	 */
	private $assignees;

	/**
	 * Constructor.
	 *
	 * @param mixed $assignees Assignee collection.
	 */
	public function __construct( $assignees ) {
		$this->assignees = $assignees;
	}

	/**
	 * Return the configured assignee collection.
	 *
	 * @return mixed
	 */
	public function get_assignees() {
		return $this->assignees;
	}
}
