<?php
/** @package GravityNotify */
namespace GravityNotify\Tests\Unit\Admin;

use GravityNotify\Admin\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {
	public function test_supported_values_are_bounded_and_secrets_are_preserved_on_blank_replacement(): void {
		$existing = array( 'ippanel_api_key' => 'keep-api', 'sms_from_number' => '+982100000000', 'bale_bot_token' => 'keep-bale' );
		$result = Settings::sanitize_input( array( 'ippanel_api_key' => '', 'sms_from_number' => '+989121234567', 'bale_bot_token' => '' ), $existing );
		self::assertSame( 'keep-api', $result['ippanel_api_key'] );
		self::assertSame( '+989121234567', $result['sms_from_number'] );
		self::assertSame( 'keep-bale', $result['bale_bot_token'] );
	}

	public function test_malformed_values_fail_safely_and_unknown_fields_are_dropped(): void {
		$result = Settings::sanitize_input(
			array( 'ippanel_api_key' => " api\nkey\0 ", 'sms_from_number' => '09121234567', 'bale_bot_token' => array( 'bad' ), 'legacy_rule' => 'must-not-migrate' )
		);
		self::assertSame( 'apikey', $result['ippanel_api_key'] );
		self::assertSame( '', $result['sms_from_number'] );
		self::assertSame( '', $result['bale_bot_token'] );
		self::assertArrayNotHasKey( 'legacy_rule', $result );
	}

	public function test_diagnostics_expose_only_readiness_not_secret_values(): void {
		$settings = array( 'ippanel_api_key' => 'super-secret-api', 'sms_from_number' => '+982100000000', 'bale_bot_token' => 'super-secret-bale' );
		$facts = Settings::diagnostic_facts( $settings );
		self::assertSame( array( 'IPPanel' => 'CONFIGURED', 'Bale' => 'CONFIGURED' ), $facts );
		self::assertStringNotContainsString( 'super-secret', json_encode( $facts ) );
	}
}
