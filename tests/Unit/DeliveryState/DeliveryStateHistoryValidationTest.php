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
 * Proves malformed nested retained history cannot become Retry/suppression authority.
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
	 * Missing/wrong execution fields, type and outcome truth all fail closed.
	 *
	 * @return void
	 */
	public function test_malformed_execution_records_fail_closed(): void {
		$mutations = array(
			'missing required execution field' => static function ( array &$target ): void {
				unset( $target['executions'][0]['timestamp'] );
			},
			'invalid execution type'            => static function ( array &$target ): void {
				$target['executions'][0]['type'] = 'UNKNOWN';
			},
			'wrong timestamp type'              => static function ( array &$target ): void {
				$target['executions'][0]['timestamp'] = array();
			},
			'wrong delivery truth type'         => static function ( array &$target ): void {
				$target['executions'][0]['delivery_succeeded'] = 1;
			},
			'invalid execution final status'    => static function ( array &$target ): void {
				$target['executions'][0]['final_status'] = 'UNKNOWN';
			},
			'contradictory execution outcome'   => static function ( array &$target ): void {
				$target['executions'][0]['delivery_succeeded'] = false;
			},
		);

		foreach ( $mutations as $label => $mutate ) {
			$store   = $this->ordinary_store( true );
			$manager = $this->manager( $store );
			$mutate( $store->states[10]['notifications']['feed:7'] );
			$this->assert_target_rejected( $manager, $label );
		}
	}

	/**
	 * Duplicate/out-of-order execution sequences and stale target sequence fail closed.
	 *
	 * @return void
	 */
	public function test_execution_sequence_relationships_fail_closed_when_stale_or_reordered(): void {
		$mutations = array(
			'duplicate sequence'    => static function ( array &$target ): void {
				$target['executions'][1]['execution_sequence'] = 1;
			},
			'out-of-order sequence' => static function ( array &$target ): void {
				$target['executions'][0]['execution_sequence'] = 3;
			},
			'last sequence lower'   => static function ( array &$target ): void {
				$target['last_execution_sequence'] = 1;
			},
			'last sequence higher'  => static function ( array &$target ): void {
				$target['last_execution_sequence'] = 3;
			},
		);

		foreach ( $mutations as $label => $mutate ) {
			$store   = $this->two_ordinary_execution_store();
			$manager = $this->manager( $store );
			$mutate( $store->states[10]['notifications']['feed:7'] );
			$this->assert_target_rejected( $manager, $label );
		}
	}

	/**
	 * Malformed nested attempts never remain trusted transport evidence.
	 *
	 * @return void
	 */
	public function test_malformed_attempt_records_fail_closed(): void {
		$mutations = array(
			'attempt item not array'        => static function ( array &$target ): void {
				$target['executions'][0]['attempts'][0] = 'invalid';
			},
			'missing attempt field'         => static function ( array &$target ): void {
				unset( $target['executions'][0]['attempts'][0]['capability'] );
			},
			'parent sequence mismatch'      => static function ( array &$target ): void {
				$target['executions'][0]['attempts'][0]['execution_sequence'] = 2;
			},
			'invalid attempt status'        => static function ( array &$target ): void {
				$target['executions'][0]['attempts'][0]['status'] = 'UNKNOWN';
			},
			'malformed provider references' => static function ( array &$target ): void {
				$target['executions'][0]['attempts'][0]['provider_references'] = array( array( 'bad' ) );
			},
			'wrong provider representation' => static function ( array &$target ): void {
				$target['executions'][0]['attempts'][0]['provider'] = array();
			},
		);

		foreach ( $mutations as $label => $mutate ) {
			$store   = $this->ordinary_store( false );
			$manager = $this->manager( $store );
			$mutate( $store->states[10]['notifications']['feed:7'] );
			$this->assert_target_rejected( $manager, $label );
		}
	}

	/**
	 * Duplicate/out-of-order attempt sequences fail closed.
	 *
	 * @return void
	 */
	public function test_attempt_sequences_must_be_strictly_increasing_and_unique(): void {
		$mutations = array(
			'duplicate attempt sequence' => static function ( array &$target ): void {
				$target['executions'][0]['attempts'][1]['attempt_sequence'] = 1;
			},
			'out-of-order attempts'      => static function ( array &$target ): void {
				$target['executions'][0]['attempts'][0]['attempt_sequence'] = 2;
				$target['executions'][0]['attempts'][1]['attempt_sequence'] = 1;
			},
		);

		foreach ( $mutations as $label => $mutate ) {
			$store   = $this->ordinary_store_with_two_attempts();
			$manager = $this->manager( $store );
			$mutate( $store->states[10]['notifications']['feed:7'] );
			$this->assert_target_rejected( $manager, $label );
		}
	}

	/**
	 * Malformed safe-skip records invalidate the retained target.
	 *
	 * @return void
	 */
	public function test_malformed_skip_records_fail_closed(): void {
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
					array( array( 'subject' => 'recipient', 'reason' => 'missing_destination' ) ),
					false
				)
			)
		);

		$store->states[10]['notifications']['feed:7']['executions'][0]['skips'][0] = array( 'subject' => 'recipient' );
		$this->assert_target_rejected( $manager, 'malformed skip' );
	}

	/**
	 * Retry history must map one-to-one to retained manual-Retry executions.
	 *
	 * @return void
	 */
	public function test_retry_history_relationships_fail_closed_when_contradictory(): void {
		$mutations = array(
			'retry points to ordinary execution' => static function ( array &$target ): void {
				$target['retry_history'][] = array(
					'execution_sequence' => 1,
					'timestamp'          => '2026-09-09T00:00:00+00:00',
					'resolved'           => false,
				);
			},
			'manual retry history missing'       => static function ( array &$target ): void {
				$target['retry_history'] = array();
			},
			'retry resolved contradiction'       => static function ( array &$target ): void {
				$target['retry_history'][0]['resolved'] = true;
			},
			'duplicate retry linkage'             => static function ( array &$target ): void {
				$target['retry_history'][] = $target['retry_history'][0];
			},
		);

		foreach ( $mutations as $label => $mutate ) {
			$store = 'retry points to ordinary execution' === $label
				? $this->ordinary_store( false )
				: $this->unresolved_retry_store();
			$manager = $this->manager( $store );
			$mutate( $store->states[10]['notifications']['feed:7'] );
			$this->assert_target_rejected( $manager, $label );
		}
	}

	/**
	 * resolved_by_retry must agree with retained successful Retry evidence.
	 *
	 * @return void
	 */
	public function test_resolved_by_retry_must_match_retained_retry_truth(): void {
		$unresolved = $this->unresolved_retry_store();
		$unresolved->states[10]['notifications']['feed:7']['resolved_by_retry'] = true;
		$this->assert_target_rejected( $this->manager( $unresolved ), 'true without successful Retry' );

		$resolved = $this->successful_multi_retry_store();
		$resolved->states[10]['notifications']['feed:7']['resolved_by_retry'] = false;
		$this->assert_target_rejected( $this->manager( $resolved ), 'false with successful Retry' );
	}

	/**
	 * Target decision fields must describe the final retained execution.
	 *
	 * @return void
	 */
	public function test_target_final_state_must_match_final_retained_execution(): void {
		$store   = $this->ordinary_store( true );
		$manager = $this->manager( $store );
		$store->states[10]['notifications']['feed:7']['final_status']       = DeliveryStateManager::FINAL_UNRESOLVED;
		$store->states[10]['notifications']['feed:7']['attention_required'] = true;
		$this->assert_target_rejected( $manager, 'stale top-level final state' );
	}

	/**
	 * Current writer-produced ordinary and Retry histories remain trusted.
	 *
	 * @return void
	 */
	public function test_current_writer_produced_histories_remain_valid(): void {
		$unresolved_ordinary = $this->ordinary_store( false );
		self::assertSame( DeliveryStateManager::RETRY_ALLOWED, $this->manager( $unresolved_ordinary )->retry_eligibility( 10, 7 ) );

		$resolved_ordinary = $this->ordinary_store( true );
		self::assertTrue( $this->manager( $resolved_ordinary )->is_confirmed_complete( 10, 7 ) );

		$unresolved_retry  = $this->unresolved_retry_store();
		$unresolved_target = $this->manager( $unresolved_retry )->target_state( 10, 7 );
		self::assertNotNull( $unresolved_target );
		self::assertTrue( $unresolved_target['attention_required'] );
		self::assertFalse( $unresolved_target['retry_history'][0]['resolved'] );

		$resolved_retry  = $this->successful_multi_retry_store();
		$resolved_target = $this->manager( $resolved_retry )->target_state( 10, 7 );
		self::assertNotNull( $resolved_target );
		self::assertCount( 3, $resolved_target['executions'] );
		self::assertCount( 2, $resolved_target['retry_history'] );
		self::assertTrue( $resolved_target['resolved_by_retry'] );
		self::assertFalse( $resolved_target['attention_required'] );
	}

	/**
	 * Valid duplicate-suppressed retained history remains trusted.
	 *
	 * @return void
	 */
	public function test_duplicate_suppressed_history_remains_valid(): void {
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
		self::assertSame( 1, $provider->send_count );
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
	 * Malformed nested history fails closed before manual Retry transport or mutation.
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
	 * Assert all target trust decisions reject one malformed target.
	 *
	 * @param DeliveryStateManager $manager Manager.
	 * @param string               $label Assertion label.
	 * @return void
	 */
	private function assert_target_rejected( DeliveryStateManager $manager, string $label ): void {
		self::assertSame( DeliveryStateManager::RETRY_STATE_MALFORMED, $manager->retry_eligibility( 10, 7 ), $label );
		self::assertFalse( $manager->is_confirmed_complete( 10, 7 ), $label );
		self::assertNull( $manager->target_state( 10, 7 ), $label );
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
	 * Build two valid ordinary executions for sequence mutation tests.
	 *
	 * @return InMemoryDeliveryStateStore
	 */
	private function two_ordinary_execution_store(): InMemoryDeliveryStateStore {
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
	 * Build one valid ordinary execution with two ordered attempts.
	 *
	 * @return InMemoryDeliveryStateStore
	 */
	private function ordinary_store_with_two_attempts(): InMemoryDeliveryStateStore {
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
	 * Build valid unresolved ordinary + unresolved manual-Retry history.
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
	 * Build ordinary failure + unresolved Retry + successful Retry history.
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
	 * Build the existing recipient + synchronous transport processor.
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
