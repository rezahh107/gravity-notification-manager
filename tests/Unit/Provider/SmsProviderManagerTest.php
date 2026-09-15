<?php
/**
 * SMS Provider Manager configuration/composition tests.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Provider;

use GravityNotify\Delivery\Sms\FarazSmsProvider;
use GravityNotify\Delivery\Sms\IPPanelProvider;
use GravityNotify\Delivery\Sms\MelipayamakProvider;
use GravityNotify\Delivery\Sms\SmsIrProvider;
use GravityNotify\Provider\SmsProviderManager;
use GravityNotify\Tests\Support\WordPress\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

/** Proves heterogeneous readiness, migration, and deterministic no-I/O composition. */
final class SmsProviderManagerTest extends TestCase {

	/** All approved providers exist in the exact deterministic production order. */
	public function test_approved_provider_order_is_deterministic(): void {
		self::assertSame(
			array(
				SmsProviderManager::IPPANEL,
				SmsProviderManager::MELIPAYAMAK,
				SmsProviderManager::SMSIR,
				SmsProviderManager::FARAZSMS,
			),
			SmsProviderManager::identifiers()
		);
	}

	/** Existing flat IPPanel state migrates without enabling any newly introduced provider. */
	public function test_flat_ippanel_state_migrates_without_credential_reentry_or_new_provider_enablement(): void {
		$manager = new SmsProviderManager(
			array(
				'ippanel_api_key' => 'legacy-api-key',
				'sms_from_number' => '+982100000000',
			)
		);

		self::assertTrue( $manager->ready( SmsProviderManager::IPPANEL ) );
		self::assertSame( '+982100000000', $manager->configured_sender( SmsProviderManager::IPPANEL ) );
		self::assertFalse( $manager->enabled( SmsProviderManager::MELIPAYAMAK ) );
		self::assertFalse( $manager->enabled( SmsProviderManager::SMSIR ) );
		self::assertFalse( $manager->enabled( SmsProviderManager::FARAZSMS ) );
	}

	/** Provider-specific readiness validates the actual required credential shape and sender format. */
	public function test_provider_specific_readiness_semantics(): void {
		$manager = new SmsProviderManager( $this->all_provider_settings() );
		foreach ( SmsProviderManager::identifiers() as $identifier ) {
			self::assertTrue( $manager->enabled( $identifier ), $identifier );
			self::assertTrue( $manager->ready( $identifier ), $identifier );
			self::assertSame( 'CONFIGURED', $manager->readiness_status( $identifier ), $identifier );
		}

		$settings = $this->all_provider_settings();
		$settings[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ]['sender'] = '30001234';
		$settings[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::MELIPAYAMAK ]['password'] = '';
		$settings[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::SMSIR ]['sender'] = '+982100000000';
		$settings[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::FARAZSMS ]['api_key'] = '';
		$manager = new SmsProviderManager( $settings );
		foreach ( SmsProviderManager::identifiers() as $identifier ) {
			self::assertFalse( $manager->ready( $identifier ), $identifier );
			self::assertSame( 'NEEDS_SETUP', $manager->readiness_status( $identifier ), $identifier );
		}
	}

	/** Ready providers are constructed in order without contacting any provider. */
	public function test_provider_construction_is_side_effect_free_and_ordered(): void {
		$http      = new FakeHttpTransport( array() );
		$providers = ( new SmsProviderManager( $this->all_provider_settings() ) )->enabled_providers( $http );

		self::assertCount( 4, $providers );
		self::assertInstanceOf( IPPanelProvider::class, $providers[0] );
		self::assertInstanceOf( MelipayamakProvider::class, $providers[1] );
		self::assertInstanceOf( SmsIrProvider::class, $providers[2] );
		self::assertInstanceOf( FarazSmsProvider::class, $providers[3] );
		self::assertSame( array(), $http->requests() );
	}

	/** Only SMS.ir exposes the currently verified sender-line discovery boundary. */
	public function test_sender_discovery_capability_is_truthful(): void {
		$manager = new SmsProviderManager( $this->all_provider_settings() );
		$http    = new FakeHttpTransport( array() );

		self::assertFalse( SmsProviderManager::supports_discovery( SmsProviderManager::IPPANEL ) );
		self::assertFalse( SmsProviderManager::supports_discovery( SmsProviderManager::MELIPAYAMAK ) );
		self::assertTrue( SmsProviderManager::supports_discovery( SmsProviderManager::SMSIR ) );
		self::assertFalse( SmsProviderManager::supports_discovery( SmsProviderManager::FARAZSMS ) );
		self::assertNull( $manager->sender_discovery( SmsProviderManager::IPPANEL, $http ) );
		self::assertNull( $manager->sender_discovery( SmsProviderManager::MELIPAYAMAK, $http ) );
		self::assertNotNull( $manager->sender_discovery( SmsProviderManager::SMSIR, $http ) );
		self::assertNull( $manager->sender_discovery( SmsProviderManager::FARAZSMS, $http ) );
	}

	/**
	 * Return settings with every approved provider configured.
	 *
	 * @return array<string, mixed>
	 */
	private function all_provider_settings(): array {
		return array(
			SmsProviderManager::CONFIG_KEY => array(
				SmsProviderManager::IPPANEL => array(
					'enabled' => true,
					'api_key' => 'ippanel-key',
					'sender'  => '+982100000000',
				),
				SmsProviderManager::MELIPAYAMAK => array(
					'enabled'  => true,
					'username' => 'meli-user',
					'password' => 'meli-pass',
					'sender'   => '50001234',
				),
				SmsProviderManager::SMSIR => array(
					'enabled' => true,
					'api_key' => 'smsir-key',
					'sender'  => '30001234',
				),
				SmsProviderManager::FARAZSMS => array(
					'enabled' => true,
					'api_key' => 'faraz-key',
					'sender'  => '30005678',
				),
			),
		);
	}
}
