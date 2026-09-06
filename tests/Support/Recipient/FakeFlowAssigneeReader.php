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
	 * @param array $entry Ignored Entry object.
	 * @param array $form Ignored Form object.
	 * @return array<string, mixed>
	 */
	public function read( array $entry, array $form ): array {
		unset( $entry, $form );
		return $this->collection;
	}
}
