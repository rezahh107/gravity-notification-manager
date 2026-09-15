<?php
/**
 * Bounded greenfield admin settings ownership.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Admin;

use GravityNotify\Provider\SmsProviderManager;

/** Owns the namespaced GNM option and deterministic provider-config migration. */
final class Settings {

	public const OPTION = 'gravity_notify_settings';
	public const GROUP  = 'gravity_notify_settings';

	/** Read current settings without persisting migrations or contacting providers. */
	public static function read(): array {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$raw   = is_array( $value ) ? $value : array();
		return self::with_compatibility_aliases( self::normalize_stored( $raw ) );
	}

	/**
	 * Sanitize one Settings API submission.
	 *
	 * @param mixed $input Raw Settings API value.
	 * @return array<string, mixed>
	 */
	public static function sanitize_option( $input ): array {
		$existing = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return self::sanitize_input( is_array( $input ) ? $input : array(), is_array( $existing ) ? $existing : array() );
	}

	/**
	 * Sanitize supported settings while preserving omitted write-only secrets.
	 *
	 * @param array<string, mixed> $input    Raw submission.
	 * @param array<string, mixed> $existing Existing option.
	 * @return array<string, mixed>
	 */
	public static function sanitize_input( array $input, array $existing = array() ): array {
		$normalized = self::normalize_stored( $existing );
		$providers  = SmsProviderManager::sanitize_submission( $input, $normalized );
		$bale_token = self::secret( $normalized['bale_bot_token'] ?? '' );

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
	 * Deterministically normalize flat IPPanel settings and current nested state.
	 *
	 * @param array<string, mixed> $stored Stored option value.
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
	 * Persist only bounded line metadata after a successful explicit discovery action.
	 *
	 * Existing selected sender is deliberately preserved even when the remote account
	 * currently reports zero lines.
	 *
	 * @param string             $identifier Provider identifier.
	 * @param array<int, string> $lines      Discovered lines.
	 */
	public static function persist_discovered_lines( string $identifier, array $lines ): bool {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return false;
		}
		$existing = get_option( self::OPTION, array() );
		$settings = self::normalize_stored( is_array( $existing ) ? $existing : array() );
		$settings[ SmsProviderManager::CONFIG_KEY ] = SmsProviderManager::with_discovered_lines( $settings, $identifier, $lines );
		return (bool) update_option( self::OPTION, $settings );
	}

	/**
	 * Derive channel readiness without exposing secrets.
	 *
	 * @param array<string, mixed>|null $settings Optional settings snapshot.
	 * @return array<string, bool>
	 */
	public static function readiness( ?array $settings = null ): array {
		$settings = null === $settings ? self::read() : $settings;
		$manager  = new SmsProviderManager( $settings );
		$result   = array();
		foreach ( SmsProviderManager::identifiers() as $identifier ) {
			$result[ $identifier ] = $manager->ready( $identifier );
		}
		$result['bale'] = '' !== self::secret( $settings['bale_bot_token'] ?? '' );
		return $result;
	}

	/**
	 * Return privacy-safe configuration states for Overview/Diagnostics.
	 *
	 * @param array<string, mixed>|null $settings Optional settings snapshot.
	 * @return array<string, string>
	 */
	public static function diagnostic_facts( ?array $settings = null ): array {
		$settings = null === $settings ? self::read() : $settings;
		$manager  = new SmsProviderManager( $settings );
		$result   = array();
		foreach ( SmsProviderManager::definitions() as $identifier => $definition ) {
			$result[ (string) $definition['label'] ] = $manager->readiness_status( $identifier );
		}
		$result[ __( 'Bale', 'gravity-notification-manager' ) ] = '' !== self::secret( $settings['bale_bot_token'] ?? '' ) ? 'CONFIGURED' : 'NEEDS_SETUP';
		return $result;
	}

	/**
	 * Keep the historical flat read aliases in-memory for bounded compatible callers.
	 *
	 * @param array<string, mixed> $settings Normalized settings.
	 * @return array<string, mixed>
	 */
	private static function with_compatibility_aliases( array $settings ): array {
		$manager = new SmsProviderManager( $settings );
		$config  = $manager->configuration( SmsProviderManager::IPPANEL );
		$settings['ippanel_api_key'] = null === $config ? '' : (string) $config['api_key'];
		$settings['sms_from_number'] = null === $config ? '' : (string) $config['sender'];
		return $settings;
	}

	/**
	 * Sanitize a bounded write-only secret.
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
		return is_string( $value ) ? substr( $value, 0, 512 ) : '';
	}
}
