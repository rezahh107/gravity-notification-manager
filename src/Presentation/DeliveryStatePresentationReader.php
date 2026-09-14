<?php
/**
 * Read-only WU-07 projection over canonical WU-05 Entry Meta state.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Presentation;

use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\DeliveryState\DeliveryStateReadResult;
use GravityNotify\DeliveryState\DeliveryStateStoreInterface;

/**
 * Projects trusted WU-05 state into bounded operator-safe presentation facts.
 */
final class DeliveryStatePresentationReader {

	/**
	 * State store.
	 *
	 * @var DeliveryStateStoreInterface
	 */
	private DeliveryStateStoreInterface $store;

	/**
	 * Existing WU-05 validator/decision authority.
	 *
	 * @var DeliveryStateManager
	 */
	private DeliveryStateManager $manager;

	/**
	 * Constructor.
	 *
	 * @param DeliveryStateStoreInterface $store   State store.
	 * @param DeliveryStateManager        $manager WU-05 state manager.
	 */
	public function __construct( DeliveryStateStoreInterface $store, DeliveryStateManager $manager ) {
		$this->store   = $store;
		$this->manager = $manager;
	}

	/**
	 * Read one Entry without mutating state.
	 *
	 * Malformed persisted state is never treated as resolved. Missing state is
	 * distinct from malformed state and does not fabricate a notification target.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array<string, mixed>
	 */
	public function read_entry( int $entry_id ): array {
		if ( 0 >= $entry_id ) {
			return $this->malformed_entry( $entry_id );
		}

		$read = $this->store->read( $entry_id );
		if ( DeliveryStateReadResult::MISSING === $read->status() ) {
			return array(
				'entry_id'                 => $entry_id,
				'read_status'              => DeliveryStateReadResult::MISSING,
				'entry_requires_attention' => false,
				'targets'                  => array(),
			);
		}

		if ( DeliveryStateReadResult::VALID !== $read->status() ) {
			return $this->malformed_entry( $entry_id );
		}

		$state = $read->state();
		if ( ! $this->is_valid_root_identity( $state, $entry_id ) ) {
			return $this->malformed_entry( $entry_id );
		}

		$targets         = array();
		$has_malformed   = false;
		$needs_attention = false;

		foreach ( array_keys( $state['notifications'] ) as $key ) {
			$feed_id = $this->feed_id_from_key( $key );
			if ( null === $feed_id ) {
				$has_malformed   = true;
				$needs_attention = true;
				continue;
			}

			$target = $this->manager->target_state( $entry_id, $feed_id );
			if ( null === $target ) {
				$targets[] = array(
					'trusted'            => false,
					'feed_id'            => $feed_id,
					'form_id'            => null,
					'feed_name'          => '',
					'channel'            => '',
					'final_status'       => null,
					'attention_required' => true,
					'retry_eligibility'  => DeliveryStateManager::RETRY_STATE_MALFORMED,
					'last_execution'     => null,
				);
				$has_malformed   = true;
				$needs_attention = true;
				continue;
			}

			$projected       = $this->project_target( $entry_id, $feed_id, $target );
			$targets[]       = $projected;
			$needs_attention = $needs_attention || true === $projected['attention_required'];
		}

		usort(
			$targets,
			static fn( array $left, array $right ): int => (int) $left['feed_id'] <=> (int) $right['feed_id']
		);

		return array(
			'entry_id'                 => $entry_id,
			'read_status'              => $has_malformed ? DeliveryStateReadResult::MALFORMED : DeliveryStateReadResult::VALID,
			'entry_requires_attention' => $needs_attention,
			'targets'                  => $targets,
		);
	}

	/**
	 * Project one target after complete WU-05 validation succeeds.
	 *
	 * Provider identifiers/references, recipient values, message content and raw
	 * diagnostics are deliberately omitted from the WU-07 operator projection.
	 *
	 * @param int                  $entry_id Entry ID.
	 * @param int                  $feed_id  Feed ID.
	 * @param array<string, mixed> $target   Trusted WU-05 target.
	 * @return array<string, mixed>
	 */
	private function project_target( int $entry_id, int $feed_id, array $target ): array {
		$executions     = $target['executions'];
		$last_execution = end( $executions );
		$attempt_status = array();

		if ( is_array( $last_execution ) && isset( $last_execution['attempts'] ) && is_array( $last_execution['attempts'] ) ) {
			foreach ( $last_execution['attempts'] as $attempt ) {
				if ( is_array( $attempt ) && isset( $attempt['status'] ) && is_string( $attempt['status'] ) ) {
					$attempt_status[] = $attempt['status'];
				}
			}
		}

		return array(
			'trusted'            => true,
			'feed_id'            => $feed_id,
			'form_id'            => $target['form_id'],
			'feed_name'          => $target['feed_name'],
			'channel'            => $target['channel'],
			'final_status'       => $target['final_status'],
			'attention_required' => $target['attention_required'],
			'retry_eligibility'  => $this->manager->retry_eligibility( $entry_id, $feed_id ),
			'last_execution'     => is_array( $last_execution )
				? array(
					'type'             => $last_execution['type'],
					'timestamp'        => $last_execution['timestamp'],
					'attempt_statuses' => $attempt_status,
				)
				: null,
		);
	}

	/**
	 * Validate only root identity needed to enumerate target keys safely.
	 * Complete target validation is delegated to DeliveryStateManager.
	 *
	 * @param array<string, mixed> $state    State.
	 * @param int                  $entry_id Entry ID.
	 * @return bool
	 */
	private function is_valid_root_identity( array $state, int $entry_id ): bool {
		return DeliveryStateManager::SCHEMA_NAMESPACE === ( $state['namespace'] ?? null )
			&& DeliveryStateManager::SCHEMA_VERSION === ( $state['schema_version'] ?? null )
			&& ( $state['entry_id'] ?? null ) === $entry_id
			&& isset( $state['notifications'] )
			&& is_array( $state['notifications'] );
	}

	/**
	 * Parse the canonical WU-05 target key.
	 *
	 * @param mixed $key State-map key.
	 * @return int|null
	 */
	private function feed_id_from_key( $key ): ?int {
		if ( ! is_string( $key ) || 1 !== preg_match( '/^feed:([1-9][0-9]*)$/', $key, $matches ) ) {
			return null;
		}

		$feed_id = (int) $matches[1];
		return 0 < $feed_id ? $feed_id : null;
	}

	/**
	 * Build an explicit untrusted entry model.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array<string, mixed>
	 */
	private function malformed_entry( int $entry_id ): array {
		return array(
			'entry_id'                 => $entry_id,
			'read_status'              => DeliveryStateReadResult::MALFORMED,
			'entry_requires_attention' => true,
			'targets'                  => array(),
		);
	}
}
