<?php
/**
 * Atomic lock manager for SMS events.
 *
 * Uses WordPress options table with add_option (atomic insert)
 * to prevent race conditions between multiple queue workers.
 *
 * Lock TTL is intentionally generous (120s) to cover slow
 * HTTP requests, provider timeouts, and fallback attempts.
 *
 * @package GFSMS\Services
 * @since   3.0.0
 */

declare( strict_types = 1 );

namespace GFSMS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Class LockManager
 *
 * Atomic lock manager for SMS events.
 *
 * @since 3.0.0
 */
final class LockManager {

	/**
	 * Default lock TTL in seconds.
	 *
	 * Long enough for SMS API timeout + fallback + retry.
	 * Filterable via 'gfsms_lock_ttl' for tuning.
	 *
	 * @since 3.0.0
	 * @var int
	 */
	private const DEFAULT_LOCK_TTL = 120;

	/**
	 * Acquire an atomic lock for an entry event.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $entry_id Gravity Forms entry ID.
	 * @param string $meta_key Unique event meta key (e.g. gfsms_event_step_1_3).
	 *
	 * @return bool True if lock was acquired, false if already locked.
	 */
	public function acquire( int $entry_id, string $meta_key ): bool {
		$lock_key = $this->build_lock_key( $entry_id, $meta_key );
		$ttl      = (int) apply_filters( 'gfsms_lock_ttl', self::DEFAULT_LOCK_TTL );

		// 1. Atomic insert – returns false if key already exists.
		if ( true === add_option( $lock_key, time(), '', false ) ) {
			return true;
		}

		// 2. Key exists – check if it has expired.
		$existing = (int) get_option( $lock_key, 0 );
		if ( $existing && ( time() - $existing ) < $ttl ) {
			return false;
		}

		// 3. Expired – take over the lock.
		update_option( $lock_key, time(), false );
		return true;
	}

	/**
	 * Finalise the event: persist state and release lock atomically.
	 *
	 * Called by Dispatcher when the SMS lifecycle is complete
	 * (success or permanent failure). Retry path should use release().
	 *
	 * @since 3.0.0
	 *
	 * @param int    $entry_id Gravity Forms entry ID.
	 * @param string $meta_key Unique event meta key.
	 * @param string $state    Final state ('sent' or 'failed_permanent').
	 *
	 * @return void
	 */
	public function finalize( int $entry_id, string $meta_key, string $state ): void {
		$lock_key = $this->build_lock_key( $entry_id, $meta_key );

		// Validate the state value.
		$allowed_states = array( 'sent', 'failed_permanent' );
		if ( false === in_array( $state, $allowed_states, true ) ) {
			$state = 'failed_permanent'; // default safe state.
		}

		// Persist state in Gravity Forms entry meta.
		gform_update_meta( $entry_id, $meta_key, $state );

		// Release the option-based lock.
		delete_option( $lock_key );
	}

	/**
	 * Release the lock without changing the state.
	 *
	 * Used when we are scheduling a retry (the event isn't final yet).
	 *
	 * @since 3.0.0
	 *
	 * @param int    $entry_id Gravity Forms entry ID.
	 * @param string $meta_key Unique event meta key.
	 *
	 * @return void
	 */
	public function release( int $entry_id, string $meta_key ): void {
		delete_option( $this->build_lock_key( $entry_id, $meta_key ) );
	}

	/**
	 * Legacy helper – marks state without touching the lock.
	 *
	 * Kept for compatibility; prefer finalize() in new code.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $entry_id Gravity Forms entry ID.
	 * @param string $meta_key Unique event meta key.
	 * @param string $state    Final state ('sent' or 'failed_permanent').
	 *
	 * @return void
	 */
	public function mark_state( int $entry_id, string $meta_key, string $state ): void {
		$allowed_states = array( 'sent', 'failed_permanent' );
		if ( false === in_array( $state, $allowed_states, true ) ) {
			$state = 'failed_permanent';
		}

		gform_update_meta( $entry_id, $meta_key, $state );
	}

	/**
	 * Build a deterministic, clean option key for the lock.
	 *
	 * @since 3.0.0
	 *
	 * @param int    $entry_id Gravity Forms entry ID.
	 * @param string $meta_key Unique event meta key.
	 *
	 * @return string
	 */
	private function build_lock_key( int $entry_id, string $meta_key ): string {
		return 'gfsms_lock_' . $entry_id . '_' . sanitize_key( $meta_key );
	}
}
