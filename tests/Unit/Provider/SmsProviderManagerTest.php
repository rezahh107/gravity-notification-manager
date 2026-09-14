<?php
/**
 * SMS Provider Manager configuration/composition tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Provider;

use GravityNotify\Delivery\Sms\IPPanelProvider;
use GravityNotify\Provider\SmsProviderManager;
use GravityNotify\Tests\Support\WordPress\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

/** Proves enabled/disabled behavior and side-effect-free deterministic composition. */
final class SmsProviderManagerTest extends TestCase {

	/** Enabled configured IPPanel is composed once in deterministic supported order. */
	public function test_enabled_ippanel_is_composed_once_without_network_io(): void {
		$http    = new FakeHttpTransport( array() );
		$manager = new SmsProviderManager( $this->settings( true ) );

		self::assertSame( array( SmsProviderManager::IPPANEL ), array_keys( $manager->configurations() ) );
		self::assertTrue( $manager->enabled( SmsProviderManager::IPPANEL ) );
		self::assertTrue( $manager->ready( SmsProviderManager::IPPANEL ) );
		self::assertSame( 'CONFIGURED', $manager->readiness_status( SmsProviderManager::IPPANEL ) );
		self::assertSame( '+989000000000', $manager->configured_sender( SmsProviderManager::IPPANEL ) );

		$providers = $manager->enabled_providers( $http );
		self::assertCount( 1, $providers );
		self::assertInstanceOf( IPPanelProvider::class, $providers[0] );
		self::assertSame( SmsProviderManager::IPPANEL, $providers[0]->identifier() );
		self::assertSame( array(), $http->requests() );
	}

	/** Disabled provider remains configured but is excluded from runtime composition. */
	public function test_disabled_ippanel_is_not_composed_or_ready(): void {
		$http    = new FakeHttpTransport( array() );
		$manager = new SmsProviderManager( $this->settings( false ) );

		self::assertFalse( $manager->enabled( SmsProviderManager::IPPANEL ) );
		self::assertFalse( $manager->ready( SmsProviderManager::IPPANEL ) );
		self::assertSame( 'DISABLED', $manager->readiness_status( SmsProviderManager::IPPANEL ) );
		self::assertSame( array(), $manager->enabled_providers( $http ) );
		self::assertNull( $manager->provider( SmsProviderManager::IPPANEL, $http ) );
		self::assertSame( array(), $http->requests() );
	}

	/** Enabled but incomplete configuration is exposed as NEEDS_SETUP and not composed without an API key. */
	public function test_incomplete_enabled_provider_is_not_ready_or_constructed_without_api_key(): void {
		$http    = new FakeHttpTransport( array() );
		$manager = new SmsProviderManager(
			array(
				SmsProviderManager::CONFIG_KEY => array(
					SmsProviderManager::IPPANEL => array(
						'enabled' => true,
						'api_key' => '',
						'sender'  => '+989000000000',
					),
				),
			)
		);

		self::assertSame( 'NEEDS_SETUP', $manager->readiness_status( SmsProviderManager::IPPANEL ) );
		self::assertSame( array(), $manager->enabled_providers( $http ) );
		self::assertSame( array(), $http->requests() );
	}

	/**
	 * Build one normalized provider settings fixture.
	 *
	 * @param bool $enabled Whether IPPanel is enabled.
	 * @return array<string, mixed>
	 */
	private function settings( bool $enabled ): array {
		return array(
			SmsProviderManager::CONFIG_KEY => array(
				SmsProviderManager::IPPANEL => array(
					'enabled' => $enabled,
					'api_key' => 'test-api-key',
					'sender'  => '+989000000000',
				),
			),
			'bale_bot_token'               => '',
		);
	}
}
