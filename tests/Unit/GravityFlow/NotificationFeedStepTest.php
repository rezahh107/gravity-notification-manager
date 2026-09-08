<?php
/**
 * Tests for the supported Gravity Flow Feed-Step adapter.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\GravityFlow;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Sms\SmsProviderInterface;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\GravityFlow\FeedStepRegistration;
use GravityNotify\GravityFlow\NotificationFeedStep;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Tests\Support\Delivery\FakeSmsProvider;
use GravityNotify\Tests\Support\GravityFlow\GravityFlowStepFeedAddOnStub;
use GravityNotify\Tests\Support\GravityFlow\GravityFlowStepsStub;
use GravityNotify\Tests\Support\GravityForms\GFFeedAddOnStub;
use GravityNotify\Tests\Support\Recipient\FakeEntryFieldReader;
use GravityNotify\Tests\Support\Recipient\FakeFlowAssigneeReader;
use GravityNotify\Tests\Support\Recipient\FakeUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Proves the adapter delegates Feed-Step behavior to Gravity Flow's base.
 */
final class NotificationFeedStepTest extends TestCase {

	/**
	 * Install deterministic global framework aliases before loading the adapter.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! class_exists( 'GFFeedAddOn', false ) ) {
			class_alias( GFFeedAddOnStub::class, 'GFFeedAddOn' );
		}
		if ( ! class_exists( 'Gravity_Flow_Step_Feed_Add_On', false ) ) {
			class_alias( GravityFlowStepFeedAddOnStub::class, 'Gravity_Flow_Step_Feed_Add_On' );
		}
		if ( ! class_exists( 'Gravity_Flow_Steps', false ) ) {
			class_alias( GravityFlowStepsStub::class, 'Gravity_Flow_Steps' );
		}
	}

	/**
	 * Clean singleton execution dependency after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		NotificationFeedAddOn::get_instance()->configure_processor( null );
		parent::tearDown();
	}

	/**
	 * The adapter registers through the supported registry and targets GNM.
	 *
	 * @return void
	 */
	public function test_step_registers_with_supported_feed_addon_contract(): void {
		self::assertTrue( FeedStepRegistration::register() );

		$steps = GravityFlowStepsStub::steps();
		self::assertNotEmpty( $steps );
		$step = $steps[0];

		self::assertInstanceOf( NotificationFeedStep::class, $step );
		self::assertSame( 'gravity_notification_manager', $step->_step_type );
		self::assertSame( NotificationFeedAddOn::class, $step->get_feed_add_on_class_name() );
		self::assertSame( 'Gravity Notification Manager', $step->get_label() );
	}

	/**
	 * Existing GNM Feeds are discovered and selected by the inherited contract.
	 *
	 * @return void
	 */
	public function test_existing_feed_discovery_and_selection_are_inherited(): void {
		$add_on = NotificationFeedAddOn::get_instance();
		$add_on->set_test_feeds(
			array(
				$this->feed( 11, true ),
				$this->feed( 12, true ),
			)
		);

		$step = new NotificationFeedStep();
		$step->configure_test_context( array( 'id' => 4 ), array( 'id' => 9 ), array( 11 ) );

		self::assertSame( array( 11, 12 ), array_column( $step->get_feeds(), 'id' ) );
		self::assertSame( array( 12 ), array_column( $step->intercept_submission_feeds( $step->get_feeds() ), 'id' ) );
	}

	/**
	 * Native condition handling prevents dispatch without a GNM condition engine.
	 *
	 * @return void
	 */
	public function test_native_condition_path_prevents_dispatch_without_parallel_engine(): void {
		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS );
		$add_on   = NotificationFeedAddOn::get_instance();
		$add_on->configure_processor( $this->processor( $provider ) );
		$add_on->set_test_feeds( array( $this->feed( 21, false ) ) );

		$step = new NotificationFeedStep();
		$step->configure_test_context( array( 'id' => 4 ), array( 'id' => 9 ), array( 21 ) );

		self::assertTrue( $step->process() );
		self::assertSame( 0, $provider->send_count );
	}

	/**
	 * Delivery failure remains separate from the Flow step-completion signal.
	 *
	 * @return void
	 */
	public function test_flow_execution_uses_shared_feed_path_and_failure_does_not_strand_workflow(): void {
		$provider = new FakeSmsProvider( AttemptStatus::FAILED );
		$add_on   = NotificationFeedAddOn::get_instance();
		$add_on->configure_processor( $this->processor( $provider ) );
		$add_on->set_test_feeds( array( $this->feed( 31, true ) ) );

		$step = new NotificationFeedStep();
		$step->configure_test_context( array( 'id' => 4 ), array( 'id' => 9 ), array( 31 ) );

		self::assertTrue( $step->process() );
		self::assertSame( 1, $provider->send_count );
		self::assertNotNull( $add_on->last_execution_result() );
		self::assertFalse( $add_on->last_execution_result()->delivery_succeeded() );
		self::assertSame( AttemptStatus::FAILED, $add_on->last_execution_result()->attempts()[0]->status() );
	}

	/**
	 * Build one deterministic GNM Feed.
	 *
	 * @param int  $id            Feed ID.
	 * @param bool $condition_met Native condition result supplied by the base stub.
	 * @return array<string, mixed>
	 */
	private function feed( int $id, bool $condition_met ): array {
		return array(
			'id'            => $id,
			'is_active'     => true,
			'condition_met' => $condition_met,
			'meta'          => array(
				'feedName'               => 'Notify',
				'message'                => 'Case accepted',
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
				'recipient_source_value' => '+989121234567',
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
			),
		);
	}

	/**
	 * Build the real shared WU-04 processor around existing WU-02/WU-03 seams.
	 *
	 * @param SmsProviderInterface $provider Provider.
	 * @return NotificationFeedProcessor
	 */
	private function processor( SmsProviderInterface $provider ): NotificationFeedProcessor {
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
			new SynchronousDispatcher( new SmsProviderRegistry( array( $provider ) ) ),
			'+982100000000'
		);
	}
}
