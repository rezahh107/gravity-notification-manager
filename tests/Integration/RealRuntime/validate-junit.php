<?php
/**
 * Fail-closed required-test manifest validation for real-runtime JUnit output.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

const GRAVITY_NOTIFY_REQUIRED_REAL_TESTS = array(
	'ENV-REAL-01'                 => 'test_env_real_01_real_runtime_identity',
	'GF-REAL-01'                  => 'test_gf_real_01_registration',
	'GF-REAL-02'                  => 'test_gf_real_02_settings_contract',
	'GF-REAL-03'                  => 'test_gf_real_03_feed_round_trip',
	'GF-REAL-04'                  => 'test_gf_real_04_native_condition_lifecycle',
	'GF-REAL-05'                  => 'test_gf_real_05_native_merge_tag_rendering',
	'GF-REAL-06'                  => 'test_gf_real_06_result_semantics',
	'GFLOW-REAL-01'               => 'test_gflow_real_01_step_discovery',
	'GFLOW-REAL-02'               => 'test_gflow_real_02_submission_and_workflow_position',
	'GFLOW-REAL-03'               => 'test_gflow_real_03_native_condition_inside_flow',
	'GFLOW-REAL-04'               => 'test_gflow_real_04_failure_does_not_strand_workflow',
	'SAFE-REAL-01'                => 'test_safe_real_01_http_is_blocked',
	'SAFE-REAL-02'                => 'test_safe_real_02_fake_attempt_evidence',
	'SAFE-REAL-03'                => 'test_safe_real_03_wordpress_http_interception',
	'WU07-KSES-REAL-01'           => 'test_wu07_kses_real_01_entry_detail_retry_form_survives_local_kses',
	'WU07-KSES-REAL-02'           => 'test_wu07_kses_real_02_local_allowlist_rejects_unapproved_markup',
	'WU08-MIGRATION-REAL-01'      => 'test_wu08_migration_real_01_preview_is_secret_safe_and_execution_is_idempotent',
	'WU08-CUTOVER-REAL-02'        => 'test_wu08_cutover_real_02_cutover_disables_legacy_before_greenfield_and_rollback_reverses_safely',
	'WU08-FLOW-REAL-03'           => 'test_wu08_flow_real_03_flow_verification_is_read_only_when_target_placement_is_missing',
	'WU08-ERROR-REAL-04'          => 'test_wu08_error_real_04_unrelated_feed_read_wp_error_remains_fail_closed',
	'WU08-REEXEC-REAL-05'         => 'test_wu08_reexec_real_05_execute_preserves_valid_greenfield_enabled_authority',
	'WU08-AUTHORITY-REAL-06'      => 'test_wu08_authority_real_06_prepared_plus_active_feed_fails_closed',
	'WU08-AUTHORITY-REAL-07'      => 'test_wu08_authority_real_07_greenfield_enabled_plus_inactive_feed_fails_closed',
	'WU08-AUTHORITY-REAL-08'      => 'test_wu08_authority_real_08_legacy_disabled_is_non_mutating_during_migration',
	'WU08-AUTHORITY-REAL-09'      => 'test_wu08_authority_real_09_active_feed_without_registry_authority_fails_closed',
	'WU08-AUTHORITY-REAL-10'      => 'test_wu08_authority_real_10_registry_feed_identity_disagreement_fails_closed',
	'WU08-AUTHORITY-REAL-11'      => 'test_wu08_authority_real_11_migration_marker_mismatch_fails_closed',
	'WU08-AUTHORITY-REAL-12'      => 'test_wu08_authority_real_12_target_metadata_mismatch_fails_closed',
	'WU08-AUTHORITY-REAL-13'      => 'test_wu08_authority_real_13_direct_identity_drift_disables_runtime_authorization',
	'WU08-AUTHORITY-REAL-14'      => 'test_wu08_authority_real_14_stale_direct_identity_cannot_start_cutover',
	'WU08-FLOW-AUTHORITY-REAL-15' => 'test_wu08_flow_authority_real_15_flow_source_and_target_drift_disables_authorization',
	'WU09-RETIRE-REAL-01'         => 'test_wu09_retire_real_01_legacy_sender_classes_and_hooks_are_absent',
	'WU09-RETIRE-REAL-02'         => 'test_wu09_retire_real_02_greenfield_boot_is_the_only_notification_bootstrap',
	'IPPANEL-CONTRACT-REAL-19'    => 'test_ippanel_contract_real_19_real_gf_flow_production_path_reaches_delivered',
);

/**
 * Emit the manifest state and terminate with the matching fail-closed code.
 *
 * @param string $state  Machine-readable integration state.
 * @param string $detail Human-readable failure detail.
 */
function gravity_notify_manifest_state( string $state, string $detail ): never {
	fwrite( STDERR, $detail . PHP_EOL );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI state vocabulary, never HTML.
	printf( 'GNM_REAL_INTEGRATION_STATE=%s' . PHP_EOL, $state );
	exit( 'REAL_INTEGRATION_INCOMPLETE' === $state ? 5 : ( 'REAL_INTEGRATION_DEFECT_FOUND' === $state ? 4 : 3 ) );
}

/**
 * Validate that every required RealRuntime test exists and completed cleanly.
 *
 * @param string $result_path JUnit result path.
 */
function gravity_notify_validate_manifest( string $result_path ): void {
	if ( '' === $result_path || ! is_readable( $result_path ) ) {
		gravity_notify_manifest_state( 'HARNESS_FAILURE', 'JUnit result is missing or unreadable.' );
	}
	$document = new DOMDocument();
	if ( ! $document->load( $result_path, LIBXML_NONET ) ) {
		gravity_notify_manifest_state( 'HARNESS_FAILURE', 'JUnit result is malformed.' );
	}
	$test_cases = array();
	foreach ( $document->getElementsByTagName( 'testcase' ) as $test_case ) {
		$name = $test_case->attributes?->getNamedItem( 'name' )?->nodeValue;
		if ( is_string( $name ) ) {
			$test_cases[ $name ] = $test_case;
		}
	}
	foreach ( GRAVITY_NOTIFY_REQUIRED_REAL_TESTS as $test_id => $method ) {
		if ( ! isset( $test_cases[ $method ] ) ) {
			gravity_notify_manifest_state( 'REAL_INTEGRATION_INCOMPLETE', sprintf( 'Required test %s was not present in JUnit output.', $test_id ) );
		}
		$test_case = $test_cases[ $method ];
		if ( 0 < $test_case->getElementsByTagName( 'error' )->length ) {
			gravity_notify_manifest_state( 'HARNESS_FAILURE', sprintf( 'Required test %s ended with a harness/runtime error.', $test_id ) );
		}
		if ( 0 < $test_case->getElementsByTagName( 'failure' )->length ) {
			gravity_notify_manifest_state( 'REAL_INTEGRATION_DEFECT_FOUND', sprintf( 'Required test %s observed an integration assertion failure.', $test_id ) );
		}
		if ( 0 < $test_case->getElementsByTagName( 'skipped' )->length ) {
			gravity_notify_manifest_state( 'REAL_INTEGRATION_INCOMPLETE', sprintf( 'Required test %s was skipped or incomplete.', $test_id ) );
		}
	}
	printf( 'GNM_REAL_INTEGRATION_MANIFEST=PASS tests=%d' . PHP_EOL, count( GRAVITY_NOTIFY_REQUIRED_REAL_TESTS ) );
}

gravity_notify_validate_manifest( $argv[1] ?? '' );
