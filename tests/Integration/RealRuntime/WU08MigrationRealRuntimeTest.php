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
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\Migration\CutoverRegistry;
use GravityNotify\Migration\CutoverSequence;
use GravityNotify\Migration\CutoverService;
use GravityNotify\Migration\FlowStepVerifier;
use GravityNotify\Migration\LegacyRuleMapper;
use GravityNotify\Migration\LegacyRuntimeGuard;
use GravityNotify\Migration\MigrationService;
use WP_UnitTestCase;

/**
 * Proves WU-08 against the real WordPress and Gravity Forms persistence APIs.
 */
final class WU08MigrationRealRuntimeTest extends WP_UnitTestCase {

	/** @var int */
	private int $form_id = 0;

	/** @var mixed */
	private $legacy_before;

	/** @var mixed */
	private $target_before;

	/** @var mixed */
	private $cutover_before;

	/** Preserve options and create a real Form fixture. */
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

	/** Remove fixtures and restore pre-test options. */
	public function tear_down(): void {
		if ( 0 < $this->form_id ) {
			NotificationFeedAddOn::get_instance()->delete_feeds( $this->form_id );
			GFAPI::delete_form( $this->form_id );
		}
		$this->restore_option( 'gfsms_settings', $this->legacy_before );
		$this->restore_option( Settings::OPTION, $this->target_before );
		$this->restore_option( CutoverRegistry::OPTION, $this->cutover_before );
		parent::tear_down();
	}

	/** @testdox WU08-MIGRATION-REAL-01 preview is secret-safe and execution is idempotent */
	public function test_wu08_migration_real_01_preview_is_secret_safe_and_execution_is_idempotent(): void {
		$service = new MigrationService();
		$preview = $service->preview();
		self::assertStringNotContainsString( 'synthetic-secret-do-not-report', (string) wp_json_encode( $preview ) );
		self::assertSame( array(), GFAPI::get_feeds( null, $this->form_id, NotificationFeedAddOn::get_instance()->get_slug(), null ) );
		self::assertSame( false, get_option( Settings::OPTION, false ) );
		self::assertSame( false, get_option( CutoverRegistry::OPTION, false ) );
		self::assertSame( LegacyRuleMapper::MAP_DETERMINISTIC, $preview['rules'][0]['classification'] );
		self::assertSame( LegacyRuleMapper::MANUAL_REQUIRED_AMBIGUOUS, $preview['rules'][1]['classification'] );

		$first = $service->execute();
		$feeds = GFAPI::get_feeds( null, $this->form_id, NotificationFeedAddOn::get_instance()->get_slug(), null );
		self::assertCount( 1, $feeds );
		self::assertFalse( (bool) $feeds[0]['is_active'] );
		self::assertSame( 'CREATED_INACTIVE', $first['rules'][0]['mutation_state'] );
		self::assertTrue( $first['rules'][0]['cutover_prepared'] );
		self::assertSame( LegacyRuleMapper::MANUAL_REQUIRED_AMBIGUOUS, $first['rules'][1]['classification'] );

		$target = Settings::read();
		self::assertSame( 'synthetic-secret-do-not-report', $target['ippanel_api_key'] );
		self::assertSame( '+15550000001', $target['sms_from_number'] );

		$second       = $service->execute();
		$feeds_second = GFAPI::get_feeds( null, $this->form_id, NotificationFeedAddOn::get_instance()->get_slug(), null );
		self::assertCount( 1, $feeds_second );
		self::assertSame( (int) $feeds[0]['id'], (int) $feeds_second[0]['id'] );
		self::assertSame( 'UNCHANGED', $second['rules'][0]['mutation_state'] );
	}

	/** @testdox WU08-CUTOVER-REAL-02 cutover disables legacy before greenfield and rollback reverses safely */
	public function test_wu08_cutover_real_02_cutover_disables_legacy_before_greenfield_and_rollback_reverses_safely(): void {
		$result   = ( new MigrationService() )->execute();
		$scope_id = (string) $result['rules'][0]['scope_id'];
		$feed_id  = (int) $result['rules'][0]['feed_id'];
		self::assertSame( CutoverSequence::PREPARED, CutoverRegistry::record( $scope_id )['state'] );

		$service = new CutoverService();
		self::assertTrue( $service->enable( $scope_id ) );
		self::assertSame( CutoverSequence::GREENFIELD_ENABLED, CutoverRegistry::record( $scope_id )['state'] );
		self::assertTrue( $this->feed_active( $feed_id ) );

		$legacy = get_option( 'gfsms_settings', array() );
		LegacyRuntimeGuard::begin_direct( array(), array( 'id' => $this->form_id ) );
		$filtered = LegacyRuntimeGuard::filter_direct_settings( $legacy );
		LegacyRuntimeGuard::end_direct( array(), array( 'id' => $this->form_id ) );
		self::assertArrayNotHasKey( 0, $filtered['gf_rules'] );
		self::assertArrayHasKey( 1, $filtered['gf_rules'] );

		self::assertTrue( $service->rollback( $scope_id ) );
		self::assertSame( CutoverSequence::PREPARED, CutoverRegistry::record( $scope_id )['state'] );
		self::assertFalse( $this->feed_active( $feed_id ) );

		LegacyRuntimeGuard::begin_direct( array(), array( 'id' => $this->form_id ) );
		$restored = LegacyRuntimeGuard::filter_direct_settings( $legacy );
		LegacyRuntimeGuard::end_direct( array(), array( 'id' => $this->form_id ) );
		self::assertArrayHasKey( 0, $restored['gf_rules'] );
	}

	/** @testdox WU08-FLOW-REAL-03 Flow verification is read-only when target placement is missing */
	public function test_wu08_flow_real_03_flow_verification_is_read_only_when_target_placement_is_missing(): void {
		$api    = new \Gravity_Flow_API( $this->form_id );
		$before = $api->get_steps();
		$result = ( new FlowStepVerifier( new WordPressConfigurationSource() ) )->verify( $this->form_id, 999991, 999992 );
		$after  = $api->get_steps();
		self::assertFalse( $result['ready'] );
		self::assertSame( count( is_array( $before ) ? $before : array() ), count( is_array( $after ) ? $after : array() ) );
	}

	/** @return array<string, mixed> */
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

	private function feed_active( int $feed_id ): bool {
		$feeds = GFAPI::get_feeds( $feed_id, null, NotificationFeedAddOn::get_instance()->get_slug(), null );
		$feed  = is_array( $feeds ) ? reset( $feeds ) : false;
		return is_array( $feed ) && (bool) ( $feed['is_active'] ?? false );
	}

	/** @param mixed $value */
	private function restore_option( string $name, $value ): void {
		if ( null === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
