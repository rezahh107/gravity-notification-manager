<?php
/**
 * Temporary WU-08 sender-authority registry.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

use GravityNotify\Admin\WordPressConfigurationSource;
use Throwable;

/**
 * Stores only bounded cutover authority facts; never delivery state or secrets.
 */
final class CutoverRegistry {

	public const OPTION = 'gravity_notify_cutover_v1';

	/**
	 * Read all cutover records.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function records(): array {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Read one cutover record.
	 *
	 * @param string $scope_id Scope identity.
	 * @return array<string, mixed>|null
	 */
	public static function record( string $scope_id ): ?array {
		$records = self::records();
		return isset( $records[ $scope_id ] ) && is_array( $records[ $scope_id ] ) ? $records[ $scope_id ] : null;
	}

	/**
	 * Persist a prepared direct-GF scope without regressing existing authority.
	 *
	 * @param int    $form_id      Form ID.
	 * @param int    $legacy_index Stable legacy Rule index.
	 * @param string $fingerprint  Legacy Rule fingerprint.
	 * @param int    $feed_id      Target Feed ID.
	 * @return bool
	 */
	public static function prepare_direct( int $form_id, int $legacy_index, string $fingerprint, int $feed_id ): bool {
		$scope_id = LegacyRuleMapper::direct_scope_id( $form_id, $legacy_index, $fingerprint );
		$prepared = array(
			'version'             => 1,
			'source_type'         => 'direct_gf',
			'form_id'             => $form_id,
			'legacy_rule_index'   => $legacy_index,
			'legacy_fingerprint'  => $fingerprint,
			'legacy_step_id'      => 0,
			'feed_id'             => $feed_id,
			'target_flow_step_id' => 0,
			'state'               => CutoverSequence::PREPARED,
		);
		$existing = self::record( $scope_id );
		if ( null === $existing ) {
			return self::put( $scope_id, $prepared );
		}
		if ( ! self::direct_record_matches( $existing, $form_id, $legacy_index, $fingerprint, $feed_id ) ) {
			return false;
		}
		return in_array(
			$existing['state'] ?? '',
			array( CutoverSequence::PREPARED, CutoverSequence::LEGACY_DISABLED, CutoverSequence::GREENFIELD_ENABLED ),
			true
		);
	}

	/**
	 * Check immutable direct-scope identity and target Feed binding.
	 *
	 * @param array<string, mixed> $record        Existing cutover record.
	 * @param int                  $form_id       Form ID.
	 * @param int                  $legacy_index  Stable legacy Rule index.
	 * @param string               $fingerprint   Legacy Rule fingerprint.
	 * @param int                  $feed_id       Target Feed ID.
	 * @return bool
	 */
	public static function direct_record_matches( array $record, int $form_id, int $legacy_index, string $fingerprint, int $feed_id ): bool {
		return 1 === (int) ( $record['version'] ?? 0 )
			&& 'direct_gf' === (string) ( $record['source_type'] ?? '' )
			&& (int) ( $record['form_id'] ?? 0 ) === $form_id
			&& (int) ( $record['legacy_rule_index'] ?? -1 ) === $legacy_index
			&& (string) ( $record['legacy_fingerprint'] ?? '' ) === $fingerprint
			&& 0 === (int) ( $record['legacy_step_id'] ?? 0 )
			&& (int) ( $record['feed_id'] ?? 0 ) === $feed_id
			&& 0 === (int) ( $record['target_flow_step_id'] ?? 0 );
	}

	/**
	 * Persist an operator-confirmed Flow scope after read-only target verification.
	 *
	 * @param string $source_type         Flow source type.
	 * @param int    $form_id             Form ID.
	 * @param int    $legacy_step_id      Legacy Step ID, or zero for workflow complete.
	 * @param int    $feed_id             Target Feed ID.
	 * @param int    $target_flow_step_id Target Gravity Flow Feed-Step ID.
	 * @return string|null
	 */
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

	/**
	 * Persist only a valid state transition.
	 *
	 * @param string $scope_id Scope identity.
	 * @param string $state    Target authority state.
	 * @return bool
	 */
	public static function set_state( string $scope_id, string $state ): bool {
		$record = self::record( $scope_id );
		if ( null === $record || ! in_array( $state, array( CutoverSequence::PREPARED, CutoverSequence::LEGACY_DISABLED, CutoverSequence::GREENFIELD_ENABLED ), true ) ) {
			return false;
		}
		$record['state'] = $state;
		return self::put( $scope_id, $record );
	}

	/**
	 * Positively prove the current source identity for one stored cutover scope.
	 *
	 * @param array<string, mixed> $record Cutover record.
	 * @return bool
	 */
	public static function current_scope_identity_valid( array $record ): bool {
		$source_type = (string) ( $record['source_type'] ?? '' );
		if ( 'direct_gf' === $source_type ) {
			return self::direct_scope_identity_valid( $record );
		}
		if ( 'flow_step' === $source_type ) {
			return self::flow_scope_identity_valid( $record, true );
		}
		if ( 'flow_workflow' === $source_type ) {
			return self::flow_scope_identity_valid( $record, false );
		}
		return false;
	}

	/**
	 * Determine whether one current legacy direct Rule may execute.
	 *
	 * @param int                  $form_id      Form ID.
	 * @param int                  $legacy_index Stable legacy Rule index.
	 * @param array<string, mixed> $rule         Current legacy Rule.
	 * @return bool
	 */
	public static function legacy_direct_rule_allowed( int $form_id, int $legacy_index, array $rule ): bool {
		$fingerprint = LegacyRuleMapper::fingerprint( $rule );
		$scope_id    = LegacyRuleMapper::direct_scope_id( $form_id, $legacy_index, $fingerprint );
		$record      = self::record( $scope_id );
		if ( null === $record ) {
			return true;
		}
		return ! in_array( $record['state'] ?? '', array( CutoverSequence::LEGACY_DISABLED, CutoverSequence::GREENFIELD_ENABLED ), true );
	}

	/**
	 * Determine whether one current legacy Flow Step event may execute.
	 *
	 * @param int $form_id Form ID.
	 * @param int $step_id Legacy Step ID.
	 * @return bool
	 */
	public static function legacy_flow_step_allowed( int $form_id, int $step_id ): bool {
		foreach ( self::records() as $record ) {
			if ( ! is_array( $record ) || 'flow_step' !== ( $record['source_type'] ?? '' ) ) {
				continue;
			}
			if ( (int) ( $record['form_id'] ?? 0 ) === $form_id && (int) ( $record['legacy_step_id'] ?? 0 ) === $step_id && in_array( $record['state'] ?? '', array( CutoverSequence::LEGACY_DISABLED, CutoverSequence::GREENFIELD_ENABLED ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Determine whether one current legacy workflow-complete event may execute.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	public static function legacy_workflow_allowed( int $form_id ): bool {
		foreach ( self::records() as $record ) {
			if ( is_array( $record ) && 'flow_workflow' === ( $record['source_type'] ?? '' ) && (int) ( $record['form_id'] ?? 0 ) === $form_id && in_array( $record['state'] ?? '', array( CutoverSequence::LEGACY_DISABLED, CutoverSequence::GREENFIELD_ENABLED ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Determine whether an active target Feed has explicit greenfield authority.
	 *
	 * @param int $feed_id Target Feed ID.
	 * @return bool
	 */
	public static function feed_authorized( int $feed_id ): bool {
		foreach ( self::records() as $record ) {
			if ( is_array( $record ) && (int) ( $record['feed_id'] ?? 0 ) === $feed_id && CutoverSequence::GREENFIELD_ENABLED === ( $record['state'] ?? '' ) ) {
				return self::current_scope_identity_valid( $record );
			}
		}
		return false;
	}

	/**
	 * Positively prove one direct-GF scope against the current raw legacy option.
	 *
	 * @param array<string, mixed> $record Cutover record.
	 * @return bool
	 */
	private static function direct_scope_identity_valid( array $record ): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$legacy = get_option( 'gfsms_settings', null );
		if ( ! is_array( $legacy ) || ! isset( $legacy['gf_rules'] ) || ! is_array( $legacy['gf_rules'] ) ) {
			return false;
		}
		$index = (int) ( $record['legacy_rule_index'] ?? -1 );
		if ( 0 > $index || ! array_key_exists( $index, $legacy['gf_rules'] ) || ! is_array( $legacy['gf_rules'][ $index ] ) ) {
			return false;
		}
		$rule        = $legacy['gf_rules'][ $index ];
		$form_id     = self::positive_id( $rule['form_id'] ?? null );
		$fingerprint = LegacyRuleMapper::fingerprint( $rule );
		if ( null === $form_id ) {
			return false;
		}
		return self::direct_record_matches(
			$record,
			$form_id,
			$index,
			$fingerprint,
			(int) ( $record['feed_id'] ?? 0 )
		);
	}

	/**
	 * Positively prove one Flow scope using current read-only Gravity Flow APIs.
	 *
	 * @param array<string, mixed> $record              Cutover record.
	 * @param bool                 $requires_source_step Whether a legacy source Step must still exist.
	 * @return bool
	 */
	private static function flow_scope_identity_valid( array $record, bool $requires_source_step ): bool {
		if ( 1 !== (int) ( $record['version'] ?? 0 ) || -1 !== (int) ( $record['legacy_rule_index'] ?? -2 ) || '' !== (string) ( $record['legacy_fingerprint'] ?? '' ) ) {
			return false;
		}
		$form_id        = (int) ( $record['form_id'] ?? 0 );
		$feed_id        = (int) ( $record['feed_id'] ?? 0 );
		$target_step_id = (int) ( $record['target_flow_step_id'] ?? 0 );
		$legacy_step_id = (int) ( $record['legacy_step_id'] ?? -1 );
		if ( 1 > $form_id || 1 > $feed_id || 1 > $target_step_id || ! class_exists( '\\Gravity_Flow_API' ) ) {
			return false;
		}
		if ( $requires_source_step ? 1 > $legacy_step_id : 0 !== $legacy_step_id ) {
			return false;
		}

		try {
			$api   = new \Gravity_Flow_API( $form_id );
			$steps = $api->get_steps();
			if ( ! is_array( $steps ) ) {
				return false;
			}
			if ( $requires_source_step && ! self::flow_step_exists( $steps, $legacy_step_id ) ) {
				return false;
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
			return false;
		}

		$verification = ( new FlowStepVerifier( new WordPressConfigurationSource() ) )->verify( $form_id, $feed_id, $target_step_id );
		return true === $verification['ready'];
	}

	/**
	 * Determine whether one exact Step ID remains in a current workflow.
	 *
	 * @param array<int, mixed> $steps   Current Gravity Flow Steps.
	 * @param int               $step_id Required Step ID.
	 * @return bool
	 */
	private static function flow_step_exists( array $steps, int $step_id ): bool {
		try {
			foreach ( $steps as $step ) {
				if ( ! is_object( $step ) || ! method_exists( $step, 'get_id' ) ) {
					continue;
				}
				if ( self::positive_id( $step->get_id() ) === $step_id ) {
					return true;
				}
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
			return false;
		}
		return false;
	}

	/**
	 * Parse a positive identity without malformed coercion.
	 *
	 * @param mixed $value Raw identity value.
	 * @return int|null
	 */
	private static function positive_id( $value ): ?int {
		if ( is_int( $value ) ) {
			return 0 < $value ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return null;
		}
		$value = (int) $value;
		return 0 < $value ? $value : null;
	}

	/**
	 * Persist one record and verify exact read-back.
	 *
	 * @param string               $scope_id Scope identity.
	 * @param array<string, mixed> $record   Cutover record.
	 * @return bool
	 */
	private static function put( string $scope_id, array $record ): bool {
		if ( '' === $scope_id || ! function_exists( 'update_option' ) ) {
			return false;
		}
		$records              = self::records();
		$existing             = $records[ $scope_id ] ?? null;
		$records[ $scope_id ] = $record;
		if ( $record === $existing ) {
			return true;
		}
		update_option( self::OPTION, $records, false );
		$read_back = self::records();
		return isset( $read_back[ $scope_id ] ) && $record === $read_back[ $scope_id ];
	}
}
