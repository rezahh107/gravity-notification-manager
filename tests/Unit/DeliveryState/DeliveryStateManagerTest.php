<?php
/**
 * Deterministic WU-05 Entry Meta state tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\DeliveryState;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\GravityForms\NotificationExecutionResult;
use GravityNotify\Tests\Support\DeliveryState\InMemoryDeliveryStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Proves the WU-05 state schema, history and final-state semantics independently.
 */
final class DeliveryStateManagerTest extends TestCase {

	/**
	 * T-WU05-01: namespaced/versioned state and malformed/missing reads are safe.
	 *
	 * @return void
	 */
	public function test_schema_is_namespaced_versioned_and_missing_or_malformed_state_is_safe(): void {
		$store   = new InMemoryDeliveryStateStore();
		$manager = $this->manager( $store );

		self::assertSame( DeliveryStateManager::RETRY_STATE_MISSING, $manager->retry_eligibility( 10, 7 ) );

		$store->malformed[10] = true;
		self::assertSame( DeliveryStateManager::RETRY_STATE_MALFORMED, $manager->retry_eligibility( 10, 7 ) );

		$result = new NotificationExecutionResult( array(), array(), false );
		self::assertTrue( $manager->record_execution( 10, 5, 7, 'Case update', 'sms', $result ) );

		$state = $store->states[10];
		self::assertSame( DeliveryStateManager::SCHEMA_NAMESPACE, $state['namespace'] );
		self::assertSame( DeliveryStateManager::SCHEMA_VERSION, $state['schema_version'] );
		self::assertSame( 10, $state['entry_id'] );
		self::assertArrayHasKey( 'feed:7', $state['notifications'] );

		$target = $state['notifications']['feed:7'];
		self::assertSame( 7, $target['feed_id'] );
		self::assertSame( 5, $target['form_id'] );
		self::assertSame( 'Case update', $target['feed_name'] );
		self::assertSame( 'sms', $target['channel'] );
		self::assertSame( 'prior_state_malformed', $target['executions'][0]['skips'][0]['reason'] );
	}

	/**
	 * T-WU05-02/03: every transport status is retained in order and success resolves attention.
	 *
	 * @return void
	 */
	public function test_full_attempt_history_preserves_exact_statuses_and_success_resolves_attention(): void {
		$store   = new InMemoryDeliveryStateStore();
		$manager = $this->manager( $store );
		$result  = new NotificationExecutionResult(
			array(
				$this->attempt( AttemptStatus::FAILED, 'first' ),
				$this->attempt( AttemptStatus::AMBIGUOUS, 'second' ),
				$this->attempt( AttemptStatus::SKIPPED, 'third' ),
				$this->attempt( AttemptStatus::SUCCESS, 'fourth', array( 'provider-ref-1' ) ),
			),
			array(),
			true
		);

		self::assertTrue( $manager->record_execution( 10, 5, 7, 'Case update', 'sms', $result ) );
		$target   = $manager->target_state( 10, 7 );
		$attempts = $target['executions'][0]['attempts'];

		self::assertSame(
			array( AttemptStatus::FAILED, AttemptStatus::AMBIGUOUS, AttemptStatus::SKIPPED, AttemptStatus::SUCCESS ),
			array_column( $attempts, 'status' )
		);
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $attempts, 'attempt_sequence' ) );
		self::assertSame( array( 'provider-ref-1' ), $attempts[3]['provider_references'] );
		self::assertSame( DeliveryStateManager::FINAL_RESOLVED, $target['final_status'] );
		self::assertFalse( $target['attention_required'] );
	}

	/**
	 * T-WU05-04/05: no confirmed success remains unresolved and AMBIGUOUS is never relabeled FAILED.
	 *
	 * @return void
	 */
	public function test_unresolved_state_preserves_ambiguity_truthfully(): void {
		$store   = new InMemoryDeliveryStateStore();
		$manager = $this->manager( $store );
		$result  = new NotificationExecutionResult(
			array(
				$this->attempt( AttemptStatus::AMBIGUOUS, 'first' ),
				$this->attempt( AttemptStatus::FAILED, 'second' ),
				$this->attempt( AttemptStatus::SKIPPED, 'third' ),
			),
			array(),
			false
		);

		self::assertTrue( $manager->record_execution( 10, 5, 7, 'Case update', 'sms', $result ) );
		$target = $manager->target_state( 10, 7 );

		self::assertSame( DeliveryStateManager::FINAL_UNRESOLVED, $target['final_status'] );
		self::assertTrue( $target['attention_required'] );
		self::assertSame(
			array( AttemptStatus::AMBIGUOUS, AttemptStatus::FAILED, AttemptStatus::SKIPPED ),
			array_column( $target['executions'][0]['attempts'], 'status' )
		);
	}

	/**
	 * T-WU05-06: pre-transport failure persists safe observability without inventing an attempt.
	 *
	 * @return void
	 */
	public function test_pre_transport_failure_is_recorded_without_fake_provider_attempt(): void {
		$store   = new InMemoryDeliveryStateStore();
		$manager = $this->manager( $store );
		$result  = new NotificationExecutionResult(
			array(),
			array(
				array(
					'subject' => 'recipient',
					'reason'  => 'missing_destination',
				),
			),
			false
		);

		self::assertTrue( $manager->record_execution( 10, 5, 7, 'Case update', 'sms', $result ) );
		$target    = $manager->target_state( 10, 7 );
		$execution = $target['executions'][0];

		self::assertSame( array(), $execution['attempts'] );
		self::assertSame( 'missing_destination', $execution['skips'][0]['reason'] );
		self::assertTrue( $execution['attention_required'] );
	}

	/**
	 * Manual Retry appends history and only confirmed success resolves Attention Required.
	 *
	 * @return void
	 */
	public function test_manual_retry_history_preserves_prior_execution_and_resolution_truth(): void {
		$store   = new InMemoryDeliveryStateStore();
		$manager = $this->manager( $store );

		self::assertTrue(
			$manager->record_execution(
				10,
				5,
				7,
				'Case update',
				'sms',
				new NotificationExecutionResult( array( $this->attempt( AttemptStatus::FAILED, 'first' ) ), array(), false )
			)
		);
		self::assertSame( DeliveryStateManager::RETRY_ALLOWED, $manager->retry_eligibility( 10, 7 ) );

		self::assertTrue(
			$manager->record_execution(
				10,
				5,
				7,
				'Case update',
				'sms',
				new NotificationExecutionResult( array( $this->attempt( AttemptStatus::SUCCESS, 'second' ) ), array(), true ),
				DeliveryStateManager::EXECUTION_MANUAL_RETRY
			)
		);

		$target = $manager->target_state( 10, 7 );
		self::assertCount( 2, $target['executions'] );
		self::assertSame( AttemptStatus::FAILED, $target['executions'][0]['attempts'][0]['status'] );
		self::assertSame( AttemptStatus::SUCCESS, $target['executions'][1]['attempts'][0]['status'] );
		self::assertCount( 1, $target['retry_history'] );
		self::assertTrue( $target['retry_history'][0]['resolved'] );
		self::assertTrue( $target['resolved_by_retry'] );
		self::assertFalse( $target['attention_required'] );
		self::assertSame( DeliveryStateManager::RETRY_NOT_REQUIRED, $manager->retry_eligibility( 10, 7 ) );
	}

	/**
	 * T-STATE-01: every required target-level field is mandatory at the trust boundary.
	 *
	 * @dataProvider required_target_field_provider
	 *
	 * @param string $field Required field to remove.
	 * @return void
	 */
	public function test_incomplete_target_state_is_rejected( string $field ): void {
		$store   = new InMemoryDeliveryStateStore();
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

		unset( $store->states[10]['notifications']['feed:7'][ $field ] );

		self::assertSame( DeliveryStateManager::RETRY_STATE_MALFORMED, $manager->retry_eligibility( 10, 7 ) );
		self::assertFalse( $manager->is_confirmed_complete( 10, 7 ) );
		self::assertNull( $manager->target_state( 10, 7 ) );
	}

	/**
	 * Required target-level fields.
	 *
	 * @return array<string, array{string}>
	 */
	public static function required_target_field_provider(): array {
		return array(
			'entry identity'              => array( 'entry_id' ),
			'form identity'               => array( 'form_id' ),
			'feed identity'               => array( 'feed_id' ),
			'feed name'                   => array( 'feed_name' ),
			'channel identity'            => array( 'channel' ),
			'final status'                => array( 'final_status' ),
			'attention state'             => array( 'attention_required' ),
			'execution sequence metadata' => array( 'last_execution_sequence' ),
			'retry resolution metadata'   => array( 'resolved_by_retry' ),
			'execution history'           => array( 'executions' ),
			'retry history'               => array( 'retry_history' ),
		);
	}

	/**
	 * T-STATE-01: required target fields also enforce their bounded types/identities.
	 *
	 * @dataProvider malformed_target_value_provider
	 *
	 * @param string $field Target field.
	 * @param mixed  $value Invalid value.
	 * @return void
	 */
	public function test_malformed_target_field_values_are_rejected( string $field, $value ): void {
		$store   = new InMemoryDeliveryStateStore();
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
		$store->states[10]['notifications']['feed:7'][ $field ] = $value;

		self::assertSame( DeliveryStateManager::RETRY_STATE_MALFORMED, $manager->retry_eligibility( 10, 7 ) );
		self::assertFalse( $manager->is_confirmed_complete( 10, 7 ) );
	}

	/**
	 * Invalid target field values.
	 *
	 * @return array<string, array{string,mixed}>
	 */
	public static function malformed_target_value_provider(): array {
		return array(
			'form identity not positive'    => array( 'form_id', 0 ),
			'form identity wrong type'      => array( 'form_id', '5' ),
			'feed name wrong type'          => array( 'feed_name', array() ),
			'channel wrong type'            => array( 'channel', null ),
			'attention wrong type'          => array( 'attention_required', 1 ),
			'last sequence not positive'    => array( 'last_execution_sequence', 0 ),
			'last sequence wrong type'      => array( 'last_execution_sequence', '1' ),
			'retry resolution wrong type'   => array( 'resolved_by_retry', 0 ),
			'execution history wrong type'  => array( 'executions', 'invalid' ),
			'execution history empty'       => array( 'executions', array() ),
			'retry history wrong type'      => array( 'retry_history', 'invalid' ),
		);
	}

	/**
	 * T-STATE-02: final state and Attention Required must remain coherent.
	 *
	 * @dataProvider contradictory_state_provider
	 *
	 * @param bool   $delivery_succeeded Initial transport truth.
	 * @param string $final_status Contradictory final status.
	 * @param bool   $attention_required Contradictory attention state.
	 * @return void
	 */
	public function test_contradictory_final_and_attention_state_is_rejected( bool $delivery_succeeded, string $final_status, bool $attention_required ): void {
		$store   = new InMemoryDeliveryStateStore();
		$manager = $this->manager( $store );
		$status  = $delivery_succeeded ? AttemptStatus::SUCCESS : AttemptStatus::FAILED;
		self::assertTrue(
			$manager->record_execution(
				10,
				5,
				7,
				'Case update',
				'sms',
				new NotificationExecutionResult( array( $this->attempt( $status, 'primary' ) ), array(), $delivery_succeeded )
			)
		);
		$store->states[10]['notifications']['feed:7']['final_status']       = $final_status;
		$store->states[10]['notifications']['feed:7']['attention_required'] = $attention_required;

		self::assertSame( DeliveryStateManager::RETRY_STATE_MALFORMED, $manager->retry_eligibility( 10, 7 ) );
		self::assertFalse( $manager->is_confirmed_complete( 10, 7 ) );
	}

	/**
	 * Contradictory final/attention states.
	 *
	 * @return array<string, array{bool,string,bool}>
	 */
	public static function contradictory_state_provider(): array {
		return array(
			'resolved requires no attention' => array( true, DeliveryStateManager::FINAL_RESOLVED, true ),
			'unresolved requires attention'   => array( false, DeliveryStateManager::FINAL_UNRESOLVED, false ),
		);
	}

	/**
	 * T-STATE-04: valid unresolved and resolved controls retain their decisions.
	 *
	 * @return void
	 */
	public function test_valid_target_states_retain_retry_and_suppression_decisions(): void {
		$store   = new InMemoryDeliveryStateStore();
		$manager = $this->manager( $store );
		self::assertTrue(
			$manager->record_execution(
				10,
				5,
				7,
				'Case update',
				'sms',
				new NotificationExecutionResult( array( $this->attempt( AttemptStatus::FAILED, 'primary' ) ), array(), false )
			)
		);
		self::assertSame( DeliveryStateManager::RETRY_ALLOWED, $manager->retry_eligibility( 10, 7 ) );
		self::assertFalse( $manager->is_confirmed_complete( 10, 7 ) );

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
		self::assertSame( DeliveryStateManager::RETRY_NOT_REQUIRED, $manager->retry_eligibility( 10, 7 ) );
		self::assertTrue( $manager->is_confirmed_complete( 10, 7 ) );
	}

	/**
	 * Build deterministic manager.
	 *
	 * @param InMemoryDeliveryStateStore $store Store.
	 * @return DeliveryStateManager
	 */
	private function manager( InMemoryDeliveryStateStore $store ): DeliveryStateManager {
		return new DeliveryStateManager( $store, static fn(): string => '2026-09-09T00:00:00+00:00' );
	}

	/**
	 * Build one persistence-safe transport attempt.
	 *
	 * @param string             $status Status.
	 * @param string             $provider Provider ID.
	 * @param array<int, string> $references Provider references.
	 * @return AttemptResult
	 */
	private function attempt( string $status, string $provider, array $references = array() ): AttemptResult {
		return new AttemptResult( $status, 'sms', $provider, 'plain', $references, 'test' );
	}
}
