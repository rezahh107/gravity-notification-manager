<?php
/**
 * WU-05 retained-history trust-boundary regression tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\DeliveryState;

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
 * Proves malformed retained history cannot become Retry or suppression authority.
 */
final class DeliveryStateHistoryValidationTest extends TestCase {

	/**
	 * Install the deterministic Gravity Forms Add-On parent stub.
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
	 * Prevent singleton test composition from leaking across tests.
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
	 * Execution records, sequence metadata and target outcome must remain coherent.
	 *
	 * @return void
	 */
	public function test_malformed_execution_history_fails_closed(): void {
		$this->assert_rejected_mutation(
			$this->ordinary_store( true ),
			static function ( array &$target ): void {
				unset( $target['executions'][0]['timestamp'] );
			}
		);
		$this->assert_rejected_mutation(
			$this->ordinary_store( true ),
			static function ( array &$target ): void {
				$target['executions'][0]['type'] = 'UNKNOWN';
			}
		);
		$this->assert_rejected_mutation(
			$this->ordinary_store( true ),
			static function ( array &$target ): void {
				$target['executions'][0]['delivery_succeeded'] = false;
			}
		);
		$this->assert_rejected_mutation(
			$this->two_execution_store(),
			static function ( array &$target ): void {
				$target['executions'][1]['execution_sequence'] = 1;
			}
		);
		$this->assert_rejected_mutation(
			$this->two_execution_store(),
			static function ( array &$target ): void {
				$target['executions'][0]['execution_sequence'] = 3;
			}
		);
		$this->assert_rejected_mutation(
			$this->two_execution_store(),
			static function ( array &$target ): void {
				$target['last_execution_sequence'] = 1;
			}
		);
		$this->assert_rejected_mutation(
			$this->ordinary_store( true ),
			static function ( array &$target ): void {
				$target['final_status']       = DeliveryStateManager::FINAL_UNRESOLVED;
				$target['attention_required'] = true;
			}
		);
	}

	/**
	 * Attempt records must retain exact parent, ordering, status and reference shape.
	 *
	 * @return void
	 */
	public function test_malformed_attempt_history_fails_closed(): void {
		$this->assert_rejected_mutation(
			$this->ordinary_store( false ),
			static function ( array &$target ): void {
				$target['executions'][0]['attempts'][0]['status'] = 'UNKNOWN';
			}
		);
		$this->assert_rejected_mutation(
			$this->ordinary_store( false ),
			static function ( array &$target ): void {
				$target['executions'][0]['attempts'][0]['execution_sequence'] = 2;
			}
		);
		$this->assert_rejected_mutation(
			$this->two_attempt_store(),
			static function ( array &$target ): void {
				$target['executions'][0]['attempts'][1]['attempt_sequence'] = 1;
			}
		);
		$this->assert_rejected_mutation(
			$this->two_attempt_store(),
			static function ( array &$target ): void {
				$target['executions'][0]['attempts'][0]['attempt_sequence'] = 2;
				$target['executions'][0]['attempts'][1]['attempt_sequence'] = 1;
			}
		);
		$this->assert_rejected_mutation(
			$this->ordinary_store( false ),
			static function ( array &$target ): void {
				$target['executions'][0]['attempts'][0]['provider_references'] = array( array( 'bad' ) );
			}
		);
	}

	/**
	 * Malformed safe-skip records invalidate the retained target.
	 *
	 * @return void
	 */
	public function test_malformed_skip_history_fails_closed(): void {
		$store   = new InMemoryDeliveryStateStore();
		$manager = $this->manager( $store );
		self::assertTrue(
			$manager->record_execution(
				10,
				5,
				7,
				'Case update',
				'sms',
				new NotificationExecutionResult(
					array(),
					array(
						array(
							'subject' => 'recipient',
							'reason'  => 'missing_destination',
						),
					),
					false
				)
			)
		);
		$store->states[10]['notifications']['feed:7']['executions'][0]['skips'][0] = array(
			'subject' => 'recipient',
		);
		$this->assert_target_rejected( $manager );
	}

	/**
	 * Manual-Retry history must map one-to-one to retained Retry executions.
	 *
	 * @return void
	 */
	public function test_retry_history_relationships_fail_closed_when_contradictory(): void {
		$this->assert_rejected_mutation(
			$this->ordinary_store( false ),
			static function ( array &$target ): void {
				$target['retry_history'][] = array(
					'execution_sequence' => 1,
					'timestamp'          => '2026-09-09T00:00:00+00:00',
					'resolved'           => false,
				);
			}
		);
		$this->assert_rejected_mutation(
			$this->unresolved_retry_store(),
			static function ( array &$target ): void {
				$target['retry_history'] = array();
			}
		);
		$this->assert_rejected_mutation(
			$this->unresolved_retry_store(),
			static function ( array &$target ): void {
				$target['retry_history'][0]['resolved'] = true;
			}
		);
		$this->assert_rejected_mutation(
			$this->unresolved_retry_store(),
			static function ( array &$target ): void {
				$target['resolved_by_retry'] = true;
			}
		);
		$this->assert_rejected_mutation(
			$this->successful_multi_retry_store(),
			static function ( array &$target ): void {
				$target['resolved_by_retry'] = false;
			}
		);
	}

	/**
	 * Current writer-produced ordinary, suppressed and multi-Retry history remains trusted.
	 *
	 * @return void
	 */
	public function test_current_writer_produced_histories_remain_valid(): void {
		$unresolved = $this->ordinary_store( false );
		self::assertSame( DeliveryStateManager::RETRY_ALLOWED, $this->manager( $unresolved )->retry_eligibility( 10, 7 ) );

		$resolved = $this->ordinary_store( true );
		self::assertTrue( $this->manager( $resolved )->is_confirmed_complete( 10, 7 ) );

		$retry  = $this->successful_multi_retry_store();
		$target = $this->manager( $retry )->target_state( 10, 7 );
		self::assertNotNull( $target );
		self::assertCount( 3, $target['executions'] );
		self::assertCount( 2, $target['retry_history'] );
		self::assertTrue( $target['resolved_by_retry'] );

		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS, 'primary' );
		$store    = new InMemoryDeliveryStateStore();
		$add_on   = $this->configured_add_on( $store, $provider );
		self::assertTrue( $add_on->process_feed( $this->feed(), $this->entry(), $this->form() ) );
		self::assertTrue( $add_on->process_feed( $this->feed(), $this->entry(), $this->form() ) );
		self::assertSame( 1, $provider->send_count );
		self::assertTrue( $this->manager( $store )->is_confirmed_complete( 10, 7 ) );
	}

	/**
	 * Malformed nested history bypasses suppression and uses existing ordinary recovery.
	 *
	 * @return void
	 */
	public function test_malformed_nested_history_recovers_on_ordinary_processing(): void {
		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS, 'primary' );
		$store    = new InMemoryDeliveryStateStore();
		$add_on   = $this->configured_add_on( $store, $provider );

		self::assertTrue( $add_on->process_feed( $this->feed(), $this->entry(), $this->form() ) );
		$store->states[10]['notifications']['feed:7']['executions'][0]['type'] = 'BROKEN';
		self::assertTrue( $add_on->process_feed( $this->feed(), $this->entry(), $this->form() ) );
		self::assertSame( 2, $provider->send_count );

		$target = $this->manager( $store )->target_state( 10, 7 );
		self::assertNotNull( $target );
		self::assertCount( 1, $target['executions'] );
		self::assertContains(
			array(
				'subject' => 'delivery_state',
				'reason'  => 'prior_state_malformed',
			),
			$target['executions'][0]['skips']
		);
	}

	/**
	 * Malformed nested history blocks manual Retry before send or state mutation.
	 *
	 * @return void
	 */
	public function test_malformed_nested_history_blocks_manual_retry_with_zero_send_and_mutation(): void {
		$store   = $this->ordinary_store( false );
		$manager = $this->manager( $store );
		$store->states[10]['notifications']['feed:7']['executions'][0]['attempts'][0]['status'] = 'BROKEN';
		$state_before  = $store->states[10];
		$writes_before = $store->write_count;

		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS, 'current' );
		$add_on   = NotificationFeedAddOn::get_instance();
		$add_on->configure_delivery_state_manager( $manager );
		$add_on->configure_processor( $this->processor( $provider ) );

		$runtime              = new FakeManualRetryRuntime();
		$runtime->entries[10] = $this->entry();
		$runtime->forms[5]    = $this->form();
		$runtime->feeds[7]    = $this->feed();
		$handler              = new ManualRetryHandler( $add_on, $runtime );

		self::assertSame( ManualRetryHandler::ERROR_STATE, $handler->dispatch( 'POST', $this->valid_request() ) );
		self::assertSame( 0, $provider->send_count );
		self::assertSame( $writes_before, $store->write_count );
		self::assertSame( $state_before, $store->states[10] );
	}

	/**
	 * Apply one target mutation and assert all trust decisions fail closed.
	 *
	 * @param InMemoryDeliveryStateStore $store State store.
	 * @param callable                   $mutate Target mutation.
	 * @return void
	 */
	private function assert_rejected_mutation( InMemoryDeliveryStateStore $store, callable $mutate ): void {
		$manager = $this->manager( $store );
		$mutate( $store->states[10]['notifications']['feed:7'] );
		$this->assert_target_rejected( $manager );
	}

	/**
	 * Assert all target trust decisions reject malformed retained state.
	 *
	 * @param DeliveryStateManager $manager Manager.
	 * @return void
	 */
	private function assert_target_rejected( DeliveryStateManager $manager ): void {
		self::assertSame( DeliveryStateManager::RETRY_STATE_MALFORMED, $manager->retry_eligibility( 10, 7 ) );
		self::assertFalse( $manager->is_confirmed_complete( 10, 7 ) );
		self::assertNull( $manager->target_state( 10, 7 ) );
	}

	/**
	 * Build one valid ordinary target.
	 *
	 * @param bool $resolved Delivery result.
	 * @return InMemoryDeliveryStateStore
	 */
	private function ordinary_store( bool $resolved ): InMemoryDeliveryStateStore {
		$store   = new InMemoryDeliveryStateStore();
		$manager = $this->manager( $store );
		$status  = $resolved ? AttemptStatus::SUCCESS : AttemptStatus::FAILED;
		self::assertTrue(
			$manager->record_execution(
				10,
				5,
				7,
				'Case update',
				'sms',
				new NotificationExecutionResult( array( $this->attempt( $status, 'primary' ) ), array(), $resolved )
			)
		);
		return $store;
	}

	/**
	 * Build two valid ordinary executions.
	 *
	 * @return InMemoryDeliveryStateStore
	 */
	private function two_execution_store(): InMemoryDeliveryStateStore {
		$store   = $this->ordinary_store( false );
		$manager = $this->manager( $store );
		self::assertTrue(
			$manager->record_execution(
				10,
				5,
				7,
				'Case update',
				'sms',
				new NotificationExecutionResult( array( $this->attempt( AttemptStatus::SUCCESS, 'primary' ) ), array(), true )
			)
		);
		return $store;
	}

	/**
	 * Build one valid ordinary execution with two attempts.
	 *
	 * @return InMemoryDeliveryStateStore
	 */
	private function two_attempt_store(): InMemoryDeliveryStateStore {
		$store   = new InMemoryDeliveryStateStore();
		$manager = $this->manager( $store );
		self::assertTrue(
			$manager->record_execution(
				10,
				5,
				7,
				'Case update',
				'sms',
				new NotificationExecutionResult(
					array(
						$this->attempt( AttemptStatus::AMBIGUOUS, 'primary' ),
						$this->attempt( AttemptStatus::FAILED, 'secondary' ),
					),
					array(),
					false
				)
			)
		);
		return $store;
	}

	/**
	 * Build valid unresolved ordinary plus unresolved manual-Retry history.
	 *
	 * @return InMemoryDeliveryStateStore
	 */
	private function unresolved_retry_store(): InMemoryDeliveryStateStore {
		$store   = $this->ordinary_store( false );
		$manager = $this->manager( $store );
		self::assertTrue(
			$manager->record_execution(
				10,
				5,
				7,
				'Case update',
				'sms',
				new NotificationExecutionResult( array( $this->attempt( AttemptStatus::AMBIGUOUS, 'primary' ) ), array(), false ),
				DeliveryStateManager::EXECUTION_MANUAL_RETRY
			)
		);
		return $store;
	}

	/**
	 * Build ordinary failure, unresolved Retry and successful Retry history.
	 *
	 * @return InMemoryDeliveryStateStore
	 */
	private function successful_multi_retry_store(): InMemoryDeliveryStateStore {
		$store   = $this->unresolved_retry_store();
		$manager = $this->manager( $store );
		self::assertTrue(
			$manager->record_execution(
				10,
				5,
				7,
				'Case update',
				'sms',
				new NotificationExecutionResult( array( $this->attempt( AttemptStatus::SUCCESS, 'primary' ) ), array(), true ),
				DeliveryStateManager::EXECUTION_MANUAL_RETRY
			)
		);
		return $store;
	}

	/**
	 * Configure the existing Add-On with deterministic state and transport seams.
	 *
	 * @param InMemoryDeliveryStateStore $store State store.
	 * @param FakeSmsProvider            $provider Provider.
	 * @return NotificationFeedAddOn
	 */
	private function configured_add_on( InMemoryDeliveryStateStore $store, FakeSmsProvider $provider ): NotificationFeedAddOn {
		$add_on = NotificationFeedAddOn::get_instance();
		$add_on->configure_delivery_state_manager( $this->manager( $store ) );
		$add_on->configure_processor( $this->processor( $provider ) );
		return $add_on;
	}

	/**
	 * Build the existing recipient and synchronous transport processor.
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

	/**
	 * Build one safe transport attempt.
	 *
	 * @param string $status Status.
	 * @param string $provider Provider ID.
	 * @return AttemptResult
	 */
	private function attempt( string $status, string $provider ): AttemptResult {
		return new AttemptResult( $status, 'sms', $provider, 'plain', array( 'ref-1' ), 'test' );
	}

	/**
	 * Build deterministic state manager.
	 *
	 * @param InMemoryDeliveryStateStore $store Store.
	 * @return DeliveryStateManager
	 */
	private function manager( InMemoryDeliveryStateStore $store ): DeliveryStateManager {
		return new DeliveryStateManager( $store, static fn(): string => '2026-09-09T00:00:00+00:00' );
	}

	/**
	 * Build one logical Feed fixture.
	 *
	 * @return array<string, mixed>
	 */
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

	/**
	 * Build Entry fixture.
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
	 * Build Form fixture.
	 *
	 * @return array<string, int>
	 */
	private function form(): array {
		return array( 'id' => 5 );
	}

	/**
	 * Build valid manual Retry request.
	 *
	 * @return array<string, string>
	 */
	private function valid_request(): array {
		return array(
			'entry_id' => '10',
			'feed_id'  => '7',
			'_wpnonce' => 'valid-test-nonce',
		);
	}
}
