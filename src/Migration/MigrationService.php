<?php
/**
 * Explicit, idempotent WU-08 migration service.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Migration;

use GravityNotify\Admin\Settings;
use GravityNotify\GravityForms\NotificationFeedAddOn;

/**
 * Migrates only deterministic provider values and direct-GF Rules.
 */
final class MigrationService {

	/**
	 * Build a secret-safe dry-run report without mutation.
	 *
	 * @return array<string, mixed>
	 */
	public function preview(): array {
		$legacy_raw = $this->legacy_settings_raw();
		if ( ! is_array( $legacy_raw ) ) {
			return array(
				'inventory' => MigrationInventory::settings( $legacy_raw ),
				'settings'  => array(),
				'rules'     => array(),
			);
		}
		$legacy = $legacy_raw;
		$target = Settings::read();
		$rules  = is_array( $legacy['gf_rules'] ?? null ) ? $legacy['gf_rules'] : array();
		$mapped = array();
		foreach ( $rules as $index => $rule ) {
			$mapped[] = LegacyRuleMapper::map( $rule, (int) $index, $legacy, $target );
		}

		return array(
			'inventory' => MigrationInventory::settings( $legacy ),
			'settings'  => $this->settings_plan( $legacy, $target ),
			'rules'     => $mapped,
		);
	}

	/**
	 * Execute deterministic migration without changing existing cutover authority.
	 *
	 * @return array<string, mixed>
	 */
	public function execute(): array {
		$report     = $this->preview();
		$legacy_raw = $this->legacy_settings_raw();
		if ( ! is_array( $legacy_raw ) ) {
			$report['mutation_state']  = 'FAILED';
			$report['mutation_reason'] = 'legacy_option_is_not_an_array';
			return $report;
		}
		$legacy = $legacy_raw;
		$target = Settings::read();
		$this->migrate_settings( $legacy, $target );
		$report['rules'] = array();

		$rules = is_array( $legacy['gf_rules'] ?? null ) ? $legacy['gf_rules'] : array();
		foreach ( $rules as $index => $rule ) {
			$mapping = LegacyRuleMapper::map( $rule, (int) $index, $legacy, Settings::read() );
			if ( LegacyRuleMapper::MAP_DETERMINISTIC !== ( $mapping['classification'] ?? '' ) ) {
				$report['rules'][] = $mapping;
				continue;
			}

			$feed_result = $this->ensure_inactive_feed( $mapping );
			$mapping     = array_merge( $mapping, $feed_result );
			if ( 'FAILED' !== ( $mapping['mutation_state'] ?? '' ) && isset( $mapping['feed_id'] ) && 0 < (int) $mapping['feed_id'] ) {
				if ( 'UNCHANGED_ACTIVE_CUTOVER' === $mapping['mutation_state'] ) {
					$mapping['cutover_prepared'] = true;
				} else {
					$mapping['cutover_prepared'] = CutoverRegistry::prepare_direct(
						(int) $mapping['form_id'],
						(int) $mapping['legacy_rule_index'],
						(string) $mapping['legacy_fingerprint'],
						(int) $mapping['feed_id']
					);
					if ( ! $mapping['cutover_prepared'] ) {
						$mapping['mutation_state']  = 'FAILED';
						$mapping['mutation_reason'] = 'cutover_registry_conflict';
					}
				}
			}
			$report['rules'][] = $mapping;
		}

		return $report;
	}

	/**
	 * Build provider-setting migration plan without revealing values.
	 *
	 * @param array<string, mixed> $legacy Legacy settings.
	 * @param array<string, mixed> $target Target settings.
	 * @return array<string, mixed>
	 */
	private function settings_plan( array $legacy, array $target ): array {
		$api  = is_scalar( $legacy['ippanel_api_key'] ?? null ) ? trim( (string) $legacy['ippanel_api_key'] ) : '';
		$from = is_scalar( $legacy['default_sender_number'] ?? null ) ? trim( (string) $legacy['default_sender_number'] ) : '';
		return array(
			'ippanel_api_key' => array(
				'classification' => MigrationInventory::MIGRATE_VALUE,
				'legacy_present' => '' !== $api,
				'target_present' => '' !== ( $target['ippanel_api_key'] ?? '' ),
				'conflict'       => '' !== $api && '' !== ( $target['ippanel_api_key'] ?? '' ) && ( $target['ippanel_api_key'] ?? '' ) !== $api,
			),
			'sms_from_number' => array(
				'classification' => MigrationInventory::MIGRATE_VALUE,
				'legacy_present' => '' !== $from,
				'legacy_valid'   => 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $from ),
				'target_present' => '' !== ( $target['sms_from_number'] ?? '' ),
				'conflict'       => '' !== $from && '' !== ( $target['sms_from_number'] ?? '' ) && ( $target['sms_from_number'] ?? '' ) !== $from,
			),
		);
	}

	/**
	 * Migrate only non-conflicting surviving target setting values.
	 *
	 * @param array<string, mixed> $legacy Legacy settings.
	 * @param array<string, mixed> $target Target settings.
	 * @return void
	 */
	private function migrate_settings( array $legacy, array $target ): void {
		$input = array();
		$api   = is_scalar( $legacy['ippanel_api_key'] ?? null ) ? trim( (string) $legacy['ippanel_api_key'] ) : '';
		$from  = is_scalar( $legacy['default_sender_number'] ?? null ) ? trim( (string) $legacy['default_sender_number'] ) : '';

		if ( '' !== $api && ( '' === ( $target['ippanel_api_key'] ?? '' ) || ( $target['ippanel_api_key'] ?? '' ) === $api ) ) {
			$input['ippanel_api_key'] = $api;
		}
		if ( 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $from ) && ( '' === ( $target['sms_from_number'] ?? '' ) || ( $target['sms_from_number'] ?? '' ) === $from ) ) {
			$input['sms_from_number'] = $from;
		}
		if ( array() === $input || ! function_exists( 'update_option' ) ) {
			return;
		}
		$new_value = Settings::sanitize_input( $input, $target );
		update_option( Settings::OPTION, $new_value, false );
	}

	/**
	 * Reconcile or create one deterministic target Feed without implicit rollback.
	 *
	 * @param array<string, mixed> $mapping Deterministic mapping result.
	 * @return array<string, mixed>
	 */
	private function ensure_inactive_feed( array $mapping ): array {
		if ( ! class_exists( '\\GFAPI' ) || ! class_exists( NotificationFeedAddOn::class ) ) {
			return $this->failure( 'gravity_forms_feed_api_unavailable' );
		}
		$slug        = NotificationFeedAddOn::get_instance()->get_slug();
		$read_result = $this->normalize_feed_read_result( \GFAPI::get_feeds( null, (int) $mapping['form_id'], $slug, null ) );
		if ( null !== $read_result['failure'] ) {
			return $read_result['failure'];
		}

		$feeds    = $read_result['feeds'];
		$scope_id = (string) ( $mapping['scope_id'] ?? '' );
		$record   = CutoverRegistry::record( $scope_id );
		if ( null !== $record ) {
			return $this->reconcile_registered_feed( $feeds, $mapping, $record );
		}

		$matching = array();
		foreach ( $feeds as $feed ) {
			if ( is_array( $feed ) && $scope_id === (string) ( $feed['meta']['gnm_migration_source'] ?? '' ) ) {
				$matching[] = $feed;
			}
		}
		if ( 1 < count( $matching ) ) {
			return $this->failure( 'duplicate_migration_source_marker' );
		}
		if ( 1 === count( $matching ) ) {
			$feed    = $matching[0];
			$feed_id = (int) ( $feed['id'] ?? 0 );
			if ( 1 > $feed_id || ! $this->feed_meta_matches( (array) ( $feed['meta'] ?? array() ), (array) $mapping['feed_meta'] ) ) {
				return $this->failure( 'source_marker_conflict' );
			}
			if ( ! empty( $feed['is_active'] ) ) {
				return $this->failure( 'existing_active_feed_without_cutover_authority', $feed_id );
			}
			return array(
				'mutation_state' => 'UNCHANGED',
				'feed_id'        => $feed_id,
			);
		}

		$feed_id = \GFAPI::add_feed( (int) $mapping['form_id'], (array) $mapping['feed_meta'], $slug );
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $feed_id ) ) || ! is_int( $feed_id ) || 1 > $feed_id ) {
			return $this->failure( 'feed_create_failed' );
		}
		$inactive = \GFAPI::update_feed_property( $feed_id, 'is_active', 0 );
		if ( true !== $inactive ) {
			\GFAPI::delete_feed( $feed_id );
			return $this->failure( 'feed_fail_closed_deactivation_failed' );
		}
		return array(
			'mutation_state' => 'CREATED_INACTIVE',
			'feed_id'        => $feed_id,
		);
	}

	/**
	 * Normalize the documented GFAPI Feed read result at the existing boundary.
	 *
	 * Only error code `not_found` means semantic zero Feeds. Every other WP_Error
	 * remains a deterministic failure.
	 *
	 * @param mixed $result Raw GFAPI::get_feeds() result.
	 * @return array{feeds:array<int, mixed>,failure:array<string, mixed>|null}
	 */
	private function normalize_feed_read_result( $result ): array {
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $result ) ) {
			if ( method_exists( $result, 'get_error_code' ) && 'not_found' === $result->get_error_code() ) {
				return array(
					'feeds'   => array(),
					'failure' => null,
				);
			}
			return array(
				'feeds'   => array(),
				'failure' => $this->failure( 'feed_read_failed' ),
			);
		}
		return array(
			'feeds'   => is_array( $result ) ? $result : array(),
			'failure' => null,
		);
	}

	/**
	 * Reconcile an already registered direct scope without mutating authority.
	 *
	 * @param array<int, mixed>    $feeds   Current target Feed collection.
	 * @param array<string, mixed> $mapping Current deterministic mapping.
	 * @param array<string, mixed> $record  Existing CutoverRegistry record.
	 * @return array<string, mixed>
	 */
	private function reconcile_registered_feed( array $feeds, array $mapping, array $record ): array {
		$feed_id = (int) ( $record['feed_id'] ?? 0 );
		if ( ! CutoverRegistry::direct_record_matches(
			$record,
			(int) $mapping['form_id'],
			(int) $mapping['legacy_rule_index'],
			(string) $mapping['legacy_fingerprint'],
			$feed_id
		) ) {
			return $this->failure( 'cutover_registry_identity_conflict' );
		}

		$feed = null;
		foreach ( $feeds as $candidate ) {
			if ( is_array( $candidate ) && $feed_id === (int) ( $candidate['id'] ?? 0 ) ) {
				$feed = $candidate;
				break;
			}
		}
		if ( null === $feed ) {
			return $this->failure( 'registered_feed_missing', $feed_id );
		}
		if ( (string) ( $mapping['scope_id'] ?? '' ) !== (string) ( $feed['meta']['gnm_migration_source'] ?? '' ) ) {
			return $this->failure( 'migration_marker_mismatch', $feed_id );
		}
		if ( ! $this->feed_meta_matches( (array) ( $feed['meta'] ?? array() ), (array) $mapping['feed_meta'] ) ) {
			return $this->failure( 'target_metadata_mismatch', $feed_id );
		}

		$active = ! empty( $feed['is_active'] );
		$state  = (string) ( $record['state'] ?? '' );
		if ( CutoverSequence::PREPARED === $state ) {
			return $active
				? $this->failure( 'contradictory_prepared_feed_active', $feed_id )
				: array(
					'mutation_state' => 'UNCHANGED',
					'feed_id'        => $feed_id,
				);
		}
		if ( CutoverSequence::LEGACY_DISABLED === $state ) {
			return $this->failure( 'cutover_transition_in_progress', $feed_id );
		}
		if ( CutoverSequence::GREENFIELD_ENABLED === $state ) {
			if ( ! $active ) {
				return $this->failure( 'contradictory_greenfield_feed_inactive', $feed_id );
			}
			if ( ! CutoverRegistry::feed_authorized( $feed_id ) ) {
				return $this->failure( 'greenfield_authority_invalid', $feed_id );
			}
			return array(
				'mutation_state' => 'UNCHANGED_ACTIVE_CUTOVER',
				'feed_id'        => $feed_id,
			);
		}
		return $this->failure( 'cutover_registry_state_invalid', $feed_id );
	}

	/**
	 * Build a deterministic non-mutating migration failure result.
	 *
	 * @param string $reason  Safe diagnostic reason.
	 * @param int    $feed_id Related Feed ID when known.
	 * @return array<string, mixed>
	 */
	private function failure( string $reason, int $feed_id = 0 ): array {
		$result = array(
			'mutation_state'  => 'FAILED',
			'mutation_reason' => $reason,
		);
		if ( 0 < $feed_id ) {
			$result['feed_id'] = $feed_id;
		}
		return $result;
	}

	/**
	 * Compare only the target-authoritative Feed metadata written by WU-08.
	 *
	 * @param array<string, mixed> $existing Existing Feed metadata.
	 * @param array<string, mixed> $expected Expected migrated metadata.
	 * @return bool
	 */
	private function feed_meta_matches( array $existing, array $expected ): bool {
		foreach ( array( 'feedName', 'message', 'recipient_source_type', 'recipient_source_value', 'channel', 'fallback_policy', 'gnm_migration_source' ) as $key ) {
			if ( (string) ( $expected[ $key ] ?? '' ) !== (string) ( $existing[ $key ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Read the raw legacy option so malformed state cannot be silently normalized.
	 *
	 * @return mixed
	 */
	private function legacy_settings_raw() {
		return function_exists( 'get_option' ) ? get_option( 'gfsms_settings', array() ) : array();
	}
}
