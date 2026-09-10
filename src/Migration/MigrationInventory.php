<?php
/**
 * Secret-safe WU-08 migration inventory.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

/**
 * Classifies legacy settings without exposing credential values.
 */
final class MigrationInventory {

	public const MIGRATE_VALUE = 'MIGRATE_VALUE';
	public const MAP_DETERMINISTIC = 'MAP_DETERMINISTIC';
	public const MANUAL_REQUIRED_AMBIGUOUS = 'MANUAL_REQUIRED_AMBIGUOUS';
	public const RETAIN_READ_ONLY_TEMPORARILY = 'RETAIN_READ_ONLY_TEMPORARILY';
	public const DO_NOT_MIGRATE_RETIRE_LATER = 'DO_NOT_MIGRATE_RETIRE_LATER';
	public const NOT_APPLICABLE = 'NOT_APPLICABLE';

	/**
	 * Inventory material legacy option keys.
	 *
	 * @param mixed $legacy_settings Raw legacy option value.
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings( $legacy_settings ): array {
		if ( ! is_array( $legacy_settings ) ) {
			return array(
				'_option' => array(
					'classification' => self::MANUAL_REQUIRED_AMBIGUOUS,
					'reason'         => 'legacy_option_is_not_an_array',
				),
			);
		}

		$result = array();
		foreach ( array( 'ippanel_api_key', 'default_sender_number' ) as $key ) {
			$value          = $legacy_settings[ $key ] ?? '';
			$result[ $key ] = array(
				'classification' => self::MIGRATE_VALUE,
				'present'        => is_scalar( $value ) && '' !== trim( (string) $value ),
			);
		}

		foreach ( array( 'secondary_api_key', 'secondary_sender_number', 'enable_fallback', 'use_queue', 'queue_delay', 'retry_enabled', 'max_retry', 'enable_rate_limit', 'log_retention_days', 'debug_mode', 'webhook_url', 'webhook_events', 'pattern_map', 'conditional_logic' ) as $key ) {
			$result[ $key ] = array(
				'classification' => self::DO_NOT_MIGRATE_RETIRE_LATER,
			);
		}

		$result['gf_rules'] = array(
			'classification' => self::MAP_DETERMINISTIC,
		);
		$result['recipient_rules'] = array(
			'classification' => self::MANUAL_REQUIRED_AMBIGUOUS,
			'reason'         => 'workflow_recipient_semantics_require_native_flow_setup',
		);
		$result['legacy_logs'] = array(
			'classification' => self::RETAIN_READ_ONLY_TEMPORARILY,
		);
		$result['plato_user_mobile'] = array(
			'classification' => self::DO_NOT_MIGRATE_RETIRE_LATER,
		);

		return $result;
	}
}
