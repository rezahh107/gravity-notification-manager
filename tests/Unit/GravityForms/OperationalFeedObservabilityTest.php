<?php
/**
 * Feed/Retry observational integration over the existing synchronous state path.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\GravityForms;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Bale\BaleChannelInterface;
use GravityNotify\Delivery\Sms\SmsProviderInterface;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\Observability\OperationalContext;
use GravityNotify\Observability\OperationalLogger;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Tests\Support\Delivery\FakeBaleChannel;
use GravityNotify\Tests\Support\Delivery\FakeSmsProvider;
use GravityNotify\Tests\Support\DeliveryState\InMemoryDeliveryStateStore;
use GravityNotify\Tests\Support\GravityForms\GFFeedAddOnStub;
use GravityNotify\Tests\Support\Observability\InMemoryOperationalEventStore;
use GravityNotify\Tests\Support\Recipient\FakeEntryFieldReader;
use GravityNotify\Tests\Support\Recipient\FakeFlowAssigneeReader;
use GravityNotify\Tests\Support\Recipient\FakeUserDirectory;
use PHPUnit\Framework\TestCase;

/** OperationalFeedObservabilityTest implementation. */
final class OperationalFeedObservabilityTest extends TestCase {

	/**
	 * SetUpBeforeClass.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( 'GFFeedAddOn', false ) ) {
			class_alias( GFFeedAddOnStub::class, 'GFFeedAddOn' );
		}
	}

	/**
	 * TearDown.
	 */
	protected function tearDown(): void {
		$add_on = NotificationFeedAddOn::get_instance();
		$add_on->configure_processor( null );
		$add_on->configure_delivery_state_manager( null );
		parent::tearDown();
	}

	/**
	 * Test normal sms fallback has one trace without changing entry meta truth.
	 */
	public function test_normal_sms_fallback_has_one_trace_without_changing_entry_meta_truth(): void {
		$delivery_store = new InMemoryDeliveryStateStore();
		$event_store    = new InMemoryOperationalEventStore();
		$first          = new FakeSmsProvider( AttemptStatus::AMBIGUOUS, 'primary' );
		$second         = new FakeSmsProvider( AttemptStatus::SUCCESS, 'fallback' );
		$add_on         = $this->configured_add_on( $delivery_store, array( $first, $second ), null, $event_store );

		self::assertTrue( $add_on->process_feed( $this->sms_feed( FeedRuleSchema::FALLBACK_COMPATIBLE_SMS ), $this->entry(), $this->form() ) );

		$target = $this->manager( $delivery_store )->target_state( 10, 7 );
		self::assertSame( array( AttemptStatus::AMBIGUOUS, AttemptStatus::SUCCESS ), array_column( $target['executions'][0]['attempts'], 'status' ) );
		self::assertCount( 2, $event_store->events );
		$first_event  = $event_store->events[0]->to_array();
		$second_event = $event_store->events[1]->to_array();
		self::assertSame( $first_event['trace_id'], $second_event['trace_id'] );
		self::assertSame( array( 1, 2 ), array( $first_event['attempt_index'], $second_event['attempt_index'] ) );
		self::assertSame( OperationalContext::EXECUTION_NORMAL, $first_event['execution_type'] );
		self::assertSame( 5, $first_event['form_id'] );
		self::assertSame( 7, $first_event['feed_id'] );
		self::assertSame( 10, $first_event['entry_id'] );
		self::assertNotSame( '+989121234567', $first_event['destination'] );
	}

	/**
	 * Test manual retry is distinct in log and preserves retry state authority.
	 */
	public function test_manual_retry_is_distinct_in_log_and_preserves_retry_state_authority(): void {
		$delivery_store = new InMemoryDeliveryStateStore();
		$event_store    = new InMemoryOperationalEventStore();
		$failed         = new FakeSmsProvider( AttemptStatus::FAILED, 'primary' );
		$add_on         = $this->configured_add_on( $delivery_store, array( $failed ), null, $event_store );
		$feed           = $this->sms_feed();

		self::assertFalse( $add_on->process_feed( $feed, $this->entry(), $this->form() ) );
		$success = new FakeSmsProvider( AttemptStatus::SUCCESS, 'primary' );
		$add_on->configure_processor( $this->processor( array( $success ), null, $event_store ) );
		self::assertNotNull( $add_on->retry_feed( $feed, $this->entry(), $this->form() ) );

		self::assertCount( 2, $event_store->events );
		self::assertSame( OperationalContext::EXECUTION_NORMAL, $event_store->events[0]->get( 'execution_type' ) );
		self::assertSame( OperationalContext::EXECUTION_RETRY, $event_store->events[1]->get( 'execution_type' ) );
		self::assertNotSame( $event_store->events[0]->get( 'trace_id' ), $event_store->events[1]->get( 'trace_id' ) );

		$target = $this->manager( $delivery_store )->target_state( 10, 7 );
		self::assertSame( DeliveryStateManager::EXECUTION_ORDINARY, $target['executions'][0]['type'] );
		self::assertSame( DeliveryStateManager::EXECUTION_MANUAL_RETRY, $target['executions'][1]['type'] );
		self::assertFalse( $target['attention_required'] );
	}

	/**
	 * Test bale feed is observed as bale not sms.
	 */
	public function test_bale_feed_is_observed_as_bale_not_sms(): void {
		$delivery_store = new InMemoryDeliveryStateStore();
		$event_store    = new InMemoryOperationalEventStore();
		$bale           = new FakeBaleChannel( AttemptStatus::SUCCESS );
		$add_on         = $this->configured_add_on( $delivery_store, array(), $bale, $event_store );

		self::assertTrue( $add_on->process_feed( $this->bale_feed(), $this->entry(), $this->form() ) );
		self::assertSame( 1, $bale->send_count );
		self::assertCount( 1, $event_store->events );
		self::assertSame( 'bale', $event_store->events[0]->get( 'channel' ) );
		self::assertNull( $event_store->events[0]->get( 'sender' ) );
	}

		/**
		 * Configured add on.
		 *
		 * @param InMemoryDeliveryStateStore    $delivery_store Value.
		 * @param array                         $providers Value.
		 * @param BaleChannelInterface|null     $bale Value.
		 * @param InMemoryOperationalEventStore $event_store Value.
		 * @return NotificationFeedAddOn Return value.
		 */
	private function configured_add_on(
		InMemoryDeliveryStateStore $delivery_store,
		array $providers,
		?BaleChannelInterface $bale,
		InMemoryOperationalEventStore $event_store
	): NotificationFeedAddOn {
		$add_on = NotificationFeedAddOn::get_instance();
		$add_on->configure_delivery_state_manager( $this->manager( $delivery_store ) );
		$add_on->configure_processor( $this->processor( $providers, $bale, $event_store ) );
		return $add_on;
	}

		/**
		 * Processor.
		 *
		 * @param array                         $providers Value.
		 * @param BaleChannelInterface|null     $bale Value.
		 * @param InMemoryOperationalEventStore $events Value.
		 * @return NotificationFeedProcessor Return value.
		 */
	private function processor( array $providers, ?BaleChannelInterface $bale, InMemoryOperationalEventStore $events ): NotificationFeedProcessor {
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
			new SynchronousDispatcher( new SmsProviderRegistry( $providers ), $bale ),
			'+982100000000',
			new OperationalLogger( $events )
		);
	}

		/**
		 * Sms feed.
		 *
		 * @param string $fallback Value.
		 * @return array Return value.
		 */
	private function sms_feed( string $fallback = FeedRuleSchema::FALLBACK_NONE ): array {
		return $this->feed( FeedRuleSchema::CHANNEL_SMS, '+989121234567', $fallback );
	}

		/**
		 * Bale feed.
		 *
		 * @return array Return value.
		 */
	private function bale_feed(): array {
		return $this->feed( FeedRuleSchema::CHANNEL_BALE, '123456789', FeedRuleSchema::FALLBACK_NONE );
	}

		/**
		 * Feed.
		 *
		 * @param string $channel Value.
		 * @param string $recipient Value.
		 * @param string $fallback Value.
		 * @return array Return value.
		 */
	private function feed( string $channel, string $recipient, string $fallback ): array {
		return array(
			'id'         => 7,
			'form_id'    => 5,
			'is_active'  => true,
			'addon_slug' => 'gravity-notification-manager',
			'meta'       => array(
				'feedName'               => 'Case update',
				'message'                => 'Case accepted',
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
				'recipient_source_value' => $recipient,
				'channel'                => $channel,
				'fallback_policy'        => $fallback,
			),
		);
	}

		/**
		 * Entry.
		 *
		 * @return array Return value.
		 */
	private function entry(): array {
		return array(
			'id'      => 10,
			'form_id' => 5,
		);
	}

		/**
		 * Form.
		 *
		 * @return array Return value.
		 */
	private function form(): array {
		return array( 'id' => 5 );
	}

	/**
	 * Manager.
	 *
	 * @param InMemoryDeliveryStateStore $store Value.
	 * @return DeliveryStateManager Return value.
	 */
	private function manager( InMemoryDeliveryStateStore $store ): DeliveryStateManager {
		return new DeliveryStateManager( $store, static fn(): string => '2026-09-14T00:00:00+00:00' );
	}
}
