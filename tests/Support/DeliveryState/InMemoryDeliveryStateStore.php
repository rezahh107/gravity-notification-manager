<?php
/**
 * Deterministic in-memory WU-05 state store.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\DeliveryState;

use GravityNotify\DeliveryState\DeliveryStateReadResult;
use GravityNotify\DeliveryState\DeliveryStateStoreInterface;

/**
 * Persists state without WordPress or Gravity Forms for unit tests.
 */
final class InMemoryDeliveryStateStore implements DeliveryStateStoreInterface {

	/**
	 * Stored state keyed by Entry ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $states = array();

	/**
	 * Entry IDs whose state should read as malformed.
	 *
	 * @var array<int, bool>
	 */
	public array $malformed = array();

	/**
	 * Number of successful write attempts.
	 *
	 * @var int
	 */
	public int $write_count = 0;

	/**
	 * Read state for one Entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return DeliveryStateReadResult
	 */
	public function read( int $entry_id ): DeliveryStateReadResult {
		if ( true === ( $this->malformed[ $entry_id ] ?? false ) ) {
			return DeliveryStateReadResult::malformed();
		}

		if ( ! isset( $this->states[ $entry_id ] ) ) {
			return DeliveryStateReadResult::missing();
		}

		return DeliveryStateReadResult::valid( $this->states[ $entry_id ] );
	}

	/**
	 * Persist state for one Entry.
	 *
	 * @param int                  $entry_id Entry ID.
	 * @param int                  $form_id  Form ID.
	 * @param array<string, mixed> $state    State.
	 * @return bool
	 */
	public function write( int $entry_id, int $form_id, array $state ): bool {
		unset( $form_id );
		++$this->write_count;
		$this->states[ $entry_id ] = $state;
		unset( $this->malformed[ $entry_id ] );
		return true;
	}
}
