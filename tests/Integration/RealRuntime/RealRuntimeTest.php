<?php
/**
 * Real WordPress, Gravity Forms, and Gravity Flow integration contract.
 *
 * @package GravityNotify
 */

declare(strict_types=1);

namespace GravityNotify\Tests\Integration\RealRuntime;

use GFAPI;
use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Http\WordPressHttpTransport;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\GravityFlow\NotificationFeedStep;
use GravityNotify\GravityForms\FeedRuleSchema;
use GravityNotify\GravityForms\NotificationFeedAddOn;
use GravityNotify\GravityForms\NotificationFeedProcessor;
use GravityNotify\Recipient\RecipientResolver;
use GravityNotify\Tests\Support\Delivery\FakeSmsProvider;
use GravityNotify\Tests\Support\Recipient\FakeEntryFieldReader;
use GravityNotify\Tests\Support\Recipient\FakeFlowAssigneeReader;
use GravityNotify\Tests\Support\Recipient\FakeUserDirectory;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Exercises greenfield integration only after both real plugins load.
 */
final class RealRuntimeTest extends WP_UnitTestCase {
	/**
	 * Greenfield add-on under test.
	 *
	 * @var NotificationFeedAddOn
	 */
	private NotificationFeedAddOn $add_on;

	/**
	 * Fixture form ID.
	 *
	 * @var int
	 */
	private int $form_id  = 0;

	/**
	 * Fixture entry ID.
	 *
	 * @var int
	 */
	private int $entry_id = 0;

	/** Prepare the real add-on for a test. */
	public function set_up(): void {
		parent::set_up();
		$this->add_on = NotificationFeedAddOn::get_instance();
	}

	/** Remove real database fixtures and request-local injection. */
	public function tear_down(): void {
		$this->add_on->configure_processor( null );
		if ( 0 < $this->entry_id ) {
			GFAPI::delete_entry( $this->entry_id );
		}
		if ( 0 < $this->form_id ) {
			GFAPI::delete_form( $this->form_id );
		}
		parent::tear_down();
	}

	/**
	 * Prove ENV-REAL-01 real runtime identity.
	 *
	 * @testdox ENV-REAL-01 real runtime identity
	 */
	public function test_env_real_01_real_runtime_identity(): void {
		global $wp_version;
		self::assertSame( '7.1', $wp_version );
		self::assertNotSame( '', \GFForms::$version );
		self::assertTrue( defined( 'GRAVITY_FLOW_VERSION' ) );
		self::assertFalse( is_a( 'GFFeedAddOn', 'GravityNotify\\Tests\\Support\\GravityForms\\GFFeedAddOnStub', true ) );
		self::assertFalse( is_a( 'Gravity_Flow_Step_Feed_Add_On', 'GravityNotify\\Tests\\Support\\GravityFlow\\GravityFlowStepFeedAddOnStub', true ) );
	}

	/**
	 * Prove GF-REAL-01 real GFFeedAddOn registration.
	 *
	 * @testdox GF-REAL-01 real GFFeedAddOn registration
	 */
	public function test_gf_real_01_registration(): void {
		self::assertInstanceOf( \GFFeedAddOn::class, $this->add_on );
		self::assertContains( NotificationFeedAddOn::class, \GFAddOn::get_registered_addons() );
	}

	/**
	 * Prove GF-REAL-02 real settings framework contract.
	 *
	 * @testdox GF-REAL-02 real settings framework contract
	 */
	public function test_gf_real_02_settings_contract(): void {
		$fields = array_column( $this->add_on->feed_settings_fields()[0]['fields'], null, 'name' );
		self::assertSame(
			array( 'feedName', 'message', 'recipient_source_type', 'recipient_source_value', 'channel', 'fallback_policy', 'feed_condition' ),
			array_keys( $fields )
		);
		self::assertSame( 'feed_condition', $fields['feed_condition']['type'] );
		self::assertStringContainsString( 'merge-tag-support', $fields['message']['class'] );
		self::assertTrue( method_exists( $this->add_on, 'settings_feed_condition' ) );
		self::assertTrue( method_exists( 'GFCommon', 'replace_variables' ) );
	}

	/**
	 * Prove GF-REAL-03 real feed persistence and retrieval.
	 *
	 * @testdox GF-REAL-03 real feed persistence and retrieval
	 */
	public function test_gf_real_03_feed_round_trip(): void {
		$this->create_fixture();
		$feed = $this->add_on->get_feed( $this->add_feed( 'Round trip {Name:1}' ) );
		self::assertSame( 'Real runtime notification', $feed['meta']['feedName'] );
		self::assertSame( 'Round trip {Name:1}', $feed['meta']['message'] );
		self::assertSame( FeedRuleSchema::RECIPIENT_FIXED, $feed['meta']['recipient_source_type'] );
		self::assertSame( '+15550000000', $feed['meta']['recipient_source_value'] );
		self::assertSame( FeedRuleSchema::CHANNEL_SMS, $feed['meta']['channel'] );
		self::assertSame( FeedRuleSchema::FALLBACK_NONE, $feed['meta']['fallback_policy'] );
	}

	/**
	 * Prove GF-REAL-04 native feed condition lifecycle.
	 *
	 * @testdox GF-REAL-04 native feed condition lifecycle
	 */
	public function test_gf_real_04_native_condition_lifecycle(): void {
		$this->create_fixture();
		$provider = $this->configure_provider( AttemptStatus::SUCCESS );
		$this->add_feed( 'Conditional', 'Not Alice' );
		$this->submit_fixture();
		self::assertSame( 0, $provider->send_count );
		$this->add_on->delete_feeds( $this->form_id );
		$this->add_feed( 'Conditional', 'Alice' );
		$this->submit_fixture();
		self::assertSame( 1, $provider->send_count );
	}

	/**
	 * Prove GF-REAL-05 native merge-tag rendering.
	 *
	 * @testdox GF-REAL-05 native merge-tag rendering
	 */
	public function test_gf_real_05_native_merge_tag_rendering(): void {
		$this->create_fixture();
		$provider = $this->configure_provider( AttemptStatus::SUCCESS );
		$this->add_feed( 'Hello {Name:1}' );
		$this->submit_fixture();
		self::assertSame( 1, $provider->send_count );
		self::assertSame( 'Hello Alice', $provider->last_request->message() );
	}

	/**
	 * Prove GF-REAL-06 process_feed result semantics.
	 *
	 * @testdox GF-REAL-06 process_feed result semantics
	 */
	public function test_gf_real_06_result_semantics(): void {
		$this->create_fixture();
		$feed = $this->add_on->get_feed( $this->add_feed( 'Result' ) );
		$this->configure_provider( AttemptStatus::SUCCESS );
		self::assertTrue( $this->add_on->process_feed( $feed, GFAPI::get_entry( $this->entry_id ), GFAPI::get_form( $this->form_id ) ) );
		$this->configure_provider( AttemptStatus::FAILED );
		self::assertFalse( $this->add_on->process_feed( $feed, GFAPI::get_entry( $this->entry_id ), GFAPI::get_form( $this->form_id ) ) );
	}

	/**
	 * Prove GFLOW-REAL-01 real Feed-Step discovery.
	 *
	 * @testdox GFLOW-REAL-01 real Feed-Step discovery
	 */
	public function test_gflow_real_01_step_discovery(): void {
		$steps = \Gravity_Flow_Steps::get_all();
		self::assertArrayHasKey( 'gravity_notification_manager', $steps );
		self::assertInstanceOf( NotificationFeedStep::class, $steps['gravity_notification_manager'] );
	}

	/**
	 * Prove GFLOW-REAL-02 submission interception and workflow execution.
	 *
	 * @testdox GFLOW-REAL-02 submission interception and workflow execution
	 */
	public function test_gflow_real_02_submission_and_workflow_position(): void {
		$provider = $this->create_flow_fixture( AttemptStatus::SUCCESS, 'Alice' );
		$this->submit_fixture();
		self::assertSame( 0, $provider->send_count );
		$this->process_workflow();
		self::assertSame( 1, $provider->send_count );
	}

	/**
	 * Prove GFLOW-REAL-03 native condition inside Flow lifecycle.
	 *
	 * @testdox GFLOW-REAL-03 native condition inside Flow lifecycle
	 */
	public function test_gflow_real_03_native_condition_inside_flow(): void {
		$provider = $this->create_flow_fixture( AttemptStatus::SUCCESS, 'Not Alice' );
		$this->process_workflow();
		self::assertSame( 0, $provider->send_count );
	}

	/**
	 * Prove GFLOW-REAL-04 provider failure does not strand workflow.
	 *
	 * @testdox GFLOW-REAL-04 provider failure does not strand workflow
	 */
	public function test_gflow_real_04_failure_does_not_strand_workflow(): void {
		$provider = $this->create_flow_fixture( AttemptStatus::FAILED, 'Alice' );
		$this->process_workflow();
		self::assertSame( 1, $provider->send_count );
		self::assertFalse( $this->add_on->last_execution_result()->delivery_succeeded() );
		self::assertSame( 'complete', gform_get_meta( $this->entry_id, 'workflow_final_status' ) );
	}

	/**
	 * Prove SAFE-REAL-01 production WordPress HTTP is blocked before I/O.
	 *
	 * @testdox SAFE-REAL-01 production WordPress HTTP is blocked before I/O
	 */
	public function test_safe_real_01_http_is_blocked(): void {
		$this->expectException( RuntimeException::class );
		( new WordPressHttpTransport() )->post( 'https://provider.invalid/send', array() );
	}

	/**
	 * Prove SAFE-REAL-02 fake seam records deterministic attempt evidence.
	 *
	 * @testdox SAFE-REAL-02 fake seam records deterministic attempt evidence
	 */
	public function test_safe_real_02_fake_attempt_evidence(): void {
		$this->create_fixture();
		$provider = $this->configure_provider( AttemptStatus::SUCCESS );
		$feed     = $this->add_on->get_feed( $this->add_feed( 'Fake only' ) );
		self::assertTrue( $this->add_on->process_feed( $feed, GFAPI::get_entry( $this->entry_id ), GFAPI::get_form( $this->form_id ) ) );
		self::assertSame( 1, $provider->send_count );
		self::assertSame( AttemptStatus::SUCCESS, $this->add_on->last_execution_result()->attempts()[0]->status() );
	}

	/** Create a minimal persisted Gravity Forms form and entry. */
	private function create_fixture(): void {
		$this->form_id = GFAPI::add_form(
			array(
				'title'  => 'GNM real integration fixture',
				'fields' => array(
					array(
						'id'    => 1,
						'type'  => 'text',
						'label' => 'Name',
					),
				),
			)
		);
		self::assertGreaterThan( 0, $this->form_id );
		$this->entry_id = GFAPI::add_entry(
			array(
				'form_id' => $this->form_id,
				'1'       => 'Alice',
			)
		);
		self::assertGreaterThan( 0, $this->entry_id );
	}

	/**
	 * Persist one real feed.
	 *
	 * @param string $message Message template.
	 * @param string $condition_value Optional equality condition value.
	 * @return int Feed ID.
	 */
	private function add_feed( string $message, string $condition_value = '' ): int {
		$meta = array(
			'feedName'               => 'Real runtime notification',
			'message'                => $message,
			'recipient_source_type'  => FeedRuleSchema::RECIPIENT_FIXED,
			'recipient_source_value' => '+15550000000',
			'channel'                => FeedRuleSchema::CHANNEL_SMS,
			'fallback_policy'        => FeedRuleSchema::FALLBACK_NONE,
		);
		if ( '' !== $condition_value ) {
			$meta['feed_condition_conditional_logic']        = 1;
			$meta['feed_condition_conditional_logic_object'] = array(
				'conditionalLogic' => array(
					'actionType' => 'show',
					'logicType'  => 'all',
					'rules'      => array(
						array(
							'fieldId'  => '1',
							'operator' => 'is',
							'value'    => $condition_value,
						),
					),
				),
			);
		}
		$feed_id = $this->add_on->add_feed( $this->form_id, $meta, 'Real runtime notification' );
		self::assertGreaterThan( 0, $feed_id );
		return $feed_id;
	}

	/**
	 * Inject a deterministic no-network provider.
	 *
	 * @param string $status Attempt status.
	 * @return FakeSmsProvider
	 */
	private function configure_provider( string $status ): FakeSmsProvider {
		$provider = new FakeSmsProvider( $status );
		$resolver = new RecipientResolver(
			new FakeEntryFieldReader( array() ),
			new FakeUserDirectory( array(), array(), array() ),
			new FakeFlowAssigneeReader(
				array(
					'available' => false,
					'reason'    => 'not_used',
					'assignees' => array(),
				)
			)
		);
		$this->add_on->configure_processor( new NotificationFeedProcessor( $resolver, new SynchronousDispatcher( new SmsProviderRegistry( array( $provider ) ) ), '+15550000001' ) );
		return $provider;
	}

	/** Invoke the real Gravity Forms submission action. */
	private function submit_fixture(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking the official Gravity Forms lifecycle hook.
		do_action( 'gform_after_submission', GFAPI::get_entry( $this->entry_id ), GFAPI::get_form( $this->form_id ) );
	}

	/**
	 * Persist one Flow-positioned feed fixture.
	 *
	 * @param string $status Fake delivery status.
	 * @param string $condition_value Native condition value.
	 * @return FakeSmsProvider
	 */
	private function create_flow_fixture( string $status, string $condition_value ): FakeSmsProvider {
		$this->create_fixture();
		$provider                  = $this->configure_provider( $status );
		$feed_id                   = $this->add_feed( 'Flow {Name:1}', $condition_value );
		$form                      = GFAPI::get_form( $this->form_id );
		$form['gravityflow_steps'] = array(
			10 => array(
				'id'        => '10',
				'step_type' => 'gravity_notification_manager',
				'step_name' => 'Notify',
				'feeds'     => array( $feed_id ),
			),
		);
		GFAPI::update_form( $form );
		gform_update_meta( $this->entry_id, 'workflow_step', 10 );
		return $provider;
	}

	/** Advance the fixture using the real Gravity Flow API. */
	private function process_workflow(): void {
		$api = new \Gravity_Flow_API( $this->form_id );
		$api->process_workflow( $this->entry_id );
	}
}
