<?php
/**
 * SMS provider configuration and production composition.
 *
 * @package GravityNotify
 */

namespace GravityNotify\Provider;

use GravityNotify\Delivery\Http\HttpTransportInterface;
use GravityNotify\Delivery\Sms\FarazSmsProvider;
use GravityNotify\Delivery\Sms\IPPanelProvider;
use GravityNotify\Delivery\Sms\MelipayamakProvider;
use GravityNotify\Delivery\Sms\SmsIrProvider;
use GravityNotify\Delivery\Sms\SmsProviderInterface;
use GravityNotify\Provider\Connection\FarazSmsConnection;
use GravityNotify\Provider\Connection\IPPanelConnection;
use GravityNotify\Provider\Connection\MelipayamakConnection;
use GravityNotify\Provider\Connection\ProviderConnectionInterface;
use GravityNotify\Provider\Connection\SmsIrConnection;
use GravityNotify\Provider\Discovery\SenderLineDiscoveryInterface;
use GravityNotify\Provider\Discovery\SmsIrSenderLineDiscovery;

/** Owns heterogeneous SMS provider configuration outside the stable delivery contract. */
final class SmsProviderManager {

	public const CONFIG_KEY   = 'sms_providers';
	public const IPPANEL      = 'ippanel';
	public const MELIPAYAMAK  = 'melipayamak';
	public const SMSIR        = 'smsir';
	public const FARAZSMS     = 'farazsms';
	public const SCHEMA_VERSION = 3;

	/**
	 * Normalized provider configurations.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $providers;

	/**
	 * Build the provider manager from a full GNM settings snapshot.
	 *
	 * @param array<string, mixed> $settings Full GNM settings snapshot.
	 */
	public function __construct( array $settings ) {
		$this->providers = self::normalize_configurations( $settings );
	}

	/**
	 * Stable provider definitions in deterministic runtime order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		return array(
			self::IPPANEL => array(
				'label'         => 'IPPanel',
				'credentials'   => array( 'api_key' => array( 'secret' => true ) ),
				'sender_format' => 'e164',
				'discovery'     => false,
				'manual_sender' => true,
			),
			self::MELIPAYAMAK => array(
				'label'       => 'Melipayamak',
				'credentials' => array(
					'username' => array( 'secret' => false ),
					'password' => array( 'secret' => true ),
				),
				'sender_format' => 'numeric',
				'discovery'     => false,
				'manual_sender' => true,
			),
			self::SMSIR => array(
				'label'         => 'SMS.ir',
				'credentials'   => array( 'api_key' => array( 'secret' => true ) ),
				'sender_format' => 'numeric',
				'discovery'     => true,
				'manual_sender' => false,
			),
			self::FARAZSMS => array(
				'label'         => 'FarazSMS',
				'credentials'   => array( 'api_key' => array( 'secret' => true ) ),
				'sender_format' => 'numeric',
				'discovery'     => false,
				'manual_sender' => true,
			),
		);
	}

	/**
	 * Return approved provider identifiers in deterministic order.
	 *
	 * @return array<int, string>
	 */
	public static function identifiers(): array {
		return array_keys( self::definitions() );
	}

	/**
	 * Normalize current nested settings or migrate the previous flat IPPanel shape.
	 *
	 * New provider records are created disabled, so an upgrade cannot create new
	 * external delivery paths merely by loading or saving existing settings.
	 *
	 * @param array<string, mixed> $settings Stored settings.
	 * @return array<string, array<string, mixed>>
	 */
	public static function normalize_configurations( array $settings ): array {
		$stored = is_array( $settings[ self::CONFIG_KEY ] ?? null ) ? $settings[ self::CONFIG_KEY ] : array();
		$result = array();

		foreach ( self::definitions() as $identifier => $definition ) {
			$raw = is_array( $stored[ $identifier ] ?? null ) ? $stored[ $identifier ] : array();
			if ( self::IPPANEL === $identifier && array() === $raw ) {
				$api_key = self::secret( $settings['ippanel_api_key'] ?? '' );
				$raw     = array(
					'enabled' => '' !== $api_key,
					'api_key' => $api_key,
					'sender'  => self::sanitize_sender( $identifier, $settings['sms_from_number'] ?? '' ),
				);
			}

			$config = array(
				'enabled'          => array_key_exists( 'enabled', $raw ) ? self::enabled_value( $raw['enabled'] ) : false,
				'sender'           => self::sanitize_sender( $identifier, $raw['sender'] ?? '' ),
				'discovered_lines' => self::sanitize_lines( $identifier, $raw['discovered_lines'] ?? array() ),
			);
			foreach ( $definition['credentials'] as $field => $metadata ) {
				$config[ $field ] = true === ( $metadata['secret'] ?? false )
					? self::secret( $raw[ $field ] ?? '' )
					: self::plain_credential( $raw[ $field ] ?? '' );
			}
			$result[ $identifier ] = $config;
		}
		return $result;
	}

	/**
	 * Sanitize one Provider Manager Settings API submission.
	 *
	 * Write-only secrets are preserved when the submitted field is blank or omitted.
	 * Providers omitted from a provider-specific save remain untouched.
	 *
	 * @param array<string, mixed> $input    Raw submitted option.
	 * @param array<string, mixed> $existing Existing stored option.
	 * @return array<string, array<string, mixed>>
	 */
	public static function sanitize_submission( array $input, array $existing ): array {
		$current   = self::normalize_configurations( $existing );
		$submitted = is_array( $input[ self::CONFIG_KEY ] ?? null ) ? $input[ self::CONFIG_KEY ] : null;

		if ( null === $submitted && ( array_key_exists( 'ippanel_api_key', $input ) || array_key_exists( 'sms_from_number', $input ) ) ) {
			$config           = $current[ self::IPPANEL ];
			$config['sender'] = self::sanitize_sender( self::IPPANEL, $input['sms_from_number'] ?? $config['sender'] );
			$replacement      = self::secret( $input['ippanel_api_key'] ?? '' );
			if ( '' !== $replacement ) {
				$config['api_key'] = $replacement;
			}
			$current[ self::IPPANEL ] = $config;
			return $current;
		}
		if ( null === $submitted ) {
			return $current;
		}

		foreach ( self::definitions() as $identifier => $definition ) {
			$raw = is_array( $submitted[ $identifier ] ?? null ) ? $submitted[ $identifier ] : null;
			if ( null === $raw ) {
				continue;
			}
			$config            = $current[ $identifier ];
			$config['enabled'] = self::enabled_value( $raw['enabled'] ?? false );
			$config['sender']  = self::sanitize_sender( $identifier, $raw['sender'] ?? $config['sender'] );

			foreach ( $definition['credentials'] as $field => $metadata ) {
				if ( true === ( $metadata['secret'] ?? false ) ) {
					$replacement = self::secret( $raw[ $field ] ?? '' );
					if ( '' !== $replacement ) {
						$config[ $field ] = $replacement;
					}
				} else {
					$config[ $field ] = self::plain_credential( $raw[ $field ] ?? $config[ $field ] ?? '' );
				}
			}
			$current[ $identifier ] = $config;
		}
		return $current;
	}

	/**
	 * Replace bounded discovery metadata for one provider without changing its sender.
	 *
	 * @param array<string, mixed> $settings Settings snapshot.
	 * @param string               $identifier Provider identifier.
	 * @param array<int, string>   $lines Discovered lines.
	 * @return array<string, array<string, mixed>>
	 */
	public static function with_discovered_lines( array $settings, string $identifier, array $lines ): array {
		$configurations = self::normalize_configurations( $settings );
		if ( isset( $configurations[ $identifier ] ) ) {
			$configurations[ $identifier ]['discovered_lines'] = self::sanitize_lines( $identifier, $lines );
		}
		return $configurations;
	}

	/**
	 * Return all normalized provider configurations.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function configurations(): array {
		return $this->providers;
	}

	/**
	 * Return one normalized provider configuration.
	 *
	 * @param string $identifier Provider identifier.
	 * @return array<string, mixed>|null
	 */
	public function configuration( string $identifier ): ?array {
		return $this->providers[ $identifier ] ?? null;
	}

	/**
	 * Return whether the provider is enabled.
	 *
	 * @param string $identifier Provider identifier.
	 * @return bool
	 */
	public function enabled( string $identifier ): bool {
		$config = $this->configuration( $identifier );
		return null !== $config && true === $config['enabled'];
	}

	/**
	 * Determine runtime readiness from provider-specific credentials and sender format.
	 *
	 * @param string $identifier Provider identifier.
	 * @return bool
	 */
	public function ready( string $identifier ): bool {
		$config     = $this->configuration( $identifier );
		$definition = self::definitions()[ $identifier ] ?? null;
		if ( null === $config || null === $definition || ! $config['enabled'] || ! self::valid_sender( $identifier, (string) $config['sender'] ) ) {
			return false;
		}
		return $this->credentials_ready( $identifier, $config );
	}

	/**
	 * Return the privacy-safe readiness status for one provider.
	 *
	 * @param string $identifier Provider identifier.
	 * @return string
	 */
	public function readiness_status( string $identifier ): string {
		if ( ! $this->enabled( $identifier ) ) {
			return 'DISABLED';
		}
		return $this->ready( $identifier ) ? 'CONFIGURED' : 'NEEDS_SETUP';
	}

	/**
	 * Return the configured sender for one provider.
	 *
	 * @param string $identifier Provider identifier.
	 * @return string
	 */
	public function configured_sender( string $identifier ): string {
		$config = $this->configuration( $identifier );
		return null === $config ? '' : (string) $config['sender'];
	}

	/** First ready sender exists only to satisfy the stable normalized request envelope. */
	public function primary_sender(): string {
		foreach ( self::identifiers() as $identifier ) {
			if ( $this->ready( $identifier ) ) {
				return $this->configured_sender( $identifier );
			}
		}
		return '';
	}

	/**
	 * Construct all ready providers in deterministic approved order without network I/O.
	 *
	 * @param HttpTransportInterface $http          HTTP transport.
	 * @param string|null            $test_endpoint Optional IPPanel loopback test endpoint.
	 * @return array<int, SmsProviderInterface>
	 */
	public function enabled_providers( HttpTransportInterface $http, ?string $test_endpoint = null ): array {
		$providers = array();
		foreach ( self::identifiers() as $identifier ) {
			$provider = $this->provider( $identifier, $http, $test_endpoint );
			if ( null !== $provider ) {
				$providers[] = $provider;
			}
		}
		return $providers;
	}

	/**
	 * Construct one ready provider through the production composition boundary.
	 *
	 * @param string                 $identifier    Provider identifier.
	 * @param HttpTransportInterface $http          HTTP transport.
	 * @param string|null            $test_endpoint Optional IPPanel loopback test endpoint.
	 * @return SmsProviderInterface|null
	 */
	public function provider( string $identifier, HttpTransportInterface $http, ?string $test_endpoint = null ): ?SmsProviderInterface {
		if ( ! $this->ready( $identifier ) ) {
			return null;
		}
		$config = $this->providers[ $identifier ];
		return match ( $identifier ) {
			self::IPPANEL => new IPPanelProvider( $config['api_key'], $http, $test_endpoint, $config['sender'] ),
			self::MELIPAYAMAK => new MelipayamakProvider( $config['username'], $config['password'], $config['sender'], $http ),
			self::SMSIR => new SmsIrProvider( $config['api_key'], $config['sender'], $http ),
			self::FARAZSMS => new FarazSmsProvider( $config['api_key'], $config['sender'], $http ),
			default => null,
		};
	}

	/**
	 * Resolve optional explicit line discovery for a configured provider.
	 *
	 * Unsupported or unverified providers return null.
	 *
	 * @param string                 $identifier Provider identifier.
	 * @param HttpTransportInterface $http       HTTP transport.
	 * @return SenderLineDiscoveryInterface|null
	 */
	public function sender_discovery( string $identifier, HttpTransportInterface $http ): ?SenderLineDiscoveryInterface {
		$config = $this->configuration( $identifier );
		if ( null === $config || ! $this->credentials_ready( $identifier, $config ) ) {
			return null;
		}
		return match ( $identifier ) {
			self::SMSIR => new SmsIrSenderLineDiscovery( $config['api_key'], $http ),
			default => null,
		};
	}

	/**
	 * Resolve explicit credential validation for an approved provider.
	 *
	 * @param string                 $identifier Provider identifier.
	 * @param HttpTransportInterface $http       HTTP transport.
	 * @return ProviderConnectionInterface|null
	 */
	public function connection_checker( string $identifier, HttpTransportInterface $http ): ?ProviderConnectionInterface {
		$config = $this->configuration( $identifier );
		if ( null === $config || ! $this->credentials_ready( $identifier, $config ) ) {
			return null;
		}
		return match ( $identifier ) {
			self::IPPANEL => new IPPanelConnection( $config['api_key'], $http ),
			self::MELIPAYAMAK => new MelipayamakConnection( $config['username'], $config['password'], $http ),
			self::SMSIR => new SmsIrConnection( $config['api_key'], $http ),
			self::FARAZSMS => new FarazSmsConnection( $config['api_key'], $http ),
			default => null,
		};
	}

	/**
	 * Return whether the provider exposes verified sender-line discovery.
	 *
	 * @param string $identifier Provider identifier.
	 * @return bool
	 */
	public static function supports_discovery( string $identifier ): bool {
		return true === ( self::definitions()[ $identifier ]['discovery'] ?? false );
	}

	/**
	 * Return whether the provider uses explicit manual sender input.
	 *
	 * @param string $identifier Provider identifier.
	 * @return bool
	 */
	public static function manual_sender_allowed( string $identifier ): bool {
		return true === ( self::definitions()[ $identifier ]['manual_sender'] ?? false );
	}

	/**
	 * Validate a provider sender according to the verified provider contract.
	 *
	 * @param string $identifier Provider identifier.
	 * @param string $value      Sender value.
	 * @return bool
	 */
	public static function valid_sender( string $identifier, string $value ): bool {
		$value  = trim( $value );
		$format = self::definitions()[ $identifier ]['sender_format'] ?? '';
		if ( 'e164' === $format ) {
			return 1 === preg_match( '/^\+[1-9][0-9]{1,14}$/D', $value );
		}
		return 'numeric' === $format && 1 === preg_match( '/^[1-9][0-9]{2,31}$/D', $value );
	}

	/**
	 * Return whether all required provider credentials are present.
	 *
	 * @param string               $identifier Provider identifier.
	 * @param array<string, mixed> $config     Provider configuration.
	 * @return bool
	 */
	private function credentials_ready( string $identifier, array $config ): bool {
		$definition = self::definitions()[ $identifier ] ?? null;
		if ( null === $definition ) {
			return false;
		}
		foreach ( $definition['credentials'] as $field => $metadata ) {
			unset( $metadata );
			if ( '' === (string) ( $config[ $field ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Sanitize one provider sender against its verified format.
	 *
	 * @param string $identifier Provider identifier.
	 * @param mixed  $value      Raw sender value.
	 * @return string
	 */
	private static function sanitize_sender( string $identifier, $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		return self::valid_sender( $identifier, $value ) ? $value : '';
	}

	/**
	 * Sanitize bounded discovered sender lines.
	 *
	 * @param string $identifier Provider identifier.
	 * @param mixed  $values     Raw discovered values.
	 * @return array<int, string>
	 */
	private static function sanitize_lines( string $identifier, $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}
		$lines = array();
		foreach ( $values as $value ) {
			if ( is_int( $value ) ) {
				$value = (string) $value;
			}
			if ( is_string( $value ) && self::valid_sender( $identifier, $value ) ) {
				$lines[] = trim( $value );
			}
			if ( 50 <= count( $lines ) ) {
				break;
			}
		}
		return array_values( array_unique( $lines ) );
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
		return is_string( $value ) ? substr( $value, 0, 512 ) : '';
	}

	/**
	 * Sanitize a bounded non-secret credential value.
	 *
	 * @param mixed $value Raw credential value.
	 * @return string
	 */
	private static function plain_credential( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ?? '' );
		return substr( $value, 0, 191 );
	}

	/**
	 * Normalize one provider enablement value.
	 *
	 * @param mixed $value Raw enablement value.
	 * @return bool
	 */
	private static function enabled_value( $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'on' === $value || 'yes' === $value;
	}
}
