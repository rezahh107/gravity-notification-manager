<?php
/**
 * Delivery-state persistence contract.
 *
 * @package GravityNotify
 */

namespace GravityNotify\DeliveryState;

/**
 * Persists the lightweight WU-05 state without owning transport behavior.
 */
interface DeliveryStateStoreInterface {

	/**
	 * Read state for one Entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return DeliveryStateReadResult
	 */
	public function read( int $entry_id ): DeliveryStateReadResult;

	/**
	 * Replace the versioned state document for one Entry.
	 *
	 * @param int                  $entry_id Entry ID.
	 * @param int                  $form_id  Form ID.
	 * @param array<string, mixed> $state    Versioned state document.
	 * @return bool
	 */
	public function write( int $entry_id, int $form_id, array $state ): bool;
}
