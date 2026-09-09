<?php
/**
 * Gravity Forms Entry Meta delivery-state persistence.
 *
 * @package GravityNotify
 */

namespace GravityNotify\DeliveryState;

/**
 * Stores one namespaced/versioned JSON state document in Gravity Forms Entry Meta.
 */
final class EntryMetaDeliveryStore implements DeliveryStateStoreInterface {

	/**
	 * Canonical WU-05 Entry Meta key.
	 */
	public const META_KEY = 'gravity_notify_delivery_state_v1';

	/**
	 * Read delivery state for one Entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return DeliveryStateReadResult
	 */
	public function read( int $entry_id ): DeliveryStateReadResult {
		if ( $entry_id <= 0 || ! function_exists( 'gform_get_meta' ) ) {
			return DeliveryStateReadResult::malformed();
		}

		$raw = gform_get_meta( $entry_id, self::META_KEY );
		if ( false === $raw || '' === $raw || null === $raw ) {
			return DeliveryStateReadResult::missing();
		}

		if ( ! is_string( $raw ) ) {
			return DeliveryStateReadResult::malformed();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return DeliveryStateReadResult::malformed();
		}

		return DeliveryStateReadResult::valid( $decoded );
	}

	/**
	 * Replace the versioned state document for one Entry.
	 *
	 * @param int                  $entry_id Entry ID.
	 * @param int                  $form_id  Form ID.
	 * @param array<string, mixed> $state    State document.
	 * @return bool
	 */
	public function write( int $entry_id, int $form_id, array $state ): bool {
		if ( $entry_id <= 0 || $form_id <= 0 || ! function_exists( 'gform_update_meta' ) ) {
			return false;
		}

		$encoded = json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			return false;
		}

		return false !== gform_update_meta( $entry_id, self::META_KEY, $encoded, $form_id );
	}
}
