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
	private ConfigurationSourceInterface $source;

	public function __construct( ConfigurationSourceInterface $source ) {
		$this->source = $source;
	}

	/** @return array<string,mixed>|null */
	public function verify( int $form_id, int $feed_id ): ?array {
		if ( $form_id < 1 || $feed_id < 1 ) {
			return null;
		}
		return ( new PointInspector( $this->source ) )->find( $form_id, $feed_id );
	}
}
