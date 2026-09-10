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

	/** Build a secret-safe dry-run report without mutation. @return array<string, mixed> */
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

	/** Execute deterministic migration and leave every created Feed inactive. @return array<string, mixed> */
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
			if ( isset( $mapping['feed_id'] ) && 0 < (int) $mapping['feed_id'] ) {
				$mapping['cutover_prepared'] = CutoverRegistry::prepare_direct(
					(int) $mapping['form_id'],
					(int) $mapping['legacy_rule_index'],
					(string) $mapping['legacy_fingerprint'],
					(int) $mapping['feed_id']
				);
			}
			$report['rules'][] = $mapping;
		}

		return $report;
	}

	/** @return array<string, mixed> */
	private function settings_plan( array $legacy, array $target ): array {
		$api  = is_scalar( $legacy['ippanel_api_key'] ?? null ) ? trim( (string) $legacy['ippanel_api_key'] ) : '';
		$from = is_scalar( $legacy['default_sender_number'] ?? null ) ? trim( (string) $legacy['default_sender_number'] ) : '';
		return array(
			'ippanel_api_key' => array(
				'classification' => MigrationInventory::MIGRATE_VALUE,
				'legacy_present' => '' !== $api,
				'target_present' => '' !== ( $target['ippanel_api_key'] ?? '' ),
				'conflict'       => '' !== $api && '' !== ( $target['ippanel_api_key'] ?? '' ) && $api !== ( $target['ippanel_api_key'] ?? '' ),
			),
			'sms_from_number' => array(
				'classification' => MigrationInventory::MIGRATE_VALUE,
				'legacy_present' => '' !== $from,
				'legacy_valid'   => 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $from ),
				'target_present' => '' !== ( $target['sms_from_number'] ?? '' ),
				'conflict'       => '' !== $from && '' !== ( $target['sms_from_number'] ?? '' ) && $from !== ( $target['sms_from_number'] ?? '' ),
			),
		);
	}

	private function migrate_settings( array $legacy, array $target ): void {
		$input = array();
		$api   = is_scalar( $legacy['ippanel_api_key'] ?? null ) ? trim( (string) $legacy['ippanel_api_key'] ) : '';
		$from  = is_scalar( $legacy['default_sender_number'] ?? null ) ? trim( (string) $legacy['default_sender_number'] ) : '';

		if ( '' !== $api && ( '' === ( $target['ippanel_api_key'] ?? '' ) || $api === ( $target['ippanel_api_key'] ?? '' ) ) ) {
			$input['ippanel_api_key'] = $api;
		}
		if ( 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $from ) && ( '' === ( $target['sms_from_number'] ?? '' ) || $from === ( $target['sms_from_number'] ?? '' ) ) ) {
			$input['sms_from_number'] = $from;
		}
		if ( array() === $input || ! function_exists( 'update_option' ) ) {
			return;
		}
		$new_value = Settings::sanitize_input( $input, $target );
		update_option( Settings::OPTION, $new_value, false );
	}

	/** @return array<string, mixed> */
	private function ensure_inactive_feed( array $mapping ): array {
		if ( ! class_exists( '\\GFAPI' ) || ! class_exists( NotificationFeedAddOn::class ) ) {
			return array( 'mutation_state' => 'FAILED', 'mutation_reason' => 'gravity_forms_feed_api_unavailable' );
		}
		$slug  = NotificationFeedAddOn::get_instance()->get_slug();
		$feeds = \GFAPI::get_feeds( null, (int) $mapping['form_id'], $slug, null );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $feeds ) ) {
			return array( 'mutation_state' => 'FAILED', 'mutation_reason' => 'feed_read_failed' );
		}
		foreach ( is_array( $feeds ) ? $feeds : array() as $feed ) {
			if ( ! is_array( $feed ) || ( $feed['meta']['gnm_migration_source'] ?? '' ) !== ( $mapping['scope_id'] ?? '' ) ) {
				continue;
			}

			$feed_id = (int) ( $feed['id'] ?? 0 );
			if ( 1 > $feed_id || ! $this->feed_meta_matches( (array) ( $feed['meta'] ?? array() ), (array) $mapping['feed_meta'] ) ) {
				return array( 'mutation_state' => 'FAILED', 'mutation_reason' => 'source_marker_conflict' );
			}

			if ( ! empty( $feed['is_active'] ) ) {
				$inactive = \GFAPI::update_feed_property( $feed_id, 'is_active', 0 );
				if ( true !== $inactive ) {
					return array( 'mutation_state' => 'FAILED', 'mutation_reason' => 'existing_feed_deactivation_failed' );
				}
				return array( 'mutation_state' => 'EXISTING_DEACTIVATED', 'feed_id' => $feed_id );
			}

			return array( 'mutation_state' => 'UNCHANGED', 'feed_id' => $feed_id );
		}

		$feed_id = \GFAPI::add_feed( (int) $mapping['form_id'], (array) $mapping['feed_meta'], $slug );
		if ( ( function_exists( 'is_wp_error' ) && is_wp_error( $feed_id ) ) || ! is_int( $feed_id ) || 1 > $feed_id ) {
			return array( 'mutation_state' => 'FAILED', 'mutation_reason' => 'feed_create_failed' );
		}
		$inactive = \GFAPI::update_feed_property( $feed_id, 'is_active', 0 );
		if ( true !== $inactive ) {
			\GFAPI::delete_feed( $feed_id );
			return array( 'mutation_state' => 'FAILED', 'mutation_reason' => 'feed_fail_closed_deactivation_failed' );
		}
		return array( 'mutation_state' => 'CREATED_INACTIVE', 'feed_id' => $feed_id );
	}

	/** @param array<string, mixed> $existing @param array<string, mixed> $expected */
	private function feed_meta_matches( array $existing, array $expected ): bool {
		foreach ( array( 'feedName', 'message', 'recipient_source_type', 'recipient_source_value', 'channel', 'fallback_policy', 'gnm_migration_source' ) as $key ) {
			if ( (string) ( $existing[ $key ] ?? '' ) !== (string) ( $expected[ $key ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Read raw legacy option so malformed state cannot be silently normalized. @return mixed */
	private function legacy_settings_raw() {
		return function_exists( 'get_option' ) ? get_option( 'gfsms_settings', array() ) : array();
	}
}
