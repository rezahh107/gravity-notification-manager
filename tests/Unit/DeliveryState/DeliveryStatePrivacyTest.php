<?php
/**
 * WU-05 persistence privacy regression.
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
 * Proves provider diagnostics/raw error material is not copied into Entry Meta state.
 */
final class DeliveryStatePrivacyTest extends TestCase {

	/**
	 * Provider diagnostic bodies are execution-local facts, not persisted state.
	 *
	 * @return void
	 */
	public function test_provider_diagnostic_is_not_persisted(): void {
		$store   = new InMemoryDeliveryStateStore();
		$manager = new DeliveryStateManager( $store, static fn(): string => '2026-09-09T00:00:00+00:00' );
		$result  = new NotificationExecutionResult(
			array(
				new AttemptResult(
					AttemptStatus::AMBIGUOUS,
					'sms',
					'primary',
					'plain',
					array( 'safe-provider-reference' ),
					'secret-provider-body-do-not-persist'
				),
			),
			array(),
			false
		);

		self::assertTrue( $manager->record_execution( 10, 5, 7, 'Case update', 'sms', $result ) );

		$encoded = json_encode( $store->states[10] );
		self::assertIsString( $encoded );
		self::assertStringNotContainsString( 'secret-provider-body-do-not-persist', $encoded );
		self::assertArrayNotHasKey(
			'diagnostic',
			$store->states[10]['notifications']['feed:7']['executions'][0]['attempts'][0]
		);
	}
}
