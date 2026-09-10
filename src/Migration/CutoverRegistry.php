<?php
/**
 * Temporary WU-08 sender-authority registry.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

/**
 * Stores only bounded cutover authority facts; never delivery state or secrets.
 */
final class CutoverRegistry {

	public const OPTION = 'gravity_notify_cutover_v1';

	/** @return array<string, array<string, mixed>> */
	public static function records(): array {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $value ) ? $value : array();
	}

	/** @return array<string, mixed>|null */
	public static function record( string $scope_id ): ?array {
		$records = self::records();
		return isset( $records[ $scope_id ] ) && is_array( $records[ $scope_id ] ) ? $records[ $scope_id ] : null;
	}

	/** Persist a prepared direct-GF scope idempotently. */
	public static function prepare_direct( int $form_id, int $legacy_index, string $fingerprint, int $feed_id ): bool {
		$scope_id = LegacyRuleMapper::direct_scope_id( $form_id, $legacy_index, $fingerprint );
		return self::put(
			$scope_id,
			array(
				'version'             => 1,
				'source_type'         => 'direct_gf',
				'form_id'             => $form_id,
				'legacy_rule_index'   => $legacy_index,
				'legacy_fingerprint'  => $fingerprint,
				'legacy_step_id'      => 0,
				'feed_id'             => $feed_id,
				'target_flow_step_id' => 0,
				'state'               => CutoverSequence::PREPARED,
			)
		);
	}

	/** Persist an operator-confirmed Flow scope after read-only target verification. */
	public static function prepare_flow( string $source_type, int $form_id, int $legacy_step_id, int $feed_id, int $target_flow_step_id ): ?string {
		if ( ! in_array( $source_type, array( 'flow_step', 'flow_workflow' ), true ) || 1 > $form_id || 1 > $feed_id || 1 > $target_flow_step_id ) {
			return null;
		}
		if ( 'flow_step' === $source_type && 1 > $legacy_step_id ) {
			return null;
		}
		if ( 'flow_workflow' === $source_type ) {
			$legacy_step_id = 0;
		}

		$scope_id = sprintf( '%s:%d:%d:%d:%d', $source_type, $form_id, $legacy_step_id, $feed_id, $target_flow_step_id );
		$ok       = self::put(
			$scope_id,
			array(
				'version'             => 1,
				'source_type'         => $source_type,
				'form_id'             => $form_id,
				'legacy_rule_index'   => -1,
				'legacy_fingerprint'  => '',
				'legacy_step_id'      => $legacy_step_id,
				'feed_id'             => $feed_id,
				'target_flow_step_id' => $target_flow_step_id,
				'state'               => CutoverSequence::PREPARED,
			)
		);
		return $ok ? $scope_id : null;
	}

	/** Persist only a valid state transition. */
	public static function set_state( string $scope_id, string $state ): bool {
		$record = self::record( $scope_id );
		if ( null === $record || ! in_array( $state, array( CutoverSequence::PREPARED, CutoverSequence::LEGACY_DISABLED, CutoverSequence::GREENFIELD_ENABLED ), true ) ) {
			return false;
		}
		$record['state'] = $state;
		return self::put( $scope_id, $record );
	}

	/** Whether one current legacy direct Rule may execute. */
	public static function legacy_direct_rule_allowed( int $form_id, int $legacy_index, array $rule ): bool {
		$fingerprint = LegacyRuleMapper::fingerprint( $rule );
		$scope_id    = LegacyRuleMapper::direct_scope_id( $form_id, $legacy_index, $fingerprint );
		$record      = self::record( $scope_id );
		if ( null === $record ) {
			return true;
		}
		return ! in_array( $record['state'] ?? '', array( CutoverSequence::LEGACY_DISABLED, CutoverSequence::GREENFIELD_ENABLED ), true );
	}

	/** Whether one current legacy Flow Step event may execute. */
	public static function legacy_flow_step_allowed( int $form_id, int $step_id ): bool {
		foreach ( self::records() as $record ) {
			if ( ! is_array( $record ) || 'flow_step' !== ( $record['source_type'] ?? '' ) ) {
				continue;
			}
			if ( $form_id === (int) ( $record['form_id'] ?? 0 ) && $step_id === (int) ( $record['legacy_step_id'] ?? 0 ) && in_array( $record['state'] ?? '', array( CutoverSequence::LEGACY_DISABLED, CutoverSequence::GREENFIELD_ENABLED ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/** Whether one current legacy workflow-complete event may execute. */
	public static function legacy_workflow_allowed( int $form_id ): bool {
		foreach ( self::records() as $record ) {
			if ( is_array( $record ) && 'flow_workflow' === ( $record['source_type'] ?? '' ) && $form_id === (int) ( $record['form_id'] ?? 0 ) && in_array( $record['state'] ?? '', array( CutoverSequence::LEGACY_DISABLED, CutoverSequence::GREENFIELD_ENABLED ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/** Whether an active target Feed has explicit greenfield authority. */
	public static function feed_authorized( int $feed_id ): bool {
		foreach ( self::records() as $record ) {
			if ( is_array( $record ) && $feed_id === (int) ( $record['feed_id'] ?? 0 ) && CutoverSequence::GREENFIELD_ENABLED === ( $record['state'] ?? '' ) ) {
				return self::legacy_identity_still_safe( $record );
			}
		}
		return false;
	}

	/** Re-check the legacy identity so source drift disables greenfield authority. */
	private static function legacy_identity_still_safe( array $record ): bool {
		$source_type = (string) ( $record['source_type'] ?? '' );
		if ( 'direct_gf' !== $source_type ) {
			return true;
		}
		$legacy = function_exists( 'get_option' ) ? get_option( 'gfsms_settings', array() ) : array();
		$rules  = is_array( $legacy ) && isset( $legacy['gf_rules'] ) && is_array( $legacy['gf_rules'] ) ? $legacy['gf_rules'] : array();
		$index  = (int) ( $record['legacy_rule_index'] ?? -1 );
		if ( ! array_key_exists( $index, $rules ) ) {
			return true;
		}
		$rule = $rules[ $index ];
		return is_array( $rule ) && (string) ( $record['legacy_fingerprint'] ?? '' ) === LegacyRuleMapper::fingerprint( $rule );
	}

	private static function put( string $scope_id, array $record ): bool {
		if ( '' === $scope_id || ! function_exists( 'update_option' ) ) {
			return false;
		}
		$records              = self::records();
		$existing             = $records[ $scope_id ] ?? null;
		$records[ $scope_id ] = $record;
		if ( $existing === $record ) {
			return true;
		}
		update_option( self::OPTION, $records, false );
		$read_back = self::records();
		return isset( $read_back[ $scope_id ] ) && $read_back[ $scope_id ] === $record;
	}
}
