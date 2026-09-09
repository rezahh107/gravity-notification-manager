<?php
/**
 * WU-05 execution/state integration tests over the existing WU-02/03/04 seams.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\GravityForms;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Sms\SmsProviderInterface;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Tests\Support\Delivery\FakeSmsProvider;
use GravityNotify\Tests\Support\DeliveryState\InMemoryDeliveryStateStore;
use GravityNotify\Tests\Support\GravityForms\GFFeedAddOnStub;
use GravityNotify\Tests\Support\Recipient\FakeEntryFieldReader;
use GravityNotify\Tests\Support\Recipient\FakeFlowAssigneeReader;
use GravityNotify\Tests\Support\Recipient\FakeUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Proves state persistence does not fork transport/recipient execution semantics.
 */
final class NotificationDeliveryStateTest extends TestCase {

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
	 * Prevent singleton composition from leaking across tests.
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
	 * T-WU05-05: AMBIGUOUS still falls through the configured synchronous chain and persists truthfully.
	 *
	 * @return void
	 */
	public function test_ambiguous_transport_fallback_is_unchanged_and_persisted_as_ambiguous(): void {
		$first  = new FakeSmsProvider( AttemptStatus::AMBIGUOUS, 'first' );
		$second = new FakeSmsProvider( AttemptStatus::SUCCESS, 'second' );
		$store  = new InMemoryDeliveryStateStore();
		$add_on = $this->configured_add_on( $store, array( $first, $second ) );

		self::assertTrue( $add_on->process_feed( $this->feed( FeedRuleSchema::FALLBACK_COMPATIBLE_SMS ), $this->entry(), $this->form() ) );
		self::assertSame( 1, $first->send_count );
		self::assertSame( 1, $second->send_count );

		$target = $this->manager( $store )->target_state( 10, 7 );
		self::assertSame(
			array( AttemptStatus::AMBIGUOUS, AttemptStatus::SUCCESS ),
			array_column( $target['executions'][0]['attempts'], 'status' )
		);
		self::assertFalse( $target['attention_required'] );
	}

	/**
	 * T-WU05-06: recipient failure before transport is persisted and sends nothing.
	 *
	 * @return void
	 */
	public function test_pre_transport_recipient_failure_is_persisted_without_fake_attempt(): void {
		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS, 'unused' );
		$store    = new InMemoryDeliveryStateStore();
		$add_on   = $this->configured_add_on( $store, array( $provider ) );
		$feed     = $this->feed( FeedRuleSchema::FALLBACK_NONE, '' );

		self::assertFalse( $add_on->process_feed( $feed, $this->entry(), $this->form() ) );
		self::assertSame( 0, $provider->send_count );

		$target = $this->manager( $store )->target_state( 10, 7 );
		self::assertSame( array(), $target['executions'][0]['attempts'] );
		self::assertSame( 'missing_destination', $target['executions'][0]['skips'][0]['reason'] );
		self::assertTrue( $target['attention_required'] );
	}

	/**
	 * T-WU05-07: a confirmed ordinary execution is sequentially duplicate-suppressed without a second send.
	 *
	 * @return void
	 */
	public function test_confirmed_ordinary_execution_is_best_effort_duplicate_suppressed(): void {
		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS, 'primary' );
		$store    = new InMemoryDeliveryStateStore();
		$add_on   = $this->configured_add_on( $store, array( $provider ) );
		$feed     = $this->feed();

		self::assertTrue( $add_on->process_feed( $feed, $this->entry(), $this->form() ) );
		self::assertTrue( $add_on->process_feed( $feed, $this->entry(), $this->form() ) );
		self::assertSame( 1, $provider->send_count );

		$target = $this->manager( $store )->target_state( 10, 7 );
		self::assertCount( 2, $target['executions'] );
		self::assertSame( DeliveryStateManager::EXECUTION_ORDINARY, $target['executions'][0]['type'] );
		self::assertSame( DeliveryStateManager::EXECUTION_DUPLICATE_SUPPRESSED, $target['executions'][1]['type'] );
		self::assertSame( 'duplicate_suppressed', $target['executions'][1]['skips'][0]['reason'] );
		self::assertSame( array(), $target['executions'][1]['attempts'] );
	}

	/**
	 * T-WU05-08: explicit Retry bypasses ordinary suppression, reuses the current chain and resolves Attention Required on success.
	 *
	 * @return void
	 */
	public function test_manual_retry_success_appends_history_and_resolves_attention_required(): void {
		$failed = new FakeSmsProvider( AttemptStatus::FAILED, 'primary' );
		$store  = new InMemoryDeliveryStateStore();
		$add_on = $this->configured_add_on( $store, array( $failed ) );
		$feed   = $this->feed();

		self::assertFalse( $add_on->process_feed( $feed, $this->entry(), $this->form() ) );
		self::assertSame( 1, $failed->send_count );

		$success = new FakeSmsProvider( AttemptStatus::SUCCESS, 'primary' );
		$add_on->configure_processor( $this->processor( array( $success ) ) );
		$result = $add_on->retry_feed( $feed, $this->entry(), $this->form() );

		self::assertNotNull( $result );
		self::assertTrue( $result->delivery_succeeded() );
		self::assertSame( 1, $success->send_count );

		$target = $this->manager( $store )->target_state( 10, 7 );
		self::assertCount( 2, $target['executions'] );
		self::assertSame( DeliveryStateManager::EXECUTION_MANUAL_RETRY, $target['executions'][1]['type'] );
		self::assertSame( AttemptStatus::FAILED, $target['executions'][0]['attempts'][0]['status'] );
		self::assertSame( AttemptStatus::SUCCESS, $target['executions'][1]['attempts'][0]['status'] );
		self::assertFalse( $target['attention_required'] );
		self::assertTrue( $target['retry_history'][0]['resolved'] );
	}

	/**
	 * T-WU05-09: unresolved Retry appends truthful history and leaves Attention Required set.
	 *
	 * @return void
	 */
	public function test_manual_retry_unresolved_preserves_attention_and_exact_attempt_status(): void {
		$initial = new FakeSmsProvider( AttemptStatus::FAILED, 'primary' );
		$store   = new InMemoryDeliveryStateStore();
		$add_on  = $this->configured_add_on( $store, array( $initial ) );
		$feed    = $this->feed();

		self::assertFalse( $add_on->process_feed( $feed, $this->entry(), $this->form() ) );

		$ambiguous = new FakeSmsProvider( AttemptStatus::AMBIGUOUS, 'primary' );
		$add_on->configure_processor( $this->processor( array( $ambiguous ) ) );
		$result = $add_on->retry_feed( $feed, $this->entry(), $this->form() );

		self::assertNotNull( $result );
		self::assertFalse( $result->delivery_succeeded() );
		self::assertSame( 1, $ambiguous->send_count );

		$target = $this->manager( $store )->target_state( 10, 7 );
		self::assertCount( 2, $target['executions'] );
		self::assertSame( AttemptStatus::AMBIGUOUS, $target['executions'][1]['attempts'][0]['status'] );
		self::assertTrue( $target['attention_required'] );
		self::assertFalse( $target['retry_history'][0]['resolved'] );
	}

	/**
	 * Configure the singleton with deterministic WU-05 state and current WU-02/03/04 processor.
	 *
	 * @param InMemoryDeliveryStateStore       $store State store.
	 * @param array<int, SmsProviderInterface> $providers Providers.
	 * @return NotificationFeedAddOn
	 */
	private function configured_add_on( InMemoryDeliveryStateStore $store, array $providers ): NotificationFeedAddOn {
		$add_on = NotificationFeedAddOn::get_instance();
		$add_on->configure_delivery_state_manager( $this->manager( $store ) );
		$add_on->configure_processor( $this->processor( $providers ) );
		return $add_on;
	}

	/**
	 * Build the existing WU-03 resolver and WU-02 dispatcher.
	 *
	 * @param array<int, SmsProviderInterface> $providers Providers.
	 * @return NotificationFeedProcessor
	 */
	private function processor( array $providers ): NotificationFeedProcessor {
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
			new SynchronousDispatcher( new SmsProviderRegistry( $providers ), null ),
			'+982100000000'
		);
	}

	/**
	 * Build one logical SMS Feed.
	 *
	 * @param string $fallback Fallback policy.
	 * @param string $recipient Fixed recipient.
	 * @return array<string, mixed>
	 */
	private function feed( string $fallback = FeedRuleSchema::FALLBACK_NONE, string $recipient = '+989121234567' ): array {
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
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy'        => $fallback,
			),
		);
	}

	/**
	 * Build one Entry fixture.
	 *
	 * @return array<string, int>
	 */
	private function entry(): array {
		return array(
			'id'      => 10,
			'form_id' => 5,
		);
	}

	/**
	 * Build one Form fixture.
	 *
	 * @return array<string, int>
	 */
	private function form(): array {
		return array( 'id' => 5 );
	}

	/**
	 * Deterministic manager.
	 *
	 * @param InMemoryDeliveryStateStore $store Store.
	 * @return DeliveryStateManager
	 */
	private function manager( InMemoryDeliveryStateStore $store ): DeliveryStateManager {
		return new DeliveryStateManager( $store, static fn(): string => '2026-09-09T00:00:00+00:00' );
	}
}
