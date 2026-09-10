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

	/** Enable one prepared scope without intentional sender overlap. */
	public function enable( string $scope_id ): bool {
		$record = CutoverRegistry::record( $scope_id );
		if ( null === $record || CutoverSequence::PREPARED !== ( $record['state'] ?? '' ) || ! $this->target_ready( $record ) ) {
			return false;
		}
		if ( CutoverSequence::LEGACY_DISABLED !== CutoverSequence::enable_next( CutoverSequence::PREPARED, true, false ) ) {
			return false;
		}
		if ( ! CutoverRegistry::set_state( $scope_id, CutoverSequence::LEGACY_DISABLED ) || ! $this->legacy_inactive( $record ) ) {
			CutoverRegistry::set_state( $scope_id, CutoverSequence::PREPARED );
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

	/** Roll one scope back by closing greenfield before restoring legacy. */
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

	/** Prepare an operator-confirmed Flow scope after read-only placement verification. */
	public function prepare_flow( string $source_type, int $form_id, int $legacy_step_id, int $feed_id, int $target_flow_step_id ): ?string {
		$verification = ( new FlowStepVerifier( new WordPressConfigurationSource() ) )->verify( $form_id, $feed_id, $target_flow_step_id );
		if ( ! $verification['ready'] || ! $this->set_feed_active( $feed_id, false ) ) {
			return null;
		}
		return CutoverRegistry::prepare_flow( $source_type, $form_id, $legacy_step_id, $feed_id, $target_flow_step_id );
	}

	/** @param array<string, mixed> $record */
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

	/** @param array<string, mixed> $record */
	private function legacy_inactive( array $record ): bool {
		$source_type = (string) ( $record['source_type'] ?? '' );
		if ( 'direct_gf' === $source_type ) {
			$legacy = function_exists( 'get_option' ) ? get_option( 'gfsms_settings', array() ) : array();
			$rules  = is_array( $legacy ) && is_array( $legacy['gf_rules'] ?? null ) ? $legacy['gf_rules'] : array();
			$index  = (int) ( $record['legacy_rule_index'] ?? -1 );
			if ( ! isset( $rules[ $index ] ) || ! is_array( $rules[ $index ] ) ) {
				return true;
			}
			return ! CutoverRegistry::legacy_direct_rule_allowed( (int) $record['form_id'], $index, $rules[ $index ] );
		}
		if ( 'flow_step' === $source_type ) {
			return ! CutoverRegistry::legacy_flow_step_allowed( (int) $record['form_id'], (int) $record['legacy_step_id'] );
		}
		if ( 'flow_workflow' === $source_type ) {
			return ! CutoverRegistry::legacy_workflow_allowed( (int) $record['form_id'] );
		}
		return false;
	}

	private function set_feed_active( int $feed_id, bool $active ): bool {
		if ( 1 > $feed_id || ! class_exists( '\\GFAPI' ) ) {
			return false;
		}
		$result = \GFAPI::update_feed_property( $feed_id, 'is_active', $active ? 1 : 0 );
		if ( true !== $result ) {
			return false;
		}
		$feed = $this->feed( $feed_id );
		return null !== $feed && $active === (bool) ( $feed['is_active'] ?? false );
	}

	/** @return array<string, mixed>|null */
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
