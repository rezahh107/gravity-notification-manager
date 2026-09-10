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
 * Sends one plain SMS through the cutover-authorized production Flow path.
 */
final class LiveIPPanelFlowStepValidationTest extends WP_UnitTestCase {

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
	 * Pre-test greenfield settings option.
	 *
	 * @var mixed
	 */
	private $settings_before;

	/**
	 * Pre-test cutover registry option.
	 *
	 * @var mixed
	 */
	private $cutover_before;

	/** Prepare isolated live-runtime state. */
	public function set_up(): void {
		parent::set_up();
		$this->settings_before = get_option( Settings::OPTION, null );
		$this->cutover_before  = get_option( CutoverRegistry::OPTION, null );
		delete_option( Settings::OPTION );
		delete_option( CutoverRegistry::OPTION );
		$this->remove_runtime_callbacks();
	}

	/** Restore all local WordPress state after live validation. */
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
	 * Prove one cutover-authorized Flow Feed reaches documented delivery.
	 *
	 * @testdox LIVE-IPPANEL-PR-01 one cutover-authorized Flow Feed sends and reaches documented delivered state
	 */
	public function test_live_ippanel_pr_01_flow_feed_sends_and_reaches_delivered_state(): void {
		self::assertSame( 1, preg_match( '/^\+[1-9][0-9]{1,14}$/D', GRAVITY_NOTIFY_LIVE_SMS_FROM ) );
		self::assertSame( 1, preg_match( '/^\+[1-9][0-9]{1,14}$/D', GRAVITY_NOTIFY_LIVE_SMS_TO ) );

		$head_marker = substr( preg_replace( '/[^0-9a-f]/i', '', GRAVITY_NOTIFY_LIVE_HEAD ) ?? '', 0, 12 );
		$run_marker  = preg_replace( '/[^0-9]/', '', GRAVITY_NOTIFY_LIVE_RUN_ID ) ?? '';
		self::assertNotSame( '', $head_marker );
		self::assertNotSame( '', $run_marker );
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

		$target_feed_id = $this->add_target_feed( 'GNM RUN034 ' . $correlation );
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
		$this->register_legacy_and_guard_callbacks();
		self::assertTrue( $service->enable( $scope_id ) );
		self::assertFalse( CutoverRegistry::legacy_flow_step_allowed( $this->form_id, $source_step_id ) );
		self::assertTrue( CutoverRegistry::feed_authorized( $target_feed_id ) );
		$this->assert_source_event_is_guarded( $source_step_id );

		$this->remove_legacy_sender_callbacks();
		ProductionRuntime::register();

		$http_status     = 0;
		$transport_error = false;
		$http_observer   = static function ( $response, $context, $class, $args, $url ) use ( &$http_status, &$transport_error ): void {
			unset( $class, $args );
			if ( 'response' !== $context || 'https://edge.ippanel.com/v1/api/send' !== $url ) {
				return;
			}
			if ( is_wp_error( $response ) ) {
				$transport_error = true;
				return;
			}
			$http_status = (int) wp_remote_retrieve_response_code( $response );
		};
		add_action( 'http_api_debug', $http_observer, 10, 5 );
		try {
			$submission = GFAPI::submit_form(
				$this->form_id,
				array( 'input_1' => $correlation )
			);
		} finally {
			remove_action( 'http_api_debug', $http_observer, 10 );
		}
		self::assertNotWPError( $submission );
		self::assertTrue( (bool) rgar( $submission, 'is_valid' ) );
		$this->entry_id = (int) rgar( $submission, 'entry_id' );
		self::assertGreaterThan( 0, $this->entry_id );

		$result = NotificationFeedAddOn::get_instance()->last_execution_result();
		self::assertNotNull( $result );
		self::assertCount( 1, $result->attempts() );
		$attempt = $result->attempts()[0];
		if ( AttemptStatus::SUCCESS !== $attempt->status() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bounded provider-neutral diagnostics only.
			printf(
				'GNM_LIVE_ATTEMPT status=%s provider=%s capability=%s diagnostic=%s http_status=%d transport_error=%s refs=%d' . PHP_EOL,
				$this->safe_token( $attempt->status() ),
				$this->safe_token( (string) $attempt->provider_id() ),
				$this->safe_token( (string) $attempt->capability() ),
				$this->safe_token( $attempt->diagnostic() ),
				$http_status,
				$transport_error ? 'yes' : 'no',
				count( $attempt->provider_references() )
			);
		}
		self::assertSame( AttemptStatus::SUCCESS, $attempt->status() );
		self::assertSame( 'ippanel', $attempt->provider_id() );
		self::assertCount( 1, $attempt->provider_references() );
		$reference = $this->safe_reference( $attempt->provider_references()[0] );
		self::assertNotSame( '', $reference );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bounded correlation marker only.
		printf( 'GNM_LIVE_CORRELATION=%s' . PHP_EOL, $correlation );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI evidence.
		printf( 'GNM_LIVE_SMS_ATTEMPTS=1' . PHP_EOL );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized provider reference only.
		printf( 'GNM_LIVE_PROVIDER_REFERENCE=%s' . PHP_EOL, $reference );
		$this->require_documented_delivery( $reference );
	}

	/**
	 * Poll the documented IPPanel recipient report until terminal delivery.
	 *
	 * @param string $reference Safe provider outbox reference.
	 */
	private function require_documented_delivery( string $reference ): void {
		$last_state = 'not_final';
		for ( $poll = 1; $poll <= 30; ++$poll ) {
			$url      = add_query_arg(
				array(
					'page'     => 1,
					'per_page' => 10,
					'bulk_id'  => $reference,
				),
				'https://edge.ippanel.com/v1/api/report/recipients'
			);
			$response = wp_remote_get(
				$url,
				array(
					'headers' => array(
						'Authorization' => GRAVITY_NOTIFY_LIVE_IPPANEL_API_KEY,
						'Content-Type'  => 'application/json',
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
					self::fail( 'IPPanel delivery-report request was rejected.' );
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
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bounded integer evidence.
				printf( 'GNM_LIVE_DELIVERY_POLL=%d' . PHP_EOL, $poll );
				return;
			}
			if ( in_array( $message_status, array( '3', '4' ), true ) ) {
				self::fail( 'IPPanel reached a documented terminal non-delivery state.' );
			}
			$last_state = in_array( $message_status, array( '0', '1' ), true ) ? 'provider_pending' : 'unknown_provider_state';
			sleep( 10 );
		}
		self::fail( 'IPPanel delivery did not reach delivered state; state=' . $last_state );
	}

	/**
	 * Prove the legacy sender callbacks are absent inside the source scope.
	 *
	 * @param int $source_step_id Legacy source Step ID.
	 */
	private function assert_source_event_is_guarded( int $source_step_id ): void {
		$observed   = 0;
		$listener   = array( LegacyListener::class, 'on_step_complete' );
		$dispatcher = array( LegacyDispatcher::instance(), 'handle_step_complete' );
		$observer   = function ( $step_id ) use ( &$observed, $source_step_id, $listener, $dispatcher ): void {
			if ( $source_step_id !== (int) $step_id ) {
				return;
			}
			++$observed;
			self::assertFalse( has_action( 'gravityflow_step_complete', $listener ) );
			self::assertFalse( has_action( 'gravityflow_step_complete', $dispatcher ) );
		};
		add_action( 'gravityflow_step_complete', $observer, 5, 1 );
		try {
			$step = ( new \Gravity_Flow_API( $this->form_id ) )->get_step( $source_step_id );
			self::assertIsObject( $step );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Documented Gravity Flow hook.
			do_action( 'gravityflow_step_complete', $source_step_id, 0, $this->form_id, 'approved', $step );
			self::assertSame( 1, $observed );
		} finally {
			remove_action( 'gravityflow_step_complete', $observer, 5 );
		}
	}

	/** Register canonical legacy callbacks and the production cutover guard. */
	private function register_legacy_and_guard_callbacks(): void {
		add_action( 'gravityflow_step_complete', array( LegacyListener::class, 'on_step_complete' ), 10, 5 );
		add_action( 'gravityflow_step_complete', array( LegacyDispatcher::instance(), 'handle_step_complete' ), 10, 5 );
		LegacyRuntimeGuard::boot();
	}

	/** Persist the one live greenfield target Feed. */
	private function add_target_feed( string $message ): int {
		$feed_id = GFAPI::add_feed(
			$this->form_id,
			array(
				'feedName'               => 'Live IPPanel target',
				'message'                => $message,
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
				'recipient_source_value' => GRAVITY_NOTIFY_LIVE_SMS_TO,
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
			),
			NotificationFeedAddOn::get_instance()->get_slug()
		);
		self::assertNotWPError( $feed_id );
		self::assertIsInt( $feed_id );
		return $feed_id;
	}

	/** Persist the live target GNM Feed-Step first in workflow order. */
	private function add_target_step( int $feed_id ): int {
		$step_id = ( new \Gravity_Flow_API( $this->form_id ) )->add_step(
			array(
				'step_name'        => 'Live IPPanel target step',
				'step_type'        => 'gravity_notification_manager',
				'feed_' . $feed_id => '1',
			)
		);
		self::assertGreaterThan( 0, $step_id );
		return $step_id;
	}

	/** Persist a real non-GNM Step that supplies legacy source identity only. */
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

	/** Reduce a provider reference to a bounded log-safe identifier. */
	private function safe_reference( string $reference ): string {
		$reference = preg_replace( '/[^A-Za-z0-9_-]/', '', $reference ) ?? '';
		return substr( $reference, 0, 128 );
	}

	/** Reduce provider-neutral diagnostic text to a bounded safe token. */
	private function safe_token( string $value ): string {
		$value = preg_replace( '/[^A-Za-z0-9_.:-]/', '_', $value ) ?? '';
		return substr( $value, 0, 64 );
	}

	/** Remove canonical legacy step sender callbacks. */
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

	/** Restore one WordPress option exactly. */
	private function restore_option( string $name, $value ): void {
		if ( null === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}
}
