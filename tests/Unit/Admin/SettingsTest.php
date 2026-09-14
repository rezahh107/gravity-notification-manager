<?php
/**
 * Tests for GNM settings/provider configuration behavior.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\Settings;
use GravityNotify\Provider\SmsProviderManager;
use PHPUnit\Framework\TestCase;

/** Proves migration, secret preservation, heterogeneous normalization, and privacy boundaries. */
final class SettingsTest extends TestCase {

	/** Flat IPPanel settings migrate and all newly introduced providers stay disabled. */
	public function test_flat_ippanel_settings_migrate_without_enabling_new_providers(): void {
		$result = Settings::normalize_stored(
			array(
				'ippanel_api_key' => 'existing-api-key',
				'sms_from_number' => '+982100000000',
				'bale_bot_token'  => 'existing-bale-token',
			)
		);

		self::assertSame( SmsProviderManager::SCHEMA_VERSION, $result['schema_version'] );
		self::assertSame( SmsProviderManager::identifiers(), array_keys( $result[ SmsProviderManager::CONFIG_KEY ] ) );
		self::assertTrue( $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ]['enabled'] );
		self::assertSame( 'existing-api-key', $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ]['api_key'] );
		self::assertSame( '+982100000000', $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ]['sender'] );
		foreach ( array( SmsProviderManager::MELIPAYAMAK, SmsProviderManager::SMSIR, SmsProviderManager::FARAZSMS ) as $identifier ) {
			self::assertFalse( $result[ SmsProviderManager::CONFIG_KEY ][ $identifier ]['enabled'] );
		}
		self::assertSame( 'existing-bale-token', $result['bale_bot_token'] );
		self::assertArrayNotHasKey( 'ippanel_api_key', $result );
		self::assertArrayNotHasKey( 'sms_from_number', $result );
	}

	/** Repeated normalization is deterministic and idempotent. */
	public function test_provider_configuration_upgrade_is_idempotent(): void {
		$first = Settings::normalize_stored(
			array(
				'ippanel_api_key' => 'existing-api-key',
				'sms_from_number' => '+982100000000',
				'bale_bot_token'  => 'existing-bale-token',
			)
		);
		self::assertSame( $first, Settings::normalize_stored( $first ) );
		self::assertCount( 4, $first[ SmsProviderManager::CONFIG_KEY ] );
	}

	/** Read/normalization cannot persist a migration merely because a page/runtime was loaded. */
	public function test_settings_read_path_is_side_effect_free(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Settings.php' );
		self::assertIsString( $source );
		$start = strpos( $source, 'public static function read()' );
		$end   = strpos( $source, 'public static function sanitize_option', false === $start ? 0 : $start );
		self::assertIsInt( $start );
		self::assertIsInt( $end );
		$read = substr( $source, $start, $end - $start );
		self::assertStringContainsString( 'get_option(', $read );
		self::assertStringNotContainsString( 'update_option(', $read );
		self::assertStringNotContainsString( 'wp_remote_', $read );
	}

	/** Provider-specific saves preserve omitted provider config and blank write-only secrets. */
	public function test_provider_submission_preserves_write_only_secrets_and_other_providers(): void {
		$existing = Settings::normalize_stored( $this->all_provider_settings() );
		$result   = Settings::sanitize_input(
			array(
				SmsProviderManager::CONFIG_KEY => array(
					SmsProviderManager::MELIPAYAMAK => array(
						'enabled'  => '1',
						'username' => 'new-user',
						'password' => '',
						'sender'   => '50009999',
					),
				),
			),
			$existing
		);

		self::assertSame( 'new-user', $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::MELIPAYAMAK ]['username'] );
		self::assertSame( 'meli-pass', $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::MELIPAYAMAK ]['password'] );
		self::assertSame( '50009999', $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::MELIPAYAMAK ]['sender'] );
		self::assertSame( 'ippanel-key', $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ]['api_key'] );
		self::assertSame( 'smsir-key', $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::SMSIR ]['api_key'] );
		self::assertSame( 'faraz-key', $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::FARAZSMS ]['api_key'] );
	}

	/** Sender validation is provider-specific rather than globally applying IPPanel E.164. */
	public function test_provider_specific_sender_formats_are_enforced(): void {
		$input = array(
			SmsProviderManager::CONFIG_KEY => array(
				SmsProviderManager::IPPANEL => array( 'enabled' => '1', 'api_key' => 'ip', 'sender' => '30001234' ),
				SmsProviderManager::SMSIR => array( 'enabled' => '1', 'api_key' => 'sms', 'sender' => '30001234' ),
			),
		);
		$result = Settings::sanitize_input( $input );
		self::assertSame( '', $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ]['sender'] );
		self::assertSame( '30001234', $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::SMSIR ]['sender'] );
	}

	/** Discovery metadata refresh never rewrites the previously selected sender. */
	public function test_discovery_metadata_replacement_preserves_selected_sender(): void {
		$settings = Settings::normalize_stored( $this->all_provider_settings() );
		$result   = SmsProviderManager::with_discovered_lines( $settings, SmsProviderManager::SMSIR, array() );
		self::assertSame( '30001234', $result[ SmsProviderManager::SMSIR ]['sender'] );
		self::assertSame( array(), $result[ SmsProviderManager::SMSIR ]['discovered_lines'] );
	}

	/** Diagnostics show readiness for every approved provider without credentials. */
	public function test_diagnostics_expose_provider_readiness_without_secrets(): void {
		$settings = Settings::normalize_stored( $this->all_provider_settings() );
		$facts    = Settings::diagnostic_facts( $settings );
		self::assertSame( 'CONFIGURED', $facts['IPPanel'] );
		self::assertSame( 'CONFIGURED', $facts['Melipayamak'] );
		self::assertSame( 'CONFIGURED', $facts['SMS.ir'] );
		self::assertSame( 'CONFIGURED', $facts['FarazSMS'] );
		self::assertSame( 'CONFIGURED', $facts['Bale'] );
		self::assertStringNotContainsString( 'secret-', (string) json_encode( $facts ) );
	}

	/** @return array<string, mixed> */
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
			'bale_bot_token' => 'secret-bale',
		);
	}
}
