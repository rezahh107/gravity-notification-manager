<?php
/**
 * Bounded greenfield admin settings ownership.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use GravityNotify\Provider\SmsProviderManager;

/**
 * Owns the namespaced GNM option and deterministic provider-config migration.
 */
final class Settings {

	public const OPTION = 'gravity_notify_settings';
	public const GROUP  = 'gravity_notify_settings';

	/**
	 * Read current GNM settings through the deterministic provider-config read path.
	 *
	 * Existing flat IPPanel values are normalized in-memory into the nested provider
	 * shape so runtime delivery keeps working before any operator save. Reads remain
	 * side-effect-free; nested storage is persisted only by an explicit Settings API
	 * write. Compatibility aliases are returned in-memory for bounded legacy callers;
	 * aliases are never stored.
	 *
	 * @return array<string, mixed>
	 */
	public static function read(): array {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$raw   = is_array( $value ) ? $value : array();
		return self::with_compatibility_aliases( self::normalize_stored( $raw ) );
	}

	/**
	 * Sanitize one WordPress Settings API submission.
	 *
	 * @param mixed $input Raw submitted option value.
	 * @return array<string, mixed>
	 */
	public static function sanitize_option( $input ): array {
		$existing = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return self::sanitize_input( is_array( $input ) ? $input : array(), is_array( $existing ) ? $existing : array() );
	}

	/**
	 * Sanitize supported settings while preserving omitted provider/channel ownership.
	 *
	 * @param array<string, mixed> $input    Raw submitted input.
	 * @param array<string, mixed> $existing Existing option state.
	 * @return array<string, mixed>
	 */
	public static function sanitize_input( array $input, array $existing = array() ): array {
		$normalized  = self::normalize_stored( $existing );
		$providers   = SmsProviderManager::sanitize_submission( $input, $normalized );
		$bale_token  = self::secret( $normalized['bale_bot_token'] ?? '' );
		$replacement = self::secret( $input['bale_bot_token'] ?? '' );
		if ( '' !== $replacement ) {
			$bale_token = $replacement;
		}

		return array(
			'schema_version'               => SmsProviderManager::SCHEMA_VERSION,
			SmsProviderManager::CONFIG_KEY => $providers,
			'bale_bot_token'                => $bale_token,
		);
	}

	/**
	 * Deterministically normalize either old flat storage or the current nested shape.
	 *
	 * Reapplying this method to its own output is idempotent and cannot create
	 * duplicate provider configurations because supported providers use stable keys.
	 *
	 * @param array<string, mixed> $stored Raw stored option state.
	 * @return array<string, mixed>
	 */
	public static function normalize_stored( array $stored ): array {
		return array(
			'schema_version'               => SmsProviderManager::SCHEMA_VERSION,
			SmsProviderManager::CONFIG_KEY => SmsProviderManager::normalize_configurations( $stored ),
			'bale_bot_token'                => self::secret( $stored['bale_bot_token'] ?? '' ),
		);
	}

	/**
	 * Derive provider/channel readiness without exposing secrets.
	 *
	 * @param array<string, mixed>|null $settings Optional sanitized settings override.
	 * @return array<string, bool>
	 */
	public static function readiness( ?array $settings = null ): array {
		$settings = null === $settings ? self::read() : $settings;
		$manager  = new SmsProviderManager( $settings );
		return array(
			'ippanel' => $manager->ready( SmsProviderManager::IPPANEL ),
			'bale'    => '' !== self::secret( $settings['bale_bot_token'] ?? '' ),
		);
	}

	/**
	 * Return privacy-safe diagnostic status labels only.
	 *
	 * @param array<string, mixed>|null $settings Optional sanitized settings override.
	 * @return array<string, string>
	 */
	public static function diagnostic_facts( ?array $settings = null ): array {
		$settings = null === $settings ? self::read() : $settings;
		$manager  = new SmsProviderManager( $settings );
		return array(
			__( 'IPPanel', 'gravity-notification-manager' ) => $manager->readiness_status( SmsProviderManager::IPPANEL ),
			__( 'Bale', 'gravity-notification-manager' )    => '' !== self::secret( $settings['bale_bot_token'] ?? '' ) ? 'CONFIGURED' : 'NEEDS_SETUP',
		);
	}

	/**
	 * Keep the previous flat read contract available only as an in-memory bridge.
	 *
	 * @param array<string, mixed> $settings Normalized stored settings.
	 * @return array<string, mixed>
	 */
	private static function with_compatibility_aliases( array $settings ): array {
		$manager = new SmsProviderManager( $settings );
		$config  = $manager->configuration( SmsProviderManager::IPPANEL );

		$settings['ippanel_api_key'] = null === $config ? '' : $config['api_key'];
		$settings['sms_from_number'] = null === $config ? '' : $config['sender'];
		return $settings;
	}

	/**
	 * Sanitize a bounded write-only secret value.
	 *
	 * @param mixed $value Raw secret value.
	 * @return string
	 */
	private static function secret( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		if ( ! is_string( $value ) ) {
			return '';
		}

		return substr( $value, 0, 512 );
	}
}
