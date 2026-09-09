<?php
/**
 * WU-05 missing nonce regression.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\GravityForms;

use GravityNotify\Delivery\AttemptStatus;
use GravityNotify\Delivery\Sms\SmsProviderRegistry;
use GravityNotify\Delivery\SynchronousDispatcher;
use GravityNotify\DeliveryState\DeliveryStateManager;
use GravityNotify\GravityForms\ManualRetryHandler;
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
 * Proves a missing nonce is independently rejected before send or state mutation.
 */
final class ManualRetryMissingNonceTest extends TestCase {

	/**
	 * Install the deterministic Feed Add-On parent when Gravity Forms is absent.
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
	 * T-WU05-10: missing nonce fails closed independently of an invalid nonce.
	 *
	 * @return void
	 */
	public function test_missing_nonce_fails_closed_before_send_or_state_mutation(): void {
		$provider = new FakeSmsProvider( AttemptStatus::SUCCESS, 'unused' );
		$store    = new InMemoryDeliveryStateStore();
		$manager  = new DeliveryStateManager( $store );
		$add_on   = NotificationFeedAddOn::get_instance();
		$add_on->configure_delivery_state_manager( $manager );
		$add_on->configure_processor( $this->processor( $provider ) );

		$runtime = new FakeManualRetryRuntime();
		$handler = new ManualRetryHandler( $add_on, $runtime );
		$result  = $handler->dispatch(
			'POST',
			array(
				'entry_id' => '10',
				'feed_id'  => '7',
			)
		);

		self::assertSame( ManualRetryHandler::ERROR_NONCE, $result );
		self::assertSame( 0, $provider->send_count );
		self::assertSame( 0, $store->write_count );

		$add_on->configure_processor( null );
		$add_on->configure_delivery_state_manager( null );
	}

	/**
	 * Build the existing synchronous recipient/transport chain.
	 *
	 * @param FakeSmsProvider $provider Provider fake.
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
}
