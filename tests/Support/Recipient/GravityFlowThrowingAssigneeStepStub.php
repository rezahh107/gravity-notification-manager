<?php
/**
 * Throwing Gravity Flow assignee Step test double.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\Recipient;

/**
 * Models an unavailable assignee collection API.
 */
final class GravityFlowThrowingAssigneeStepStub {

	/**
	 * Throw a deterministic assignee API failure.
	 *
	 * @return array
	 * @throws \RuntimeException Always for unavailable-assignee coverage.
	 */
	public function get_assignees(): array {
		throw new \RuntimeException( 'synthetic assignee API failure' );
	}
}
