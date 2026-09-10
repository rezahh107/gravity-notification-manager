<?php
/**
 * Explicit WU-08 controlled cutover operations.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

use GravityNotify\Admin\WordPressConfigurationSource;
use GravityNotify\GravityForms\NotificationFeedAddOn;

/**
 * Enforces legacy-disable verification before target Feed activation.
 */
final class CutoverService {

	/**
	 * Enable one prepared scope without intentional sender overlap.
	 *
	 * @param string $scope_id Cutover scope identity.
	 * @return bool
	 */
	public function enable( string $scope_id ): bool {
		$record = CutoverRegistry::record( $scope_id );
		if (
			null === $record
			|| CutoverSequence::PREPARED !== ( $record['state'] ?? '' )
			|| ! CutoverRegistry::current_scope_identity_valid( $record )
			|| ! $this->target_ready( $record )
		) {
			return false;
		}
		if ( CutoverSequence::LEGACY_DISABLED !== CutoverSequence::enable_next( CutoverSequence::PREPARED, true, false ) ) {
			return false;
		}
		if ( ! CutoverRegistry::set_state( $scope_id, CutoverSequence::LEGACY_DISABLED ) ) {
			return false;
		}
		if ( ! $this->legacy_inactive( $record ) ) {
			return false;
		}
		if ( CutoverSequence::GREENFIELD_ENABLED !== CutoverSequence::enable_next( CutoverSequence::LEGACY_DISABLED, true, true ) ) {
			CutoverRegistry::set_state( $scope_id, CutoverSequence::PREPARED );
			return false;
		}
		if ( ! $this->set_feed_active( (int) $record['feed_id'], true ) ) {
			CutoverRegistry::set_state( $scope_id, CutoverSequence::PREPARED );
			return false;
		}
		if ( ! CutoverRegistry::set_state( $scope_id, CutoverSequence::GREENFIELD_ENABLED ) ) {
			$this->set_feed_active( (int) $record['feed_id'], false );
			CutoverRegistry::set_state( $scope_id, CutoverSequence::PREPARED );
			return false;
		}
		return CutoverRegistry::feed_authorized( (int) $record['feed_id'] );
	}

	/**
	 * Roll one scope back by closing greenfield before restoring legacy.
	 *
	 * @param string $scope_id Cutover scope identity.
	 * @return bool
	 */
	public function rollback( string $scope_id ): bool {
		$record = CutoverRegistry::record( $scope_id );
		if ( null === $record || CutoverSequence::GREENFIELD_ENABLED !== ( $record['state'] ?? '' ) ) {
			return false;
		}
		if ( ! CutoverRegistry::set_state( $scope_id, CutoverSequence::LEGACY_DISABLED ) ) {
			return false;
		}
		if ( ! $this->set_feed_active( (int) $record['feed_id'], false ) ) {
			return false;
		}
		return CutoverRegistry::set_state( $scope_id, CutoverSequence::PREPARED );
	}

	/**
	 * Prepare an operator-confirmed Flow scope after read-only placement verification.
	 *
	 * @param string $source_type         Legacy Flow source type.
	 * @param int    $form_id             Form ID.
	 * @param int    $legacy_step_id      Legacy Step ID or zero for workflow complete.
	 * @param int    $feed_id             Target Feed ID.
	 * @param int    $target_flow_step_id Target GNM Flow Step ID.
	 * @return string|null
	 */
	public function prepare_flow( string $source_type, int $form_id, int $legacy_step_id, int $feed_id, int $target_flow_step_id ): ?string {
		$verification = ( new FlowStepVerifier( new WordPressConfigurationSource() ) )->verify( $form_id, $feed_id, $target_flow_step_id );
		if ( ! $verification['ready'] || ! $this->set_feed_active( $feed_id, false ) ) {
			return null;
		}
		return CutoverRegistry::prepare_flow( $source_type, $form_id, $legacy_step_id, $feed_id, $target_flow_step_id );
	}

	/**
	 * Verify target Feed and optional Flow placement are ready for cutover.
	 *
	 * @param array<string, mixed> $record Cutover record.
	 * @return bool
	 */
	private function target_ready( array $record ): bool {
		$feed = $this->feed( (int) ( $record['feed_id'] ?? 0 ) );
		if ( null === $feed || true === (bool) ( $feed['is_active'] ?? false ) ) {
			return false;
		}
		if ( in_array( $record['source_type'] ?? '', array( 'flow_step', 'flow_workflow' ), true ) ) {
			$verification = ( new FlowStepVerifier( new WordPressConfigurationSource() ) )->verify(
				(int) $record['form_id'],
				(int) $record['feed_id'],
				(int) $record['target_flow_step_id']
			);
			return $verification['ready'];
		}
		return 'direct_gf' === ( $record['source_type'] ?? '' );
	}

	/**
	 * Verify the exact legacy sender scope is suppressed and still matches current identity.
	 *
	 * @param array<string, mixed> $record Cutover record.
	 * @return bool
	 */
	private function legacy_inactive( array $record ): bool {
		if ( ! CutoverRegistry::current_scope_identity_valid( $record ) ) {
			return false;
		}
		$source_type = (string) ( $record['source_type'] ?? '' );
		if ( 'direct_gf' === $source_type ) {
			$legacy = function_exists( 'get_option' ) ? get_option( 'gfsms_settings', null ) : null;
			if ( ! is_array( $legacy ) || ! is_array( $legacy['gf_rules'] ?? null ) ) {
				return false;
			}
			$index = (int) ( $record['legacy_rule_index'] ?? -1 );
			if ( 0 > $index || ! array_key_exists( $index, $legacy['gf_rules'] ) || ! is_array( $legacy['gf_rules'][ $index ] ) ) {
				return false;
			}
			return ! CutoverRegistry::legacy_direct_rule_allowed( (int) $record['form_id'], $index, $legacy['gf_rules'][ $index ] );
		}
		if ( 'flow_step' === $source_type ) {
			return ! CutoverRegistry::legacy_flow_step_allowed( (int) $record['form_id'], (int) $record['legacy_step_id'] );
		}
		if ( 'flow_workflow' === $source_type ) {
			return ! CutoverRegistry::legacy_workflow_allowed( (int) $record['form_id'] );
		}
		return false;
	}

	/**
	 * Set one target Feed active flag and verify exact read-back.
	 *
	 * @param int  $feed_id Target Feed ID.
	 * @param bool $active  Desired active state.
	 * @return bool
	 */
	private function set_feed_active( int $feed_id, bool $active ): bool {
		if ( 1 > $feed_id || ! class_exists( '\\GFAPI' ) ) {
			return false;
		}
		$result = \GFAPI::update_feed_property( $feed_id, 'is_active', $active ? 1 : 0 );
		if ( true !== $result ) {
			return false;
		}
		$feed = $this->feed( $feed_id );
		return null !== $feed && (bool) ( $feed['is_active'] ?? false ) === $active;
	}

	/**
	 * Read one target GNM Feed by identity.
	 *
	 * @param int $feed_id Target Feed ID.
	 * @return array<string, mixed>|null
	 */
	private function feed( int $feed_id ): ?array {
		if ( 1 > $feed_id || ! class_exists( '\\GFAPI' ) || ! class_exists( NotificationFeedAddOn::class ) ) {
			return null;
		}
		$feeds = \GFAPI::get_feeds( $feed_id, null, NotificationFeedAddOn::get_instance()->get_slug(), null );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $feeds ) ) {
			return null;
		}
		$feed = is_array( $feeds ) ? reset( $feeds ) : false;
		return is_array( $feed ) ? $feed : null;
	}
}
