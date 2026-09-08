<?php
/**
 * WordPress / Gravity Forms implementation for manual Retry.
 *
 * @package GravityNotify
 */

namespace GravityNotify\GravityForms;

/**
 * Uses current stable WordPress and Gravity Forms APIs for Retry authorization/data lookup.
 */
final class WordPressManualRetryRuntime implements ManualRetryRuntimeInterface {

	/**
	 * Gravity Forms capability required for Entry mutation/send operations.
	 */
	public const CAPABILITY = 'gravityforms_edit_entries';

	/**
	 * Whether the current user may Retry notification delivery.
	 *
	 * @return bool
	 */
	public function current_user_can_retry(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( self::CAPABILITY );
	}

	/**
	 * Verify the request nonce bound to one Entry/Feed target.
	 *
	 * @param string $nonce    Nonce.
	 * @param int    $entry_id Entry ID.
	 * @param int    $feed_id  Feed ID.
	 * @return bool
	 */
	public function verify_nonce( string $nonce, int $entry_id, int $feed_id ): bool {
		return function_exists( 'wp_verify_nonce' )
			&& false !== wp_verify_nonce( $nonce, self::nonce_action( $entry_id, $feed_id ) );
	}

	/**
	 * Build the stable nonce action for one Retry target.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $feed_id  Feed ID.
	 * @return string
	 */
	public static function nonce_action( int $entry_id, int $feed_id ): string {
		return 'gravity_notify_retry_' . $entry_id . '_' . $feed_id;
	}

	/**
	 * Load one Entry through GFAPI.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array|null
	 */
	public function get_entry( int $entry_id ): ?array {
		if ( ! class_exists( '\GFAPI' ) || ! method_exists( '\GFAPI', 'get_entry' ) ) {
			return null;
		}

		$entry = \GFAPI::get_entry( $entry_id );
		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * Load one Form through GFAPI.
	 *
	 * @param int $form_id Form ID.
	 * @return array|null
	 */
	public function get_form( int $form_id ): ?array {
		if ( ! class_exists( '\GFAPI' ) || ! method_exists( '\GFAPI', 'get_form' ) ) {
			return null;
		}

		$form = \GFAPI::get_form( $form_id );
		return is_array( $form ) ? $form : null;
	}

	/**
	 * Load one Feed through the current GFAPI feed surface.
	 *
	 * @param int $feed_id Feed ID.
	 * @return array|null
	 */
	public function get_feed( int $feed_id ): ?array {
		if ( ! class_exists( '\GFAPI' ) || ! method_exists( '\GFAPI', 'get_feed' ) ) {
			return null;
		}

		$feed = \GFAPI::get_feed( $feed_id );
		return is_array( $feed ) ? $feed : null;
	}
}
