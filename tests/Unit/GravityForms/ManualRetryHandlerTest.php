<?php
/**
 * Deterministic WU-05 manual Retry security tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\GravityForms;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\ManualRetryHandler;
use GravityNotify\GravityForms\NotificationExecutionResult;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Tests\Support\Delivery\FakeSmsProvider;
use GravityNotify\Tests\Support\DeliveryState\InMemoryDeliveryStateStore;
use GravityNotify\Tests\Support\GravityForms\FakeManualRetryRuntime;
use GravityNotify\Tests\Support\GravityForms\GFFeedAddOnStub;
use GravityNotify\Tests\Support\Recipient\FakeEntryFieldReader;
use GravityNotify\Tests\Support\Recipient\FakeFlowAssigneeReader;
use GravityNotify\Tests\Support\Recipient\FakeUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Proves manual Retry fails closed and performs no implicit delivery.
 */
final class ManualRetryHandlerTest extends TestCase {

	/**
	 * Install deterministic global Gravity Forms parent-class alias.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( 'GFFeedAddOn', false ) ) {
			class_alias( GFFeedAddOnStub::class, 'GFFeedAddOn' );
		}
	}

	/**
	 * Reset singleton composition.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$add_on = NotificationFeedAddOn::get_instance();
		$add_on->configure_processor( null );
		$add_on->configure_delivery_state_manager( null );
		parent::tearDown();
	}

	/**
	 * T-WU05-08/10: authorized valid handler Retry runs synchronously and resolves state.
	 *
	 * @return void
	 */
	public function test_authorized_valid_retry_runs_current_chain_and_resolves_state(): void {
		$context = $this->context();
		$result  = $context['handler']->dispatch( 'POST', $this->valid_request() );

		self::assertSame( ManualRetryHandler::RESULT_SUCCESS, $result );
		self::assertSame( 1, $context['provider']->send_count );
		$target = $context['manager']->target_state( 10, 7 );
		self::assertCount( 2, $target['executions'] );
		self::assertSame( DeliveryStateManager::EXECUTION_MANUAL_RETRY, $target['executions'][1]['type'] );
		self::assertFalse( $target['attention_required'] );
	}

	/** T-WU05-10: missing capability fails closed. */
	public function test_missing_capability_fails_closed_with_zero_send_and_mutation(): void {
		$context = $this->context();
		$context['runtime']->capability = false;
		$this->assert_denied_without_side_effect( $context, ManualRetryHandler::ERROR_CAPABILITY, $this->valid_request() );
	}

	/** T-WU05-10: invalid nonce fails closed. */
	public function test_invalid_nonce_fails_closed_with_zero_send_and_mutation(): void {
		$context = $this->context();
		$context['runtime']->nonce_valid = false;
		$this->assert_denied_without_side_effect( $context, ManualRetryHandler::ERROR_NONCE, $this->valid_request() );
	}

	/** T-WU05-10: malformed Entry ID fails closed. */
	public function test_malformed_entry_id_fails_closed_with_zero_send_and_mutation(): void {
		$context             = $this->context();
		$request             = $this->valid_request();
		$request['entry_id'] = '10x';
		$this->assert_denied_without_side_effect( $context, ManualRetryHandler::ERROR_ENTRY_ID, $request );
	}

	/** T-WU05-10: missing Entry fails closed. */
	public function test_missing_entry_fails_closed_with_zero_send_and_mutation(): void {
		$context = $this->context();
		unset( $context['runtime']->entries[10] );
		$this->assert_denied_without_side_effect( $context, ManualRetryHandler::ERROR_ENTRY, $this->valid_request() );
	}

	/** T-WU05-10: malformed Feed ID fails closed. */
	public function test_malformed_feed_id_fails_closed_with_zero_send_and_mutation(): void {
		$context            = $this->context();
		$request            = $this->valid_request();
		$request['feed_id'] = '7.0';
		$this->assert_denied_without_side_effect( $context, ManualRetryHandler::ERROR_FEED_ID, $request );
	}

	/** T-WU05-10: missing Feed fails closed. */
	public function test_missing_feed_fails_closed_with_zero_send_and_mutation(): void {
		$context = $this->context();
		unset( $context['runtime']->feeds[7] );
		$this->assert_denied_without_side_effect( $context, ManualRetryHandler::ERROR_FEED, $this->valid_request() );
	}

	/** T-WU05-10: malformed persisted state fails closed. */
	public function test_malformed_state_fails_closed_with_zero_send_and_mutation(): void {
		$context = $this->context();
		$context['store']->malformed[10] = true;
		$this->assert_denied_without_side_effect( $context, ManualRetryHandler::ERROR_STATE, $this->valid_request() );
	}

	/**
	 * T-WU05-11: GET/read-only request never executes delivery.
	 *
	 * @return void
	 */
	public function test_get_request_never_triggers_retry_delivery(): void {
		$context = $this->context();
		$this->assert_denied_without_side_effect( $context, ManualRetryHandler::ERROR_METHOD, $this->valid_request(), 'GET' );
	}

	/**
	 * Build a valid unresolved state plus a success-capable current processor.
	 *
	 * @return array{handler:ManualRetryHandler,runtime:FakeManualRetryRuntime,provider:FakeSmsProvider,store:InMemoryDeliveryStateStore,manager:DeliveryStateManager}
	 */
	private function context(): array {
		$store   = new InMemoryDeliveryStateStore();
		$manager = new DeliveryStateManager( $store, static fn(): string => '2026-09-09T00:00:00+00:00' );
		$manager->record_execution(
			10,
			5,
			7,
			'Case update',
			'sms',
			new NotificationExecutionResult(
				array( new AttemptResult( AttemptStatus::FAILED, 'sms', 'initial', 'plain', array(), 'test' ) ),
				array(),
				false
			)
		);

		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS, 'current' );
		$add_on   = NotificationFeedAddOn::get_instance();
		$add_on->configure_delivery_state_manager( $manager );
		$add_on->configure_processor( $this->processor( $provider ) );

		$runtime = new FakeManualRetryRuntime();
		$runtime->entries[10] = array( 'id' => 10, 'form_id' => 5 );
		$runtime->forms[5]    = array( 'id' => 5 );
		$runtime->feeds[7]    = $this->feed();

		return array(
			'handler'  => new ManualRetryHandler( $add_on, $runtime ),
			'runtime'  => $runtime,
			'provider' => $provider,
			'store'    => $store,
			'manager'  => $manager,
		);
	}

	/**
	 * Assert a denied request has no transport or state side effect.
	 *
	 * @param array  $context Test context.
	 * @param string $expected Expected error.
	 * @param array  $request Request.
	 * @param string $method HTTP method.
	 * @return void
	 */
	private function assert_denied_without_side_effect( array $context, string $expected, array $request, string $method = 'POST' ): void {
		$writes_before = $context['store']->write_count;
		$result        = $context['handler']->dispatch( $method, $request );

		self::assertSame( $expected, $result );
		self::assertSame( 0, $context['provider']->send_count );
		self::assertSame( $writes_before, $context['store']->write_count );
	}

	/**
	 * Current WU-03/WU-02 processor.
	 *
	 * @param FakeSmsProvider $provider Provider.
	 * @return NotificationFeedProcessor
	 */
	private function processor( FakeSmsProvider $provider ): NotificationFeedProcessor {
		$resolver = new RecipientResolver(
			new FakeEntryFieldReader( array() ),
			new FakeUserDirectory( array(), array(), array() ),
			new FakeFlowAssigneeReader(
				array(
					'available' => false,
					'reason'    => 'flow_context_unavailable',
					'assignees' => array(),
				)
			)
		);

		return new NotificationFeedProcessor(
			$resolver,
			new SynchronousDispatcher( new SmsProviderRegistry( array( $provider ) ), null ),
			'+982100000000'
		);
	}

	/** @return array<string, string> */
	private function valid_request(): array {
		return array(
			'entry_id' => '10',
			'feed_id'  => '7',
			'_wpnonce' => 'valid-test-nonce',
		);
	}

	/** @return array<string, mixed> */
	private function feed(): array {
		return array(
			'id'         => 7,
			'form_id'    => 5,
			'is_active'  => true,
			'addon_slug' => 'gravity-notification-manager',
			'meta'       => array(
				'feedName'               => 'Case update',
				'message'                => 'Case accepted',
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
				'recipient_source_value' => '+989121234567',
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
			),
		);
	}
}
