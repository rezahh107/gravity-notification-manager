<?php
/**
 * WU-04 integration tests for explicit Flow assignee Step selection.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\GravityFlow;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Sms\SmsProviderInterface;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\GravityFlow\NotificationFeedStep;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Tests\Support\Delivery\FakeSmsProvider;
use GravityNotify\Tests\Support\GravityFlow\GravityFlowStepFeedAddOnStub;
use GravityNotify\Tests\Support\GravityForms\GFFeedAddOnStub;
use GravityNotify\Tests\Support\Recipient\FakeEntryFieldReader;
use GravityNotify\Tests\Support\Recipient\FakeFlowAssigneeReader;
use GravityNotify\Tests\Support\Recipient\FakeUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Proves the configured business Step ID reaches synchronous WU-04 delivery.
 */
final class ExplicitFlowAssigneeFeedStepTest extends TestCase {

	/** @return void */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! class_exists( 'GFFeedAddOn', false ) ) {
			class_alias( GFFeedAddOnStub::class, 'GFFeedAddOn' );
		}
		if ( ! class_exists( 'Gravity_Flow_Step_Feed_Add_On', false ) ) {
			class_alias( GravityFlowStepFeedAddOnStub::class, 'Gravity_Flow_Step_Feed_Add_On' );
		}
	}

	/** @return void */
	protected function tearDown(): void {
		NotificationFeedAddOn::get_instance()->configure_processor( null );
		parent::tearDown();
	}

	/**
	 * The selected business Step assignee receives exactly one synchronous send.
	 *
	 * @return void
	 */
	public function test_explicit_selected_step_assignee_is_sent_once_synchronously(): void {
		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS );
		$flow     = $this->flow_reader();
		$add_on   = NotificationFeedAddOn::get_instance();
		$add_on->configure_processor( $this->processor( $provider, $flow ) );
		$add_on->set_test_feeds( array( $this->feed( 41, 73 ) ) );

		$step = new NotificationFeedStep();
		$step->configure_test_context( array( 'id' => 9 ), array( 'id' => 501, 'form_id' => 9 ), array( 41 ) );

		self::assertTrue( $step->process() );
		self::assertSame( 73, $flow->last_step_id );
		self::assertSame( 1, $provider->send_count );
		self::assertSame( array( '+989121110088' ), $provider->last_request->recipients() );
		self::assertNotNull( $add_on->last_execution_result() );
		self::assertTrue( $add_on->last_execution_result()->delivery_succeeded() );
	}

	/**
	 * Provider failure stays observable while the Feed Step still completes.
	 *
	 * @return void
	 */
	public function test_explicit_selected_step_provider_failure_does_not_strand_flow(): void {
		$provider = new FakeSmsProvider( AttemptStatus::FAILED );
		$flow     = $this->flow_reader();
		$add_on   = NotificationFeedAddOn::get_instance();
		$add_on->configure_processor( $this->processor( $provider, $flow ) );
		$add_on->set_test_feeds( array( $this->feed( 42, 74 ) ) );

		$step = new NotificationFeedStep();
		$step->configure_test_context( array( 'id' => 9 ), array( 'id' => 502, 'form_id' => 9 ), array( 42 ) );

		self::assertTrue( $step->process() );
		self::assertSame( 74, $flow->last_step_id );
		self::assertSame( 1, $provider->send_count );
		self::assertNotNull( $add_on->last_execution_result() );
		self::assertFalse( $add_on->last_execution_result()->delivery_succeeded() );
		self::assertSame( AttemptStatus::FAILED, $add_on->last_execution_result()->attempts()[0]->status() );
	}

	/** @return FakeFlowAssigneeReader */
	private function flow_reader(): FakeFlowAssigneeReader {
		return new FakeFlowAssigneeReader(
			array(
				'available' => true,
				'reason'    => '',
				'assignees' => array(
					array(
						'type' => 'user_id',
						'id'   => '88',
					),
				),
			)
		);
	}

	/**
	 * @param SmsProviderInterface   $provider Provider fake.
	 * @param FakeFlowAssigneeReader $flow Explicit-Step assignee fake.
	 * @return NotificationFeedProcessor
	 */
	private function processor( SmsProviderInterface $provider, FakeFlowAssigneeReader $flow ): NotificationFeedProcessor {
		$resolver = new RecipientResolver(
			new FakeEntryFieldReader( array() ),
			new FakeUserDirectory(
				array(),
				array(),
				array( 88 => array( RecipientResolver::SMS_META_KEY => '+989121110088' ) )
			),
			$flow
		);

		return new NotificationFeedProcessor(
			$resolver,
			new SynchronousDispatcher( new SmsProviderRegistry( array( $provider ) ) ),
			'+982100000000'
		);
	}

	/**
	 * @param int $feed_id Feed ID.
	 * @param int $recipient_step_id Explicit assignee-bearing business Step ID.
	 * @return array<string, mixed>
	 */
	private function feed( int $feed_id, int $recipient_step_id ): array {
		return array(
			'id'            => $feed_id,
			'is_active'     => true,
			'condition_met' => true,
			'meta'          => array(
				'feedName'               => 'Notify selected assignee',
				'message'                => 'Case accepted',
				'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FLOW_ASSIGNEE,
				'recipient_source_value' => (string) $recipient_step_id,
				'channel'                => FeedRuleSchema::CHANNEL_SMS,
				'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
			),
		);
	}
}
