<?php
/**
 * Real WordPress / Gravity Forms WU-08 migration and cutover regression.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\RealRuntime;

use GFAPI;
use GravityNotify\Admin\Settings;
use GravityNotify\Admin\WordPressConfigurationSource;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\Migration\CutoverRegistry;
use GravityNotify\Migration\CutoverSequence;
use GravityNotify\Migration\CutoverService;
use GravityNotify\Migration\FlowStepVerifier;
use GravityNotify\Migration\LegacyRuleMapper;
use GravityNotify\Migration\LegacyRuntimeGuard;
use GravityNotify\Migration\MigrationService;
use GravityNotify\Migration\ProductionRuntime;
use ReflectionMethod;
use ReflectionProperty;
use WP_Error;
use WP_UnitTestCase;

/**
 * Proves WU-08 against the real WordPress and Gravity Forms persistence APIs.
 */
final class WU08MigrationRealRuntimeTest extends WP_UnitTestCase {

	/**
	 * Real synthetic Form ID.
	 *
	 * @var int
	 */
	private int $form_id = 0;

	/**
	 * Legacy option value before the current test.
	 *
	 * @var mixed
	 */
	private $legacy_before;

	/**
	 * Target option value before the current test.
	 *
	 * @var mixed
	 */
	private $target_before;

	/**
	 * Cutover option value before the current test.
	 *
	 * @var mixed
	 */
	private $cutover_before;

	/**
	 * Preserve options and create a real Form fixture.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->legacy_before  = get_option( 'gfsms_settings', null );
		$this->target_before  = get_option( Settings::OPTION, null );
		$this->cutover_before = get_option( CutoverRegistry::OPTION, null );
		delete_option( Settings::OPTION );
		delete_option( CutoverRegistry::OPTION );

		$this->form_id = GFAPI::add_form(
			array(
				'title'  => 'GNM WU-08 migration fixture',
				'fields' => array(),
			)
		);
		self::assertGreaterThan( 0, $this->form_id );
		update_option( 'gfsms_settings', $this->legacy_fixture(), false );
	}

	/**
	 * Remove fixtures and restore pre-test options.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		NotificationFeedAddOn::get_instance()->configure_processor( null );
		if ( 0 < $this->form_id ) {
			NotificationFeedAddOn::get_instance()->delete_feeds( $this->form_id );
			GFAPI::delete_form( $this->form_id );
		}
		$this->restore_option( 'gfsms_settings', $this->legacy_before );
		$this->restore_option( Settings::OPTION, $this->target_before );
		$this->restore_option( CutoverRegistry::OPTION, $this->cutover_before );
		parent::tear_down();
	}

	/**
	 * Verify semantic zero Feeds, inaugural creation, and pre-cutover idempotence.
	 *
	 * @testdox WU08-MIGRATION-REAL-01 preview is secret-safe and execution is idempotent
	 */
	public function test_wu08_migration_real_01_preview_is_secret_safe_and_execution_is_idempotent(): void {
		$service = new MigrationService();
		$preview = $service->preview();
		self::assertStringNotContainsString( 'synthetic-secret-do-not-report', (string) wp_json_encode( $preview ) );
		$this->assert_semantic_zero_feeds();
		self::assertSame( false, get_option( Settings::OPTION, false ) );
		self::assertSame( false, get_option( CutoverRegistry::OPTION, false ) );
		self::assertSame( LegacyRuleMapper::MAP_DETERMINISTIC, $preview['rules'][0]['classification'] );
		self::assertSame( LegacyRuleMapper::MANUAL_REQUIRED_AMBIGUOUS, $preview['rules'][1]['classification'] );

		$first = $service->execute();
		$feeds = $this->feeds_for_form();
		self::assertCount( 1, $feeds );
		self::assertFalse( (bool) $feeds[0]['is_active'] );
		self::assertSame( 'CREATED_INACTIVE', $first['rules'][0]['mutation_state'] );
		self::assertTrue( $first['rules'][0]['cutover_prepared'] );
		self::assertSame( LegacyRuleMapper::MANUAL_REQUIRED_AMBIGUOUS, $first['rules'][1]['classification'] );

		$target = Settings::read();
		self::assertSame( 'synthetic-secret-do-not-report', $target['ippanel_api_key'] );
		self::assertSame( '+15550000001', $target['sms_from_number'] );

		$second       = $service->execute();
		$feeds_second = $this->feeds_for_form();
		self::assertCount( 1, $feeds_second );
		self::assertSame( (int) $feeds[0]['id'], (int) $feeds_second[0]['id'] );
		self::assertSame( 'UNCHANGED', $second['rules'][0]['mutation_state'] );
		self::assertSame( CutoverSequence::PREPARED, CutoverRegistry::record( (string) $second['rules'][0]['scope_id'] )['state'] );
	}

	/**
	 * Preserve the explicit cutover and rollback ordering regression.
	 *
	 * @testdox WU08-CUTOVER-REAL-02 cutover disables legacy before greenfield and rollback reverses safely
	 */
	public function test_wu08_cutover_real_02_cutover_disables_legacy_before_greenfield_and_rollback_reverses_safely(): void {
		$result   = ( new MigrationService() )->execute();
		$scope_id = (string) $result['rules'][0]['scope_id'];
		$feed_id  = (int) $result['rules'][0]['feed_id'];
		self::assertSame( CutoverSequence::PREPARED, CutoverRegistry::record( $scope_id )['state'] );

		$service = new CutoverService();
		self::assertTrue( $service->enable( $scope_id ) );
		self::assertSame( CutoverSequence::GREENFIELD_ENABLED, CutoverRegistry::record( $scope_id )['state'] );
		self::assertTrue( $this->feed_active( $feed_id ) );
		$this->assert_legacy_rule_suppressed( true );

		self::assertTrue( $service->rollback( $scope_id ) );
		self::assertSame( CutoverSequence::PREPARED, CutoverRegistry::record( $scope_id )['state'] );
		self::assertFalse( $this->feed_active( $feed_id ) );
		$this->assert_legacy_rule_suppressed( false );
	}

	/**
	 * Preserve read-only Flow placement verification.
	 *
	 * @testdox WU08-FLOW-REAL-03 Flow verification is read-only when target placement is missing
	 */
	public function test_wu08_flow_real_03_flow_verification_is_read_only_when_target_placement_is_missing(): void {
		$api    = new \Gravity_Flow_API( $this->form_id );
		$before = $api->get_steps();
		$result = ( new FlowStepVerifier( new WordPressConfigurationSource() ) )->verify( $this->form_id, 999991, 999992 );
		$after  = $api->get_steps();
		self::assertFalse( $result['ready'] );
		self::assertSame( count( is_array( $before ) ? $before : array() ), count( is_array( $after ) ? $after : array() ) );
	}

	/**
	 * Prove unrelated WP_Error values remain fail-closed at the production read boundary.
	 *
	 * @testdox WU08-ERROR-REAL-04 unrelated Feed-read WP_Error remains fail-closed
	 */
	public function test_wu08_error_real_04_unrelated_feed_read_wp_error_remains_fail_closed(): void {
		$this->assert_semantic_zero_feeds();
		$registry_before = get_option( CutoverRegistry::OPTION, false );
		$service         = new MigrationService();
		$method          = new ReflectionMethod( MigrationService::class, 'normalize_feed_read_result' );
		$result          = $method->invoke( $service, new WP_Error( 'synthetic_storage_failure', 'Synthetic only.' ) );

		self::assertSame( array(), $result['feeds'] );
		self::assertSame( 'FAILED', $result['failure']['mutation_state'] );
		self::assertSame( 'feed_read_failed', $result['failure']['mutation_reason'] );
		$this->assert_semantic_zero_feeds();
		self::assertSame( $registry_before, get_option( CutoverRegistry::OPTION, false ) );
	}

	/**
	 * Prove valid completed cutover survives migration re-execution unchanged.
	 *
	 * @testdox WU08-REEXEC-REAL-05 Execute preserves valid GREENFIELD_ENABLED authority
	 */
	public function test_wu08_reexec_real_05_execute_preserves_valid_greenfield_enabled_authority(): void {
		$first    = ( new MigrationService() )->execute();
		$scope_id = (string) $first['rules'][0]['scope_id'];
		$feed_id  = (int) $first['rules'][0]['feed_id'];
		self::assertTrue( ( new CutoverService() )->enable( $scope_id ) );
		self::assertTrue( $this->feed_active( $feed_id ) );
		$this->assert_legacy_rule_suppressed( true );

		$second = ( new MigrationService() )->execute();
		$feeds  = $this->feeds_for_form();
		self::assertCount( 1, $feeds );
		self::assertSame( $feed_id, (int) $feeds[0]['id'] );
		self::assertTrue( (bool) $feeds[0]['is_active'] );
		self::assertSame( 'UNCHANGED_ACTIVE_CUTOVER', $second['rules'][0]['mutation_state'] );
		self::assertSame( CutoverSequence::GREENFIELD_ENABLED, CutoverRegistry::record( $scope_id )['state'] );
		$this->assert_legacy_rule_suppressed( true );
	}

	/**
	 * Prove PREPARED plus active Feed is rejected without silent mutation.
	 *
	 * @testdox WU08-AUTHORITY-REAL-06 PREPARED plus active Feed fails closed
	 */
	public function test_wu08_authority_real_06_prepared_plus_active_feed_fails_closed(): void {
		$first    = ( new MigrationService() )->execute();
		$scope_id = (string) $first['rules'][0]['scope_id'];
		$feed_id  = (int) $first['rules'][0]['feed_id'];
		$this->set_feed_active_fixture( $feed_id, true );

		$second = ( new MigrationService() )->execute();
		self::assertSame( 'FAILED', $second['rules'][0]['mutation_state'] );
		self::assertSame( 'contradictory_prepared_feed_active', $second['rules'][0]['mutation_reason'] );
		self::assertTrue( $this->feed_active( $feed_id ) );
		self::assertSame( CutoverSequence::PREPARED, CutoverRegistry::record( $scope_id )['state'] );
	}

	/**
	 * Prove GREENFIELD_ENABLED plus inactive Feed is rejected without rollback.
	 *
	 * @testdox WU08-AUTHORITY-REAL-07 GREENFIELD_ENABLED plus inactive Feed fails closed
	 */
	public function test_wu08_authority_real_07_greenfield_enabled_plus_inactive_feed_fails_closed(): void {
		$first    = ( new MigrationService() )->execute();
		$scope_id = (string) $first['rules'][0]['scope_id'];
		$feed_id  = (int) $first['rules'][0]['feed_id'];
		self::assertTrue( ( new CutoverService() )->enable( $scope_id ) );
		$this->set_feed_active_fixture( $feed_id, false );

		$second = ( new MigrationService() )->execute();
		self::assertSame( 'FAILED', $second['rules'][0]['mutation_state'] );
		self::assertSame( 'contradictory_greenfield_feed_inactive', $second['rules'][0]['mutation_reason'] );
		self::assertFalse( $this->feed_active( $feed_id ) );
		self::assertSame( CutoverSequence::GREENFIELD_ENABLED, CutoverRegistry::record( $scope_id )['state'] );
	}

	/**
	 * Prove transitional LEGACY_DISABLED authority is preserved without migration action.
	 *
	 * @testdox WU08-AUTHORITY-REAL-08 LEGACY_DISABLED is non-mutating during migration
	 */
	public function test_wu08_authority_real_08_legacy_disabled_is_non_mutating_during_migration(): void {
		$first    = ( new MigrationService() )->execute();
		$scope_id = (string) $first['rules'][0]['scope_id'];
		$feed_id  = (int) $first['rules'][0]['feed_id'];
		self::assertTrue( CutoverRegistry::set_state( $scope_id, CutoverSequence::LEGACY_DISABLED ) );

		$second = ( new MigrationService() )->execute();
		self::assertSame( 'FAILED', $second['rules'][0]['mutation_state'] );
		self::assertSame( 'cutover_transition_in_progress', $second['rules'][0]['mutation_reason'] );
		self::assertFalse( $this->feed_active( $feed_id ) );
		self::assertSame( CutoverSequence::LEGACY_DISABLED, CutoverRegistry::record( $scope_id )['state'] );
	}

	/**
	 * Prove an active migrated Feed without exact registry authority is never normalized.
	 *
	 * @testdox WU08-AUTHORITY-REAL-09 active Feed without registry authority fails closed
	 */
	public function test_wu08_authority_real_09_active_feed_without_registry_authority_fails_closed(): void {
		$first   = ( new MigrationService() )->execute();
		$feed_id = (int) $first['rules'][0]['feed_id'];
		$this->set_feed_active_fixture( $feed_id, true );
		delete_option( CutoverRegistry::OPTION );

		$second = ( new MigrationService() )->execute();
		self::assertSame( 'FAILED', $second['rules'][0]['mutation_state'] );
		self::assertSame( 'existing_active_feed_without_cutover_authority', $second['rules'][0]['mutation_reason'] );
		self::assertTrue( $this->feed_active( $feed_id ) );
		self::assertSame( false, get_option( CutoverRegistry::OPTION, false ) );
	}

	/**
	 * Prove registry/Feed identity disagreement fails closed without repair.
	 *
	 * @testdox WU08-AUTHORITY-REAL-10 registry Feed identity disagreement fails closed
	 */
	public function test_wu08_authority_real_10_registry_feed_identity_disagreement_fails_closed(): void {
		$first    = ( new MigrationService() )->execute();
		$scope_id = (string) $first['rules'][0]['scope_id'];
		$feed_id  = (int) $first['rules'][0]['feed_id'];
		$records  = get_option( CutoverRegistry::OPTION, array() );
		$records[ $scope_id ]['feed_id'] = $feed_id + 100000;
		update_option( CutoverRegistry::OPTION, $records, false );
		$before = get_option( CutoverRegistry::OPTION, array() );

		$second = ( new MigrationService() )->execute();
		self::assertSame( 'FAILED', $second['rules'][0]['mutation_state'] );
		self::assertSame( 'registered_feed_missing', $second['rules'][0]['mutation_reason'] );
		self::assertFalse( $this->feed_active( $feed_id ) );
		self::assertSame( $before, get_option( CutoverRegistry::OPTION, array() ) );
	}

	/**
	 * Prove migration marker drift fails closed without rewriting Feed or registry.
	 *
	 * @testdox WU08-AUTHORITY-REAL-11 migration marker mismatch fails closed
	 */
	public function test_wu08_authority_real_11_migration_marker_mismatch_fails_closed(): void {
		$first    = ( new MigrationService() )->execute();
		$scope_id = (string) $first['rules'][0]['scope_id'];
		$feed_id  = (int) $first['rules'][0]['feed_id'];
		$feed     = $this->only_feed();
		$meta     = (array) $feed['meta'];
		$meta['gnm_migration_source'] = 'synthetic-mismatch';
		self::assertTrue( GFAPI::update_feed_property( $feed_id, 'meta', $meta ) );
		self::assertSame( 'synthetic-mismatch', $this->only_feed()['meta']['gnm_migration_source'] );
		$record_before = CutoverRegistry::record( $scope_id );

		$second = ( new MigrationService() )->execute();
		self::assertSame( 'FAILED', $second['rules'][0]['mutation_state'] );
		self::assertSame( 'migration_marker_mismatch', $second['rules'][0]['mutation_reason'] );
		self::assertSame( 'synthetic-mismatch', $this->only_feed()['meta']['gnm_migration_source'] );
		self::assertSame( $record_before, CutoverRegistry::record( $scope_id ) );
	}

	/**
	 * Prove target metadata drift fails closed without rewriting Feed or registry.
	 *
	 * @testdox WU08-AUTHORITY-REAL-12 target metadata mismatch fails closed
	 */
	public function test_wu08_authority_real_12_target_metadata_mismatch_fails_closed(): void {
		$first    = ( new MigrationService() )->execute();
		$scope_id = (string) $first['rules'][0]['scope_id'];
		$feed_id  = (int) $first['rules'][0]['feed_id'];
		$feed     = $this->only_feed();
		$meta     = (array) $feed['meta'];
		$meta['message'] = 'Synthetic changed message';
		self::assertTrue( GFAPI::update_feed_property( $feed_id, 'meta', $meta ) );
		self::assertSame( 'Synthetic changed message', $this->only_feed()['meta']['message'] );
		$record_before = CutoverRegistry::record( $scope_id );

		$second = ( new MigrationService() )->execute();
		self::assertSame( 'FAILED', $second['rules'][0]['mutation_state'] );
		self::assertSame( 'target_metadata_mismatch', $second['rules'][0]['mutation_reason'] );
		self::assertSame( 'Synthetic changed message', $this->only_feed()['meta']['message'] );
		self::assertSame( $record_before, CutoverRegistry::record( $scope_id ) );
	}

	/**
	 * Prove completed direct cutover loses runtime authorization on source identity drift.
	 *
	 * @testdox WU08-AUTHORITY-REAL-13 direct identity drift disables runtime authorization
	 */
	public function test_wu08_authority_real_13_direct_identity_drift_disables_runtime_authorization(): void {
		$first    = ( new MigrationService() )->execute();
		$scope_id = (string) $first['rules'][0]['scope_id'];
		$feed_id  = (int) $first['rules'][0]['feed_id'];
		self::assertTrue( ( new CutoverService() )->enable( $scope_id ) );
		self::assertTrue( CutoverRegistry::feed_authorized( $feed_id ) );
		$record_before = CutoverRegistry::record( $scope_id );

		$this->remove_primary_legacy_rule_fixture();
		self::assertFalse( CutoverRegistry::feed_authorized( $feed_id ) );
		self::assertTrue( $this->feed_active( $feed_id ) );
		self::assertSame( $record_before, CutoverRegistry::record( $scope_id ) );

		NotificationFeedAddOn::get_instance()->configure_processor( null );
		ProductionRuntime::register();
		$property = new ReflectionProperty( NotificationFeedAddOn::class, 'processor' );
		self::assertNull( $property->getValue( NotificationFeedAddOn::get_instance() ) );
		self::assertTrue( $this->feed_active( $feed_id ) );
		self::assertSame( $record_before, CutoverRegistry::record( $scope_id ) );
	}

	/**
	 * Prove stale direct identity cannot begin controlled cutover.
	 *
	 * @testdox WU08-AUTHORITY-REAL-14 stale direct identity cannot start cutover
	 */
	public function test_wu08_authority_real_14_stale_direct_identity_cannot_start_cutover(): void {
		$first    = ( new MigrationService() )->execute();
		$scope_id = (string) $first['rules'][0]['scope_id'];
		$feed_id  = (int) $first['rules'][0]['feed_id'];
		$record_before = CutoverRegistry::record( $scope_id );
		$this->remove_primary_legacy_rule_fixture();

		self::assertFalse( ( new CutoverService() )->enable( $scope_id ) );
		self::assertFalse( $this->feed_active( $feed_id ) );
		self::assertSame( $record_before, CutoverRegistry::record( $scope_id ) );
		self::assertSame( CutoverSequence::PREPARED, CutoverRegistry::record( $scope_id )['state'] );
	}

	/**
	 * Prove Flow source and target identity are revalidated from real current topology.
	 *
	 * @testdox WU08-FLOW-AUTHORITY-REAL-15 Flow source and target drift disables authorization
	 */
	public function test_wu08_flow_authority_real_15_flow_source_and_target_drift_disables_authorization(): void {
		$source_feed_id = $this->add_flow_feed_fixture( 'Synthetic source feed' );
		$source_step_id = $this->add_flow_step_fixture( 'Synthetic source step', $source_feed_id );
		$feed_id        = $this->add_flow_feed_fixture( 'Synthetic target feed' );
		$target_step_id = $this->add_flow_step_fixture( 'Synthetic target step', $feed_id );
		self::assertGreaterThan( 0, $source_step_id );
		self::assertGreaterThan( 0, $target_step_id );
		$api         = new \Gravity_Flow_API( $this->form_id );
		$source_step = $api->get_step( $source_step_id );
		$target_step = $api->get_step( $target_step_id );
		self::assertIsObject( $source_step, 'Source Flow Step was not persisted/read back.' );
		self::assertIsObject( $target_step, 'Target Flow Step was not persisted/read back.' );
		self::assertTrue( method_exists( $target_step, 'is_active' ), 'Target Flow Step does not expose active state.' );
		self::assertTrue( (bool) $target_step->is_active(), 'Target Flow Step is inactive.' );
		self::assertTrue( method_exists( $target_step, 'get_setting' ), 'Target Flow Step does not expose persisted settings.' );
		self::assertTrue( (bool) $target_step->get_setting( 'feed_' . $feed_id ), 'Target Feed selection is missing from the persisted Flow Step.' );
		$this->set_feed_active_fixture( $feed_id, false );
		$verification = ( new FlowStepVerifier( new WordPressConfigurationSource() ) )->verify( $this->form_id, $feed_id, $target_step_id );
		self::assertTrue( $verification['ready'], $verification['reason'] );
		self::assertSame( '', $verification['reason'] );
		$service  = new CutoverService();
		$scope_id = $service->prepare_flow( 'flow_step', $this->form_id, $source_step_id, $feed_id, $target_step_id );
		self::assertIsString( $scope_id );
		self::assertNotSame( '', $scope_id );
		self::assertNotNull( CutoverRegistry::record( $scope_id ) );
		self::assertTrue( $service->enable( $scope_id ) );
		self::assertTrue( CutoverRegistry::feed_authorized( $feed_id ) );

		$missing_source_feed = $this->add_flow_feed_fixture( 'Missing source target' );
		$missing_source_step = $this->add_flow_step_fixture( 'Missing source target step', $missing_source_feed );
		$missing_scope       = CutoverRegistry::prepare_flow( 'flow_step', $this->form_id, $source_step_id + 100000, $missing_source_feed, $missing_source_step );
		self::assertIsString( $missing_scope );
		self::assertTrue( $this->feed_active( $missing_source_feed ) );
		self::assertTrue( CutoverRegistry::set_state( $missing_scope, CutoverSequence::GREENFIELD_ENABLED ) );
		$missing_record = CutoverRegistry::record( $missing_scope );
		self::assertFalse( CutoverRegistry::feed_authorized( $missing_source_feed ) );
		self::assertTrue( $this->feed_active( $missing_source_feed ) );
		self::assertSame( $missing_record, CutoverRegistry::record( $missing_scope ) );

		$mismatched_feed = $this->add_flow_feed_fixture( 'Mismatched workflow target' );
		$mismatch_scope  = CutoverRegistry::prepare_flow( 'flow_workflow', $this->form_id, 0, $mismatched_feed, $target_step_id + 100000 );
		self::assertIsString( $mismatch_scope );
		self::assertTrue( $this->feed_active( $mismatched_feed ) );
		self::assertTrue( CutoverRegistry::set_state( $mismatch_scope, CutoverSequence::GREENFIELD_ENABLED ) );
		$mismatch_record = CutoverRegistry::record( $mismatch_scope );
		self::assertFalse( CutoverRegistry::feed_authorized( $mismatched_feed ) );
		self::assertTrue( $this->feed_active( $mismatched_feed ) );
		self::assertSame( $mismatch_record, CutoverRegistry::record( $mismatch_scope ) );
	}

	/**
	 * Build the synthetic, non-production legacy settings fixture.
	 *
	 * @return array<string, mixed>
	 */
	private function legacy_fixture(): array {
		return array(
			'ippanel_api_key'       => 'synthetic-secret-do-not-report',
			'default_sender_number' => '+15550000001',
			'use_queue'             => true,
			'retry_enabled'         => true,
			'gf_rules'              => array(
				array(
					'form_id'          => (string) $this->form_id,
					'recipient_type'   => 'fixed',
					'fixed_recipient'  => '+15550000002',
					'message_template' => 'Hello {Name:1}',
					'sender_number'    => '',
					'pattern_code'     => '',
				),
				array(
					'form_id'          => (string) $this->form_id,
					'recipient_type'   => 'submitter',
					'message_template' => 'Manual only',
					'sender_number'    => '',
					'pattern_code'     => '',
				),
			),
		);
	}

	/**
	 * Remove the exact primary direct Rule from the current legacy identity fixture.
	 *
	 * @return void
	 */
	private function remove_primary_legacy_rule_fixture(): void {
		$legacy = get_option( 'gfsms_settings', array() );
		self::assertIsArray( $legacy );
		self::assertIsArray( $legacy['gf_rules'] ?? null );
		self::assertArrayHasKey( 0, $legacy['gf_rules'] );
		unset( $legacy['gf_rules'][0] );
		update_option( 'gfsms_settings', $legacy, false );
		$read_back = get_option( 'gfsms_settings', array() );
		self::assertIsArray( $read_back );
		self::assertArrayNotHasKey( 0, $read_back['gf_rules'] );
	}

	/**
	 * Add one synthetic no-send GNM Feed for a Flow identity fixture.
	 *
	 * @param string $name Fixture label.
	 * @return int
	 */
	private function add_flow_feed_fixture( string $name ): int {
		$feed_id = GFAPI::add_feed(
			$this->form_id,
			array(
				'feedName'               => $name,
				'message'                => 'Synthetic no-send Flow message',
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
				'recipient_source_value' => '+15550000003',
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
			),
			NotificationFeedAddOn::get_instance()->get_slug()
		);
		self::assertNotWPError( $feed_id );
		self::assertIsInt( $feed_id );
		self::assertGreaterThan( 0, $feed_id );
		return $feed_id;
	}

	/**
	 * Add one real Gravity Flow GNM Step for a read-only identity fixture.
	 *
	 * @param string $name    Fixture label.
	 * @param int    $feed_id Selected Feed ID.
	 * @return int
	 */
	private function add_flow_step_fixture( string $name, int $feed_id ): int {
		$api     = new \Gravity_Flow_API( $this->form_id );
		$step_id = $api->add_step(
			array(
				'step_name'        => $name,
				'step_type'        => 'gravity_notification_manager',
				'feed_' . $feed_id => '1',
			)
		);
		self::assertGreaterThan( 0, $step_id );
		self::assertNotNull( $api->get_step( $step_id ) );
		return $step_id;
	}

	/**
	 * Return all GNM Feeds for the current Form, normalizing only observed not_found.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function feeds_for_form(): array {
		$feeds = GFAPI::get_feeds( null, $this->form_id, NotificationFeedAddOn::get_instance()->get_slug(), null );
		if ( is_wp_error( $feeds ) ) {
			self::assertSame( 'not_found', $feeds->get_error_code() );
			return array();
		}
		self::assertIsArray( $feeds );
		return $feeds;
	}

	/**
	 * Assert semantic absence of target Feeds without assuming an array representation.
	 *
	 * @return void
	 */
	private function assert_semantic_zero_feeds(): void {
		self::assertCount( 0, $this->feeds_for_form() );
	}

	/**
	 * Return the single current target Feed.
	 *
	 * @return array<string, mixed>
	 */
	private function only_feed(): array {
		$feeds = $this->feeds_for_form();
		self::assertCount( 1, $feeds );
		return $feeds[0];
	}

	/**
	 * Read one Feed active state.
	 *
	 * @param int $feed_id Feed ID.
	 * @return bool
	 */
	private function feed_active( int $feed_id ): bool {
		$feeds = GFAPI::get_feeds( $feed_id, null, NotificationFeedAddOn::get_instance()->get_slug(), null );
		$feed  = is_array( $feeds ) ? reset( $feeds ) : false;
		return is_array( $feed ) && (bool) ( $feed['is_active'] ?? false );
	}

	/**
	 * Set Feed active state only to construct a contradictory test fixture.
	 *
	 * @param int  $feed_id Feed ID.
	 * @param bool $active  Fixture active state.
	 * @return void
	 */
	private function set_feed_active_fixture( int $feed_id, bool $active ): void {
		self::assertTrue( GFAPI::update_feed_property( $feed_id, 'is_active', $active ? 1 : 0 ) );
		self::assertSame( $active, $this->feed_active( $feed_id ) );
	}

	/**
	 * Assert the first deterministic legacy Rule is suppressed or restored.
	 *
	 * @param bool $suppressed Expected suppression state.
	 * @return void
	 */
	private function assert_legacy_rule_suppressed( bool $suppressed ): void {
		$legacy = get_option( 'gfsms_settings', array() );
		LegacyRuntimeGuard::begin_direct( array(), array( 'id' => $this->form_id ) );
		$filtered = LegacyRuntimeGuard::filter_direct_settings( $legacy );
		LegacyRuntimeGuard::end_direct( array(), array( 'id' => $this->form_id ) );
		if ( $suppressed ) {
			self::assertArrayNotHasKey( 0, $filtered['gf_rules'] );
			self::assertArrayHasKey( 1, $filtered['gf_rules'] );
			return;
		}
		self::assertArrayHasKey( 0, $filtered['gf_rules'] );
	}

	/**
	 * Restore one WordPress option to its pre-test state.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Previous option value.
	 * @return void
	 */
	private function restore_option( string $name, $value ): void {
		if ( null === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
