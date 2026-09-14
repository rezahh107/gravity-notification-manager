<?php
/**
 * SMS provider configuration and production composition.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider;

use GravityNotify\Delivery\Http\HttpTransportInterface;
use GravityNotify\Delivery\Sms\IPPanelProvider;
use GravityNotify\Delivery\Sms\SmsProviderInterface;

/**
 * Owns supported SMS provider configuration outside the normalized delivery contract.
 *
 * The manager is deliberately narrow: it normalizes configuration, exposes readiness,
 * and constructs enabled production providers in deterministic supported order. It does
 * not send messages or perform provider discovery merely by being read/constructed.
 */
final class SmsProviderManager {

	public const CONFIG_KEY = 'sms_providers';
	public const IPPANEL    = 'ippanel';

	/** Current provider-configuration storage schema. */
	public const SCHEMA_VERSION = 2;

	/**
	 * Normalized supported provider configurations in deterministic order.
	 *
	 * @var array<string, array{enabled:bool,api_key:string,sender:string}>
	 */
	private array $providers;

	/**
	 * @param array<string, mixed> $settings Full GNM settings snapshot.
	 */
	public function __construct( array $settings ) {
		$this->providers = self::normalize_configurations( $settings );
	}

	/**
	 * Convert either the current nested shape or the previous flat IPPanel shape.
	 *
	 * Existing flat settings intentionally become one enabled IPPanel configuration
	 * whenever the old runtime would have registered IPPanel (non-empty API key).
	 *
	 * @param array<string, mixed> $settings Full stored settings.
	 * @return array<string, array{enabled:bool,api_key:string,sender:string}>
	 */
	public static function normalize_configurations( array $settings ): array {
		$stored_providers = is_array( $settings[ self::CONFIG_KEY ] ?? null ) ? $settings[ self::CONFIG_KEY ] : array();
		$stored_ippanel   = is_array( $stored_providers[ self::IPPANEL ] ?? null ) ? $stored_providers[ self::IPPANEL ] : null;

		if ( null !== $stored_ippanel ) {
			$api_key = self::secret( $stored_ippanel['api_key'] ?? '' );
			$sender  = self::sender( $stored_ippanel['sender'] ?? '' );
			$enabled = array_key_exists( 'enabled', $stored_ippanel )
				? self::enabled_value( $stored_ippanel['enabled'] )
				: '' !== $api_key;
		} else {
			$api_key = self::secret( $settings['ippanel_api_key'] ?? '' );
			$sender  = self::sender( $settings['sms_from_number'] ?? '' );
			$enabled = '' !== $api_key;
		}

		return array(
			self::IPPANEL => array(
				'enabled' => $enabled,
				'api_key' => $api_key,
				'sender'  => $sender,
			),
		);
	}

	/**
	 * Sanitize a Settings API submission while preserving omitted responsibilities.
	 *
	 * The Provider Manager submits the nested shape. The old Settings UI may still
	 * submit flat IPPanel field names during this compatibility batch; those inputs
	 * are accepted as a migration alias but are never emitted as stored state.
	 *
	 * @param array<string, mixed> $input    Raw submitted option value.
	 * @param array<string, mixed> $existing Existing stored option state.
	 * @return array<string, array{enabled:bool,api_key:string,sender:string}>
	 */
	public static function sanitize_submission( array $input, array $existing ): array {
		$current = self::normalize_configurations( $existing );
		$ippanel = $current[ self::IPPANEL ];

		$submitted_providers = is_array( $input[ self::CONFIG_KEY ] ?? null ) ? $input[ self::CONFIG_KEY ] : null;
		$submitted_ippanel   = is_array( $submitted_providers[ self::IPPANEL ] ?? null ) ? $submitted_providers[ self::IPPANEL ] : null;

		if ( null !== $submitted_ippanel ) {
			$ippanel['enabled'] = self::enabled_value( $submitted_ippanel['enabled'] ?? false );
			$ippanel['sender']  = self::sender( $submitted_ippanel['sender'] ?? $ippanel['sender'] );
			$replacement        = self::secret( $submitted_ippanel['api_key'] ?? '' );
			if ( '' !== $replacement ) {
				$ippanel['api_key'] = $replacement;
			}
		} elseif ( array_key_exists( 'ippanel_api_key', $input ) || array_key_exists( 'sms_from_number', $input ) ) {
			$ippanel['sender'] = self::sender( $input['sms_from_number'] ?? $ippanel['sender'] );
			$replacement       = self::secret( $input['ippanel_api_key'] ?? '' );
			if ( '' !== $replacement ) {
				$ippanel['api_key'] = $replacement;
			}
		}

		return array( self::IPPANEL => $ippanel );
	}

	/**
	 * Return all supported configurations in deterministic composition order.
	 *
	 * @return array<string, array{enabled:bool,api_key:string,sender:string}>
	 */
	public function configurations(): array {
		return $this->providers;
	}

	/**
	 * Return one supported provider configuration.
	 *
	 * @return array{enabled:bool,api_key:string,sender:string}|null
	 */
	public function configuration( string $identifier ): ?array {
		return $this->providers[ $identifier ] ?? null;
	}

	/** Determine whether the provider is explicitly enabled. */
	public function enabled( string $identifier ): bool {
		$config = $this->configuration( $identifier );
		return null !== $config && $config['enabled'];
	}

	/** Determine whether the enabled provider has all required runtime configuration. */
	public function ready( string $identifier ): bool {
		$config = $this->configuration( $identifier );
		return null !== $config && $config['enabled'] && '' !== $config['api_key'] && '' !== $config['sender'];
	}

	/** Return a semantic readiness status without exposing credentials. */
	public function readiness_status( string $identifier ): string {
		if ( ! $this->enabled( $identifier ) ) {
			return 'DISABLED';
		}

		return $this->ready( $identifier ) ? 'CONFIGURED' : 'NEEDS_SETUP';
	}

	/** Return the configured sender for one provider. */
	public function configured_sender( string $identifier ): string {
		$config = $this->configuration( $identifier );
		return null === $config ? '' : $config['sender'];
	}

	/**
	 * Construct enabled SMS providers in deterministic configured order.
	 *
	 * Construction performs no network request. Provider I/O still occurs only from
	 * the existing synchronous send() path.
	 *
	 * @param HttpTransportInterface $http          WordPress-compatible HTTP transport.
	 * @param string|null            $test_endpoint Optional no-send loopback seam.
	 * @return array<int, SmsProviderInterface>
	 */
	public function enabled_providers( HttpTransportInterface $http, ?string $test_endpoint = null ): array {
		$providers = array();
		foreach ( array_keys( $this->providers ) as $identifier ) {
			$provider = $this->provider( $identifier, $http, $test_endpoint );
			if ( null !== $provider ) {
				$providers[] = $provider;
			}
		}
		return $providers;
	}

	/**
	 * Construct one enabled supported provider through the same production path.
	 *
	 * @param HttpTransportInterface $http          WordPress-compatible HTTP transport.
	 * @param string|null            $test_endpoint Optional no-send loopback seam.
	 */
	public function provider( string $identifier, HttpTransportInterface $http, ?string $test_endpoint = null ): ?SmsProviderInterface {
		$config = $this->configuration( $identifier );
		if ( self::IPPANEL !== $identifier || null === $config || ! $config['enabled'] || '' === $config['api_key'] ) {
			return null;
		}

		return new IPPanelProvider( $config['api_key'], $http, $test_endpoint );
	}

	/** Sanitize a bounded write-only provider secret. */
	private static function secret( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		return is_string( $value ) ? substr( $value, 0, 512 ) : '';
	}

	/** Keep the existing IPPanel E.164 sender contract. */
	private static function sender( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );
		return 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $value ) ? $value : '';
	}

	/** Parse the bounded WordPress checkbox/storage truth shapes. */
	private static function enabled_value( $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'on' === $value || 'yes' === $value;
	}
}
