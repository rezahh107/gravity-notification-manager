<?php
/**
 * Deterministic WU-07 presentation read-model tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Presentation;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\DeliveryState\DeliveryStateReadResult;
use GravityNotify\GravityForms\NotificationExecutionResult;
use GravityNotify\Presentation\DeliveryStatePresentationReader;
use GravityNotify\Tests\Support\DeliveryState\InMemoryDeliveryStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Proves WU-07 reads WU-05 truth without creating a parallel state authority.
 */
final class DeliveryStatePresentationReaderTest extends TestCase {

	/** T-WU07-01: confirmed success is RESOLVED and needs no attention. */
	public function test_resolved_target_is_projected_without_attention(): void {
		$context = $this->context();
		$this->record( $context['manager'], 10, 5, 7, AttemptStatus::SUCCESS, true );

		$model = $context['reader']->read_entry( 10 );

		self::assertSame( DeliveryStateReadResult::VALID, $model['read_status'] );
		self::assertFalse( $model['entry_requires_attention'] );
		self::assertCount( 1, $model['targets'] );
		self::assertSame( DeliveryStateManager::FINAL_RESOLVED, $model['targets'][0]['final_status'] );
		self::assertFalse( $model['targets'][0]['attention_required'] );
		self::assertSame( array( AttemptStatus::SUCCESS ), $model['targets'][0]['last_execution']['attempt_statuses'] );
	}

	/** T-WU07-01: AMBIGUOUS remains exact transport truth and Attention Required. */
	public function test_unresolved_ambiguous_target_preserves_exact_attempt_truth(): void {
		$context = $this->context();
		$this->record( $context['manager'], 10, 5, 7, AttemptStatus::AMBIGUOUS, false );

		$model = $context['reader']->read_entry( 10 );

		self::assertTrue( $model['entry_requires_attention'] );
		self::assertSame( DeliveryStateManager::FINAL_UNRESOLVED, $model['targets'][0]['final_status'] );
		self::assertTrue( $model['targets'][0]['attention_required'] );
		self::assertSame( array( AttemptStatus::AMBIGUOUS ), $model['targets'][0]['last_execution']['attempt_statuses'] );
		self::assertSame( DeliveryStateManager::RETRY_ALLOWED, $model['targets'][0]['retry_eligibility'] );
	}

	/** T-WU07-01/05: malformed root is explicit and never treated as resolved. */
	public function test_malformed_state_is_explicit_attention_required(): void {
		$context                         = $this->context();
		$context['store']->malformed[10] = true;

		$model = $context['reader']->read_entry( 10 );

		self::assertSame( DeliveryStateReadResult::MALFORMED, $model['read_status'] );
		self::assertTrue( $model['entry_requires_attention'] );
		self::assertSame( array(), $model['targets'] );
	}

	/** T-WU07-01/05: partial target is retained as explicit untrusted Attention Required. */
	public function test_partial_target_is_not_trusted_or_silently_dropped(): void {
		$context = $this->context();
		$this->record( $context['manager'], 10, 5, 7, AttemptStatus::FAILED, false );
		unset( $context['store']->states[10]['notifications']['feed:7']['executions'] );

		$model = $context['reader']->read_entry( 10 );

		self::assertSame( DeliveryStateReadResult::MALFORMED, $model['read_status'] );
		self::assertTrue( $model['entry_requires_attention'] );
		self::assertCount( 1, $model['targets'] );
		self::assertFalse( $model['targets'][0]['trusted'] );
		self::assertTrue( $model['targets'][0]['attention_required'] );
		self::assertSame( DeliveryStateManager::RETRY_STATE_MALFORMED, $model['targets'][0]['retry_eligibility'] );
	}

	/** T-WU07-01/05: multiple Feed targets remain distinct and deterministic. */
	public function test_multiple_feed_targets_remain_distinct_and_sorted(): void {
		$context = $this->context();
		$this->record( $context['manager'], 10, 5, 9, AttemptStatus::SUCCESS, true );
		$this->record( $context['manager'], 10, 5, 3, AttemptStatus::SKIPPED, false );

		$model = $context['reader']->read_entry( 10 );

		self::assertSame( array( 3, 9 ), array_column( $model['targets'], 'feed_id' ) );
		self::assertTrue( $model['entry_requires_attention'] );
		self::assertSame( array( AttemptStatus::SKIPPED ), $model['targets'][0]['last_execution']['attempt_statuses'] );
		self::assertSame( array( AttemptStatus::SUCCESS ), $model['targets'][1]['last_execution']['attempt_statuses'] );
	}

	/** T-WU07-01/07: projection omits provider references and other unsafe transport payloads. */
	public function test_projection_exposes_only_bounded_operator_safe_attempt_facts(): void {
		$context = $this->context();
		$result  = new NotificationExecutionResult(
			array(
				new AttemptResult(
					AttemptStatus::FAILED,
					'sms',
					'secret-provider-id',
					'plain',
					array( 'provider-reference-123' ),
					'safe-diagnostic'
				),
			),
			array(),
			false
		);
		$context['manager']->record_execution( 10, 5, 7, 'Case update', 'sms', $result );

		$encoded = json_encode( $context['reader']->read_entry( 10 ) );

		self::assertIsString( $encoded );
		self::assertStringContainsString( AttemptStatus::FAILED, $encoded );
		self::assertStringNotContainsString( 'secret-provider-id', $encoded );
		self::assertStringNotContainsString( 'provider-reference-123', $encoded );
		self::assertStringNotContainsString( 'safe-diagnostic', $encoded );
	}

	/** T-WU07-01/02: missing state is distinguished from malformed state. */
	public function test_missing_state_has_no_fabricated_target_or_attention_claim(): void {
		$context = $this->context();

		$model = $context['reader']->read_entry( 10 );

		self::assertSame( DeliveryStateReadResult::MISSING, $model['read_status'] );
		self::assertFalse( $model['entry_requires_attention'] );
		self::assertSame( array(), $model['targets'] );
	}

	/**
	 * Build deterministic state/read context.
	 *
	 * @return array{store:InMemoryDeliveryStateStore,manager:DeliveryStateManager,reader:DeliveryStatePresentationReader}
	 */
	private function context(): array {
		$store   = new InMemoryDeliveryStateStore();
		$manager = new DeliveryStateManager( $store, static fn(): string => '2026-09-09T12:00:00+00:00' );
		$reader  = new DeliveryStatePresentationReader( $store, $manager );

		return compact( 'store', 'manager', 'reader' );
	}

	/**
	 * Record one canonical WU-05 execution.
	 *
	 * @param DeliveryStateManager $manager   State manager.
	 * @param int                  $entry_id  Entry ID.
	 * @param int                  $form_id   Form ID.
	 * @param int                  $feed_id   Feed ID.
	 * @param string               $status    Attempt status.
	 * @param bool                 $succeeded Logical success.
	 * @return void
	 */
	private function record( DeliveryStateManager $manager, int $entry_id, int $form_id, int $feed_id, string $status, bool $succeeded ): void {
		self::assertTrue(
			$manager->record_execution(
				$entry_id,
				$form_id,
				$feed_id,
				'Feed ' . $feed_id,
				'sms',
				new NotificationExecutionResult(
					array( new AttemptResult( $status, 'sms', 'test-provider', 'plain', array(), 'test' ) ),
					array(),
					$succeeded
				)
			)
		);
	}
}
