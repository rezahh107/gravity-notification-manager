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

/**
 * Proves migration, sanitization, preservation, and diagnostic privacy boundaries.
 */
final class SettingsTest extends TestCase {

	/** Flat 3.3.0 IPPanel settings migrate without losing Bale or working delivery state. */
	public function test_flat_ippanel_settings_migrate_to_one_enabled_provider_configuration(): void {
		$legacy = array(
			'ippanel_api_key' => 'existing-api-key',
			'sms_from_number' => '+982100000000',
			'bale_bot_token'  => 'existing-bale-token',
		);

		$result = Settings::normalize_stored( $legacy );
		self::assertSame( SmsProviderManager::SCHEMA_VERSION, $result['schema_version'] );
		self::assertSame( array( SmsProviderManager::IPPANEL ), array_keys( $result[ SmsProviderManager::CONFIG_KEY ] ) );
		self::assertSame(
			array(
				'enabled' => true,
				'api_key' => 'existing-api-key',
				'sender'  => '+982100000000',
			),
			$result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ]
		);
		self::assertSame( 'existing-bale-token', $result['bale_bot_token'] );
		self::assertArrayNotHasKey( 'ippanel_api_key', $result );
		self::assertArrayNotHasKey( 'sms_from_number', $result );
	}

	/** Repeated normalization is idempotent and cannot duplicate the IPPanel configuration. */
	public function test_provider_configuration_upgrade_is_idempotent(): void {
		$first = Settings::normalize_stored(
			array(
				'ippanel_api_key' => 'existing-api-key',
				'sms_from_number' => '+982100000000',
				'bale_bot_token'  => 'existing-bale-token',
			)
		);
		$second = Settings::normalize_stored( $first );

		self::assertSame( $first, $second );
		self::assertCount( 1, $second[ SmsProviderManager::CONFIG_KEY ] );
	}

	/** Read/normalization cannot persist the upgrade merely because a page/runtime was loaded. */
	public function test_settings_read_path_is_side_effect_free(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/src/Admin/Settings.php' );
		self::assertIsString( $source );
		$start = strpos( $source, 'public static function read()' );
		$end   = strpos( $source, 'public static function sanitize_option', false === $start ? 0 : $start );
		self::assertIsInt( $start );
		self::assertIsInt( $end );
		$read = substr( $source, $start, $end - $start );
		self::assertStringContainsString( 'get_option(', $read );
		self::assertStringContainsString( 'normalize_stored(', $read );
		self::assertStringNotContainsString( 'update_option(', $read );
		self::assertStringNotContainsString( 'add_option(', $read );
	}

	/** Blank replacement preserves the secret while explicit nested enable state is honored. */
	public function test_provider_submission_preserves_secret_and_can_disable_provider(): void {
		$existing = Settings::normalize_stored(
			array(
				'ippanel_api_key' => 'keep-api',
				'sms_from_number' => '+982100000000',
				'bale_bot_token'  => 'keep-bale',
			)
		);
		$result = Settings::sanitize_input(
			array(
				SmsProviderManager::CONFIG_KEY => array(
					SmsProviderManager::IPPANEL => array(
						'api_key' => '',
						'sender'  => '+989121234567',
					),
				),
			),
			$existing
		);
		$config = $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ];

		self::assertFalse( $config['enabled'] );
		self::assertSame( 'keep-api', $config['api_key'] );
		self::assertSame( '+989121234567', $config['sender'] );
		self::assertSame( 'keep-bale', $result['bale_bot_token'] );
	}

	/** Existing flat Settings UI submissions remain a compatibility input, not stored output. */
	public function test_flat_submission_alias_updates_nested_provider_without_reintroducing_flat_storage(): void {
		$existing = Settings::normalize_stored(
			array(
				'ippanel_api_key' => 'keep-api',
				'sms_from_number' => '+982100000000',
				'bale_bot_token'  => 'keep-bale',
			)
		);
		$result = Settings::sanitize_input(
			array(
				'ippanel_api_key' => '',
				'sms_from_number' => '+989121234567',
				'bale_bot_token'  => '',
			),
			$existing
		);
		$config = $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ];

		self::assertTrue( $config['enabled'] );
		self::assertSame( 'keep-api', $config['api_key'] );
		self::assertSame( '+989121234567', $config['sender'] );
		self::assertSame( 'keep-bale', $result['bale_bot_token'] );
		self::assertArrayNotHasKey( 'ippanel_api_key', $result );
		self::assertArrayNotHasKey( 'sms_from_number', $result );
	}

	/** Malformed values fail safely and unrelated fields are discarded. */
	public function test_malformed_values_fail_safely_and_unknown_fields_are_dropped(): void {
		$result = Settings::sanitize_input(
			array(
				SmsProviderManager::CONFIG_KEY => array(
					SmsProviderManager::IPPANEL => array(
						'enabled' => '1',
						'api_key' => " api\nkey\0 ",
						'sender'  => '09121234567',
					),
				),
				'bale_bot_token' => array( 'bad' ),
				'legacy_rule'    => 'must-not-migrate',
			)
		);
		$config = $result[ SmsProviderManager::CONFIG_KEY ][ SmsProviderManager::IPPANEL ];

		self::assertTrue( $config['enabled'] );
		self::assertSame( 'apikey', $config['api_key'] );
		self::assertSame( '', $config['sender'] );
		self::assertSame( '', $result['bale_bot_token'] );
		self::assertArrayNotHasKey( 'legacy_rule', $result );
	}

	/** Diagnostic facts expose semantic readiness state without credential values. */
	public function test_diagnostics_expose_only_readiness_not_secret_values(): void {
		$settings = Settings::normalize_stored(
			array(
				'ippanel_api_key' => 'super-secret-api',
				'sms_from_number' => '+982100000000',
				'bale_bot_token'  => 'super-secret-bale',
			)
		);
		$facts = Settings::diagnostic_facts( $settings );
		self::assertSame(
			array(
				'IPPanel' => 'CONFIGURED',
				'Bale'    => 'CONFIGURED',
			),
			$facts
		);
		self::assertStringNotContainsString( 'super-secret', (string) json_encode( $facts ) );
	}
}
