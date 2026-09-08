<?php
/**
 * Gravity Flow assignee identity test double.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Recipient;

/**
 * Exposes the documented Gravity Flow assignee identity methods.
 */
final class GravityFlowAssigneeIdentityStub {

	/**
	 * Assignee type.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Assignee identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Constructor.
	 *
	 * @param string $type Assignee type.
	 * @param string $id   Assignee identifier.
	 */
	public function __construct( string $type, string $id ) {
		$this->type = $type;
		$this->id   = $id;
	}

	/**
	 * Return the configured assignee type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Return the configured assignee identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}
}
