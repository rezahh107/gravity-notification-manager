<?php
/**
 * WordPress / Gravity Forms runtime seam for manual Retry.
 *
 * @package GravityNotify
 */

namespace GravityNotify\GravityForms;

/**
 * Isolates version-sensitive static/runtime APIs for deterministic Retry tests.
 */
interface ManualRetryRuntimeInterface {

	/**
	 * Whether the current user may edit Gravity Forms entries.
	 *
	 * @return bool
	 */
	public function current_user_can_retry(): bool;

	/**
	 * Verify the request nonce bound to the Entry and Feed IDs.
	 *
	 * @param string $nonce    Submitted nonce.
	 * @param int    $entry_id Entry ID.
	 * @param int    $feed_id  Feed ID.
	 * @return bool
	 */
	public function verify_nonce( string $nonce, int $entry_id, int $feed_id ): bool;

	/**
	 * Load one Entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array|null
	 */
	public function get_entry( int $entry_id ): ?array;

	/**
	 * Load one Form.
	 *
	 * @param int $form_id Form ID.
	 * @return array|null
	 */
	public function get_form( int $form_id ): ?array;

	/**
	 * Load one Feed.
	 *
	 * @param int $feed_id Feed ID.
	 * @return array|null
	 */
	public function get_feed( int $feed_id ): ?array;
}
