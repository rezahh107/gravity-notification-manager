<?php
/**
 * Pure no-dual-sender cutover state machine.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

/**
 * Defines the only allowed WU-08 sender-authority transitions.
 */
final class CutoverSequence {

	public const PREPARED = 'PREPARED';
	public const LEGACY_DISABLED = 'LEGACY_DISABLED';
	public const GREENFIELD_ENABLED = 'GREENFIELD_ENABLED';

	/**
	 * Transition toward greenfield authority.
	 *
	 * @param string $state           Current state.
	 * @param bool   $target_ready    Target Feed/Flow readiness verified.
	 * @param bool   $legacy_inactive Legacy path read-back verified inactive.
	 * @return string|null Next state, or null when transition is forbidden.
	 */
	public static function enable_next( string $state, bool $target_ready, bool $legacy_inactive ): ?string {
		if ( self::PREPARED === $state ) {
			return $target_ready ? self::LEGACY_DISABLED : null;
		}
		if ( self::LEGACY_DISABLED === $state ) {
			return $target_ready && $legacy_inactive ? self::GREENFIELD_ENABLED : null;
		}
		return null;
	}

	/**
	 * Transition toward legacy rollback.
	 *
	 * @param string $state Current state.
	 * @return string|null Next state.
	 */
	public static function rollback_next( string $state ): ?string {
		if ( self::GREENFIELD_ENABLED === $state ) {
			return self::LEGACY_DISABLED;
		}
		if ( self::LEGACY_DISABLED === $state ) {
			return self::PREPARED;
		}
		return null;
	}
}
