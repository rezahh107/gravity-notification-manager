<?php
/**
 * Gravity Flow assignee access seam for recipient resolution.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Recipient;

/**
 * Reads documented Gravity Flow assignee identities without mutation.
 */
interface FlowAssigneeReader {

	/**
	 * Read assignees from one explicitly selected Step for the current Entry.
	 *
	 * Return shape:
	 * array(
	 *     'available' => bool,
	 *     'reason'    => string,
	 *     'assignees' => array<int, array{type:string,id:string}>,
	 * ).
	 *
	 * @param array $entry   Current Gravity Forms Entry object.
	 * @param array $form    Current Gravity Forms Form object.
	 * @param int   $step_id Explicit positive Gravity Flow Step ID.
	 * @return array<string, mixed>
	 */
	public function read( array $entry, array $form, int $step_id ): array;
}
