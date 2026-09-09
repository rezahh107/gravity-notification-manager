<?php
/**
 * Explicit Notification Point re-verification service.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

/**
 * Creates a fresh inspector per verification so stale UI state is never trusted.
 */
final class PointVerificationService {

	/**
	 * Read-only authoritative configuration source.
	 *
	 * @var ConfigurationSourceInterface
	 */
	private ConfigurationSourceInterface $source;

	/**
	 * Create the re-verification service.
	 *
	 * @param ConfigurationSourceInterface $source Read-only authoritative source.
	 */
	public function __construct( ConfigurationSourceInterface $source ) {
		$this->source = $source;
	}

	/**
	 * Re-read one Notification Point from authoritative current configuration.
	 *
	 * @param int $form_id Gravity Forms form ID.
	 * @param int $feed_id GNM Feed ID.
	 * @return array<string, mixed>|null
	 */
	public function verify( int $form_id, int $feed_id ): ?array {
		if ( $form_id < 1 || $feed_id < 1 ) {
			return null;
		}

		return ( new PointInspector( $this->source ) )->find( $form_id, $feed_id );
	}
}
