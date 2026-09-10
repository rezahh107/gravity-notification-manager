<?php
/**
 * Read-only WU-08 Gravity Flow placement verification.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

use GravityNotify\Admin\ConfigurationSourceInterface;

/**
 * Verifies an operator-selected target Step/Feed without mutating topology.
 */
final class FlowStepVerifier {

	public function __construct( private ConfigurationSourceInterface $source ) {}

	/** @return array{ready:bool,reason:string} */
	public function verify( int $form_id, int $feed_id, int $target_step_id ): array {
		if ( 1 > $form_id || 1 > $feed_id || 1 > $target_step_id ) {
			return array( 'ready' => false, 'reason' => 'invalid_identity' );
		}
		$placements = $this->source->workflow_placements( $form_id, array( $feed_id ) );
		if ( null === $placements ) {
			return array( 'ready' => false, 'reason' => 'gravity_flow_unavailable' );
		}
		foreach ( $placements as $placement ) {
			if ( $target_step_id === (int) ( $placement['step_id'] ?? 0 ) && true === (bool) ( $placement['active'] ?? false ) && in_array( $feed_id, $placement['feed_ids'] ?? array(), true ) ) {
				return array( 'ready' => true, 'reason' => '' );
			}
		}
		return array( 'ready' => false, 'reason' => 'target_flow_step_missing_inactive_or_mismatched' );
	}
}
