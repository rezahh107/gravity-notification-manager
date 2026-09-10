<?php
/**
 * Explicitly authorized live IPPanel end-to-end PR validation.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\LiveRuntime;

use GFAPI;
use GFSMS\Integration\Dispatcher as LegacyDispatcher;
use GFSMS\Integration\Listener as LegacyListener;
use GravityNotify\Admin\Settings;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\Migration\CutoverRegistry;
use GravityNotify\Migration\CutoverSequence;
use GravityNotify\Migration\CutoverService;
use GravityNotify\Migration\LegacyRuntimeGuard;
use GravityNotify\Migration\ProductionRuntime;
use WP_UnitTestCase;

/**
 * Sends exactly one plain SMS through the greenfield Flow Feed-Step production path.
 */
final class LiveIPPanelFlowStepTest extends WP_UnitTestCase {

	/** @var int Real fixture Form ID. */
	private int $form_id = 0;

	/** @var int Real fixture Entry ID. */
	private int $entry_id = 0;

	/** @var mixed Pre-test greenfield settings option. */
	private $settings_before;

	/** @var mixed Pre-test cutover registry option. */
	private $cutover_before;

	/** Prepare isolated live-runtime state. */
	public function set_up(): void {
		parent::set_up();
		$this->settings_before = get_option( Settings::OPTION, null );
		$this->cutover_before = get_option( CutoverRegistry::OPTION, null );
		delete_option( Settings::OPTION );
		delete_option( CutoverRegistry::OPTION );
		$this->remove_runtime_callbacks();
	}

	/** Restore all local WordPress state after the live validation. */
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
		$this->restore_option( Settings::OPTION, $this->settings_before );
		$this->restore_option( CutoverRegistry::OPTION, $this->cutover_before );
		parent::tear_down();
	}

	/**
	 * @testdox LIVE-IPPANEL-PR-01 one cutover-authorized Flow Feed sends and reaches documented delivered state
	 */
	public function test_live_ippanel_pr_01_flow_feed_sends_and_reaches_delivered_state(): void {
		self::assertSame( 1, preg_match( '/^\+[1-9][0-9]{1,14}$/D', GRAVITY_NOTIFY_LIVE_SMS_FROM ), 'Configured live sender must use E.164 syntax.' );
		self::assertSame( 1, preg_match( '/^\+[1-9][0-9]{1,14}$/D', GRAVITY_NOTIFY_LIVE_SMS_TO ), 'Configured live recipient must use E.164 syntax.' );

		$head_marker = substr( preg_replace( '/[^0-9a-f]/i', '', GRAVITY_NOTIFY_LIVE_HEAD ) ?? '', 0, 12 );
		$run_marker  = preg_replace( '/[^0-9]/', '', GRAVITY_NOTIFY_LIVE_RUN_ID ) ?? '';
		self::assertNotSame( '', $head_marker, 'Live Head correlation marker is unavailable.' );
		self::assertNotSame( '', $run_marker, 'Live run correlation marker is unavailable.' );
		$correlation = sprintf( 'run-%s-head-%s', $run_marker, $head_marker );

		self::assertTrue(
			update_option(
				Settings::OPTION,
				array(
					'ippanel_api_key' => GRAVITY_NOTIFY_LIVE_IPPANEL_API_KEY,
					'sms_from_number' => GRAVITY_NOTIFY_LIVE_SMS_FROM,
					'bale_bot_token'  => '',
				),
				false
			)
		);

		$this->form_id = GFAPI::add_form(
			array(
				'title'  => 'GNM live IPPanel PR validation',
				'fields' => array(
					array(
						'id'    => 1,
						'type'  => 'text',
						'label' => 'Marker',
					),
				),
			)
		);
		self::assertGreaterThan( 0, $this->form_id );

		$source_feed_id = $this->add_feed( 'Legacy source identity', 'inactive source' );
		$this->set_feed_active( $source_feed_id, false );
		$source_step_id = $this->add_step( 'Legacy source step', $source_feed_id );
		$target_feed_id = $this->add_feed( 'Live IPPanel target', 'GNM RUN033 ' . $correlation );
		$target_step_id = $this->add_step( 'Live IPPanel target step', $target_feed_id );

		$service  = new CutoverService();
		$scope_id = $service->prepare_flow( 'flow_step', $this->form_id, $source_step_id, $target_feed_id, $target_step_id );
		self::assertIsString( $scope_id, 'Real Flow cutover preparation failed.' );
		self::assertSame( CutoverSequence::PREPARED, CutoverRegistry::record( $scope_id )['state'] );
		self::assertTrue( CutoverRegistry::legacy_flow_step_allowed( $this->form_id, $source_step_id ) );
		self::assertFalse( CutoverRegistry::feed_authorized( $target_feed_id ) );

		$this->register_legacy_and_guard_callbacks();
		self::assertTrue( $service->enable( $scope_id ), 'Controlled Flow cutover did not complete.' );
		self::assertFalse( CutoverRegistry::legacy_flow_step_allowed( $this->form_id, $source_step_id ) );
		self::assertTrue( CutoverRegistry::feed_authorized( $target_feed_id ) );
		$this->assert_source_event_is_guarded( $source_step_id );

		// The bounded live proof has established the real legacy source callback is suppressed.
		// Remove restored legacy callbacks before the target Flow step so this validation can
		// never create an unrelated legacy provider request while proving the greenfield send.
		$this->remove_legacy_sender_callbacks();
		self::assertFalse( has_action( 'gravityflow_step_complete', array( LegacyListener::class, 'on_step_complete' ) ) );
		self::assertFalse( has_action( 'gravityflow_step_complete', array( LegacyDispatcher::instance(), 'handle_step_complete' ) ) );
		self::assertFalse( CutoverRegistry::legacy_flow_step_allowed( $this->form_id, $source_step_id ) );

		ProductionRuntime::register();
		$submission = GFAPI::submit_form(
			$this->form_id,
			array( 'input_1' => $correlation )
		);
		self::assertNotWPError( $submission );
		self::assertTrue( (bool) rgar( $submission, 'is_valid' ), 'Real Gravity Forms submission was invalid.' );
		$this->entry_id = (int) rgar( $submission, 'entry_id' );
		self::assertGreaterThan( 0, $this->entry_id );

		$result = NotificationFeedAddOn::get_instance()->last_execution_result();
		self::assertNotNull( $result, 'Greenfield Feed-Step produced no execution result.' );
		self::assertCount( 1, $result->attempts(), 'Live validation must perform exactly one provider attempt.' );
		$attempt = $result->attempts()[0];
		self::assertSame( AttemptStatus::SUCCESS, $attempt->status(), 'IPPanel did not establish documented provider acceptance.' );
		self::assertSame( 'ippanel', $attempt->provider_id() );
		self::assertCount( 1, $attempt->provider_references(), 'IPPanel acceptance must expose one safe outbox reference.' );
		$reference = $this->safe_reference( $attempt->provider_references()[0] );
		self::assertNotSame( '', $reference, 'Provider reference was not safe to report.' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bounded CLI evidence, never HTML.
		printf( 'GNM_LIVE_CORRELATION=%s' . PHP_EOL, $correlation );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI evidence.
		printf( 'GNM_LIVE_SMS_ATTEMPTS=1' . PHP_EOL );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized provider reference only.
		printf( 'GNM_LIVE_PROVIDER_REFERENCE=%s' . PHP_EOL, $reference );

		$this->require_documented_delivery( $reference );
	}

	/**
	 * Poll the current documented IPPanel recipient report until terminal delivery.
	 *
	 * @param string $reference Safe provider outbox reference.
	 */
	private function require_documented_delivery( string $reference ): void {
		$last_state = 'not_final';
		for ( $poll = 1; $poll <= 30; ++$poll ) {
			$url = add_query_arg(
				array(
					'page' => 1,
					'per_page' => 10,
					'bulk_id' => $reference,
				),
				'https://edge.ippanel.com/v1/api/report/recipients'
			);
			$response = wp_remote_get(
				$url,
				array(
					'headers' => array(
						'Authorization' => GRAVITY_NOTIFY_LIVE_IPPANEL_API_KEY,
						'Content-Type' => 'application/json',
					),
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) ) {
				$last_state = 'transport_error';
				sleep( 10 );
				continue;
			}
			$status_code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 > $status_code || 300 <= $status_code ) {
				$last_state = 'http_' . $status_code;
				if ( in_array( $status_code, array( 401, 403, 422 ), true ) ) {
					self::fail( 'IPPanel delivery-report request was rejected; live environment/configuration evidence is not PASS.' );
				}
				sleep( 10 );
				continue;
			}
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $decoded ) || true !== ( $decoded['meta']['status'] ?? null ) || ! is_array( $decoded['data'] ?? null ) ) {
				$last_state = 'malformed_or_unready_report';
				sleep( 10 );
				continue;
			}
			$record = reset( $decoded['data'] );
			if ( ! is_array( $record ) ) {
				$last_state = 'report_without_recipient_state';
				sleep( 10 );
				continue;
			}
			$message_status = (string) ( $record['message_status'] ?? '' );
			if ( '2' === $message_status ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI evidence.
				printf( 'GNM_LIVE_DELIVERY_STATUS=DELIVERED' . PHP_EOL );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bounded integer CLI evidence.
				printf( 'GNM_LIVE_DELIVERY_POLL=%d' . PHP_EOL, $poll );
				return;
			}
			if ( in_array( $message_status, array( '3', '4' ), true ) ) {
				self::fail( 'IPPanel reached a documented terminal non-delivery state.' );
			}
			$last_state = in_array( $message_status, array( '0', '1' ), true ) ? 'provider_pending' : 'unknown_provider_state';
			sleep( 10 );
		}
		self::fail( 'IPPanel delivery did not reach documented delivered state within the bounded verification window; state=' . $last_state );
	}

	/**
	 * Prove the real legacy source callback is absent inside the guarded scope.
	 *
	 * @param int $source_step_id Legacy source Step ID.
	 */
	private function assert_source_event_is_guarded( int $source_step_id ): void {
		$observed = 0;
		$listener = array( LegacyListener::class, 'on_step_complete' );
		$dispatcher = array( LegacyDispatcher::instance(), 'handle_step_complete' );
		$observer = function ( $step_id ) use ( &$observed, $source_step_id, $listener, $dispatcher ): void {
			if ( $source_step_id !== (int) $step_id ) {
				return;
			}
			++$observed;
			self::assertFalse( has_action( 'gravityflow_step_complete', $listener ), 'Legacy Listener remained effective inside guarded source scope.' );
			self::assertFalse( has_action( 'gravityflow_step_complete', $dispatcher ), 'Legacy Dispatcher remained effective inside guarded source scope.' );
		};
		add_action( 'gravityflow_step_complete', $observer, 5, 1 );
		try {
			$step = ( new \Gravity_Flow_API( $this->form_id ) )->get_step( $source_step_id );
			self::assertIsObject( $step );
			do_action( 'gravityflow_step_complete', $source_step_id, 0, $this->form_id, 'approved', $step );
			self::assertSame( 1, $observed );
		} finally {
			remove_action( 'gravityflow_step_complete', $observer, 5 );
		}
	}

	/** Register canonical legacy callbacks and the existing production cutover guard. */
	private function register_legacy_and_guard_callbacks(): void {
		add_action( 'gravityflow_step_complete', array( LegacyListener::class, 'on_step_complete' ), 10, 5 );
		add_action( 'gravityflow_step_complete', array( LegacyDispatcher::instance(), 'handle_step_complete' ), 10, 5 );
		LegacyRuntimeGuard::boot();
	}

	/**
	 * Persist one real GNM Feed.
	 *
	 * @param string $name    Feed name.
	 * @param string $message Message body.
	 * @return int Feed ID.
	 */
	private function add_feed( string $name, string $message ): int {
		$feed_id = GFAPI::add_feed(
			$this->form_id,
			array(
				'feedName' => $name,
				'message' => $message,
				'recipient_source_type' => FeedRuleSchema::RECIPIENT_FIXED,
				'recipient_source_value' => GRAVITY_NOTIFY_LIVE_SMS_TO,
				'channel' => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy' => FeedRuleSchema::FALLBACK_NONE,
			),
			NotificationFeedAddOn::get_instance()->get_slug()
		);
		self::assertNotWPError( $feed_id );
		self::assertIsInt( $feed_id );
		return $feed_id;
	}

	/**
	 * Persist one real Gravity Flow GNM Feed Step.
	 *
	 * @param string $name    Step name.
	 * @param int    $feed_id Selected Feed ID.
	 * @return int Step ID.
	 */
	private function add_step( string $name, int $feed_id ): int {
		$step_id = ( new \Gravity_Flow_API( $this->form_id ) )->add_step(
			array(
				'step_name' => $name,
				'step_type' => 'gravity_notification_manager',
				'feed_' . $feed_id => '1',
			)
		);
		self::assertGreaterThan( 0, $step_id );
		return $step_id;
	}

	/**
	 * Set one fixture Feed active flag.
	 *
	 * @param int  $feed_id Feed ID.
	 * @param bool $active  Desired active state.
	 */
	private function set_feed_active( int $feed_id, bool $active ): void {
		self::assertTrue( GFAPI::update_feed_property( $feed_id, 'is_active', $active ? 1 : 0 ) );
	}

	/**
	 * Reduce provider reference to a bounded log-safe identifier.
	 *
	 * @param string $reference Provider outbox reference.
	 * @return string
	 */
	private function safe_reference( string $reference ): string {
		$reference = preg_replace( '/[^A-Za-z0-9_-]/', '', $reference ) ?? '';
		return substr( $reference, 0, 128 );
	}

	/** Remove canonical and previously defective alternate legacy step callbacks. */
	private function remove_legacy_sender_callbacks(): void {
		remove_action( 'gravityflow_step_complete', array( LegacyListener::class, 'on_step_complete' ), 10 );
		remove_action( 'gravityflow_step_complete', array( '\\GFSMS\\Integration\\Listener', 'on_step_complete' ), 10 );
		remove_action( 'gravityflow_step_complete', array( LegacyDispatcher::instance(), 'handle_step_complete' ), 10 );
	}

	/** Remove every runtime callback installed by this live test. */
	private function remove_runtime_callbacks(): void {
		$this->remove_legacy_sender_callbacks();
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

	/**
	 * Restore one WordPress option exactly.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Previous value.
	 */
	private function restore_option( string $name, $value ): void {
		if ( null === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
