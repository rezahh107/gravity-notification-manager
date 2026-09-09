<?php
/**
 * Deterministic manual Retry runtime fake.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Support\GravityForms;

use GravityNotify\GravityForms\ManualRetryRuntimeInterface;

/**
 * Supplies capability, nonce and GFAPI-like fixtures without WordPress I/O.
 */
final class FakeManualRetryRuntime implements ManualRetryRuntimeInterface {

	/**
	 * Capability result.
	 *
	 * @var bool
	 */
	public bool $capability = true;

	/**
	 * Nonce verification result.
	 *
	 * @var bool
	 */
	public bool $nonce_valid = true;

	/**
	 * Entry fixtures.
	 *
	 * @var array<int, array>
	 */
	public array $entries = array();

	/**
	 * Form fixtures.
	 *
	 * @var array<int, array>
	 */
	public array $forms = array();

	/**
	 * Feed fixtures.
	 *
	 * @var array<int, array>
	 */
	public array $feeds = array();

	/**
	 * Whether the current user may Retry.
	 *
	 * @return bool
	 */
	public function current_user_can_retry(): bool {
		return $this->capability;
	}

	/**
	 * Verify a deterministic nonce outcome.
	 *
	 * @param string $nonce Nonce.
	 * @param int    $entry_id Entry ID.
	 * @param int    $feed_id Feed ID.
	 * @return bool
	 */
	public function verify_nonce( string $nonce, int $entry_id, int $feed_id ): bool {
		unset( $entry_id, $feed_id );
		return '' !== $nonce && $this->nonce_valid;
	}

	/**
	 * Load one Entry fixture.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array|null
	 */
	public function get_entry( int $entry_id ): ?array {
		return $this->entries[ $entry_id ] ?? null;
	}

	/**
	 * Load one Form fixture.
	 *
	 * @param int $form_id Form ID.
	 * @return array|null
	 */
	public function get_form( int $form_id ): ?array {
		return $this->forms[ $form_id ] ?? null;
	}

	/**
	 * Load one Feed fixture.
	 *
	 * @param int $feed_id Feed ID.
	 * @return array|null
	 */
	public function get_feed( int $feed_id ): ?array {
		return $this->feeds[ $feed_id ] ?? null;
	}
}
