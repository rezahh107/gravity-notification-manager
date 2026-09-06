<?php
/**
 * Gravity Flow assignee access seam for recipient resolution.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Recipient;

/**
 * Reads current documented Gravity Flow assignee identities without mutation.
 */
interface FlowAssigneeReader {

	/**
	 * Read current-step assignees.
	 *
	 * Return shape:
	 * array(
	 *     'available' => bool,
	 *     'reason'    => string,
	 *     'assignees' => array<int, array{type:string,id:string}>,
	 * ).
	 *
	 * @param array $entry Current Gravity Forms Entry object.
	 * @param array $form  Current Gravity Forms Form object.
	 * @return array<string, mixed>
	 */
	public function read( array $entry, array $form ): array;
}
