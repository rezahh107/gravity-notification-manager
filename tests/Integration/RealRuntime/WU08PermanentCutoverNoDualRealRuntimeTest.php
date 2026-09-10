<?php
/**
 * Permanent WU-08 controlled-cutover/no-dual-sender regression.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\RealRuntime;

use GFAPI;
use GFSMS\Integration\Dispatcher as LegacyDispatcher;
use GFSMS\Integration\Listener as LegacyListener;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\Migration\CutoverRegistry;
use GravityNotify\Migration\CutoverSequence;
use GravityNotify\Migration\CutoverService;
use GravityNotify\Migration\LegacyRuntimeGuard;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Tests\Support\Delivery\FakeSmsProvider;
use GravityNotify\Tests\Support\Recipient\FakeEntryFieldReader;
use GravityNotify\Tests\Support\Recipient\FakeFlowAssigneeReader;
use GravityNotify\Tests\Support\Recipient\FakeUserDirectory;
use WP_Hook;
use WP_UnitTestCase;

/**
 * Proves one representative Flow notification never has two effective authorities.
 */
final class WU08PermanentCutoverNoDualRealRuntimeTest extends WP_UnitTestCase {

	/**
	 * Real fixture Form ID.
	 *
	 * @var int
	 */
	private int $form_id = 0;

	/**
	 * Real fixture Entry ID.
	 *
	 * @var int
	 */
	private int $entry_id = 0;

	/**
	 * Pre-test cutover option value.
	 *
	 * @var mixed
	 */
	private $cutover_before;

	/** Prepare isolated real-runtime fixtures. */
	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'GFSMS_SETTINGS_OPTION' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Legacy bootstrap prerequisite.
			define( 'GFSMS_SETTINGS_OPTION', 'gfsms_settings' );
		}
		$this->cutover_before = get_option( CutoverRegistry::OPTION, null );
		delete_option( CutoverRegistry::OPTION );
		$this->remove_runtime_callbacks();
		$this->form_id = GFAPI::add_form(
			array(
				'title'  => 'GNM WU-08 permanent cutover fixture',
				'fields' => array(
					array(
						'id'    => 1,
						'type'  => 'text',
						'label' => 'Name',
					),
				),
			)
		);
		self::assertGreaterThan( 0, $this->form_id );
	}

	/** Restore all runtime state touched by the regression. */
	public function tear_down(): void {
		NotificationFeedAddOn::get_instance()->configure_processor( null );
		$this->remove_runtime_callbacks();
		if ( 0 < $this->entry_id ) {
			GFAPI::delete_entry( $this->entry_id );
		}
		if ( 0 < $this->form_id ) {
			NotificationFeedAddOn::get_instance()->delete_feeds( $this->form_id );
			GFAPI::delete_form( $this->form_id );
		}
		if ( null === $this->cutover_before ) {
			delete_option( CutoverRegistry::OPTION );
		} else {
			update_option( CutoverRegistry::OPTION, $this->cutover_before, false );
		}
		parent::tear_down();
	}

	/**
	 * Prove controlled cutover and rollback preserve one effective sender authority.
	 *
	 * @testdox WU08-CUTOVER-NODUAL-REAL-18 Flow cutover and rollback preserve one effective sender authority
	 */
	public function test_wu08_cutover_nodual_real_18_flow_cutover_and_rollback_preserve_one_effective_sender_authority(): void {
		$target_feed_id = $this->add_target_feed();
		$target_step_id = $this->add_target_step( $target_feed_id );
		$source_step_id = $this->add_legacy_source_step();
		$source_step    = ( new \Gravity_Flow_API( $this->form_id ) )->get_step( $source_step_id );
		self::assertIsObject( $source_step );
		self::assertSame( 'approval', $source_step->get_type() );

		$service  = new CutoverService();
		$scope_id = $service->prepare_flow(
			'flow_step',
			$this->form_id,
			$source_step_id,
			$target_feed_id,
			$target_step_id
		);
		self::assertIsString( $scope_id );
		self::assertSame( CutoverSequence::PREPARED, CutoverRegistry::record( $scope_id )['state'] );
		self::assertTrue( CutoverRegistry::legacy_flow_step_allowed( $this->form_id, $source_step_id ) );
		self::assertFalse( CutoverRegistry::feed_authorized( $target_feed_id ) );
		self::assertFalse( $this->feed_active( $target_feed_id ) );

		$this->register_legacy_and_guard_callbacks();
		$this->assert_one_legacy_callback( array( LegacyListener::class, 'on_step_complete' ) );
		$this->assert_one_legacy_callback( array( LegacyDispatcher::instance(), 'handle_step_complete' ) );

		$enable_trace = $this->trace_cutover_option(
			$scope_id,
			$source_step_id,
			$target_feed_id,
			static fn() => $service->enable( $scope_id )
		);
		self::assertSame(
			array( CutoverSequence::LEGACY_DISABLED, CutoverSequence::GREENFIELD_ENABLED ),
			array_column( $enable_trace, 'state' )
		);
		self::assertSame( false, $enable_trace[0]['legacy_allowed'] );
		self::assertSame( false, $enable_trace[0]['greenfield_authorized'] );
		self::assertSame( false, $enable_trace[0]['feed_active'] );
		self::assertSame( false, $enable_trace[1]['legacy_allowed'] );
		self::assertSame( true, $enable_trace[1]['greenfield_authorized'] );
		self::assertSame( true, $enable_trace[1]['feed_active'] );

		$this->assert_guarded_source_event( $source_step_id );
		$this->assert_one_legacy_callback( array( LegacyListener::class, 'on_step_complete' ) );
		$this->assert_one_legacy_callback( array( LegacyDispatcher::instance(), 'handle_step_complete' ) );

		$provider   = $this->configure_fake_greenfield_provider();
		$submission = GFAPI::submit_form(
			$this->form_id,
			array( 'input_1' => 'Alice' )
		);
		self::assertNotWPError( $submission );
		self::assertTrue( (bool) rgar( $submission, 'is_valid' ) );
		$this->entry_id = (int) rgar( $submission, 'entry_id' );
		self::assertGreaterThan( 0, $this->entry_id );
		self::assertSame( 1, $provider->send_count );
		self::assertCount( 1, $provider->requests );
		self::assertSame( 'RUN-034 Alice', $provider->requests[0]->message() );
		self::assertFalse( CutoverRegistry::legacy_flow_step_allowed( $this->form_id, $source_step_id ) );
		self::assertTrue( CutoverRegistry::feed_authorized( $target_feed_id ) );

		$rollback_trace = $this->trace_cutover_option(
			$scope_id,
			$source_step_id,
			$target_feed_id,
			static fn() => $service->rollback( $scope_id )
		);
		self::assertSame(
			array( CutoverSequence::LEGACY_DISABLED, CutoverSequence::PREPARED ),
			array_column( $rollback_trace, 'state' )
		);
		self::assertFalse( $rollback_trace[0]['legacy_allowed'] );
		self::assertFalse( $rollback_trace[0]['greenfield_authorized'] );
		self::assertTrue( $rollback_trace[0]['feed_active'] );
		self::assertTrue( $rollback_trace[1]['legacy_allowed'] );
		self::assertFalse( $rollback_trace[1]['greenfield_authorized'] );
		self::assertFalse( $rollback_trace[1]['feed_active'] );

		self::assertTrue( $service->enable( $scope_id ) );
		$this->assert_guarded_source_event( $source_step_id );
		self::assertTrue( $service->rollback( $scope_id ) );
		$this->assert_one_legacy_callback( array( LegacyListener::class, 'on_step_complete' ) );
		$this->assert_one_legacy_callback( array( LegacyDispatcher::instance(), 'handle_step_complete' ) );
	}

	/**
	 * Capture authoritative registry/feed state at each cutover transition.
	 *
	 * @param string   $scope_id       Scope ID.
	 * @param int      $source_step_id Legacy source Step ID.
	 * @param int      $feed_id        Target Feed ID.
	 * @param callable $operation      Cutover operation.
	 * @return array<int, array<string, mixed>>
	 */
	private function trace_cutover_option( string $scope_id, int $source_step_id, int $feed_id, callable $operation ): array {
		$trace    = array();
		$observer = function ( string $option ) use ( &$trace, $scope_id, $source_step_id, $feed_id ): void {
			if ( CutoverRegistry::OPTION !== $option ) {
				return;
			}
			$record = CutoverRegistry::record( $scope_id );
			if ( null === $record ) {
				return;
			}
			$trace[] = array(
				'state'                 => (string) $record['state'],
				'legacy_allowed'        => CutoverRegistry::legacy_flow_step_allowed( $this->form_id, $source_step_id ),
				'greenfield_authorized' => CutoverRegistry::feed_authorized( $feed_id ),
				'feed_active'           => $this->feed_active( $feed_id ),
			);
		};
		add_action( 'updated_option', $observer, 10, 1 );
		try {
			self::assertTrue( (bool) $operation() );
		} finally {
			remove_action( 'updated_option', $observer, 10 );
		}
		return $trace;
	}

	/** Register canonical legacy sender callbacks plus the WU-08 guard. */
	private function register_legacy_and_guard_callbacks(): void {
		add_action( 'gravityflow_step_complete', array( LegacyListener::class, 'on_step_complete' ), 10, 5 );
		add_action( 'gravityflow_step_complete', array( LegacyDispatcher::instance(), 'handle_step_complete' ), 10, 5 );
		LegacyRuntimeGuard::boot();
	}

	/**
	 * Prove source-step callbacks are suppressed only inside the disabled scope.
	 *
	 * @param int $source_step_id Legacy source Step ID.
	 */
	private function assert_guarded_source_event( int $source_step_id ): void {
		$observations = 0;
		$listener     = array( LegacyListener::class, 'on_step_complete' );
		$dispatcher   = array( LegacyDispatcher::instance(), 'handle_step_complete' );
		$observer     = function ( $step_id ) use ( &$observations, $source_step_id, $listener, $dispatcher ): void {
			if ( $source_step_id !== (int) $step_id ) {
				return;
			}
			++$observations;
			self::assertFalse( has_action( 'gravityflow_step_complete', $listener ) );
			self::assertFalse( has_action( 'gravityflow_step_complete', $dispatcher ) );
		};
		add_action( 'gravityflow_step_complete', $observer, 5, 1 );
		try {
			$step = ( new \Gravity_Flow_API( $this->form_id ) )->get_step( $source_step_id );
			self::assertIsObject( $step );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Documented Gravity Flow hook.
			do_action( 'gravityflow_step_complete', $source_step_id, 0, $this->form_id, 'approved', $step );
			self::assertSame( 1, $observations );
		} finally {
			remove_action( 'gravityflow_step_complete', $observer, 5 );
		}
	}

	/** Configure the existing greenfield processor with a no-network provider. */
	private function configure_fake_greenfield_provider(): FakeSmsProvider {
		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS );
		$resolver = new RecipientResolver(
			new FakeEntryFieldReader( array() ),
			new FakeUserDirectory( array(), array(), array() ),
			new FakeFlowAssigneeReader(
				array(
					'available' => false,
					'reason'    => 'not_used',
					'assignees' => array(),
				)
			)
		);
		NotificationFeedAddOn::get_instance()->configure_processor(
			new NotificationFeedProcessor(
				$resolver,
				new SynchronousDispatcher( new SmsProviderRegistry( array( $provider ) ) ),
				'+15550000001'
			)
		);
		return $provider;
	}

	/** Persist the one greenfield target Feed. */
	private function add_target_feed(): int {
		$feed_id = GFAPI::add_feed(
			$this->form_id,
			array(
				'feedName'               => 'Greenfield target',
				'message'                => 'RUN-034 {Name:1}',
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
				'recipient_source_value' => '+15550000003',
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
			),
			NotificationFeedAddOn::get_instance()->get_slug()
		);
		self::assertNotWPError( $feed_id );
		self::assertIsInt( $feed_id );
		return $feed_id;
	}

	/**
	 * Persist the greenfield target GNM Feed-Step first in workflow order.
	 *
	 * @param int $feed_id Selected target Feed ID.
	 */
	private function add_target_step( int $feed_id ): int {
		$step_id = ( new \Gravity_Flow_API( $this->form_id ) )->add_step(
			array(
				'step_name'        => 'Greenfield target step',
				'step_type'        => 'gravity_notification_manager',
				'feed_' . $feed_id => '1',
			)
		);
		self::assertGreaterThan( 0, $step_id );
		return $step_id;
	}

	/** Persist a real non-GNM source Step representing the legacy hook scope. */
	private function add_legacy_source_step(): int {
		$step_id = ( new \Gravity_Flow_API( $this->form_id ) )->add_step(
			array(
				'step_name' => 'Legacy source step',
				'step_type' => 'approval',
			)
		);
		self::assertGreaterThan( 0, $step_id );
		return $step_id;
	}

	/**
	 * Read the target Feed active flag.
	 *
	 * @param int $feed_id Target Feed ID.
	 */
	private function feed_active( int $feed_id ): bool {
		$feeds = GFAPI::get_feeds( $feed_id, null, NotificationFeedAddOn::get_instance()->get_slug(), null );
		$feed  = is_array( $feeds ) ? reset( $feeds ) : false;
		return is_array( $feed ) && (bool) ( $feed['is_active'] ?? false );
	}

	/**
	 * Assert one exact canonical legacy callback registration.
	 *
	 * @param array<int, mixed> $callback Callback identity.
	 */
	private function assert_one_legacy_callback( array $callback ): void {
		global $wp_filter;
		self::assertArrayHasKey( 'gravityflow_step_complete', $wp_filter );
		self::assertInstanceOf( WP_Hook::class, $wp_filter['gravityflow_step_complete'] );
		$matches = array_filter(
			$wp_filter['gravityflow_step_complete']->callbacks[10] ?? array(),
			static fn( array $registered ): bool => ( $registered['function'] ?? null ) === $callback
		);
		self::assertCount( 1, $matches );
	}

	/** Remove every callback this regression can register. */
	private function remove_runtime_callbacks(): void {
		$dispatcher = LegacyDispatcher::instance();
		remove_action( 'gravityflow_step_complete', array( LegacyListener::class, 'on_step_complete' ), 10 );
		remove_action( 'gravityflow_step_complete', array( '\\GFSMS\\Integration\\Listener', 'on_step_complete' ), 10 );
		remove_action( 'gravityflow_step_complete', array( $dispatcher, 'handle_step_complete' ), 10 );
		remove_action( 'gravityflow_step_complete', array( LegacyRuntimeGuard::class, 'begin_flow_step' ), 1 );
		remove_action( 'gravityflow_step_complete', array( LegacyRuntimeGuard::class, 'restore_flow_step' ), 11 );
		remove_action( 'gravityflow_workflow_complete', array( LegacyRuntimeGuard::class, 'begin_workflow' ), 1 );
		remove_action( 'gravityflow_workflow_complete', array( LegacyRuntimeGuard::class, 'restore_workflow' ), 11 );
		remove_action( 'gform_after_submission', array( LegacyRuntimeGuard::class, 'begin_direct' ), 1 );
		remove_action( 'gform_after_submission', array( LegacyRuntimeGuard::class, 'end_direct' ), 11 );
		remove_filter( 'option_gfsms_settings', array( LegacyRuntimeGuard::class, 'filter_direct_settings' ), PHP_INT_MAX );
		remove_action( 'gfsms_process_payload', array( LegacyRuntimeGuard::class, 'begin_process_payload' ), 1 );
		remove_action( 'gfsms_process_payload', array( LegacyRuntimeGuard::class, 'restore_process_payload' ), 11 );
		remove_action( 'gfsms_retry_payload', array( LegacyRuntimeGuard::class, 'begin_retry_payload' ), 1 );
		remove_action( 'gfsms_retry_payload', array( LegacyRuntimeGuard::class, 'restore_retry_payload' ), 11 );
	}
}
