<?php
/**
 * Deterministic WU-07 operational presentation tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Presentation;

use GravityNotify\Delivery\AttemptResult;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\GravityForms\ManualRetryHandler;
use GravityNotify\GravityForms\NotificationExecutionResult;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\Presentation\DeliveryStatePresentationReader;
use GravityNotify\Presentation\OperationalPresentation;
use GravityNotify\Tests\Support\DeliveryState\InMemoryDeliveryStateStore;
use GravityNotify\Tests\Support\GravityForms\FakeManualRetryRuntime;
use GravityNotify\Tests\Support\GravityForms\GFFeedAddOnStub;
use PHPUnit\Framework\TestCase;

/**
 * Proves Entry Detail, Retry visibility, Attention query and privacy boundaries.
 */
final class OperationalPresentationTest extends TestCase {

	/** Install deterministic Gravity Forms Add-On parent class. */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! class_exists( 'GFFeedAddOn', false ) ) {
			class_alias( GFFeedAddOnStub::class, 'GFFeedAddOn' );
		}
	}

	/** T-WU07-02/07: Entry Detail is semantic, RTL-safe, privacy-safe and read-only. */
	public function test_entry_detail_renders_truthful_safe_status_without_state_mutation(): void {
		$context = $this->context();
		$this->record( $context['manager'], 10, 5, 7, AttemptStatus::AMBIGUOUS, false );
		$writes_before = $context['store']->write_count;

		$html = $context['presentation']->entry_detail_html( array( 'id' => 10 ), array( 'id' => 5 ) );

		self::assertSame( $writes_before, $context['store']->write_count );
		self::assertStringContainsString( 'Attention Required', $html );
		self::assertStringContainsString( 'AMBIGUOUS', $html );
		self::assertStringContainsString( '<bdi dir="ltr">', $html );
		self::assertStringContainsString( '<dl>', $html );
		self::assertStringNotContainsString( 'secret-provider', $html );
		self::assertStringNotContainsString( 'provider-reference', $html );
		self::assertStringNotContainsString( '+989121234567', $html );
		self::assertStringNotContainsString( 'private message body', $html );
	}

	/** T-WU07-02: missing and malformed state render explicit safe fallbacks. */
	public function test_entry_detail_handles_missing_and_malformed_state_without_fatal(): void {
		$context = $this->context();
		$missing = $context['presentation']->entry_detail_html( array( 'id' => 10 ), array( 'id' => 5 ) );
		self::assertStringContainsString( 'No notification delivery state', $missing );

		$context['store']->malformed[10] = true;
		$malformed = $context['presentation']->entry_detail_html( array( 'id' => 10 ), array( 'id' => 5 ) );
		self::assertStringContainsString( 'malformed or untrusted', $malformed );
		self::assertStringContainsString( 'Retry is unavailable', $malformed );
	}

	/** T-WU07-02: supported Entry Detail filter receives one bounded side meta box. */
	public function test_entry_detail_meta_box_registration_is_bounded(): void {
		$context = $this->context();
		$boxes   = $context['presentation']->register_entry_detail_meta_box( array(), array( 'id' => 10 ), array( 'id' => 5 ) );

		self::assertArrayHasKey( 'gravity_notify_delivery', $boxes );
		self::assertSame( 'side', $boxes['gravity_notify_delivery']['context'] );
		self::assertIsCallable( $boxes['gravity_notify_delivery']['callback'] );
	}

	/** T-WU07-03: Retry control is shown only for current eligible applicable targets. */
	public function test_retry_control_eligibility_reuses_wu05_state_capability_and_feed_contract(): void {
		$context = $this->context();
		$this->record( $context['manager'], 10, 5, 7, AttemptStatus::FAILED, false );
		$target = $context['reader']->read_entry( 10 )['targets'][0];

		$context['runtime']->feeds[7] = $this->feed();
		self::assertTrue( $context['presentation']->retry_control_eligible( $target, 10, 5 ) );

		$context['runtime']->capability = false;
		self::assertFalse( $context['presentation']->retry_control_eligible( $target, 10, 5 ) );
		$context['runtime']->capability = true;

		$context['runtime']->feeds[7]['form_id'] = 999;
		self::assertFalse( $context['presentation']->retry_control_eligible( $target, 10, 5 ) );
	}

	/** T-WU07-04: post-Retry messages are derived only from freshly re-read state. */
	public function test_retry_notice_never_claims_resolution_without_fresh_persisted_resolution(): void {
		$context = $this->context();
		$this->record( $context['manager'], 10, 5, 7, AttemptStatus::AMBIGUOUS, false );
		$unresolved = $context['reader']->read_entry( 10 );

		$optimistic = $context['presentation']->retry_notice_for_result( $unresolved, ManualRetryHandler::RESULT_SUCCESS, 7 );
		self::assertStringContainsString( 'could not be confirmed', $optimistic );
		self::assertStringNotContainsString( 'now confirms RESOLVED', $optimistic );

		$unresolved_notice = $context['presentation']->retry_notice_for_result( $unresolved, ManualRetryHandler::RESULT_UNRESOLVED, 7 );
		self::assertStringContainsString( 'Attention Required remains active', $unresolved_notice );

		$this->record( $context['manager'], 10, 5, 7, AttemptStatus::SUCCESS, true, DeliveryStateManager::EXECUTION_MANUAL_RETRY );
		$resolved        = $context['reader']->read_entry( 10 );
		$resolved_notice = $context['presentation']->retry_notice_for_result( $resolved, ManualRetryHandler::RESULT_SUCCESS, 7 );
		self::assertStringContainsString( 'now confirms RESOLVED', $resolved_notice );

		$failure_notice = $context['presentation']->retry_notice_for_result( $unresolved, ManualRetryHandler::ERROR_STATE, 7 );
		self::assertStringContainsString( 'persisted state transition was not confirmed', $failure_notice );
		self::assertStringContainsString( 'Do not infer delivery resolution', $failure_notice );
	}

	/** T-WU07-05: deterministic Attention query includes unresolved/malformed only. */
	public function test_attention_query_is_derived_from_authoritative_read_model(): void {
		$context = $this->context();
		$this->record( $context['manager'], 11, 5, 7, AttemptStatus::SUCCESS, true );
		$this->record( $context['manager'], 12, 5, 7, AttemptStatus::FAILED, false );
		$context['store']->malformed[13] = true;

		$ids = $context['presentation']->attention_entry_ids( array( 11, 12, 13, 14, 'bad' ) );

		self::assertSame( array( 12, 13 ), $ids );
	}

	/** T-WU07-05: one unresolved target keeps a multi-target Entry queryable. */
	public function test_attention_query_is_deterministic_for_multi_target_entry(): void {
		$context = $this->context();
		$this->record( $context['manager'], 20, 5, 3, AttemptStatus::SUCCESS, true );
		$this->record( $context['manager'], 20, 5, 9, AttemptStatus::SKIPPED, false );

		self::assertSame( array( 20 ), $context['presentation']->attention_entry_ids( array( 20 ) ) );
	}

	/** T-WU07-06/07: GravityView card contains bounded facts and Entry Detail fallback only. */
	public function test_attention_card_exposes_bounded_safe_operator_facts(): void {
		$context = $this->context();
		$this->record( $context['manager'], 10, 5, 7, AttemptStatus::FAILED, false );
		$model = $context['reader']->read_entry( 10 );

		$html = $context['presentation']->attention_card_html( $model, '/wp-admin/admin.php?page=gf_entries' );

		self::assertStringContainsString( 'Attention Required', $html );
		self::assertStringContainsString( 'FAILED', $html );
		self::assertStringContainsString( 'Open Gravity Forms Entry Detail', $html );
		self::assertStringContainsString( '<bdi dir="ltr">', $html );
		self::assertStringNotContainsString( 'secret-provider', $html );
		self::assertStringNotContainsString( 'provider-reference', $html );
	}

	/** T-WU07-06: missing optional GravityView runtime returns input untouched. */
	public function test_gravityview_filter_fails_gracefully_without_optional_runtime(): void {
		$context = $this->context();
		$entries = new \stdClass();
		$view    = new \stdClass();
		$view->ID = 123;

		self::assertSame( $entries, $context['presentation']->filter_gravityview_entries( $entries, $view, null ) );
	}

	/**
	 * Build presentation context with deterministic state and runtime seams.
	 *
	 * @return array{store:InMemoryDeliveryStateStore,manager:DeliveryStateManager,reader:DeliveryStatePresentationReader,runtime:FakeManualRetryRuntime,presentation:OperationalPresentation}
	 */
	private function context(): array {
		$store   = new InMemoryDeliveryStateStore();
		$manager = new DeliveryStateManager( $store, static fn(): string => '2026-09-09T12:00:00+00:00' );
		$reader  = new DeliveryStatePresentationReader( $store, $manager );
		$runtime = new FakeManualRetryRuntime();
		$add_on  = NotificationFeedAddOn::get_instance();

		$runtime->entries[10] = array( 'id' => 10, 'form_id' => 5 );
		$runtime->forms[5]    = array( 'id' => 5 );

		return array(
			'store'        => $store,
			'manager'      => $manager,
			'reader'       => $reader,
			'runtime'      => $runtime,
			'presentation' => new OperationalPresentation( $add_on, $reader, $runtime ),
		);
	}

	/**
	 * Record one canonical state execution.
	 *
	 * @param DeliveryStateManager $manager        State manager.
	 * @param int                  $entry_id       Entry ID.
	 * @param int                  $form_id        Form ID.
	 * @param int                  $feed_id        Feed ID.
	 * @param string               $status         Attempt status.
	 * @param bool                 $succeeded      Logical success.
	 * @param string               $execution_type Execution type.
	 * @return void
	 */
	private function record(
		DeliveryStateManager $manager,
		int $entry_id,
		int $form_id,
		int $feed_id,
		string $status,
		bool $succeeded,
		string $execution_type = DeliveryStateManager::EXECUTION_ORDINARY
	): void {
		self::assertTrue(
			$manager->record_execution(
				$entry_id,
				$form_id,
				$feed_id,
				'Case update',
				'sms',
				new NotificationExecutionResult(
					array(
						new AttemptResult(
							$status,
							'sms',
							'secret-provider',
							'plain',
							array( 'provider-reference' ),
							'test'
						),
					),
					array(),
					$succeeded
				),
				$execution_type
			)
		);
	}

	/** Build one applicable current Feed fixture. */
	private function feed(): array {
		return array(
			'id'         => 7,
			'form_id'    => 5,
			'is_active'  => true,
			'addon_slug' => 'gravity-notification-manager',
			'meta'       => array(
				'feedName' => 'Case update',
				'channel'  => 'sms',
			),
		);
	}
}
